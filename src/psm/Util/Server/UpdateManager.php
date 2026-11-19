<?php

/**
 * PHP Server Monitor
 * Monitor your servers and websites.
 *
 * This file is part of PHP Server Monitor.
 * PHP Server Monitor is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP Server Monitor is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP Server Monitor.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package     phpservermon
 * @author      Pepijn Over <pep@mailbox.org>
 * @copyright   Copyright (c) 2008-2017 Pepijn Over <pep@mailbox.org>
 * @license     http://www.gnu.org/licenses/gpl.txt GNU GPL v3
 * @version     Release: @package_version@
 * @link        http://www.phpservermonitor.org/
 * @since       phpservermon 3.0.0
 **/

namespace psm\Util\Server;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Run an update on all servers.
 */
class UpdateManager implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Go :-)
     *
     * @param boolean $skip_perms if TRUE, no user permissions will be taken in account and all servers will be updated
     * @param string|null $status If all servers (null), or just `on` or `off` should be checked.
     * @param bool|null $force When true, check all servers regardless of check_interval.
     */
    public function run($skip_perms = false, $status = null, $force = null)
    {
        if ($force === null) {
            $force = !$skip_perms;
        }
    	if (false === in_array($status, ['on', 'off'], true)) {
    		$status = null;
	    }

        $db = $this->container->get('db');
        (new \psm\Util\Install\Installer($db))->ensureCheckIntervalColumn();

        // check if we need to restrict the servers to a certain user
        $sql_join = '';

        if (!$skip_perms && $this->container->get('user')->getUserLevel() > PSM_USER_ADMIN) {
            // restrict by user_id
            $sql_join = "JOIN `" . PSM_DB_PREFIX . "users_servers` AS `us` ON (
						`us`.`user_id`={$this->container->get('user')->getUserId()}
						AND `us`.`server_id`=`s`.`server_id`
						)";
        }

        $sql = "SELECT `s`.`server_id`,`s`.`ip`,`s`.`port`,`s`.`label`,`s`.`type`,`s`.`pattern`,`s`.`header_name`,
            `s`.`header_value`,`s`.`status`,`s`.`active`,`s`.`email`,`s`.`check_interval`,`s`.`last_check`,
            `s`.`warning_threshold_counter`
				FROM `" . PSM_DB_PREFIX . "servers` AS `s`
				{$sql_join}
				WHERE `active`='yes' " . ($status !== null ? ' AND `status` = \'' . $status . '\'' : '');

        $servers = $db->query($sql);

        $updater = new Updater\StatusUpdater($db);
        $notifier = new Updater\StatusNotifier($db);
        $now = time();

        foreach ($servers as $server) {
            if (!$force && $server['status'] !== 'off' && (int) ($server['warning_threshold_counter'] ?? 0) === 0) {
                $last_check = (string) ($server['last_check'] ?? '');
                if ($last_check !== '' && $last_check !== '0000-00-00 00:00:00') {
                    $interval_min = max(1, (int) ($server['check_interval'] ?? 1));
                    $last_ts = strtotime($last_check);
                    if ($last_ts !== false && ($now - $last_ts) < ($interval_min * 60 - 15)) {
                        continue;
                    }
                }
            }

            $status_old = ($server['status'] == 'on') ? true : false;
            $status_new = $updater->update($server['server_id']);
            // notify the nerds if applicable
            $notifier->notify($server['server_id'], $status_old, $status_new);
            // clean-up time!! archive all records
            $archive = new ArchiveManager($db);
            $archive->archive($server['server_id']);
            $archive->cleanup($server['server_id']);
        }
        if ($notifier->combine) {
            $notifier->notifyCombined();
        }
    }
}
