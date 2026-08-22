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

// Where the "Hub" link points. Override in config.php once the portal exists.
$mh_hub_url = defined('HUB_URL') ? HUB_URL : 'https://monthausint.com/';

$mh_active = $nav_active ?? 'marketing';
?>
<style>
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
</style>

<header class="pc-header">
  <div class="mh-header-inner">
    <a class="mh-logo" href="/"><img src="/assets/images/logo-white.svg" alt="Mont Haus"></a>
    <nav class="mh-nav">
      <a class="mh-nav-link" href="<?= htmlspecialchars($mh_hub_url) ?>"><i class="ti ti-home"></i> Hub</a>
      <div class="mh-nav-divider"></div>
      <a class="mh-nav-link<?= $mh_active === 'marketing' ? ' active' : '' ?>" href="/"><i class="ti ti-speakerphone"></i> Marketing</a>
      <?php if (!empty($nav_extra)): ?>
        <a class="mh-nav-link active" href="#"><?= htmlspecialchars($nav_extra) ?></a>
      <?php endif; ?>

      <?php if (!empty($mh_user['id'])): ?>
        <div class="mh-nav-divider"></div>
        <div class="mh-user">
          <span class="mh-user-avatar" aria-hidden="true"><?= htmlspecialchars($mh_initials) ?></span>
          <span class="mh-user-name" title="<?= htmlspecialchars($mh_user['email'] ?? '') ?>"><?= htmlspecialchars($mh_name) ?></span>
        </div>
        <a class="mh-nav-link" href="/logout.php" title="Sign out"><i class="ti ti-logout"></i> Sign out</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
