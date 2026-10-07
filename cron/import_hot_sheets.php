<?php
/**
 * cron/import_hot_sheets.php — Hot Sheet data from the hub (monthausint.com).
 *
 * Input: JSON from hot-sheets/export_for_marketing.php on the hub.
 *   subscribers            → hs_subscribers            (upsert on email)
 *   manual_listings        → hs_manual_listings        (upsert on hub_id)
 *   manual_listing_changes → hs_manual_listing_changes (upsert on hub_id)
 *   pipeline_events        → hs_pipeline_events        (upsert on hub_id)     } when the export
 *   pipeline_transactions  → hs_pipeline_transactions  (upsert on txn id)     } carries them
 *
 * Pipeline: until Zapier points at this site, the hub's review queue is the
 * one that is worked, and each import mirrors it here, review decisions
 * included (the hub is authoritative until go-live). A transaction's MLS
 * match is copied as its MLS number only: the hub's listings.id means
 * nothing here, and the parser rebuilds the match on its next run.
 *
 * Re-runnable on purpose: until Paperless Pipeline moves here, pocket listings
 * and buyer reps are still created on the hub, so export + import again to
 * refresh. Every write is an upsert keyed on the hub's own ids.
 *
 * Subscribers keep their hub is_active, but that does not make anyone receive
 * anything: HOT_SHEET_ALLOWED_RECIPIENTS decides that while the hub still
 * serves everyone. An existing marketing row keeps its unsubscribe token and
 * its unsubscribed state (an unsubscribe here is never undone by an import).
 *
 *   php /var/www/marketing.monthaus.com/cron/import_hot_sheets.php /home/admin/hot_sheets_export.json --dry-run
 *   php /var/www/marketing.monthaus.com/cron/import_hot_sheets.php /home/admin/hot_sheets_export.json
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../inc/db.php';

$file = $argv[1] ?? '';
$dry  = in_array('--dry-run', $argv, true);
if ($file === '' || !is_file($file)) { fwrite(STDERR, "usage: php cron/import_hot_sheets.php <export.json> [--dry-run]\n"); exit(1); }
$d = json_decode((string)file_get_contents($file), true);
if (!is_array($d) || !isset($d['subscribers'], $d['manual_listings'], $d['manual_listing_changes'])) {
    fwrite(STDERR, "{$file} is not an export from hot-sheets/export_for_marketing.php\n"); exit(1);
}
$chk = $conn->query("SHOW TABLES LIKE 'hs_manual_listing_changes'");
if (!$chk || !$chk->fetch_row()) { fwrite(STDERR, "sql/hot_sheets_v2.sql has not been run. Nothing done.\n"); exit(1); }

echo 'Import from ' . ($d['source'] ?? '?') . ', exported ' . ($d['exported_at'] ?? '?') . ($dry ? ' — DRY RUN, nothing will be written' : '') . "\n";

$conn->begin_transaction();
try {
    // ── Subscribers ─────────────────────────────────────────────────────────
    $find = $conn->prepare("SELECT id, unsubscribed_at FROM hs_subscribers WHERE email = ?");
    $ins  = $conn->prepare("INSERT INTO hs_subscribers (email, receives_listings, receives_rentals, frequency, is_active,
                                                        unsubscribe_token, hub_subscriber_id)
                            VALUES (?,?,?,?,?,?,?)");
    $upd  = $conn->prepare("UPDATE hs_subscribers SET receives_listings = ?, receives_rentals = ?, frequency = ?,
                                   is_active = IF(unsubscribed_at IS NULL, ?, 0), hub_subscriber_id = ?
                             WHERE id = ?");
    $n = ['sub_new' => 0, 'sub_upd' => 0];
    $fc = $conn->query("SHOW COLUMNS FROM hs_subscribers LIKE 'frequency'")->fetch_assoc();
    $twice_weekly_ok = $fc && str_contains((string)$fc['Type'], 'twice_weekly');
    foreach ($d['subscribers'] as $s) {
        $email = strtolower(trim((string)$s['email']));
        if ($email === '') continue;
        $rl = (int)$s['receives_listings']; $rr = (int)$s['receives_rentals'];
        // The hub's weekly is this system's twice a week (Monday + Thursday) once sql/hot_sheets_v4_areas.sql has run.
        $fq = ($s['frequency'] ?? 'daily') === 'weekly' ? ($twice_weekly_ok ? 'twice_weekly' : 'weekly') : 'daily';
        $ac = (int)$s['is_active']; $hid = (int)$s['id'];
        $find->bind_param('s', $email); $find->execute();
        $cur = $find->get_result()->fetch_assoc();
        echo ($cur ? '  = ' : '  + ') . "subscriber {$email} ({$fq}" . ($ac ? '' : ', inactive') . ($cur && $cur['unsubscribed_at'] ? ', unsubscribed here: kept that way' : '') . ")\n";
        if ($cur) {
            $n['sub_upd']++;
            if (!$dry) { $cid = (int)$cur['id']; $upd->bind_param('iisiii', $rl, $rr, $fq, $ac, $hid, $cid); $upd->execute(); }
        } else {
            $n['sub_new']++;
            if (!$dry) {
                $tok = bin2hex(random_bytes(16));   // 32 hex: the only key unsubscribe.php accepts
                $ins->bind_param('siisisi', $email, $rl, $rr, $fq, $ac, $tok, $hid);
                $ins->execute();
            }
        }
    }

    // ── Manual listings ─────────────────────────────────────────────────────
    $ml = $conn->prepare("INSERT INTO hs_manual_listings
            (hub_id, entry_type, mls_number, market, address, city, state_abbr, postal_code, price, close_price,
             status, primary_photo_url, listing_url, agent_names, notes, is_active, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE entry_type=VALUES(entry_type), mls_number=VALUES(mls_number), market=VALUES(market),
            address=VALUES(address), city=VALUES(city), state_abbr=VALUES(state_abbr), postal_code=VALUES(postal_code),
            price=VALUES(price), close_price=VALUES(close_price), status=VALUES(status),
            primary_photo_url=VALUES(primary_photo_url), listing_url=VALUES(listing_url), agent_names=VALUES(agent_names),
            notes=VALUES(notes), is_active=VALUES(is_active)");
    foreach ($d['manual_listings'] as $m) {
        $hid = (int)$m['id']; $et = (string)$m['entry_type']; $mn = $m['mls_number'] ?: null;
        $mk  = $m['market'] ? strtolower((string)$m['market']) : null;
        $ad = (string)$m['address']; $ci = $m['city'] ?: null; $st = $m['state_abbr'] ?: 'CO'; $pc = $m['postal_code'] ?: null;
        $pr = $m['price']; $cp = $m['close_price']; $ss = (string)$m['status'];
        $ph = $m['primary_photo_url'] ?: null; $lu = $m['listing_url'] ?: null; $an = $m['agent_names'] ?: null;
        $no = $m['notes'] ?: null; $ac = (int)$m['is_active']; $ca = (string)($m['created_at'] ?: date('Y-m-d H:i:s'));
        echo "  ~ manual #{$hid} {$et} {$ss}: {$ad}" . ($ac ? '' : ' (inactive)') . "\n";
        if ($dry) continue;
        $ml->bind_param('issssssssssssssis', $hid, $et, $mn, $mk, $ad, $ci, $st, $pc, $pr, $cp, $ss, $ph, $lu, $an, $no, $ac, $ca);
        $ml->execute();
    }

    // ── Manual listing changes ──────────────────────────────────────────────
    $map = [];
    if (!$dry) foreach ($conn->query("SELECT id, hub_id FROM hs_manual_listings WHERE hub_id IS NOT NULL")->fetch_all(MYSQLI_ASSOC) as $r) {
        $map[(int)$r['hub_id']] = (int)$r['id'];
    }
    $mc = $conn->prepare("INSERT INTO hs_manual_listing_changes (hub_id, manual_listing_id, change_type, old_value, new_value, detected_at)
                          VALUES (?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE change_type=VALUES(change_type), old_value=VALUES(old_value),
                              new_value=VALUES(new_value), detected_at=VALUES(detected_at)");
    $orphans = 0;
    foreach ($d['manual_listing_changes'] as $c) {
        if ($dry) continue;
        $lid = $map[(int)$c['manual_listing_id']] ?? null;
        if (!$lid) { $orphans++; continue; }
        $hid = (int)$c['id']; $ct = (string)$c['change_type']; $ov = $c['old_value']; $nv = (string)$c['new_value']; $da = (string)$c['detected_at'];
        $mc->bind_param('iissss', $hid, $lid, $ct, $ov, $nv, $da);
        $mc->execute();
    }

    // ── Paperless Pipeline (only when the export carries it) ───────────────
    $n['pe'] = 0; $n['pt'] = 0;
    $has_pl = ($t = $conn->query("SHOW TABLES LIKE 'hs_pipeline_transactions'")) && $t->fetch_row();
    if (isset($d['pipeline_events'], $d['pipeline_transactions']) && !$has_pl) {
        echo "  ! the export carries Pipeline data but sql/hot_sheets_v3_pipeline.sql has not been run: skipped\n";
    } elseif (isset($d['pipeline_events'], $d['pipeline_transactions'])) {
        $pe = $conn->prepare("INSERT INTO hs_pipeline_events (hub_id, payload, headers, source_ip, parse_status, parse_notes, parsed_at, received_at)
                              VALUES (?,?,?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE payload = VALUES(payload), parse_status = VALUES(parse_status),
                                  parse_notes = VALUES(parse_notes), parsed_at = VALUES(parsed_at)");
        foreach ($d['pipeline_events'] as $e) {
            $n['pe']++;
            if ($dry) continue;
            $hid = (int)$e['id']; $pl = (string)$e['payload']; $hd = $e['headers'] ?? null; $ip = $e['source_ip'] ?? null;
            $ps = in_array($e['parse_status'] ?? '', ['unparsed', 'parsed', 'error', 'ignored'], true) ? $e['parse_status'] : 'parsed';
            $pn = $e['parse_notes'] ?? null; $pa = $e['parsed_at'] ?? null; $ra = (string)($e['received_at'] ?? date('Y-m-d H:i:s'));
            $pe->bind_param('isssssss', $hid, $pl, $hd, $ip, $ps, $pn, $pa, $ra);
            $pe->execute();
        }
        $ev_map = [];
        if (!$dry) foreach ($conn->query("SELECT id, hub_id FROM hs_pipeline_events WHERE hub_id IS NOT NULL")->fetch_all(MYSQLI_ASSOC) as $r) $ev_map[(int)$r['hub_id']] = (int)$r['id'];
        $ml_map = $map;
        $pt = $conn->prepare("INSERT INTO hs_pipeline_transactions
                (hub_id, event_id, pipeline_txn_id, address, postal_code, property_type, mls_number, side, status_category,
                 status_label, status_changed_at, acceptance_date, closing_date, sale_price, agent_names, unmatched_agents,
                 suggested_entry_type, mls_match_number, match_confidence, review_status, manual_listing_id, reviewed_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE hub_id = VALUES(hub_id), event_id = VALUES(event_id), address = VALUES(address),
                postal_code = VALUES(postal_code), property_type = VALUES(property_type), mls_number = VALUES(mls_number),
                side = VALUES(side), status_category = VALUES(status_category), status_label = VALUES(status_label),
                status_changed_at = VALUES(status_changed_at), acceptance_date = VALUES(acceptance_date),
                closing_date = VALUES(closing_date), sale_price = VALUES(sale_price), agent_names = VALUES(agent_names),
                unmatched_agents = VALUES(unmatched_agents), suggested_entry_type = VALUES(suggested_entry_type),
                mls_match_number = VALUES(mls_match_number), match_confidence = VALUES(match_confidence),
                review_status = VALUES(review_status), manual_listing_id = VALUES(manual_listing_id),
                reviewed_at = VALUES(reviewed_at)");
        foreach ($d['pipeline_transactions'] as $x) {
            $n['pt']++;
            echo "  ~ pipeline {$x['pipeline_txn_id']}: " . ($x['address'] ?? '') . " [{$x['review_status']}]\n";
            if ($dry) continue;
            $hid = (int)$x['id']; $eid = $ev_map[(int)$x['event_id']] ?? null; $txn = (string)$x['pipeline_txn_id'];
            $ad = $x['address'] ?? null; $pc = $x['postal_code'] ?? null; $pty = $x['property_type'] ?? null; $mn = $x['mls_number'] ?? null;
            $sd = $x['side'] ?? 'Unknown'; $sc = $x['status_category'] ?? null; $sl = $x['status_label'] ?? null;
            $sca = $x['status_changed_at'] ?? null; $acd = $x['acceptance_date'] ?? null; $cld = $x['closing_date'] ?? null;
            $sp = $x['sale_price'] ?? null; $an = $x['agent_names'] ?? null; $ua = $x['unmatched_agents'] ?? null;
            $se = $x['suggested_entry_type'] ?? null; $mmn = $x['mls_match_number'] ?? null;
            $mc = in_array($x['match_confidence'] ?? '', ['none', 'fuzzy', 'exact'], true) ? $x['match_confidence'] : 'none';
            $rs = in_array($x['review_status'] ?? '', ['pending', 'promoted', 'rejected'], true) ? $x['review_status'] : 'pending';
            $mli = !empty($x['manual_listing_id']) ? ($ml_map[(int)$x['manual_listing_id']] ?? null) : null;
            $ra = $x['reviewed_at'] ?? null;
            $pt->bind_param('iissssssssssssssssssis', $hid, $eid, $txn, $ad, $pc, $pty, $mn, $sd, $sc, $sl, $sca, $acd, $cld,
                            $sp, $an, $ua, $se, $mmn, $mc, $rs, $mli, $ra);
            $pt->execute();
        }
    }

    $dry ? $conn->rollback() : $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, '✗ ' . $e->getMessage() . " — rolled back, nothing written.\n");
    exit(1);
}

echo "\n" . ($dry ? 'DRY RUN: ' : '') . "{$n['sub_new']} new subscribers, {$n['sub_upd']} updated; "
   . count($d['manual_listings']) . ' manual listings, ' . count($d['manual_listing_changes']) . ' changes'
   . (!empty($orphans) ? ", {$orphans} changes skipped (their listing was not in the export)" : '')
   . (isset($d['pipeline_events']) ? "; {$n['pe']} pipeline events, {$n['pt']} pipeline transactions" : '') . ".\n";
$conn->close();
