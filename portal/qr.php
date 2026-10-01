<?php
/**
 * portal/qr.php — an agent's QR codes, and where each one goes.
 * docs/AGENT_PORTAL_PLAN.md, section 4, decision 7 (2026-10-01).
 *
 * Deliberately tiny next to the admin qr_codes.php: the agent can change a
 * code's destination and nothing else. No creating, pausing, resuming,
 * deleting or renaming; a paused code is shown as paused and is not editable.
 *
 * Codes shown: qr_codes.intake_id IN portal_context()['ids'] (their account,
 * plus a team's members). A code id arriving in ?edit= or a POST is only ever
 * used after checking it is one of those (portal_qr_owned()).
 *
 * A destination is either the owner's profile page on the public site
 * (dest_type 'profile', resolved at scan time, so it follows go-live) or a
 * page on monthaus.com (dest_type 'url', checked with qr_dest_error(), the same
 * rule the admin page and qr.php use). Every change is logged in
 * qr_code_changes with the signed-in user's id, as the admin page does.
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

/** The code with this id, only if it belongs to this portal. */
function portal_qr_owned(mysqli $conn, string $in, int $qid): ?array {
    if ($qid <= 0) return null;
    $r = $conn->query("SELECT q.*, mi.agent_name, mi.slug, " . (mk_column_exists($conn, 'marketing_intakes', 'entity_type') ? 'mi.entity_type' : "'agent' AS entity_type") . "
                         FROM qr_codes q LEFT JOIN marketing_intakes mi ON mi.id = q.intake_id
                        WHERE q.id = {$qid} AND q.intake_id IN ({$in}) LIMIT 1");
    return $r ? ($r->fetch_assoc() ?: null) : null;
}

/** True when the code's owner has a profile page it can point at. */
function portal_qr_can_profile(array $c): bool {
    return ($c['entity_type'] ?? 'agent') !== 'team' && trim((string)($c['slug'] ?? '')) !== '';
}

/** "Your profile page", "Jonathan's profile page", or the address. */
function portal_qr_dest_words(array $c, int $acct_id): string {
    if (($c['dest_type'] ?? '') === 'profile') {
        if ((int)$c['intake_id'] === $acct_id) return 'Your profile page on monthaus.com';
        return (strtok(trim((string)$c['agent_name']), ' ') ?: 'Their') . "'s profile page on monthaus.com";
    }
    $u = (string)($c['dest_url'] ?? '');
    return $u !== '' ? preg_replace('~^https?://~', '', $u) : 'Nowhere yet';
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
        $msg = ['err', 'That QR code is paused. Ask Nikki to turn it back on.'];
    } else {
        $type = ($_POST['dest_type'] ?? '') === 'profile' ? 'profile' : 'url';
        $url  = $type === 'url' ? trim((string)($_POST['dest_url'] ?? '')) : null;
        if ($type === 'url' && $url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
        $err  = $type === 'profile'
            ? (portal_qr_can_profile($c) ? '' : 'There is no profile page to point this code at.')
            : qr_dest_error((string)$url);
        if ($err !== '') {
            $msg = ['err', $err];
            $_SESSION['pt_qr_draft'] = ['id' => $qid, 'dest_type' => $type, 'dest_url' => (string)$url];
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
    $back = ($msg[0] ?? '') === 'err' && $c ? ['edit' => $qid] : [];
    header('Location: ' . portal_url($ctx, '/portal/qr.php', $back));
    exit;
}

$flash = $_SESSION['pt_qr_flash'] ?? null;
unset($_SESSION['pt_qr_flash']);
$draft = $_SESSION['pt_qr_draft'] ?? null;
unset($_SESSION['pt_qr_draft']);

$edit = isset($_GET['edit']) ? portal_qr_owned($conn, $in, (int)$_GET['edit']) : null;

$codes = [];
if (!$edit) {
    $r = $conn->query("SELECT q.*, mi.agent_name, mi.slug, " . (mk_column_exists($conn, 'marketing_intakes', 'entity_type') ? 'mi.entity_type' : "'agent' AS entity_type") . "
                         FROM qr_codes q LEFT JOIN marketing_intakes mi ON mi.id = q.intake_id
                        WHERE q.intake_id IN ({$in})
                        ORDER BY (q.intake_id = {$acct_id}) DESC, mi.agent_name, q.label, q.code");
    $codes = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

portal_header($ctx, 'QR codes', 'qr');
if ($flash): ?>
  <div class="pt-flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= ph($flash[1]) ?></div>
<?php endif;

if ($edit):
    $can_profile = portal_qr_can_profile($edit);
    $cur_type = $draft && (int)$draft['id'] === (int)$edit['id'] ? $draft['dest_type'] : $edit['dest_type'];
    $cur_url  = $draft && (int)$draft['id'] === (int)$edit['id'] ? $draft['dest_url'] : (string)($edit['dest_url'] ?? '');
    if (!$can_profile) $cur_type = 'url';
    $profile_url = $can_profile ? qr_profile_url((string)$edit['slug']) : '';
?>
  <h1 class="pt-h1">Where should “<?= ph($edit['label'] ?: $edit['code']) ?>” go?</h1>
  <p class="pt-lead">The printed code stays the same. Only where it sends people changes, straight away.</p>

  <?php if (!(int)$edit['is_active']): ?>
    <div class="pt-flash err">This QR code is paused. Ask Nikki to turn it back on.</div>
    <a class="pt-btn quiet" href="<?= ph(portal_url($ctx, '/portal/qr.php')) ?>">Back to your QR codes</a>
  <?php else: ?>
  <form method="post" action="<?= ph(portal_url($ctx, '/portal/qr.php')) ?>">
    <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <?php if ($can_profile): ?>
      <label class="pt-choice">
        <input type="radio" name="dest_type" value="profile" <?= $cur_type === 'profile' ? 'checked' : '' ?>>
        <b><?= (int)$edit['intake_id'] === $acct_id ? 'My profile page' : ph(strtok((string)$edit['agent_name'], ' ')) . '\'s profile page' ?></b>
        <small><?= ph(preg_replace('~^https?://~', '', $profile_url)) ?></small>
      </label>
    <?php endif; ?>
    <label class="pt-choice">
      <input type="radio" name="dest_type" value="url" <?= $cur_type === 'url' ? 'checked' : '' ?>>
      <b>A page on monthaus.com</b>
      <small>For example one of your listings. Copy its address from your browser and paste it here.</small>
      <input type="url" name="dest_url" inputmode="url" placeholder="https://monthaus.com/..." value="<?= ph($cur_type === 'url' ? $cur_url : '') ?>"
             onfocus="this.closest('label').querySelector('input[type=radio]').checked = true">
    </label>
    <div class="pt-row">
      <button type="submit" class="pt-btn main">Save</button>
      <a class="pt-btn quiet" href="<?= ph(portal_url($ctx, '/portal/qr.php')) ?>">Cancel</a>
    </div>
  </form>
  <?php endif; ?>

<?php else: ?>
  <h1 class="pt-h1">Your QR codes</h1>
  <p class="pt-lead">Each code is printed on a sign or a card. You can change where it sends people at any time, without reprinting anything.</p>

  <?php if (!$codes): ?>
    <div class="pt-card"><p class="pt-card-sub" style="margin:0">You do not have any QR codes yet. Ask Nikki if you need one.</p></div>
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
        <?php if (!$mine): ?><p class="pt-qr-owner"><?= ph($c['agent_name']) ?></p><?php endif; ?>
        <p class="pt-qr-goes"><span>Goes to</span><b><?= ph(portal_qr_dest_words($c, $acct_id)) ?></b></p>
        <p class="pt-qr-meta">Scanned <?= $n === 1 ? 'once' : number_format($n) . ' times' ?></p>
        <?php if ($live): ?>
          <a class="pt-btn main" style="margin-top:12px" href="<?= ph(portal_url($ctx, '/portal/qr.php', ['edit' => (int)$c['id']])) ?>">Change where it goes</a>
        <?php else: ?>
          <p class="pt-help">Paused: scans go to the Mont Haus home page. Ask Nikki to turn it back on.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <?php if ($codes): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
  <script src="/assets/js/mh-qr.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/mh-qr.js') ?>"></script>
  <script>
    if (window.qrcode && window.MHQR) document.querySelectorAll('[data-qr]').forEach(function (el) { el.innerHTML = MHQR.svg(el.dataset.qr, ''); });
  </script>
  <?php endif; ?>
<?php endif;
portal_footer();
