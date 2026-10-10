<?php
/**
 * _nav.php — the site header, shared by every page in the app.
 *
 * Set these before including, both optional:
 *   $nav_active — 'marketing' (default) marks the Marketing tab current
 *   $nav_extra  — a trailing crumb, e.g. 'Edit Intake'
 *
 * The page-level <style> blocks already define .pc-header, .mh-header-inner,
 * .mh-logo, .mh-nav, .mh-nav-link and .mh-nav-divider. Only the user menu is
 * styled here, so this partial drops into any of them unchanged.
 */

$mh_user = function_exists('current_user')
    ? current_user()
    : ['first_name' => '', 'last_name' => '', 'email' => '', 'role' => ''];

$mh_name = trim(($mh_user['first_name'] ?? '') . ' ' . ($mh_user['last_name'] ?? ''));
if ($mh_name === '') {
    $mh_name = (string) ($mh_user['email'] ?? '');
}

$mh_initials = '';
foreach (preg_split('/\s+/', $mh_name) as $mh_part) {
    if ($mh_part !== '') {
        $mh_initials .= strtoupper($mh_part[0]);
    }
}
$mh_initials = substr($mh_initials, 0, 2) ?: '?';

$mh_active = $nav_active ?? 'marketing';
?>
<!-- One look across the portal and the website's admin (Nikki, 2026-09-23).
     Loaded here, in the body, so it lands after each page's own <style> block
     and wins the ties without a pile of !important. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/mh-theme.css?v=20260928">

