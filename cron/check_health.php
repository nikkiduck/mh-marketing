<?php
/**
 * cron/check_health.php — tells Nikki when a marketing cron fails or stalls
 * (2026-10-01). Every cron here writes only to its log; nobody sees a failure
 * unless they go looking. This reads the logs and the database every 30
 * minutes and emails ALERT_EMAIL when something is wrong:
 *
 *   roster     Anyprop roster sync: no completed run in 2.5 h, or a ✗
 *   listings   listing sync (Hot Sheets + agent pages): no run in 2.5 h, or a ✗
 *   pipeline   parser: no run in 45 min, errors, or a webhook event still
 *              unparsed after 30 min
 *   disk       root filesystem over 85 %
 *
 * Emailed once, then at most every 12 hours while it lasts, plus a "back to
 * normal" note when it clears. State: /var/log/mh-marketing/check_health.state.json
 * (outside the docroot). Sent with hs_send_email(), so the address must be on
 * HOT_SHEET_ALLOWED_RECIPIENTS while that gate is set (it is).
 *
 *   php /var/www/marketing.monthaus.com/cron/check_health.php            # check, email if needed
 *   php /var/www/marketing.monthaus.com/cron/check_health.php --dry-run  # print, send nothing
 *
 * Cron: every 30 minutes (see CLAUDE.md > Cron; never write a cron step inside
 * a block comment).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/hs_mail.php';

const ALERT_EMAIL = 'nikki.boxer@monthaus.com';
const LOG_DIR     = '/var/log/mh-marketing';
const STATE_FILE  = '/var/log/mh-marketing/check_health.state.json';
const REPEAT_SECS = 12 * 3600;

$dry = in_array('--dry-run', $argv, true);
$now = time();

/**
 * The newest completed run's time (from its $done line) and the text since the
 * completed run before it: that is the last run plus anything started since.
 * Log timestamps here are UTC.
 */
function log_state(string $file, string $done_re): array {
    if (!is_readable($file)) return ['done_at' => null, 'text' => ''];
    $fh = fopen($file, 'r');
    fseek($fh, max(0, filesize($file) - 200000));
    $lines = preg_split('/\R/', (string)stream_get_contents($fh));
    fclose($fh);
    $done_idx = [];
    foreach ($lines as $i => $l) if (preg_match($done_re, $l)) $done_idx[] = $i;
    $done_at = null;
    if ($done_idx && preg_match('/^\[([0-9-]+ [0-9:]+)\]/', $lines[end($done_idx)], $m)) $done_at = strtotime($m[1] . ' UTC');
    $from = count($done_idx) >= 2 ? $done_idx[count($done_idx) - 2] + 1 : 0;
    return ['done_at' => $done_at, 'text' => implode("\n", array_slice($lines, $from))];
}
function bad_lines(string $text): array {
    $out = [];
    foreach (preg_split('/\R/', $text) as $l) {
        if (preg_match('/✗|Fatal|Uncaught|HTTP [45]\d\d|quota/iu', $l)) $out[] = trim($l);
    }
    return array_slice(array_values(array_unique($out)), 0, 8);
}
$ago = fn(?int $t) => $t ? round(($GLOBALS['now'] - $t) / 60) . ' min ago (' . gmdate('M j H:i', $t) . ' UTC)' : 'never';

$issues = [];

$s = log_state(LOG_DIR . '/anyprop_roster.log', '/✓ (DRY RUN )?[Dd]one/u');
if (!$s['done_at'] || $now - $s['done_at'] > 150 * 60) {
    $issues['roster'] = 'The Anyprop roster sync has not completed since ' . $ago($s['done_at']) . '. New brokers and contact changes are not coming in.';
}
if ($b = bad_lines($s['text'])) {
    $issues['roster'] = trim(($issues['roster'] ?? '') . "\nThe last roster sync reported:\n  " . implode("\n  ", $b));
}

$s = log_state(LOG_DIR . '/hot_sheet_sync.log', '/✓ Done:/u');
if (!$s['done_at'] || $now - $s['done_at'] > 150 * 60) {
    $issues['listings'] = 'The listing sync (Hot Sheets and agent pages) has not completed since ' . $ago($s['done_at']) . '.';
}
if ($b = bad_lines($s['text'])) {
    $issues['listings'] = trim(($issues['listings'] ?? '') . "\nThe last listing sync reported:\n  " . implode("\n  ", $b));
}

