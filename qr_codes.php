<?php
/**
 * qr_codes.php — dynamic QR codes for brokers (qr.monthaus.com).
 *
 * Each code is a fixed address, qr.monthaus.com/<code>, printed on a yard
 * sign, an open house sign, a card. What it opens is changed here, as often
 * as needed, without reprinting anything. qr.php does the redirecting; this
 * page manages the table behind it. See CLAUDE.md, "Dynamic QR codes".
 *
 * What the page deliberately does NOT offer:
 *   · renaming a code: the old one is on a sign somewhere;
 *   · deleting a code that has ever been scanned: it is on a sign somewhere.
 *     Pause sends scans to QR_FALLBACK_URL, and a paused code can be
 *     repointed and resumed for a new use. A code with no scans at all can be
 *     deleted (2026-09-30), so test codes and mistakes do not pile up; the
 *     page's own Test link counts as a scan, which ends that.
 *
 * The QR images are drawn in the browser (qrcode-generator, cdnjs) and
 * downloaded as SVG for print or PNG for everything else. Nothing is stored.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/schema.php';
require_once __DIR__ . '/inc/qr.php';
require_login();
require_role('admin');

$ready = mk_table_exists($conn, 'qr_codes')
      && mk_table_exists($conn, 'qr_code_changes')
      && mk_table_exists($conn, 'qr_scans_daily');
$has_team = mk_column_exists($conn, 'marketing_intakes', 'entity_type');
$me       = (int) ($_SESSION['user_id'] ?? 0) ?: null;

// ── CSRF (same pattern as users.php) ─────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

/** A broker row for validation, or null. */
function qr_broker(mysqli $conn, int $iid, bool $has_team): ?array {
    if ($iid <= 0) return null;
    $cols = 'id, agent_name, slug' . ($has_team ? ', entity_type' : '');
    $r = $conn->query("SELECT {$cols} FROM marketing_intakes WHERE id = {$iid} LIMIT 1");
    return $r ? ($r->fetch_assoc() ?: null) : null;
}

/** Checks the destination half of a post. Returns [type, url|null, error]. */
function qr_read_dest(array $p, ?array $broker): array {
    $type = ($p['dest_type'] ?? '') === 'url' ? 'url' : 'profile';
    if ($type === 'profile') {
        if (!$broker)                                         return [$type, null, 'Pick a broker to point at their profile page, or choose a specific page.'];
        if (($broker['entity_type'] ?? 'agent') === 'team')   return [$type, null, $broker['agent_name'] . ' is a team and has no profile page. Choose a specific page.'];
        if (trim((string) $broker['slug']) === '')            return [$type, null, $broker['agent_name'] . ' has no web address yet (set it under Website on their page), so there is no profile page to point at.'];
        return [$type, null, ''];
    }
    $url = trim((string) ($p['dest_url'] ?? ''));
    return [$type, $url, qr_dest_error($url)];
}

function qr_dest_text(string $type, ?string $url): string {
    return $type === 'profile' ? 'Profile page' : (string) $url;
}

