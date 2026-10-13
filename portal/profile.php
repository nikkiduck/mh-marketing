<?php
/**
 * portal/profile.php — My Profile (Nikki, 2026-10-12). What the website and
 * marketing hold about the agent, some of it editable here:
 *   read-only  name, email, title, headshot, MLS board identities, current listings
 *   editable   cell phone, bio (full + short), social handles, Hot Sheet subscription
 *   plus       a small form to contact marketing@
 * Every save goes through portal_change_alert(): marketing@ gets who changed
 * what, before and after, when; the agent page gets a note. The website
 * reads these fields from the roster feed within the hour, so a change here
 * is a change on monthaus.com.
 *
 * Scope: the portal account only (a login is a person's; a team row can be
 * previewed but not edited). Field rules are the admin page's: handles are
 * stored bare (no @, no URL), the bio keeps the same safe tags, blank = NULL.
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/portal_hotsheets.php';
require_once __DIR__ . '/../inc/boards.php';

$ctx  = portal_context($conn);
$acct = (int)$ctx['acct']['id'];
$back = fn(string $anchor) => portal_url($ctx, '/portal/profile.php') . '#' . $anchor;

$st = $conn->prepare("SELECT * FROM marketing_intakes WHERE id = ? LIMIT 1");
$st->bind_param('i', $acct); $st->execute();
$me = $st->get_result()->fetch_assoc() ?: [];
$st->close();

$SOCIAL = [
    'social_instagram' => ['Instagram', 'https://www.instagram.com/', ['https://www.instagram.com/', 'https://instagram.com/']],
    'social_facebook'  => ['Facebook',  'https://www.facebook.com/',  ['https://www.facebook.com/', 'https://facebook.com/', 'https://fb.com/']],
    'social_linkedin'  => ['LinkedIn',  'https://www.linkedin.com/in/', ['https://www.linkedin.com/in/', 'https://linkedin.com/in/', 'https://www.linkedin.com/company/', 'https://linkedin.com/company/']],
    'social_tiktok'    => ['TikTok',    'https://www.tiktok.com/@',   ['https://www.tiktok.com/@', 'https://tiktok.com/@', 'https://www.tiktok.com/', 'https://tiktok.com/']],
    'website_url'      => ['Website',   '',                           []],
];
const PORTAL_BIO_TAGS = '<p><br><strong><b><em><i><u><ol><ul><li><a><h3><h4><blockquote>';

/** Save changed fields, alert, flash. $fields: column => [label, new value]. */
function portal_profile_save(mysqli $conn, array $ctx, array $me, string $what, array $fields, string $flash_key): void {
    $changes = []; $sets = []; $vals = []; $types = '';
    foreach ($fields as $col => [$label, $new]) {
        $old = (string)($me[$col] ?? '');
        if ($old === $new) continue;
        $changes[] = [$label, $old, $new];
        $sets[] = "`{$col}` = ?"; $vals[] = $new === '' ? null : $new; $types .= 's';
    }
    if (!$sets) { $_SESSION[$flash_key] = ['ok', 'Nothing changed.']; return; }
    $vals[] = (int)$ctx['acct']['id']; $types .= 'i';
    $st = $conn->prepare("UPDATE marketing_intakes SET " . implode(', ', $sets) . " WHERE id = ?");
    $st->bind_param($types, ...$vals); $st->execute(); $st->close();
    portal_change_alert($conn, $ctx, $what, $changes);
    $_SESSION[$flash_key] = ['ok', 'Saved. Marketing has been told, and the website follows within the hour.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $key = 'pt_pf_' . preg_replace('/[^a-z]/', '', $action);
    $anchor = ['phone' => 'contact', 'bio' => 'bio', 'social' => 'social', 'contact' => 'message'][$action] ?? 'top';
    if (!portal_csrf_ok()) {
        $_SESSION[$key] = ['err', 'That page had been open too long. Please try again.'];
    } elseif ($ctx['preview']) {
        $_SESSION[$key] = ['err', 'This is a preview: change their details from their agent page.'];
    } elseif ($action === 'phone') {
        $phone = mb_substr(trim((string)($_POST['cell_phone'] ?? '')), 0, 30);
        portal_profile_save($conn, $ctx, $me, 'phone number', ['cell_phone' => ['Cell phone', $phone]], $key);
    } elseif ($action === 'bio') {
        $full = trim((string)($_POST['bio_text'] ?? ''));
        if ($full !== '') {
            $full = strip_tags($full, PORTAL_BIO_TAGS);
            $full = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $full);
            if (in_array(trim($full), ['<p><br></p>', '<p></p>', '<br>'], true)) $full = '';
        }
        $short = mb_substr(trim((string)($_POST['bio_short'] ?? '')), 0, 1000);
        portal_profile_save($conn, $ctx, $me, 'bio', ['bio_text' => ['Full bio', $full], 'bio_short' => ['Short bio', $short]], $key);
    } elseif ($action === 'social') {
        $fields = [];
        foreach ($SOCIAL as $col => [$label, $prefix, $strip]) {
            $v = mb_substr(trim((string)($_POST[$col] ?? '')), 0, $col === 'website_url' ? 255 : 150);
            foreach ($strip as $pre) if ($v !== '' && stripos($v, $pre) === 0) { $v = substr($v, strlen($pre)); break; }
            if ($col !== 'website_url') $v = ltrim($v, '@');
            if ($col === 'website_url' && $v !== '' && !preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
            $fields[$col] = [$label, $v];
        }
        portal_profile_save($conn, $ctx, $me, 'social media links', $fields, $key);
    } elseif ($action === 'contact') {
        $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200);
        $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 4000);
        if ($subject === '' || $message === '') {
            $_SESSION[$key] = ['err', 'Please give your message a subject and some words.'];
        } else {
            $u = $ctx['user']; $who = trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['email'];
            $rid = 0;
            if (mk_table_exists($conn, 'marketing_requests')) {
                $kind = 'contact'; $uidv = (int)$u['id']; $dest = '';
                $st = $conn->prepare("INSERT INTO marketing_requests (intake_id, user_id, kind, title, details, destination) VALUES (?, ?, ?, ?, ?, ?)");
                $st->bind_param('iissss', $acct, $uidv, $kind, $subject, $message, $dest);
                $st->execute(); $rid = (int)$conn->insert_id; $st->close();
            }
            $e = fn($v) => nl2br(htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), false);
            $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;">'
                  . '<p><strong>' . $e($who) . '</strong> (' . $e($ctx['acct']['agent_name']) . ') wrote from their agent portal:</p>'
                  . '<p style="white-space:pre-wrap;border-left:3px solid #c9a96a;padding-left:12px;">' . $e($message) . '</p>'
                  . '<p style="color:#6b7280;font-size:12px;">Reply to this email to answer ' . $e($who) . ' directly.' . ($rid ? " Request #{$rid}." : '') . '</p></div>';
            [$st_mail, $detail] = portal_send_mail('Portal message: ' . $subject . ' (' . $ctx['acct']['agent_name'] . ')', $html, (string)$u['email'], $who);
            if ($rid) {
                $st = $conn->prepare("UPDATE marketing_requests SET email_status = ?, email_error = ? WHERE id = ?");
                $err = $st_mail === 'sent' ? null : mb_substr($detail, 0, 500);
                $st->bind_param('ssi', $st_mail, $err, $rid); $st->execute(); $st->close();
            }
            $_SESSION[$key] = $st_mail === 'sent' ? ['ok', 'Sent. Marketing will reply to ' . $u['email'] . '.']
                                                  : ['err', 'Sorry, your message could not be sent just now. Please email ' . PORTAL_MARKETING_EMAIL . ' directly.'];
            if ($st_mail === 'sent') $_SESSION['pt_pf_contact_clear'] = 1;
        }
    }
    header('Location: ' . $back($anchor)); exit;
}

