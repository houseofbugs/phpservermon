<?php

/**
 * DFS Monitor — Notification targets (Discord, Teams, Home Assistant, Webhook).
 *
 * This file is part of DFS Monitor, a fork of PHP Server Monitor (GPL-3.0-or-later).
 */

namespace psm\Module\Target\Controller;

use psm\Module\AbstractController;
use psm\Service\Database;
use psm\Util\Notify\TargetManager;
use psm\Util\Notify\TargetTypes;
use Symfony\Component\HttpFoundation\JsonResponse;

class TargetController extends AbstractController
{
    /** @var TargetManager */
    protected $manager;

    public function __construct(Database $db, \Twig\Environment $twig)
    {
        parent::__construct($db, $twig);

        $this->setMinUserLevelRequired(PSM_USER_ADMIN);
        $this->setCSRFKey('target');
        $this->setActions(array('index', 'edit', 'save', 'delete', 'test', 'services', 'applyall'), 'index');
        $this->twig->addGlobal('subtitle', psm_get_lang('menu', 'target'));
        $this->manager = new TargetManager($db);
    }

    protected function executeIndex()
    {
        $modal = new \psm\Util\Module\Modal($this->twig, 'delete', \psm\Util\Module\Modal::MODAL_TYPE_DANGER);
        $this->addModal($modal);
        $modal->setTitle('Delete notification target');
        $modal->setMessage('Delete target <b>%1</b>? Monitors using it will stop sending to it.');
        $modal->setOKButtonLabel(psm_get_lang('system', 'delete'));

        $targets = $this->manager->all();
        foreach ($targets as &$t) {
            $t['url_edit'] = psm_build_url(array('mod' => 'target', 'action' => 'edit', 'id' => $t['target_id']));
            $t['url_delete'] = psm_build_url(array('mod' => 'target', 'action' => 'delete', 'id' => $t['target_id']));
            $t['url_applyall'] = psm_build_url(array('mod' => 'target', 'action' => 'applyall', 'id' => $t['target_id']));
            $t['last_sent_nice'] = $t['last_sent'] ? psm_timespan($t['last_sent']) : null;
        }
        unset($t);

        $types = array();
        foreach (TargetTypes::all() as $key => $def) {
            $types[] = array(
                'key' => $key,
                'label' => $def['label'],
                'icon' => $def['icon'],
                'description' => $def['description'],
                'url' => psm_build_url(array('mod' => 'target', 'action' => 'edit', 'type' => $key)),
            );
        }

        return $this->twig->render('module/target/list.tpl.html', array(
            'targets' => $targets,
            'types' => $types,
            'server_total' => count((array) $this->db->select(PSM_DB_PREFIX . 'servers', null, array('server_id'))),
            'label_edit' => psm_get_lang('system', 'edit'),
            'label_delete' => psm_get_lang('system', 'delete'),
        ));
    }

    protected function executeEdit()
    {
        $target_id = (int) psm_GET('id', 0);
        $target = $target_id ? $this->manager->get($target_id) : null;
        if ($target_id && !$target) {
            $this->addMessage('Notification target not found.', 'error');
            return $this->runAction('index');
        }
        $type = $target ? $target['type'] : (string) psm_GET('type', psm_POST('type', ''));
        $def = TargetTypes::get($type);
        if (!$def) {
            return $this->runAction('index');
        }

        $values = $target ? $target['settings'] : array();
        $posted = psm_POST('settings');
        if (is_array($posted)) {
            $values = array_merge($values, $posted);
        }
        $fields = array();
        foreach ($def['fields'] as $field) {
            $name = $field['name'];
            $field['value'] = isset($values[$name]) ? $values[$name] : ($field['default'] ?? '');
            if ($field['type'] === 'password') {
                $field['has_value'] = $target && !empty($target['settings'][$name]);
                $field['value'] = '';
            }
            $fields[] = $field;
        }

        return $this->twig->render('module/target/update.tpl.html', array(
            'target_id' => $target_id,
            'type' => $type,
            'type_def' => $def,
            'fields' => $fields,
            'name' => psm_POST('name', $target ? $target['name'] : $def['label']),
            'is_default' => $target ? (int) $target['is_default'] : (int) psm_POST('is_default', 0),
            'active' => $target ? (int) $target['active'] : 1,
            'url_save' => psm_build_url(array('mod' => 'target', 'action' => 'save', 'id' => $target_id)),
            'url_back' => psm_build_url(array('mod' => 'target')),
            'titlemode' => $target ? 'Edit ' . $target['name'] : 'New ' . $def['label'] . ' target',
        ));
    }

