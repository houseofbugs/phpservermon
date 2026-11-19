<?php

/**
 * DFS Monitor — builds outbound HTTP requests for notification targets.
 *
 * Event shape (array):
 *   server_id, label, ip, port, type, status ('down'|'up'), error, down_for, date, url
 *
 * This file is part of DFS Monitor, a fork of PHP Server Monitor (GPL-3.0-or-later).
 */

namespace psm\Util\Notify;

class Formatter
{
    const COLOR_DOWN = 0xEF4444;
    const COLOR_UP = 0x22C55E;

    /**
     * @param string $type
     * @param array $settings decrypted settings
     * @param array $events
     * @return array ['url' => string, 'headers' => string[], 'body' => string, 'verify_tls' => bool]
     */
    public static function build($type, array $settings, array $events)
    {
        switch ($type) {
            case 'discord':
                return self::discord($settings, $events);
            case 'teams':
                return self::teams($settings, $events);
            case 'homeassistant':
                return self::homeAssistant($settings, $events);
            case 'webhook':
            default:
                return self::webhook($settings, $events);
        }
    }

    public static function summaryTitle(array $events)
    {
        if (count($events) === 1) {
            $e = $events[0];
            return $e['status'] === 'down'
                ? $e['label'] . ' is DOWN'
                : $e['label'] . ' is back UP';
        }
        list($down, $up) = self::counts($events);
        $parts = array();
        if ($down) {
            $parts[] = $down . ' down';
        }
        if ($up) {
            $parts[] = $up . ' recovered';
        }
        return PSM_BRAND_NAME . ': ' . implode(', ', $parts);
    }

    public static function eventLine(array $e)
    {
        if ($e['status'] === 'down') {
            return $e['label'] . ' (' . self::host($e) . ') is DOWN' . ($e['error'] !== '' ? ': ' . $e['error'] : '');
        }
        return $e['label'] . ' (' . self::host($e) . ') is back UP'
            . ($e['down_for'] !== '' ? ' after ' . $e['down_for'] : '');
    }

    public static function counts(array $events)
    {
        $down = 0;
        $up = 0;
        foreach ($events as $e) {
            $e['status'] === 'down' ? $down++ : $up++;
        }
        return array($down, $up);
    }

    public static function host(array $e)
    {
        $host = (string) $e['ip'];
        if ($e['type'] === 'service' && !empty($e['port'])) {
            $host .= ':' . $e['port'];
        }
        return $host;
    }

    /* ------------------------------------------------------------------ webhook */

    protected static function webhook(array $s, array $events)
    {
        list($down, $up) = self::counts($events);
        $payload = array(
            'event' => 'monitor.status_changed',
            'source' => PSM_BRAND_NAME,
            'version' => PSM_VERSION,
            'sent_at' => gmdate('c'),
            'summary' => array('down' => $down, 'up' => $up, 'title' => self::summaryTitle($events)),
            'monitors' => array_map(function ($e) {
                return array(
                    'id' => (int) $e['server_id'],
                    'label' => $e['label'],
                    'host' => $e['ip'],
                    'port' => $e['port'] !== '' ? (int) $e['port'] : null,
                    'type' => $e['type'],
                    'status' => $e['status'],
                    'error' => $e['error'] !== '' ? $e['error'] : null,
                    'down_for' => $e['down_for'] !== '' ? $e['down_for'] : null,
                    'checked_at' => $e['date'],
                    'url' => $e['url'] !== '' ? $e['url'] : null,
                );
            }, $events),
        );
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = array('Content-Type: application/json', 'User-Agent: ' . PSM_BRAND_NAME . '/' . PSM_VERSION);
        foreach (preg_split('/\r\n|\r|\n/', (string) ($s['headers'] ?? '')) as $line) {
            $line = trim($line);
            if ($line !== '' && strpos($line, ':') > 0) {
                $headers[] = $line;
            }
        }
        if (!empty($s['secret'])) {
            $headers[] = 'X-DFS-Signature: sha256=' . hash_hmac('sha256', $body, $s['secret']);
        }
        return array(
            'url' => $s['url'],
            'headers' => $headers,
            'body' => $body,
            'verify_tls' => !isset($s['verify_tls']) || $s['verify_tls'] === '1',
        );
    }

    /* ------------------------------------------------------------------ discord */

