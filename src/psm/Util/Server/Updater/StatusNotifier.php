<?php

/**
 * PHP Server Monitor / DFS Monitor
 *
 * This file is part of DFS Monitor, a fork of PHP Server Monitor.
 * It is free software: you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later version.
 *
 * @package     phpservermon
 * @license     http://www.gnu.org/licenses/gpl.txt GNU GPL v3
 **/

/**
 * Sends notifications when a server changes status:
 *  - Email (per user that has access to the server)
 *  - Notification targets (Webhook, Discord, Microsoft Teams, Home Assistant)
 *
 * @see \psm\Util\Server\Updater\StatusUpdater
 */
namespace psm\Util\Server\Updater;

use psm\Service\Database;
use psm\Util\Notify\EmailRenderer;
use psm\Util\Notify\TargetManager;

class StatusNotifier
{
    /** @var Database */
    protected $db;

    /** @var TargetManager */
    protected $targets;

    /** @var bool */
    protected $send_emails = false;

    /** @var bool */
    protected $save_logs = false;

    /**
     * Send multiple notifications as one (per recipient / per target, per cron run)?
     * @var bool
     */
    public $combine = false;

    /**
     * Queued events when combining.
     * @var array
     */
    protected $queue = array(
        'email' => array(),   // user_id => ['user' => row, 'events' => []]
        'targets' => array(), // target_id => ['target' => row, 'events' => []]
    );

    /** @var int */
    protected $server_id;

    /** @var array */
    protected $server;

    /** @var bool */
    protected $status_old;

    /** @var bool */
    protected $status_new;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->targets = new TargetManager($db);
        $this->send_emails = (bool) psm_get_conf('email_status');
        $this->save_logs = (bool) psm_get_conf('log_status');
        $this->combine = (bool) psm_get_conf('combine_notifications');
    }

    /**
     * Decide whether a status change warrants a notification and deliver / queue it.
     *
     * @param int $server_id
     * @param bool $status_old
     * @param bool $status_new
     * @return bool TRUE if a notification was triggered
     */
    public function notify($server_id, $status_old, $status_new)
    {
        $this->server_id = (int) $server_id;
        $this->status_old = $status_old;
        $this->status_new = $status_new;

        $this->server = $this->db->selectRow(PSM_DB_PREFIX . 'servers', array(
            'server_id' => $server_id,
        ), array(
            'server_id', 'ip', 'port', 'label', 'type', 'error', 'email',
            'last_online', 'last_offline', 'last_offline_duration',
        ));
        if (empty($this->server)) {
            return false;
        }

        $notify = false;
        switch (psm_get_conf('alert_type')) {
            case 'always':
                $notify = ($status_new == false);
                break;
            case 'offline':
                $notify = ($status_new == false && $status_old == true);
                break;
            case 'status':
            default:
                $notify = ($status_new != $status_old);
                break;
        }
        if (!$notify) {
            return false;
        }

        if ($this->save_logs) {
            psm_add_log($this->server_id, 'status', psm_parse_msg($status_new, 'sms', $this->server));
        }

        $event = $this->buildEvent();

        // Email: every user with access to this server
        if ($this->send_emails && $this->server['email'] == 'yes') {
            foreach ($this->getUsers($this->server_id) as $user) {
                if (trim((string) $user['email']) === '') {
                    continue;
                }
                if ($this->combine) {
                    $uid = (int) $user['user_id'];
                    if (!isset($this->queue['email'][$uid])) {
                        $this->queue['email'][$uid] = array('user' => $user, 'events' => array());
                    }
                    $this->queue['email'][$uid]['events'][] = $event;
                } else {
                    $this->sendEmail($user, array($event));
                }
            }
        }

        // Notification targets attached to this server
        foreach ($this->targets->forServer($this->server_id) as $target) {
            if ($this->combine) {
                $tid = (int) $target['target_id'];
                if (!isset($this->queue['targets'][$tid])) {
                    $this->queue['targets'][$tid] = array('target' => $target, 'events' => array());
                }
                $this->queue['targets'][$tid]['events'][] = $event;
            } else {
                $this->targets->send($target, array($event));
            }
        }

        return true;
    }

    /**
     * Flush combined notifications (called once at the end of a cron run).
     */
    public function notifyCombined()
    {
        foreach ($this->queue['email'] as $item) {
            $this->sendEmail($item['user'], $item['events']);
        }
        foreach ($this->queue['targets'] as $item) {
            $this->targets->send($item['target'], $item['events']);
        }
        $this->queue = array('email' => array(), 'targets' => array());
    }

    /**
     * @return array event
     */
    protected function buildEvent()
    {
        return array(
            'server_id' => (int) $this->server['server_id'],
            'label' => (string) $this->server['label'],
            'ip' => (string) $this->server['ip'],
            'port' => (string) $this->server['port'],
            'type' => (string) $this->server['type'],
            'status' => $this->status_new ? 'up' : 'down',
            'error' => trim((string) $this->server['error']),
            'down_for' => trim((string) $this->server['last_offline_duration']),
            'date' => date('Y-m-d H:i:s'),
            'url' => psm_monitor_url($this->server['server_id']),
        );
    }

    /**
     * Send one (possibly combined) HTML email to a user.
     * @param array $user
     * @param array $events
     */
    protected function sendEmail(array $user, array $events)
    {
        list($subject, $html, $text) = EmailRenderer::render($events);
        $mail = psm_build_mail();
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Priority = 1;
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->addAddress($user['email'], $user['name']);
        $ok = false;
        try {
            $ok = $mail->send();
        } catch (\Exception $e) {
            $ok = false;
        }

        if (psm_get_conf('log_email')) {
            $message = ($ok ? '✓ Emailed ' : '✗ Email FAILED to ') . htmlspecialchars($user['email'])
                . ($ok ? '' : ': ' . htmlspecialchars($mail->ErrorInfo))
                . ' — ' . htmlspecialchars($subject);
            $servers = array();
            foreach ($events as $e) {
                $servers[(int) $e['server_id']] = true;
            }
            foreach (array_keys($servers) as $server_id) {
                $log_id = psm_add_log($server_id, 'email', $message);
                if (!empty($log_id)) {
                    psm_add_log_user($log_id, $user['user_id']);
                }
            }
        }
    }

    /**
     * Get all users for the provided server id
     * @param int $server_id
     * @return array
     */
    public function getUsers($server_id)
    {
        $users = $this->db->query('
            SELECT `u`.`user_id`, `u`.`name`, `u`.`email`
            FROM `' . PSM_DB_PREFIX . 'users` AS `u`
            JOIN `' . PSM_DB_PREFIX . 'users_servers` AS `us` ON (
                `us`.`user_id` = `u`.`user_id`
                AND `us`.`server_id` = ' . (int) $server_id . '
            )');
        return is_array($users) ? $users : array();
    }
}