// ── Writes ───────────────────────────────────────────────────────────────────
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');
    $flash  = null;
    $back   = 'qr_codes.php' . (!empty($_POST['back_intake']) ? '?intake=' . (int) $_POST['back_intake'] : '');
    $anchor = '';

    if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
        $flash = ['err', 'That form had expired. Please try again.'];
    }

    elseif ($action === 'create') {
        $iid    = (int) ($_POST['intake_id'] ?? 0);
        $broker = qr_broker($conn, $iid, $has_team);
        $code   = qr_normalize_code((string) ($_POST['code'] ?? ''));
        $label  = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 120);
        [$type, $url, $derr] = qr_read_dest($_POST, $broker);

        $err = $iid && !$broker ? 'That broker could not be found.' : (qr_code_error($code) ?: $derr);
        if ($err === '') {
            $s = $conn->prepare("SELECT 1 FROM qr_codes WHERE code = ? LIMIT 1");
            $s->bind_param('s', $code); $s->execute();
            if ($s->get_result()->fetch_row()) $err = qr_public_url($code) . ' is already taken. Codes are never reused, so pick another.';
            $s->close();
        }
        if ($err !== '') {
            $flash = ['err', $err];
            // Keep what was typed, so a typo does not mean starting over.
            $_SESSION['qr_draft'] = array_intersect_key($_POST, array_flip(['intake_id', 'code', 'label', 'dest_type', 'dest_url']));
        } else {
            $bid = $broker ? (int) $broker['id'] : null;
            $s = $conn->prepare("INSERT INTO qr_codes (code, intake_id, label, dest_type, dest_url, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $s->bind_param('sisssi', $code, $bid, $label, $type, $url, $me);
            try {
                $s->execute();
                $new_id = (int) $conn->insert_id;
                qr_log_change($conn, $new_id, 'created', null, qr_dest_text($type, $url), $me);
                $flash  = ['ok', 'Created ' . qr_public_url($code) . '. Download the image below.'];
                $anchor = '#qr-' . $new_id;
            } catch (mysqli_sql_exception $e) {
                $flash = ['err', 'Could not save: ' . $e->getMessage()];
            }
            $s->close();
        }
    }

    elseif ($action === 'update' || $action === 'pause' || $action === 'resume') {
        $qid = (int) ($_POST['id'] ?? 0);
        $cur = $qid ? $conn->query("SELECT * FROM qr_codes WHERE id = {$qid} LIMIT 1")->fetch_assoc() : null;
        $anchor = '#qr-' . $qid;

        if (!$cur) {
            $flash = ['err', 'That code could not be found.'];
        } elseif ($action === 'pause' || $action === 'resume') {
            $on = $action === 'resume' ? 1 : 0;
            if ((int) $cur['is_active'] !== $on) {
                $conn->query("UPDATE qr_codes SET is_active = {$on}, updated_at = UTC_TIMESTAMP() WHERE id = {$qid}");
                qr_log_change($conn, $qid, $on ? 'resumed' : 'paused', null, null, $me);
            }
            $flash = ['ok', $on ? qr_public_url($cur['code']) . ' is live again.'
                                : qr_public_url($cur['code']) . ' is paused. Scans now go to ' . QR_FALLBACK_URL . '.'];
        } else {
            $iid    = (int) ($_POST['intake_id'] ?? 0);
            $broker = qr_broker($conn, $iid, $has_team);
            $label  = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 120);
            [$type, $url, $derr] = qr_read_dest($_POST, $broker);
            $err = $iid && !$broker ? 'That broker could not be found.' : $derr;

            if ($err !== '') {
                $flash = ['err', $err];
            } else {
                $bid     = $broker ? (int) $broker['id'] : null;
                $old_dst = qr_dest_text((string) $cur['dest_type'], $cur['dest_url']);
                $new_dst = qr_dest_text($type, $url);
                $changes = [];
                if ($old_dst !== $new_dst)                           $changes[] = ['destination', $old_dst, $new_dst];
                if ((string) $cur['label'] !== $label)               $changes[] = ['label', $cur['label'], $label];
                if ((int) ($cur['intake_id'] ?? 0) !== (int) $bid) {
                    $old_b = qr_broker($conn, (int) $cur['intake_id'], $has_team);
                    $changes[] = ['broker', $old_b['agent_name'] ?? '(none)', $broker['agent_name'] ?? '(none)'];
                }
                if (!$changes) {
                    $flash = ['ok', 'Nothing had changed.'];
                } else {
                    $s = $conn->prepare("UPDATE qr_codes SET intake_id = ?, label = ?, dest_type = ?, dest_url = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?");
                    $s->bind_param('isssi', $bid, $label, $type, $url, $qid);
                    $s->execute(); $s->close();
                    foreach ($changes as [$what, $o, $n]) qr_log_change($conn, $qid, $what, $o, $n, $me);
                    $flash = ['ok', 'Saved. ' . qr_public_url($cur['code']) . ' now opens ' . ($type === 'profile' ? 'the profile page' : $url) . '.'];
                }
            }
        }
    }

    elseif ($action === 'delete') {
        // Only a code nobody has ever scanned: anything scanned is in print.
        // scan_count is re-checked in the DELETE itself, so a scan arriving
        // between the page load and the click still wins.
        $qid = (int) ($_POST['id'] ?? 0);
        $cur = $qid ? $conn->query("SELECT * FROM qr_codes WHERE id = {$qid} LIMIT 1")->fetch_assoc() : null;
        if (!$cur) {
            $flash = ['err', 'That code could not be found.'];
        } elseif ((int) $cur['scan_count'] > 0) {
            $flash = ['err', qr_public_url($cur['code']) . ' has been scanned, so it may be printed somewhere and cannot be deleted. Pause it instead.'];
            $anchor = '#qr-' . $qid;
        } else {
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM qr_codes WHERE id = {$qid} AND scan_count = 0");
                if ($conn->affected_rows !== 1) throw new RuntimeException('it was scanned a moment ago, so it was kept.');
                $conn->query("DELETE FROM qr_code_changes WHERE qr_id = {$qid}");
                $conn->query("DELETE FROM qr_scans_daily WHERE qr_id = {$qid}");
                $conn->commit();
                $flash = ['ok', 'Deleted ' . qr_public_url($cur['code']) . '. The name can be used again.'];
            } catch (Throwable $e) {
                $conn->rollback();
                $flash = ['err', 'Could not delete: ' . $e->getMessage()];
                $anchor = '#qr-' . $qid;
            }
        }
    }

    $_SESSION['qr_flash'] = $flash;
    header('Location: ' . $back . $anchor);
    exit;
}

$flash = $_SESSION['qr_flash'] ?? null;
$draft = $_SESSION['qr_draft'] ?? [];
unset($_SESSION['qr_flash'], $_SESSION['qr_draft']);

