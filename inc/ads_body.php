<?php
/**
 * inc/ads_body.php — the Digital Ads builder (prototype, 2026-11-09): the
 * slides previewed across the top at the ad's own proportions, the controls
 * under them. Nothing is drawn here but the controls: each preview is
 * ad_render.php's HTML in an iframe, scaled to fit.
 *
 * Expects: $ctx (the admin's social context), $csrf, $ad_listings
 * (plan_listings()), $ad_agents (plan_agents()), $ad_phones
 * (ads_agent_phones()), the endpoint URLs ($render_url, $export_url,
 * $photos_url).
 */
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$kinds = ads_frame_kinds();
$S = ads_size('970x250');
?>
  <form id="adForm" class="ad-form" method="post" action="<?= $e($export_url) ?>" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <input type="hidden" name="size" value="970x250">
    <?php foreach ($kinds as $k => $t): ?>
    <input type="hidden" name="photo[<?= $e($k) ?>]" value=""><input type="hidden" name="zoom[<?= $e($k) ?>]" value="1"><input type="hidden" name="fx[<?= $e($k) ?>]" value="50"><input type="hidden" name="fy[<?= $e($k) ?>]" value="50">
    <?php endforeach; ?>

    <div class="ad-stages" id="adStages">
      <?php $n = 1; foreach ($kinds as $k => $t): ?>
      <div class="ad-stage<?= $k === 'headline' ? ' act' : '' ?>" data-kind="<?= $e($k) ?>" title="Click to work on this slide">
        <div class="ad-stage-l"><b><?= $n++ ?></b> <?= $e($t) ?></div>
        <div class="ad-stage-box"><iframe title="<?= $e($t) ?>" width="<?= (int)$S['w'] ?>" height="<?= (int)$S['h'] ?>" scrolling="no"></iframe><div class="ad-busy"></div></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="ad-grid">
      <div class="ad-col">
        <section class="sp-step">
          <h2 class="sp-step-h">Listing</h2>
          <label class="sp-field">Which listing (every Mont Haus listing in the feed)
            <select name="listing" id="adListing">
              <?php $first = true; foreach (plan_listing_groups($ad_listings) as $gname => $group): ?>
              <optgroup label="<?= $e($gname) ?>">
                <?php foreach ($group as $l): ?>
                <option value="<?= $e($l['id']) ?>" data-agent="<?= (int)ads_agent_for_listing($l, $ad_agents) ?>"<?= $first ? ' selected' : '' ?>><?= $e(implode('  ·  ', array_filter([($l['address'] !== '' ? $l['address'] : 'Address withheld') . ', ' . $l['city'], $l['price_text'], social_status_words((string)$l['status']), $l['agent']]))) ?></option>
                <?php $first = false; endforeach; ?>
              </optgroup>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="sp-field">Broker on the Presented by slide
            <select name="agent" id="adAgent">
              <option value="0">None</option>
              <?php foreach ($ad_agents as $a): ?>
              <option value="<?= (int)$a['id'] ?>" data-phone="<?= $e($ad_phones[$a['id']] ?? '') ?>"><?= $e($a['name']) ?><?= isset($ad_phones[$a['id']]) ? ' · ' . $e($ad_phones[$a['id']]) : ' · no phone on the profile' ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="sp-two">
            <label class="sp-field">File name prefix (the outlet)
              <input name="prefix" value="VD" maxlength="12" placeholder="VD, ADN">
            </label>
            <label class="sp-field">Seconds per slide
              <input type="number" name="hold" value="4" min="2" max="8" step="0.5">
            </label>
          </div>
        </section>

        <section class="sp-step">
          <h2 class="sp-step-h">Words</h2>
          <label class="sp-field">Headline, slide 1 (Enter for a second line; blank = the address)
            <textarea name="headline" rows="2" maxlength="90" placeholder="Beautifully Renovated&#10;at Simba Run"></textarea>
          </label>
          <label class="sp-check"><input type="checkbox" name="frame_price" value="1" checked> Slide 2: a second headline and the price</label>
          <div class="sp-two ad-only-price">
            <label class="sp-field">Headline, slide 2 (blank = the price leads)
              <textarea name="headline2" rows="2" maxlength="90" placeholder="Desirable Location,&#10;Priced to Sell"></textarea>
            </label>
            <label class="sp-field">Price line (blank = the list price)
              <input name="price" maxlength="40" placeholder="Now $1,725,000">
            </label>
          </div>
          <label class="sp-check"><input type="checkbox" name="frame_agent" value="1" checked> Slide 3: Presented by the broker (headshot, name, phone)</label>
        </section>
      </div>

      <div class="ad-col">
        <section class="sp-step">
          <h2 class="sp-step-h">Photos</h2>
          <p class="sp-note">Click a slide above, then a photo to put it on that slide. Drag and zoom to frame it.</p>
          <div class="sp-framing">
            <div class="sp-framer empty ad-framer" id="adFramer" style="aspect-ratio:<?= (int)$S['photo_w'] ?> / <?= (int)$S['h'] ?>"><img id="adFramerImg" alt="" draggable="false"><span class="sp-framer-hint">Drag to move</span></div>
            <label class="sp-zoom">Zoom <input type="range" id="adZoom" min="1" max="3" step="0.02" value="1"></label>
          </div>
          <div class="sp-photos ad-photos" id="adPhotos"><div class="sp-wait">Loading photos…</div></div>
          <label class="sp-field" style="margin-top:14px">Panel texture (ghosted behind the words)
            <select name="texture">
              <?php foreach (ads_textures() as $k => $t): ?>
              <option value="<?= $e($k) ?>"<?= $k === ADS_TEXTURE_DEFAULT ? ' selected' : '' ?>><?= $e($t) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </section>

        <section class="sp-step">
          <h2 class="sp-step-h">Download</h2>
          <div class="sp-actions">
            <button type="submit" class="sp-btn" name="export" value="gif" id="adDlGif">Download GIF</button>
            <button type="submit" class="sp-btn sp-btn-soft" name="export" value="slides">Download slides (JPG, 2x)</button>
            <button type="button" class="sp-btn sp-btn-soft" id="adProbe">Check GIF size</button>
          </div>
          <p class="sp-help" id="adSize">The GIF is 970 × 250, looping, each slide held the seconds above; it is kept under 1 MB by dropping colours. The slides ZIP holds each slide as a 1940 × 500 JPG for Photoshop. Each takes about ten seconds to make.</p>
        </section>
      </div>
    </div>
  </form>

