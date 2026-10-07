<?php
/**
 * cron/send_hot_sheet.php — the Hot Sheet emails, one per AREA (2026-10-07).
 *
 * Replaces monthausint.com/hot-sheets/send_hot_sheet.php. While the hub keeps
 * serving everyone, HOT_SHEET_ALLOWED_RECIPIENTS (inc/config.php) limits this
 * to nikki.boxer@monthaus.com: every other subscriber is logged as 'blocked'
 * in hs_sends and nothing is sent to them. The gate is in inc/hs_mail.php, at
 * the last step before the network.
 *
 * Who gets what (Nikki, 2026-10-07):
 *   · each active, not-unsubscribed row in hs_subscribers
 *   · one email per area in their `areas` list (the website's Communities
 *     areas: Roaring Fork Valley, Vail Valley, Summit County, Gunnison
 *     Valley, Southwest Colorado, Front Range), each with that area's Latest
 *     Updates (new rentals included), MLS Listings, Pocket Listings, Buyer's
 *     Rep and Rentals
 *   · an area with nothing in it is NOT sent at all (subscribers.php and the
 *     portal say so)
 *   · daily = every run; twice_weekly = Mondays and Thursdays (Mountain
 *     time); weekly (legacy, from the hub import) = Mondays
 *   · an address already sent that area's email today (hs_sends) is skipped,
 *     so a cron that fires twice cannot double-send. --force overrides.
 *
 * Usage:
 *   php /var/www/marketing.monthaus.com/cron/send_hot_sheet.php --dry-run
 *   php /var/www/marketing.monthaus.com/cron/send_hot_sheet.php --to=nikki.boxer@monthaus.com [--area=vail-valley]
 *   php /var/www/marketing.monthaus.com/cron/send_hot_sheet.php
 *
 * --to=ADDR sends every area that has content (or the one --area) to ADDR
 * only, whatever hs_subscribers says, and still only if ADDR is on the
 * allowlist. That is the test send. --area=KEY with no --to limits the real
 * run to one area.
 *
 * Cron (unchanged since 2026-09-21): minute 0, hour 13 UTC, every day. The
 * script decides the weekday in America/Denver itself, so the UTC hour only
 * moves the delivery time, never which day counts as Monday or Thursday.
 *
 * Requires sql/hot_sheets_v4_areas.sql (hs_subscribers.areas, hs_sends.area_key).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/hs_data.php';
require_once __DIR__ . '/../inc/hs_template.php';
require_once __DIR__ . '/../inc/hs_mail.php';

$args  = array_slice($argv, 1);
$dry   = in_array('--dry-run', $args, true);
$force = in_array('--force', $args, true);
$to    = null; $only_area = null;
foreach ($args as $a) {
    if (preg_match('/^--to=(.+)$/', $a, $m))   $to = strtolower(trim($m[1]));
    if (preg_match('/^--area=(.+)$/', $a, $m)) $only_area = trim($m[1]);
}

$mt        = new DateTimeImmutable('now', new DateTimeZone('America/Denver'));
$send_date = $mt->format('Y-m-d');
$dow       = (int)$mt->format('N');   // 1 = Monday … 7 = Sunday
function out(string $s): void { echo '[' . gmdate('Y-m-d H:i:s') . "] {$s}" . PHP_EOL; }

out('Hot Sheet send, ' . $mt->format('l, F j') . ' (Mountain)' . ($dry ? ' — DRY RUN, nothing will be sent' : '')
    . ($to ? " — test send to {$to} only" : '') . ($only_area ? " — area {$only_area} only" : ''));
out('Allowed recipients: ' . (trim((string)HOT_SHEET_ALLOWED_RECIPIENTS) === '' ? 'ANYONE (no restriction)' : HOT_SHEET_ALLOWED_RECIPIENTS));

$chk = $conn->query("SHOW TABLES LIKE 'hs_sends'");
if (!$chk || !$chk->fetch_row()) { out('✗ sql/hot_sheets_v2.sql has not been run. Nothing done.'); exit(1); }
$chk = $conn->query("SHOW COLUMNS FROM hs_sends LIKE 'area_key'");
$chk2 = $conn->query("SHOW COLUMNS FROM hs_subscribers LIKE 'areas'");
if (!$chk || !$chk->fetch_row() || !$chk2 || !$chk2->fetch_row()) {
    out('✗ sql/hot_sheets_v4_areas.sql has not been run (hs_sends.area_key / hs_subscribers.areas missing). Nothing sent.'); exit(1);
}

// ── Content, once, then sliced per area ─────────────────────────────────────
$data  = build_hs_data($conn);
$areas = hs_all_areas($data);
$names = mk_area_names();
if ($only_area !== null && !isset($areas[$only_area])) {
    out("✗ Unknown area '{$only_area}'. Areas: " . implode(', ', array_keys($names)) . '.'); exit(1);
}
out('Updates since ' . $data['since_label'] . '.');
foreach ($areas as $k => $a) {
    out(sprintf('  %-20s %s', $names[$k] . ':', $a['has_content']
        ? count($a['updates']) . ' updates, ' . count($a['mls_listings']) . ' MLS, ' . count($a['pocket_listings']) . ' pocket, '
          . count($a['buyer_rep']) . ' buyer rep, ' . count($a['rentals']) . ' rentals'
        : 'nothing to show, no email'));
}
if ($orphans = hs_rows_without_area($data)) {
    out('⚠ ' . count($orphans) . ' entr' . (count($orphans) === 1 ? 'y' : 'ies') . ' in no area, so in no email (set the area on pipeline_review.php): '
        . implode('; ', array_map(fn($r) => $r['address'], $orphans)));
}

$date_label = $mt->format('F j, Y');
$subject_for = fn(string $k) => "Mont Haus Hot Sheet · {$names[$k]} · {$date_label}";

// ── Recipients ───────────────────────────────────────────────────────────────
if ($to) {
    $r = $conn->prepare("SELECT * FROM hs_subscribers WHERE email = ?");
    $r->bind_param('s', $to); $r->execute();
    $sub = $r->get_result()->fetch_assoc();
    $r->close();
    $recipients = [[
        'id' => $sub['id'] ?? null, 'email' => $to, 'frequency' => 'daily',
        'areas' => json_encode($only_area !== null ? [$only_area] : array_keys($names)),
        'unsubscribe_token' => $sub['unsubscribe_token'] ?? '',
    ]];
} else {
    $recipients = $conn->query("SELECT * FROM hs_subscribers
                                 WHERE is_active = 1 AND unsubscribed_at IS NULL
                                 ORDER BY email")->fetch_all(MYSQLI_ASSOC);
}

$already = $conn->prepare("SELECT 1 FROM hs_sends WHERE send_date = ? AND email = ? AND area_key = ? AND outcome = 'sent' LIMIT 1");
$log     = $conn->prepare("INSERT INTO hs_sends (subscriber_id, email, kind, area_key, send_date, outcome, detail) VALUES (?,?,'area',?,?,?,?)");

$tally = ['sent' => 0, 'failed' => 0, 'blocked' => 0, 'skipped' => 0, 'empty' => 0];
foreach ($recipients as $sub) {
    $email = strtolower(trim($sub['email']));
    $freq  = (string)$sub['frequency'];
    if (!$to) {
        $due = $freq === 'daily' || ($freq === 'twice_weekly' && in_array($dow, [1, 4], true)) || ($freq === 'weekly' && $dow === 1);
        if (!$due) { $tally['skipped']++; echo "    · {$email}: {$freq}, not today\n"; continue; }
    }
    $wanted = mk_areas_decode($sub['areas'] ?? null);
    if ($only_area !== null) $wanted = array_values(array_intersect($wanted, [$only_area]));
    if (!$wanted) { $tally['skipped']++; echo "    · {$email}: no areas chosen\n"; continue; }

    foreach ($wanted as $k) {
        $a = $areas[$k];
        if (!$a['has_content']) { $tally['empty']++; echo "    · {$email} {$k}: nothing to show, no email\n"; continue; }
        if (!$force && !$dry) {
            $already->bind_param('sss', $send_date, $email, $k); $already->execute();
            if ($already->get_result()->fetch_row()) { $tally['skipped']++; echo "    · {$email} {$k}: already sent today\n"; continue; }
        }
        if ($dry) {
            $ok = hs_recipient_allowed($email);
            $tally[$ok ? 'sent' : 'blocked']++;
            echo "    ~ {$email} {$k}: " . ($ok ? 'would send' : 'would be BLOCKED (not on the allowlist)') . "\n";
            continue;
        }
        $unsub = $sub['unsubscribe_token'] ? hs_unsubscribe_url($sub['unsubscribe_token']) : '';
        $html  = render_hot_sheet_email($a, $unsub);
        [$outcome, $detail] = hs_send_email($email, $subject_for($k), $html, $unsub);
        $logged = ($outcome === 'sent' && $to) ? 'test' : $outcome;
        $sid = $sub['id'] !== null ? (int)$sub['id'] : null;
        $log->bind_param('isssss', $sid, $email, $k, $send_date, $logged, $detail); $log->execute();
        $tally[$outcome]++;
        echo "    " . ['sent' => '✓', 'failed' => '✗', 'blocked' => '⊘'][$outcome] . " {$email} {$k}: {$outcome}"
           . ($outcome === 'sent' ? '' : " ({$detail})") . "\n";
    }
}

out(($dry ? '✓ DRY RUN: ' : '✓ Done: ') . "{$tally['sent']} " . ($dry ? 'would send' : 'sent') . ", {$tally['failed']} failed, "
    . "{$tally['blocked']} blocked by the allowlist, {$tally['skipped']} skipped, {$tally['empty']} area emails with nothing to show.");
$conn->close();
exit($tally['failed'] > 0 ? 1 : 0);