// ── Read ────────────────────────────────────────────────────────────────────
$flash = [];
foreach (['phone', 'bio', 'social', 'contact'] as $k) { $flash[$k] = $_SESSION['pt_pf_' . $k] ?? null; unset($_SESSION['pt_pf_' . $k]); }
$hs_flash = $_SESSION['pt_hs_flash'] ?? null; unset($_SESSION['pt_hs_flash']);
unset($_SESSION['pt_pf_contact_clear']);

$identities = [];
if (mk_table_exists($conn, 'agent_mls_ids')) {
    $st = $conn->prepare("SELECT market, mls_agent_id FROM agent_mls_ids WHERE intake_id = ? AND mls_agent_id <> '' ORDER BY market, mls_agent_id");
    $st->bind_param('i', $acct); $st->execute();
    $identities = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}
$listings = portal_listings($conn, $ctx);
$hs_ready = portal_hs_ready($conn);
$hs = $hs_ready ? portal_hs_load($conn, $ctx) : null;
$conn->close();
// ── Nothing below this line may touch the database. ─────────────────────────

$photo = trim((string)($me['headshot_face_url'] ?? '')) ?: trim((string)($me['headshot_url'] ?? ''));
$is_team = ($me['entity_type'] ?? 'agent') === 'team';
$name  = (string)($me['agent_name'] ?? $ctx['acct']['agent_name']);
$email_shown = (string)($me['mh_email'] ?? '') ?: (string)$ctx['user']['email'];
$flash_html = function (?array $f): string { return $f ? '<div class="pt-flash ' . ($f[0] === 'ok' ? 'ok' : 'err') . '">' . ph($f[1]) . '</div>' : ''; };
$price = fn($v) => $v !== null && (float)$v > 0 ? '$' . number_format((float)$v) : '';

