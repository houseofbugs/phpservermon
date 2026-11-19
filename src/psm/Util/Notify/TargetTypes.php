<?php

/**
 * DFS Monitor — notification target type registry.
 *
 * This file is part of DFS Monitor, a fork of PHP Server Monitor (GPL-3.0-or-later).
 */

namespace psm\Util\Notify;

class TargetTypes
{
    /**
     * Field definition keys: name, label, type (text|url|password|textarea|checkbox), required, help, placeholder
     * Fields with type "password" are stored encrypted.
     * @return array
     */
    public static function all()
    {
        return array(
            'discord' => array(
                'label' => 'Discord',
                'icon' => 'discord',
                'description' => 'Post rich embeds to a Discord channel via a channel webhook.',
                'fields' => array(
                    array('name' => 'url', 'label' => 'Webhook URL', 'type' => 'url', 'required' => true,
                        'placeholder' => 'https://discord.com/api/webhooks/…',
                        'help' => 'Channel settings → Integrations → Webhooks → New webhook → Copy URL.'),
                    array('name' => 'username', 'label' => 'Bot display name', 'type' => 'text',
                        'placeholder' => 'DFS Monitor'),
                    array('name' => 'mention', 'label' => 'Mention on DOWN', 'type' => 'text',
                        'placeholder' => '@here  or  <@&ROLE_ID>',
                        'help' => 'Optional text prepended when something goes down.'),
                ),
            ),
            'teams' => array(
                'label' => 'Microsoft Teams',
                'icon' => 'microsoft-teams',
                'description' => 'Send Adaptive Cards to a Teams channel or chat using a Workflows webhook.',
                'fields' => array(
                    array('name' => 'url', 'label' => 'Workflow webhook URL', 'type' => 'url', 'required' => true,
                        'placeholder' => 'https://….logic.azure.com/workflows/…  or  …powerautomate…',
                        'help' => 'In Teams: channel ⋯ → Workflows → "Post to a channel when a webhook request is received" → copy the URL.'),
                ),
            ),
            'homeassistant' => array(
                'label' => 'Home Assistant',
                'icon' => 'house-gear',
                'description' => 'Call a Home Assistant notify service (mobile app push, persistent notification, etc.).',
                'fields' => array(
                    array('name' => 'base_url', 'label' => 'Home Assistant URL', 'type' => 'url', 'required' => true,
                        'placeholder' => 'http://homeassistant.local:8123'),
                    array('name' => 'token', 'label' => 'Long-lived access token', 'type' => 'password', 'required' => true,
                        'help' => 'Profile → Security → Long-lived access tokens → Create token.'),
                    array('name' => 'service', 'label' => 'Notify service', 'type' => 'text', 'required' => true,
                        'placeholder' => 'notify.mobile_app_my_phone',
                        'help' => 'Use “Load services” to pick from your Home Assistant.'),
                    array('name' => 'verify_tls', 'label' => 'Verify TLS certificate', 'type' => 'checkbox', 'default' => '1'),
                ),
            ),
            'webhook' => array(
                'label' => 'Webhook',
                'icon' => 'braces',
                'description' => 'POST a JSON payload to any URL — n8n, Zapier, Node-RED, custom APIs.',
                'fields' => array(
                    array('name' => 'url', 'label' => 'URL', 'type' => 'url', 'required' => true,
                        'placeholder' => 'https://example.com/hooks/dfs-monitor'),
                    array('name' => 'headers', 'label' => 'Custom headers', 'type' => 'textarea',
                        'placeholder' => "Authorization: Bearer abc123\nX-Env: production",
                        'help' => 'One "Name: value" per line.'),
                    array('name' => 'secret', 'label' => 'Signing secret', 'type' => 'password',
                        'help' => 'If set, requests include X-DFS-Signature: sha256=HMAC(body, secret).'),
                    array('name' => 'verify_tls', 'label' => 'Verify TLS certificate', 'type' => 'checkbox', 'default' => '1',
                        'help' => 'Turn off only for LAN endpoints with self-signed certificates.'),
                ),
            ),
        );
    }

    public static function get($type)
    {
        $all = self::all();
        return isset($all[$type]) ? $all[$type] : null;
    }

    public static function exists($type)
    {
        return self::get($type) !== null;
    }

    public static function secretFields($type)
    {
        $def = self::get($type);
        $out = array();
        foreach ($def ? $def['fields'] : array() as $field) {
            if ($field['type'] === 'password') {
                $out[] = $field['name'];
            }
        }
        return $out;
    }
}
