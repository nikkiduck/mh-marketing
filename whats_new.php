<?php
/**
 * whats_new.php — What's New (Nikki, 2026-11-14): the notifications agents see under the bell, typed once here.
 *
 * Each notification has an area, and the area decides who sees it and where (inc/whats_new.php):
 *   Marketing Portal   every agent, in the agent portal
 *   B2Bs               Aspen and Vail agents: MH Send's B2B pages, and their portal
 *   Client Emails      agents who have Client Emails: Client Emails in MH Send, and their portal
 *
 * Until 2026-11-14 the B2B ones were entries in MH Send's changelog.json, edited and uploaded by hand; its two
 * notices were carried over as the list's first rows.
 *
 * POSTs come back here: csrf_token + wn_action = add | set | active | remove. Super admin only. The portal shows a
 * change straight away; MH Send within about five minutes (it caches the feed, api/whats_new.php).
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/whats_new.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
require_role('super_admin');          // calls require_login() itself

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['csrf_token'];
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── Changes ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $err = ''; $done = ''; $keep = null;
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $err = 'That page had been open too long. Nothing was changed: please try again.';
    } else {
        $action = (string)($_POST['wn_action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'add' || $action === 'set') {
            $f = wn_fields($_POST);
            if (is_string($f)) { $err = $f; if ($action === 'add') $keep = ['area' => (string)($_POST['area'] ?? ''), 'icon' => (string)($_POST['icon'] ?? ''), 'title' => (string)($_POST['title'] ?? ''), 'message' => (string)($_POST['message'] ?? '')]; }
            elseif ($action === 'add') { if (wn_add($f) > 0) $done = 'Posted to ' . WN_AREAS[$f['area']][0] . '.'; else $err = 'The notification could not be saved on the server. Nothing was posted.'; }
            elseif (!wn_set($id, $f)) $err = 'That notification could not be changed.';
            else $done = 'Saved. Nobody is told about it a second time.';
        } elseif ($action === 'active') {
            $on = (string)($_POST['on'] ?? '') === '1';
            if (!wn_set_active($id, $on)) $err = 'That notification could not be changed.';
            else $done = $on ? 'Showing again.' : 'Hidden. It is kept here and shown to nobody.';
        } elseif ($action === 'remove') {
            if (!wn_remove($id)) $err = 'That notification could not be deleted.'; else $done = 'Deleted.';
        } else {
            $err = 'That is not something this page does.';
        }
    }
    $_SESSION['wn_flash'] = ['ok' => $err === '', 'words' => $err !== '' ? $err : $done, 'keep' => $keep];
    header('Location: whats_new.php');
    exit;
}

// ── The page ─────────────────────────────────────────────────────────────────
$flash = $_SESSION['wn_flash'] ?? null; unset($_SESSION['wn_flash']);
$keep = is_array($flash['keep'] ?? null) ? $flash['keep'] : ['area' => '', 'icon' => '', 'title' => '', 'message' => ''];
$items = wn_items(null, false);
$hid = '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
$icons = ['✨', '📣', '📧', '📊', '🏡', '🏘️', '🗓️', '📷', '🎨', '🔗', '⏱️', '✅'];

/** The fields of one notification form (add, or change): $v holds the values, $n keeps the ids apart. */
$fields = function (array $v, string $n) use ($e, $icons): string {
    ob_start(); ?>
      <div class="wn-where" role="radiogroup" aria-label="Where it shows">
        <?php foreach (WN_AREAS as $k => [$label, $who]): ?>
        <label class="wn-area"><input type="radio" name="area" value="<?= $e($k) ?>"<?= ($v['area'] ?? '') === $k ? ' checked' : '' ?> required>
          <span><b><?= $e($label) ?></b><small><?= $e($who) ?></small></span></label>
        <?php endforeach; ?>
      </div>
      <div class="wn-fields">
        <div class="wn-icon"><label for="wnIcon<?= $e($n) ?>">Icon</label>
          <input type="text" id="wnIcon<?= $e($n) ?>" name="icon" maxlength="4" value="<?= $e(($v['icon'] ?? '') ?: WN_ICON_DEFAULT) ?>" autocomplete="off">
          <div class="wn-picks" aria-label="Icons to choose from"><?php foreach ($icons as $ic): ?><button type="button" data-icon="<?= $e($ic) ?>" data-for="wnIcon<?= $e($n) ?>" title="Use this icon"><?= $e($ic) ?></button><?php endforeach; ?></div></div>
        <div><label for="wnTitle<?= $e($n) ?>">Title</label>
          <input type="text" id="wnTitle<?= $e($n) ?>" name="title" maxlength="<?= WN_TITLE_MAX ?>" value="<?= $e($v['title'] ?? '') ?>" placeholder="Open House Tours" required></div>
        <div><label for="wnMsg<?= $e($n) ?>">Message</label>
          <textarea id="wnMsg<?= $e($n) ?>" name="message" rows="4" maxlength="<?= WN_MESSAGE_MAX ?>" placeholder="What is new, and where to find it. Two or three sentences." required><?= $e($v['message'] ?? '') ?></textarea>
          <small>Plain text, up to <?= WN_MESSAGE_MAX ?> characters. A line break is kept.</small></div>
      </div>
    <?php return (string)ob_get_clean();
};

