<?php
/**
 * portal/social.php — Social posts (Nikki, 2026-10-11): an Instagram-ready
 * PNG of one of the agent's listings in four steps: template, listing, photo,
 * words, with a live preview and a download. inc/social.php has the rules;
 * inc/social_render.php has the templates. Hidden behind SOCIAL_BETA_EMAILS
 * while it is built: not in the nav, 404 for everyone else.
 *
 * The preview is an iframe whose srcdoc is the HTML social_render.php
 * returns for the form as it stands (fetch on every change, debounced),
 * scaled to fit. Download POSTs the same form to social_export.php, which
 * screenshots the same HTML. Share… (phones) fetches that PNG and hands it
 * to the share sheet, so it can go straight into the Instagram app.
 */
require_once __DIR__ . '/../inc/social.php';

$ctx = portal_context($conn);
social_gate();

$agent     = social_agent($conn, $ctx);
$listings  = array_values(social_listings($conn, $ctx));
$templates = social_templates();
$labels    = social_labels();
$render_url = portal_url($ctx, '/portal/social_render.php');
$export_url = portal_url($ctx, '/portal/social_export.php');
$photos_url = portal_url($ctx, '/portal/social_photos.php');
$next_sat   = (new DateTimeImmutable('next saturday'))->format('Y-m-d');

/** "Under Contract" for the RESO value; the rest as sent. */
function social_status_words(string $s): string {
    return $s === 'Active Under Contract' ? 'Under Contract' : $s;
}

portal_header($ctx, 'Social posts', 'social');
?>
<link rel="stylesheet" href="/assets/css/social.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/social.css') ?>">
  <h1 class="pt-h1">Social posts</h1>
  <p class="pt-lead">An Instagram-ready picture of one of your listings, in four steps: pick a template and a listing, choose the photo, check the words, then download it and post it yourself.</p>

<?php if (!$listings): ?>
  <div class="pt-flash err">We do not see any live listings under your name yet. If that is wrong, email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</div>
<?php else: ?>
  <div class="sp-grid">
    <form id="spForm" class="sp-form" method="post" action="<?= ph($export_url) ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">

      <section class="sp-step">
        <h2 class="sp-step-h"><span>1</span>Template</h2>
        <div class="sp-choices">
          <?php foreach ($templates as $k => $t): ?>
          <label class="sp-choice">
            <input type="radio" name="type" value="<?= ph($k) ?>"<?= $k === 'featured' ? ' checked' : '' ?>>
            <span class="sp-choice-in"><b><?= ph($t['name']) ?></b><small><?= ph($t['blurb']) ?></small></span>
          </label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h"><span>2</span>Listing</h2>
        <div class="sp-listings">
          <?php foreach ($listings as $i => $l): ?>
          <label class="sp-listing">
            <input type="radio" name="listing" value="<?= ph($l['id']) ?>"<?= $i === 0 ? ' checked' : '' ?>>
            <span class="sp-listing-in">
              <?php if ($l['thumb'] !== ''): ?><img src="<?= ph($l['thumb']) ?>" alt="" loading="lazy"><?php else: ?><span class="sp-noimg"></span><?php endif; ?>
              <span class="sp-listing-t">
                <b><?= ph($l['address']) ?></b>
                <small><?= ph(implode(' · ', array_filter([$l['city'], $l['price_text'], social_status_words((string)$l['status'])]))) ?></small>
              </span>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h"><span>3</span>Photo</h2>
        <div class="sp-photos" id="spPhotos"><div class="sp-wait">Loading photos…</div></div>
        <div class="sp-framing">
          <label>Left / right <input type="range" name="focus_x" min="0" max="100" value="50"></label>
          <label>Up / down <input type="range" name="focus_y" min="0" max="100" value="50"></label>
          <small>Slide to choose which part of the photo shows.</small>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h"><span>4</span>Words</h2>
        <div class="sp-fields">
          <label class="sp-field sp-only-featured">Label
            <select name="label"><?php foreach ($labels as $lb): ?><option><?= ph($lb) ?></option><?php endforeach; ?></select>
          </label>
          <div class="sp-only-openhouse sp-when" hidden>
            <label class="sp-field">Date <input type="date" name="oh_date" value="<?= ph($next_sat) ?>"></label>
            <label class="sp-field">From <input type="time" name="oh_start" value="11:00" step="900"></label>
            <label class="sp-field">To <input type="time" name="oh_end" value="14:00" step="900"></label>
          </div>
          <label class="sp-field">Address line <input name="headline" maxlength="80"></label>
          <label class="sp-field">Town line <input name="subline" maxlength="80"></label>
          <label class="sp-field">Price <input name="price" maxlength="40"></label>
          <label class="sp-field">Details <input name="specs" maxlength="80"></label>
          <label class="sp-field sp-only-featured">One line about it (optional) <input name="note" maxlength="90" placeholder="A West End Victorian, steps from downtown"></label>
          <h3 class="sp-sub">You</h3>
          <label class="sp-field">Name <input name="agent_name" maxlength="60" value="<?= ph($agent['name']) ?>"></label>
          <label class="sp-field sp-only-featured">Title <input name="agent_title" maxlength="60" value="<?= ph($agent['title']) ?>"></label>
          <label class="sp-field">Phone <input name="agent_phone" maxlength="30" value="<?= ph($agent['phone']) ?>"></label>
          <?php if ($agent['headshot'] !== ''): ?>
          <label class="sp-check"><input type="hidden" name="has_headshot_box" value="1"><input type="checkbox" name="show_headshot" value="1" checked> Show my headshot</label>
          <?php endif; ?>
        </div>
      </section>

      <div class="sp-actions">
        <button type="submit" class="sp-btn">Download PNG</button>
        <button type="button" class="sp-btn sp-btn-alt" id="spShare" hidden>Share…</button>
        <p class="sp-help">The download is a 1080 × 1440 PNG, Instagram's portrait size. On your phone, Share… hands it straight to the Instagram app.</p>
      </div>
    </form>

    <aside class="sp-preview">
      <div class="sp-stage" id="spStage">
        <iframe id="spFrame" title="Preview" width="1080" height="1440" scrolling="no"></iframe>
        <div class="sp-busy" id="spBusy"></div>
      </div>
      <p class="sp-cap">Preview. What you see is what you download.</p>
    </aside>
  </div>

