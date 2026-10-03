<?php
/**
 * spark_debug.php — look at a board's raw Spark (FBS) feed: Member / Office
 * (2026-10-02). The Spark twin of anyprop_debug.php, for comparing what the
 * MLS itself sends with what Anyprop relays, e.g. a Mont Haus office that
 * Anyprop's Telluride feed does not carry.
 *
 * Admin only. Reads Spark's RESO Web API
 * (replication.sparkapi.com/Version/3/Reso/OData) and writes nothing. The
 * access token is either one configured in inc/db.php (SPARK_ACCESS_TOKEN =
 * Aspen, VAIL_SPARK_ACCESS_TOKEN = Vail) or one pasted into the form: a pasted
 * token is POSTed (never in the URL or the access log), kept in the PHP
 * session for the following searches, and dropped with "Forget token" or at
 * sign-out. It is never written to disk.
 *
 * Spark's OData filter honours eq / and / or only: startswith() and
 * contains() answer 200 with an empty list (checked 2026-10-02), so member
 * searches are exact-field (MemberLastName, MemberEmail, MemberMlsId,
 * OfficeKey / OfficeMlsId). An office NAME search therefore pages through the
 * board's whole Office list (a few hundred rows) and matches in PHP, case-
 * insensitively; "*" lists every office.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';
require_login();
require_role('admin');

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function odata_q(string $s): string { return str_replace("'", "''", $s); }

const SPARK_BASE = 'https://replication.sparkapi.com/Version/3/Reso/OData/';

/** One GET; returns [http code, decoded body or null, raw body]. */
function spk_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    if ($err) return [0, null, "cURL: {$err}"];
    return [$code, json_decode($raw, true), $raw];
}

// ── Tokens ───────────────────────────────────────────────────────────────────
$configured = [];
if (defined('SPARK_ACCESS_TOKEN') && SPARK_ACCESS_TOKEN !== '')           $configured['aspen'] = ['Aspen (configured)', SPARK_ACCESS_TOKEN];
if (defined('VAIL_SPARK_ACCESS_TOKEN') && VAIL_SPARK_ACCESS_TOKEN !== '') $configured['vail']  = ['Vail (configured)',  VAIL_SPARK_ACCESS_TOKEN];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['forget'])) {
        unset($_SESSION['spark_debug_token'], $_SESSION['spark_debug_label']);
    } else {
        $t = trim((string)($_POST['token'] ?? ''));
        if ($t !== '' && preg_match('/^[A-Za-z0-9._-]{8,200}$/', $t)) {
            $_SESSION['spark_debug_token'] = $t;
            $_SESSION['spark_debug_label'] = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 40) ?: 'pasted token';
        }
    }
    header('Location: spark_debug.php'); exit;
}

$pasted = $_SESSION['spark_debug_token'] ?? '';
$pasted_label = $_SESSION['spark_debug_label'] ?? 'pasted token';
$sources = $configured;
if ($pasted !== '') $sources['pasted'] = [$pasted_label, $pasted];

// ── Inputs ───────────────────────────────────────────────────────────────────
$resource = ($_GET['resource'] ?? 'Member') === 'Office' ? 'Office' : 'Member';
$src      = (string)($_GET['src'] ?? ($pasted !== '' ? 'pasted' : array_key_first($sources) ?? ''));
if (!isset($sources[$src])) $src = array_key_first($sources) ?? '';
$q        = trim((string)($_GET['q'] ?? ''));
$by       = (string)($_GET['by'] ?? 'last');
$top      = max(1, min(500, (int)($_GET['top'] ?? 50)));
$active   = !isset($_GET['q']) || !empty($_GET['active']);
$allowed_by = $resource === 'Member'
    ? ['last' => 'Last name (exact)', 'email' => 'Email (exact)', 'mlsid' => 'MLS ID (exact)', 'office' => 'Office MLS ID (exact)', 'officekey' => 'Office key (exact)']
    : ['name' => 'Office name (contains, * = all)', 'mlsid' => 'Office MLS ID (exact)', 'officekey' => 'Office key (exact)'];
if (!isset($allowed_by[$by])) $by = array_key_first($allowed_by);

