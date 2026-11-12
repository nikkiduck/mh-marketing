<?php
/**
 * social_linkinbio.php — Link in Bio (Nikki, 2026-11-12): the covers on
 * monthaus.com/linkinbio, the link in the corporate Instagram bio. A grid of
 * post covers, each opening the address chosen here (a listing's page, an
 * agent's page, anything else), so a caption can say "visit our link in bio"
 * for a listing whose agent has no social account.
 *
 *   - From Weekly Plans: a saved Post piece's "Link in bio ›" button opens
 *     this page with ?week=&slot=; the Add this post box has the destination
 *     prefilled (bio_plan_dest()) and makes the cover from the post's first
 *     picture, the way Download makes it.
 *   - Add one by hand: any picture and any address (a reel, a repost, a post
 *     made outside the plan).
 *   - The list: change the words or the address, move a cover, remake a plan
 *     cover after the post changed, remove one. The website shows the first
 *     BIO_MAX; the rest stay here, greyed.
 *
 * Every change is sent to the website straight away (mk_site_sync(), the
 * roster's Sync to Website); the hourly sync is the backstop. The store is
 * inc/linkinbio.php. POSTs come back here: csrf_token + bio_action = add_plan
 * | add_manual | set | move | recover | remove. Super admin only.
 */
require_once __DIR__ . '/inc/plan.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
require_once __DIR__ . '/inc/site_sync.php';      // mk_site_sync()
$ctx = plan_gate($conn);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$page_url = rtrim(PUBLIC_SITE_URL, '/') . '/linkinbio';

/**
 * A Weekly Plans post piece ready to become a cover: ['kind', 'piece',
 * 'day', 'words'], or words saying why not. Only the POST piece: a story is
 * gone in a day and carries its own link sticker.
 */
$plan_piece = function (string $week, int $slot) use ($conn): array|string {
    if (!plan_store_key_ok($week)) return 'That week is not one of the plan\'s.';
    $plan = plan_week_load($week);
    $def = plan_week_slots($plan)[$slot] ?? null;
    $item = $plan['items'][$slot] ?? null;
    $piece = plan_item_pieces($item)['post'] ?? null;
    if (!$def || !$piece) return 'There is no saved post for that day. Open the piece from Weekly Plans, make the post and save it to the week first.';
    return ['kind' => (string)($item['kind'] ?? $def['type']), 'piece' => $piece, 'day' => $def['day'], 'words' => $def['words']];
};
/** The piece's first picture as PNG bytes (the cover of a set), or words saying why not. */
$plan_png = function (array $p) use ($conn, $ctx): string|array {
    $form = $p['piece']['form'];
    if ($p['kind'] === 'property') {
        $l = plan_listing($conn, (string)($form['listing'] ?? ''));
        // The tools draw from the live feed (and closings of the last PLAN_CLOSED_DAYS days); a listing that has left it cannot be drawn again.
        if (!$l) return ['error' => 'That post\'s listing is no longer in the feed, so its picture cannot be made here. Use Add one by hand below with the picture you posted.'];
        plan_listing_tiles($l);   // the listing's photos into the session, so the saved ids pass plan_photo() (as plan_export.php)
    }
    $r = plan_post_from_request($conn, $ctx, $form);
    if (is_string($r)) return ['error' => 'The post could not be drawn from what is saved. Open it from Weekly Plans, save it again, and come back. (' . $r . ')'];
    $frame = $r['frames'][0];
    $png = social_export_png(plan_render_frame($frame, null, true), plan_frame_height($frame));
    return $png === null ? ['error' => 'The picture could not be made. Please try again in a minute.'] : $png;
};

