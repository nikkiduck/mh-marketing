<?php
/**
 * portal/social.php — Social posts (Nikki, 2026-10-11; reworked 2026-10-12):
 * an Instagram-ready PNG of one of the agent's listings in four steps:
 * template and look, listing, photo (the listing's or their own, framed by
 * drag and zoom), words (a headline and four sub lines with styles), with a
 * live preview and a download. inc/social.php has the rules;
 * inc/social_render.php has the templates. Hidden behind SOCIAL_BETA_EMAILS
 * while it is built: not in the nav, 404 for everyone else.
 *
 * The preview is an iframe whose srcdoc is the HTML social_render.php
 * returns for the form as it stands (fetch on every change, debounced),
 * scaled to fit. Download POSTs the same form to social_export.php, which
 * screenshots the same HTML. (A Share… button for phones' share sheets was
 * removed 2026-10-12: it opened the sheet, then errored; Nikki.) The
 * framing box in step 3 applies the same CSS the renderer does (zoom, focus),
 * as a control only; the post itself is never drawn here.
 */
require_once __DIR__ . '/../inc/social.php';

$ctx = portal_context($conn);
social_gate();

$listings  = array_values(social_listings($conn, $ctx));
$templates = social_templates_offered();
$themes    = social_themes();
$labels    = social_labels();
$styles    = social_line_styles();
$defaults  = social_line_defaults();
$render_url = portal_url($ctx, '/portal/social_render.php');
$export_url = portal_url($ctx, '/portal/social_export.php');
$photos_url = portal_url($ctx, '/portal/social_photos.php');
$upload_url = portal_url($ctx, '/portal/social_upload.php');
$next_sat   = (new DateTimeImmutable('next saturday'))->format('Y-m-d');
$line_names = ['Text 1', 'Text 2', 'Text 3', 'Text 4'];

/** "Under Contract" for the RESO value; the rest as sent. */
function social_status_words(string $s): string {
    return $s === 'Active Under Contract' ? 'Under Contract' : $s;
}

portal_header($ctx, 'Social posts', 'social');
?>
<link rel="stylesheet" href="/assets/css/social.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/social.css') ?>">
  <h1 class="pt-h1">Instagram Post Template</h1>
  <p class="pt-lead">Pick a listing, choose and frame the photo, edit the text, and download.</p>

<?php if (!$listings): ?>
  <div class="pt-flash err">We do not see any live listings under your name yet. If that is wrong, email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</div>
