<?php
/**
 * anyprop_debug.php — look at the raw Anyprop Member / Office feed (2026-10-02).
 *
 * For checking what a board actually sends about a person or an office, for
 * example when an agent's identity has not appeared on a board that is live.
 * Admin only. Reads Anyprop with the same credentials as the roster sync and
 * writes nothing: no database, no cache.
 *
 * Member searches: name (contains, on MemberFullName), email (exact), MLS id
 * (exact), office MLS id (exact). Office searches: name (contains), office
 * MLS id (exact). Anyprop's $filter whitelist is in its docs
 * (docs.anyprop.com/api-documentation/v1-listings-api.md): MemberLastName is
 * NOT filterable, which is why name search is on the full name. contains()
 * is case-sensitive there, so a name search tries the spelling as typed,
 * lower case, UPPER CASE and Title Case together.
 *
 * "Mont Haus office" (2026-10-04): no search text needed. Finds every office
 * whose name contains Mont Haus on the chosen board (or all boards), the same
 * search the roster sync runs, then lists everyone in those offices: the
 * question to ask when an agent is missing from a board.
 *
 * Lists (Nikki, 2026-11-16: "enter different parameters, specific board,
 * specific company, and see the raw output"): Members can also be searched by
 * company (contains on OfficeName, the four spellings) or as everyone on one
 * board, narrowed by Status (Anyprop filters it) and Member type (Anyprop
 * cannot filter MemberType, so the page keeps the matching rows after the
 * fetch and shows each type with its count to pick from). Up to 5,000 rows,
 * following @odata.nextLink 500 at a time. "Download CSV" (Board, First Name,
 * Last Name, Company, Email, Status, Member Type, MLS ID, Office MLS ID) and
 * "Download JSON" re-run the same search and return every row; the page shows
 * the first 200 records of the raw JSON.
 *
 * A readable table (Nikki, 2026-11-16: "quite useful and could be for the
 * other admins as well"): real headers instead of the feed's field names
 * (apd_catalog()), a click on a header sorts, and a Columns menu shows or
 * hides any field the result carries (the choice is remembered per browser,
 * per Members / Offices). The rows travel as one JSON block and the table is
 * drawn in the browser, so sorting and the column choice need no round trip.
 * Download CSV carries the columns showing (?cols=). The raw JSON and the
 * request sit in a section that is closed until it is opened, for debugging.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/boards.php';
require_login();
require_role('admin');

const APD_MAX_ROWS = 5000;   // one board's whole membership fits (Aspen: 1,710)
const APD_JSON_ROWS = 200;    // raw JSON records drawn on the page

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function odata_q(string $s): string { return str_replace("'", "''", $s); }

/** Bearer token for Anyprop (30-day token, fetched fresh for each page view). */
function apd_token(): string {
    if (!defined('ANYPROP_USERNAME') || ANYPROP_USERNAME === '' || !defined('ANYPROP_PASSWORD')) {
        throw new RuntimeException('ANYPROP_USERNAME / ANYPROP_PASSWORD are not defined in inc/db.php');
    }
    $ch = curl_init('https://api.anyprop.com/v1/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['username' => ANYPROP_USERNAME, 'password' => ANYPROP_PASSWORD]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    if ($err) throw new RuntimeException("token request failed: {$err}");
    $d = json_decode((string)$raw, true);
    if ($code !== 200 || empty($d['access_token'])) throw new RuntimeException("token refused (HTTP {$code})");
    return $d['access_token'];
}

/** One GET; returns [http code, decoded body or null, raw body]. */
function apd_get(string $url): array {
    static $tok = null;
    $tok ??= apd_token();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tok, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_ENCODING       => '',
    ]);
    $raw = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    if ($err) return [0, null, "cURL: {$err}"];
    return [$code, json_decode($raw, true), $raw];
}

/**
 * Every row of a search, following @odata.nextLink, up to $max.
 * Returns [rows, pages fetched, error text or ''].
 */
function apd_get_all(string $url, int $max): array {
    $rows = []; $pages = 0;
    while ($url !== '' && count($rows) < $max) {
        [$code, $data, $raw] = apd_get($url);
        $pages++;
        if ($code !== 200) return [$rows, $pages, "Anyprop answered HTTP {$code} on page {$pages}: " . mb_substr($raw, 0, 600)];
        foreach ($data['value'] ?? [] as $r) { $rows[] = $r; if (count($rows) >= $max) break; }
        $url = (string)($data['@odata.nextLink'] ?? '');
    }
    return [$rows, $pages, ''];
}

/** The four spellings contains() needs, since Anyprop compares case-sensitively. */
function apd_contains(string $field, string $q): string {
    return '(' . implode(' or ', array_unique(array_map(fn($s) => "contains({$field},'" . odata_q($s) . "')",
        [$q, strtolower($q), strtoupper($q), ucwords(strtolower($q))]))) . ')';
}

/** Fields never offered as a column: credentials, nested or bulky values (the raw JSON has them), and the second address line (shown with the first). */
const APD_SKIP = ['@odata.id', 'MemberPassword', 'MemberLoginId', 'RawMlsAdditionalData', 'Media', 'HistoryTransactional',
                  'Office', 'MainOffice', 'OfficeBroker', 'OfficeManager', 'MemberAddress2', 'OfficeAddress2', 'SocialMediaType'];

/**
 * The columns with a name of our own, in the order they are shown:
 * feed field => [header, shown by default, group]. Groups: 'main', and 'ids'
 * (the keys and system ids only a debugging session wants). The default ones
 * are the columns of the CSV Nikki asked for on 2026-11-16, plus Updated.
 * A field that is not here is still offered, under its feed name spelled out
 * (apd_auto_label()).
 */