<script>
(function () {
  const L = <?= json_encode($listings, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const RENDER = <?= json_encode($render_url) ?>, EXPORT = <?= json_encode($export_url) ?>, PHOTOS = <?= json_encode($photos_url) ?>;
  const form = document.getElementById('spForm'), frame = document.getElementById('spFrame'),
        stage = document.getElementById('spStage'), photos = document.getElementById('spPhotos'),
        busy = document.getElementById('spBusy'), share = document.getElementById('spShare');
  const f = n => form.elements[n];
  const esc = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  const listing = () => L.find(l => l.id === f('listing').value);

  function fill(l) {
    if (!l) return;
    f('headline').value = l.address; f('subline').value = l.subline;
    f('price').value = l.price_text; f('specs').value = l.specs_text;
  }
  function showGroups() {
    const oh = f('type').value === 'openhouse';
    form.querySelectorAll('.sp-only-featured').forEach(el => el.hidden = oh);
    form.querySelectorAll('.sp-only-openhouse').forEach(el => el.hidden = !oh);
  }
  let timer, seq = 0;
  function update() { clearTimeout(timer); timer = setTimeout(render, 250); }
  async function render() {
    const n = ++seq; busy.classList.add('on');
    try {
      const r = await fetch(RENDER, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      const html = await r.text();
      if (n === seq) frame.srcdoc = html;
    } catch (e) { /* the previous preview stays */ }
    finally { if (n === seq) busy.classList.remove('on'); }
  }
  async function loadPhotos() {
    const l = listing(); if (!l) return;
    photos.innerHTML = '<div class="sp-wait">Loading photos…</div>';
    const u = new URL(PHOTOS, location.origin); u.searchParams.set('listing', l.id);
    let j = { photos: [] };
    try { j = await (await fetch(u, { credentials: 'same-origin' })).json(); } catch (e) {}
    if (!j.photos || !j.photos.length) {
      photos.innerHTML = '<div class="sp-wait">This listing has no photos in the feed yet.</div>';
      update(); return;
    }
    photos.innerHTML = j.photos.map((p, i) =>
      '<label class="sp-photo"><input type="radio" name="photo" value="' + esc(p.url) + '"' + (i === 0 ? ' checked' : '') +
      '><img src="' + esc(p.thumb) + '" alt="" loading="lazy"></label>').join('');
    update();
  }
  function scale() {
    const w = stage.clientWidth;
    frame.style.transform = 'scale(' + (w / 1080) + ')';
    stage.style.height = Math.round(1440 * w / 1080) + 'px';
  }

  form.addEventListener('input', e => {
    const n = e.target.name;
    if (n === 'listing') { fill(listing()); f('focus_x').value = 50; f('focus_y').value = 50; loadPhotos(); return; }
    if (n === 'type') showGroups();
    update();
  });
  form.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'submit') e.preventDefault();
  });
  form.addEventListener('submit', () => { busy.classList.add('on'); setTimeout(() => busy.classList.remove('on'), 10000); });

  if (navigator.share && navigator.canShare) {
    share.hidden = false;
    share.addEventListener('click', async () => {
      share.disabled = true; share.textContent = 'Making your picture…';
      try {
        const r = await fetch(EXPORT, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
        if (!r.ok) throw new Error('export failed');
        const blob = await r.blob();
        const m = (r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/);
        const file = new File([blob], m ? m[1] : 'mont-haus-post.png', { type: 'image/png' });
        if (navigator.canShare({ files: [file] })) await navigator.share({ files: [file] });
        else { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = file.name; a.click(); }
      } catch (e) {
        if (e.name !== 'AbortError') alert('Sorry, that did not work. Try Download PNG instead.');
      }
      share.disabled = false; share.textContent = 'Share…';
    });
  }

  new ResizeObserver(scale).observe(stage); scale();
  showGroups();
  fill(listing());
  loadPhotos();
})();
</script>
<?php endif;
portal_footer();