// ── Changes ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_csrf_ok()) portal_stop('Please try again', 'That page had been open too long. Go back, reload it, and try again.');
    $action = (string)($_POST['bio_action'] ?? '');
    $id = (string)($_POST['id'] ?? '');
    $err = ''; $done = '';
    $url = bio_clean_url((string)($_POST['url'] ?? ''));
    $title = bio_clean_title((string)($_POST['title'] ?? ''));
    $needs_url = in_array($action, ['add_plan', 'add_manual', 'set'], true);
    if ($needs_url && $url === '') {
        $err = 'That link is not a web address the page can open. It has to start with https:// (or just monthaus.com/...).';
    } elseif ($action === 'add_plan') {
        $week = (string)($_POST['week'] ?? ''); $slot = (int)($_POST['slot'] ?? -1);
        $p = $plan_piece($week, $slot);
        if (is_string($p)) $err = $p;
        elseif (bio_find_plan($week, $slot)) $err = 'That post is already in the bio. Change its link in the list below.';
        else {
            $png = $plan_png($p);
            if (is_array($png)) $err = $png['error'];
            elseif (bio_add(['url' => $url, 'title' => $title !== '' ? $title : bio_clean_title((string)($p['piece']['summary'] ?? '')), 'source' => 'plan', 'week' => $week, 'slot' => $slot], $png) === '') $err = 'The cover could not be saved on the server.';
            else $done = 'Added to the bio: ' . $p['day'] . '\'s ' . $p['words'] . '.';
        }
    } elseif ($action === 'add_manual') {
        $f = $_FILES['cover'] ?? null; $tmp = (string)($f['tmp_name'] ?? '');
        $mime = $tmp !== '' && is_uploaded_file($tmp) ? (string)@mime_content_type($tmp) : '';
        if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $mime === '') $err = 'The picture did not arrive. Please choose it again.';
        elseif ((int)$f['size'] > 20 * 1024 * 1024) $err = 'That picture is over 20 MB. Please use a smaller one.';
        elseif (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) $err = 'Please use a JPG, PNG or WEBP picture' . (in_array($mime, ['image/heic', 'image/heif'], true) ? ' (this one is HEIC; your phone can export it as JPG)' : '') . '.';
        elseif (bio_add(['url' => $url, 'title' => $title, 'source' => 'manual'], (string)file_get_contents($tmp), $mime === 'image/jpeg' ? $tmp : '') === '') $err = 'That file could not be read as a picture, or could not be saved.';
        else $done = 'Added to the bio.';
    } elseif ($action === 'set') {
        if (!bio_id_ok($id) || !bio_set($id, $url, $title)) $err = 'That cover could not be changed.'; else $done = 'Saved.';
    } elseif ($action === 'move') {
        $dir = (string)($_POST['dir'] ?? '');
        if (!bio_id_ok($id) || !in_array($dir, ['up', 'down', 'top'], true) || !bio_move($id, $dir === 'top' ? 'top' : ($dir === 'up' ? -1 : 1))) $err = 'That cover could not be moved.'; else $done = 'Moved.';
    } elseif ($action === 'remove') {
        if (!bio_remove($id)) $err = 'That cover could not be removed.'; else $done = 'Removed from the bio.';
    } elseif ($action === 'recover') {
        $it = null; foreach (bio_items() as $x) if ($x['id'] === $id) $it = $x;
        $p = $it && ($it['source'] ?? '') === 'plan' ? $plan_piece((string)$it['week'], (int)$it['slot']) : 'That cover did not come from a Weekly Plans post.';
        if (is_string($p)) $err = $p;
        else {
            $png = $plan_png($p);
            if (is_array($png)) $err = $png['error'];
            elseif (!bio_recover($id, $png)) $err = 'The new cover could not be saved on the server.';
            else $done = 'The cover was made again from the saved post.';
        }
    } else {
        $err = 'That is not something this page does.';
    }
    $flash = ['ok' => $err === '', 'words' => $err !== '' ? $err : $done, 'site' => ''];
    if ($err === '') {
        $r = mk_site_sync(false);   // the same run as the roster's Sync to Website; about a second
        $flash['site'] = $r['ok'] ? 'The website has it.' : 'Saved here, but the website did not take it just now (' . implode(' ', array_slice($r['lines'], 0, 2)) . '). It tries again by itself at half past the hour.';
        $flash['site_ok'] = $r['ok'];
    }
    $_SESSION['bio_flash'] = $flash;
    $back = 'social_linkinbio.php';
    if ($err !== '' && $action === 'add_plan') $back .= '?week=' . rawurlencode((string)($_POST['week'] ?? '')) . '&slot=' . (int)($_POST['slot'] ?? 0);
    header('Location: ' . $back);
    exit;
}

