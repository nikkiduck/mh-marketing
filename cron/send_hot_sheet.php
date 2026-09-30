<?php
/**
 * cron/send_hot_sheet.php — the Hot Sheet emails (Listings, Rentals).
 *
 * Replaces monthausint.com/hot-sheets/send_hot_sheet.php. While the hub keeps
 * serving everyone, HOT_SHEET_ALLOWED_RECIPIENTS (inc/config.php) limits this
 * to nikki.boxer@monthaus.com: every other subscriber is logged as 'blocked'
 * in hs_sends and nothing is sent to them. The gate is in inc/hs_mail.php, at
 * the last step before the network.
 *
 * Who gets what (as on the hub):
 *   · each active, not-unsubscribed row in hs_subscribers
 *   · Listings and/or Rentals per receives_listings / receives_rentals
 *   · daily = every run; weekly = Mondays (Mountain time)
 *   · an address already sent that email today (hs_sends) is skipped, so a cron
 *     that fires twice cannot double-send. --force overrides.
 *
 * Usage:
 *   php /var/www/marketing.monthaus.com/cron/send_hot_sheet.php --dry-run
 *   php /var/www/marketing.monthaus.com/cron/send_hot_sheet.php --to=nikki.boxer@monthaus.com
 *   php /var/www/marketing.monthaus.com/cron/send_hot_sheet.php
 *
 * --to=ADDR sends both emails to ADDR only, whatever hs_subscribers says, and
 * still only if ADDR is on the allowlist. That is the test send.
 *
 * Cron, when ready (7am Mountain; written in words, never inside a block
 * comment): minute 0, hour 13 UTC in summer / 14 in winter, every day.
 * The script decides Monday in America/Denver itself, so the UTC hour only
 * moves the delivery time, never which day counts as Monday.
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
$to    = null;
foreach ($args as $a) if (preg_match('/^--to=(.+)$/', $a, $m)) $to = strtolower(trim($m[1]));

$mt        = new DateTimeImmutable('now', new DateTimeZone('America/Denver'));
$send_date = $mt->format('Y-m-d');
$is_monday = (int)$mt->format('N') === 1;
function out(string $s): void { echo '[' . gmdate('Y-m-d H:i:s') . "] {$s}" . PHP_EOL; }

out('Hot Sheet send, ' . $mt->format('l, F j') . ' (Mountain)' . ($dry ? ' — DRY RUN, nothing will be sent' : '')
    . ($to ? " — test send to {$to} only" : ''));
out('Allowed recipients: ' . (trim((string)HOT_SHEET_ALLOWED_RECIPIENTS) === '' ? 'ANYONE (no restriction)' : HOT_SHEET_ALLOWED_RECIPIENTS));

$chk = $conn->query("SHOW TABLES LIKE 'hs_sends'");
if (!$chk || !$chk->fetch_row()) { out('✗ sql/hot_sheets_v2.sql has not been run. Nothing done.'); exit(1); }

$data = build_hs_data($conn);
out('Content: ' . count($data['sale_updates']) . ' sale updates, ' . count($data['mls_listings']) . ' MLS listings, '
    . count($data['pocket_listings']) . ' pocket, ' . count($data['buyer_rep']) . ' buyer rep, '
    . count($data['rental_updates']) . ' new rentals, ' . count($data['rental_listings']) . ' rentals. Updates since ' . $data['since_label'] . '.');

$date_label = $mt->format('F j, Y');
$subjects = ['listings' => "Mont Haus Hot Sheet · Listings · {$date_label}",
             'rentals'  => "Mont Haus Hot Sheet · Rentals · {$date_label}"];

// ── Recipients ───────────────────────────────────────────────────────────────
if ($to) {
    $r = $conn->prepare("SELECT * FROM hs_subscribers WHERE email = ?");
    $r->bind_param('s', $to); $r->execute();
    $sub = $r->get_result()->fetch_assoc();
    $r->close();
    $recipients = [[
        'id' => $sub['id'] ?? null, 'email' => $to, 'receives_listings' => 1, 'receives_rentals' => 1,
        'frequency' => 'daily', 'unsubscribe_token' => $sub['unsubscribe_token'] ?? '',
    ]];
} else {
    $recipients = $conn->query("SELECT * FROM hs_subscribers
                                 WHERE is_active = 1 AND unsubscribed_at IS NULL
                                   AND (receives_listings = 1 OR receives_rentals = 1)
                                 ORDER BY email")->fetch_all(MYSQLI_ASSOC);
}

$already = $conn->prepare("SELECT 1 FROM hs_sends WHERE send_date = ? AND email = ? AND kind = ? AND outcome = 'sent' LIMIT 1");
$log     = $conn->prepare("INSERT INTO hs_sends (subscriber_id, email, kind, send_date, outcome, detail) VALUES (?,?,?,?,?,?)");

$tally = ['sent' => 0, 'failed' => 0, 'blocked' => 0, 'skipped' => 0];
foreach ($recipients as $sub) {
    $email = strtolower(trim($sub['email']));
    if (!$to && $sub['frequency'] === 'weekly' && !$is_monday) { $tally['skipped']++; echo "    · {$email}: weekly, not Monday\n"; continue; }

    foreach (['listings' => 'receives_listings', 'rentals' => 'receives_rentals'] as $kind => $flag) {
        if (!(int)$sub[$flag]) continue;
        if (!$force && !$dry) {
            $already->bind_param('sss', $send_date, $email, $kind); $already->execute();
            if ($already->get_result()->fetch_row()) { $tally['skipped']++; echo "    · {$email} {$kind}: already sent today\n"; continue; }
        }
        if ($dry) {
            $ok = hs_recipient_allowed($email);
            $tally[$ok ? 'sent' : 'blocked']++;
            echo "    ~ {$email} {$kind}: " . ($ok ? 'would send' : 'would be BLOCKED (not on the allowlist)') . "\n";
            continue;
        }
        $unsub = $sub['unsubscribe_token'] ? hs_unsubscribe_url($sub['unsubscribe_token']) : '';
        $html  = render_hot_sheet_email($data, $kind, $unsub);
        [$outcome, $detail] = hs_send_email($email, $subjects[$kind], $html, $unsub);
        $logged = ($outcome === 'sent' && $to) ? 'test' : $outcome;
        $sid = $sub['id'] !== null ? (int)$sub['id'] : null;
        $log->bind_param('isssss', $sid, $email, $kind, $send_date, $logged, $detail); $log->execute();
        $tally[$outcome]++;
        echo "    " . ['sent' => '✓', 'failed' => '✗', 'blocked' => '⊘'][$outcome] . " {$email} {$kind}: {$outcome}"
           . ($outcome === 'sent' ? '' : " ({$detail})") . "\n";
    }
}

out(($dry ? '✓ DRY RUN: ' : '✓ Done: ') . "{$tally['sent']} " . ($dry ? 'would send' : 'sent') . ", {$tally['failed']} failed, "
    . "{$tally['blocked']} blocked by the allowlist, {$tally['skipped']} skipped.");
$conn->close();
exit($tally['failed'] > 0 ? 1 : 0);
