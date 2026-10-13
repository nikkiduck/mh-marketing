<?php
/**
 * portal/profile.php — My Profile (Nikki, 2026-10-12). What the website and
 * marketing hold about the agent, some of it editable here:
 *   read-only  name, email, phone, title, headshot, MLS board identities, current listings
 *   editable   bio, social handles, Hot Sheet subscription
 *   plus       a small form to contact marketing@
 * (Nikki, 2026-10-12 pm: phone read-only with the name and email; the short bio is gone
 * for good; handles get an @ in front of the box and a pasted URL is parsed; listings
 * last, drawn like the admin agent page's cards; a row of anchors under the header.)
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
require_once __DIR__ . '/../inc/qr.php';   // qr_profile_url(): the agent's page on the website

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
    'website_url'      => ['Personal Website', '',                    []],
];
const PORTAL_BIO_TAGS = '<p><br><strong><b><em><i><u><ol><ul><li><a><h3><h4><blockquote>';

/**
 * A handle from whatever was typed: "@jhorn", "jhorn", or a pasted address
 * ("https://www.instagram.com/jhorn/?hl=en", "linkedin.com/in/jackson-horn").
 * Stored bare, as the admin page stores them. The website is kept as an https URL.
 */
function portal_handle(string $col, string $v): string {
    $v = trim($v);
    if ($col === 'website_url') {
        if ($v !== '' && !preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
        return $v;
    }
    // An address, not a handle: it has a scheme, starts with www., or has a slash
    // in it (a handle never does; "mont.haus" with a dot is still a handle).
    if (preg_match('~^(?:https?://|www\.)~i', $v) || str_contains($v, '/')) {
        $path = preg_replace('~^(?:https?://)?[^/]*~i', '', $v);
        $path = trim($path, '/');
        $path = preg_replace('~[?#].*$~', '', $path);
        $parts = array_values(array_filter(explode('/', $path), fn($x) => $x !== ''));
        // linkedin.com/in/<handle>, linkedin.com/company/<name>, tiktok.com/@<handle>, instagram.com/<handle>
        if ($parts && in_array(strtolower($parts[0]), ['in', 'company', 'pub', 'profile.php'], true)) array_shift($parts);
        $v = (string)($parts[0] ?? '');
    }
    return ltrim(rawurldecode($v), '@');
}

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
    $anchor = ['bio' => 'bio', 'social' => 'social', 'areas' => 'areas'][$action] ?? 'top';
    if (!portal_csrf_ok()) {
        $_SESSION[$key] = ['err', 'That page had been open too long. Please try again.'];
    } elseif ($ctx['preview']) {
        $_SESSION[$key] = ['err', 'This is a preview: change their details from their agent page.'];
    } elseif ($action === 'bio') {
        $full = trim((string)($_POST['bio_text'] ?? ''));
        if ($full !== '') {
            $full = strip_tags($full, PORTAL_BIO_TAGS);
            $full = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $full);
            if (in_array(trim($full), ['<p><br></p>', '<p></p>', '<br>'], true)) $full = '';
        }
        portal_profile_save($conn, $ctx, $me, 'bio', ['bio_text' => ['Bio', $full]], $key);
    } elseif ($action === 'social') {
        $fields = [];
        foreach ($SOCIAL as $col => [$label]) {
            $v = mb_substr(portal_handle($col, (string)($_POST[$col] ?? '')), 0, $col === 'website_url' ? 255 : 150);
            $fields[$col] = [$label, $v];
        }
        portal_profile_save($conn, $ctx, $me, 'social media links', $fields, $key);
    } elseif ($action === 'areas') {
        // One area per line in the box; stored the admin way, joined with "; " (the website prints the text as one line).
        $lines = array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string)($_POST['service_area'] ?? ''))), fn($x) => $x !== ''));
        $areas = mb_substr(implode('; ', $lines), 0, 255);
        portal_profile_save($conn, $ctx, $me, 'service areas', ['service_area' => ['Service areas', $areas]], $key);
    }
    header('Location: ' . $back($anchor)); exit;
}

// ── Read ────────────────────────────────────────────────────────────────────
$flash = [];
foreach (['bio', 'social', 'areas'] as $k) { $flash[$k] = $_SESSION['pt_pf_' . $k] ?? null; unset($_SESSION['pt_pf_' . $k]); }
$hs_flash = $_SESSION['pt_hs_flash'] ?? null; unset($_SESSION['pt_hs_flash']);

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
$email_shown = (string)($me['mh_email'] ?? '') ?: ($ctx['preview'] ? '' : (string)$ctx['user']['email']);   // never the admin's own address in a preview
$flash_html = function (?array $f): string { return $f ? '<div class="pt-flash ' . ($f[0] === 'ok' ? 'ok' : 'err') . '">' . ph($f[1]) . '</div>' : ''; };
$price = fn($v) => $v !== null && (float)$v > 0 ? '$' . number_format((float)$v) : '';

