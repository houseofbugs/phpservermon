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
 **/

namespace psm\Module\Config\Controller;

use psm\Module\AbstractController;
use psm\Service\Database;

class ConfigController extends AbstractController
{

    /**
     * Checkboxes
     * @var array $checkboxes
     */
    protected $checkboxes = array(
        'proxy',
        'email_status',
        'email_smtp',
        'log_status',
        'log_email',
        'show_update',
        'combine_notifications',
    );

    /**
     * Fields for saving
     * @var array $fields
     */
    protected $fields = array(
        'site_title',
        'proxy_url',
        'proxy_user',
        'proxy_password',
        'email_from_name',
        'email_from_email',
        'email_smtp_host',
        'email_smtp_port',
        'email_smtp_username',
    );

    /**
     * Fields for saving encrypted.
     * @var array
     */
    protected $encryptedFields = [
        'email_smtp_password',
    ];

    private $default_tab = 'general';

    public function __construct(Database $db, \Twig\Environment $twig)
    {
        parent::__construct($db, $twig);

        $this->setMinUserLevelRequired(PSM_USER_ADMIN);
        $this->setCSRFKey('config');

        $this->setActions(array(
            'index', 'save',
        ), 'index');
    }

    /**
     * Populate all the config fields with values from the database
     *
     * @return string
     */
    protected function executeIndex()
    {
        $this->twig->addGlobal('subtitle', psm_get_lang('menu', 'config'));
        $tpl_data = $this->getLabels();

        $config_db = $this->db->select(
            PSM_DB_PREFIX . 'config',
            null,
            array('key', 'value')
        );

        $config = array();
        foreach ($config_db as $entry) {
            $config[$entry['key']] = $entry['value'];
        }

        // generate language array
        $lang_keys = psm_get_langs();
        $tpl_data['language_current'] = (isset($config['language']))
                ? $config['language']
                : 'en_US';
        $tpl_data['languages'] = array();
        foreach ($lang_keys as $key => $label) {
            $tpl_data['languages'][] = array(
                'value' => $key,
                'label' => $label,
            );
        }

        foreach (array("status", "offline", "always") as $alert_type) {
            $tpl_data['alert_type'][] = array(
                'value' => $alert_type,
                'label' => psm_get_lang('config', 'alert_type_' . $alert_type),
            );
        }

        $tpl_data['email_smtp_security'] = array(
            array(
                'value' => '',
                'label' => psm_get_lang('config', 'email_smtp_security_none')
            ),
            array(
                'value' => 'ssl',
                'label' => 'SSL'
            ),
            array(
                'value' => 'tls',
                'label' => 'TLS'
            )
        );

        $tpl_data['alert_type_selected'] = isset($config['alert_type']) ?
            $config['alert_type'] : '';
        $tpl_data['email_smtp_security_selected'] =  isset($config['email_smtp_security']) ?
            $config['email_smtp_security'] : '';
        $tpl_data['auto_refresh_servers'] = isset($config['auto_refresh_servers']) ?
            $config['auto_refresh_servers'] : '0';
        $tpl_data['log_retention_period'] = isset($config['log_retention_period']) ?
            $config['log_retention_period'] : '365';
        $tpl_data['password_encrypt_key'] = isset($config['password_encrypt_key']) ?
            $config['password_encrypt_key'] : sha1(microtime());

        foreach ($this->checkboxes as $input_key) {
            $tpl_data[$input_key . '_checked'] =
                (isset($config[$input_key]) && (int) $config[$input_key] == 1)
                ? 'checked="checked"'
                : '';
        }
        foreach ($this->fields as $input_key) {
            $tpl_data[$input_key] = (isset($config[$input_key])) ? $config[$input_key] : '';
        }
        if (empty($tpl_data['site_title'])) {
            $tpl_data['site_title'] = PSM_BRAND_NAME;
        }
        // encrypted fields
        foreach ($this->encryptedFields as $encryptedField) {
            $tpl_data[$encryptedField] = '';
        }

        $tpl_data[$this->default_tab . '_active'] = 'active';

        $testmodals = array('email');
        foreach ($testmodals as $modal_id) {
            $modal = new \psm\Util\Module\Modal(
                $this->twig,
                'test' . ucfirst($modal_id),
                \psm\Util\Module\Modal::MODAL_TYPE_OKCANCEL
            );
            $this->addModal($modal);
            $modal->setTitle(psm_get_lang('servers', 'send_' . $modal_id));
            $modal->setMessage(psm_get_lang('config', 'test_' . $modal_id));
            $modal->setOKButtonLabel(psm_get_lang('config', 'send'));
        }

        return $this->twig->render('module/config/config.tpl.html', $tpl_data);
    }

