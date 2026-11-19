<?php

/**
 * DFS Monitor — branded HTML email for status notifications.
 *
 * This file is part of DFS Monitor, a fork of PHP Server Monitor (GPL-3.0-or-later).
 */

namespace psm\Util\Notify;

class EmailRenderer
{
    /**
     * @param array $events
     * @return array [subject, html, text]
     */
    public static function render(array $events)
    {
        list($down) = Formatter::counts($events);
        $subject = ($down ? '🔴 ' : '🟢 ') . Formatter::summaryTitle($events);
        $accent = $down ? '#ef4444' : '#22c55e';
        $e = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };

        $rows = '';
        $text = Formatter::summaryTitle($events) . "\n\n";
        foreach ($events as $ev) {
            $isDown = $ev['status'] === 'down';
            $color = $isDown ? '#ef4444' : '#22c55e';
            $badge = $isDown ? 'DOWN' : 'UP';
            $detail = $isDown
                ? ($ev['error'] !== '' ? $ev['error'] : 'No response')
                : ($ev['down_for'] !== '' ? 'Recovered after ' . $ev['down_for'] : 'Recovered');
            $link = $ev['url'] !== ''
                ? '<a href="' . $e($ev['url']) . '" style="color:#0891b2;text-decoration:none;font-weight:600;">View&nbsp;→</a>'
                : '';
            $rows .= '<tr><td style="padding:14px 20px;border-top:1px solid #e4e4e7;">'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
                . '<td style="font:600 15px/1.4 Inter,Segoe UI,Arial,sans-serif;color:#18181b;">'
                . '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . $color . ';color:#fff;font-size:11px;letter-spacing:.04em;margin-right:8px;">' . $badge . '</span>'
                . $e($ev['label']) . '</td>'
                . '<td align="right" style="font:13px Inter,Segoe UI,Arial,sans-serif;">' . $link . '</td></tr>'
                . '<tr><td colspan="2" style="padding-top:6px;font:13px/1.5 Inter,Segoe UI,Arial,sans-serif;color:#52525b;">'
                . $e(Formatter::host($ev)) . ' · ' . $e(ucfirst($ev['type'])) . ' · ' . $e($ev['date']) . '<br>'
                . '<span style="color:' . ($isDown ? '#b91c1c' : '#15803d') . ';">' . $e($detail) . '</span>'
                . '</td></tr></table></td></tr>';
            $text .= '- [' . $badge . '] ' . Formatter::eventLine($ev) . ' (' . $ev['date'] . ')'
                . ($ev['url'] !== '' ? "\n  " . $ev['url'] : '') . "\n";
        }

        $dashboard = psm_dashboard_url();
        $button = $dashboard !== ''
            ? '<tr><td style="padding:20px;border-top:1px solid #e4e4e7;"><a href="' . $e($dashboard) . '" '
                . 'style="display:inline-block;background:#0891b2;color:#fff;font:600 14px Inter,Segoe UI,Arial,sans-serif;'
                . 'padding:10px 18px;border-radius:8px;text-decoration:none;">Open ' . $e(PSM_BRAND_NAME) . '</a></td></tr>'
            : '';
        if ($dashboard !== '') {
            $text .= "\nDashboard: " . $dashboard . "\n";
        }

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f4f4f5;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f5;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e4e4e7;">'
            . '<tr><td style="height:4px;background:' . $accent . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:20px 20px 6px;font:700 13px Inter,Segoe UI,Arial,sans-serif;color:#0891b2;letter-spacing:.02em;">'
            . '<span style="display:inline-block;width:22px;height:22px;border-radius:6px;background:#0891b2;color:#fff;text-align:center;line-height:22px;margin-right:6px;">∿</span>'
            . $e(PSM_BRAND_NAME) . '</td></tr>'
            . '<tr><td style="padding:4px 20px 16px;font:700 20px/1.3 Inter,Segoe UI,Arial,sans-serif;color:#18181b;">'
            . $e(Formatter::summaryTitle($events)) . '</td></tr>'
            . $rows . $button
            . '</table>'
            . '<p style="font:12px Inter,Segoe UI,Arial,sans-serif;color:#a1a1aa;margin:16px 0 0;">Sent by '
            . $e(PSM_BRAND_NAME) . ' · ' . $e(date('Y-m-d H:i:s T')) . '</p>'
            . '</td></tr></table></body></html>';

        return array($subject, $html, $text);
    }
}
