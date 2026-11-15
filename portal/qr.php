<?php
/**
 * portal/qr.php — an agent's QR codes, and where each one goes.
 * docs/AGENT_PORTAL_PLAN.md, section 4, decision 7 (2026-10-01).
 *
 * Deliberately tiny next to the admin qr_codes.php: the agent can change a
 * code's destination and REQUEST a new code (?request=1: project name,
 * description, destination; saved in marketing_requests, then emailed to
 * PORTAL_MARKETING_EMAIL with Reply-To the agent). Nothing else. No creating, pausing, resuming,
 * deleting or renaming; a paused code is shown as paused and is not editable.
 *
 * Codes shown: qr_codes.intake_id IN portal_context()['ids'] (their account,
 * plus a team's members), and since 2026-10-26 any code SHARED with one of
 * those ids (qr_code_shares, set on the admin page: Kimberlee Coates's open
 * house code on Jackson Horn's portal too). qr_scope_sql() in inc/qr.php is
 * the one clause for both. A code id arriving in ?edit= or a POST is only
 * ever used after checking it is one of those (portal_qr_owned()).
 *
 * A destination is either the owner's profile page on the public site
 * (dest_type 'profile', resolved at scan time, so it follows go-live) or a
 * page on monthaus.com (dest_type 'url', checked with qr_dest_error(), the same
 * rule the admin page and qr.php use). Every change is logged in
 * qr_code_changes with the signed-in user's id, as the admin page does.
 *
 * Since 2026-11-15 (Nikki) the change is made IN the code's card on the list:
 * a dropdown (My Profile, My Listings, Custom URL) and, for Custom URL, a
 * text box. There is no separate edit page any more (?edit= shows the list).
 * My Listings is the owner's monthaus.com/{slug}-listings page, stored as a
 * 'url' destination (qr_listings_url()); a card shows it selected when the
 * stored address is exactly that one. The form posts `dest` (profile |
 * listings | custom); the older `dest_type` (profile | url) is still read.
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/qr.php';

$ctx = portal_context($conn);
if (!mk_table_exists($conn, 'qr_codes')) {
    portal_header($ctx, 'QR codes', 'qr');
    echo '<h1 class="pt-h1">QR codes</h1><p class="pt-lead">You do not have any QR codes yet.</p>';
    portal_footer();
    exit;
}

$in = implode(',', array_map('intval', $ctx['ids']));   // from portal_context, never the request
$acct_id = (int)$ctx['acct']['id'];

/** The code with this id, only if it belongs to this portal (owned, or shared with it). */
function portal_qr_owned(mysqli $conn, string $in, int $qid): ?array {
    if ($qid <= 0) return null;
    $r = $conn->query("SELECT q.*, mi.agent_name, mi.slug, " . (mk_column_exists($conn, 'marketing_intakes', 'entity_type') ? 'mi.entity_type' : "'agent' AS entity_type") . "
                         FROM qr_codes q LEFT JOIN marketing_intakes mi ON mi.id = q.intake_id
                        WHERE q.id = {$qid} AND " . qr_scope_sql($conn, $in) . " LIMIT 1");
    return $r ? ($r->fetch_assoc() ?: null) : null;
}

/** True when the code is on this portal because it was shared, not owned (or a team's). */
function portal_qr_is_shared(array $c, array $ctx): bool {
    return !in_array((int)$c['intake_id'], array_map('intval', $ctx['ids']), true);
}

/** True when the code's owner has a profile page it can point at. */
function portal_qr_can_profile(array $c): bool {
    return ($c['entity_type'] ?? 'agent') !== 'team' && trim((string)($c['slug'] ?? '')) !== '';
}

/** The dropdown choice a code's stored destination is: profile, listings or custom. */
function portal_qr_choice(array $c): string {
    if (($c['dest_type'] ?? '') === 'profile') return portal_qr_can_profile($c) ? 'profile' : 'custom';
    return portal_qr_can_profile($c) && rtrim((string)($c['dest_url'] ?? ''), '/') === qr_listings_url((string)$c['slug']) ? 'listings' : 'custom';
}

/** "Your profile page", "Jonathan's listings page", or the address. */
function portal_qr_dest_words(array $c, int $acct_id): string {
    $choice = portal_qr_choice($c);
    if (($c['dest_type'] ?? '') === 'profile' || $choice === 'listings') {
        $what = $choice === 'listings' ? 'listings page' : 'profile page';
        if ((int)$c['intake_id'] === $acct_id) return "Your {$what} on monthaus.com";
        return (strtok(trim((string)$c['agent_name']), ' ') ?: 'Their') . "'s {$what} on monthaus.com";
    }
    $u = (string)($c['dest_url'] ?? '');
    return $u !== '' ? preg_replace('~^https?://~', '', $u) : 'Nowhere yet';
}

// ── A request for a new code ────────────────────────────────────────────────
// Saved first (when marketing_requests exists), then emailed, so a failed email
// never loses it. If the email fails the agent is told to write in directly.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
    $f = [
        'title'       => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200),
        'details'     => mb_substr(trim((string)($_POST['details'] ?? '')), 0, 4000),
        'destination' => mb_substr(trim((string)($_POST['destination'] ?? '')), 0, 1000),
    ];
    if (!portal_csrf_ok()) {
        $_SESSION['pt_qr_flash'] = ['err', 'That page had been open too long. Please try again.'];
        $_SESSION['pt_qr_req'] = $f;
        header('Location: ' . portal_url($ctx, '/portal/qr.php', ['request' => 1])); exit;
    }
    // The destination is the exact page address, copied from the browser
    // (Nikki, 2026-10-01), held to the same rule as any QR destination:
    // https, on monthaus.com. A pasted address missing its https:// gets it.
    if ($f['destination'] !== '' && !preg_match('~^https?://~i', $f['destination'])) $f['destination'] = 'https://' . $f['destination'];
    $derr = $f['destination'] === '' ? 'Paste the web address the code should open.' : qr_dest_error($f['destination']);
    if ($f['title'] === '' || $derr !== '') {
        $_SESSION['pt_qr_flash'] = ['err', $f['title'] === '' ? 'Please give the project a name.' : $derr];
        $_SESSION['pt_qr_req'] = $f;
        header('Location: ' . portal_url($ctx, '/portal/qr.php', ['request' => 1])); exit;
    }
    $u = $ctx['user'];
    $who = trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['email'];
    $rid = 0;
    if (mk_table_exists($conn, 'marketing_requests')) {
        $kind = 'qr_code'; $uidv = (int)$u['id'];
        $st = $conn->prepare("INSERT INTO marketing_requests (intake_id, user_id, kind, title, details, destination) VALUES (?, ?, ?, ?, ?, ?)");
        $st->bind_param('iissss', $acct_id, $uidv, $kind, $f['title'], $f['details'], $f['destination']);
        $st->execute();
        $rid = (int)$conn->insert_id;
        $st->close();
    }
    $e = fn($v) => nl2br(htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), false);
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;">'
          . '<p><strong>' . $e($who) . '</strong> (' . $e($ctx['acct']['agent_name']) . ') requested a QR code from the agent portal.</p>'
          . '<table style="border-collapse:collapse;font-size:14px;">'
          . '<tr><td style="padding:4px 12px 4px 0;color:#6b7280;vertical-align:top;">Project</td><td>' . $e($f['title']) . '</td></tr>'
          . '<tr><td style="padding:4px 12px 4px 0;color:#6b7280;vertical-align:top;">Description</td><td>' . ($f['details'] !== '' ? $e($f['details']) : '—') . '</td></tr>'
          . '<tr><td style="padding:4px 12px 4px 0;color:#6b7280;vertical-align:top;">Destination</td><td>' . $e($f['destination']) . '</td></tr>'
          . '<tr><td style="padding:4px 12px 4px 0;color:#6b7280;vertical-align:top;">From</td><td>' . $e($u['email']) . '</td></tr>'
          . '</table>'
          . '<p style="margin-top:18px;"><a href="' . htmlspecialchars(rtrim(SITE_URL, '/') . '/qr_codes.php?intake=' . $acct_id) . '" '
          . 'style="background:#2b3038;color:#fff;text-decoration:none;padding:10px 16px;border-radius:4px;display:inline-block;">Create it on the QR Codes page</a></p>'
          . '<p style="color:#6b7280;font-size:12px;">Reply to this email to reach ' . $e($who) . ' directly.' . ($rid ? " Request #{$rid}." : '') . '</p></div>';
    [$st_mail, $detail] = portal_send_mail('QR code request: ' . $f['title'] . ' (' . $ctx['acct']['agent_name'] . ')', $html, (string)$u['email'], $who);
    if ($rid) {
        $st = $conn->prepare("UPDATE marketing_requests SET email_status = ?, email_error = ? WHERE id = ?");
        $err = $st_mail === 'sent' ? null : mb_substr($detail, 0, 500);
        $st->bind_param('ssi', $st_mail, $err, $rid);
        $st->execute(); $st->close();
    }
    if ($st_mail === 'sent') {
        header('Location: ' . portal_url($ctx, '/portal/qr.php', ['sent' => 1])); exit;
    }
    $_SESSION['pt_qr_flash'] = ['err', 'Sorry, your request could not be sent just now. Please email it to ' . PORTAL_MARKETING_EMAIL . '.'];
    $_SESSION['pt_qr_req'] = $f;
    header('Location: ' . portal_url($ctx, '/portal/qr.php', ['request' => 1])); exit;
}