// ── Reads ────────────────────────────────────────────────────────────────────
$filter = (int) ($_GET['intake'] ?? 0);

$bcols   = 'id, agent_name, slug, is_active, status' . ($has_team ? ', entity_type' : '');
$brokers = $conn->query("SELECT {$bcols} FROM marketing_intakes
                          WHERE is_active = 1 AND status <> 'archived'
                          ORDER BY agent_name")->fetch_all(MYSQLI_ASSOC);

$codes = $changes = $recent = [];
$taken = [];
if ($ready) {
    $where = $filter ? 'WHERE q.intake_id = ' . $filter : '';
    $tcol  = $has_team ? ', mi.entity_type' : '';
    $codes = $conn->query("SELECT q.*, mi.agent_name, mi.slug, mi.is_active AS agent_active, mi.status AS agent_status{$tcol}
                             FROM qr_codes q
                        LEFT JOIN marketing_intakes mi ON mi.id = q.intake_id
                             {$where}
                         ORDER BY mi.agent_name IS NULL, mi.agent_name, q.created_at")->fetch_all(MYSQLI_ASSOC);

    $taken = array_column($conn->query("SELECT code FROM qr_codes")->fetch_all(MYSQLI_ASSOC), 'code');

    if ($codes) {
        $ids   = implode(',', array_map('intval', array_column($codes, 'id')));
        $since = (new DateTime(qr_today()))->modify('-29 days')->format('Y-m-d');
        foreach ($conn->query("SELECT qr_id, SUM(scans) n FROM qr_scans_daily
                                WHERE qr_id IN ({$ids}) AND scan_date >= '{$since}' GROUP BY qr_id") as $r) {
            $recent[(int) $r['qr_id']] = (int) $r['n'];
        }
        foreach ($conn->query("SELECT c.*, u.first_name, u.last_name FROM qr_code_changes c
                            LEFT JOIN users u ON u.id = c.changed_by
                                WHERE c.qr_id IN ({$ids}) ORDER BY c.changed_at DESC, c.id DESC") as $r) {
            $k = (int) $r['qr_id'];
            if (count($changes[$k] ?? []) < 10) $changes[$k][] = $r;
        }
    }
}
$conn->close();
// ── Nothing below this line may touch the database. ─────────────────────────

function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
function mt(?string $utc, string $fmt = 'M j, Y g:ia'): string {
    if (!$utc) return '';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('America/Denver'))->format($fmt);
}

// Group for display: one card per broker, office codes last.
$groups = [];
foreach ($codes as $c) {
    $k = $c['intake_id'] ? (int) $c['intake_id'] : 0;
    $groups[$k]['name'] ??= $c['intake_id'] ? ($c['agent_name'] ?: 'Broker #' . $c['intake_id']) : 'Office (no broker)';
    $groups[$k]['id']     = $k;
    $groups[$k]['rows'][] = $c;
}
$counts = ['live' => 0, 'paused' => 0];
foreach ($codes as $c) $counts[(int) $c['is_active'] ? 'live' : 'paused']++;

$filter_name = '';
foreach ($brokers as $b) if ((int) $b['id'] === $filter) $filter_name = $b['agent_name'];

$d_iid  = (int) ($draft['intake_id'] ?? $filter);
$d_type = ($draft['dest_type'] ?? '') === 'url' ? 'url' : 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>QR Codes | Mont Haus Marketing</title>
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
    .hdr-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .wrap { max-width:1100px; margin:32px auto 60px; padding:0 20px; }
    .mk-page-header { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .card { background:#fff; border-radius:6px; padding:22px 26px; margin-bottom:18px; }
    .card-title { font-size:14px; font-weight:700; margin:0 0 16px; display:flex; align-items:center; gap:8px; }
    .card-title a { text-decoration:none; }
    .form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px; }
    .form-row > div { min-width:0; }
    .form-input, select.form-input { padding:8px 10px; border:1px solid #d1d5db; border-radius:4px; font-size:14px; width:100%; }
    .fld-label { display:block; font-size:12px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; margin-bottom:4px; }
    .prefix { display:flex; align-items:stretch; }
    .prefix span { display:flex; align-items:center; padding:0 10px; background:#f5f3ee; border:1px solid #d1d5db; border-right:0;
                   border-radius:4px 0 0 4px; font-size:13px; color:#6b7280; white-space:nowrap; }
    .prefix .form-input { border-radius:0 4px 4px 0; }
    .radio-row { display:flex; flex-direction:column; gap:6px; margin:4px 0 10px; font-size:14px; }
    .radio-row label { display:flex; gap:8px; align-items:center; }
    .radio-row label.disabled { color:#9ca3af; }
    .flash { padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:14px; }
    .flash.ok  { background:#f0fdf4; color:#15803d; }
    .flash.err { background:#fef2f2; color:#b91c1c; }
    .note { background:#fbf7ec; border:1px solid #e3dccb; padding:12px 14px; font-size:13px; color:#5c4a28; margin-bottom:18px; }
    .hint { font-size:12px; color:#9ca3af; }
    .pill { display:inline-block; font-size:11px; padding:2px 9px; border-radius:10px; white-space:nowrap; }
    .pill.live   { background:#f0fdf4; color:#15803d; }
    .pill.paused { background:#fff7ed; color:#c2410c; }
    .warn { color:#b45309; font-size:12px; margin-top:4px; }

    .qr-row { display:grid; grid-template-columns:96px 1fr auto; gap:18px; padding:16px 0; border-top:1px solid #f1ece0; align-items:start; }
    .qr-row:first-of-type { border-top:0; padding-top:0; }
    .qr-row:target { background:#fdf6e3; box-shadow:0 0 0 10px #fdf6e3; border-radius:4px; }
    .qr-img { width:96px; height:96px; background:#fff; border:1px solid #eee; border-radius:4px; }
    .qr-img svg { width:100%; height:100%; display:block; }
    .qr-url { font-size:15px; font-weight:600; word-break:break-all; }
    .qr-url button { border:0; background:none; color:#75BDB6; cursor:pointer; padding:0 4px; font-size:15px; vertical-align:-2px; }
    .qr-label { font-size:13px; color:#374151; margin:2px 0 6px; }
    .qr-dest { font-size:13px; color:#374151; word-break:break-all; }
    .qr-dest i { color:#9ca3af; }
    .qr-meta { font-size:12px; color:#6b7280; margin-top:6px; }
    .qr-actions { display:flex; flex-direction:column; gap:6px; align-items:stretch; min-width:130px; }
    .qr-actions form { margin:0; }
    .qr-actions .btn { width:100%; }
    .qr-actions .qr-del { color:#b91c1c; border-color:#fecaca; }
    .btn-xs { padding:4px 10px; font-size:11px; line-height:1.3; white-space:nowrap; }
    details.qr-more { margin-top:10px; }
    details.qr-more summary { cursor:pointer; font-size:12px; color:#75BDB6; font-weight:600; display:inline-block; margin-right:14px; }
    .qr-edit { background:#fafaf7; border:1px solid #eee; border-radius:6px; padding:14px 16px; margin-top:8px; }
    .hist { width:100%; border-collapse:collapse; font-size:12px; margin-top:8px; }
    .hist td { padding:4px 8px 4px 0; border-top:1px solid #f1ece0; vertical-align:top; word-break:break-all; }
    .hist td:first-child { white-space:nowrap; color:#6b7280; word-break:normal; }
    .hist td:last-child  { white-space:nowrap; word-break:normal; color:#6b7280; }
    @media (max-width:720px) {
      .qr-row { grid-template-columns:72px 1fr; }
      .qr-img { width:72px; height:72px; }
      .qr-actions { grid-column:1 / -1; flex-direction:row; flex-wrap:wrap; }
      .qr-actions .btn { width:auto; }
      .card { padding:18px 16px; }
    }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = 'marketing'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

    <div class="mk-page-header">
      <div>
        <h1>QR Codes</h1>
        <p class="hint" style="margin:4px 0 0;">
          <?php if ($ready): ?>
            <?= $counts['live'] ?> live &middot; <?= $counts['paused'] ?> paused<?= $filter_name !== '' ? ' &middot; ' . e($filter_name) : '' ?>
          <?php endif; ?>
        </p>
      </div>
      <div class="hdr-actions">
        <form method="GET" style="margin:0;">
          <select name="intake" class="form-input" style="width:auto;min-width:200px;" onchange="this.form.submit()">
            <option value="0">Every broker</option>
            <?php foreach ($brokers as $b): ?>
              <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $filter ? 'selected' : '' ?>><?= e($b['agent_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php if ($filter): ?>
          <a class="btn btn-outline btn-sm" href="agent.php?id=<?= $filter ?>&tab=profile"><i class="ti ti-user"></i> Their page</a>
        <?php endif; ?>
        <a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>
      </div>
    </div>

    <?php if ($flash): ?><div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>

    <?php if (!$ready): ?>
      <div class="note">
        <strong>Not set up yet.</strong> Run <code>sql/qr_codes_v1.sql</code> in TablePlus, then reload this page.
        Until then <?= e(QR_BASE_URL) ?> sends every scan to <?= e(QR_FALLBACK_URL) ?>.
      </div>
    <?php else: ?>

    <!-- ── New code ───────────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-title"><i class="ti ti-qrcode"></i> New QR code</div>
      <form method="POST" id="newForm" autocomplete="off">
        <input type="hidden" name="_action" value="create">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="back_intake" value="<?= $filter ?>">
        <div class="form-row">
          <div style="flex:1 1 240px;">
            <span class="fld-label">Broker</span>
            <select name="intake_id" id="newBroker" class="form-input">
              <option value="0" data-slug="" data-team="0">Office (no broker)</option>
              <?php foreach ($brokers as $b): $team = ($b['entity_type'] ?? '') === 'team'; ?>
                <option value="<?= (int) $b['id'] ?>" data-slug="<?= e($b['slug']) ?>" data-team="<?= $team ? 1 : 0 ?>"
                        <?= (int) $b['id'] === $d_iid ? 'selected' : '' ?>><?= e($b['agent_name']) ?><?= $team ? ' (team)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1 1 280px;">
            <span class="fld-label">Code</span>
            <div class="prefix"><span><?= e(preg_replace('~^https://~', '', QR_BASE_URL)) ?>/</span>
              <input type="text" name="code" id="newCode" class="form-input" maxlength="40" required
                     pattern="[a-z0-9][a-z0-9\-]*[a-z0-9]" placeholder="jboxer" value="<?= e($draft['code'] ?? '') ?>"></div>
            <span class="hint" id="codeSize" style="display:block;margin-top:4px;">Shorter codes print with bigger squares and scan from further away. 10 characters or fewer is best for signs.</span>
          </div>
          <div style="flex:1 1 200px;">
            <span class="fld-label">Label</span>
            <input type="text" name="label" class="form-input" maxlength="120" placeholder="Yard sign" value="<?= e($draft['label'] ?? '') ?>">
          </div>
        </div>
        <span class="fld-label">Opens</span>
        <div class="radio-row" data-dest>
          <label data-profile-label><input type="radio" name="dest_type" value="profile" <?= $d_type === 'profile' ? 'checked' : '' ?>>
            The broker's profile page <span class="hint" data-profile-preview></span></label>
          <label><input type="radio" name="dest_type" value="url" <?= $d_type === 'url' ? 'checked' : '' ?>> A specific page on <?= e(QR_ALLOWED_DOMAIN) ?></label>
          <input type="url" name="dest_url" class="form-input" data-url-input placeholder="https://monthaus.com/listing/..."
                 value="<?= e($draft['dest_url'] ?? '') ?>" style="max-width:640px;">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Create code</button>
        <p class="hint" style="margin:10px 0 0;">
          The code is what gets printed, so it can never be changed or reused afterwards. Short and readable is best:
          the broker's web address for their main code, then something like <code>-open-house</code> for others.
          Where it opens can be changed at any time.
        </p>
      </form>
    </div>

    <!-- ── The codes ──────────────────────────────────────────────────────── -->
    <?php if (!$codes): ?>
      <div class="card"><p class="hint" style="margin:0;">No QR codes <?= $filter ? 'for ' . e($filter_name ?: 'this broker') . ' ' : '' ?>yet.</p></div>
    <?php endif; ?>

    <?php foreach ($groups as $g): ?>
      <div class="card">
        <div class="card-title">
          <?php if ($g['id']): ?>
            <a href="agent.php?id=<?= $g['id'] ?>&tab=profile"><?= e($g['name']) ?></a>
          <?php else: ?><?= e($g['name']) ?><?php endif; ?>
          <span class="hint" style="font-weight:400;"><?= count($g['rows']) ?> code<?= count($g['rows']) === 1 ? '' : 's' ?></span>
        </div>

        <?php foreach ($g['rows'] as $c):
          $qid    = (int) $c['id'];
          $url    = qr_public_url($c['code']);
          $live   = (int) $c['is_active'] === 1;
          $target = qr_resolve($c);
          $team   = ($c['entity_type'] ?? '') === 'team';
          $warn   = '';
          if ($live && $target === null) {
              $warn = $c['dest_type'] === 'profile'
                  ? 'This broker has no web address, so scans go to ' . QR_FALLBACK_URL . '. Set it on their page or choose a specific page.'
                  : 'The saved address no longer passes the rules, so scans go to ' . QR_FALLBACK_URL . '.';
          } elseif ($c['intake_id'] && $c['dest_type'] === 'profile'
                    && (!(int) $c['agent_active'] || $c['agent_status'] === 'archived')) {
              $warn = 'This broker is archived, so their profile page may be gone. Repoint or pause this code.';
          }
          $shown = $c['dest_type'] === 'profile' ? ($target ?? '') : (string) $c['dest_url'];
        ?>
          <div class="qr-row" id="qr-<?= $qid ?>">
            <div class="qr-img" data-qr="<?= e($url) ?>" title="<?= e($url) ?>"></div>

            <div style="min-width:0;">
              <div class="qr-url">
                <?= e(preg_replace('~^https://~', '', $url)) ?>
                <button type="button" data-copy="<?= e($url) ?>" title="Copy the address"><i class="ti ti-copy"></i></button>
              </div>
              <div class="qr-label">
                <?= $c['label'] !== '' ? e($c['label']) : '<span class="hint">No label</span>' ?>
                &nbsp;<span class="pill <?= $live ? 'live' : 'paused' ?>"><?= $live ? 'Live' : 'Paused' ?></span>
              </div>
              <div class="qr-dest">
                <i class="ti ti-arrow-right"></i>
                <?php if (!$live): ?>
                  <span class="hint">Paused: scans go to <?= e(QR_FALLBACK_URL) ?>. When resumed it opens</span>
                <?php endif; ?>
                <?php if ($c['dest_type'] === 'profile'): ?>
                  Profile page<?php if ($shown !== ''): ?>
                    &middot; <a href="<?= e($shown) ?>" target="_blank" rel="noopener"><?= e(preg_replace('~^https://~', '', $shown)) ?></a><?php endif; ?>
                <?php else: ?>
                  <a href="<?= e($shown) ?>" target="_blank" rel="noopener"><?= e(preg_replace('~^https://~', '', $shown)) ?></a>
                <?php endif; ?>
              </div>
              <?php if ($warn !== ''): ?><div class="warn"><i class="ti ti-alert-triangle"></i> <?= e($warn) ?></div><?php endif; ?>
              <div class="qr-meta">
                <?= number_format((int) $c['scan_count']) ?> scan<?= (int) $c['scan_count'] === 1 ? '' : 's' ?>
                &middot; <?= number_format($recent[$qid] ?? 0) ?> in the last 30 days
                <?php if ($c['last_scan_at']): ?>&middot; last <?= e(mt($c['last_scan_at'])) ?><?php endif; ?>
              </div>
              <div class="qr-meta" data-density></div>

              <details class="qr-more">
                <summary>Change</summary>
                <form method="POST" class="qr-edit">
                  <input type="hidden" name="_action" value="update">
                  <input type="hidden" name="id" value="<?= $qid ?>">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="back_intake" value="<?= $filter ?>">
                  <div class="form-row">
                    <div style="flex:1 1 220px;">
                      <span class="fld-label">Broker</span>
                      <select name="intake_id" class="form-input" data-broker>
                        <option value="0" data-slug="" data-team="0">Office (no broker)</option>
                        <?php $listed = false; foreach ($brokers as $b): $bt = ($b['entity_type'] ?? '') === 'team';
                          if ((int) $b['id'] === (int) $c['intake_id']) $listed = true; ?>
                          <option value="<?= (int) $b['id'] ?>" data-slug="<?= e($b['slug']) ?>" data-team="<?= $bt ? 1 : 0 ?>"
                                  <?= (int) $b['id'] === (int) $c['intake_id'] ? 'selected' : '' ?>><?= e($b['agent_name']) ?><?= $bt ? ' (team)' : '' ?></option>
                        <?php endforeach; ?>
                        <?php if ($c['intake_id'] && !$listed): // archived broker: keep them selectable so saving does not unassign ?>
                          <option value="<?= (int) $c['intake_id'] ?>" data-slug="<?= e($c['slug']) ?>" data-team="<?= $team ? 1 : 0 ?>" selected>
                            <?= e($c['agent_name'] ?: 'Broker #' . $c['intake_id']) ?> (archived)</option>
                        <?php endif; ?>
                      </select>
                    </div>
                    <div style="flex:1 1 200px;">
                      <span class="fld-label">Label</span>
                      <input type="text" name="label" class="form-input" maxlength="120" value="<?= e($c['label']) ?>">
                    </div>
                  </div>
                  <span class="fld-label">Opens</span>
                  <div class="radio-row" data-dest>
                    <label data-profile-label><input type="radio" name="dest_type" value="profile" <?= $c['dest_type'] === 'profile' ? 'checked' : '' ?>>
                      The broker's profile page <span class="hint" data-profile-preview></span></label>
                    <label><input type="radio" name="dest_type" value="url" <?= $c['dest_type'] === 'url' ? 'checked' : '' ?>> A specific page on <?= e(QR_ALLOWED_DOMAIN) ?></label>
                    <input type="url" name="dest_url" class="form-input" data-url-input placeholder="https://monthaus.com/listing/..."
                           value="<?= e($c['dest_url'] ?? '') ?>" style="max-width:640px;">
                  </div>
                  <button type="submit" class="btn btn-primary btn-sm">Save</button>
                  <span class="hint" style="margin-left:8px;">The printed code stays the same.</span>
                </form>
              </details>
              <details class="qr-more">
                <summary>History</summary>
                <table class="hist">
                  <?php foreach ($changes[$qid] ?? [] as $h):
                    $who = trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')); ?>
                    <tr>
                      <td><?= e(mt($h['changed_at'], 'M j, Y')) ?></td>
                      <td><?= e(ucfirst($h['what'])) ?><?php if ($h['old_value'] !== null && $h['what'] !== 'created'): ?>:
                          <?= e($h['old_value'] !== '' ? $h['old_value'] : '(blank)') ?> &rarr;<?php elseif ($h['what'] === 'created'): ?>:<?php endif; ?>
                          <?= e($h['new_value'] ?? '') ?></td>
                      <td><?= e($who) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($changes[$qid])): ?><tr><td colspan="3" class="hint">Nothing recorded.</td></tr><?php endif; ?>
                </table>
              </details>
            </div>

            <div class="qr-actions">
              <button type="button" class="btn btn-outline btn-xs" data-dl="svg" data-code="<?= e($c['code']) ?>" data-url="<?= e($url) ?>"><i class="ti ti-download"></i> SVG (print)</button>
              <button type="button" class="btn btn-outline btn-xs" data-dl="png" data-code="<?= e($c['code']) ?>" data-url="<?= e($url) ?>"><i class="ti ti-download"></i> PNG</button>
              <a class="btn btn-outline btn-xs" href="<?= e($url) ?>" target="_blank" rel="noopener" title="Opens the code as a phone would. Counts as a scan."><i class="ti ti-external-link"></i> Test</a>
              <form method="POST">
                <input type="hidden" name="_action" value="<?= $live ? 'pause' : 'resume' ?>">
                <input type="hidden" name="id" value="<?= $qid ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="back_intake" value="<?= $filter ?>">
                <?php if ($live): ?>
                  <button type="submit" class="btn btn-outline btn-xs" onclick="return confirm('Pause <?= e($c['code']) ?>? Scans will go to <?= e(QR_FALLBACK_URL) ?> until you resume it.');"><i class="ti ti-player-pause"></i> Pause</button>
                <?php else: ?>
                  <button type="submit" class="btn btn-primary btn-xs"><i class="ti ti-player-play"></i> Resume</button>
                <?php endif; ?>
              </form>
              <?php if ((int) $c['scan_count'] === 0): ?>
                <form method="POST">
                  <input type="hidden" name="_action" value="delete">
                  <input type="hidden" name="id" value="<?= $qid ?>">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="back_intake" value="<?= $filter ?>">
                  <button type="submit" class="btn btn-outline btn-xs qr-del" title="Only codes that have never been scanned can be deleted"
                          onclick="return confirm('Delete <?= e($c['code']) ?> for good? It has never been scanned. Its history goes with it, and the name can be used again.');"><i class="ti ti-trash"></i> Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <p class="hint">
      A code that has been scanned is never deleted, because it is printed somewhere. To retire one, pause it; to reuse one, repoint it and resume.
      A code with no scans yet can be deleted. The Test link counts as a scan.
      Every scan is sent on with <code>utm_source=qr</code> and the code as <code>utm_campaign</code>, so visits show up in the website's analytics.
      Every code is drawn with the Mont Haus triangle in the centre (codes longer than 34 characters are too dense for it and are drawn plain).
      Each download is scanned on this page before it is saved.
    </p>

    <?php endif; ?>
  </div>
</div>

<?php if ($ready): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script src="assets/js/mh-qr.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/mh-qr.js') ?>"></script>
<script>
(function () {
  var PROFILE_BASE = <?= json_encode(rtrim(defined('PUBLIC_SITE_URL') ? PUBLIC_SITE_URL : QR_FALLBACK_URL, '/') . '/broker.php?s=') ?>;
  var TAKEN = <?= json_encode(array_values($taken)) ?>;

  // ── QR drawing: assets/js/mh-qr.js, checked by tests/qr_marks.html ──────
  // Every code is drawn with the triangle (Nikki, 2026-10-01: the printed
  // signs use it, so the portal and this page must show the same picture).
  // Error correction H with the mark; a code too long for it (over 34
  // characters, QR version 7+) is drawn plain. The encoded address is the same
  // either way, so the choice is artwork only. mh-qr.js still supports the M.
  var ready = !!(window.qrcode && window.MHQR), mark = 'triangle';
  function markFor(text) { return mark && MHQR.canMark(text) ? mark : ''; }
  // "33 squares across": the module count, quiet zone excluded. What decides
  // how far away a printed code still scans; fewer is better.
  function density(text) {
    var plain = MHQR.layout(text, '').n, marked = MHQR.canMark(text) ? MHQR.layout(text, 'triangle').n : null;
    return { plain: plain, marked: marked };
  }
  function draw() {
    if (!ready) return;
    document.querySelectorAll('[data-qr]').forEach(function (el) {
      var t = el.dataset.qr, m = markFor(t);
      el.innerHTML = MHQR.svg(t, m);
      el.title = t + (!m ? ' (too long for the triangle: drawn plain)' : '');
      var dn = el.closest('.qr-row') && el.closest('.qr-row').querySelector('[data-density]');
      if (dn) {
        var d = density(t);
        dn.textContent = m ? d.marked + ' squares across' : d.plain + ' squares across (too long for the triangle, drawn plain)';
      }
    });
  }
  function save(blob, name) {
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }

  if (ready) draw();
  else document.querySelectorAll('[data-qr]').forEach(function (el) { el.innerHTML = '<span class="hint" style="padding:6px;display:block;">QR library did not load</span>'; });

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-dl]');
    if (b) {
      if (!ready) { alert('The QR library did not load. Check the connection and reload.'); return; }
      var t = b.dataset.url, m = markFor(t);
      if (!m && !confirm('This code is too long for the triangle (over 34 characters). Download it plain?')) return;
      // Scan our own drawing before handing it over. A code with a mark is
      // never saved unchecked; a plain one may be if the checker is missing,
      // since that is the drawing this page has always produced.
      var ok = MHQR.verify(t, m);
      if (ok === false) { alert('This code did not scan back correctly, so it was not downloaded. Please tell Nikki.'); return; }
      if (ok === null && m) { alert('The scan check did not load, so a code with a mark cannot be checked. Reload the page, or choose None.'); return; }
      var name = 'monthaus-qr-' + b.dataset.code + (m ? '-' + m : '');
      if (b.dataset.dl === 'svg') save(new Blob([MHQR.svg(t, m)], { type: 'image/svg+xml' }), name + '.svg');
      else MHQR.canvas(t, m, Math.max(1, Math.floor(2400 / MHQR.layout(t, m).size))).toBlob(function (blob) { save(blob, name + '.png'); }, 'image/png');
      return;
    }
    var cp = e.target.closest('[data-copy]');
    if (cp && navigator.clipboard) {
      navigator.clipboard.writeText(cp.dataset.copy).then(function () {
        var i = cp.querySelector('i'); i.className = 'ti ti-check'; setTimeout(function () { i.className = 'ti ti-copy'; }, 1200);
      });
    }
  });

  // ── Destination controls (new form and every Change form) ───────────────
  function wire(form) {
    var sel = form.querySelector('select[name="intake_id"]'), box = form.querySelector('[data-dest]');
    if (!sel || !box) return;
    var prof = box.querySelector('input[value="profile"]'), url = box.querySelector('input[value="url"]');
    var urlIn = box.querySelector('[data-url-input]'), plab = box.querySelector('[data-profile-label]');
    var prev = box.querySelector('[data-profile-preview]');
    function sync(fromBroker) {
      var o = sel.options[sel.selectedIndex], slug = o.dataset.slug || '', team = o.dataset.team === '1';
      var can = slug !== '' && !team;
      prof.disabled = !can; plab.classList.toggle('disabled', !can);
      prev.textContent = can ? '(' + (PROFILE_BASE + encodeURIComponent(slug)).replace(/^https:\/\//, '') + ')'
                        : (team ? '(teams have no profile page)' : (sel.value === '0' ? '(pick a broker)' : '(no web address set)'));
      if (!can && prof.checked) url.checked = true;
      if (can && fromBroker && urlIn.value === '') prof.checked = true;
      urlIn.style.display = url.checked ? '' : 'none';
      urlIn.required = url.checked;
    }
    sel.addEventListener('change', function () { sync(true); });
    [prof, url].forEach(function (r) { r.addEventListener('change', function () { sync(false); if (url.checked) urlIn.focus(); }); });
    sync(false);
  }
  document.querySelectorAll('#newForm, form.qr-edit').forEach(wire);

  // ── Suggest a code from the broker's web address ────────────────────────
  var nb = document.getElementById('newBroker'), nc = document.getElementById('newCode');
  var touched = nc.value !== '';
  nc.addEventListener('input', function () {
    touched = nc.value !== '';
    var v = nc.value.toLowerCase().replace(/[\s_]+/g, '-').replace(/[^a-z0-9-]/g, '');
    if (v !== nc.value) nc.value = v;
    nc.setCustomValidity(TAKEN.indexOf(v) >= 0 ? 'That code is already taken.' : '');
    sizeHint();
  });
  var cs = document.getElementById('codeSize'), csDefault = cs ? cs.textContent : '';
  function sizeHint() {
    if (!cs || !ready) return;
    var v = nc.value;
    if (!v) { cs.textContent = csDefault; return; }
    var d = density(<?= json_encode(rtrim(QR_BASE_URL, '/') . '/') ?> + v);
    cs.textContent = v.length + ' character' + (v.length === 1 ? '' : 's') + ': '
      + (d.marked ? d.marked + ' squares across' : d.plain + ' squares across, too long for the triangle')
      + (v.length > 10 ? '. 10 or fewer is best for signs.' : '.');
  }
  function suggest() {
    if (touched) return;
    var slug = nb.options[nb.selectedIndex].dataset.slug || '';
    if (!slug) { nc.value = ''; return; }
    var c = slug, i = 2;
    while (TAKEN.indexOf(c) >= 0) c = slug + '-' + (i++);
    nc.value = c;
  }
  nb.addEventListener('change', function () { suggest(); sizeHint(); });
  suggest();
  sizeHint();
})();
</script>
<?php endif; ?>
</body>
</html>
