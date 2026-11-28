<?php
/**
 * inc/video_list.php — the Video tool's first page: the saved videos (Nikki,
 * 2026-11-27: "a separate landing page with the saved videos. A user can
 * click a create new button or select one to edit ... Replicate an existing
 * video with its settings"). Included by template_video.php when no video is
 * being edited.
 *
 * Expects: $ctx, $csrf, $saved_url. Each card: Edit (?open=<id>), Replicate
 * (?copy=<id>: the editor opens with everything that video holds, as a NEW
 * video; the one copied is not touched) and Delete.
 */
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$vd_saved = video_saved_list($ctx);
$vd_kinds = ['listing' => 'Listing', 'mine' => 'My Photos', 'clip' => 'Video Clip'];
?>
<div class="vl-top">
  <a class="sp-btn" href="template_video.php?new=1">Create new video</a>
</div>
<p class="sp-help" id="vlMsg" hidden></p>
<p class="sp-note" id="vlNone"<?= $vd_saved ? ' hidden' : '' ?>>No saved videos yet. A video is saved each time you make one, or with Save for later.</p>
<div class="vl-grid" id="vlGrid">
  <?php foreach ($vd_saved as $s):
      $about = array_filter([
          $vd_kinds[$s['kind']] ?? '',
          $s['kind'] !== 'clip' && $s['photos'] > 0 ? $s['photos'] . ($s['photos'] === 1 ? ' photo' : ' photos') : '',
          $s['secs'] > 0 ? rtrim(rtrim(number_format($s['secs'], 1), '0'), '.') . ' seconds' : '',
      ]);
  ?>
  <article class="vl-card" data-id="<?= $e($s['id']) ?>">
    <a class="vl-thumb" href="template_video.php?open=<?= $e($s['id']) ?>" aria-label="Edit <?= $e($s['name']) ?>"><?php if ($s['thumb'] !== ''): ?><img src="<?= $e($s['thumb']) ?>" alt="" loading="lazy"><?php endif; ?></a>
    <div class="vl-body">
      <h2 class="vl-name"><?= $e($s['name']) ?></h2>
      <p class="vl-about"><?= $e(implode('  ·  ', $about)) ?></p>
      <p class="vl-when">Saved <time data-t="<?= (int)$s['saved'] ?>"><?= $e(date('M j, Y', $s['saved'])) ?></time></p>
      <div class="vl-acts">
        <a class="vd-mini" href="template_video.php?open=<?= $e($s['id']) ?>">Edit</a>
        <a class="vd-mini" href="template_video.php?copy=<?= $e($s['id']) ?>" title="Start a new video with everything this one holds">Replicate</a>
        <button type="button" class="vd-mini vl-del">Delete</button>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<script>
(function () {
  const SAVED = <?= json_encode($saved_url) ?>, CSRF = <?= json_encode($csrf) ?>;
  const grid = document.getElementById('vlGrid'), msg = document.getElementById('vlMsg');
  // The time each was saved, on the reader's own clock.
  grid.querySelectorAll('time[data-t]').forEach(t => { t.textContent = new Date(+t.dataset.t * 1000).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }); });
  grid.addEventListener('click', async e => {
    const b = e.target.closest('.vl-del'); if (!b) return;
    const card = b.closest('.vl-card');
    if (!confirm('Delete "' + card.querySelector('.vl-name').textContent + '"? Videos you already downloaded are not affected.')) return;
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('delete', card.dataset.id);
    let j;
    try { j = await (await fetch(SAVED, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (err) { j = null; }
    if (!j || j.error) { msg.hidden = false; msg.classList.add('bad'); msg.textContent = (j && j.error) || 'That could not be deleted. Please try again.'; return; }
    card.remove(); msg.hidden = true;
    document.getElementById('vlNone').hidden = !!grid.querySelector('.vl-card');
  });
})();
</script>