<script>
(function () {
  const form = document.getElementById('adForm');
  const f = n => form.querySelector('[name="' + n + '"]');
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const RENDER = <?= json_encode($render_url) ?>, EXPORT = <?= json_encode($export_url) ?>, PHOTOS = <?= json_encode($photos_url) ?>;
  const KINDS = <?= json_encode(array_keys($kinds)) ?>;
  const W = <?= (int)$S['w'] ?>, H = <?= (int)$S['h'] ?>;
  const stages = Array.from(document.querySelectorAll('.ad-stage'));
  const photos = document.getElementById('adPhotos');
  const framer = document.getElementById('adFramer'), fimg = document.getElementById('adFramerImg'), zoomCtl = document.getElementById('adZoom');
  const sizeOut = document.getElementById('adSize');
  let act = 'headline', tiles = [], agentTouched = false;

  // ── Which slides are on ──
  const on = k => k === 'headline' || (k === 'price' ? f('frame_price').checked : (f('frame_agent').checked && f('agent').value !== '0'));
  const visible = () => KINDS.filter(on);
  function syncSlides() {
    stages.forEach(s => { s.hidden = !on(s.dataset.kind); });
    form.querySelectorAll('.ad-only-price').forEach(el => el.hidden = !f('frame_price').checked);
    if (!on(act)) setActive('headline');
    stages.filter(s => !s.hidden).forEach((s, i) => { s.querySelector('.ad-stage-l b').textContent = i + 1; });
  }

  // ── Previews: one fetch per visible slide, the same form plus its position ──
  let timer, seq = 0;
  function update() { clearTimeout(timer); timer = setTimeout(render, 250); }
  async function render() {
    const n = ++seq, vis = visible();
    await Promise.all(stages.filter(s => !s.hidden).map(async s => {
      const i = vis.indexOf(s.dataset.kind); if (i < 0) return;
      const busy = s.querySelector('.ad-busy'); busy.classList.add('on');
      try {
        const fd = new FormData(form); fd.set('frame', String(i));
        const r = await fetch(RENDER, { method: 'POST', body: fd, credentials: 'same-origin' });
        const html = await r.text();
        if (n === seq) s.querySelector('iframe').srcdoc = html;
      } catch (e) { /* the previous preview stays */ }
      finally { if (n === seq) busy.classList.remove('on'); }
    }));
  }
  function scale() {
    stages.forEach(s => {
      const box = s.querySelector('.ad-stage-box'), w = box.clientWidth; if (!w) return;
      s.querySelector('iframe').style.transform = 'scale(' + (w / W) + ')';
      box.style.height = Math.round(H * w / W) + 'px';
    });
  }
  new ResizeObserver(scale).observe(document.getElementById('adStages')); scale();

  // ── The active slide ──
  function setActive(k) {
    act = k;
    stages.forEach(s => s.classList.toggle('act', s.dataset.kind === k));
    const id = f('photo[' + k + ']').value, t = tiles.find(p => p.id === id);
    framerShow(t ? t.large : '');
    photos.querySelectorAll('.sp-photo').forEach(el => el.classList.toggle('on', el.dataset.id === id));
  }
  stages.forEach(s => s.addEventListener('click', () => setActive(s.dataset.kind)));

  // ── Photos ──
  function badges(id) { return KINDS.filter(k => on(k) && f('photo[' + k + ']').value === id).map(k => visible().indexOf(k) + 1); }
  function paintBadges() {
    photos.querySelectorAll('.sp-photo').forEach(el => {
      const b = badges(el.dataset.id); el.querySelector('.ad-badge').textContent = b.join(' '); el.classList.toggle('used', b.length > 0);
      el.classList.toggle('on', el.dataset.id === f('photo[' + act + ']').value);
    });
  }
  async function loadPhotos() {
    photos.innerHTML = '<div class="sp-wait">Loading photos…</div>';
    const u = new URL(PHOTOS, location.origin); u.searchParams.set('kind', 'property'); u.searchParams.set('subject', f('listing').value);
    let j = { photos: [] };
    try { j = await (await fetch(u, { credentials: 'same-origin' })).json(); } catch (e) {}
    tiles = (j.photos || []).filter(p => !p.own);
    if (!tiles.length) { photos.innerHTML = '<div class="sp-wait">No photos for this listing.</div>'; KINDS.forEach(k => f('photo[' + k + ']').value = ''); framerShow(''); update(); return; }
    // A fresh listing: the first three photos on the three slides, in feed order.
    KINDS.forEach((k, i) => { setPhoto(k, (tiles[i] || tiles[0]).id); });
    photos.innerHTML = tiles.map(p => '<label class="sp-photo" data-id="' + esc(p.id) + '"' + (p.caption ? ' title="' + esc(p.caption) + '"' : '') + '><img src="' + esc(p.thumb) + '" alt="" loading="lazy"><span class="ad-badge"></span></label>').join('');
    setActive(act); paintBadges(); update();
  }
  function setPhoto(k, id) { f('photo[' + k + ']').value = id; f('zoom[' + k + ']').value = 1; f('fx[' + k + ']').value = 50; f('fy[' + k + ']').value = 50; }
  photos.addEventListener('click', e => {
    const tile = e.target.closest('.sp-photo'); if (!tile) return;
    e.preventDefault();
    setPhoto(act, tile.dataset.id); setActive(act); paintBadges(); update();
  });

  // ── Framing: the same CSS the slide uses, as a control; drag and zoom write the active slide's fields ──
  function framerShow(src) {
    framer.classList.toggle('empty', !src); fimg.src = src || '';
    zoomCtl.value = f('zoom[' + act + ']').value; framerApply();
  }
  function framerApply() {
    const Z = parseFloat(f('zoom[' + act + ']').value), fx = +f('fx[' + act + ']').value, fy = +f('fy[' + act + ']').value, bw = framer.clientWidth, bh = framer.clientHeight;
    fimg.style.width = (Z * 100) + '%'; fimg.style.height = (Z * 100) + '%';
    fimg.style.objectPosition = fx + '% ' + fy + '%';
    fimg.style.transform = 'translate(' + (-(Z - 1) * fx / 100 * bw) + 'px,' + (-(Z - 1) * fy / 100 * bh) + 'px)';
  }
  zoomCtl.addEventListener('input', () => { f('zoom[' + act + ']').value = zoomCtl.value; framerApply(); update(); });
  let drag = null;
  framer.addEventListener('pointerdown', e => { if (!fimg.getAttribute('src')) return; framer.setPointerCapture(e.pointerId); drag = { x: e.clientX, y: e.clientY, fx: +f('fx[' + act + ']').value, fy: +f('fy[' + act + ']').value }; framer.classList.add('dragging'); });
  framer.addEventListener('pointermove', e => {
    if (!drag) return;
    const Z = parseFloat(f('zoom[' + act + ']').value), bw = framer.clientWidth, bh = framer.clientHeight;
    const nat = (fimg.naturalWidth && fimg.naturalHeight) ? fimg.naturalWidth / fimg.naturalHeight : bw / bh;
    const ox = Math.max(1, Math.max(bw, bh * nat) * Z - bw), oy = Math.max(1, Math.max(bh, bw / nat) * Z - bh);
    f('fx[' + act + ']').value = Math.round(Math.max(0, Math.min(100, drag.fx - (e.clientX - drag.x) / ox * 100)));
    f('fy[' + act + ']').value = Math.round(Math.max(0, Math.min(100, drag.fy - (e.clientY - drag.y) / oy * 100)));
    framerApply(); update();
  });
  ['pointerup', 'pointercancel'].forEach(ev => framer.addEventListener(ev, () => { drag = null; framer.classList.remove('dragging'); }));

  // ── The listing drives the broker (until the broker is chosen by hand) and the photos ──
  function syncAgent() {
    if (agentTouched) return;
    const want = (f('listing').selectedOptions[0] || {}).dataset.agent || '0';
    f('agent').value = f('agent').querySelector('option[value="' + want + '"]') ? want : '0';
  }
  form.addEventListener('change', e => {
    const n = e.target.name;
    if (n === 'listing') { syncAgent(); syncSlides(); loadPhotos(); return; }
    if (n === 'agent') agentTouched = true;
    if (n === 'agent' || n === 'frame_price' || n === 'frame_agent') { syncSlides(); paintBadges(); }
    update();
  });
  form.addEventListener('input', e => { if (['headline', 'headline2', 'price', 'prefix', 'hold'].includes(e.target.name)) update(); });

  // ── The size readout: the GIF made once on the server, nothing downloaded ──
  document.getElementById('adProbe').addEventListener('click', async () => {
    sizeOut.textContent = 'Making the GIF to weigh it (about ten seconds)…';
    try {
      const fd = new FormData(form); fd.set('probe', '1');
      const j = await (await fetch(EXPORT, { method: 'POST', body: fd, credentials: 'same-origin' })).json();
      sizeOut.textContent = j.error ? j.error : ('GIF: ' + j.text + (j.fits ? '. Under the 1 MB cap.' : ''));
      sizeOut.classList.toggle('bad', !j.error && !j.fits);
    } catch (e) { sizeOut.textContent = 'The size check did not come back. Try again.'; }
  });
  form.addEventListener('submit', e => { const b = e.submitter; if (b && b.name === 'export') sizeOut.textContent = (b.value === 'gif' ? 'Making the GIF' : 'Making the slides') + ' (about ten seconds)… the download starts when it is ready.'; });

  syncAgent(); syncSlides(); loadPhotos();
})();
</script>
