<?php
/**
 * cron/sync_anyprop_roster.php — Anyprop Member feed → the one agent roster.
 *
 * Replaces the Spark crons (sync_roster.php, sync_mh_brokers.php) once the
 * production feed is live. Until then it runs alongside them: it writes only
 * marketing_intakes' mls_* / web fields and agent_mls_ids, never office_roster
 * or mh_brokers.
 *
 * For every Mont Haus member returned by Anyprop:
 *   1. known identity (market + MemberMlsId)    → refresh the row's mls_* fields
 *      (alias identities: heartbeat only, never refresh from a team record)
 *   2. unknown, email matches exactly one row    → attach as a new identity
 *   3. unknown, name matches exactly one row     → attach as a new identity
 *   4. still unknown                             → create a row, status 'active'
 *      (Active = with Mont Haus in the MLS), web_status 'pending' (the website
 *      waits for approval), plus the marketing checklist (mk_onboard). The
 *      Hot Sheets start only when "Hot Sheets: Subscribe" is ticked on it.
 * Then, per board that returned members:
 *   · an identity the feed stopped returning goes Inactive; an agent left with
 *     no active identity gets departure_detected_at, which puts them on the
 *     roster's Inactive tab. Their website profile, Hot Sheets and FUB are NOT
 *     touched: Nikki offboards by hand (2026-09-22).
 *   · an Inactive agent the feed returns again is Active again.
 * New, Inactive and back-again agents are emailed to ROSTER_ALERT_EMAILS
 * (inc/roster_alerts.php).
 *   · an identity the feed has NEVER returned (last_seen_at NULL, i.e. seeded
 *     from the Spark era) is reported, never used to deactivate anyone.
 * Curated fields (agent_name, mh_email, cell_phone, bio_text, social_*,
 * headshots, status) are never written, and nothing is ever deleted.
 *
 * Usage:
 *   php cron/sync_anyprop_roster.php --dry-run          # print every decision, write nothing
 *   php cron/sync_anyprop_roster.php                    # live
 *   php cron/sync_anyprop_roster.php --board=cren       # one board (OriginatingSystemName)
 *   php cron/sync_anyprop_roster.php --save=/tmp/ap.json   # also save the raw Member payload
 *   php cron/sync_anyprop_roster.php --fixture=tests/fixtures/anyprop_members.json
 *                                                       # replay a saved payload, no API calls
 *
 * Requires in inc/db.php: ANYPROP_USERNAME, ANYPROP_PASSWORD.
 * Optional:               ANYPROP_MH_OFFICE_IDS = ['OSN:OfficeMlsId', ...] to skip
 *                         office discovery (e.g. ['agsmls:805522330']).
 *                         ANYPROP_MH_MEMBER_IDS = ['OSN:MemberMlsId', ...]: agents known
 *                         to be Mont Haus on a board whose office id we do not know.
 *                         They are fetched by id, AND their OfficeMlsId is then used to
 *                         pull everyone else in that office. That is how CREN is found:
 *                         its office never matched a name search (probe, 2026-09-21).
 *
 * Cron: see CLAUDE.md > Cron for the live schedule (written out there, never
 * inside a block comment). A run that finds the previous one still going
 * exits at once (flock on the system temp dir), so a slow API cannot stack runs.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$lock = fopen(sys_get_temp_dir() . '/mh_sync_anyprop_roster.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . gmdate('Y-m-d H:i:s') . '] Previous run still going; skipped.' . PHP_EOL;
    exit(0);
}

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/agent_roster.php';
require_once __DIR__ . '/../inc/agent_lifecycle.php';
require_once __DIR__ . '/../inc/roster_alerts.php';

// The environment overrides exist for the test harness, which points them at
// a local stand-in for Anyprop. Nothing on the server sets them.
define('AP_TOKEN_URL', getenv('MH_ANYPROP_TOKEN_URL') ?: 'https://api.anyprop.com/v1/token');
define('AP_BASE',      getenv('MH_ANYPROP_BASE')      ?: 'https://api.anyprop.com/v1/listings/data');

$args    = array_slice($argv, 1);
$dry     = in_array('--dry-run', $args, true);
$board   = null; $save = null; $fixture = null;
foreach ($args as $a) {
    if (preg_match('/^--board=([A-Za-z0-9_]+)$/', $a, $m)) $board   = strtolower($m[1]);
    if (preg_match('/^--save=(.+)$/', $a, $m))              $save    = $m[1];
    if (preg_match('/^--fixture=(.+)$/', $a, $m))           $fixture = $m[1];
}
$now = gmdate('Y-m-d H:i:s');   // db.php pins the session to UTC
function out(string $s): void { global $now; echo "[{$now}] {$s}" . PHP_EOL; }

out('Anyprop roster sync' . ($dry ? ' — DRY RUN, nothing will be written' : '')
    . ($board ? " — board {$board}" : '') . ($fixture ? " — fixture {$fixture}" : ''));

// Fail loudly if the migration has not run: every query below would error.
$chk = $conn->query("SHOW COLUMNS FROM marketing_intakes LIKE 'web_status'");
if (!$chk || !$chk->fetch_row()) {
    out('✗ sql/agent_roster_v1.sql has not been run — marketing_intakes.web_status is missing. Nothing done.');
    exit(1);
}

// ── Anyprop HTTP ─────────────────────────────────────────────────────────────
function ap_token(): string {
    static $token = null;
    if ($token) return $token;
    if (!defined('ANYPROP_USERNAME') || ANYPROP_USERNAME === '' || !defined('ANYPROP_PASSWORD')) {
        throw new RuntimeException('ANYPROP_USERNAME / ANYPROP_PASSWORD are not defined in inc/db.php');
    }
    $ch = curl_init(AP_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['username' => ANYPROP_USERNAME, 'password' => ANYPROP_PASSWORD]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err) throw new RuntimeException("token request failed: {$err}");
    $d = json_decode($raw ?: '', true);
    if ($code !== 200 || empty($d['access_token'])) {
        throw new RuntimeException("token refused (HTTP {$code}): " . substr((string)$raw, 0, 300));
    }
    return $token = $d['access_token'];
}

/** GET every page of an OData query, following @odata.nextLink. */
function ap_get_all(string $url): array {
    $rows = []; $pages = 0;
    while ($url !== null && $pages++ < 50) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . ap_token(), 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_ENCODING       => '',
        ]);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($err)          throw new RuntimeException("cURL: {$err}");
        if ($code !== 200) throw new RuntimeException("HTTP {$code}: " . substr((string)$raw, 0, 400));
        $d = json_decode($raw, true);
        if (!is_array($d)) throw new RuntimeException('response was not JSON: ' . substr((string)$raw, 0, 200));
        foreach ($d['value'] ?? [] as $r) $rows[] = $r;
        $url = $d['@odata.nextLink'] ?? null;
    }
    return $rows;
}