function apd_catalog(string $resource): array {
    if ($resource === 'Member') return [
        'OriginatingSystemName'       => ['Board', true, 'main'],
        'MemberFirstName'             => ['First Name', true, 'main'],
        'MemberLastName'              => ['Last Name', true, 'main'],
        'OfficeName'                  => ['Company', true, 'main'],
        'MemberEmail'                 => ['Email', true, 'main'],
        'MemberStatus'                => ['Status', true, 'main'],
        'MemberType'                  => ['Member Type', true, 'main'],
        'MemberMlsId'                 => ['MLS ID', true, 'main'],
        'OfficeMlsId'                 => ['Office MLS ID', true, 'main'],
        'ModificationTimestamp'       => ['Updated', true, 'main'],
        'MemberFullName'              => ['Full Name', false, 'main'],
        'MemberMiddleName'            => ['Middle Name', false, 'main'],
        'MemberNickname'              => ['Nickname', false, 'main'],
        'MemberMobilePhone'           => ['Mobile Phone', false, 'main'],
        'MemberDirectPhone'           => ['Direct Phone', false, 'main'],
        'MemberPreferredPhone'        => ['Preferred Phone', false, 'main'],
        'MemberPreferredPhoneExt'     => ['Preferred Phone Ext', false, 'main'],
        'MemberOfficePhone'           => ['Office Phone', false, 'main'],
        'MemberOfficePhoneExt'        => ['Office Phone Ext', false, 'main'],
        'MemberHomePhone'             => ['Home Phone', false, 'main'],
        'MemberFax'                   => ['Fax', false, 'main'],
        'MemberStateLicense'          => ['License Number', false, 'main'],
        'MemberDesignation'           => ['Designations', false, 'main'],
        'MemberLanguages'             => ['Languages', false, 'main'],
        'MemberAddress1'              => ['Address', false, 'main'],
        'MemberCity'                  => ['City', false, 'main'],
        'MemberStateOrProvince'       => ['State', false, 'main'],
        'MemberPostalCode'            => ['ZIP', false, 'main'],
        'MemberAOR'                   => ['Association', false, 'main'],
        'MemberNationalAssociationId' => ['NRDS ID', false, 'main'],
        'MemberMlsAccessYN'           => ['MLS Access', false, 'main'],
        'MemberMlsSecurityClass'      => ['MLS Security Class', false, 'main'],
        'MemberBio'                   => ['Bio', false, 'main'],
        'OriginalEntryTimestamp'      => ['First Added', false, 'main'],
        'SourceSystemName'            => ['Source System', false, 'main'],
        'MemberKey'                   => ['Member Key', false, 'ids'],
        'OfficeKey'                   => ['Office Key', false, 'ids'],
        'MemberAORkey'                => ['Association Key', false, 'ids'],
        'MemberAORMlsId'              => ['Association MLS ID', false, 'ids'],
        'OriginatingSystemID'         => ['Originating System ID', false, 'ids'],
        'OriginatingSystemMemberKey'  => ['Originating System Member Key', false, 'ids'],
        'SourceSystemID'              => ['Source System ID', false, 'ids'],
        'SourceSystemMemberKey'       => ['Source System Member Key', false, 'ids'],
        'anyprop_Key'                 => ['Anyprop Key', false, 'ids'],
        'anyprop_OriginatingSystemID' => ['Anyprop Originating System ID', false, 'ids'],
    ];
    return [
        'OriginatingSystemName'       => ['Board', true, 'main'],
        'OfficeName'                  => ['Office Name', true, 'main'],
        'OfficeMlsId'                 => ['Office MLS ID', true, 'main'],
        'OfficeStatus'                => ['Status', true, 'main'],
        'OfficeEmail'                 => ['Email', true, 'main'],
        'OfficePhone'                 => ['Phone', true, 'main'],
        'ModificationTimestamp'       => ['Updated', true, 'main'],
        'OfficeAddress1'              => ['Address', false, 'main'],
        'OfficeCity'                  => ['City', false, 'main'],
        'OfficeStateOrProvince'       => ['State', false, 'main'],
        'OfficePostalCode'            => ['ZIP', false, 'main'],
        'OfficeMailAddress1'          => ['Mailing Address', false, 'main'],
        'OfficeMailCareOf'            => ['Mailing Care Of', false, 'main'],
        'OfficeMailCity'              => ['Mailing City', false, 'main'],
        'OfficeMailStateOrProvince'   => ['Mailing State', false, 'main'],
        'OfficeMailPostalCode'        => ['Mailing ZIP', false, 'main'],
        'OfficeFax'                   => ['Fax', false, 'main'],
        'OfficeType'                  => ['Office Type', false, 'main'],
        'MainOfficeMlsId'             => ['Main Office MLS ID', false, 'main'],
        'OfficeBrokerMlsId'           => ['Broker MLS ID', false, 'main'],
        'OfficeCorporateLicense'      => ['Corporate License', false, 'main'],
        'IDXOfficeParticipationYN'    => ['IDX Participation', false, 'main'],
        'OfficeAOR'                   => ['Association', false, 'main'],
        'OriginalEntryTimestamp'      => ['First Added', false, 'main'],
        'SourceSystemName'            => ['Source System', false, 'main'],
        'OfficeKey'                   => ['Office Key', false, 'ids'],
        'MainOfficeKey'               => ['Main Office Key', false, 'ids'],
        'OfficeBrokerKey'             => ['Broker Key', false, 'ids'],
        'OfficeAORkey'                => ['Association Key', false, 'ids'],
        'OfficeAORMlsId'              => ['Association MLS ID', false, 'ids'],
        'OriginatingSystemID'         => ['Originating System ID', false, 'ids'],
        'OriginatingSystemOfficeKey'  => ['Originating System Office Key', false, 'ids'],
        'SourceSystemID'              => ['Source System ID', false, 'ids'],
        'SourceSystemOfficeKey'       => ['Source System Office Key', false, 'ids'],
        'anyprop_Key'                 => ['Anyprop Key', false, 'ids'],
        'anyprop_OriginatingSystemID' => ['Anyprop Originating System ID', false, 'ids'],
    ];
}

/** A header for a feed field we have no name for: "MemberCountyOrParish" reads "Member County Or Parish". */
function apd_auto_label(string $k): string {
    $w = preg_replace('/^anyprop_/', 'Anyprop ', $k);
    $w = preg_replace('/YN$/', '', $w);
    $w = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $w);
    return trim(preg_replace(['/\bMls\b/', '/\bId\b/', '/\bAor\b/', '/\bIdx\b/'], ['MLS', 'ID', 'AOR', 'IDX'], $w));
}

