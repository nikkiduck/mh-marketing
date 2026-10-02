<?php
/**
 * inc/roster_alerts.php — the roster change email.
 *
 * Nikki, 2026-09-22: Active means "with Mont Haus in the MLS". The MLS syncs
 * add new agents as Active on their own, and an agent the MLS stops listing
 * under Mont Haus moves to the roster's Inactive tab. Nothing about the website,
 * the Hot Sheets or FUB changes by itself: this email tells her who to look at,
 * and she takes them off (Offboard) or marks them still with MH by hand.
 *
 * Recipients: ROSTER_ALERT_EMAILS in inc/config.php (comma separated), falling
 * back to PIPELINE_NOTIFY_EMAILS. Sent through hs_send_email(), so until go-live
 * the Hot Sheet allowlist applies to it too.
 */

require_once __DIR__ . '/config.php';   // SITE_URL, recipients, HOT_SHEET_* for hs_mail
require_once __DIR__ . '/hs_mail.php';

function mk_roster_alert_recipients(): array {
    $raw = defined('ROSTER_ALERT_EMAILS') ? ROSTER_ALERT_EMAILS
         : (defined('PIPELINE_NOTIFY_EMAILS') ? PIPELINE_NOTIFY_EMAILS : '');
    return array_values(array_filter(array_map(fn($e) => strtolower(trim($e)), explode(',', (string)$raw))));
}

/**
 * $changes = ['new' => [ids], 'inactive' => [ids], 'back' => [ids]].
 * Returns the lines to print. With $dry, says what it would send.
 */
function mk_roster_alert(mysqli $conn, array $changes, bool $dry = false): array {
    $changes = array_map(fn($ids) => array_values(array_unique(array_map('intval', $ids))), $changes + ['new' => [], 'inactive' => [], 'back' => []]);
    if (!array_filter($changes)) return [];
    $to = mk_roster_alert_recipients();
    if (!$to) return ['Roster alert not sent: set ROSTER_ALERT_EMAILS in inc/config.php.'];

    $all = array_merge(...array_values($changes));
    $info = [];
    $r = $conn->query("SELECT id, agent_name, mls_full_name, web_status, in_fub, mh_email, mls_email
                         FROM marketing_intakes WHERE id IN (" . implode(',', $all) . ")");
    if ($r) while ($x = $r->fetch_assoc()) $info[(int)$x['id']] = $x;
    $boards = [];
    $r = $conn->query("SELECT intake_id, GROUP_CONCAT(DISTINCT market ORDER BY market SEPARATOR ', ') AS m
                         FROM agent_mls_ids WHERE is_alias = 0 AND intake_id IN (" . implode(',', $all) . ") GROUP BY intake_id");
    $lbl = ['aspen' => 'Aspen', 'vail' => 'Vail', 'cren' => 'CREN', 'recolorado' => 'REColorado', 'elevate' => 'Elevate', 'altitude' => 'Altitude', 'telluride' => 'Telluride'];
    if ($r) while ($x = $r->fetch_assoc()) {
        $boards[(int)$x['intake_id']] = implode(', ', array_map(fn($m) => $lbl[$m] ?? ucfirst($m), explode(', ', $x['m'])));
    }
    $subbed = [];
    $t = $conn->query("SHOW TABLES LIKE 'hs_subscribers'");
    if ($t && $t->fetch_row()) {
        $r = $conn->query("SELECT intake_id FROM hs_subscribers WHERE is_active = 1 AND unsubscribed_at IS NULL
                              AND intake_id IN (" . implode(',', $all) . ")");
        if ($r) while ($x = $r->fetch_assoc()) $subbed[(int)$x['intake_id']] = true;
    }

    $base = rtrim(SITE_URL, '/');
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $td = 'style="padding:6px 10px;border-bottom:1px solid #eee;"';
    $name = fn(int $id) => '<a href="' . $h($base . '/agent.php?id=' . $id) . '" style="color:#0184BB;">'
                         . $h(trim((string)($info[$id]['agent_name'] ?? '')) ?: ($info[$id]['mls_full_name'] ?? "#{$id}")) . '</a>';
    $section = function (string $title, string $note, array $ids, bool $still_on) use ($td, $name, $boards, $info, $subbed, $h): string {
        if (!$ids) return '';
        $rows = '';
        foreach ($ids as $id) {
            $on = [];
            if ($still_on) {
                if (($info[$id]['web_status'] ?? '') === 'approved') $on[] = 'website';
                if (!empty($subbed[$id]))                         $on[] = 'Hot Sheets';
                if (!empty($info[$id]['in_fub']))                 $on[] = 'FUB rotation';
            }
            $rows .= "<tr><td {$td}>" . $name($id) . "</td><td {$td}>" . $h($boards[$id] ?? '') . '</td>'
                   . ($still_on ? "<td {$td}>" . ($on ? $h(implode(', ', $on)) : 'nothing') . '</td>' : '') . '</tr>';
        }
        return '<h3 style="font-size:15px;margin:22px 0 4px;">' . $h($title) . '</h3>'
             . '<p style="margin:0 0 8px;color:#4b5563;">' . $h($note) . '</p>'
             . '<table style="border-collapse:collapse;font-size:13px;"><tr>'
             . '<th align="left" style="padding:6px 10px;border-bottom:2px solid #ddd;">Agent</th>'
             . '<th align="left" style="padding:6px 10px;border-bottom:2px solid #ddd;">Boards</th>'
             . ($still_on ? '<th align="left" style="padding:6px 10px;border-bottom:2px solid #ddd;">Still on</th>' : '')
             . '</tr>' . $rows . '</table>';
    };

    $ni = count($changes['inactive']); $nn = count($changes['new']); $nb = count($changes['back']);
    $parts = [];
    if ($ni) $parts[] = "{$ni} no longer with Mont Haus in the MLS";
    if ($nn) $parts[] = "{$nn} new agent" . ($nn === 1 ? '' : 's');
    if ($nb) $parts[] = "{$nb} back in the MLS";
    $subject = 'Agent roster: ' . implode(', ', $parts);

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;">'
          . $section('No longer with Mont Haus in the MLS',
                     'Moved to the Inactive tab. Nothing was turned off: take them off the website and their subscriptions with Offboard, or mark them still with Mont Haus.',
                     $changes['inactive'], true)
          . $section('New agents',
                     'Added as Active with the marketing checklist. The website profile waits for your approval, and the Hot Sheets start when you tick "Hot Sheets: Subscribe" on their checklist.',
                     $changes['new'], false)
          . $section('Back in the MLS under Mont Haus',
                     'They were Inactive and are Active again.', $changes['back'], false)
          . '<p style="margin-top:22px;"><a href="' . $h($base . '/index.php') . '"'
          . ' style="background:#0184BB;color:#fff;text-decoration:none;padding:10px 16px;border-radius:4px;display:inline-block;">Open the roster</a></p></div>';

    $out = [];
    foreach ($to as $addr) {
        if ($dry) { $out[] = "Roster alert (dry run, not sent) → {$addr}: {$subject}"; continue; }
        [$o, $d] = hs_send_email($addr, $subject, $html);
        $out[] = "Roster alert → {$addr}: {$o}" . ($o === 'sent' ? '' : " ({$d})");
    }
    return $out;
}
