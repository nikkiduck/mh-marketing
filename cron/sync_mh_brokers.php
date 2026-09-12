<?php
/**
 * sync_mh_brokers.php
 * CLI-only — blocked from the web via .htaccess.
 *
 * Pulls active Mont Haus Members from both Aspen/Glenwood and Vail MLS
 * via the RESO OData v3 Member endpoint (OfficeKey filter), then upserts
 * full_name, email, mobile_phone, and markets into mh_brokers.
 *
 * Strategy: ON DUPLICATE KEY UPDATE keeps those fields current on every run,
 * but never touches is_active so manual deactivations are preserved.
 * To deactivate a departed broker:
 *   UPDATE mh_brokers SET is_active = 0 WHERE full_name = 'Name Here';
 *
 * Usage:
 *   php sync_mh_brokers.php           # live run
 *   php sync_mh_brokers.php --dry-run # print names, no DB writes
 *
 * Cron (Lightsail — daily, UTC). Runs BEFORE sync_roster.php, which re-links
 * mh_brokers.roster_id at the end of its own run:
 *   0 3 * * * /usr/bin/php /var/www/marketing.monthaus.com/cron/sync_mh_brokers.php >> /var/log/mh-marketing/sync_mh_brokers.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.' . PHP_EOL);
}

require_once __DIR__ . '/../inc/db.php';

$dry_run = in_array('--dry-run', $argv ?? [], true);

// ── Market config ─────────────────────────────────────────────────────────────
// OfficeKey values confirmed via RESO OData v3 Member endpoint:
//   Aspen: confirmed in mls_debug.php (OFFICE_ID)
//   Vail:  confirmed via vail_reso_debug.php
$markets = [
    'Aspen' => [
        'token'      => SPARK_ACCESS_TOKEN,        // dvmnyve1x08y9y92rx72z75yo
        'office_key' => '20260410152836477040000000',
    ],
    'Vail'  => [
        'token'      => VAIL_SPARK_ACCESS_TOKEN,   // 7h1aa95tbaobfcqpzfkgkbjvr
        'office_key' => '20220505163801419347000000',
    ],
];

// ── HTTP helper ───────────────────────────────────────────────────────────────
function reso_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $error];
}

/**
 * Fetch all active Members for one office, following @odata.nextLink pages.
 * Returns array of ['full_name' => ..., 'email' => ...] records,
 * de-duped by full_name.
 */
function fetch_brokers(string $token, string $office_key, string $market): array {
    $url = 'https://replication.sparkapi.com/Version/3/Reso/OData/Member?'
         . http_build_query([
               '$filter' => "OfficeKey eq '{$office_key}' and MemberStatus eq 'Active'",
               '$select' => 'MemberFullName,MemberFirstName,MemberLastName,MemberEmail,MemberMobilePhone,MemberStatus',
               '$top'    => 200,
           ]);

    $brokers = [];  // keyed by full_name for dedup
    $page    = 1;

    while ($url !== null) {
        echo "  [{$market}] page {$page}: {$url}\n";
        $res = reso_get($url, $token);

        if ($res['error']) {
            echo "  [{$market}] cURL error: {$res['error']}\n";
            return array_values($brokers);
        }
        if ($res['code'] !== 200) {
            echo "  [{$market}] HTTP {$res['code']}\n";
            echo "  [{$market}] Body: {$res['body']}\n";
            return array_values($brokers);
        }

        $data    = json_decode($res['body'], true);
        $records = $data['value'] ?? [];
        echo "  [{$market}] " . count($records) . " record(s) on page {$page}.\n";

        foreach ($records as $m) {
            $full = trim($m['MemberFullName'] ?? '');
            if ($full === '') {
                $first = trim($m['MemberFirstName'] ?? '');
                $last  = trim($m['MemberLastName']  ?? '');
                $full  = trim($first . ' ' . $last);
            }
            if ($full === '') continue;

            $brokers[$full] = [
                'full_name'    => $full,
                'email'        => trim($m['MemberEmail']       ?? '') ?: null,
                'mobile_phone' => trim($m['MemberMobilePhone'] ?? '') ?: null,
            ];
        }

        $url = $data['@odata.nextLink'] ?? null;
        $page++;
    }

    return array_values($brokers);
}

// ── Collect brokers from both markets ────────────────────────────────────────
$all_brokers = [];  // keyed by full_name for cross-market dedup

foreach ($markets as $market => $cfg) {
    echo "\n=== {$market} ===\n";
    $brokers = fetch_brokers($cfg['token'], $cfg['office_key'], $market);
    echo "  [{$market}] " . count($brokers) . " active broker(s):\n";
    foreach ($brokers as $b) {
        $email_str = $b['email'] ? " <{$b['email']}>" : '';
        echo "    - {$b['full_name']}{$email_str}\n";
        if (isset($all_brokers[$b['full_name']])) {
            // Same name appeared in both markets — merge market list, keep existing email
            $all_brokers[$b['full_name']]['markets'][] = $market;
        } else {
            $b['markets'] = [$market];
            $all_brokers[$b['full_name']] = $b;
        }
    }
}

$all_brokers = array_values($all_brokers);
usort($all_brokers, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));

