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
 * Results are shown as the raw JSON the API returned, plus a short table.
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

// ── Inputs ───────────────────────────────────────────────────────────────────
$resource = ($_GET['resource'] ?? 'Member') === 'Office' ? 'Office' : 'Member';
$board    = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_GET['board'] ?? '')));
$q        = trim((string)($_GET['q'] ?? ''));
$by       = (string)($_GET['by'] ?? 'name');
$top      = max(1, min(APD_MAX_ROWS, (int)($_GET['top'] ?? 500)));
$status   = in_array($_GET['status'] ?? '', ['Active', 'Inactive'], true) ? $_GET['status'] : '';
$mtype    = $resource === 'Member' ? trim((string)($_GET['type'] ?? '')) : '';
$download = in_array($_GET['download'] ?? '', ['csv', 'json'], true) ? $_GET['download'] : '';
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
    $label = fn($osn) => mk_board_label(mk_market_slug((string)$osn));
    if ($resource === 'Member') {
        fputcsv($out, ['Board', 'First Name', 'Last Name', 'Company', 'Email', 'Status', 'Member Type', 'MLS ID', 'Office MLS ID'], ',', '"', '');
        foreach ($rows as $r) fputcsv($out, [$label($r['OriginatingSystemName'] ?? ''),
            trim((string)($r['MemberFirstName'] ?? '')), trim((string)($r['MemberLastName'] ?? '')),
            trim((string)($r['OfficeName'] ?? '')), trim((string)($r['MemberEmail'] ?? '')),
            (string)($r['MemberStatus'] ?? ''), (string)($r['MemberType'] ?? ''),
            (string)($r['MemberMlsId'] ?? ''), (string)($r['OfficeMlsId'] ?? '')], ',', '"', '');
    } else {
        fputcsv($out, ['Board', 'Office Name', 'Office MLS ID', 'Status', 'Email', 'Phone'], ',', '"', '');
        foreach ($rows as $r) fputcsv($out, [$label($r['OriginatingSystemName'] ?? ''),
            trim((string)($r['OfficeName'] ?? '')), (string)($r['OfficeMlsId'] ?? ''),
            (string)($r['OfficeStatus'] ?? ''), trim((string)($r['OfficeEmail'] ?? '')),
            trim((string)($r['OfficePhone'] ?? ''))], ',', '"', '');
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
$summary_cols = $resource === 'Member'
    ? ['OriginatingSystemName', 'MemberMlsId', 'MemberFullName', 'MemberEmail', 'MemberStatus', 'MemberType', 'OfficeMlsId', 'OfficeName', 'ModificationTimestamp']
    : ['OriginatingSystemName', 'OfficeMlsId', 'OfficeName', 'OfficeStatus', 'OfficeEmail', 'OfficePhone', 'ModificationTimestamp'];
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
    .wrap { max-width:1100px; margin:32px auto 60px; padding:0 20px; }
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
    .tbl-scroll { overflow-x:auto; }
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
        <p class="hint" style="margin:4px 0 0;">What the MLS feed says about a member or an office, as the API returns it. Reads only.</p>
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
          After a search, pick a member type under the count (Anyprop cannot filter on it, so the page does). Download CSV or JSON gives every row, up to <?= number_format(APD_MAX_ROWS) ?>.
        </p>
      </form>
    </div>

    <?php if ($searched): ?>
      <?php if ($mh_mode && $error === ''): ?>
      <div class="card">
        <div class="card-title"><i class="ti ti-building"></i> <?= count($mh_offices) ?> Mont Haus office<?= count($mh_offices) === 1 ? '' : 's' ?> in the feed<?= $board !== '' ? ' for ' . e($boards[$board]['label'] ?? $board) : '' ?></div>
        <?php if ($mh_offices): ?>
        <div class="tbl-scroll"><table class="sum">
          <thead><tr><th>Board</th><th>OfficeMlsId</th><th>OfficeName</th><th>OfficeStatus</th><th>Members returned</th></tr></thead>
          <tbody>
          <?php foreach ($mh_offices as $o): $n_m = count(array_filter($fetched ?? [], fn($m) => ($m['OfficeMlsId'] ?? '') === $o['OfficeMlsId'] && ($m['OriginatingSystemName'] ?? '') === $o['OriginatingSystemName'])); ?>
            <tr><td><?= e(mk_board_label(mk_market_slug((string)$o['OriginatingSystemName']))) ?> (<?= e($o['OriginatingSystemName']) ?>)</td><td><?= e($o['OfficeMlsId']) ?></td><td class="mh"><?= e($o['OfficeName']) ?></td><td><?= e($o['OfficeStatus'] ?? '') ?></td><td><?= $n_m ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php else: ?>
          <p class="hint">Anyprop has no office named Mont Haus <?= $board !== '' ? 'on this board' : 'on any board' ?>. Until the board sends one, nobody can be attached here by office.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="card">
        <div class="card-title"><i class="ti ti-api"></i> Request</div>
        <div class="q">GET <?= e($url) ?></div>
        <p class="hint" style="margin:8px 0 0;">$filter = <?= e($filter) ?><?= $pages > 1 ? ' · ' . $pages . ' pages (followed @odata.nextLink)' : '' ?><?= $mtype !== '' ? ' · then kept MemberType = ' . e($mtype === '(none)' ? '(blank)' : $mtype) . ' on this page' : '' ?></p>
      </div>

      <?php if ($error !== ''): ?>
        <div class="flash err"><?= e($error) ?></div>
      <?php else: ?>
        <div class="card">
          <div class="pre-head" style="flex-wrap:wrap; gap:8px;">
            <div class="card-title" style="margin:0;"><i class="ti ti-list"></i> <?= number_format(count($rows)) ?> record<?= count($rows) === 1 ? '' : 's' ?><?= $hit_cap ? ($mtype !== '' ? ' (the first ' . number_format(APD_MAX_ROWS) . ' fetched; narrow the search)' : ' (limit reached, raise Max rows)') : '' ?></div>
            <?php if ($rows): ?>
            <div style="display:flex; gap:6px;">
              <a class="btn-copy" href="<?= e(apd_link(['download' => 'csv'])) ?>"><i class="ti ti-download"></i> Download CSV</a>
              <a class="btn-copy" href="<?= e(apd_link(['download' => 'json'])) ?>"><i class="ti ti-download"></i> Download JSON</a>
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
          <div class="tbl-scroll">
            <table class="sum">
              <thead><tr><?php foreach ($summary_cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
              <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <?php foreach ($summary_cols as $c): $v = $r[$c] ?? ''; $v = is_scalar($v) || $v === null ? (string)$v : json_encode($v);
                        // CREN sends no MemberFullName: show first + last so the row is readable
                        if ($c === 'MemberFullName' && trim($v) === '') $v = trim(($r['MemberFirstName'] ?? '') . ' ' . ($r['MemberLastName'] ?? '')); ?>
                    <td class="<?= $c === 'OfficeName' && preg_match('/mont\s*haus/i', $v) ? 'mh' : '' ?>"><?= e($v) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
            <p class="hint">Nothing matched. On a board where this person is at another brokerage, search by email instead of name, or widen to all boards.</p>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="pre-head">
            <div class="card-title" style="margin:0;"><i class="ti ti-code"></i> Raw JSON<?= count($rows) > APD_JSON_ROWS ? ' <span class="hint" style="font-weight:400;">first ' . APD_JSON_ROWS . ' of ' . number_format(count($rows)) . ' records; Download JSON has them all</span>' : '' ?></div>
            <button type="button" class="btn-copy" id="copyJson">Copy</button>
          </div>
          <pre class="json" id="json"><?= e($pretty) ?></pre>
        </div>
      <?php endif; ?>
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
  // Light syntax colouring of the JSON block (text only, already escaped).
  var pre = document.getElementById('json');
  if (pre) pre.innerHTML = pre.innerHTML.replace(/("(?:[^"\\]|\\.)*")(\s*:)?|(\b-?\d+(?:\.\d+)?\b)/g, function (m, str, colon, num) {
    if (str) return colon ? '<span class="k">' + str + '</span>' + colon : '<span class="s">' + str + '</span>';
    return '<span class="n">' + num + '</span>';
  });
</script>
</body>
</html>