$result = null; $error = ''; $url = ''; $filter = '';
if ($q !== '' && $src !== '') {
    $v = odata_q($q);
    $parts = [];
    if ($resource === 'Member') {
        $parts[] = match ($by) {
            'email'     => "MemberEmail eq '" . odata_q(strtolower($q)) . "'",
            'mlsid'     => "MemberMlsId eq '{$v}'",
            'office'    => "OfficeMlsId eq '{$v}'",
            'officekey' => "OfficeKey eq '{$v}'",
            default     => "MemberLastName eq '{$v}'",
        };
        if ($active) $parts[] = "MemberStatus eq 'Active'";
        $select = 'MemberKey,MemberMlsId,MemberFullName,MemberFirstName,MemberLastName,MemberEmail,MemberStatus,MemberType,MemberStateLicense,MemberMobilePhone,OfficeKey,OfficeMlsId,OfficeName,ModificationTimestamp,OriginatingSystemName';
    } else {
        if ($by === 'name') $scan = true;   // no usable name filter on Spark: fetch all, match here
        else $parts[] = $by === 'mlsid' ? "OfficeMlsId eq '{$v}'" : "OfficeKey eq '{$v}'";
        $select = 'OfficeKey,OfficeMlsId,OfficeName,OfficeStatus,OfficeEmail,OfficePhone,OfficeCity,ModificationTimestamp,OriginatingSystemName';
    }
    $filter = implode(' and ', $parts);
    if (!empty($scan)) {
        // Page through every office (500 a page, 20 pages at most) and keep the
        // ones whose name contains the text; * keeps them all.
        $url = SPARK_BASE . 'Office?' . http_build_query(['$select' => $select, '$top' => 500]);
        $next = $url; $all = []; $pages = 0; $needle = mb_strtolower($q);
        while ($next !== null && $pages++ < 20) {
            [$code, $data, $raw] = spk_get($next, $sources[$src][1]);
            if ($code !== 200) { $error = "Spark answered HTTP {$code}: " . mb_substr($raw, 0, 600); break; }
            foreach ($data['value'] ?? [] as $o) {
                if ($q === '*' || mb_strpos(mb_strtolower((string)($o['OfficeName'] ?? '')), $needle) !== false) $all[] = $o;
            }
            $next = $data['@odata.nextLink'] ?? null;
        }
        if ($error === '') {
            $filter = "(client side) OfficeName contains '{$q}', " . ($pages) . " page(s) read";
            $result = ['data' => ['@odata.context' => $data['@odata.context'] ?? '', 'value' => array_slice($all, 0, $top)], 'raw' => ''];
        }
    } else {
        $url = SPARK_BASE . $resource . '?' . http_build_query(['$filter' => $filter, '$select' => $select, '$top' => $top]);
        [$code, $data, $raw] = spk_get($url, $sources[$src][1]);
        if ($code !== 200) $error = "Spark answered HTTP {$code}: " . mb_substr($raw, 0, 600);
        else $result = ['data' => $data, 'raw' => $raw];
    }
}
$rows = $result['data']['value'] ?? [];
$pretty = $result ? json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
$summary_cols = $resource === 'Member'
    ? ['MemberMlsId', 'MemberFullName', 'MemberEmail', 'MemberStatus', 'OfficeMlsId', 'OfficeName', 'OfficeKey', 'ModificationTimestamp']
    : ['OfficeMlsId', 'OfficeName', 'OfficeStatus', 'OfficeCity', 'OfficePhone', 'OfficeKey', 'ModificationTimestamp'];
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Spark Feed | Mont Haus Marketing</title>
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
    .note { background:#fbf7ec; border:1px solid #e3dccb; padding:12px 14px; font-size:13px; color:#5c4a28; margin-bottom:18px; }
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
    .chk { display:flex; align-items:center; gap:6px; font-size:13px; padding:9px 0; white-space:nowrap; }
    @media (max-width:700px) { .form-row > div { flex:1 1 100%; } }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = 'marketing'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

    <div class="mk-page-header">
      <div>
        <h1>Spark Feed</h1>
        <p class="hint" style="margin:4px 0 0;">A board's own Spark (FBS) feed, as the API returns it. Reads only. Compare with the <a href="anyprop_debug.php">Anyprop Feed</a>.</p>
      </div>
      <a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-arrow-left"></i> Roster</a>
    </div>

    <div class="card">
      <div class="card-title"><i class="ti ti-key"></i> Access token</div>
      <form method="POST" action="spark_debug.php" autocomplete="off">
        <div class="form-row">
          <div style="flex:0 0 200px;">
            <label class="fld-label" for="label">Board</label>
            <input class="form-input" type="text" name="label" id="label" placeholder="Telluride" maxlength="40">
          </div>
          <div style="flex:1 1 320px;">
            <label class="fld-label" for="token">Spark access token</label>
            <input class="form-input" type="password" name="token" id="token" placeholder="paste the token for that board" autocomplete="off">
          </div>
          <div><button type="submit" class="btn btn-primary">Use this token</button></div>
          <?php if ($pasted !== ''): ?><div><button type="submit" name="forget" value="1" class="btn btn-outline">Forget token (<?= e($pasted_label) ?>)</button></div><?php endif; ?>
        </div>
        <p class="hint" style="margin:0;">
          <?php if ($configured): ?>Configured in inc/db.php: <?= e(implode(', ', array_map(fn($s) => $s[0], $configured))) ?>. <?php endif; ?>
          A pasted token is sent in the form body, held in your session for this browser only, and never written down.
        </p>
      </form>
    </div>

    <?php if (!$sources): ?>
      <div class="note">No token yet: paste one above, or set SPARK_ACCESS_TOKEN / VAIL_SPARK_ACCESS_TOKEN in inc/db.php.</div>
    <?php else: ?>
    <div class="card">
      <form method="GET" action="spark_debug.php">
        <div class="form-row">
          <div>
            <span class="fld-label">Look up</span>
            <div class="seg">
              <label class="<?= $resource === 'Member' ? 'on' : '' ?>"><input type="radio" name="resource" value="Member" <?= $resource === 'Member' ? 'checked' : '' ?>>Members</label>
              <label class="<?= $resource === 'Office' ? 'on' : '' ?>"><input type="radio" name="resource" value="Office" <?= $resource === 'Office' ? 'checked' : '' ?>>Offices</label>
            </div>
          </div>
          <div style="flex:0 0 180px;">
            <label class="fld-label" for="src">Feed (token)</label>
            <select class="form-input" name="src" id="src">
              <?php foreach ($sources as $k => $s): ?><option value="<?= e($k) ?>" <?= $k === $src ? 'selected' : '' ?>><?= e($s[0]) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="flex:0 0 190px;">
            <label class="fld-label" for="by">Search by</label>
            <select class="form-input" name="by" id="by">
              <?php foreach ($allowed_by as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $by ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1 1 220px;">
            <label class="fld-label" for="q">Search for</label>
            <input class="form-input" type="text" name="q" id="q" value="<?= e($q) ?>" placeholder="<?= $resource === 'Member' ? 'Boxer, jonathan.boxer@monthaus.com, 49179…' : 'Mont Haus, or * for every office' ?>" autofocus>
          </div>
          <div style="flex:0 0 90px;">
            <label class="fld-label" for="top">Max rows</label>
            <input class="form-input" type="number" name="top" id="top" value="<?= $top ?>" min="1" max="500">
          </div>
          <?php if ($resource === 'Member'): ?>
          <div><label class="chk"><input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?>> Active only</label></div>
          <?php endif; ?>
          <div><button type="submit" class="btn btn-primary"><i class="ti ti-search"></i> Search</button></div>
        </div>
        <p class="hint" style="margin:0;">
          Member searches are exact matches (last name, email, IDs) and case-sensitive, so spell them as the board does. An office name search reads the board's whole office list and matches anywhere in the name; type * to list every office.
        </p>
      </form>
    </div>

    <?php if ($q !== ''): ?>
      <div class="card">
        <div class="card-title"><i class="ti ti-api"></i> Request</div>
        <div class="q">GET <?= e($url) ?></div>
        <p class="hint" style="margin:8px 0 0;">$filter = <?= e($filter) ?> &middot; token: <?= e($sources[$src][0]) ?></p>
      </div>

      <?php if ($error !== ''): ?>
        <div class="flash err"><?= e($error) ?></div>
      <?php else: ?>
        <div class="card">
          <div class="card-title"><i class="ti ti-list"></i> <?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) >= $top ? ' (limit reached, raise Max rows)' : '' ?><?= !empty($result['data']['@odata.nextLink']) ? ' (more pages exist)' : '' ?></div>
          <?php if ($rows): ?>
          <div class="tbl-scroll">
            <table class="sum">
              <thead><tr><?php foreach ($summary_cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
              <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <?php foreach ($summary_cols as $c): $v = $r[$c] ?? ''; $v = is_scalar($v) || $v === null ? (string)$v : json_encode($v); ?>
                    <td class="<?= $c === 'OfficeName' && preg_match('/mont\s*haus/i', $v) ? 'mh' : '' ?>"><?= e($v) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
            <p class="hint">Nothing matched. Spark matches are exact and case-sensitive: try the email, the MLS ID, or untick "Active only".</p>
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
    <?php endif; ?>

  </div>
</div>

<script>
  document.querySelectorAll('.seg input').forEach(function (r) {
    r.addEventListener('change', function () { r.form.submit(); });
  });
  var cp = document.getElementById('copyJson');
  if (cp) cp.addEventListener('click', function () {
    var t = document.getElementById('json').textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { cp.textContent = 'Copied'; setTimeout(function () { cp.textContent = 'Copy'; }, 1500); },
      function () { window.getSelection().selectAllChildren(document.getElementById('json')); });
  });
  var pre = document.getElementById('json');
  if (pre) pre.innerHTML = pre.innerHTML.replace(/("(?:[^"\\]|\\.)*")(\s*:)?|(\b-?\d+(?:\.\d+)?\b)/g, function (m, str, colon, num) {
    if (str) return colon ? '<span class="k">' + str + '</span>' + colon : '<span class="s">' + str + '</span>';
    return '<span class="n">' + num + '</span>';
  });
</script>
</body>
</html>
