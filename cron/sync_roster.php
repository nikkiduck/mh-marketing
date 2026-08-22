<?php
/**
 * sync_roster.php
 * Syncs agents from both Aspen and Vail MLS into office_roster (master table).
 * After syncing, re-links mh_brokers.roster_id for any newly added agents.
 * CLI-only — blocked from the web via .htaccess.
 *
 * Cron (SiteGround — daily at 3 AM server time):
 *   0 3 * * * /usr/bin/php /var/www/marketing.monthaus.com/cron/sync_roster.php >> /var/log/mh-marketing/sync_roster.log 2>&1
 *
 * Aspen: upserts by agent_key (has UNIQUE KEY uq_agent_key).
 * Vail:  no unique index on vail_agent_key, so we match by email first,
 *        then UPDATE existing rows or INSERT new Vail-only rows.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.' . PHP_EOL);
}

require_once __DIR__ . '/../inc/db.php';

$start = microtime(true);
$now   = date('Y-m-d H:i:s');
echo "[{$now}] Starting roster sync…" . PHP_EOL;

// ── Market config ─────────────────────────────────────────────────────────────
$markets_config = [
    'Aspen' => [
        'token'      => SPARK_ACCESS_TOKEN,
        'office_key' => '20260410152836477040000000',
        'endpoint'   => 'spark_v1',  // v1/accounts/by/office
    ],
    'Vail' => [
        'token'      => VAIL_SPARK_ACCESS_TOKEN,
        'office_key' => '20220505163801419347000000',
        'endpoint'   => 'reso_v3',   // RESO OData v3 Member
    ],
];

// ── HTTP helper ───────────────────────────────────────────────────────────────
function do_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $err];
}

// ── Extract phone/email from Spark account record ─────────────────────────────
function extract_phone(array $account): string {
    if (empty($account['Phones'])) return '';
    foreach ($account['Phones'] as $p) {
        if (!empty($p['Primary'])) return $p['Number'] ?? '';
    }
    return $account['Phones'][0]['Number'] ?? '';
}
function extract_email(array $account): string {
    if (empty($account['Emails'])) return '';
    foreach ($account['Emails'] as $e) {
        if (!empty($e['Primary'])) return $e['Address'] ?? '';
    }
    return $account['Emails'][0]['Address'] ?? '';
}

// ─────────────────────────────────────────────────────────────────────────────
// ASPEN — v1/accounts/by/office (upsert by agent_key UNIQUE KEY)
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "[{$now}] === Aspen ===" . PHP_EOL;

$cfg = $markets_config['Aspen'];
$res = do_get(
    "https://replication.sparkapi.com/v1/accounts/by/office/{$cfg['office_key']}?_limit=500",
    $cfg['token']
);

$aspen_upserted = 0;
$aspen_emails   = [];  // track emails for markets update

if ($res['error'] || $res['code'] !== 200) {
    echo "[{$now}] ✗ Aspen API error — HTTP {$res['code']}: {$res['error']}" . PHP_EOL;
} else {
    $decoded  = json_decode($res['body'], true);
    $accounts = $decoded['D']['Results'] ?? [];
    echo "[{$now}] Fetched " . count($accounts) . " Aspen accounts." . PHP_EOL;

    $stmt = $conn->prepare("
        INSERT INTO office_roster (agent_key, name, phone, email, office, markets, active, last_synced_at)
        VALUES (?, ?, ?, ?, ?, 'Aspen', 1, ?)
        ON DUPLICATE KEY UPDATE
            name           = VALUES(name),
            phone          = VALUES(phone),
            email          = VALUES(email),
            office         = VALUES(office),
            last_synced_at = VALUES(last_synced_at),
            markets        = CASE
                               WHEN markets IS NULL THEN 'Aspen'
                               WHEN markets NOT LIKE '%Aspen%' THEN CONCAT(markets, ', Aspen')
                               ELSE markets
                             END
            -- active intentionally omitted: manual deactivations survive re-sync
    ");

    foreach ($accounts as $account) {
        $agent_key = trim($account['Id'] ?? '');
        if (!$agent_key) continue;

        $name   = trim($account['Name']   ?? '');
        $office = trim($account['Office'] ?? '');
        $phone  = extract_phone($account);
        $email  = extract_email($account);

        $stmt->bind_param('ssssss', $agent_key, $name, $phone, $email, $office, $now);
        if ($stmt->execute()) {
            $aspen_upserted++;
            if ($email) $aspen_emails[] = strtolower($email);
        } else {
            echo "[{$now}] ✗ Aspen upsert failed ({$name}): " . $stmt->error . PHP_EOL;
        }
    }
    $stmt->close();
    echo "[{$now}] Aspen: {$aspen_upserted} upserted." . PHP_EOL;
}

// ─────────────────────────────────────────────────────────────────────────────
// VAIL — RESO OData v3 Member endpoint (match by email, no unique key)
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "[{$now}] === Vail ===" . PHP_EOL;

$cfg      = $markets_config['Vail'];
$vail_url = 'https://replication.sparkapi.com/Version/3/Reso/OData/Member?'
          . http_build_query([
              '$filter' => "OfficeKey eq '{$cfg['office_key']}' and MemberStatus eq 'Active'",
              '$select' => 'MemberKey,MemberFullName,MemberFirstName,MemberLastName,MemberEmail,MemberMobilePhone,OfficeName',
              '$top'    => 200,
          ]);

$vail_updated  = 0;
$vail_inserted = 0;
$page = 1;

while ($vail_url !== null) {
    echo "[{$now}] Vail page {$page}: fetching…" . PHP_EOL;
    $res = do_get($vail_url, $cfg['token']);

    if ($res['error'] || $res['code'] !== 200) {
        echo "[{$now}] ✗ Vail API error — HTTP {$res['code']}: {$res['error']}" . PHP_EOL;
        break;
    }

    $data    = json_decode($res['body'], true);
    $members = $data['value'] ?? [];
    echo "[{$now}] Vail page {$page}: " . count($members) . " member(s)." . PHP_EOL;

    foreach ($members as $m) {
        $vail_key = trim($m['MemberKey'] ?? '');
        if (!$vail_key) continue;

        $full = trim($m['MemberFullName'] ?? '');
        if (!$full) {
            $full = trim(($m['MemberFirstName'] ?? '') . ' ' . ($m['MemberLastName'] ?? ''));
        }
        $email  = trim($m['MemberEmail']       ?? '') ?: null;
        $phone  = trim($m['MemberMobilePhone']  ?? '') ?: null;
        $office = trim($m['OfficeName']         ?? '') ?: 'Mont Haus International Realty';

        // Try to find an existing roster row by email
        $existing_id = null;
        if ($email) {
            $chk = $conn->prepare(
                "SELECT id FROM office_roster WHERE LOWER(email) = LOWER(?) LIMIT 1"
            );
            $chk->bind_param('s', $email);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($row) $existing_id = (int)$row['id'];
        }

        if ($existing_id) {
            // Update existing row — add vail_agent_key and mark Vail market
            $upd = $conn->prepare("
                UPDATE office_roster SET
                    vail_agent_key = ?,
                    last_synced_at = ?,
                    markets = CASE
                                WHEN markets IS NULL THEN 'Vail'
                                WHEN markets NOT LIKE '%Vail%' THEN CONCAT(markets, ', Vail')
                                ELSE markets
                              END
                WHERE id = ?
            ");
            $upd->bind_param('ssi', $vail_key, $now, $existing_id);
            $upd->execute();
            $upd->close();
            $vail_updated++;
        } else {
            // New Vail-only agent — insert as active
            $ins = $conn->prepare("
                INSERT INTO office_roster (vail_agent_key, name, phone, email, office, markets, active, last_synced_at)
                VALUES (?, ?, ?, ?, ?, 'Vail', 1, ?)
                ON DUPLICATE KEY UPDATE last_synced_at = VALUES(last_synced_at)
            ");
            $ins->bind_param('ssssss', $vail_key, $full, $phone, $email, $office, $now);
            $ins->execute();
            $ins->close();
            $vail_inserted++;
            echo "[{$now}]   + New Vail agent: {$full}" . PHP_EOL;
        }
    }

    $vail_url = $data['@odata.nextLink'] ?? null;
    $page++;
}

echo "[{$now}] Vail: {$vail_updated} updated, {$vail_inserted} new." . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// RE-LINK mh_brokers.roster_id for any newly synced agents
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "[{$now}] Re-linking mh_brokers → office_roster by email…" . PHP_EOL;

$linked = $conn->query("
    UPDATE mh_brokers mb
    JOIN office_roster r
      ON LOWER(r.email COLLATE utf8mb4_unicode_ci) = LOWER(mb.email COLLATE utf8mb4_unicode_ci)
    SET mb.roster_id = r.id
    WHERE mb.roster_id IS NULL
");
$mb_linked = $conn->affected_rows;
echo "[{$now}] mh_brokers: {$mb_linked} newly linked." . PHP_EOL;

// Re-link marketing_intakes too
$conn->query("
    UPDATE marketing_intakes mi
    JOIN office_roster r
      ON LOWER(r.email COLLATE utf8mb4_unicode_ci) = LOWER(mi.mh_email COLLATE utf8mb4_unicode_ci)
    SET mi.roster_id = r.id
    WHERE mi.roster_id IS NULL
");
$mi_linked = $conn->affected_rows;
echo "[{$now}] marketing_intakes: {$mi_linked} newly linked." . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
$conn->close();
$elapsed = round(microtime(true) - $start, 2);
echo PHP_EOL . "[{$now}] ✓ Roster sync complete in {$elapsed}s." . PHP_EOL;
echo "[{$now}]   Aspen: {$aspen_upserted} upserted" . PHP_EOL;
echo "[{$now}]   Vail:  {$vail_updated} updated + {$vail_inserted} new" . PHP_EOL;
echo "[{$now}]   Links: {$mb_linked} mh_brokers, {$mi_linked} marketing_intakes" . PHP_EOL;