admin_tool_header([
    'title'   => "What's New",
    'hint'    => 'The notifications agents see under the bell and as a banner the first time. Type each one once and choose where it shows: the portal picks up the B2B and Client Emails ones by itself.',
    'actions' => '<a class="btn btn-outline btn-sm" href="/portal/"><i class="ti ti-user-circle"></i> My Portal</a>'
               . '<a class="btn btn-outline btn-sm" href="https://send.monthaus.com/" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> MH Send</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'active'  => 'whatsnew',
    'css'     => '
    .wn-flash { border-radius:10px; padding:10px 14px; font-size:14px; margin:0 0 16px; background:#e9f3ec; border:1px solid #bfe0c9; color:#1f5a35; }
    .wn-flash.err { background:#fbeaea; border-color:#f0c9c9; color:#8f2424; }
    .wn-box { background:#fff; border:1px solid #dfe3e8; border-radius:14px; padding:18px 20px; margin:0 0 20px; }
    .wn-box h2 { font-family:"Cormorant Garamond",Georgia,serif; font-weight:500; font-size:24px; margin:0 0 4px; color:#1f242c; }
    .wn-box p.sub { font-size:13.5px; color:#6b7a8f; margin:0 0 14px; line-height:1.5; }
    .wn-where { display:grid; grid-template-columns:1fr; gap:10px; margin:0 0 14px; }
    @media (min-width:760px) { .wn-where { grid-template-columns:1fr 1fr 1fr; } }
    .wn-area { display:flex; gap:10px; align-items:flex-start; border:1px solid #dfe3e8; border-radius:10px; padding:10px 12px; cursor:pointer; margin:0; background:#fff; }
    .wn-area:has(input:checked) { border-color:#4a5566; box-shadow:0 0 0 2px rgba(74,85,102,.14); }
    .wn-area input { margin-top:3px; accent-color:#4a5566; }
    .wn-area b { display:block; font-size:14px; font-weight:600; color:#1f242c; }
    .wn-area small { display:block; font-size:12.5px; color:#6b7a8f; line-height:1.4; margin-top:2px; }
    .wn-fields { display:grid; grid-template-columns:1fr; gap:12px; }
    .wn-fields label { display:block; font-size:12px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:#6b7a8f; margin:0 0 4px; }
    .wn-fields input[type=text], .wn-fields textarea { width:100%; font:inherit; font-size:14px; color:#1f242c; border:1px solid #c9d0d9; border-radius:8px; padding:8px 10px; background:#fff; }
    .wn-fields textarea { resize:vertical; line-height:1.5; }
    .wn-fields input:focus, .wn-fields textarea:focus { border-color:#4a5566; outline:0; }
    .wn-fields small { display:block; font-size:12.5px; color:#6b7a8f; margin-top:4px; }
    .wn-icon { display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; }
    .wn-icon label { flex-basis:100%; }
    .wn-icon input[type=text] { width:64px; text-align:center; font-size:20px; padding:4px 6px; }
    .wn-picks { display:flex; flex-wrap:wrap; gap:4px; }
    .wn-picks button { font-size:18px; line-height:1; width:34px; height:34px; border:1px solid #dfe3e8; border-radius:8px; background:#fff; cursor:pointer; padding:0; }
    .wn-picks button:hover { border-color:#4a5566; }
    .wn-go { margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .wn-go small { font-size:12.5px; color:#6b7a8f; }
    .wn-btn { font:inherit; font-size:13px; font-weight:600; color:#fff; background:#4a5566; border:0; border-radius:999px; padding:8px 16px; cursor:pointer; text-decoration:none; }
    .wn-btn:hover { background:#353e4b; color:#fff; }
    .wn-btn.soft { background:#fff; color:#4a5569; border:1px solid #dfe3e8; padding:6px 12px; }
    .wn-btn.soft:hover { border-color:#4a5566; background:#fff; color:#4a5569; }
    .wn-list { list-style:none; margin:0; padding:0; }
    .wn-row { display:grid; grid-template-columns:40px 1fr; gap:14px; padding:16px 0; border-top:1px solid #e6e9ee; }
    .wn-row:first-child { border-top:0; padding-top:4px; }
    .wn-row.off .wn-ic, .wn-row.off .wn-t, .wn-row.off .wn-m { opacity:.5; }
    .wn-ic { font-size:24px; line-height:1.2; text-align:center; }
    .wn-t { font-size:15px; font-weight:600; color:#1f242c; margin:0 0 2px; }
    .wn-m { font-size:14px; color:#434b57; line-height:1.5; margin:0 0 6px; white-space:pre-line; }
    .wn-meta { font-size:12.5px; color:#6b7a8f; margin:0; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .wn-tag { display:inline-block; font-size:11px; font-weight:600; letter-spacing:.05em; text-transform:uppercase; border-radius:999px; padding:2px 9px; background:#eceff3; color:#434b57; }
    .wn-tag.b2b { background:#e7edf5; color:#3c4f6b; }
    .wn-tag.emails { background:#e9f3ec; color:#1f5a35; }
    .wn-tag.hidden { background:#fbf1dc; color:#7a5a1f; }
    .wn-tools { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; align-items:flex-start; }
    .wn-tools form { display:inline; margin:0; }
    .wn-tools details[open] { flex-basis:100%; order:9; }
    .wn-tools details > summary { display:inline-block; list-style:none; cursor:pointer; }
    .wn-tools details > summary::-webkit-details-marker { display:none; }
    .wn-tools details[open] > summary { margin-bottom:12px; }
    .wn-tools details form { display:block; background:#f6f7f9; border:1px solid #e6e9ee; border-radius:10px; padding:14px; }
    .wn-empty { font-size:14px; color:#6b7a8f; margin:0; }
    ',
]);
?>
    <?php if ($flash): ?><p class="wn-flash<?= $flash['ok'] ? '' : ' err' ?>"><?= $e($flash['words']) ?></p><?php endif; ?>

    <form method="post" action="whats_new.php" class="wn-box">
      <?= $hid ?><input type="hidden" name="wn_action" value="add">
      <h2>Post a notification</h2>
      <p class="sub">Choose where it shows, then write it. Agents get a banner at the top of the page until they close it, and it stays under their bell after that.</p>
      <?= $fields($keep, 'New') ?>
      <div class="wn-go"><button type="submit" class="wn-btn">Post notification</button>
        <small>The portal shows it straight away; MH Send within about five minutes.</small></div>
    </form>

    <div class="wn-box">
      <h2>Posted</h2>
      <p class="sub"><?= count($items) ?> <?= count($items) === 1 ? 'notification' : 'notifications' ?>, newest first. Changing one does not tell anybody about it again; to announce something new, post a new one.</p>
      <?php if (!$items): ?>
      <p class="wn-empty">Nothing posted yet.</p>
      <?php else: ?>
      <ul class="wn-list">
        <?php foreach ($items as $it): ?>
        <li class="wn-row<?= $it['active'] ? '' : ' off' ?>">
          <div class="wn-ic" aria-hidden="true"><?= $e($it['icon']) ?></div>
          <div>
            <p class="wn-t"><?= $e($it['title']) ?></p>
            <p class="wn-m"><?= $e($it['message']) ?></p>
            <p class="wn-meta"><span class="wn-tag <?= $e($it['area']) ?>"><?= $e(WN_AREAS[$it['area']][0]) ?></span><?php if (!$it['active']): ?><span class="wn-tag hidden">Hidden</span><?php endif; ?><span><?= $e(wn_date($it['created'])) ?></span></p>
            <div class="wn-tools">
              <details><summary class="wn-btn soft">Change</summary>
                <form method="post" action="whats_new.php"><?= $hid ?><input type="hidden" name="wn_action" value="set"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                  <?= $fields($it, (string)$it['id']) ?>
                  <div class="wn-go"><button type="submit" class="wn-btn">Save changes</button></div>
                </form>
              </details>
              <form method="post" action="whats_new.php"><?= $hid ?><input type="hidden" name="wn_action" value="active"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="on" value="<?= $it['active'] ? '0' : '1' ?>"><button type="submit" class="wn-btn soft"><?= $it['active'] ? 'Hide' : 'Show again' ?></button></form>
              <form method="post" action="whats_new.php" onsubmit="return confirm('Delete this notification for good?')"><?= $hid ?><input type="hidden" name="wn_action" value="remove"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><button type="submit" class="wn-btn soft">Delete</button></form>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <script>
    // An icon button fills its form's Icon field.
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest('.wn-picks button');
      if (!b) return;
      var f = document.getElementById(b.dataset.for);
      if (f) { f.value = b.dataset.icon; f.focus(); }
    });
    </script>
<?php admin_tool_footer();