echo "\n=== " . count($all_brokers) . " unique broker(s) across both markets ===\n";

// ── Dry-run exit ──────────────────────────────────────────────────────────────
if ($dry_run) {
    echo "\n[dry-run] No DB writes.\n";
    exit(0);
}

// ── Upsert into mh_brokers ────────────────────────────────────────────────────
// New brokers: INSERT with all fields.
// Existing brokers: UPDATE email, mobile_phone, markets — is_active is
//   intentionally excluded so manual deactivations survive a re-sync.
$stmt = $conn->prepare(
    "INSERT INTO mh_brokers (full_name, email, mobile_phone, markets) VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         email        = VALUES(email),
         mobile_phone = VALUES(mobile_phone),
         markets      = VALUES(markets)"
);
$added        = 0;
$updated      = 0;
$newly_added  = [];   // collect for notification email

foreach ($all_brokers as $b) {
    $markets_str = implode(', ', $b['markets']);
    $stmt->bind_param('ssss', $b['full_name'], $b['email'], $b['mobile_phone'], $markets_str);
    $stmt->execute();
    // affected_rows: 1 = inserted, 2 = updated, 0 = row existed and data unchanged
    if ($conn->affected_rows === 1) {
        $added++;
        $newly_added[] = $b;
        echo "  + Added:   {$b['full_name']}\n";
    } elseif ($conn->affected_rows === 2) {
        $updated++;
        echo "  ~ Updated: {$b['full_name']}\n";
    }
}
$stmt->close();

echo "\nDone. {$added} added, {$updated} updated in mh_brokers.\n";
echo "To deactivate a departed broker:\n";
echo "  UPDATE mh_brokers SET is_active = 0 WHERE full_name = 'Name Here';\n";

// ── Notify on new additions ───────────────────────────────────────────────────
if (!empty($newly_added)) {
    $rows = '';
    foreach ($newly_added as $b) {
        $email_cell  = $b['email'] ? htmlspecialchars($b['email']) : '<em style="color:#9ca3af;">none</em>';
        $market_cell = htmlspecialchars(implode(' & ', $b['markets']));
        $rows .= "<tr>"
               . "<td style='padding:6px 12px;border-bottom:1px solid #e5e7eb;'>" . htmlspecialchars($b['full_name']) . "</td>"
               . "<td style='padding:6px 12px;border-bottom:1px solid #e5e7eb;'>{$email_cell}</td>"
               . "<td style='padding:6px 12px;border-bottom:1px solid #e5e7eb;'>{$market_cell}</td>"
               . "</tr>";
    }

    $count     = count($newly_added);
    $plural    = $count === 1 ? 'broker' : 'brokers';
    $timestamp = date('F j, Y \a\t g:i A T');

    $html_body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;padding:24px;">
  <p style="margin:0 0 16px;">The broker roster sync just added <strong>{$count} new {$plural}</strong> to <code>mh_brokers</code> ({$timestamp}):</p>
  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;">
    <thead>
      <tr style="background:#f3f4f6;">
        <th style="padding:7px 12px;text-align:left;border-bottom:1px solid #e5e7eb;">Name</th>
        <th style="padding:7px 12px;text-align:left;border-bottom:1px solid #e5e7eb;">MLS Email</th>
        <th style="padding:7px 12px;text-align:left;border-bottom:1px solid #e5e7eb;">MLS</th>
      </tr>
    </thead>
    <tbody>{$rows}</tbody>
  </table>
  <p style="margin:20px 0 0;color:#6b7280;font-size:12px;">
    Sent by sync_mh_brokers.php — Mont Haus Hot Sheet
  </p>
</body>
</html>
HTML;

    $text_body = "The broker roster sync added {$count} new {$plural} ({$timestamp}):\n\n";
    foreach ($newly_added as $b) {
        $mls_str    = implode(' & ', $b['markets']);
        $text_body .= "  - {$b['full_name']}" . ($b['email'] ? " <{$b['email']}>" : '') . " [{$mls_str}]\n";
    }
    $text_body .= "\nSent by sync_mh_brokers.php — Mont Haus Hot Sheet\n";

    $payload = json_encode([
        'personalizations' => [[
            'to' => [['email' => 'nikki.boxer@monthaus.com', 'name' => 'Nikki Boxer']],
        ]],
        'from'    => ['email' => 'hotsheet@monthaus.com', 'name' => 'Mont Haus Hot Sheet'],
        'subject' => "Roster sync: {$count} new {$plural} added",
        'content' => [
            ['type' => 'text/plain', 'value' => $text_body],
            ['type' => 'text/html',  'value' => $html_body],
        ],
    ]);

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . SENDGRID_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $sg_body = curl_exec($ch);
    $sg_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $sg_err  = curl_error($ch);
    curl_close($ch);

    if ($sg_code >= 200 && $sg_code < 300) {
        echo "Notification sent to nikki.boxer@monthaus.com ({$count} new {$plural}).\n";
    } else {
        echo "WARNING: notification email failed. HTTP {$sg_code}"
           . ($sg_err  ? " / cURL: {$sg_err}"   : '')
           . ($sg_body ? " / Body: {$sg_body}"   : '') . "\n";
    }
}

$conn->close();