    /**
     * If a post has been done, gather all the posted data
     * and save it to the database
     */
    protected function executeSave()
    {
        if (!empty($_POST)) {
        	// save new config
            $clean = array(
                'language' => $_POST['language'],
                'alert_type' => $_POST['alert_type'],
                'email_smtp_security' =>
                    in_array($_POST['email_smtp_security'], array('', 'ssl', 'tls'))
                    ? $_POST['email_smtp_security']
                    : '',
                'auto_refresh_servers' => intval(psm_POST('auto_refresh_servers', 0)),
                'log_retention_period' => intval(psm_POST('log_retention_period', 365)),
                'password_encrypt_key' => psm_POST('password_encrypt_key', sha1(microtime()))
            );
	        foreach ($this->checkboxes as $input_key) {
                $clean[$input_key] = (isset($_POST[$input_key])) ? '1' : '0';
            }
            foreach ($this->fields as $input_key) {
                if (isset($_POST[$input_key])) {
                    $clean[$input_key] = $_POST[$input_key];
                }
            }
            foreach ($this->encryptedFields as $encryptedField) {
                $value = filter_input(INPUT_POST, $encryptedField);
                if ($value !== null && $value !== '') {
                    $clean[$encryptedField] =  psm_password_encrypt(psm_get_conf('password_encrypt_key'), $value);
                }
                // else { leave as is }
            }
            $language_refresh = ($clean['language'] != psm_get_conf('language'));
            foreach ($clean as $key => $value) {
                psm_update_conf($key, $value);
            }
            $this->addMessage(psm_get_lang('config', 'updated'), 'success');

            if (!empty($_POST['test_email'])) {
                $this->testEmail();
            }

            if ($language_refresh) {
                header('Location: ' . psm_build_url(array('mod' => 'config'), true, false));
                die();
            }

            if (isset($_POST['general_submit'])) {
                $this->default_tab = 'general';
            } elseif (isset($_POST['email_submit']) || !empty($_POST['test_email'])) {
                $this->default_tab = 'email';
            }
        }
        return $this->runAction('index');
    }

    /**
     * Execute email test
     *
     * @todo move test to separate class
     */
    protected function testEmail()
    {
        $mail = psm_build_mail();
        list($subject, $html, $text) = \psm\Util\Notify\EmailRenderer::render(array(array(
            'server_id' => 0, 'label' => 'Test monitor', 'ip' => 'https://example.com', 'port' => '',
            'type' => 'website', 'status' => 'up', 'error' => '',
            'down_for' => '', 'date' => date('Y-m-d H:i:s'), 'url' => psm_dashboard_url(),
        )));
        $mail->isHTML(true);
        $mail->Subject  = 'Test email — ' . $subject;
        $mail->Priority = 1;
        $mail->Body = $html;
        $mail->AltBody  = $text;
        $user = $this->getUser()->getUser();
        $mail->AddAddress($user->email, $user->name);
        if ($mail->Send()) {
            $this->addMessage(psm_get_lang('config', 'email_sent'), 'success');
        } else {
            $this->addMessage(psm_get_lang('config', 'email_error') . ': ' . $mail->ErrorInfo, 'error');
        }
    }


    protected function getLabels()
    {
        return array(
            'label_tab_email' => psm_get_lang('config', 'tab_email'),
            'label_settings_email' => psm_get_lang('config', 'settings_email'),
            'label_settings_notification' => psm_get_lang('config', 'settings_notification'),
            'label_settings_log' => psm_get_lang('config', 'settings_log'),
            'label_settings_proxy' => psm_get_lang('config', 'settings_proxy'),
            'label_general' => psm_get_lang('config', 'general'),
            'label_site_title' => psm_get_lang('config', 'site_title'),
            'label_language' => psm_get_lang('config', 'language'),
            'label_show_update' => psm_get_lang('config', 'show_update'),
            'label_password_encrypt_key' => psm_get_lang('config', 'password_encrypt_key'),
            'label_password_encrypt_key_note' => psm_get_lang('config', 'password_encrypt_key_note'),
            'label_proxy' => psm_get_lang('config', 'proxy'),
            'label_proxy_url' => psm_get_lang('config', 'proxy_url'),
            'label_proxy_user' => psm_get_lang('config', 'proxy_user'),
            'label_proxy_password' => psm_get_lang('config', 'proxy_password'),
            'label_email_status' => psm_get_lang('config', 'email_status'),
            'label_email_from_email' => psm_get_lang('config', 'email_from_email'),
            'label_email_from_name' => psm_get_lang('config', 'email_from_name'),
            'label_email_smtp' => psm_get_lang('config', 'email_smtp'),
            'label_email_smtp_host' => psm_get_lang('config', 'email_smtp_host'),
            'label_email_smtp_port' => psm_get_lang('config', 'email_smtp_port'),
            'label_email_smtp_security' => psm_get_lang('config', 'email_smtp_security'),
            'label_email_smtp_username' => psm_get_lang('config', 'email_smtp_username'),
            'label_email_smtp_password' => psm_get_lang('config', 'email_smtp_password'),
            'label_email_smtp_noauth' => psm_get_lang('config', 'email_smtp_noauth'),
            'label_alert_type' => psm_get_lang('config', 'alert_type'),
            'label_alert_type_description' => psm_get_lang('config', 'alert_type_description'),
            'label_combine_notifications' => psm_get_lang('config', 'combine_notifications'),
            'label_combine_notifications_description' => psm_get_lang('config', 'combine_notifications_description'),
            'label_log_status' => psm_get_lang('config', 'log_status'),
            'label_log_status_description' => psm_get_lang('config', 'log_status_description'),
            'label_log_email' => psm_get_lang('config', 'log_email'),
            'label_alert_proxy' => psm_get_lang('config', 'alert_proxy'),
            'label_alert_proxy_url' => psm_get_lang('config', 'alert_proxy_url'),
            'label_auto_refresh' => psm_get_lang('config', 'auto_refresh'),
            'label_auto_refresh_description' => psm_get_lang('config', 'auto_refresh_description'),
            'label_seconds' => psm_get_lang('config', 'seconds'),
            'label_save' => psm_get_lang('system', 'save'),
            'label_test' => psm_get_lang('config', 'test'),
            'label_log_retention_period' => psm_get_lang('config', 'log_retention_period'),
            'label_log_retention_period_description' => psm_get_lang('config', 'log_retention_period_description'),
            'label_log_retention_days' => psm_get_lang('config', 'log_retention_days'),
            'label_days' => psm_get_lang('config', 'log_retention_days'),
            'label_leave_blank' => psm_get_lang('users', 'password_leave_blank'),

        );
    }
}