/** What kind of value a field holds: 'date' (shown in Mountain time, sorted by the moment) or 'text'. */
function apd_kind(string $k): string {
    return preg_match('/(Timestamp|Date)$/', $k) ? 'date' : 'text';
}

/** One field of one record as text: the board by its name, Yes / No, a list joined, the two address lines together. */
function apd_cell(array $r, string $k): string {
    $v = $r[$k] ?? null;
    if ($k === 'OriginatingSystemName') return mk_board_label(mk_market_slug((string)$v));
    if ($k === 'MemberAddress1' || $k === 'OfficeAddress1') {
        return trim(trim((string)$v) . ' ' . trim((string)($r[substr($k, 0, -1) . '2'] ?? '')));
    }
    // CREN sends no MemberFullName: first + last, so the column is never blank for a person
    if ($k === 'MemberFullName' && trim((string)$v) === '') return trim(($r['MemberFirstName'] ?? '') . ' ' . ($r['MemberLastName'] ?? ''));
    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (is_array($v)) return implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x), $v));
    return trim((string)$v);
}

/**
 * The columns a result can show, in order: the named ones (a default one
 * always; another only when some row has a value for it), then every other
 * field with a value, under its spelled-out feed name.
 * Returns [field => ['label', 'on' (shown by default), 'group', 'kind']].
 */
function apd_columns(string $resource, array $rows): array {
    $has = [];
    foreach ($rows as $r) foreach ($r as $k => $v) {
        if (isset($has[$k]) || $v === null || $v === '' || $v === []) continue;
        if (is_array($v)) { foreach ($v as $x) if (!is_scalar($x)) continue 2; }   // a list of plain values only
        $has[$k] = true;
    }
    $cols = [];
    foreach (apd_catalog($resource) as $k => [$label, $on, $group]) {
        if ($on || isset($has[$k])) $cols[$k] = ['label' => $label, 'on' => $on, 'group' => $group, 'kind' => apd_kind($k)];
    }
    $rest = array_diff(array_keys($has), array_keys($cols), APD_SKIP);
    sort($rest);
    foreach ($rest as $k) $cols[$k] = ['label' => apd_auto_label($k), 'on' => false, 'group' => 'other', 'kind' => apd_kind($k)];
    return $cols;
}

// ── Inputs ───────────────────────────────────────────────────────────────────
$resource = ($_GET['resource'] ?? 'Member') === 'Office' ? 'Office' : 'Member';
$board    = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_GET['board'] ?? '')));
$q        = trim((string)($_GET['q'] ?? ''));
$by       = (string)($_GET['by'] ?? 'name');
$top      = max(1, min(APD_MAX_ROWS, (int)($_GET['top'] ?? 500)));
$status   = in_array($_GET['status'] ?? '', ['Active', 'Inactive'], true) ? $_GET['status'] : '';
$mtype    = $resource === 'Member' ? trim((string)($_GET['type'] ?? '')) : '';
$download = in_array($_GET['download'] ?? '', ['csv', 'json'], true) ? $_GET['download'] : '';
// The columns the CSV should carry: the ones showing in the table when Download CSV was pressed.
$cols_req = array_values(array_filter(explode(',', (string)($_GET['cols'] ?? '')), fn($k) => (bool)preg_match('/^[A-Za-z0-9_]{1,60}$/', $k)));
$allowed_by = $resource === 'Member' ? ['mhoffice', 'all', 'name', 'company', 'email', 'mlsid', 'office'] : ['all', 'name', 'mlsid'];
if (!in_array($by, $allowed_by, true)) $by = 'name';
$mh_mode  = $resource === 'Member' && $by === 'mhoffice';   // every member of the Mont Haus office(s)
$all_mode = $by === 'all';                                   // everyone on one board
$searched = isset($_GET['by']) && ($mh_mode || $all_mode || $q !== '');
$mh_offices = [];

$boards = mk_board_registry();   // slug => entry; the OSN is what the API filters on
$osn_of = [];
foreach ($boards as $slug => $b) $osn_of[$slug] = $b['osn'];
$status_clause = $status !== '' ? ($resource === 'Member' ? 'MemberStatus' : 'OfficeStatus') . " eq '" . odata_q($status) . "'" : '';