$jump = ['contact' => 'Contact', 'bio' => 'Bio', 'social' => 'Social', 'areas' => 'Service areas', 'boards' => 'MLS boards', 'hotsheets' => 'Hot Sheets', 'listings' => 'Listings'];
portal_header($ctx, 'My Profile', 'profile', $jump);
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
        <p class="pt-card-sub"><?= ph($email_shown) ?><?= trim((string)($me['cell_phone'] ?? '')) !== '' ? ' · ' . ph(portal_phone($me['cell_phone'])) : '' ?></p>
        <?php if (trim((string)($me['slug'] ?? '')) !== '' && ($me['web_status'] ?? '') === 'approved'): ?>
          <p class="pt-card-sub" style="margin-top:6px"><a href="<?= ph(qr_profile_url((string)$me['slug'])) ?>" target="_blank" rel="noopener">View your page on monthaus.com ↗</a></p>
        <?php elseif (!$is_team): ?>
          <p class="pt-card-sub" style="margin-top:6px">Your page on monthaus.com is not published yet.</p>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <p class="pt-help" style="margin:-6px 0 22px">To update your name or contact information, email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a> or send a message via the <a href="#contact-marketing">form below</a>.</p>

  <!-- Bio: editable -->
  <section class="pt-card pt-section" id="bio">
    <h2 class="pt-card-h">Bio</h2>
    <p class="pt-card-sub">Your bio, as it reads on your page on monthaus.com.</p>
    <?= $flash_html($flash['bio']) ?>
    <form method="post" class="pt-form" action="<?= ph(portal_url($ctx, '/portal/profile.php')) ?>" id="bioForm">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="bio">
      <div id="bioEditor" class="pt-editor"><?= strip_tags((string)($me['bio_text'] ?? ''), PORTAL_BIO_TAGS) ?></div>
      <textarea name="bio_text" id="bioText" rows="10" class="pt-editor-fallback"><?= ph($me['bio_text'] ?? '') ?></textarea>
      <div class="pt-row"><button type="submit" class="pt-btn main">Save bio</button></div>
    </form>
  </section>

  <!-- Social: editable -->
  <section class="pt-card pt-section" id="social">
    <h2 class="pt-card-h">Social + Digital</h2>
    <?= $flash_html($flash['social']) ?>
    <form method="post" class="pt-form" action="<?= ph(portal_url($ctx, '/portal/profile.php')) ?>">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="social">
      <div class="pt-grid2">
      <?php foreach ($SOCIAL as $col => [$label, $prefix]): ?>
        <label><?= ph($label) ?>
          <span class="pt-at<?= $col === 'website_url' ? ' none' : '' ?>">
            <input type="text" name="<?= ph($col) ?>" maxlength="<?= $col === 'website_url' ? 255 : 150 ?>" autocapitalize="none" autocorrect="off"
                   value="<?= ph(portal_handle($col, (string)($me[$col] ?? ''))) ?>" placeholder="<?= $col === 'website_url' ? 'https://…' : '' ?>">
          </span></label>
      <?php endforeach; ?>
      </div>
      <div class="pt-row"><button type="submit" class="pt-btn main">Save social media</button></div>
    </form>
  </section>

  <!-- Service areas: editable, one per line -->
  <section class="pt-card pt-section" id="areas">
    <h2 class="pt-card-h">Service areas</h2>
    <p class="pt-card-sub">Where you work, as it reads on your page on monthaus.com. One area per line.</p>
    <?= $flash_html($flash['areas']) ?>
    <form method="post" class="pt-form" action="<?= ph(portal_url($ctx, '/portal/profile.php')) ?>">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="areas">
      <label class="pt-visually-hidden" for="serviceArea">Service areas</label>
      <textarea id="serviceArea" name="service_area" rows="4" maxlength="255" placeholder="Aspen and the Roaring Fork Valley"><?= ph(implode("\n", array_filter(array_map('trim', explode(';', (string)($me['service_area'] ?? ''))), fn($x) => $x !== ''))) ?></textarea>
      <div class="pt-row"><button type="submit" class="pt-btn main">Save service areas</button></div>
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

  <!-- Listings: read-only, last (they can get long), drawn like the admin agent page's cards -->
  <section class="pt-section" id="listings">
    <h2 class="pt-h2">Current Listings</h2>
    <?php if (!$listings): ?>
      <div class="pt-card"><p class="pt-card-sub" style="margin:0">We do not see any live listings under your name right now. If that is wrong, email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p></div>
    <?php else: ?>
      <div class="pt-lgrid">
        <?php foreach ($listings as $l):
          $lst   = (string)$l['status'];
          $lstat = $lst === 'Pending' || $lst === 'Active Under Contract' ? 'pending' : 'active';
          $llab  = ($lst === 'Active Under Contract' ? 'Under Contract' : $lst) . ((int)$l['is_rental'] ? ' · For Rent' : '');
          $u = trim((string)($l['url'] ?? ''));
        ?>
        <div class="pt-lcard">
          <?php if (trim((string)$l['primary_photo']) !== ''): ?>
            <img class="pt-lphoto" src="<?= ph($l['primary_photo']) ?>" alt="" loading="lazy">
          <?php else: ?>
            <div class="pt-lphoto"></div>
          <?php endif; ?>
          <div class="pt-lbody">
            <div class="pt-lstatus <?= $lstat ?>"><?= ph($llab) ?></div>
            <div class="pt-laddr"><?= ph($l['address']) ?></div>
            <?php if (trim((string)$l['city']) !== ''): ?><div class="pt-lcity"><?= ph(trim((string)$l['city'])) ?>, CO</div><?php endif; ?>
            <?php if ($price($l['list_price']) !== ''): ?><div class="pt-lprice"><?= ph($price($l['list_price'])) ?></div><?php endif; ?>
            <?php if ($u !== ''): ?><a class="pt-llink" href="<?= ph($u) ?>" target="_blank" rel="noopener">View on the website ↗</a><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
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