portal_header($ctx, 'My Profile', 'profile');
?>
  <h1 class="pt-h1" id="top">My Profile</h1>
  <p class="pt-lead">What Mont Haus and the website hold about you. Anything you change here goes to the website within the hour, and marketing is told.</p>

  <!-- Who: read-only -->
  <section class="pt-card pt-section" id="contact">
    <div class="pt-who">
      <?php if ($photo !== ''): ?><img class="pt-avatar large" src="<?= ph($photo) ?>" alt=""><?php else: ?><span class="pt-avatar large"></span><?php endif; ?>
      <div class="pt-who-text">
        <h2 class="pt-card-h"><?= ph($name) ?></h2>
        <?php if (trim((string)($me['agent_title'] ?? '')) !== ''): ?><p class="pt-card-sub"><?= ph(str_replace("\n", ' ', (string)$me['agent_title'])) ?></p><?php endif; ?>
        <p class="pt-card-sub"><?= ph($email_shown) ?></p>
        <p class="pt-help" style="margin:8px 0 0">To change your name, email or headshot, email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>
      </div>
    </div>
    <?= $flash_html($flash['phone']) ?>
    <form method="post" class="pt-form pt-inline" action="<?= ph(portal_url($ctx, '/portal/profile.php')) ?>">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="phone">
      <label>Cell phone
        <input type="text" name="cell_phone" inputmode="tel" autocomplete="tel" maxlength="30" value="<?= ph($me['cell_phone'] ?? '') ?>" placeholder="970 555 0100"></label>
      <div class="pt-row"><button type="submit" class="pt-btn main">Save phone</button></div>
    </form>
  </section>

  <!-- Bio: editable -->
  <section class="pt-card pt-section" id="bio">
    <h2 class="pt-card-h">Bio</h2>
    <p class="pt-card-sub">Your full bio is on your page on monthaus.com. The short one is for cards and print.</p>
    <?= $flash_html($flash['bio']) ?>
    <form method="post" class="pt-form" action="<?= ph(portal_url($ctx, '/portal/profile.php')) ?>" id="bioForm">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="bio">
      <label>Full bio</label>
      <div id="bioEditor" class="pt-editor"><?= strip_tags((string)($me['bio_text'] ?? ''), PORTAL_BIO_TAGS) ?></div>
      <textarea name="bio_text" id="bioText" rows="10" class="pt-editor-fallback"><?= ph($me['bio_text'] ?? '') ?></textarea>
      <label style="margin-top:16px">Short bio
        <textarea name="bio_short" rows="3" maxlength="1000" placeholder="Two or three sentences."><?= ph($me['bio_short'] ?? '') ?></textarea></label>
      <div class="pt-row"><button type="submit" class="pt-btn main">Save bio</button></div>
    </form>
  </section>

  <!-- Social: editable -->
  <section class="pt-card pt-section" id="social">
    <h2 class="pt-card-h">Social media</h2>
    <p class="pt-card-sub">Your handle, not the full address: <b>monthaus</b>, not instagram.com/monthaus. These show on your page on monthaus.com.</p>
    <?= $flash_html($flash['social']) ?>
    <form method="post" class="pt-form" action="<?= ph(portal_url($ctx, '/portal/profile.php')) ?>">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="social">
      <div class="pt-grid2">
      <?php foreach ($SOCIAL as $col => [$label, $prefix]): ?>
        <label><?= ph($label) ?>
          <input type="text" name="<?= ph($col) ?>" maxlength="<?= $col === 'website_url' ? 255 : 150 ?>" autocapitalize="none" autocorrect="off"
                 value="<?= ph($me[$col] ?? '') ?>" placeholder="<?= $col === 'website_url' ? 'https://…' : 'handle' ?>"></label>
      <?php endforeach; ?>
      </div>
      <div class="pt-row"><button type="submit" class="pt-btn main">Save social media</button></div>
    </form>
  </section>

  <!-- Boards: read-only -->
  <section class="pt-card pt-section" id="boards">
    <h2 class="pt-card-h">MLS boards</h2>
    <?php if (!$identities): ?>
      <p class="pt-card-sub">No MLS identity is on file for you yet. If you are active on a board, email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>
    <?php else: ?>
      <p class="pt-card-sub">Your agent IDs as the boards issue them. These are matched from the MLS and cannot be edited here.</p>
      <div class="pt-kv">
        <?php foreach ($identities as $i): ?>
          <div><span><?= ph(mk_board_label((string)$i['market'])) ?></span><b><?= ph($i['mls_agent_id']) ?></b></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Hot Sheets: editable -->
  <section class="pt-card pt-section" id="hotsheets">
    <h2 class="pt-card-h">Internal Hot Sheets</h2>
    <p class="pt-card-sub">Emailed Mont Haus listings, pocket listings, buyer reps and rentals, for the areas you work. One email per area, with the latest activity at the top.</p>
    <?php if ($hs): portal_hs_form($ctx, $hs, $hs_flash); else: ?>
      <p class="pt-card-sub">Hot Sheet choices are not switched on yet.</p>
    <?php endif; ?>
  </section>

  <!-- Listings: read-only -->
  <section class="pt-card pt-section" id="listings">
    <h2 class="pt-card-h">Current listings</h2>
    <?php if (!$listings): ?>
      <p class="pt-card-sub">We do not see any live listings under your name right now. If that is wrong, email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>
    <?php else: ?>
      <p class="pt-card-sub">From the MLS feed, as the website shows them.</p>
      <div class="pt-list">
        <?php foreach ($listings as $l): $u = trim((string)($l['url'] ?? '')); ?>
          <a class="pt-list-row" <?= $u !== '' ? 'href="' . ph($u) . '" target="_blank" rel="noopener"' : '' ?>>
            <?php if (trim((string)$l['primary_photo']) !== ''): ?><img src="<?= ph($l['primary_photo']) ?>" alt="" loading="lazy"><?php else: ?><span class="pt-list-nopic"></span><?php endif; ?>
            <span class="pt-list-text">
              <b><?= ph($l['address']) ?></b>
              <span><?= ph(trim((string)$l['city'])) ?><?= $price($l['list_price']) !== '' ? ' · ' . ph($price($l['list_price'])) . ((int)$l['is_rental'] ? ' / mo' : '') : '' ?> · <?= ph((int)$l['is_rental'] ? 'Rental' : (string)$l['status']) ?></span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Contact marketing -->
  <section class="pt-card pt-section" id="message">
    <h2 class="pt-card-h">Contact marketing</h2>
    <p class="pt-card-sub">A question, a change we should make, something you need. It goes to <?= PORTAL_MARKETING_EMAIL ?> and they reply to <?= ph($ctx['user']['email']) ?>.</p>
    <?= $flash_html($flash['contact']) ?>
    <form method="post" class="pt-form" action="<?= ph(portal_url($ctx, '/portal/profile.php')) ?>">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="contact">
      <label>Subject <input type="text" name="subject" maxlength="200" required placeholder="e.g. New headshot"></label>
      <label>Message <textarea name="message" rows="5" maxlength="4000" required></textarea></label>
      <div class="pt-row"><button type="submit" class="pt-btn main">Send to marketing</button></div>
    </form>
  </section>

<link rel="stylesheet" href="/assets/vendor/quill.snow.css">
<script src="/assets/vendor/quill.js"></script>
<script>
// The bio editor: Quill when its files are on the server (assets/vendor, like
// the admin page), the plain textarea otherwise. Same safe tags either way.
(function () {
  var holder = document.getElementById('bioEditor'), ta = document.getElementById('bioText'), form = document.getElementById('bioForm');
  if (typeof Quill === 'undefined' || !holder) { if (holder) holder.style.display = 'none'; ta.style.display = 'block'; return; }
  ta.style.display = 'none';
  var q = new Quill(holder, { theme: 'snow', modules: { toolbar: [['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], ['link'], ['clean']] } });
  form.addEventListener('submit', function () { ta.value = q.root.innerHTML; });
})();
</script>
<?php portal_footer();