// ── The page ─────────────────────────────────────────────────────────────────
$flash = $_SESSION['bio_flash'] ?? null; unset($_SESSION['bio_flash']);
$items = bio_items();
$csrf = (string)$_SESSION['csrf_token'];
$hid = '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';

// A post handed over by Weekly Plans (?week=&slot=).
$offer = null; $offer_note = '';
if (isset($_GET['week'], $_GET['slot'])) {
    $ow = (string)$_GET['week']; $os = (int)$_GET['slot'];
    $p = $plan_piece($ow, $os);
    if (is_string($p)) $offer_note = $p;
    elseif ($has = bio_find_plan($ow, $os, $items)) $offer_note = 'That post is already in the bio: it is marked below.';
    else $offer = $p + ['week' => $ow, 'slot' => $os, 'dest' => bio_plan_dest($conn, $p['kind'], $p['piece']['form'])];
}
$marked = isset($has) && $has ? $has['id'] : '';

admin_tool_header([
    'title'   => 'Link in Bio',
    'hint'    => 'The covers on monthaus.com/linkinbio, the link in the Instagram bio. Each cover opens the address you give it. Newest first; the page shows the first ' . BIO_MAX . '.',
    'actions' => '<a class="btn btn-outline btn-sm" href="' . $e($page_url) . '" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> View the page</a>'
               . '<a class="btn btn-outline btn-sm" href="social_plan.php"><i class="ti ti-calendar-week"></i> Weekly Plans</a>'
               . '<a class="btn btn-outline btn-sm" href="social_media.php"><i class="ti ti-brand-instagram"></i> Social Media</a>',
    'active'  => 'social',
    'css'     => '
    .lb-flash { border-radius:10px; padding:10px 14px; font-size:14px; margin:0 0 16px; background:#e9f3ec; border:1px solid #bfe0c9; color:#1f5a35; }
    .lb-flash.err { background:#fbeaea; border-color:#f0c9c9; color:#8f2424; }
    .lb-flash.warn { background:#fbf1dc; border-color:#ecd9a8; color:#7a5a1f; }
    .lb-box { background:#fff; border:1px solid #dfe3e8; border-radius:14px; padding:18px 20px; margin:0 0 20px; }
    .lb-box.offer { border-color:#4a5566; box-shadow:0 0 0 2px rgba(74,85,102,.14); }
    .lb-box h2 { font-family:"Cormorant Garamond",Georgia,serif; font-weight:500; font-size:24px; margin:0 0 4px; color:#1f242c; }
    .lb-box p.sub { font-size:13.5px; color:#6b7a8f; margin:0 0 14px; line-height:1.5; }
    .lb-fields { display:grid; grid-template-columns:1fr; gap:12px; }
    @media (min-width:760px) { .lb-fields.two { grid-template-columns:1fr 1fr; } }
    .lb-fields label { display:block; font-size:12px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:#6b7a8f; margin:0 0 4px; }
    .lb-fields input[type=text], .lb-fields input[type=url] { width:100%; font:inherit; font-size:14px; color:#1f242c; border:1px solid #c9d0d9; border-radius:8px; padding:8px 10px; background:#fff; }
    .lb-fields input:focus { border-color:#4a5566; outline:0; }
    .lb-fields small { display:block; font-size:12.5px; color:#6b7a8f; margin-top:4px; }
    .lb-go { margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .lb-btn { font:inherit; font-size:13px; font-weight:600; color:#fff; background:#4a5566; border:0; border-radius:999px; padding:8px 16px; cursor:pointer; text-decoration:none; }
    .lb-btn:hover { background:#353e4b; color:#fff; }
    .lb-btn.soft { background:#fff; color:#4a5569; border:1px solid #dfe3e8; padding:6px 12px; }
    .lb-btn.soft:hover { border-color:#4a5566; background:#fff; color:#4a5569; }
    .lb-btn[disabled] { opacity:.4; cursor:default; }
    .lb-list { list-style:none; margin:0; padding:0; }
    .lb-row { display:grid; grid-template-columns:96px 1fr; gap:16px; padding:16px 0; border-top:1px solid #e6e9ee; }
    .lb-row:first-child { border-top:0; padding-top:4px; }
    .lb-row.off { opacity:.55; }
    .lb-row.marked { background:#f3f5f8; margin:0 -12px; padding-left:12px; padding-right:12px; border-radius:10px; }
    .lb-row img { width:96px; aspect-ratio:3/4; object-fit:cover; border-radius:6px; background:#eceff3; display:block; }
    .lb-n { font-size:12px; color:#6b7a8f; margin:0 0 8px; }
    .lb-n b { color:#434b57; font-weight:600; }
    .lb-tools { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; align-items:center; }
    .lb-tools form { display:inline; margin:0; }
    .lb-empty { font-size:14px; color:#6b7a8f; margin:0; }
    @media (max-width:560px) { .lb-row { grid-template-columns:64px 1fr; gap:12px; } .lb-row img { width:64px; } }
    ',
]);
?>
    <?php if ($flash): ?>
    <p class="lb-flash<?= $flash['ok'] ? ((($flash['site_ok'] ?? true)) ? '' : ' warn') : ' err' ?>"><?= $e($flash['words']) ?><?= $flash['site'] !== '' ? ' ' . $e($flash['site']) : '' ?></p>
    <?php endif; ?>
    <?php if ($offer_note !== ''): ?><p class="lb-flash warn"><?= $e($offer_note) ?></p><?php endif; ?>

    <?php if ($offer): ?>
    <form method="post" action="social_linkinbio.php" class="lb-box offer" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='Making the cover…';">
      <?= $hid ?><input type="hidden" name="bio_action" value="add_plan"><input type="hidden" name="week" value="<?= $e($offer['week']) ?>"><input type="hidden" name="slot" value="<?= (int)$offer['slot'] ?>">
      <h2>Add this post</h2>
      <p class="sub"><?= $e($offer['day']) ?>: <?= $e($offer['piece']['summary'] ?? '') ?>. The cover is the post's first picture, as saved to the week.</p>
      <div class="lb-fields two">
        <div><label for="lbOfferUrl">Where the cover goes</label>
          <input type="text" id="lbOfferUrl" name="url" maxlength="<?= BIO_URL_MAX ?>" value="<?= $e($offer['dest']) ?>" placeholder="https://monthaus.com/…" required>
          <small><?= $offer['dest'] !== '' ? ($offer['kind'] === 'property' ? 'The listing\'s page on monthaus.com. Change it to the agent\'s page or anything else.' : 'Filled in from the post. Change it to anything else.') : 'Paste the page this post should open.' ?></small></div>
        <div><label for="lbOfferTitle">Words for the picture</label>
          <input type="text" id="lbOfferTitle" name="title" maxlength="<?= BIO_TITLE_MAX ?>" value="<?= $e(bio_clean_title((string)($offer['piece']['summary'] ?? ''))) ?>">
          <small>Not shown on the page; read aloud by screen readers.</small></div>
      </div>
      <div class="lb-go"><button type="submit" class="lb-btn">Add to link in bio</button><a class="lb-btn soft" href="social_plan.php?week=<?= $e(rawurlencode($offer['week'])) ?>">Back to the week</a></div>
    </form>
    <?php endif; ?>

    <div class="lb-box">
      <h2>On the page</h2>
      <p class="sub"><?= count($items) ?> <?= count($items) === 1 ? 'cover' : 'covers' ?>, in the page's order. To add a post from the plan, use its Link in bio button on <a href="social_plan.php">Weekly Plans</a>.</p>
      <?php if (!$items): ?>
      <p class="lb-empty">Nothing yet. The page shows the logo and its three buttons until the first cover is added.</p>
      <?php else: ?>
      <ul class="lb-list">
        <?php foreach ($items as $k => $it): $on = $k < BIO_MAX; $last = $k === count($items) - 1; ?>
        <li class="lb-row<?= $on ? '' : ' off' ?><?= $it['id'] === $marked ? ' marked' : '' ?>">
          <a href="<?= $e($it['url']) ?>" target="_blank" rel="noopener"><img src="<?= $e(bio_cover_url($it)) ?>" alt="<?= $e($it['title'] ?? '') ?>" loading="lazy"></a>
          <div>
            <p class="lb-n"><b><?= $on ? 'No. ' . ($k + 1) : 'Not on the page (over ' . BIO_MAX . ')' ?></b> · <?= ($it['source'] ?? '') === 'plan' ? 'from Weekly Plans, ' . $e((string)($it['week'] ?? '')) : 'added by hand' ?> · <?= $e(plan_when((string)($it['added_at'] ?? ''))) ?></p>
            <form method="post" action="social_linkinbio.php"><?= $hid ?><input type="hidden" name="bio_action" value="set"><input type="hidden" name="id" value="<?= $e($it['id']) ?>">
              <div class="lb-fields two">
                <div><label>Where the cover goes</label><input type="text" name="url" maxlength="<?= BIO_URL_MAX ?>" value="<?= $e($it['url']) ?>" required></div>
                <div><label>Words for the picture</label><input type="text" name="title" maxlength="<?= BIO_TITLE_MAX ?>" value="<?= $e($it['title'] ?? '') ?>"></div>
              </div>
              <div class="lb-tools"><button type="submit" class="lb-btn soft">Save changes</button></div>
            </form>
            <div class="lb-tools">
              <?php foreach ([['top', 'To the top', $k === 0], ['up', '↑ Earlier', $k === 0], ['down', '↓ Later', $last]] as [$dir, $w, $dis]): ?>
              <form method="post" action="social_linkinbio.php"><?= $hid ?><input type="hidden" name="bio_action" value="move"><input type="hidden" name="id" value="<?= $e($it['id']) ?>"><input type="hidden" name="dir" value="<?= $dir ?>"><button type="submit" class="lb-btn soft"<?= $dis ? ' disabled' : '' ?>><?= $w ?></button></form>
              <?php endforeach; ?>
              <?php if (($it['source'] ?? '') === 'plan'): ?>
              <form method="post" action="social_linkinbio.php" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='Making the cover…';"><?= $hid ?><input type="hidden" name="bio_action" value="recover"><input type="hidden" name="id" value="<?= $e($it['id']) ?>"><button type="submit" class="lb-btn soft" title="Make the picture again from the post as it is saved to the week now">Remake cover</button></form>
              <?php endif; ?>
              <form method="post" action="social_linkinbio.php" onsubmit="return confirm('Take this cover off the link in bio page?')"><?= $hid ?><input type="hidden" name="bio_action" value="remove"><input type="hidden" name="id" value="<?= $e($it['id']) ?>"><button type="submit" class="lb-btn soft">Remove</button></form>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <form method="post" action="social_linkinbio.php" enctype="multipart/form-data" class="lb-box">
      <?= $hid ?><input type="hidden" name="bio_action" value="add_manual">
      <h2>Add one by hand</h2>
      <p class="sub">For a reel, a repost or anything made outside the plan: the picture people will recognise from the Instagram grid, and the page it should open. It goes to the top.</p>
      <div class="lb-fields two">
        <div><label for="lbCover">The picture</label><input type="file" id="lbCover" name="cover" accept="image/jpeg,image/png,image/webp" required>
          <small>JPG, PNG or WEBP. Shown as a 3:4 tile like the Instagram grid, cut from the middle.</small></div>
        <div><label for="lbUrl">Where the cover goes</label><input type="text" id="lbUrl" name="url" maxlength="<?= BIO_URL_MAX ?>" placeholder="https://monthaus.com/…" required></div>
        <div><label for="lbTitle">Words for the picture</label><input type="text" id="lbTitle" name="title" maxlength="<?= BIO_TITLE_MAX ?>" placeholder="New listing, 123 Main St, Aspen"></div>
      </div>
      <div class="lb-go"><button type="submit" class="lb-btn">Add to link in bio</button></div>
    </form>
<?php admin_tool_footer();