$s = log_state(LOG_DIR . '/pipeline_parse.log', '/\] Done: \d+ parsed, \d+ errors/u');
if (!$s['done_at'] || $now - $s['done_at'] > 45 * 60) {
    $issues['pipeline'] = 'The Paperless Pipeline parser has not run since ' . $ago($s['done_at']) . '.';
} else {
    $b = bad_lines($s['text']);
    if (preg_match_all('/Done: \d+ parsed, ([1-9]\d*) errors/', $s['text'], $m)) $b[] = 'parser errors: ' . implode(', ', $m[1]);
    if ($b) $issues['pipeline'] = "The Paperless Pipeline parser reported:\n  " . implode("\n  ", $b);
}
$r = $conn->query("SELECT COUNT(*), MIN(received_at) FROM hs_pipeline_events
                    WHERE parse_status = 'unparsed' AND received_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE");
if ($r && ($row = $r->fetch_row()) && (int)$row[0] > 0) {
    $issues['pipeline'] = trim(($issues['pipeline'] ?? '') . "\n{$row[0]} Paperless event(s) still unparsed, oldest received {$row[1]} UTC.");
}

$pct = 100 - (int)round(disk_free_space('/') / disk_total_space('/') * 100);
if ($pct > 85) $issues['disk'] = "The marketing server's disk is {$pct}% full.";

// The roster sync talks to Anyprop: a quota error there is Nikki's call to make.
foreach ($issues as $k => $m) {
    if (stripos($m, 'quota') !== false) $issues[$k] = $m . "\n\nThis is an Anyprop QUOTA error. If it is on a live board (Aspen, elevateMLS, Telluride), call Anyprop: those are meant to be unlimited.";
}

// ── Email ────────────────────────────────────────────────────────────────────
$state = is_file(STATE_FILE) ? (json_decode((string)file_get_contents(STATE_FILE), true) ?: []) : [];
$send = []; $cleared = [];
foreach ($issues as $k => $m) {
    if (!isset($state[$k]) || $now - (int)$state[$k] >= REPEAT_SECS) { $send[$k] = $m; $state[$k] = $now; }
}
foreach (array_keys($state) as $k) if (!isset($issues[$k])) { $cleared[] = $k; unset($state[$k]); }

echo '[' . gmdate('Y-m-d H:i:s') . '] check_health: ' . ($issues ? count($issues) . ' problem(s): ' . implode(', ', array_keys($issues)) : 'all OK')
   . ($send ? '; emailing ' . implode(', ', array_keys($send)) : '') . ($cleared ? '; cleared ' . implode(', ', $cleared) : '') . PHP_EOL;

$h = fn($s) => nl2br(htmlspecialchars($s, ENT_QUOTES, 'UTF-8'), false);
$mails = [];
if ($send) {
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;"><p>marketing.monthaus.com needs attention.</p>';
    foreach ($send as $k => $m) $html .= '<p><strong>' . strtoupper($k) . '</strong><br>' . $h($m) . '</p>';
    $html .= '<p style="color:#6b7280;font-size:12px;">Logs: /var/log/mh-marketing/ on the marketing server. Repeats every 12 hours until fixed.</p></div>';
    $mails[] = ['marketing.monthaus.com: ' . implode(', ', array_keys($send)) . ' problem', $html];
}
if ($cleared) {
    $mails[] = ['marketing.monthaus.com: back to normal (' . implode(', ', $cleared) . ')',
                '<p style="font-family:Arial,Helvetica,sans-serif;">These are working again: ' . htmlspecialchars(implode(', ', $cleared)) . '.</p>'];
}
foreach ($mails as [$subj, $html]) {
    if ($dry) { echo "--- would email " . ALERT_EMAIL . ": {$subj}\n" . strip_tags(str_replace('<br>', "\n", $html)) . "\n"; continue; }
    [$o, $d] = hs_send_email(ALERT_EMAIL, $subj, $html);
    echo "    email \"{$subj}\": {$o}" . ($o === 'sent' ? '' : " ({$d})") . PHP_EOL;
}
if (!$dry) file_put_contents(STATE_FILE, json_encode($state));