// ── Save ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qid = (int)($_POST['id'] ?? 0);
    $c   = portal_qr_owned($conn, $in, $qid);
    $msg = null;
    if (!portal_csrf_ok()) {
        $msg = ['err', 'That page had been open too long. Please try again.'];
    } elseif (!$c) {
        $msg = ['err', 'That QR code could not be found.'];
    } elseif (!(int)$c['is_active']) {
        $msg = ['err', 'That QR code is paused. Email ' . PORTAL_MARKETING_EMAIL . ' to turn it back on.'];
    } else {
        // The card's dropdown posts `dest`; a form from before 2026-11-15 posts dest_type.
        $dest = (string)($_POST['dest'] ?? '');
        if (!in_array($dest, ['profile', 'listings', 'custom'], true)) $dest = ($_POST['dest_type'] ?? '') === 'profile' ? 'profile' : 'custom';
        $type = $dest === 'profile' ? 'profile' : 'url';
        $url  = $dest === 'custom' ? trim((string)($_POST['dest_url'] ?? ''))
              : ($dest === 'listings' && portal_qr_can_profile($c) ? qr_listings_url((string)$c['slug']) : null);
        if ($dest === 'custom' && $url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
        $err  = $dest === 'custom' ? qr_dest_error((string)$url)
              : (portal_qr_can_profile($c) ? '' : 'There is no ' . ($dest === 'listings' ? 'listings' : 'profile') . ' page to point this code at.');
        if ($err !== '') {
            $msg = ['err', $err];
            $_SESSION['pt_qr_draft'] = ['id' => $qid, 'dest' => $dest, 'dest_url' => (string)$url];
        } else {
            $old = $c['dest_type'] === 'profile' ? 'Profile page' : (string)$c['dest_url'];
            $new = $type === 'profile' ? 'Profile page' : $url;
            if ($old === $new) {
                $msg = ['ok', 'No change: ' . ($c['label'] ?: $c['code']) . ' already goes there.'];
            } else {
                $st = $conn->prepare("UPDATE qr_codes SET dest_type = ?, dest_url = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?");
                $st->bind_param('ssi', $type, $url, $qid);
                $st->execute();
                $st->close();
                qr_log_change($conn, $qid, 'destination', $old, $new, (int)$ctx['user']['id']);
                $msg = ['ok', 'Saved. ' . ($c['label'] ?: $c['code']) . ' now goes to '
                      . portal_qr_dest_words(['dest_type' => $type, 'dest_url' => $url] + $c, $acct_id) . '.'];
            }
        }
    }
    $_SESSION['pt_qr_flash'] = $msg;
    header('Location: ' . portal_url($ctx, '/portal/qr.php'));
    exit;
}

