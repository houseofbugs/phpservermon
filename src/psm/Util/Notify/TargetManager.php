<?php

/**
 * DFS Monitor — notification targets: storage, delivery, logging.
 *
 * This file is part of DFS Monitor, a fork of PHP Server Monitor (GPL-3.0-or-later).
 */

namespace psm\Util\Notify;

use psm\Service\Database;

class TargetManager
{
    /** @var Database */
    protected $db;

    /** @var int seconds to wait before the single retry */
    public $retryDelay = 2;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    protected function table($name)
    {
        return PSM_DB_PREFIX . $name;
    }

    /* ------------------------------------------------------------------ storage */

    public function all()
    {
        $rows = $this->db->query('SELECT * FROM `' . $this->table('targets') . '` ORDER BY `name` ASC');
        $counts = array();
        foreach ((array) $this->db->query('SELECT `target_id`, COUNT(*) AS `cnt` FROM `'
            . $this->table('servers_targets') . '` GROUP BY `target_id`') as $row) {
            $counts[(int) $row['target_id']] = (int) $row['cnt'];
        }
        $out = array();
        foreach ((array) $rows as $row) {
            $row['server_count'] = $counts[(int) $row['target_id']] ?? 0;
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    public function get($target_id)
    {
        $row = $this->db->selectRow($this->table('targets'), array('target_id' => (int) $target_id));
        return empty($row) ? null : $this->hydrate($row);
    }

    /**
     * @param array $row db row
     * @return array row + 'settings' (decrypted array) + 'type_def'
     */
    protected function hydrate(array $row)
    {
        $settings = json_decode((string) $row['settings'], true);
        $settings = is_array($settings) ? $settings : array();
        foreach (TargetTypes::secretFields($row['type']) as $field) {
            if (!empty($settings[$field])) {
                $plain = psm_password_decrypt(psm_get_conf('password_encrypt_key'), $settings[$field]);
                $settings[$field] = $plain === false ? '' : $plain;
            }
        }
        $row['settings'] = $settings;
        $row['type_def'] = TargetTypes::get($row['type']);
        return $row;
    }

    /**
     * Save a target. Empty secret fields keep their existing value.
     * @return int target id
     */
    public function save(array $data, $target_id = 0)
    {
        $type = $data['type'];
        $existing = $target_id ? $this->get($target_id) : null;
        $settings = array();
        foreach (TargetTypes::get($type)['fields'] as $field) {
            $name = $field['name'];
            $value = isset($data['settings'][$name]) ? trim((string) $data['settings'][$name]) : '';
            if ($field['type'] === 'checkbox') {
                $value = $value === '1' ? '1' : '0';
            }
            if ($field['type'] === 'password') {
                if ($value === '' && $existing && !empty($existing['settings'][$name])) {
                    $value = $existing['settings'][$name];
                }
                $value = $value === '' ? '' : psm_password_encrypt(psm_get_conf('password_encrypt_key'), $value);
            }
            $settings[$name] = $value;
        }
        $row = array(
            'name' => $data['name'],
            'type' => $type,
            'settings' => json_encode($settings, JSON_UNESCAPED_SLASHES),
            'is_default' => !empty($data['is_default']) ? 1 : 0,
            'active' => !empty($data['active']) ? 1 : 0,
        );
        if ($target_id) {
            $this->db->save($this->table('targets'), $row, array('target_id' => (int) $target_id));
            return (int) $target_id;
        }
        return (int) $this->db->save($this->table('targets'), $row);
    }

    public function delete($target_id)
    {
        $this->db->delete($this->table('servers_targets'), array('target_id' => (int) $target_id));
        $this->db->delete($this->table('targets'), array('target_id' => (int) $target_id));
    }

    public function defaults()
    {
        return array_values(array_filter($this->all(), function ($t) {
            return (int) $t['is_default'] === 1;
        }));
    }

    public function serverTargetIds($server_id)
    {
        $ids = array();
        foreach ((array) $this->db->select($this->table('servers_targets'), array('server_id' => (int) $server_id), array('target_id')) as $row) {
            $ids[] = (int) $row['target_id'];
        }
        return $ids;
    }

    public function setServerTargets($server_id, array $target_ids)
    {
        $this->db->delete($this->table('servers_targets'), array('server_id' => (int) $server_id));
        $rows = array();
        foreach (array_unique(array_map('intval', $target_ids)) as $tid) {
            if ($tid > 0) {
                $rows[] = array('server_id' => (int) $server_id, 'target_id' => $tid);
            }
        }
        if ($rows) {
            $this->db->insertMultiple($this->table('servers_targets'), $rows);
        }
    }

    public function applyToAllServers($target_id)
    {
        $this->db->exec('INSERT IGNORE INTO `' . $this->table('servers_targets') . '` (`server_id`, `target_id`)
            SELECT `server_id`, ' . (int) $target_id . ' FROM `' . $this->table('servers') . '`');
    }

    /**
     * Active targets attached to a server.
     */
    public function forServer($server_id)
    {
        $rows = $this->db->query('SELECT `t`.* FROM `' . $this->table('targets') . '` AS `t`
            JOIN `' . $this->table('servers_targets') . '` AS `st` ON `st`.`target_id` = `t`.`target_id`
            WHERE `st`.`server_id` = ' . (int) $server_id . ' AND `t`.`active` = 1');
        return array_map(array($this, 'hydrate'), (array) $rows);
    }

    /* ------------------------------------------------------------------ delivery */

    /**
     * Deliver events to a target (one retry on failure), record status and log.
     * @param array $target hydrated target
     * @param array $events
     * @param bool $log write to the log table
     * @return array ['ok' => bool, 'code' => int, 'error' => string]
     */
    public function send(array $target, array $events, $log = true)
    {
        if (empty($events)) {
            return array('ok' => true, 'code' => 0, 'error' => '');
        }
        $request = Formatter::build($target['type'], $target['settings'], $events);
        $result = $this->http($request);
        if (!$result['ok'] && $this->retryDelay >= 0) {
            if ($this->retryDelay > 0) {
                sleep($this->retryDelay);
            }
            $result = $this->http($request);
            $result['retried'] = true;
        }

        $this->db->save($this->table('targets'), array(
            'last_status' => $result['ok'] ? 'ok' : 'failed',
            'last_error' => $result['ok'] ? null : mb_substr($result['error'], 0, 250),
            'last_sent' => date('Y-m-d H:i:s'),
        ), array('target_id' => (int) $target['target_id']));

        if ($log) {
            $status = $result['ok']
                ? 'Delivered to <b>' . htmlspecialchars($target['name']) . '</b> (HTTP ' . $result['code'] . ')'
                : 'Delivery to <b>' . htmlspecialchars($target['name']) . '</b> FAILED: ' . htmlspecialchars($result['error']);
            $servers = array();
            foreach ($events as $e) {
                $servers[(int) $e['server_id']][] = $e;
            }
            foreach ($servers as $server_id => $server_events) {
                if ($server_id <= 0) {
                    continue;
                }
                $line = htmlspecialchars(Formatter::eventLine($server_events[0]));
                psm_add_log($server_id, $target['type'], ($result['ok'] ? '✓ ' : '✗ ') . $status . ' — ' . $line);
            }
        }
        return $result;
    }

    /**
     * Send a synthetic test notification.
     */
    public function test(array $target)
    {
        $event = array(
            'server_id' => 0,
            'label' => 'Test monitor',
            'ip' => 'https://example.com',
            'port' => '',
            'type' => 'website',
            'status' => 'down',
            'error' => 'This is a test notification from ' . PSM_BRAND_NAME . ' — no action needed.',
            'down_for' => '',
            'date' => date('Y-m-d H:i:s'),
            'url' => psm_dashboard_url(),
        );
        $saved = $this->retryDelay;
        $this->retryDelay = -1;
        $result = $this->send($target, array($event), false);
        $this->retryDelay = $saved;
        return $result;
    }

    /**
     * Fetch notify.* services from a Home Assistant instance.
     * @return array ['ok' => bool, 'services' => string[], 'error' => string]
     */
    public function homeAssistantServices($base_url, $token, $verify_tls = true)
    {
        $result = $this->http(array(
            'url' => rtrim($base_url, '/') . '/api/services',
            'headers' => array('Authorization: Bearer ' . $token, 'Content-Type: application/json'),
            'body' => null,
            'verify_tls' => $verify_tls,
        ));
        if (!$result['ok']) {
            return array('ok' => false, 'services' => array(), 'error' => $result['error']);
        }
        $services = array();
        foreach ((array) json_decode($result['response'], true) as $domain) {
            if (($domain['domain'] ?? '') === 'notify') {
                foreach (array_keys((array) ($domain['services'] ?? array())) as $name) {
                    if ($name !== 'send_message') {
                        $services[] = 'notify.' . $name;
                    }
                }
            }
        }
        sort($services);
        return array('ok' => true, 'services' => $services, 'error' => '');
    }

    /**
     * @param array $request url, headers, body (null = GET), verify_tls
     * @return array ok, code, error, response
     */
    protected function http(array $request)
    {
        if (empty($request['url']) || !preg_match('#^https?://#i', $request['url'])) {
            return array('ok' => false, 'code' => 0, 'error' => 'Invalid or missing URL', 'response' => '');
        }
        $ch = curl_init($request['url']);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $request['headers'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => (bool) $request['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $request['verify_tls'] ? 2 : 0,
            CURLOPT_USERAGENT => PSM_BRAND_NAME . '/' . PSM_VERSION,
        ));
        if ($request['body'] !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
        }
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return array('ok' => false, 'code' => 0, 'error' => $curl_error ?: 'Connection failed', 'response' => '');
        }
        if ($code < 200 || $code >= 300) {
            $snippet = trim(strip_tags(mb_substr((string) $response, 0, 160)));
            return array('ok' => false, 'code' => $code, 'error' => 'HTTP ' . $code . ($snippet !== '' ? ': ' . $snippet : ''), 'response' => (string) $response);
        }
        return array('ok' => true, 'code' => $code, 'error' => '', 'response' => (string) $response);
    }
}
