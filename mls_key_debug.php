<?php
/**
 * mls_key_debug.php
 * Read-only diagnostic for "why aren't this agent's listings showing?"
 *
 * Queries Spark two ways and shows the raw answers side by side:
 *   1. By agent key   — exactly what agent.php does, but with NO status filter
 *   2. By agent name  — finds listings the key misses, and shows which key each
 *                       one actually carries (team keys, co-list keys, etc.)
 *
 * Usage:
 *   /mls_key_debug.php?key=20090111180204477889000000&board=Aspen
 *   /mls_key_debug.php?name=Casson&board=Aspen
 *   ...or ?intake_id=12 to pull the key straight off an agent record.
 *
 * DELETE once the issue is resolved.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_login();
require_role('admin');

header('Content-Type: text/plain; charset=utf-8');

$board = (strcasecmp($_GET['board'] ?? 'Aspen', 'Vail') === 0) ? 'Vail' : 'Aspen';
$token = $board === 'Vail' ? VAIL_SPARK_ACCESS_TOKEN : SPARK_ACCESS_TOKEN;
$key   = trim($_GET['key']  ?? '');
$name  = trim($_GET['name'] ?? '');

// Pull the key off an agent record if given an intake id
$intake_id = (int)($_GET['intake_id'] ?? 0);
if ($intake_id && $key === '') {
    $q = $conn->query("
        SELECT mi.agent_name, r.agent_key, r.vail_agent_key
          FROM marketing_intakes mi
          LEFT JOIN office_roster r ON r.id = mi.roster_id
         WHERE mi.id = {$intake_id} LIMIT 1");
    if ($q && $row = $q->fetch_assoc()) {
        $key  = $board === 'Vail' ? (string)$row['vail_agent_key'] : (string)$row['agent_key'];
        if ($name === '') $name = (string)$row['agent_name'];
        echo "Agent record {$intake_id}: {$row['agent_name']}\n";
    }
}

function spark_get(string $filter, string $token): array {
    $url = 'https://replication.sparkapi.com/Reso/OData/Property'
         . '?$filter=' . rawurlencode($filter) . '&$top=200';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return ['error' => "HTTP {$code}", 'rows' => [], 'body' => substr((string)$raw, 0, 400)];
    $d = json_decode($raw, true);
    return ['error' => null, 'rows' => $d['value'] ?? [], 'body' => ''];
}

function dump(array $rows): void {
    if (!$rows) { echo "  (none)\n"; return; }
    printf("  %-10s %-34s %-22s %-28s %s\n", 'MLS#', 'ADDRESS', 'STATUS', 'LIST AGENT KEY', 'LIST AGENT');
    foreach ($rows as $p) {
        printf("  %-10s %-34s %-22s %-28s %s\n",
            substr((string)($p['ListingId'] ?? '?'), 0, 10),
            substr((string)($p['UnparsedAddress'] ?? '?'), 0, 33),
            substr((string)($p['MlsStatus'] ?? '?'), 0, 21),
            substr((string)($p['ListAgentKey'] ?? '—'), 0, 27),
            (string)($p['ListAgentFullName'] ?? $p['ListAgentName'] ?? ''));
        $co = trim((string)($p['CoListAgentKey'] ?? ''));
        if ($co !== '') {
            printf("  %-10s   co-list: %-24s %s\n", '', $co,
                (string)($p['CoListAgentFullName'] ?? $p['CoListAgentName'] ?? ''));
        }
    }
    // Status tally
    $tally = [];
    foreach ($rows as $p) {
        $s = (string)($p['MlsStatus'] ?? '?');
        $tally[$s] = ($tally[$s] ?? 0) + 1;
    }
    echo "\n  status tally: ";
    foreach ($tally as $s => $n) echo "{$s}={$n}  ";
    echo "\n";
}

echo str_repeat('=', 100) . "\n";
echo "Board: {$board}   Key: " . ($key ?: '(none)') . "   Name: " . ($name ?: '(none)') . "\n";
echo str_repeat('=', 100) . "\n\n";

if ($key !== '') {
    $esc = str_replace("'", "''", $key);

    echo "1a. BY KEY — no status filter (everything the MLS has for this key)\n";
    $r = spark_get("(ListAgentKey eq '{$esc}' or CoListAgentKey eq '{$esc}')", $token);
    if ($r['error']) { echo "  ERROR {$r['error']}\n  {$r['body']}\n"; } else { dump($r['rows']); }
    echo "\n";

    echo "1b. BY KEY — Active/Pending only (what agent.php currently asks for)\n";
    $r2 = spark_get("(ListAgentKey eq '{$esc}' or CoListAgentKey eq '{$esc}')"
                  . " and (MlsStatus eq 'Active' or MlsStatus eq 'Pending')", $token);
    if ($r2['error']) { echo "  ERROR {$r2['error']}\n"; } else { dump($r2['rows']); }
    echo "\n";
}

if ($name !== '') {
    // Last word of the name is the most reliable thing to search on
    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $last  = $parts ? end($parts) : $name;
    $lesc  = str_replace("'", "''", $last);

    echo "2. BY NAME — contains '{$last}', no status filter\n";
    echo "   (any row here missing from section 1a carries a different agent key)\n";
    $r3 = spark_get("contains(ListAgentFullName,'{$lesc}') or contains(CoListAgentFullName,'{$lesc}')", $token);
    if ($r3['error']) { echo "  ERROR {$r3['error']}\n  {$r3['body']}\n"; } else { dump($r3['rows']); }
}

// ── Spark v1 ─────────────────────────────────────────────────────────────────
// RESO OData's ListAgentKey is not always the same value as the Spark v1
// account UUID that sync_roster stores (see create_b2b.php line 116). If the
// key came from the v1 accounts endpoint, only v1 will match it.
function spark_v1(string $path, string $token): array {
    $url = 'https://replication.sparkapi.com' . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode((string)$raw, true);
    return [
        'code' => $code,
        'rows' => $d['D']['Results'] ?? [],
        'body' => substr((string)$raw, 0, 400),
    ];
}

if ($key !== '') {
    $esc = str_replace("'", "''", $key);

    echo "\n" . str_repeat('-', 100) . "\n";
    echo "3. SPARK v1 — is this key a valid v1 account?\n";
    $acct = spark_v1('/v1/accounts/' . rawurlencode($key), $token);
    echo "   HTTP {$acct['code']}\n";
    if ($acct['rows']) {
        foreach ($acct['rows'] as $a) {
            echo "   Name    : " . ($a['Name'] ?? '?') . "\n";
            echo "   Id      : " . ($a['Id'] ?? '?') . "\n";
            echo "   MlsId   : " . ($a['MlsId'] ?? '—') . "\n";
            echo "   Office  : " . ($a['Office'] ?? '—') . "\n";
            echo "   Type    : " . ($a['Type'] ?? '—') . "\n";
        }
        echo "   -> the key IS a Spark v1 account id, so v1 is the right API to query\n";
    } else {
        echo "   no account returned\n   " . $acct['body'] . "\n";
    }

    echo "\n4. SPARK v1 — listings where ListAgentKey = this key (no status filter)\n";
    $v1 = spark_v1('/v1/listings?' . http_build_query([
        '_filter' => "ListAgentKey Eq '{$esc}'",
        '_limit'  => 100,
    ]), $token);
    echo "   HTTP {$v1['code']}  results: " . count($v1['rows']) . "\n";
    foreach ($v1['rows'] as $l) {
        $sf = $l['StandardFields'] ?? [];
        printf("   %-10s %-34s %-22s %s\n",
            substr((string)($sf['ListingId'] ?? '?'), 0, 10),
            substr((string)($sf['UnparsedFirstLineAddress'] ?? $sf['UnparsedAddress'] ?? '?'), 0, 33),
            substr((string)($sf['MlsStatus'] ?? '?'), 0, 21),
            (string)($sf['ListAgentName'] ?? ''));
    }
    if (!$v1['rows']) echo "   " . $v1['body'] . "\n";

    echo "\n5. SPARK v1 — same, but matching CoListAgentKey\n";
    $v1c = spark_v1('/v1/listings?' . http_build_query([
        '_filter' => "CoListAgentKey Eq '{$esc}'",
        '_limit'  => 100,
    ]), $token);
    echo "   HTTP {$v1c['code']}  results: " . count($v1c['rows']) . "\n";
    foreach ($v1c['rows'] as $l) {
        $sf = $l['StandardFields'] ?? [];
        printf("   %-10s %-34s %-22s %s\n",
            substr((string)($sf['ListingId'] ?? '?'), 0, 10),
            substr((string)($sf['UnparsedFirstLineAddress'] ?? $sf['UnparsedAddress'] ?? '?'), 0, 33),
            substr((string)($sf['MlsStatus'] ?? '?'), 0, 21),
            (string)($sf['ListAgentName'] ?? ''));
    }
}

// ── Other identifiers on the account ─────────────────────────────────────────
// An agent who changed brokerages has a separate Spark account per brokerage,
// each with its own Id. Listings written while they were elsewhere carry that
// other account's key, so the current key cannot find them. MlsId and the
// agent's name usually do follow the person across brokerages.
if ($key !== '') {
    $acct2 = spark_v1('/v1/accounts/' . rawurlencode($key), $token);
    $arow  = $acct2['rows'][0] ?? [];
    $mlsid = trim((string)($arow['MlsId'] ?? ''));
    $aname = trim((string)($arow['Name']  ?? ''));

    if ($mlsid !== '') {
        $mesc = str_replace("'", "''", $mlsid);
        echo "\n" . str_repeat('-', 100) . "\n";
        echo "6. SPARK v1 — listings by ListAgentMlsId Eq '{$mlsid}' (no status filter)\n";
        $r6 = spark_v1('/v1/listings?' . http_build_query([
            '_filter' => "ListAgentMlsId Eq '{$mesc}' Or CoListAgentMlsId Eq '{$mesc}'",
            '_limit'  => 100,
        ]), $token);
        echo "   HTTP {$r6['code']}  results: " . count($r6['rows']) . "\n";
        foreach ($r6['rows'] as $l) {
            $sf = $l['StandardFields'] ?? [];
            printf("   %-9s %-32s %-12s %-28s %s\n",
                substr((string)($sf['ListingId'] ?? '?'), 0, 9),
                substr((string)($sf['UnparsedFirstLineAddress'] ?? '?'), 0, 31),
                substr((string)($sf['MlsStatus'] ?? '?'), 0, 11),
                substr((string)($sf['ListAgentKey'] ?? '—'), 0, 27),
                substr((string)($sf['ListOfficeName'] ?? ''), 0, 30));
        }
        if (!$r6['rows']) echo "   " . $r6['body'] . "\n";
    }

    if ($aname !== '') {
        $nesc = str_replace("'", "''", $aname);
        echo "\n7. SPARK v1 — listings by ListAgentName Eq '{$aname}' (no status filter)\n";
        echo "   The ListAgentKey column below is what each listing actually carries —\n";
        echo "   any value different from the account id above is a prior-brokerage key.\n";
        $r7 = spark_v1('/v1/listings?' . http_build_query([
            '_filter' => "ListAgentName Eq '{$nesc}' Or CoListAgentName Eq '{$nesc}'",
            '_limit'  => 100,
        ]), $token);
        echo "   HTTP {$r7['code']}  results: " . count($r7['rows']) . "\n";
        foreach ($r7['rows'] as $l) {
            $sf = $l['StandardFields'] ?? [];
            printf("   %-9s %-32s %-12s %-28s %s\n",
                substr((string)($sf['ListingId'] ?? '?'), 0, 9),
                substr((string)($sf['UnparsedFirstLineAddress'] ?? '?'), 0, 31),
                substr((string)($sf['MlsStatus'] ?? '?'), 0, 11),
                substr((string)($sf['ListAgentKey'] ?? '—'), 0, 27),
                substr((string)($sf['ListOfficeName'] ?? ''), 0, 30));
        }
        if (!$r7['rows']) echo "   " . $r7['body'] . "\n";
    }
}

// ── Direct MLS-number lookup ─────────────────────────────────────────────────
// Pass ?mls=186757 with a known listing of hers. This answers whether the token
// can see the listing at all, and prints the agent fields it actually carries —
// which is the ground truth for what any filter has to match on.
$mls_q = trim($_GET['mls'] ?? '');
if ($mls_q !== '') {
    foreach (preg_split('/[,\s]+/', $mls_q, -1, PREG_SPLIT_NO_EMPTY) as $one) {
        $oesc = str_replace("'", "''", $one);
        echo "\n" . str_repeat('-', 100) . "\n";
        echo "8. SPARK v1 — direct lookup of MLS# {$one}\n";
        $r8 = spark_v1('/v1/listings?' . http_build_query([
            '_filter' => "ListingId Eq '{$oesc}'",
            '_limit'  => 1,
        ]), $token);
        echo "   HTTP {$r8['code']}  results: " . count($r8['rows']) . "\n";
        if (!$r8['rows']) {
            echo "   NOT VISIBLE to this token — " . substr($r8['body'], 0, 200) . "\n";
            continue;
        }
        $sf = $r8['rows'][0]['StandardFields'] ?? [];
        foreach ([
            'UnparsedFirstLineAddress', 'MlsStatus', 'ListOfficeName', 'ListOfficeMlsId',
            'ListAgentName', 'ListAgentKey', 'ListAgentMlsId',
            'CoListAgentName', 'CoListAgentKey', 'CoListAgentMlsId',
        ] as $f) {
            printf("   %-26s %s\n", $f . ':', (string)($sf[$f] ?? '—'));
        }
    }
}

echo "\nDone.\n";