$flash = $_SESSION['pt_qr_flash'] ?? null;
unset($_SESSION['pt_qr_flash']);
$draft = $_SESSION['pt_qr_draft'] ?? null;
unset($_SESSION['pt_qr_draft']);

$req_view  = isset($_GET['request']);
$sent_view = isset($_GET['sent']);
$req_draft = $_SESSION['pt_qr_req'] ?? ['title' => '', 'details' => '', 'destination' => ''];
unset($_SESSION['pt_qr_req']);

$codes = [];
if (!$req_view && !$sent_view) {
    $r = $conn->query("SELECT q.*, mi.agent_name, mi.slug, " . (mk_column_exists($conn, 'marketing_intakes', 'entity_type') ? 'mi.entity_type' : "'agent' AS entity_type") . "
                         FROM qr_codes q LEFT JOIN marketing_intakes mi ON mi.id = q.intake_id
                        WHERE " . qr_scope_sql($conn, $in) . "
                        ORDER BY (q.intake_id = {$acct_id}) DESC, mi.agent_name, q.label, q.code");
    $codes = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

portal_header($ctx, 'QR codes', 'qr');
if ($flash): ?>
  <div class="pt-flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= ph($flash[1]) ?></div>
<?php endif;

if ($sent_view): ?>
  <div class="pt-card" style="max-width:640px">
    <h1 class="pt-h1">Your request has been sent!</h1>
    <p class="pt-lead" style="margin-bottom:0">The marketing team will create your QR code and let you know when it is ready.
      Once it is, it will appear here and you can change where it goes at any time.</p>
    <div class="pt-row"><a class="pt-btn main" href="<?= ph(portal_url($ctx, '/portal/qr.php')) ?>">Back to QR codes</a></div>
  </div>
<?php elseif ($req_view): ?>
  <h1 class="pt-h1">QR Code Request</h1>
  <p class="pt-lead">Request a dynamic QR code for a marketing project.</p>
  <form method="post" action="<?= ph(portal_url($ctx, '/portal/qr.php')) ?>" class="pt-form" style="max-width:640px">
    <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="request">
    <label>Project name
      <input type="text" name="title" maxlength="200" required value="<?= ph($req_draft['title']) ?>" placeholder="e.g. 123 Main Street yard sign"></label>
    <label>Description
      <textarea name="details" rows="4" maxlength="4000" placeholder="What is it for, and where will it be printed?"><?= ph($req_draft['details']) ?></textarea></label>
    <label>Destination URL (where the QR code takes people)
      <input type="url" name="destination" inputmode="url" maxlength="1000" required value="<?= ph($req_draft['destination']) ?>" placeholder="https://monthaus.com/..."></label>
    <p class="pt-help" style="margin-top:-8px">Copy the exact web address from your browser's address bar and paste it here.</p>
    <p class="pt-help">*You can come back here to change the destination at any time.</p>
    <div class="pt-row">
      <button type="submit" class="pt-btn main">Send request</button>
      <a class="pt-btn quiet" href="<?= ph(portal_url($ctx, '/portal/qr.php')) ?>">Cancel</a>
    </div>
  </form>
<?php else: ?>
  <h1 class="pt-h1">Your QR codes</h1>
  <p class="pt-lead">Each code is printed on a sign or a card. You can change where it sends people at any time, without reprinting anything.</p>

  <?php if (!$codes): ?>
    <div class="pt-card"><p class="pt-card-sub" style="margin:0">You do not have any QR codes yet.</p>
      <a class="pt-btn main" style="margin-top:14px" href="<?= ph(portal_url($ctx, '/portal/qr.php', ['request' => 1])) ?>">Request a QR code</a></div>
  <?php endif; ?>

  <div class="pt-cards">
  <?php foreach ($codes as $c):
      $live = (int)$c['is_active'] === 1;
      $mine = (int)$c['intake_id'] === $acct_id;
      $n    = (int)$c['scan_count'];
  ?>
    <div class="pt-card pt-qr">
      <div class="pt-qr-img" data-qr="<?= ph(qr_public_url((string)$c['code'])) ?>"></div>
      <div>
        <p class="pt-qr-label"><?= ph($c['label'] ?: 'QR code') ?><?php if (!$live): ?><span class="pt-badge">Paused</span><?php endif; ?></p>
        <?php if (!$mine): ?><p class="pt-qr-owner"><?= ph($c['agent_name']) ?><?= portal_qr_is_shared($c, $ctx) ? ' · shared with you' : '' ?></p><?php endif; ?>
        <p class="pt-qr-goes"><span>Goes to</span><b><?= ph(portal_qr_dest_words($c, $acct_id)) ?></b></p>
        <p class="pt-qr-meta">Scanned <?= $n === 1 ? 'once' : number_format($n) . ' times' ?></p>
        <?php if ($live):
            // The change is made here, in the card (Nikki, 2026-11-15). What the agent
            // typed comes back after an error; else the stored destination is shown.
            $can    = portal_qr_can_profile($c);
            $mine_d = $draft && (int)$draft['id'] === (int)$c['id'];
            $choice = $mine_d ? (string)$draft['dest'] : portal_qr_choice($c);
            if (!$can) $choice = 'custom';
            $typed  = $mine_d ? (string)$draft['dest_url'] : ($choice === 'custom' ? (string)($c['dest_url'] ?? '') : '');
            $whose  = $mine ? 'My' : (strtok(trim((string)$c['agent_name']), ' ') ?: 'Their') . "'s";
            $fid    = 'qr' . (int)$c['id'];
        ?>
          <form method="post" action="<?= ph(portal_url($ctx, '/portal/qr.php')) ?>" class="pt-qr-form">
            <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <label class="pt-qr-lab" for="<?= $fid ?>">Change where it goes</label>
            <?php if ($can): ?>
            <select name="dest" id="<?= $fid ?>" data-qr-dest>
              <option value="profile"<?= $choice === 'profile' ? ' selected' : '' ?>><?= ph($whose) ?> Profile</option>
              <option value="listings"<?= $choice === 'listings' ? ' selected' : '' ?>><?= ph($whose) ?> Listings</option>
              <option value="custom"<?= $choice === 'custom' ? ' selected' : '' ?>>Custom URL</option>
            </select>
            <?php else: ?>
            <input type="hidden" name="dest" value="custom">
            <?php endif; ?>
            <input type="url" name="dest_url" inputmode="url" maxlength="1000" placeholder="https://monthaus.com/..." aria-label="Custom URL on monthaus.com"
                   value="<?= ph($typed) ?>"<?= $can ? '' : ' id="' . $fid . '"' ?><?= $choice === 'custom' ? '' : ' hidden' ?>>
            <button type="submit" class="pt-btn main">Save</button>
          </form>
        <?php else: ?>
          <p class="pt-help">Paused: scans go to the Mont Haus home page. Email <?= PORTAL_MARKETING_EMAIL ?> to turn it back on.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <?php if ($codes): ?>
  <script>
    // Custom URL shows the text box; My Profile and My Listings need none.
    document.querySelectorAll('[data-qr-dest]').forEach(function (sel) {
      var box = sel.form.querySelector('input[name=dest_url]');
      sel.addEventListener('change', function () { box.hidden = sel.value !== 'custom'; if (!box.hidden) box.focus(); });
    });
  </script>
  <p class="pt-help" style="margin-top:22px">Need another QR code? <a href="<?= ph(portal_url($ctx, '/portal/qr.php', ['request' => 1])) ?>">Request one ›</a></p>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
  <script src="/assets/js/mh-qr.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/mh-qr.js') ?>"></script>
  <script>
    // The triangle, as printed (same rule as qr_codes.php); plain only when a
    // code is too long for it.
    if (window.qrcode && window.MHQR) document.querySelectorAll('[data-qr]').forEach(function (el) {
      var t = el.dataset.qr; el.innerHTML = MHQR.svg(t, MHQR.canMark(t) ? 'triangle' : '');
    });
  </script>
  <?php endif; ?>
<?php endif;
portal_footer();
