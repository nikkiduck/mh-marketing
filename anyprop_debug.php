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
 * Results are shown as the raw JSON the API returned, plus a short table.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/boards.php';
require_login();
require_role('admin');

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
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . apd_token(), 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_ENCODING       => '',
    ]);
    $raw = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    if ($err) return [0, null, "cURL: {$err}"];
    return [$code, json_decode($raw, true), $raw];
}

// ── Inputs ───────────────────────────────────────────────────────────────────
$resource = ($_GET['resource'] ?? 'Member') === 'Office' ? 'Office' : 'Member';
$board    = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_GET['board'] ?? '')));
$q        = trim((string)($_GET['q'] ?? ''));
$by       = (string)($_GET['by'] ?? 'name');
$top      = max(1, min(200, (int)($_GET['top'] ?? 50)));
$allowed_by = $resource === 'Member' ? ['mhoffice', 'name', 'email', 'mlsid', 'office'] : ['name', 'mlsid'];
if (!in_array($by, $allowed_by, true)) $by = 'name';
$mh_mode = $resource === 'Member' && $by === 'mhoffice';   // every member of the Mont Haus office(s)
$mh_offices = [];

$boards = mk_board_registry();   // slug => entry; the OSN is what the API filters on
$osn_of = [];
foreach ($boards as $slug => $b) $osn_of[$slug] = $b['osn'];