function odata_q(string $s): string { return str_replace("'", "''", $s); }

// ── 1. Fetch Mont Haus members ───────────────────────────────────────────────
try {
    if ($fixture) {
        $d = json_decode((string)@file_get_contents($fixture), true);
        if (!is_array($d)) throw new RuntimeException("cannot read fixture {$fixture}");
        $members = $d['value'] ?? $d;
        out('Fixture: ' . count($members) . ' member record(s).');
    } else {
        // Offices: the configured ids PLUS a name search on every board. The
        // search used to run only when nothing was configured, so with Aspen
        // configured a newly live board (Elevate, 2026-09-30) was never looked
        // at. Now each run finds Mont Haus offices on whatever boards Anyprop
        // carries, as the public site's syncs do. OData contains() is
        // case-sensitive and boards differ in casing (CREN tends to ALL CAPS),
        // so ask for all three spellings and keep only real Mont Haus names.
        $offices = [];   // "osn|OfficeMlsId" → label
        if (defined('ANYPROP_MH_OFFICE_IDS') && ANYPROP_MH_OFFICE_IDS) {
            foreach (ANYPROP_MH_OFFICE_IDS as $pair) {
                [$osn, $oid] = array_pad(explode(':', $pair, 2), 2, '');
                if ($oid !== '') $offices[$osn . '|' . $oid] = 'configured';
            }
        }
        $f = "contains(OfficeName,'Mont') or contains(OfficeName,'MONT') or contains(OfficeName,'mont')";
        foreach (ap_get_all(AP_BASE . '/Office?$filter=' . rawurlencode($f) . '&$top=200') as $o) {
            $name = (string)($o['OfficeName'] ?? '');
            $oid  = (string)($o['OfficeMlsId'] ?? '');
            // Keep the board's own casing: OData eq is case-sensitive, and
            // this value goes back into the Member filter below.
            $osn  = (string)($o['OriginatingSystemName'] ?? '');
            if ($oid === '' || !preg_match('/mont\s*haus/i', $name)) continue;
            $offices["{$osn}|{$oid}"] ??= $name;
        }
        // Known Mont Haus members, fetched by id. Each one's office is learned
        // from its own record, so a board whose office id we have never seen
        // (CREN) is still covered in full.
        $members = [];
        if (defined('ANYPROP_MH_MEMBER_IDS') && ANYPROP_MH_MEMBER_IDS) {
            $want = [];
            foreach (ANYPROP_MH_MEMBER_IDS as $pair) {
                [$osn, $mid] = array_pad(explode(':', $pair, 2), 2, '');
                if ($mid === '' || ($board && strtolower($osn) !== $board)) continue;
                $want[$osn][] = "MemberMlsId eq '" . odata_q($mid) . "'";
            }
            foreach ($want as $osn => $ors) {
                $f = "OriginatingSystemName eq '" . odata_q($osn) . "' and (" . implode(' or ', $ors) . ')';
                $got = ap_get_all(AP_BASE . '/Member?$filter=' . rawurlencode($f) . '&$top=100');
                out("  {$osn}: " . count($got) . ' of ' . count($ors) . ' known member(s) found by id');
                foreach ($got as $m) {
                    $members[] = $m;
                    $oid = (string)($m['OfficeMlsId'] ?? '');
                    $k   = ((string)($m['OriginatingSystemName'] ?? $osn)) . '|' . $oid;
                    $oname = (string)($m['OfficeName'] ?? '');
                    if ($oid === '' || isset($offices[$k])) continue;
                    // Only adopt an office that is actually Mont Haus. A known
                    // agent parked at another office on this board must not
                    // pull that whole brokerage onto our roster.
                    if (!preg_match('/mont\s*haus/i', $oname)) {
                        out("  ! {$k} (" . ($oname ?: 'no office name') . ") from " . ($m['MemberFullName'] ?? '?')
                            . ' is not a Mont Haus office: that agent is synced, the office is not pulled');
                        continue;
                    }
                    $offices[$k] = 'learned from ' . ($m['MemberFullName'] ?? $m['MemberMlsId'] ?? '?') . " ({$oname})";
                }
            }
        }

        if ($board) $offices = array_filter($offices, fn($k) => str_starts_with(strtolower($k), "{$board}|"), ARRAY_FILTER_USE_KEY);
        if (!$offices) throw new RuntimeException('no Mont Haus office found' . ($board ? " on board {$board}" : ''));
        foreach ($offices as $k => $label) out("  office {$k} — {$label}");

        foreach (array_keys($offices) as $k) {
            [$osn, $oid] = explode('|', $k, 2);
            $f = "OfficeMlsId eq '" . odata_q($oid) . "'";
            if ($osn !== '') $f = "OriginatingSystemName eq '" . odata_q($osn) . "' and {$f}";
            $got = ap_get_all(AP_BASE . '/Member?$filter=' . rawurlencode($f) . '&$top=500');
            out("  {$k}: " . count($got) . ' member record(s)');
            foreach ($got as $m) $members[] = $m;
        }
        if ($save) {
            file_put_contents($save, json_encode(['value' => $members], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            out("  raw payload saved → {$save}");
        }
    }
} catch (Throwable $e) {
    out('✗ Fetch failed: ' . $e->getMessage() . ' — nothing written.');
    exit(1);
}

// ── 2. Normalise and group by market ─────────────────────────────────────────
$by_market = [];
$skipped   = 0;
foreach ($members as $m) {
    $osn = (string)($m['OriginatingSystemName'] ?? '');
    if ($board && strtolower($osn) !== $board) continue;
    $mls_id = trim((string)($m['MemberMlsId'] ?? ''));
    if ($mls_id === '' || $osn === '') { $skipped++; continue; }
    // Unlicensed staff are not brokers the website can show.
    if (stripos((string)($m['MemberType'] ?? ''), 'unlicensed') !== false
        || stripos((string)($m['JobTitle'] ?? ''), 'unlicensed') !== false) { $skipped++; continue; }
    $first = trim((string)($m['MemberFirstName'] ?? ''));
    $last  = trim((string)($m['MemberLastName']  ?? ''));
    $by_market[mk_market_slug($osn)][$mls_id] = [
        'mls_id' => $mls_id,
        'key'    => trim((string)($m['MemberKey'] ?? '')),
        'full'   => trim((string)($m['MemberFullName'] ?? '')) ?: trim("{$first} {$last}"),
        'email'  => strtolower(trim((string)($m['MemberEmail'] ?? ''))),
        'phone'  => trim((string)($m['MemberMobilePhone'] ?? '') ?: ($m['MemberDirectPhone'] ?? '') ?: ($m['MemberPreferredPhone'] ?? '')),
        'active' => strcasecmp(trim((string)($m['MemberStatus'] ?? 'Active')), 'Active') === 0,
    ];
}
if ($skipped) out("Skipped {$skipped} record(s): unlicensed, or no MemberMlsId / board.");

// ── 3. Prepared statements ───────────────────────────────────────────────────
function prep(mysqli $conn, string $sql): mysqli_stmt {
    $s = $conn->prepare($sql);
    if (!$s) throw new RuntimeException("prepare failed: {$conn->error} — {$sql}");
    return $s;
}
$q_ident   = prep($conn, "SELECT a.intake_id, a.is_alias, mi.agent_name
                            FROM agent_mls_ids a JOIN marketing_intakes mi ON mi.id = a.intake_id
                           WHERE a.market = ? AND a.mls_agent_id = ?");
$not_team  = mk_agents_only_sql($conn);   // a team row is never an MLS person
$q_email   = prep($conn, "SELECT id, agent_name, is_active, status FROM marketing_intakes
                           WHERE (LOWER(mh_email) = ? OR LOWER(alt_email) = ? OR LOWER(mls_email) = ?){$not_team}");
$q_name    = prep($conn, "SELECT id, agent_name, is_active, status FROM marketing_intakes
                           WHERE (LOWER(TRIM(agent_name)) = ? OR LOWER(TRIM(mls_full_name)) = ?){$not_team}");
$q_roster  = prep($conn, "SELECT id FROM office_roster
                           WHERE (email <> '' AND LOWER(email) = ?) OR LOWER(TRIM(name)) = ?
                           ORDER BY (LOWER(email) = ?) DESC LIMIT 1");
$q_taken   = prep($conn, "SELECT id FROM marketing_intakes WHERE roster_id = ? LIMIT 1");
$u_mls     = prep($conn, "UPDATE marketing_intakes
                             SET mls_full_name = ?, mls_email = ?, mls_phone = ?, mls_synced_at = ?,
                                 departure_detected_at = NULL,
                                 office = COALESCE(office, ?)
                           WHERE id = ?");
$u_ident   = prep($conn, "UPDATE agent_mls_ids SET agent_key = ?, member_status = ?, last_seen_at = ?
                           WHERE market = ? AND mls_agent_id = ? AND intake_id = ?");
$i_ident   = prep($conn, "INSERT INTO agent_mls_ids (intake_id, market, mls_agent_id, agent_key, member_status, is_alias, last_seen_at)
                          VALUES (?, ?, ?, ?, ?, 0, ?)");
$i_intake  = prep($conn, "INSERT INTO marketing_intakes
                            (agent_name, slug, intake_date, status, is_active, web_status, roster_id, office,
                             mls_full_name, mls_email, mls_phone, mls_synced_at)
                          VALUES (?, ?, CURDATE(), 'active', 1, 'pending', ?, ?, ?, ?, ?, ?)");

function fetch_all_stmt(mysqli_stmt $s): array { $s->execute(); return $s->get_result()->fetch_all(MYSQLI_ASSOC); }

/**
 * Several rows for one person is common (an archived intake beside the current
 * one). When exactly one of them is live, that is the match; otherwise the
 * caller reports it as ambiguous rather than guessing.
 */
function narrow(array $hit): array {
    if (count($hit) < 2) return $hit;
    $live = array_values(array_filter($hit, fn($r) => (int)$r['is_active'] === 1 && $r['status'] !== 'archived'));
    return count($live) >= 1 ? $live : $hit;
}

$stats = ['refreshed' => 0, 'alias' => 0, 'attached' => 0, 'created' => 0, 'ambiguous' => 0,
          'identity_off' => 0, 'inactive' => 0, 'never_seen' => 0];
// For the alert email. "Back" is worked out at the end: Inactive before this
// run, Active after it (u_mls clears departure_detected_at when a row is seen).
$alert = ['new' => [], 'inactive' => [], 'back' => []];
$inactive_before = array_map('intval', array_column($conn->query(
    "SELECT id FROM marketing_intakes WHERE departure_detected_at IS NOT NULL AND is_active = 1")->fetch_all(MYSQLI_ASSOC), 'id'));

// ── 4. Per member ────────────────────────────────────────────────────────────
foreach ($by_market as $market => $list) {
    $n_active = count(array_filter($list, fn($x) => $x['active']));
    out("── {$market}: " . count($list) . " member(s), {$n_active} active");
    $office = MK_MARKET_OFFICE[$market] ?? null;

    foreach ($list as $mls_id => $m) {
        if (!$m['active']) continue;   // non-active records count as "not in the feed" below
        $status = 'Active';

        // 1. Known identity
        $q_ident->bind_param('ss', $market, $mls_id);
        $rows = fetch_all_stmt($q_ident);
        $personal = array_values(array_filter($rows, fn($r) => !(int)$r['is_alias']));
        $aliases  = array_values(array_filter($rows, fn($r) =>  (int)$r['is_alias']));

        foreach ($aliases as $r) {
            $iid = (int)$r['intake_id'];
            $stats['alias']++;
            echo "    · alias {$market}/{$mls_id} ({$m['full']}) → #{$iid} {$r['agent_name']}\n";
            if (!$dry) { $u_ident->bind_param('sssssi', $m['key'], $status, $now, $market, $mls_id, $iid); $u_ident->execute(); }
        }
        if ($personal) {
            foreach ($personal as $r) {
                $iid = (int)$r['intake_id'];
                $stats['refreshed']++;
                echo "    = {$market}/{$mls_id} {$m['full']} → #{$iid} {$r['agent_name']}\n";
                if (!$dry) {
                    $u_mls->bind_param('sssssi', $m['full'], $m['email'], $m['phone'], $now, $office, $iid); $u_mls->execute();
                    $u_ident->bind_param('sssssi', $m['key'], $status, $now, $market, $mls_id, $iid); $u_ident->execute();
                }
            }
            continue;
        }
        if ($aliases) continue;   // a team record: credited, never turned into an agent

        // 2. Email, 3. name — only a single unambiguous match attaches.
        $iid = 0; $how = '';
        if ($m['email'] !== '') {
            $q_email->bind_param('sss', $m['email'], $m['email'], $m['email']);
            $hit = narrow(fetch_all_stmt($q_email));
            if (count($hit) === 1) { $iid = (int)$hit[0]['id']; $how = 'email'; }
            elseif (count($hit) > 1) {
                $stats['ambiguous']++;
                echo "    ? {$market}/{$mls_id} {$m['full']} <{$m['email']}> matches " . count($hit)
                   . ' rows by email (#' . implode(', #', array_column($hit, 'id')) . ") — not attached, fix by hand\n";
                continue;
            }
        }
        if (!$iid && $m['full'] !== '') {
            $lname = strtolower($m['full']);
            $q_name->bind_param('ss', $lname, $lname);
            $hit = narrow(fetch_all_stmt($q_name));
            if (count($hit) === 1) { $iid = (int)$hit[0]['id']; $how = 'name'; }
            elseif (count($hit) > 1) {
                $stats['ambiguous']++;
                echo "    ? {$market}/{$mls_id} {$m['full']} matches " . count($hit)
                   . ' rows by name (#' . implode(', #', array_column($hit, 'id')) . ") — not attached, fix by hand\n";
                continue;
            }
        }

        if ($iid) {
            $stats['attached']++;
            echo "    + attach {$market}/{$mls_id} {$m['full']} → #{$iid} (by {$how})\n";
            if (!$dry) {
                $i_ident->bind_param('isssss', $iid, $market, $mls_id, $m['key'], $status, $now); $i_ident->execute();
                $u_mls->bind_param('sssssi', $m['full'], $m['email'], $m['phone'], $now, $office, $iid); $u_mls->execute();
            }
            continue;
        }

        // 4. New agent. Link the Spark-era office_roster row when there is one
        //    nobody has claimed, so index.php draws one card, not two.
        $lname = strtolower($m['full']);
        $q_roster->bind_param('sss', $m['email'], $lname, $m['email']);
        $rr = fetch_all_stmt($q_roster);
        $roster_id = $rr ? (int)$rr[0]['id'] : null;
        if ($roster_id) {
            $q_taken->bind_param('i', $roster_id);
            if (fetch_all_stmt($q_taken)) $roster_id = null;
        }
        $slug = mk_make_slug($conn, $m['full']);
        $stats['created']++;
        echo "    + NEW {$market}/{$mls_id} {$m['full']} <{$m['email']}> slug={$slug}"
           . ($roster_id ? " roster_id={$roster_id}" : '') . "\n";
        if (!$dry) {
            $conn->begin_transaction();
            $i_intake->bind_param('ssisssss', $m['full'], $slug, $roster_id, $office, $m['full'], $m['email'], $m['phone'], $now);
            $i_intake->execute();
            $new = (int)$conn->insert_id;
            $i_ident->bind_param('isssss', $new, $market, $mls_id, $m['key'], $status, $now);
            $i_ident->execute();
            $conn->commit();
            foreach (mk_onboard($conn, $new) as $line) echo "        {$line}\n";   // the marketing checklist
            $alert['new'][] = $new;
        }
    }

    // ── Departures for this market ───────────────────────────────────────────
    $seen = array_keys(array_filter($list, fn($x) => $x['active']));
    if (!$seen) { out("   {$market}: no active members returned — departures skipped (never deactivate on an empty feed)."); continue; }
    $in    = implode(',', array_fill(0, count($seen), '?'));
    $s     = prep($conn, "SELECT a.id, a.intake_id, a.mls_agent_id, a.last_seen_at, mi.agent_name
                            FROM agent_mls_ids a JOIN marketing_intakes mi ON mi.id = a.intake_id
                           WHERE a.market = ? AND a.member_status = 'Active' AND a.mls_agent_id NOT IN ({$in})");
    $bind  = array_merge([$market], array_map('strval', $seen));
    $s->bind_param(str_repeat('s', count($bind)), ...$bind);
    $gone  = fetch_all_stmt($s); $s->close();

    foreach ($gone as $g) {
        if ($g['last_seen_at'] === null) {
            $stats['never_seen']++;
            echo "    ~ {$market}/{$g['mls_agent_id']} (#{$g['intake_id']} {$g['agent_name']}) has never been returned by Anyprop — left alone\n";
            continue;
        }
        $stats['identity_off']++;
        echo "    − {$market}/{$g['mls_agent_id']} left the feed (#{$g['intake_id']} {$g['agent_name']})\n";
        if ($dry) continue;
        $conn->query("UPDATE agent_mls_ids SET member_status = 'Inactive' WHERE id = " . (int)$g['id']);
        $left = (int)$conn->query("SELECT COUNT(*) FROM agent_mls_ids WHERE intake_id = " . (int)$g['intake_id']
                                 . " AND member_status = 'Active' AND is_alias = 0")->fetch_row()[0];
        if ($left === 0) {
            // Inactive tab only. Website, Hot Sheets and FUB stay as they are.
            $d = prep($conn, "UPDATE marketing_intakes SET departure_detected_at = ?
                               WHERE id = ? AND departure_detected_at IS NULL AND is_active = 1 AND status <> 'archived'");
            $gid = (int)$g['intake_id'];
            $d->bind_param('si', $now, $gid); $d->execute();
            if ($d->affected_rows) {
                $stats['inactive']++; $alert['inactive'][] = $gid;
                echo "    ✗ #{$gid} {$g['agent_name']}: no active MLS identity left, now Inactive (website and subscriptions left on)\n";
            }
            $d->close();
        }
    }
}

// ── 5. Who came back ─────────────────────────────────────────────────────────
if (!$dry && $inactive_before) {
    $still = array_map('intval', array_column($conn->query(
        "SELECT id FROM marketing_intakes WHERE departure_detected_at IS NOT NULL")->fetch_all(MYSQLI_ASSOC), 'id'));
    $alert['back'] = array_values(array_diff($inactive_before, $still));
    foreach ($alert['back'] as $b) echo "    ↺ #{$b} is back in the MLS under Mont Haus: Active again\n";
}

// ── 6. Slugs for every named row still without one ───────────────────────────
$slugs = mk_assign_missing_slugs($conn, $dry);
if ($slugs) out('Slugs assigned to ' . count($slugs) . ' existing row(s)' . ($dry ? ' (dry run: not saved)' : '') . '.');

out(($dry ? '✓ DRY RUN done: ' : '✓ Done: ')
    . "{$stats['refreshed']} refreshed, {$stats['attached']} attached, {$stats['created']} created, "
    . "{$stats['alias']} alias heartbeats, {$stats['ambiguous']} ambiguous (skipped), "
    . "{$stats['identity_off']} identities off-feed, {$stats['inactive']} now Inactive, "
    . "{$stats['never_seen']} seeded identities not yet seen.");
foreach (mk_roster_alert($conn, $alert, $dry) as $line) out($line);
$conn->close();
