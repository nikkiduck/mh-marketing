<?php
/**
 * inc/admin_tool_shell.php — the admin-side page frame for the portal's
 * template tools (2026-10-21): Templates (templates.php), Instagram Post/Story
 * (template_social.php), Listing Flyer (template_flyer.php) and Market
 * Snapshot (market_snapshot.php). The admin header and nav, then a page
 * header with a title, a hint and action buttons, then whatever the page
 * draws; the tools' bodies are the portal's own (inc/social_body.php,
 * inc/flyer_body.php, inc/market_post_body.php), which reuse social.css and
 * expect portal.css's tokens, so the frame defines them on .wrap.
 *
 * The agent picker (admin_agent_picker()) is how an admin chooses whose
 * listings a tool works from (Nikki, 2026-10-21): the admin pages set
 * $_GET['preview'] to the chosen account and call portal_context(), the
 * same path the roster's eye icon takes, so every endpoint the body calls
 * (render, photos, upload, export) acts on that account through ?preview=.
 * Uploads stay the admin's own (social_upload_dir() keys them by users.id).
 */

/** The open of the page: <html> through the page header. $o: title, hint, actions (HTML), styles (hrefs), active (nav key). */
function admin_tool_header(array $o): void {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $v = fn(string $f) => (int)@filemtime(__DIR__ . '/../' . $f);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= $e($o['title'] ?? 'Templates') ?> | Mont Haus Marketing</title>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <?php foreach ((array)($o['styles'] ?? []) as $href): ?>
  <link rel="stylesheet" href="<?= $e($href) ?>?v=<?= $v(ltrim($href, '/')) ?>">
  <?php endforeach; ?>
  <style>
    .layout-extended .pc-container { margin-top:70px; padding-top:24px; top:0; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
    .mh-header-inner { width:100%; padding:0 15px; display:flex; align-items:center; justify-content:space-between; height:70px; }
    .mh-logo img { height:50px; display:block; }
    .mh-nav { display:flex; align-items:center; gap:4px; }
    .mh-nav-link { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; font-size:.8rem; font-weight:500;
                   color:#aebbcc; text-decoration:none; border-radius:6px; white-space:nowrap; transition:color .2s, background .2s; }
    .mh-nav-link:hover  { color:#fff; background:rgba(174,187,204,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(174,187,204,.45); }
    .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }
    .hdr-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .wrap { max-width:1100px; margin:32px auto 60px; padding:0 20px; }
    .mk-page-header { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .note { background:#eceff3; border:1px solid #dfe3e8; padding:12px 14px; font-size:13px; color:#434b57; margin-bottom:18px; }
    .hint { font-size:13px; color:#6b7280; margin:4px 0 0; max-width:60ch; }
    /* The agent picker: whose listings the tool works from. */
    .tl-agent { display:flex; align-items:center; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #dfe3e8; border-radius:10px; padding:12px 14px; margin-bottom:20px; }
    .tl-agent label { font-weight:600; font-size:13px; color:#1f242c; margin:0; }
    .tl-agent select { font-size:14px; padding:6px 36px 6px 10px; border:1px solid #c9d0d9; border-radius:8px; min-width:260px; cursor:pointer;
                       appearance:none; -webkit-appearance:none; background:#fff url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 14 14'><path d='M2.5 5l4.5 4.5L11.5 5' fill='none' stroke='%236f6857' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/></svg>") no-repeat right 12px center; }   /* the drawn chevron with room, as the template fields (2026-10-22) */
    .tl-agent .tl-count { font-size:13px; color:#6b7a8f; }
    /* The tool cards on templates.php, after the portal's. */
    .tl-group { font-size:12px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:#6b7a8f; margin:22px 0 10px; }
    .tl-cards { display:grid; grid-template-columns:1fr; gap:16px; }
    @media (min-width:640px) { .tl-cards { grid-template-columns:1fr 1fr; } }
    .tl-card { display:block; background:#fff; border:1px solid #dfe3e8; border-radius:14px; padding:20px; color:inherit; text-decoration:none; transition:border-color .15s, box-shadow .15s; }
    .tl-card:hover { border-color:#4a5566; box-shadow:0 4px 18px rgba(0,0,0,.06); text-decoration:none; color:inherit; }
    .tl-card h3 { font-family:'Cormorant Garamond', Georgia, serif; font-weight:500; font-size:24px; margin:0 0 6px; color:#1f242c; }
    .tl-card p { font-size:14px; color:#6b7a8f; margin:0 0 10px; line-height:1.5; }
    .tl-card span { font-size:13px; font-weight:600; color:#4a5569; }
    .tl-card.off { opacity:.55; pointer-events:none; }
    /* The bodies reuse the portal's social.css, which expects portal.css's tokens. */
    .wrap { --ink:#2b3038; --gold:#4a5566; --gold-deep:#4a5569; --paper:#f6f7f9; --card:#ffffff; --line:#dfe3e8; --text:#1f242c; --muted:#6b7a8f;
            --serif:'Cormorant Garamond', Georgia, serif; --sans:'Public Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif; }
    .wrap .pt-flash { border-radius:10px; padding:14px 16px; margin:0 0 20px; font-size:15px; }
    .wrap .pt-flash.err { background:#fbeaea; color:#8f2424; }
    <?= (string)($o['css'] ?? '') ?>
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = (string)($o['active'] ?? 'marketing'); include __DIR__ . '/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">
    <div class="mk-page-header">
      <div>
        <h1><?= $e($o['title'] ?? 'Templates') ?></h1>
        <?php if (!empty($o['hint'])): ?><p class="hint"><?= $e($o['hint']) ?></p><?php endif; ?>
      </div>
      <div class="hdr-actions"><?= (string)($o['actions'] ?? '') ?></div>
    </div>
<?php
}

/** The close of the page. */
function admin_tool_footer(): void {
    ?>
  </div>
</div>
</body>
</html>
<?php
}

/**
 * The agent picker: a GET form that reloads $self with ?agent=<account id>
 * when the choice changes. $agents is social_agent_choices(); $agent_id the
 * one chosen (0 = none yet). Extra query keys to keep go in $keep.
 */
function admin_agent_picker(array $agents, int $agent_id, string $self, array $keep = []): void {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $n = $agent_id && isset($agents[$agent_id]) ? (int)$agents[$agent_id]['n'] : null;
    ?>
    <form class="tl-agent" method="get" action="<?= $e($self) ?>">
      <?php foreach ($keep as $k => $val): ?><input type="hidden" name="<?= $e($k) ?>" value="<?= $e($val) ?>"><?php endforeach; ?>
      <label for="tlAgent">Agent</label>
      <select name="agent" id="tlAgent" onchange="this.form.submit()">
        <option value="0"<?= $agent_id ? '' : ' selected' ?>>Choose an agent…</option>
        <?php foreach ($agents as $a): ?>
        <option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === $agent_id ? ' selected' : '' ?>><?= $e($a['agent_name']) ?> (<?= (int)$a['n'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php if ($n !== null): ?><span class="tl-count"><?= $n === 1 ? '1 live listing' : $n . ' live listings' ?> in the feed</span>
      <?php else: ?><span class="tl-count">The number is the agent's live listings in the feed.</span><?php endif; ?>
      <noscript><button type="submit" class="btn btn-sm btn-outline">Go</button></noscript>
    </form>
    <?php
}