    protected function executeSave()
    {
        if (empty($_POST)) {
            return $this->runAction('index');
        }
        $target_id = (int) psm_GET('id', 0);
        $existing = $target_id ? $this->manager->get($target_id) : null;
        $type = $existing ? $existing['type'] : (string) psm_POST('type', '');
        $def = TargetTypes::get($type);
        if (!$def) {
            $this->addMessage('Unknown target type.', 'error');
            return $this->runAction('index');
        }

        $name = trim(strip_tags((string) psm_POST('name', '')));
        $settings = (array) psm_POST('settings', array());
        $errors = array();
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        foreach ($def['fields'] as $field) {
            $value = trim((string) ($settings[$field['name']] ?? ''));
            $has_stored = $existing && !empty($existing['settings'][$field['name']]);
            if (!empty($field['required']) && $value === '' && !($field['type'] === 'password' && $has_stored)) {
                $errors[] = $field['label'] . ' is required.';
            }
            if ($field['type'] === 'url' && $value !== '' && !preg_match('#^https?://#i', $value)) {
                $errors[] = $field['label'] . ' must start with http:// or https://';
            }
        }
        if ($errors) {
            foreach ($errors as $error) {
                $this->addMessage($error, 'error');
            }
            $_GET['type'] = $type;
            return $this->executeEdit();
        }

        $is_new = !$target_id;
        $target_id = $this->manager->save(array(
            'name' => $name,
            'type' => $type,
            'settings' => $settings,
            'is_default' => psm_POST('is_default') ? 1 : 0,
            'active' => psm_POST('active') ? 1 : 0,
        ), $target_id);

        if ($is_new && psm_POST('apply_all')) {
            $this->manager->applyToAllServers($target_id);
            $this->addMessage('Target <b>' . htmlspecialchars($name) . '</b> created and attached to all monitors.', 'success');
        } else {
            $this->addMessage('Target <b>' . htmlspecialchars($name) . '</b> saved.', 'success');
        }
        return $this->runAction('index');
    }

    protected function executeDelete()
    {
        $target_id = (int) psm_GET('id', 0);
        if ($target_id && ($t = $this->manager->get($target_id))) {
            $this->manager->delete($target_id);
            $this->addMessage('Target <b>' . htmlspecialchars($t['name']) . '</b> deleted.', 'success');
        }
        return $this->runAction('index');
    }

    protected function executeApplyall()
    {
        $target_id = (int) psm_GET('id', 0);
        if ($target_id && ($t = $this->manager->get($target_id))) {
            $this->manager->applyToAllServers($target_id);
            $this->addMessage('Target <b>' . htmlspecialchars($t['name']) . '</b> attached to all monitors.', 'success');
        }
        return $this->runAction('index');
    }

    /**
     * XHR: send a test notification using the (possibly unsaved) form values.
     */
    protected function executeTest()
    {
        $target = $this->targetFromRequest();
        if (!$target) {
            return new JsonResponse(array('ok' => false, 'error' => 'Unknown target type'));
        }
        $result = $this->manager->test($target);
        return new JsonResponse(array(
            'ok' => $result['ok'],
            'code' => $result['code'],
            'error' => $result['error'],
        ));
    }

    /**
     * XHR: list notify services from Home Assistant.
     */
    protected function executeServices()
    {
        $target = $this->targetFromRequest();
        if (!$target || $target['type'] !== 'homeassistant') {
            return new JsonResponse(array('ok' => false, 'error' => 'Not a Home Assistant target'));
        }
        $s = $target['settings'];
        if (empty($s['base_url']) || empty($s['token'])) {
            return new JsonResponse(array('ok' => false, 'error' => 'Enter the Home Assistant URL and token first.'));
        }
        return new JsonResponse($this->manager->homeAssistantServices(
            $s['base_url'],
            $s['token'],
            ($s['verify_tls'] ?? '1') === '1'
        ));
    }

    /**
     * Build an in-memory target from POST data; blank secrets fall back to stored values.
     * @return array|null
     */
    protected function targetFromRequest()
    {
        $target_id = (int) psm_GET('id', psm_POST('target_id', 0));
        $existing = $target_id ? $this->manager->get($target_id) : null;
        $type = $existing ? $existing['type'] : (string) psm_POST('type', '');
        $def = TargetTypes::get($type);
        if (!$def) {
            return null;
        }
        $posted = (array) psm_POST('settings', array());
        $settings = array();
        foreach ($def['fields'] as $field) {
            $name = $field['name'];
            $value = trim((string) ($posted[$name] ?? ''));
            if ($field['type'] === 'checkbox') {
                $value = $value === '1' ? '1' : '0';
            }
            if ($field['type'] === 'password' && $value === '' && $existing) {
                $value = (string) ($existing['settings'][$name] ?? '');
            }
            $settings[$name] = $value;
        }
        return array(
            'target_id' => $target_id,
            'name' => (string) psm_POST('name', $existing ? $existing['name'] : $def['label']),
            'type' => $type,
            'settings' => $settings,
        );
    }
}