<style>
  /* ── The gap under the header ──────────────────────────────────────────────
     The theme's `layout-extended` reserves 169px above .pc-container: 74px for
     the header and ~95px for a `.pc-tab-wrapper` secondary nav bar. None of our
     pages have that bar, so on every one of them it was 95px of dead white
     space under the nav.

     The pages each set `.pc-container { top:0 }` already and it never took
     effect — `.layout-extended .pc-container` is two classes to their one, so
     it wins on specificity no matter what order the rules appear in. Measured
     in a real browser: computed top was 169px, not the 0 the page asked for.

     Fixed here rather than in each page because every page that includes this
     partial has the bug, and a fifth page would inherit it too. Matching the
     framework's own selector and landing later in the document is what makes it
     stick. Do NOT drop `layout-extended` from <body> instead — it is also what
     makes the header dark (--pc-header-background) and full-width.

     `padding-top` and `margin-top` are doing two different jobs and should not
     be merged into one number. margin-top clears the fixed header and must stay
     equal to its height; padding-top is the gap you actually see.

     The padding also stops margin collapsing, which is what made index.php sit
     flush against the nav even though its `.wrap` asks for `margin:32px auto`.
     With nothing between them, that 32px collapsed out through .pc-container
     and vanished. Remove the padding and index.php goes flush again while the
     other pages look fine — which is a confusing bug to inherit. */
  .layout-extended .pc-container {
    top: 0;
    margin-top: 70px;                        /* structural: exactly the header's height */
    padding-top: 20px;                       /* breathing room, and see below */
    min-height: calc(100vh - 90px);          /* was calc(100vh - 169px) */
  }

  .mh-nav-spacer  { flex:1; }
  .mh-user        { display:flex; align-items:center; gap:8px; margin-left:10px; }
  .mh-user-avatar {
    width:28px; height:28px; border-radius:50%;
    display:inline-flex; align-items:center; justify-content:center;
    background:rgba(117,189,182,.35); color:#fff;
    font-size:.66rem; font-weight:600; letter-spacing:.4px; flex:none;
  }
  .mh-user-name {
    color:#cfe6e3; font-size:.76rem; font-weight:500;
    max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  @media (max-width: 720px) { .mh-user-name { display:none; } }

  /* The Users link. Icon-only on purpose — see the note at its markup. */
  .mh-user-cog {
    display:inline-flex; align-items:center; justify-content:center;
    width:26px; height:26px; flex:none; border-radius:6px;
    color:#75BDB6; text-decoration:none; font-size:15px;
    transition:color .2s, background .2s;
  }
  .mh-user-cog:hover        { color:#fff; background:rgba(117,189,182,.25); }
  .mh-user-cog.active       { color:#fff; background:rgba(117,189,182,.45); }

  /* ── Mobile: the nav collapses into a hamburger panel ──────────────────────
     Above the breakpoint nothing below applies and the desktop header is
     exactly what it was: the toggle button is display:none and .mh-nav keeps
     the horizontal flex layout each page's own <style> block gives it.

     Below it the SAME anchors move into a panel that drops out of the header.
     There is no second copy of the markup, so a link added to the <nav> below
     appears in both without further work.

     820px, not 720px: "Marketing | Billing | NB Nikki Boxer | Sign out" plus a
     trailing crumb runs out of room well above phone width — a small tablet in
     portrait was already clipping "Sign out". */
  .mh-nav-toggle { display:none; }

  @media (max-width: 820px) {
    /* The panel is positioned against this, so it must be the containing block. */
    .mh-header-inner { position:relative; }

    .mh-nav-toggle {
      display:inline-flex; align-items:center; justify-content:center;
      width:40px; height:40px; flex:none; padding:0;
      background:transparent; border:1px solid rgba(255,255,255,.22);
      border-radius:8px; color:#cfe6e3; font-size:22px; line-height:1;
      cursor:pointer; transition:color .15s, border-color .15s;
    }
    .mh-nav-toggle:hover,
    .mh-nav-toggle[aria-expanded="true"] { color:#fff; border-color:rgba(255,255,255,.45); }

    .mh-nav {
      display:none;
      position:absolute; top:100%; left:0; right:0; z-index:1035;
      flex-direction:column; align-items:stretch; gap:2px;
      background:var(--pc-header-background, #131920);
      padding:8px 12px 12px;
      border-top:1px solid rgba(255,255,255,.12);
      box-shadow:0 10px 24px rgba(0,0,0,.32);
    }
    .mh-nav.open { display:flex; }

    /* A real tap target. 44px is the smallest thing a thumb hits reliably, and
       these rows were 5px of vertical padding on desktop. */
    .mh-nav-link {
      padding:12px; font-size:.9rem; border-radius:8px;
      min-height:44px; justify-content:flex-start; gap:10px;
    }
    .mh-nav-link .ti { font-size:1.05rem; }

    /* A horizontal rule now that the axis has flipped. */
    .mh-nav-divider { width:auto; height:1px; margin:6px 2px; }

    /* Inside the panel there is room for the name, so the 720px rule above —
       which exists for the cramped horizontal bar — is put back. */
    .mh-user      { margin:2px 0 4px; padding:2px 12px; }
    .mh-user-name { display:inline-block; max-width:none; font-size:.82rem; }

    /* In the panel there is room, and a thumb needs 44px. */
    .mh-user-cog  { width:44px; height:44px; margin-left:auto; font-size:1.05rem; }
  }
</style>

<header class="pc-header">
  <div class="mh-header-inner">
    <a class="mh-logo" href="/"><img src="/assets/images/logo-white.svg" alt="Mont Haus"></a>
    <?php // Hidden above 820px. Placed before the <nav> so that the panel it
          // opens is the next thing in the tab order. ?>
    <button type="button" class="mh-nav-toggle" id="mhNavToggle"
            aria-label="Menu" aria-expanded="false" aria-controls="mhNav">
      <i class="ti ti-menu-2" aria-hidden="true"></i>
    </button>
<?php
// My Portal (Nikki, 2026-10-08; a labelled nav link since 2026-10-10): an admin
// whose login is linked to a marketing account on users.php has the agent
// portal too. The account comes from the session (mh_session_intake_id(),
// filled by require_login() while $conn is open: index.php and agent.php
// close it before this header renders, so no query here). Guarded for stubs.
$mh_portal = function_exists('is_elevated_admin') && is_elevated_admin()
          && function_exists('mh_session_intake_id') && mh_session_intake_id() > 0;
?>
    <nav class="mh-nav" id="mhNav">
      <a class="mh-nav-link<?= $mh_active === 'marketing' ? ' active' : '' ?>" href="/"><i class="ti ti-speakerphone"></i> Marketing</a>
      <a class="mh-nav-link<?= $mh_active === 'billing' ? ' active' : '' ?>" href="/billing.php"><i class="ti ti-receipt-2"></i> Billing</a>
      <?php if ($mh_portal): ?>
        <a class="mh-nav-link" href="/portal/" title="Your own agent portal"><i class="ti ti-user-circle"></i> My Portal</a>
      <?php endif; ?>
      <?php if (!empty($nav_extra)): ?>
        <a class="mh-nav-link active" href="#"><?= htmlspecialchars($nav_extra) ?></a>
      <?php endif; ?>

      <?php if (!empty($mh_user['id'])): ?>
        <div class="mh-nav-divider"></div>
        <div class="mh-user">
          <span class="mh-user-avatar" aria-hidden="true"><?= htmlspecialchars($mh_initials) ?></span>
          <span class="mh-user-name" title="<?= htmlspecialchars($mh_user['email'] ?? '') ?>"><?= htmlspecialchars($mh_name) ?></span>
          <?php // Users is icon-only, and inside .mh-user rather than in the run of
                // .mh-nav-link anchors above. The 820px breakpoint was measured
                // against those links and a fifth labelled one would need
                // re-measuring first; a 26px icon next to the avatar does not
                // change where the bar runs out of room. super_admin only, so it
                // is one person's header that grows at all.
                if (function_exists('is_super_admin') && is_super_admin()): ?>
            <a class="mh-user-cog<?= ($mh_active === 'users') ? ' active' : '' ?>"
               href="/users.php" title="Users" aria-label="Users"><i class="ti ti-settings" aria-hidden="true"></i></a>
          <?php endif; ?>
        </div>
        <a class="mh-nav-link" href="/logout.php" title="Sign out"><i class="ti ti-logout"></i> Sign out</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<script>
/* The hamburger. Deliberately tiny and dependency-free — this partial is
   included by every page and must not assume any of them loaded a library.

   The panel is only ever opened by adding a class; at desktop width the media
   query does not apply, so a leftover `open` class is inert. That is why
   nothing here listens for resize. */
(function () {
  var btn = document.getElementById('mhNavToggle');
  var nav = document.getElementById('mhNav');
  if (!btn || !nav) return;

  function setOpen(open) {
    nav.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    var i = btn.querySelector('i');
    if (i) i.className = 'ti ' + (open ? 'ti-x' : 'ti-menu-2');
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    setOpen(!nav.classList.contains('open'));
  });

  /* Tapping the page behind an open panel closes it — the expected gesture,
     and the only way out on a phone besides hitting the X. Clicks inside the
     panel are left alone so a link still navigates. */
  document.addEventListener('click', function (e) {
    if (nav.classList.contains('open') && !nav.contains(e.target)) setOpen(false);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && nav.classList.contains('open')) { setOpen(false); btn.focus(); }
  });
})();
</script>