$fetched = null; $error = ''; $url = ''; $filter = ''; $pages = 0;
if ($searched && $mh_mode) {
    // Same office discovery as cron/sync_anyprop_roster.php: contains() is
    // case-sensitive, so ask for three spellings and keep real Mont Haus names.
    $base = 'https://api.anyprop.com/v1/listings/data/';
    $osn_clause = ($board !== '' && isset($osn_of[$board])) ? "OriginatingSystemName eq '" . odata_q($osn_of[$board]) . "' and " : '';
    $of = $osn_clause . "(contains(OfficeName,'Mont') or contains(OfficeName,'MONT') or contains(OfficeName,'mont'))";
    try {
        [$code, $data, $raw] = apd_get($base . 'Office?$filter=' . rawurlencode($of) . '&$top=200');
        if ($code !== 200) throw new RuntimeException("Anyprop answered HTTP {$code}: " . mb_substr($raw, 0, 400));
        foreach ($data['value'] ?? [] as $o) {
            if (preg_match('/mont\s*haus/i', (string)($o['OfficeName'] ?? '')) && (string)($o['OfficeMlsId'] ?? '') !== '') $mh_offices[] = $o;
        }
        $members = [];
        foreach ($mh_offices as $o) {
            $mf = "OriginatingSystemName eq '" . odata_q((string)$o['OriginatingSystemName']) . "' and OfficeMlsId eq '" . odata_q((string)$o['OfficeMlsId']) . "'"
                . ($status_clause !== '' ? " and {$status_clause}" : '');
            [$got, $p, $err] = apd_get_all($base . 'Member?$filter=' . rawurlencode($mf) . '&$top=500', APD_MAX_ROWS);
            $pages += $p;
            if ($err !== '') throw new RuntimeException("Office {$o['OfficeMlsId']}: {$err}");
            foreach ($got as $m) $members[] = $m;
        }
        $url    = $base . 'Office?$filter=' . rawurlencode($of) . '  then  Member?$filter=OriginatingSystemName eq <board> and OfficeMlsId eq <office>'
                . ($status_clause !== '' ? " and {$status_clause}" : '') . ' for each office found';
        $filter = $of;
        $fetched = $members;
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
} elseif ($searched) {
    $parts = [];
    if ($board !== '' && isset($osn_of[$board])) $parts[] = "OriginatingSystemName eq '" . odata_q($osn_of[$board]) . "'";
    $v = odata_q($q);
    if ($all_mode) {
        // A whole board only: across all boards it would be tens of thousands of rows.
        if ($parts === []) $error = 'Pick a board for "Everyone on the board".';
    } elseif ($resource === 'Member') {
        $parts[] = match ($by) {
            'email'   => "MemberEmail eq '" . odata_q(strtolower($q)) . "'",
            'mlsid'   => "MemberMlsId eq '{$v}'",
            'office'  => "OfficeMlsId eq '{$v}'",
            'company' => apd_contains('OfficeName', $q),
            default   => apd_contains('MemberFullName', $q),
        };
    } else {
        $parts[] = match ($by) {
            'mlsid'  => "OfficeMlsId eq '{$v}'",
            default  => apd_contains('OfficeName', $q),
        };
    }
    if ($status_clause !== '') $parts[] = $status_clause;
    if ($error === '') {
        $filter = implode(' and ', $parts);
        // With a Member type picked, fetch everything (up to the cap) and keep
        // that type: Anyprop cannot filter MemberType, and a $top cut taken
        // before the type filter would hide rows.
        $want = $mtype !== '' || $download !== '' ? APD_MAX_ROWS : $top;
        $url  = 'https://api.anyprop.com/v1/listings/data/' . $resource . '?$filter=' . rawurlencode($filter) . '&$top=' . min(500, $want);
        try {
            [$fetched, $pages, $error] = apd_get_all($url, $want);
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
}

// Member types in what came back (before the type pick), most common first.
$type_counts = [];
foreach ($fetched ?? [] as $r) {
    $t = trim((string)($r['MemberType'] ?? ''));
    $type_counts[$t] = ($type_counts[$t] ?? 0) + 1;
}
arsort($type_counts);
$rows = $fetched ?? [];
if ($mtype !== '') {
    $want_type = $mtype === '(none)' ? '' : $mtype;
    $rows = array_values(array_filter($rows, fn($r) => trim((string)($r['MemberType'] ?? '')) === $want_type));
}
$hit_cap = $fetched !== null && count($fetched) >= ($mtype !== '' || $download !== '' ? APD_MAX_ROWS : $top);
if ($mtype === '' && $download === '') $rows = array_slice($rows, 0, $top);

// ── Downloads: the same search, every row ────────────────────────────────────
if ($download !== '' && $fetched !== null && $error === '') {
    $conn->close();
    $name = 'anyprop-' . strtolower($resource) . 's-' . ($board !== '' ? $board : 'all-boards') . '-' . date('Y-m-d');
    if ($download === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '.json"');
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM, so Excel reads accented names as UTF-8
    // The columns showing in the table (?cols=), in the table's order; without that, the default ones.
    $cols = apd_columns($resource, $rows);
    $use  = array_filter($cols, fn($c, $k) => $cols_req ? in_array($k, $cols_req, true) : $c['on'], ARRAY_FILTER_USE_BOTH);
    if (!$use) $use = array_filter($cols, fn($c) => $c['on']);
    $mt = new DateTimeZone('America/Denver');
    fputcsv($out, array_column($use, 'label'), ',', '"', '');
    foreach ($rows as $r) {
        $line = [];
        foreach ($use as $k => $c) {
            $v = apd_cell($r, $k);
            if ($c['kind'] === 'date' && $v !== '') {   // Mountain time, as the table shows it
                try { $v = (new DateTime($v))->setTimezone($mt)->format('Y-m-d H:i'); } catch (Throwable $ex) { /* left as the feed sent it */ }
            }
            $line[] = $v;
        }
        fputcsv($out, $line, ',', '"', '');
    }
    exit;
}

// The page shows the first APD_JSON_ROWS records of the raw JSON; the
// download has them all.
$shown = array_slice($rows, 0, APD_JSON_ROWS);
$pretty = $fetched !== null
    ? json_encode($mh_mode ? ['mont_haus_offices' => $mh_offices, 'value' => $shown] : ['value' => $shown],
                  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    : '';
// The table's rows, drawn in the browser: one list of columns, then each record as its values in that order.
$cols = $fetched !== null ? apd_columns($resource, $rows) : [];
$table = ['resource' => $resource, 'cols' => [], 'rows' => []];
foreach ($cols as $k => $c) $table['cols'][] = ['k' => $k, 'label' => $c['label'], 'on' => $c['on'], 'group' => $c['group'], 'kind' => $c['kind']];
foreach ($rows as $r) {
    $line = [];
    foreach ($cols as $k => $c) $line[] = apd_cell($r, $k);
    $table['rows'][] = $line;
}
/** This page's URL with some parameters changed (null drops one). */
function apd_link(array $change): string {
    $p = array_merge($_GET, $change);
    foreach ($p as $k => $v) if ($v === null) unset($p[$k]);
    return 'anyprop_debug.php?' . http_build_query($p);
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Anyprop Feed | Mont Haus Marketing</title>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <style>
    .layout-extended .pc-container { margin-top:70px; padding-top:24px; top:0; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
    .mh-header-inner { width:100%; padding:0 15px; display:flex; align-items:center; justify-content:space-between; height:70px; }
    .mh-logo img { height:50px; display:block; }
    .mh-nav { display:flex; align-items:center; gap:4px; }
    .mh-nav-link { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; font-size:.8rem; font-weight:500;
                   color:#eef1f5; text-decoration:none; border-radius:6px; white-space:nowrap; transition:color .2s, background .2s; }
    .mh-nav-link:hover  { color:#fff; background:rgba(174,187,204,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(174,187,204,.45); }
    .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }
    .wrap { max-width:1280px; margin:32px auto 60px; padding:0 20px; }
    .mk-page-header { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .card { background:#fff; border-radius:6px; padding:22px 26px; margin-bottom:18px; }
    .card-title { font-size:14px; font-weight:700; margin:0 0 16px; display:flex; align-items:center; gap:8px; }
    .form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px; }
    .form-row > div { min-width:0; }
    .form-input, select.form-input { padding:8px 10px; border:1px solid #d1d5db; border-radius:4px; font-size:14px; width:100%; }
    .fld-label { display:block; font-size:12px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; margin-bottom:4px; }
    .seg { display:inline-flex; border:1px solid #d1d5db; border-radius:4px; overflow:hidden; }
    .seg label { padding:8px 14px; font-size:13px; cursor:pointer; background:#fff; color:#374151; }
    .seg label.on { background:#1a1a1a; color:#fff; }
    .seg input { display:none; }
    .hint { font-size:12px; color:#9ca3af; }
    .flash { padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:14px; }
    .flash.err { background:#fef2f2; color:#b91c1c; }
    .flash.ok  { background:#f0fdf4; color:#15803d; }
    .q { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12px; color:#374151; word-break:break-all; background:#f5f3ee; padding:8px 10px; border-radius:4px; }
    table.sum { width:100%; border-collapse:collapse; font-size:13px; margin-top:6px; }
    table.sum th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.3px; color:#6b7280; padding:6px 8px; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
    table.sum td { padding:6px 8px; border-bottom:1px solid #eceff3; vertical-align:top; }
    table.sum td.mh { font-weight:600; color:#15803d; }
    .tbl-scroll { overflow:auto; }
    /* The results table: drawn by the script at the foot of the page. */
    #apdTable { max-height:72vh; border:1px solid #eceff3; border-radius:6px; }
    #apdTable table.sum { margin:0; }
    #apdTable th { position:sticky; top:0; z-index:1; background:#f6f7f9; padding:0; border-bottom:1px solid #dfe3e8; }
    #apdTable th button { all:unset; box-sizing:border-box; display:flex; align-items:center; gap:4px; width:100%; padding:8px 10px; cursor:pointer;
                          font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:#4b5563; white-space:nowrap; }
    #apdTable th button:hover { color:#111; }
    #apdTable th button:focus-visible { outline:2px solid #4a5569; outline-offset:-2px; }
    #apdTable th .ti { font-size:12px; color:#9ca3af; }
    #apdTable th.sorted button, #apdTable th.sorted .ti { color:#111; }
    #apdTable td { padding:7px 10px; white-space:nowrap; max-width:340px; overflow:hidden; text-overflow:ellipsis; }
    #apdTable tbody tr:hover td { background:#fafbfc; }
    #apdTable td.dim { color:#c5cad3; }
    .st { display:inline-block; border-radius:999px; padding:1px 9px; font-size:12px; font-weight:600; background:#eceff3; color:#4b5563; }
    .st.active { background:#e8f6ee; color:#15803d; }
    .st.inactive { background:#fdecec; color:#b42318; }
    /* The Columns menu. */
    .colpick { position:relative; }
    .colpanel { position:absolute; right:0; top:calc(100% + 6px); z-index:20; width:min(560px, 86vw); max-height:60vh; overflow:auto; background:#fff;
                border:1px solid #d1d5db; border-radius:8px; box-shadow:0 12px 32px rgba(0,0,0,.14); padding:14px 16px; }
    .colpanel h4 { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#6b7280; margin:12px 0 6px; }
    .colpanel h4:first-of-type { margin-top:0; }
    .colgrid { display:grid; grid-template-columns:repeat(auto-fill, minmax(165px, 1fr)); gap:2px 12px; }
    .colgrid label { display:flex; align-items:center; gap:7px; font-size:13px; color:#1f242c; padding:3px 0; cursor:pointer; margin:0; }
    .colhead { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; }
    .colhead span { font-size:12px; color:#6b7280; }
    .colhead button { all:unset; cursor:pointer; font-size:12px; font-weight:600; color:#4a5569; text-decoration:underline; }
    /* The debugging section: closed until it is opened. */
    details.dbg { background:#fff; border-radius:6px; margin-bottom:18px; }
    details.dbg > summary { cursor:pointer; padding:16px 26px; font-size:14px; font-weight:700; color:#374151; list-style:none; display:flex; align-items:center; gap:8px; }
    details.dbg > summary::-webkit-details-marker { display:none; }
    details.dbg > summary .hint { font-weight:400; }
    details.dbg > summary .chev { margin-left:auto; color:#9ca3af; transition:transform .15s; }
    details.dbg[open] > summary .chev { transform:rotate(180deg); }
    .dbg-body { padding:0 26px 22px; }
    .dbg-body h3 { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:#6b7280; margin:16px 0 8px; }
    .dbg-body h3:first-child { margin-top:0; }
    pre.json { background:#1a1a1a; color:#e5e7eb; font-size:12px; line-height:1.45; padding:16px; border-radius:6px; overflow:auto; max-height:70vh; margin:0; }
    pre.json .k { color:#aebbcc; } pre.json .s { color:#f5e6a8; } pre.json .n { color:#c4b5fd; }
    .pre-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
    .btn-copy { border:1px solid #d1d5db; background:#fff; border-radius:4px; padding:5px 10px; font-size:12px; cursor:pointer; color:#374151; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
    .types { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:12px 0 4px; }
    .chip { border:1px solid #d1d5db; border-radius:999px; padding:3px 10px; font-size:12px; color:#374151; text-decoration:none; background:#fff; white-space:nowrap; }
    .chip span { color:#9ca3af; margin-left:2px; }
    .chip.on { background:#1a1a1a; border-color:#1a1a1a; color:#fff; }
    .chip.on span { color:#d1d5db; }
    @media (max-width:700px) { .form-row > div { flex:1 1 100%; } }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $nav_active = 'roster'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

    <div class="mk-page-header">
      <div>
        <h1>Anyprop Feed</h1>
        <p class="hint" style="margin:4px 0 0;">What the MLS feed says about a member or an office. Reads only: nothing here changes the roster.</p>
      </div>
      <a class="btn btn-outline btn-sm" href="agents.php"><i class="ti ti-arrow-left"></i> Roster</a>
    </div>

    <div class="card">
      <form method="GET" action="anyprop_debug.php">
        <div class="form-row">
          <div>
            <span class="fld-label">Look up</span>
            <div class="seg">
              <label class="<?= $resource === 'Member' ? 'on' : '' ?>"><input type="radio" name="resource" value="Member" <?= $resource === 'Member' ? 'checked' : '' ?>>Members</label>
              <label class="<?= $resource === 'Office' ? 'on' : '' ?>"><input type="radio" name="resource" value="Office" <?= $resource === 'Office' ? 'checked' : '' ?>>Offices</label>
            </div>
          </div>
          <div style="flex:0 0 180px;">
            <label class="fld-label" for="board">Board</label>
            <select class="form-input" name="board" id="board">
              <option value="">All boards</option>
              <?php foreach ($boards as $slug => $b): ?>
                <option value="<?= e($slug) ?>" <?= $slug === $board ? 'selected' : '' ?>><?= e($b['label']) ?> (<?= e($b['osn']) ?>)<?= $b['status'] !== 'live' ? ' · ' . e($b['status']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:0 0 200px;">
            <label class="fld-label" for="by">Search by</label>
            <select class="form-input" name="by" id="by">
              <?php $labels = ['mhoffice' => 'Mont Haus office (everyone)', 'all' => $resource === 'Member' ? 'Everyone on the board' : 'Every office on the board',
                               'name' => 'Name (contains)', 'company' => 'Company name (contains)', 'email' => 'Email (exact)', 'mlsid' => 'MLS ID (exact)', 'office' => 'Office MLS ID (exact)'];
                    foreach ($allowed_by as $k): ?>
                <option value="<?= $k ?>" <?= $k === $by ? 'selected' : '' ?>><?= $labels[$k] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1 1 220px;">
            <label class="fld-label" for="q">Search for</label>
            <input class="form-input" type="text" name="q" id="q" value="<?= e($q) ?>" placeholder="<?= $mh_mode || $all_mode ? 'not needed for this search' : ($by === 'company' ? 'Sopris Realty' : ($resource === 'Member' ? 'Boxer, jonathan.boxer@monthaus.com, 1513…' : 'Mont Haus, 805522330…')) ?>" autofocus>
          </div>
          <div style="flex:0 0 110px;">
            <label class="fld-label" for="status">Status</label>
            <select class="form-input" name="status" id="status">
              <?php foreach (['' => 'Any', 'Active' => 'Active', 'Inactive' => 'Inactive'] as $k => $l): ?>
                <option value="<?= $k ?>" <?= $k === $status ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:0 0 100px;">
            <label class="fld-label" for="top">Max rows</label>
            <input class="form-input" type="number" name="top" id="top" value="<?= $top ?>" min="1" max="<?= APD_MAX_ROWS ?>">
          </div>
          <div>
            <button type="submit" class="btn btn-primary"><i class="ti ti-search"></i> Search</button>
          </div>
        </div>
        <p class="hint" style="margin:0;">
          <strong>Mont Haus office</strong> lists everyone Anyprop has in the Mont Haus office(s) of the chosen board, or of every board: no search text needed.
          Members: name searches the full name as the board spells it (tries the typed spelling, lower, UPPER and Title Case); email and IDs are exact.
          Offices: name (contains) or office MLS ID. Anyprop cannot filter on last name alone.
          <strong>Everyone on the board</strong> needs a board. <strong>Company name</strong> matches the office name as the board spells it (Sopris finds Sopris Realty LLC).
          After a search, pick a member type under the count (Anyprop cannot filter on it, so the page does). Click a column heading to sort; Columns chooses what the table shows.
          Download CSV gives every row, up to <?= number_format(APD_MAX_ROWS) ?>, with the columns showing.
        </p>
      </form>
    </div>

    <?php if ($searched): ?>
      <?php if ($mh_mode && $error === ''): ?>
      <div class="card">
        <div class="card-title"><i class="ti ti-building"></i> <?= count($mh_offices) ?> Mont Haus office<?= count($mh_offices) === 1 ? '' : 's' ?> in the feed<?= $board !== '' ? ' for ' . e($boards[$board]['label'] ?? $board) : '' ?></div>
        <?php if ($mh_offices): ?>
        <div class="tbl-scroll"><table class="sum">
          <thead><tr><th>Board</th><th>Office MLS ID</th><th>Office Name</th><th>Status</th><th>Members returned</th></tr></thead>
          <tbody>
          <?php foreach ($mh_offices as $o): $n_m = count(array_filter($fetched ?? [], fn($m) => ($m['OfficeMlsId'] ?? '') === $o['OfficeMlsId'] && ($m['OriginatingSystemName'] ?? '') === $o['OriginatingSystemName'])); ?>
            <tr><td><?= e(mk_board_label(mk_market_slug((string)$o['OriginatingSystemName']))) ?></td><td><?= e($o['OfficeMlsId']) ?></td><td class="mh"><?= e($o['OfficeName']) ?></td><td><?= e($o['OfficeStatus'] ?? '') ?></td><td><?= $n_m ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php else: ?>
          <p class="hint">Anyprop has no office named Mont Haus <?= $board !== '' ? 'on this board' : 'on any board' ?>. Until the board sends one, nobody can be attached here by office.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="flash err"><?= e($error) ?></div>
      <?php else: ?>
        <div class="card">
          <div class="pre-head" style="flex-wrap:wrap; gap:8px;">
            <div class="card-title" style="margin:0;"><i class="ti ti-list"></i> <?= number_format(count($rows)) ?> record<?= count($rows) === 1 ? '' : 's' ?><?= $hit_cap ? ($mtype !== '' ? ' (the first ' . number_format(APD_MAX_ROWS) . ' fetched; narrow the search)' : ' (limit reached, raise Max rows)') : '' ?></div>
            <?php if ($rows): ?>
            <div style="display:flex; gap:6px; align-items:center;">
              <div class="colpick">
                <button type="button" class="btn-copy" id="colBtn" aria-expanded="false" aria-controls="colPanel"><i class="ti ti-columns"></i> Columns</button>
                <div class="colpanel" id="colPanel" hidden></div>
              </div>
              <?php // The script adds &cols= with the columns showing; without it the CSV has the default ones. ?>
              <a class="btn-copy" id="csvLink" href="<?= e(apd_link(['download' => 'csv', 'cols' => null])) ?>" data-base="<?= e(apd_link(['download' => 'csv', 'cols' => null])) ?>"><i class="ti ti-download"></i> Download CSV</a>
            </div>
            <?php endif; ?>
          </div>
          <?php if ($resource === 'Member' && count($type_counts) > 0 && !(count($type_counts) === 1 && $mtype === '')): ?>
          <div class="types">
            <span class="fld-label" style="display:inline; margin-right:4px;">Member type</span>
            <a class="chip <?= $mtype === '' ? 'on' : '' ?>" href="<?= e(apd_link(['type' => null])) ?>">All</a>
            <?php foreach ($type_counts as $t => $n): $tk = $t === '' ? '(none)' : $t; ?>
              <a class="chip <?= $mtype === $tk ? 'on' : '' ?>" href="<?= e(apd_link(['type' => $tk])) ?>"><?= e($t === '' ? '(blank)' : $t) ?> <span><?= number_format($n) ?></span></a>
            <?php endforeach; ?>
            <?php if ($mtype === '' && $hit_cap): ?><span class="hint">counts are for the rows fetched so far</span><?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if ($rows): ?>
          <div class="tbl-scroll" id="apdTable" style="margin-top:10px;"><noscript><p class="hint" style="padding:12px;">The table needs JavaScript. Download CSV works without it.</p></noscript></div>
          <script type="application/json" id="apdData"><?= json_encode($table, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
          <?php else: ?>
            <p class="hint">Nothing matched. On a board where this person is at another brokerage, search by email instead of name, or widen to all boards.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php // For debugging: what was asked of Anyprop and what came back, as it came. Closed until opened. ?>
      <details class="dbg" id="dbg">
        <summary><i class="ti ti-code"></i> Raw JSON and request <span class="hint">for debugging</span><i class="ti ti-chevron-down chev" aria-hidden="true"></i></summary>
        <div class="dbg-body">
          <h3>Request</h3>
          <div class="q">GET <?= e($url) ?></div>
          <p class="hint" style="margin:8px 0 0;">$filter = <?= e($filter) ?><?= $pages > 1 ? ' · ' . $pages . ' pages (followed @odata.nextLink)' : '' ?><?= $mtype !== '' ? ' · then kept MemberType = ' . e($mtype === '(none)' ? '(blank)' : $mtype) . ' on this page' : '' ?></p>
          <?php if ($error === ''): ?>
          <div class="pre-head" style="margin-top:16px;">
            <h3 style="margin:0;">Raw JSON<?= count($rows) > APD_JSON_ROWS ? ' <span class="hint" style="font-weight:400; text-transform:none; letter-spacing:0;">first ' . APD_JSON_ROWS . ' of ' . number_format(count($rows)) . ' records; Download JSON has them all</span>' : '' ?></h3>
            <div style="display:flex; gap:6px;">
              <?php if ($rows): ?><a class="btn-copy" href="<?= e(apd_link(['download' => 'json', 'cols' => null])) ?>"><i class="ti ti-download"></i> Download JSON</a><?php endif; ?>
              <button type="button" class="btn-copy" id="copyJson">Copy</button>
            </div>
          </div>
          <pre class="json" id="json"><?= e($pretty) ?></pre>
          <?php endif; ?>
        </div>
      </details>
    <?php endif; ?>

  </div>
</div>

<script>
  // Segmented control: submitting the form on the radio change switches the
  // "Search by" choices to the ones that resource supports.
  document.querySelectorAll('.seg input').forEach(function (r) {
    r.addEventListener('change', function () { r.form.submit(); });
  });
  var cp = document.getElementById('copyJson');
  if (cp) cp.addEventListener('click', function () {
    var t = document.getElementById('json').textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { cp.textContent = 'Copied'; setTimeout(function () { cp.textContent = 'Copy'; }, 1500); },
      function () { window.getSelection().selectAllChildren(document.getElementById('json')); });
  });
  // Light syntax colouring of the JSON block (text only, already escaped), the
  // first time the debugging section is opened: nobody pays for it otherwise.
  var dbg = document.getElementById('dbg'), pre = document.getElementById('json'), coloured = false;
  if (dbg && pre) dbg.addEventListener('toggle', function () {
    if (!dbg.open || coloured) return;
    coloured = true;
    pre.innerHTML = pre.innerHTML.replace(/("(?:[^"\\]|\\.)*")(\s*:)?|(\b-?\d+(?:\.\d+)?\b)/g, function (m, str, colon, num) {
      if (str) return colon ? '<span class="k">' + str + '</span>' + colon : '<span class="s">' + str + '</span>';
      return '<span class="n">' + num + '</span>';
    });
  });

  // ── The results table ──────────────────────────────────────────────────────
  // The server sends the columns a result can show and each record's values in
  // that order (#apdData). Here: which columns show (remembered per browser,
  // per Members / Offices), sorting on a heading, and the Columns menu. The
  // CSV link is told which columns are showing.
  (function () {
    var src = document.getElementById('apdData'), box = document.getElementById('apdTable');
    if (!src || !box) return;
    var data = JSON.parse(src.textContent), cols = data.cols, rows = data.rows;
    var KEY = 'apdCols:' + data.resource;
    var esc = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var when = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Denver', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    var showDate = function (v) { var d = new Date(v); return isNaN(d) ? v : when.format(d); };
    var cmp = new Intl.Collator('en', { numeric: true, sensitivity: 'base' }).compare;

    // The choice: a list of field names. A field this result does not carry stays in the list, for the next search.
    var chosen = null;
    try { var saved = JSON.parse(localStorage.getItem(KEY) || 'null'); if (Array.isArray(saved)) chosen = saved; } catch (e) {}
    var defaults = function () { return cols.filter(function (c) { return c.on; }).map(function (c) { return c.k; }); };
    if (!chosen) chosen = defaults();
    var showing = function () {
      var v = cols.filter(function (c) { return chosen.indexOf(c.k) !== -1; });
      return v.length ? v : cols.filter(function (c) { return c.on; });   // never an empty table
    };
    var save = function () { try { localStorage.setItem(KEY, JSON.stringify(chosen)); } catch (e) {} };

    var sortKey = null, sortDir = 1;
    var order = rows.map(function (_, i) { return i; });   // the order the feed sent
    function sortRows() {
      order = rows.map(function (_, i) { return i; });
      if (sortKey === null) return;
      var ci = cols.findIndex(function (c) { return c.k === sortKey; });
      if (ci < 0) { sortKey = null; return; }
      order.sort(function (a, b) {
        var x = rows[a][ci], y = rows[b][ci];
        if (x === '' || y === '') return x === y ? a - b : (x === '' ? 1 : -1);   // blanks last, whichever way
        return (cmp(x, y) || (a - b)) * sortDir;
      });
    }

    function draw() {
      var vis = showing(), idx = vis.map(function (c) { return cols.indexOf(c); });
      var h = '<table class="sum"><thead><tr>';
      vis.forEach(function (c) {
        var on = c.k === sortKey;
        h += '<th class="' + (on ? 'sorted' : '') + '" aria-sort="' + (on ? (sortDir === 1 ? 'ascending' : 'descending') : 'none') + '"><button type="button" data-k="' + esc(c.k) + '" title="Sort by ' + esc(c.label) + '">'
           + esc(c.label) + '<i class="ti ti-' + (on ? (sortDir === 1 ? 'chevron-up' : 'chevron-down') : 'selector') + '" aria-hidden="true"></i></button></th>';
      });
      h += '</tr></thead><tbody>';
      for (var n = 0; n < order.length; n++) {
        var r = rows[order[n]];
        h += '<tr>';
        for (var j = 0; j < vis.length; j++) {
          var c = vis[j], v = r[idx[j]];
          if (v === '') { h += '<td class="dim">·</td>'; continue; }
          if (c.kind === 'date') { h += '<td>' + esc(showDate(v)) + '</td>'; continue; }
          if (c.label === 'Status') { h += '<td><span class="st ' + (/^active$/i.test(v) ? 'active' : (/^inactive$/i.test(v) ? 'inactive' : '')) + '">' + esc(v) + '</span></td>'; continue; }
          var mh = (c.k === 'OfficeName' && /mont\s*haus/i.test(v)) ? ' class="mh"' : '';
          h += '<td' + mh + (v.length > 40 ? ' title="' + esc(v) + '"' : '') + '>' + esc(v) + '</td>';
        }
        h += '</tr>';
      }
      box.innerHTML = h + '</tbody></table>';
      var csv = document.getElementById('csvLink');
      if (csv) csv.href = csv.getAttribute('data-base') + '&cols=' + encodeURIComponent(vis.map(function (c) { return c.k; }).join(','));
    }

    box.addEventListener('click', function (e) {
      var b = e.target.closest('th button'); if (!b) return;
      var k = b.getAttribute('data-k');
      if (sortKey === k) { if (sortDir === 1) sortDir = -1; else { sortKey = null; sortDir = 1; } }   // up, down, then back to the feed's order
      else { sortKey = k; sortDir = 1; }
      var left = box.scrollLeft;
      sortRows(); draw();
      box.scrollLeft = left;
    });

    // The Columns menu.
    var btn = document.getElementById('colBtn'), panel = document.getElementById('colPanel');
    function drawPanel() {
      var vis = showing().map(function (c) { return c.k; });
      var groups = [['main', 'Columns'], ['ids', 'IDs and keys'], ['other', 'Other fields in this result']];
      var h = '<div class="colhead"><span>' + vis.length + ' of ' + cols.length + ' showing. Your choice is kept for next time.</span><button type="button" id="colReset">Back to the default</button></div>';
      groups.forEach(function (g) {
        var list = cols.filter(function (c) { return c.group === g[0]; });
        if (!list.length) return;
        h += '<h4>' + g[1] + '</h4><div class="colgrid">';
        list.forEach(function (c) { h += '<label><input type="checkbox" value="' + esc(c.k) + '"' + (vis.indexOf(c.k) !== -1 ? ' checked' : '') + '> ' + esc(c.label) + '</label>'; });
        h += '</div>';
      });
      panel.innerHTML = h;
    }
    function openPanel(open) {
      panel.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) drawPanel();
    }
    if (btn && panel) {
      btn.addEventListener('click', function (e) { e.stopPropagation(); openPanel(panel.hidden); });
      panel.addEventListener('click', function (e) { e.stopPropagation(); });
      panel.addEventListener('change', function (e) {
        var k = e.target.value, here = cols.map(function (c) { return c.k; });
        var vis = showing().map(function (c) { return c.k; });
        if (e.target.checked) vis.push(k); else vis = vis.filter(function (x) { return x !== k; });
        if (!vis.length) { e.target.checked = true; return; }   // the last column stays
        // what is showing here, plus the earlier choices this result has no data for
        chosen = vis.concat(chosen.filter(function (x) { return here.indexOf(x) === -1; }));
        if (sortKey && vis.indexOf(sortKey) === -1) { sortKey = null; sortRows(); }
        save(); draw(); drawPanel();
      });
      panel.addEventListener('click', function (e) {
        if (e.target.id !== 'colReset') return;
        chosen = defaults();
        try { localStorage.removeItem(KEY); } catch (err) {}
        draw(); drawPanel();
      });
      document.addEventListener('click', function () { if (!panel.hidden) openPanel(false); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { openPanel(false); btn.focus(); } });
    }

    draw();
  })();
</script>
</body>
</html>