<?php else: ?>
  <div class="sp-grid">
    <form id="spForm" class="sp-form" method="post" action="<?= ph($export_url) ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="focus_x" value="50">
      <input type="hidden" name="focus_y" value="50">

      <section class="sp-step">
        <h2 class="sp-step-h"><span>1</span>Template</h2>
        <div class="sp-choices">
          <?php foreach ($templates as $k => $t): ?>
          <label class="sp-choice">
            <input type="radio" name="type" value="<?= ph($k) ?>"<?= $k === array_key_first($templates) ? ' checked' : '' ?>>
            <span class="sp-choice-in"><b><?= ph($t['name']) ?></b><small><?= ph($t['blurb']) ?></small></span>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Look</span>
          <?php foreach ($themes as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="theme" value="<?= ph($k) ?>"<?= $k === 'dark' ? ' checked' : '' ?>><span><?= ph($t) ?></span></label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h"><span>2</span>Listing</h2>
        <label class="sp-field">Which listing
          <select name="listing">
            <?php // Sales first, then rentals, under headers (Nikki, 2026-10-12: a team's list is long).
            $groups = ['Listings' => array_filter($listings, fn($l) => !(int)$l['is_rental']),
                       'Rentals'  => array_filter($listings, fn($l) => (int)$l['is_rental'])];
            foreach ($groups as $gname => $group): if (!$group) continue; ?>
            <optgroup label="<?= ph($gname) ?>">
              <?php foreach ($group as $l): ?>
              <option value="<?= ph($l['id']) ?>"><?= ph(implode('  ·  ', array_filter([$l['address'] . ', ' . $l['city'], $l['price_text'], social_status_words((string)$l['status'])]))) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h"><span>3</span>Photo</h2>
        <div class="sp-photos" id="spPhotos"><div class="sp-wait">Loading photos…</div></div>
        <div class="sp-drop" id="spDrop">
          <span id="spDropMsg">Or use your own: drop a photo here, or <label class="sp-link" for="spFile">choose one</label>.</span>
          <small>JPG, PNG or WEBP, up to 20 MB.</small>
          <input type="file" id="spFile" accept="image/jpeg,image/png,image/webp,image/*" hidden>
        </div>
        <div class="sp-framing">
          <div class="sp-framer empty" id="spFramer" style="aspect-ratio:1080 / <?= social_photo_height(array_key_first($templates)) ?>"><img id="spFramerImg" alt="" draggable="false"><span class="sp-framer-hint">Drag to move</span></div>
          <label class="sp-zoom">Zoom <input type="range" name="zoom" min="1" max="3" step="0.02" value="1"></label>
          <small>Drag the photo to choose what shows; zoom in to crop closer.</small>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h"><span>4</span>Text</h2>
        <div class="sp-fields">
          <div class="sp-two">
            <label class="sp-field">Label on the photo
              <select name="label" id="spLabel">
                <?php foreach ($labels as $lb): ?><option value="<?= ph($lb) ?>"<?= $lb === 'Featured Listing' ? ' selected' : '' ?>><?= ph($lb) ?></option><?php endforeach; ?>
                <option value="__none">No label</option>
                <option value="__custom">Custom…</option>
              </select>
            </label>
            <label class="sp-field" id="spCustomWrap" hidden>Your label <input name="label_custom" maxlength="28" placeholder="Price Improvement"></label>
          </div>
          <div class="sp-only-openhouse sp-when" hidden>
            <label class="sp-field">Date <input type="date" name="oh_date" value="<?= ph($next_sat) ?>"></label>
            <label class="sp-field">From <input type="time" name="oh_start" value="11:00" step="900"></label>
            <label class="sp-field">To <input type="time" name="oh_end" value="14:00" step="900"></label>
          </div>
          <label class="sp-field">Headline <input name="headline" maxlength="80"></label>
          <?php foreach ($line_names as $i => $nm): ?>
          <div class="sp-line<?= $i >= 2 ? ' sp-only-long' : '' ?>">
            <label class="sp-field"><?= ph($nm) ?> <input name="line_text[]" maxlength="90"<?= $i === 3 ? ' placeholder="A West End Victorian, steps from downtown"' : '' ?>></label>
            <label class="sp-field sp-style"><?= ph($nm) ?> style
              <select name="line_style[]"><?php foreach ($styles as $k => $w): ?><option value="<?= ph($k) ?>"<?= $k === $defaults[$i] ? ' selected' : '' ?>><?= ph($w) ?></option><?php endforeach; ?></select>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="sp-actions">
        <button type="submit" class="sp-btn">Download PNG</button>
        <p class="sp-help">The download is a 1080 × 1440 PNG, Instagram's portrait size.</p>
      </div>
    </form>

    <aside class="sp-preview">
      <div class="sp-stage" id="spStage">
        <iframe id="spFrame" title="Preview" width="1080" height="1440" scrolling="no"></iframe>
        <div class="sp-busy" id="spBusy"></div>
      </div>
      <p class="sp-cap">Preview.</p>
    </aside>
  </div>

<script>
(function () {
  const L = <?= json_encode($listings, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const RENDER = <?= json_encode($render_url) ?>, PHOTOS = <?= json_encode($photos_url) ?>, UPLOAD = <?= json_encode($upload_url) ?>;
  const PHOTO_H = <?= json_encode(array_map('social_photo_height', array_combine(array_keys($templates), array_keys($templates)))) ?>;
  const isOH = t => t.indexOf('openhouse') !== -1, isFull = t => t.indexOf('photo') === 0;
  const form = document.getElementById('spForm'), frame = document.getElementById('spFrame'),
        stage = document.getElementById('spStage'), photos = document.getElementById('spPhotos'),
        busy = document.getElementById('spBusy'),
        drop = document.getElementById('spDrop'), dropMsg = document.getElementById('spDropMsg'),
        fileIn = document.getElementById('spFile'),
        framer = document.getElementById('spFramer'), fimg = document.getElementById('spFramerImg'),
        labelSel = document.getElementById('spLabel'), customWrap = document.getElementById('spCustomWrap');
  const f = n => form.elements[n];
  const esc = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  const listing = () => L.find(l => l.id === f('listing').value);
  const lineText = i => form.querySelectorAll('input[name="line_text[]"]')[i];

  // ── Words pre-filled from the listing (the first three sub lines; the fourth is the agent's) ──
  function fill(l) {
    if (!l) return;
    f('headline').value = l.address;
    lineText(0).value = l.town; lineText(1).value = l.price_text; lineText(2).value = l.specs_text;
  }
  function showGroups() {
    const t = f('type').value;
    form.querySelectorAll('.sp-only-openhouse').forEach(el => el.hidden = !isOH(t));
    form.querySelectorAll('.sp-only-long').forEach(el => el.hidden = isFull(t));
    customWrap.hidden = labelSel.value !== '__custom';
    framer.style.aspectRatio = '1080 / ' + PHOTO_H[t];
  }
  // Switching template swaps the default label unless the agent chose something else.
  function labelForType() {
    const oh = isOH(f('type').value);
    if (oh && labelSel.value === 'Featured Listing') labelSel.value = 'Open House';
    if (!oh && labelSel.value === 'Open House') labelSel.value = 'Featured Listing';
  }

  // ── Preview ──
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
  function scale() {
    const w = stage.clientWidth;
    frame.style.transform = 'scale(' + (w / 1080) + ')';
    stage.style.height = Math.round(1440 * w / 1080) + 'px';
  }

  // ── Photos: the listing's, then the agent's own ──
  function photoTile(p, checked) {
    return '<label class="sp-photo' + (p.own ? ' own' : '') + '"><input type="radio" name="photo" value="' + esc(p.id) + '" data-large="' + esc(p.large) + '"' +
           (checked ? ' checked' : '') + '><img src="' + esc(p.thumb) + '" alt="" loading="lazy">' + (p.own ? '<i>Yours</i>' : '') + '</label>';
  }
  async function loadPhotos() {
    const l = listing(); if (!l) return;
    photos.innerHTML = '<div class="sp-wait">Loading photos…</div>';
    const u = new URL(PHOTOS, location.origin); u.searchParams.set('listing', l.id);
    let j = { photos: [] };
    try { j = await (await fetch(u, { credentials: 'same-origin' })).json(); } catch (e) {}
    if (!j.photos || !j.photos.length) {
      photos.innerHTML = '<div class="sp-wait">This listing has no photos in the feed yet. Add your own below.</div>';
      framerSet(''); update(); return;
    }
    photos.innerHTML = j.photos.map((p, i) => photoTile(p, i === 0)).join('');
    framerSet(j.photos[0].large); update();
  }
  async function upload(file) {
    if (!file) return;
    drop.classList.add('busy'); dropMsg.textContent = 'Uploading ' + file.name + '…';
    const fd = new FormData(); fd.append('csrf_token', f('csrf_token').value); fd.append('photo', file);
    let j = {};
    try { j = await (await fetch(UPLOAD, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); }
    catch (e) { j = { error: 'The upload did not go through. Please try again.' }; }
    drop.classList.remove('busy');
    fileIn.value = '';
    if (j.error) { dropMsg.textContent = j.error; return; }
    dropMsg.innerHTML = 'Added. Drop another, or <label class="sp-link" for="spFile">choose one</label>.';
    photos.querySelector('.sp-wait')?.remove();
    photos.insertAdjacentHTML('afterbegin', photoTile({ id: j.id, thumb: j.thumb, large: j.large, own: true }, true));
    framerSet(j.large); update();
  }
  fileIn.addEventListener('change', e => upload(e.target.files[0]));
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => upload(e.dataTransfer.files[0]));

  // ── Framing: the same CSS the post uses (zoom x the frame, cover, focus), as a control ──
  function framerSet(src) {
    f('focus_x').value = 50; f('focus_y').value = 50; f('zoom').value = 1;
    framer.classList.toggle('empty', !src);
    fimg.src = src || '';
    framerApply();
  }
  function framerApply() {
    const Z = parseFloat(f('zoom').value), fx = +f('focus_x').value, fy = +f('focus_y').value;
    const bw = framer.clientWidth, bh = framer.clientHeight;
    fimg.style.width = (Z * 100) + '%'; fimg.style.height = (Z * 100) + '%';
    fimg.style.objectPosition = fx + '% ' + fy + '%';
    fimg.style.transform = 'translate(' + (-(Z - 1) * fx / 100 * bw) + 'px,' + (-(Z - 1) * fy / 100 * bh) + 'px)';
  }
  let drag = null;
  framer.addEventListener('pointerdown', e => {
    if (!fimg.getAttribute('src')) return;
    framer.setPointerCapture(e.pointerId);
    drag = { x: e.clientX, y: e.clientY, fx: +f('focus_x').value, fy: +f('focus_y').value };
    framer.classList.add('dragging');
  });
  framer.addEventListener('pointermove', e => {
    if (!drag) return;
    const Z = parseFloat(f('zoom').value), bw = framer.clientWidth, bh = framer.clientHeight;
    const nat = (fimg.naturalWidth && fimg.naturalHeight) ? fimg.naturalWidth / fimg.naturalHeight : bw / bh;
    const coverW = Math.max(bw, bh * nat), coverH = Math.max(bh, bw / nat);   // the photo's size when it covers the frame
    const ox = Math.max(1, coverW * Z - bw), oy = Math.max(1, coverH * Z - bh);   // how far it can move
    const fx = Math.max(0, Math.min(100, drag.fx - (e.clientX - drag.x) / ox * 100));
    const fy = Math.max(0, Math.min(100, drag.fy - (e.clientY - drag.y) / oy * 100));
    f('focus_x').value = Math.round(fx); f('focus_y').value = Math.round(fy);
    framerApply(); update();
  });
  ['pointerup', 'pointercancel'].forEach(ev => framer.addEventListener(ev, () => { drag = null; framer.classList.remove('dragging'); }));
  framer.addEventListener('wheel', e => {
    if (!fimg.getAttribute('src')) return;
    e.preventDefault();
    const z = f('zoom'); z.value = Math.max(1, Math.min(3, parseFloat(z.value) - e.deltaY * 0.0025)).toFixed(2);
    framerApply(); update();
  }, { passive: false });
  fimg.addEventListener('load', framerApply);
  new ResizeObserver(framerApply).observe(framer);

  // ── Wiring ──
  form.addEventListener('input', e => {
    const n = e.target.name;
    if (n === 'listing') { fill(listing()); loadPhotos(); return; }
    if (n === 'type') { showGroups(); labelForType(); }
    if (n === 'label') showGroups();
    if (n === 'photo') framerSet(e.target.dataset.large);
    if (n === 'zoom') framerApply();
    update();
  });
  form.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'submit') e.preventDefault();
  });
  form.addEventListener('submit', () => { busy.classList.add('on'); setTimeout(() => busy.classList.remove('on'), 10000); });

  new ResizeObserver(scale).observe(stage); scale();
  showGroups();
  fill(listing());
  loadPhotos();
})();
</script>
<?php endif;
portal_footer();
