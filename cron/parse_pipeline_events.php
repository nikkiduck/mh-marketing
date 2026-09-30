<?php
/**
 * cron/parse_pipeline_events.php — hs_pipeline_events → hs_pipeline_transactions.
 *
 * Ported from monthausint.com/hot-sheets/parse_pipeline_events.php. Turns
 * stored webhook bodies into one staging row per Paperless transaction.
 * Writes nothing a hot sheet reads: promotion is by hand in pipeline_review.php.
 *
 * A deal that was promoted and later changes status (pending → closed) goes
 * back to the queue, because closing is when the sale price becomes publishable.
 *
 * Changed from the hub:
 *   · agents resolve against the roster (marketing_intakes), not mh_brokers;
 *   · --reparse no longer sends promoted deals back to review. Replaying old
 *     events in order used to flip every promoted row to pending, because an
 *     early event's status differs from the current one. Only NEW events can
 *     reopen a deal now.
 *
 * Usage:
 *   php /var/www/marketing.monthaus.com/cron/parse_pipeline_events.php
 *   php /var/www/marketing.monthaus.com/cron/parse_pipeline_events.php --reparse
 * Cron, once Zapier points here (written in words, never inside a block
 * comment): minute 49 of every hour.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/pipeline.php';
require_once __DIR__ . '/../inc/hs_mail.php';

$reparse = in_array('--reparse', $argv, true);
$now     = date('Y-m-d H:i:s');
function out(string $s): void { global $now; echo "[{$now}] {$s}\n"; }

$chk = $conn->query("SHOW TABLES LIKE 'hs_pipeline_transactions'");
if (!$chk || !$chk->fetch_row()) { out('✗ sql/hot_sheets_v3_pipeline.sql has not been run. Nothing done.'); exit(1); }

$excluded    = pl_email_list('PIPELINE_EXCLUDED_AGENT_EMAILS');
$conditional = pl_email_list('PIPELINE_CONDITIONAL_AGENT_EMAILS');
$all_excluded = array_values(array_unique(array_merge($excluded, $conditional)));
[$by_email, $by_name] = pl_roster($conn);
$idx = pl_listing_index($conn);

$rows = $conn->query("SELECT id, payload FROM hs_pipeline_events WHERE "
                   . ($reparse ? "parse_status <> 'ignored'" : "parse_status = 'unparsed'")
                   . " ORDER BY id")->fetch_all(MYSQLI_ASSOC);
out('Pipeline parse' . ($reparse ? ' (reparse all)' : '') . ': ' . count($rows) . ' event(s), '
    . count($by_email) . ' roster emails, ' . count($idx['exact']) . ' MLS addresses.');

$mark = $conn->prepare("UPDATE hs_pipeline_events SET parse_status = ?, parse_notes = ?, parsed_at = NOW() WHERE id = ?");
$up   = $conn->prepare("
    INSERT INTO hs_pipeline_transactions
        (event_id, pipeline_txn_id, address, postal_code, property_type, mls_number, side,
         status_category, status_label, status_changed_at, acceptance_date, closing_date, sale_price,
         agent_names, unmatched_agents, suggested_entry_type,
         mls_match_market, mls_match_key, mls_match_number, match_confidence)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        -- MUST stay first: compares the STORED category with the incoming one
        -- before the line below overwrites it. The first placeholder is 1 only
        -- on a normal run, never on --reparse.
        review_status = IF(? = 1 AND review_status = 'promoted'
                           AND NOT (status_category <=> VALUES(status_category)), 'pending', review_status),
        event_id = VALUES(event_id), address = VALUES(address), postal_code = VALUES(postal_code),
        property_type = VALUES(property_type), mls_number = VALUES(mls_number), side = VALUES(side),
        status_category = VALUES(status_category), status_label = VALUES(status_label),
        status_changed_at = VALUES(status_changed_at), acceptance_date = VALUES(acceptance_date),
        closing_date = VALUES(closing_date), sale_price = VALUES(sale_price),
        agent_names = VALUES(agent_names), unmatched_agents = VALUES(unmatched_agents),
        suggested_entry_type = VALUES(suggested_entry_type),
        mls_match_market = VALUES(mls_match_market), mls_match_key = VALUES(mls_match_key),
        mls_match_number = VALUES(mls_match_number), match_confidence = VALUES(match_confidence)");
if (!$mark || !$up) { out('✗ prepare failed: ' . $conn->error); exit(1); }

$reopen = $reparse ? 0 : 1;
$parsed = 0; $errors = 0;
foreach ($rows as $ev) {
    $eid = (int)$ev['id'];
    $p = json_decode($ev['payload'], true);
    if (!is_array($p)) { $st = 'error'; $n = 'Payload is not valid JSON'; $mark->bind_param('ssi', $st, $n, $eid); $mark->execute(); $errors++; continue; }

    $txn = trim((string)pl_get($p, ['Transaction ID', 'transaction_id', 'id'], ''));
    if ($txn === '') { $st = 'error'; $n = 'No Transaction ID in payload'; $mark->bind_param('ssi', $st, $n, $eid); $mark->execute(); $errors++; continue; }

    $side_raw = trim((string)pl_get($p, ['Transaction Side (Listing, Buying, Listing & Buying)', 'Transaction Side', 'transaction_side'], ''));
    $side = 'Unknown';
    if (stripos($side_raw, 'Listing & Buying') !== false) $side = 'Both';
    elseif (stripos($side_raw, 'Buying') !== false)       $side = 'Buying';
    elseif (stripos($side_raw, 'Listing') !== false)      $side = 'Listing';

    $address = trim((string)pl_get($p, ['Transaction Name', 'transaction_name', 'name'], ''));
    $postal  = trim((string)pl_get($p, ['Postal Code', 'postal_code'], '')) ?: null;
    $ptype   = trim((string)pl_get($p, ['Transaction Label', 'transaction_label'], '')) ?: null;
    $mls     = trim((string)pl_get($p, ['MLS', 'mls', 'mls_number'], '')) ?: null;
    $cat     = trim((string)pl_get($p, ['Transaction Status Category', 'transaction_status_category'], '')) ?: null;
    $label   = trim((string)pl_get($p, ['Status', 'status'], '')) ?: null;
    $changed = pl_date((string)pl_get($p, ['Transaction Status Change Date', 'transaction_status_change_date'], ''), 'Y-m-d H:i:s');
    $accept  = pl_date((string)pl_get($p, ['Acceptance Date', 'acceptance_date'], ''));
    $closing = pl_date((string)pl_get($p, ['Closing Date', 'closing_date'], ''));
    $praw    = pl_get($p, ['Sale Price', 'sale_price', 'List Price', 'list_price'], null);
    $price   = ($praw === null || $praw === '') ? null : (string)(float)preg_replace('/[^0-9.]/', '', (string)$praw);

    $res = pl_resolve_agents(pl_get($p, ['Agents (JSON)', 'agents_json', 'Agents', 'agents'], null), $by_email, $by_name, $all_excluded);
    $agents    = $res['matched']   ? implode(', ', $res['matched'])   : null;
    $unmatched = $res['unmatched'] ? implode(', ', $res['unmatched']) : null;

    // Buying → buyer rep. Listing, or Listing & Buying → pocket listing.
    $etype = $side === 'Buying' ? 'buyer_rep' : (in_array($side, ['Listing', 'Both'], true) ? 'pocket_listing' : null);

    [$hit, $conf] = pl_match_listing($idx, $address);
    $mm = $hit['market'] ?? null; $mk = $hit['listing_key'] ?? null; $mn = $hit['mls_id'] ?? null;
    if ($mls) { $mn = $mls; $conf = 'exact'; }   // a Paperless MLS number beats an address guess

    $up->bind_param('isssssssssssssssssssi',
        $eid, $txn, $address, $postal, $ptype, $mls, $side, $cat, $label, $changed, $accept, $closing, $price,
        $agents, $unmatched, $etype, $mm, $mk, $mn, $conf, $reopen);
    if (!$up->execute()) {
        $st = 'error'; $n = 'Upsert failed: ' . $up->error; $mark->bind_param('ssi', $st, $n, $eid); $mark->execute(); $errors++; continue;
    }
    $hint = $conf === 'none' ? 'no MLS match' : 'matches MLS ' . ($mn ?: '?') . " ({$conf})";
    $n  = "txn {$txn}: {$address} [{$cat}] {$side} → " . ($etype ?? '?') . " | {$hint}" . ($unmatched ? " | unmatched agents: {$unmatched}" : '');
    $st = 'parsed';
    $mark->bind_param('ssi', $st, $n, $eid); $mark->execute();
    $parsed++;
    echo "    + {$n}\n";
}
out("Done: {$parsed} parsed, {$errors} errors.");

// ── Tell someone when there is something to review ──────────────────────────
// Only when this run produced new staged rows, so an hourly cron does not
// re-announce the same backlog. Not on --reparse.
$notify = pl_email_list('PIPELINE_NOTIFY_EMAILS');
if ($parsed > 0 && !$reparse && $notify) {
    $waiting = $conn->query("SELECT address, side, status_label, agent_names, suggested_entry_type
                               FROM hs_pipeline_transactions WHERE review_status = 'pending'
                              ORDER BY status_changed_at DESC")->fetch_all(MYSQLI_ASSOC);
    if ($waiting) {
        $k = count($waiting);
        $tr = '';
        foreach ($waiting as $w) {
            $tr .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($w['address'] ?: '(no address)') . '</td>'
                 . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . ($w['suggested_entry_type'] === 'buyer_rep' ? 'Buyer Rep' : 'Off-Market') . '</td>'
                 . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($w['status_label'] ?: '') . '</td>'
                 . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($w['agent_names'] ?: '') . '</td></tr>';
        }
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;">'
              . "<p><strong>{$k}</strong> transaction" . ($k === 1 ? ' is' : 's are') . ' waiting in the Hot Sheet review queue.</p>'
              . '<table style="border-collapse:collapse;font-size:13px;"><tr>'
              . '<th align="left" style="padding:6px 10px;border-bottom:2px solid #ddd;">Address</th>'
              . '<th align="left" style="padding:6px 10px;border-bottom:2px solid #ddd;">Type</th>'
              . '<th align="left" style="padding:6px 10px;border-bottom:2px solid #ddd;">Status</th>'
              . '<th align="left" style="padding:6px 10px;border-bottom:2px solid #ddd;">Agents</th></tr>' . $tr . '</table>'
              . '<p style="margin-top:18px;"><a href="' . htmlspecialchars(rtrim(SITE_URL, '/') . '/pipeline_review.php') . '"'
              . ' style="background:#0184BB;color:#fff;text-decoration:none;padding:10px 16px;border-radius:4px;display:inline-block;">Open the review queue</a></p>'
              . '<p style="color:#6b7280;font-size:12px;">Nothing reaches a hot sheet until it is promoted.</p></div>';
        foreach ($notify as $addr) {
            [$o, $d] = hs_send_email($addr, "{$k} Paperless Pipeline transaction" . ($k === 1 ? '' : 's') . ' to review', $html);
            out("Review alert → {$addr}: {$o}" . ($o === 'sent' ? '' : " ({$d})"));
        }
    }
}
$conn->close();
exit($errors ? 1 : 0);