    protected static function discord(array $s, array $events)
    {
        list($down) = self::counts($events);
        $embeds = array();
        if (count($events) <= 10) {
            foreach ($events as $e) {
                $fields = array(
                    array('name' => 'Host', 'value' => self::trunc(self::host($e), 1024), 'inline' => true),
                    array('name' => 'Type', 'value' => ucfirst($e['type']), 'inline' => true),
                );
                if ($e['status'] === 'down') {
                    $fields[] = array('name' => 'Error', 'value' => self::trunc($e['error'] !== '' ? $e['error'] : '—', 1024));
                } elseif ($e['down_for'] !== '') {
                    $fields[] = array('name' => 'Downtime', 'value' => self::trunc($e['down_for'], 1024), 'inline' => true);
                }
                $embed = array(
                    'title' => self::trunc(($e['status'] === 'down' ? '🔴 ' : '🟢 ') . self::summaryTitle(array($e)), 256),
                    'color' => $e['status'] === 'down' ? self::COLOR_DOWN : self::COLOR_UP,
                    'fields' => $fields,
                    'timestamp' => gmdate('c', strtotime($e['date'])),
                    'footer' => array('text' => PSM_BRAND_NAME),
                );
                if ($e['url'] !== '' && preg_match('#^https?://#', $e['url'])) {
                    $embed['url'] = $e['url'];
                }
                $embeds[] = $embed;
            }
        } else {
            $lines = array();
            foreach ($events as $e) {
                $lines[] = ($e['status'] === 'down' ? '🔴 ' : '🟢 ') . self::eventLine($e);
            }
            $embeds[] = array(
                'title' => self::trunc(self::summaryTitle($events), 256),
                'description' => self::trunc(implode("\n", $lines), 4000),
                'color' => $down ? self::COLOR_DOWN : self::COLOR_UP,
                'timestamp' => gmdate('c'),
                'footer' => array('text' => PSM_BRAND_NAME),
            );
        }
        $payload = array(
            'username' => !empty($s['username']) ? $s['username'] : PSM_BRAND_NAME,
            'embeds' => $embeds,
            'allowed_mentions' => array('parse' => array('everyone', 'roles', 'users')),
        );
        if ($down && !empty($s['mention'])) {
            $payload['content'] = $s['mention'];
        }
        return array(
            'url' => $s['url'],
            'headers' => array('Content-Type: application/json'),
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'verify_tls' => true,
        );
    }

    /* ------------------------------------------------------------------ teams */

    protected static function teams(array $s, array $events)
    {
        list($down) = self::counts($events);
        $body = array(
            array(
                'type' => 'TextBlock',
                'text' => self::summaryTitle($events),
                'weight' => 'Bolder',
                'size' => 'Large',
                'color' => $down ? 'Attention' : 'Good',
                'wrap' => true,
            ),
        );
        foreach ($events as $e) {
            $facts = array(
                array('title' => 'Host', 'value' => self::host($e)),
                array('title' => 'Type', 'value' => ucfirst($e['type'])),
            );
            if ($e['status'] === 'down') {
                $facts[] = array('title' => 'Error', 'value' => $e['error'] !== '' ? $e['error'] : '—');
            } elseif ($e['down_for'] !== '') {
                $facts[] = array('title' => 'Downtime', 'value' => $e['down_for']);
            }
            $facts[] = array('title' => 'Checked', 'value' => $e['date']);
            $body[] = array(
                'type' => 'Container',
                'separator' => true,
                'spacing' => 'Medium',
                'items' => array(
                    array(
                        'type' => 'TextBlock',
                        'text' => ($e['status'] === 'down' ? '🔴 ' : '🟢 ') . $e['label'],
                        'weight' => 'Bolder',
                        'color' => $e['status'] === 'down' ? 'Attention' : 'Good',
                        'wrap' => true,
                    ),
                    array('type' => 'FactSet', 'facts' => $facts),
                ),
            );
        }
        $card = array(
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'msteams' => array('width' => 'Full'),
            'body' => $body,
        );
        $open = count($events) === 1 ? $events[0]['url'] : psm_dashboard_url();
        if ($open !== '' && preg_match('#^https?://#', $open)) {
            $card['actions'] = array(
                array('type' => 'Action.OpenUrl', 'title' => 'Open in ' . PSM_BRAND_NAME, 'url' => $open),
            );
        }
        $head = self::summaryTitle($events);
        $payload = array(
            'type' => 'message',
            'summary' => $head,
            'text' => $head,
            'attachments' => array(
                array('contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null, 'content' => $card),
            ),
        );
        return array(
            'url' => $s['url'],
            'headers' => array('Content-Type: application/json'),
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'verify_tls' => true,
        );
    }

    /* ------------------------------------------------------------------ home assistant */

    public static function haService($service)
    {
        $service = trim((string) $service);
        if (stripos($service, 'notify.') === 0) {
            $service = substr($service, 7);
        }
        return preg_replace('/[^a-z0-9_]/i', '', $service);
    }

    protected static function homeAssistant(array $s, array $events)
    {
        list($down) = self::counts($events);
        $lines = array();
        foreach ($events as $e) {
            $lines[] = ($e['status'] === 'down' ? '🔴 ' : '🟢 ') . self::eventLine($e);
        }
        $single = count($events) === 1;
        $url = $single ? $events[0]['url'] : psm_dashboard_url();
        $data = array(
            'tag' => $single ? 'dfs-monitor-' . (int) $events[0]['server_id'] : 'dfs-monitor-summary',
            'group' => 'dfs-monitor',
            'push' => array('interruption-level' => $down ? 'time-sensitive' : 'active'),
            'ttl' => 0,
            'priority' => $down ? 'high' : 'normal',
            'channel' => $down ? 'DFS Monitor alerts' : 'DFS Monitor',
        );
        if ($url !== '') {
            $data['url'] = $url;
            $data['clickAction'] = $url;
        }
        $payload = array(
            'title' => self::summaryTitle($events),
            'message' => implode("\n", $lines),
            'data' => $data,
        );
        return array(
            'url' => rtrim($s['base_url'], '/') . '/api/services/notify/' . self::haService($s['service'] ?? ''),
            'headers' => array('Content-Type: application/json', 'Authorization: Bearer ' . ($s['token'] ?? '')),
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'verify_tls' => !isset($s['verify_tls']) || $s['verify_tls'] === '1',
        );
    }

    protected static function trunc($text, $max)
    {
        $text = (string) $text;
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