$result = null; $error = ''; $url = ''; $filter = '';
if ($mh_mode && isset($_GET['by'])) {
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
            $mf = "OriginatingSystemName eq '" . odata_q((string)$o['OriginatingSystemName']) . "' and OfficeMlsId eq '" . odata_q((string)$o['OfficeMlsId']) . "'";
            [$code, $md, $raw] = apd_get($base . 'Member?$filter=' . rawurlencode($mf) . '&$top=500');
            if ($code !== 200) throw new RuntimeException("Anyprop answered HTTP {$code} for office {$o['OfficeMlsId']}: " . mb_substr($raw, 0, 300));
            foreach ($md['value'] ?? [] as $m) $members[] = $m;
        }
        $url    = $base . 'Office?$filter=' . rawurlencode($of) . '  then  Member?$filter=OriginatingSystemName eq <board> and OfficeMlsId eq <office> for each office found';
        $filter = $of;
        $result = ['data' => ['mont_haus_offices' => $mh_offices, 'value' => $members], 'raw' => ''];
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
} elseif ($q !== '') {
    $parts = [];
    if ($board !== '' && isset($osn_of[$board])) $parts[] = "OriginatingSystemName eq '" . odata_q($osn_of[$board]) . "'";
    $v = odata_q($q);
    if ($resource === 'Member') {
        $parts[] = match ($by) {
            'email'  => "MemberEmail eq '" . odata_q(strtolower($q)) . "'",
            'mlsid'  => "MemberMlsId eq '{$v}'",
            'office' => "OfficeMlsId eq '{$v}'",
            default  => '(' . implode(' or ', array_unique(array_map(fn($s) => "contains(MemberFullName,'" . odata_q($s) . "')",
                            [$q, strtolower($q), strtoupper($q), ucwords(strtolower($q))]))) . ')',
        };
    } else {
        $parts[] = match ($by) {
            'mlsid'  => "OfficeMlsId eq '{$v}'",
            default  => '(' . implode(' or ', array_unique(array_map(fn($s) => "contains(OfficeName,'" . odata_q($s) . "')",
                            [$q, strtolower($q), strtoupper($q), ucwords(strtolower($q))]))) . ')',
        };
    }
    $filter = implode(' and ', $parts);
    $url = 'https://api.anyprop.com/v1/listings/data/' . $resource . '?$filter=' . rawurlencode($filter) . '&$top=' . $top;
    try {
        [$code, $data, $raw] = apd_get($url);
        if ($code !== 200) $error = "Anyprop answered HTTP {$code}: " . mb_substr($raw, 0, 600);
        else $result = ['data' => $data, 'raw' => $raw];
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}
$rows = $result['data']['value'] ?? [];
$pretty = $result ? json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
$summary_cols = $resource === 'Member'
    ? ['OriginatingSystemName', 'MemberMlsId', 'MemberFullName', 'MemberEmail', 'MemberStatus', 'OfficeMlsId', 'OfficeName', 'ModificationTimestamp']
    : ['OriginatingSystemName', 'OfficeMlsId', 'OfficeName', 'OfficeStatus', 'OfficeEmail', 'OfficePhone', 'ModificationTimestamp'];
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
                   color:#75BDB6; text-decoration:none; border-radius:6px; white-space:nowrap; transition:color .2s, background .2s; }
    .mh-nav-link:hover  { color:#fff; background:rgba(117,189,182,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(117,189,182,.45); }
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
    table.sum td { padding:6px 8px; border-bottom:1px solid #f1ece0; vertical-align:top; }
    table.sum td.mh { font-weight:600; color:#15803d; }
    .tbl-scroll { overflow-x:auto; }
    pre.json { background:#1a1a1a; color:#e5e7eb; font-size:12px; line-height:1.45; padding:16px; border-radius:6px; overflow:auto; max-height:70vh; margin:0; }
    pre.json .k { color:#75BDB6; } pre.json .s { color:#f5e6a8; } pre.json .n { color:#c4b5fd; }
    .pre-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
    .btn-copy { border:1px solid #d1d5db; background:#fff; border-radius:4px; padding:5px 10px; font-size:12px; cursor:pointer; }
    @media (max-width:700px) { .form-row > div { flex:1 1 100%; } }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = 'marketing'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

    <div class="mk-page-header">
      <div>
        <h1>Anyprop Feed</h1>
        <p class="hint" style="margin:4px 0 0;">What the MLS feed says about a member or an office, as the API returns it. Reads only.</p>
      </div>
      <a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-arrow-left"></i> Roster</a>
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
          <div style="flex:0 0 170px;">
            <label class="fld-label" for="by">Search by</label>
            <select class="form-input" name="by" id="by">
              <?php $labels = ['mhoffice' => 'Mont Haus office (everyone)', 'name' => 'Name (contains)', 'email' => 'Email (exact)', 'mlsid' => 'MLS ID (exact)', 'office' => 'Office MLS ID (exact)'];
                    foreach ($allowed_by as $k): ?>
                <option value="<?= $k ?>" <?= $k === $by ? 'selected' : '' ?>><?= $labels[$k] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1 1 240px;">
            <label class="fld-label" for="q">Search for</label>
            <input class="form-input" type="text" name="q" id="q" value="<?= e($q) ?>" placeholder="<?= $mh_mode ? 'not needed for Mont Haus office' : ($resource === 'Member' ? 'Boxer, jonathan.boxer@monthaus.com, 1513…' : 'Mont Haus, 805522330…') ?>" autofocus>
          </div>
          <div style="flex:0 0 90px;">
            <label class="fld-label" for="top">Max rows</label>
            <input class="form-input" type="number" name="top" id="top" value="<?= $top ?>" min="1" max="200">
          </div>
          <div>
            <button type="submit" class="btn btn-primary"><i class="ti ti-search"></i> Search</button>
          </div>
        </div>
        <p class="hint" style="margin:0;">
          <strong>Mont Haus office</strong> lists everyone Anyprop has in the Mont Haus office(s) of the chosen board, or of every board: no search text needed.
          Members: name searches the full name as the board spells it (tries the typed spelling, lower, UPPER and Title Case); email and IDs are exact.
          Offices: name (contains) or office MLS ID. Anyprop cannot filter on last name alone.
        </p>
      </form>
    </div>

    <?php if ($q !== '' || ($mh_mode && isset($_GET['by']))): ?>
      <?php if ($mh_mode && $error === ''): ?>
      <div class="card">
        <div class="card-title"><i class="ti ti-building"></i> <?= count($mh_offices) ?> Mont Haus office<?= count($mh_offices) === 1 ? '' : 's' ?> in the feed<?= $board !== '' ? ' for ' . e($boards[$board]['label'] ?? $board) : '' ?></div>
        <?php if ($mh_offices): ?>
        <div class="tbl-scroll"><table class="sum">
          <thead><tr><th>Board</th><th>OfficeMlsId</th><th>OfficeName</th><th>OfficeStatus</th><th>Members returned</th></tr></thead>
          <tbody>
          <?php foreach ($mh_offices as $o): $n_m = count(array_filter($result['data']['value'] ?? [], fn($m) => ($m['OfficeMlsId'] ?? '') === $o['OfficeMlsId'] && ($m['OriginatingSystemName'] ?? '') === $o['OriginatingSystemName'])); ?>
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
        <p class="hint" style="margin:8px 0 0;">$filter = <?= e($filter) ?></p>
      </div>

      <?php if ($error !== ''): ?>
        <div class="flash err"><?= e($error) ?></div>
      <?php else: ?>
        <div class="card">
          <div class="card-title"><i class="ti ti-list"></i> <?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) >= $top ? ' (limit reached, raise Max rows)' : '' ?></div>
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
            <div class="card-title" style="margin:0;"><i class="ti ti-code"></i> Raw JSON</div>
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
