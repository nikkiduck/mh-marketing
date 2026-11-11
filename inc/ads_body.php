<?php
/**
 * inc/ads_body.php — the Digital Ads builder (prototype, 2026-11-09; seven
 * sizes since 2026-11-11): the size switch, then the previews at the ad's
 * own proportions (All sizes: one slide in every size; a single size: its
 * slides one under another), then the controls. Nothing is drawn here but
 * the controls: each preview is ad_render.php's HTML in an iframe, never
 * shown larger than life.
 *
 * Expects: $ctx (the admin's social context), $csrf, $ad_listings
 * (plan_listings()), $ad_agents (plan_agents()), $ad_phones
 * (ads_agent_phones()), the endpoint URLs ($render_url, $export_url,
 * $photos_url).
 */
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$kinds = ads_frame_kinds();
$boxes = ads_size_boxes();
?>
  <form id="adForm" class="ad-form" method="post" action="<?= $e($export_url) ?>" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <input type="hidden" name="size" value="970x250">
    <?php foreach ($kinds as $k => $t): foreach (['', '2'] as $sfx): ?>
    <input type="hidden" name="photo<?= $sfx ?>[<?= $e($k) ?>]" value=""><input type="hidden" name="zoom<?= $sfx ?>[<?= $e($k) ?>]" value="1"><input type="hidden" name="fx<?= $sfx ?>[<?= $e($k) ?>]" value="50"><input type="hidden" name="fy<?= $sfx ?>[<?= $e($k) ?>]" value="50">
    <?php endforeach; endforeach; ?>

    <div class="ad-bar">
      <div class="ad-pills" id="adSizes">
        <button type="button" class="ad-pill on" data-size="all">All sizes</button>
        <?php foreach ($boxes as $k => $b): ?>
        <button type="button" class="ad-pill" data-size="<?= $e($k) ?>" title="<?= $e($b['name']) ?>"><?= $e(str_replace('x', ' × ', $k)) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="ad-pills" id="adSlides">
        <span class="ad-pills-l">Slide</span>
        <?php $n = 1; foreach ($kinds as $k => $t): ?>
        <button type="button" class="ad-pill<?= $k === 'headline' ? ' on' : '' ?>" data-kind="<?= $e($k) ?>"><b><?= $n++ ?></b> <?= $e($t) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="ad-stages" id="adStages"></div>

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
          <div class="sp-two">
            <label class="sp-field">Broker on the Presented by slide
              <select name="agent" id="adAgent">
                <option value="0">None</option>
                <?php foreach ($ad_agents as $a): ?>
                <option value="<?= (int)$a['id'] ?>"><?= $e($a['name']) ?><?= isset($ad_phones[$a['id']]) ? ' · ' . $e($ad_phones[$a['id']]) : ' · no phone on the profile' ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="sp-field">Second broker (optional)
              <select name="agent2">
                <option value="0">None</option>
                <?php foreach ($ad_agents as $a): ?>
                <option value="<?= (int)$a['id'] ?>"><?= $e($a['name']) ?><?= isset($ad_phones[$a['id']]) ? ' · ' . $e($ad_phones[$a['id']]) : ' · no phone on the profile' ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
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
          <label class="sp-check"><input type="checkbox" name="frame_agent" value="1" checked> Slide 3: Presented by the broker(s) (headshot, name, phone)</label>
          <p class="sp-note" style="margin-top:12px">The strips set the headline on one line. The 320 × 50 has room for two lines only: its second slide always leads with the price.</p>
        </section>
      </div>

      <div class="ad-col">
        <section class="sp-step">
          <h2 class="sp-step-h">Photos</h2>
          <p class="sp-note">Pick a slide, then a photo to put it on that slide. Drag and zoom to frame it; the framing carries to every size.</p>
          <div class="sp-look" id="adSide">
            <span class="sp-look-l">Which photo</span>
            <label class="sp-pillopt"><input type="radio" name="_side" value="" checked><span>Left</span></label>
            <label class="sp-pillopt"><input type="radio" name="_side" value="2"><span>Right (970 × 90 and Impact)</span></label>
          </div>
          <div class="sp-look">
            <span class="sp-look-l">Photo width, Marquee</span>
            <?php foreach (ads_shares() as $k => $t): ?>
            <label class="sp-pillopt"><input type="radio" name="share" value="<?= $e($k) ?>"<?= $k === ADS_SHARE_DEFAULT ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="sp-framing" style="margin-top:14px">
            <div class="sp-framer empty ad-framer" id="adFramer"><img id="adFramerImg" alt="" draggable="false"><span class="sp-framer-hint">Drag to move</span></div>
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
            <button type="submit" class="sp-btn" name="export" value="all">Download all sizes (ZIP)</button>
            <button type="submit" class="sp-btn sp-btn-soft ad-one" name="export" value="gif">Download GIF</button>
            <button type="submit" class="sp-btn sp-btn-soft ad-one" name="export" value="slides">Download slides (JPG, 2x)</button>
            <button type="button" class="sp-btn sp-btn-soft ad-one" id="adProbe">Check GIF size</button>
          </div>
          <p class="sp-help" id="adSize"></p>
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
  const KINDS = <?= json_encode(array_keys($kinds)) ?>, KIND_NAMES = <?= json_encode($kinds) ?>;
  const SIZES = <?= json_encode($boxes) ?>;   // key → {name, w, h, layout, pw (0 = from the Photo width choice), ph}
  const stagesEl = document.getElementById('adStages'), sizePills = document.getElementById('adSizes'), slidePills = document.getElementById('adSlides');
  const photos = document.getElementById('adPhotos'), sideRow = document.getElementById('adSide');
  const framer = document.getElementById('adFramer'), fimg = document.getElementById('adFramerImg'), zoomCtl = document.getElementById('adZoom');
  const sizeOut = document.getElementById('adSize');
  let mode = 'all', act = 'headline', tiles = [], agentTouched = false;
  const HELP = {
    all: 'All sizes: one ZIP with the seven GIFs, each kept under 1 MB, and a list of what each weighs. It takes about a minute. Pick one size above to download it alone, as a GIF or as 2x JPG slides for Photoshop.',
    one: 'The GIF loops, each slide held the seconds above, and is kept under 1 MB by dropping colours. The slides ZIP holds each slide at twice the size as a JPG, for Photoshop. Each takes about ten seconds.',
  };

  // ── Which slides are on; which photo (left or right) the tiles and the framer work on ──
  const on = k => k === 'headline' || (k === 'price' ? f('frame_price').checked : (f('frame_agent').checked && (f('agent').value !== '0' || f('agent2').value !== '0')));
  const visible = () => KINDS.filter(on);
  const cur = () => mode === 'all' ? '970x250' : mode;                      // the size the framer is shaped for
  const twoSided = () => mode === 'all' || SIZES[mode].layout === 'tri';
  const side = () => twoSided() ? (form.querySelector('input[name="_side"]:checked') || {}).value || '' : '';
  const fld = (n, k) => f(n + side() + '[' + (k || act) + ']');

  // ── The previews ──
  function buildStages() {
    const list = mode === 'all' ? Object.keys(SIZES).map(s => ({ size: s, kind: act, label: SIZES[s].name + '  ·  ' + s.replace('x', ' × ') }))
                                : visible().map((k, i) => ({ size: mode, kind: k, label: '<b>' + (i + 1) + '</b> ' + esc(KIND_NAMES[k]) }));
    stagesEl.innerHTML = list.map(s => {
      const b = SIZES[s.size];
      return '<div class="ad-stage' + (mode !== 'all' && s.kind === act ? ' act' : '') + '" data-size="' + s.size + '" data-kind="' + s.kind + '" title="' + (mode === 'all' ? 'Click to work on this size' : 'Click to work on this slide') + '">'
           + '<div class="ad-stage-l">' + s.label + '</div>'
           + '<div class="ad-stage-box" style="max-width:' + b.w + 'px"><iframe title="Preview" width="' + b.w + '" height="' + b.h + '" style="width:' + b.w + 'px;height:' + b.h + 'px" scrolling="no"></iframe><div class="ad-busy"></div></div></div>';
    }).join('');
    scale(); render();
  }
  let timer, seq = 0;
  function update() { clearTimeout(timer); timer = setTimeout(render, 250); }
  async function render() {
    const n = ++seq, vis = visible();
    await Promise.all(Array.from(stagesEl.children).map(async s => {
      const i = vis.indexOf(s.dataset.kind); if (i < 0) return;
      const busy = s.querySelector('.ad-busy'); busy.classList.add('on');
      try {
        const fd = new FormData(form); fd.set('size', s.dataset.size); fd.set('frame', String(i));
        const r = await fetch(RENDER, { method: 'POST', body: fd, credentials: 'same-origin' });
        const html = await r.text();
        if (n === seq && s.isConnected) s.querySelector('iframe').srcdoc = html;
      } catch (e) { /* the previous preview stays */ }
      finally { if (n === seq) busy.classList.remove('on'); }
    }));
  }
  function scale() {
    Array.from(stagesEl.children).forEach(s => {
      const b = SIZES[s.dataset.size], box = s.querySelector('.ad-stage-box'), w = box.clientWidth; if (!w) return;
      s.querySelector('iframe').style.transform = 'scale(' + (w / b.w) + ')';
      box.style.height = Math.round(b.h * w / b.w) + 'px';
    });
  }
  new ResizeObserver(scale).observe(stagesEl);

  // ── Size and slide ──
  function setMode(m) {
    mode = m;
    sizePills.querySelectorAll('.ad-pill').forEach(p => p.classList.toggle('on', p.dataset.size === m));
    f('size').value = cur();
    form.querySelectorAll('.ad-one').forEach(b => b.disabled = m === 'all');
    sideRow.hidden = !twoSided();
    sizeOut.textContent = m === 'all' ? HELP.all : HELP.one; sizeOut.classList.remove('bad');
    shapeFramer(); setActive(act, true);
  }
  function setActive(k, rebuild) {
    if (!on(k)) k = 'headline';
    act = k;
    slidePills.querySelectorAll('.ad-pill').forEach(p => { p.classList.toggle('on', p.dataset.kind === k); p.hidden = !on(p.dataset.kind); });
    visible().forEach((v, i) => { const p = slidePills.querySelector('[data-kind="' + v + '"] b'); if (p) p.textContent = i + 1; });
    if (rebuild || mode === 'all') buildStages();
    else Array.from(stagesEl.children).forEach(s => s.classList.toggle('act', s.dataset.kind === k));
    framerLoad(); paintBadges();
  }
  sizePills.addEventListener('click', e => { const p = e.target.closest('.ad-pill'); if (p) setMode(p.dataset.size); });
  slidePills.addEventListener('click', e => { const p = e.target.closest('.ad-pill'); if (p) setActive(p.dataset.kind); });
  stagesEl.addEventListener('click', e => {
    const s = e.target.closest('.ad-stage'); if (!s) return;
    if (mode === 'all') setMode(s.dataset.size); else setActive(s.dataset.kind);
  });

  // ── Photos ──
  function badges(id) {
    const vis = visible(), out = [];
    vis.forEach((k, i) => { if (f('photo[' + k + ']').value === id) out.push(String(i + 1)); if (twoSided() && f('photo2[' + k + ']').value === id) out.push((i + 1) + 'R'); });
    return out;
  }
  function paintBadges() {
    const mine = fld('photo').value;
    photos.querySelectorAll('.sp-photo').forEach(el => {
      el.querySelector('.ad-badge').textContent = badges(el.dataset.id).join(' ');
      el.classList.toggle('on', el.dataset.id === mine);
    });
  }
  function setPhoto(k, sfx, id) { f('photo' + sfx + '[' + k + ']').value = id; f('zoom' + sfx + '[' + k + ']').value = 1; f('fx' + sfx + '[' + k + ']').value = 50; f('fy' + sfx + '[' + k + ']').value = 50; }
  async function loadPhotos() {
    photos.innerHTML = '<div class="sp-wait">Loading photos…</div>';
    const u = new URL(PHOTOS, location.origin); u.searchParams.set('kind', 'property'); u.searchParams.set('subject', f('listing').value);
    let j = { photos: [] };
    try { j = await (await fetch(u, { credentials: 'same-origin' })).json(); } catch (e) {}
    tiles = (j.photos || []).filter(p => !p.own);
    if (!tiles.length) { photos.innerHTML = '<div class="sp-wait">No photos for this listing.</div>'; KINDS.forEach(k => { setPhoto(k, '', ''); setPhoto(k, '2', ''); }); framerLoad(); update(); return; }
    // A fresh listing: the first three photos on the three slides, in feed order, and the next three on their right-hand sides.
    KINDS.forEach((k, i) => { setPhoto(k, '', (tiles[i] || tiles[0]).id); setPhoto(k, '2', (tiles[i + KINDS.length] || tiles[(i + 1) % tiles.length]).id); });
    photos.innerHTML = tiles.map(p => '<label class="sp-photo" data-id="' + esc(p.id) + '"' + (p.caption ? ' title="' + esc(p.caption) + '"' : '') + '><img src="' + esc(p.thumb) + '" alt="" loading="lazy"><span class="ad-badge"></span></label>').join('');
    framerLoad(); paintBadges(); update();
  }
  photos.addEventListener('click', e => {
    const tile = e.target.closest('.sp-photo'); if (!tile) return;
    e.preventDefault();
    setPhoto(act, side(), tile.dataset.id); framerLoad(); paintBadges(); update();
  });

  // ── Framing: the same CSS the slide uses, as a control; drag and zoom write the active slide's fields ──
  function shapeFramer() {
    const b = SIZES[cur()], pw = b.pw || Math.round(b.w * (+(form.querySelector('input[name="share"]:checked') || {}).value || 58) / 100);
    framer.style.aspectRatio = pw + ' / ' + b.ph;
  }
  function framerLoad() {
    const t = tiles.find(p => p.id === fld('photo').value), src = t ? t.large : '';
    framer.classList.toggle('empty', !src); if (fimg.getAttribute('src') !== src) fimg.src = src || '';
    zoomCtl.value = fld('zoom').value; framerApply();
  }
  function framerApply() {
    const Z = parseFloat(fld('zoom').value), fx = +fld('fx').value, fy = +fld('fy').value, bw = framer.clientWidth, bh = framer.clientHeight;
    fimg.style.width = (Z * 100) + '%'; fimg.style.height = (Z * 100) + '%';
    fimg.style.objectPosition = fx + '% ' + fy + '%';
    fimg.style.transform = 'translate(' + (-(Z - 1) * fx / 100 * bw) + 'px,' + (-(Z - 1) * fy / 100 * bh) + 'px)';
  }
  zoomCtl.addEventListener('input', () => { fld('zoom').value = zoomCtl.value; framerApply(); update(); });
  let drag = null;
  framer.addEventListener('pointerdown', e => { if (!fimg.getAttribute('src')) return; framer.setPointerCapture(e.pointerId); drag = { x: e.clientX, y: e.clientY, fx: +fld('fx').value, fy: +fld('fy').value }; framer.classList.add('dragging'); });
  framer.addEventListener('pointermove', e => {
    if (!drag) return;
    const Z = parseFloat(fld('zoom').value), bw = framer.clientWidth, bh = framer.clientHeight;
    const nat = (fimg.naturalWidth && fimg.naturalHeight) ? fimg.naturalWidth / fimg.naturalHeight : bw / bh;
    const ox = Math.max(1, Math.max(bw, bh * nat) * Z - bw), oy = Math.max(1, Math.max(bh, bw / nat) * Z - bh);
    fld('fx').value = Math.round(Math.max(0, Math.min(100, drag.fx - (e.clientX - drag.x) / ox * 100)));
    fld('fy').value = Math.round(Math.max(0, Math.min(100, drag.fy - (e.clientY - drag.y) / oy * 100)));
    framerApply(); update();
  });
  ['pointerup', 'pointercancel'].forEach(ev => framer.addEventListener(ev, () => { drag = null; framer.classList.remove('dragging'); }));

  // ── The listing drives the broker (until the broker is chosen by hand) and the photos ──
  function syncAgent() {
    if (agentTouched) return;
    const want = (f('listing').selectedOptions[0] || {}).dataset.agent || '0';
    f('agent').value = f('agent').querySelector('option[value="' + want + '"]') ? want : '0';
  }
  function syncSlides() { form.querySelectorAll('.ad-only-price').forEach(el => el.hidden = !f('frame_price').checked); setActive(act, true); }
  form.addEventListener('change', e => {
    const n = e.target.name;
    if (n === 'listing') { syncAgent(); syncSlides(); loadPhotos(); return; }
    if (n === 'agent') agentTouched = true;
    if (n === '_side') { framerLoad(); paintBadges(); return; }
    if (n === 'share') { shapeFramer(); framerApply(); }
    if (n === 'agent' || n === 'agent2' || n === 'frame_price' || n === 'frame_agent') { syncSlides(); return; }
    update();
  });
  form.addEventListener('input', e => { if (['headline', 'headline2', 'price', 'prefix', 'hold'].includes(e.target.name)) update(); });

  // ── The size readout: the GIF made once on the server, nothing downloaded ──
  document.getElementById('adProbe').addEventListener('click', async () => {
    sizeOut.classList.remove('bad');
    sizeOut.textContent = 'Making the GIF to weigh it (about ten seconds, longer for the Impact)…';
    try {
      const fd = new FormData(form); fd.set('probe', '1');
      const j = await (await fetch(EXPORT, { method: 'POST', body: fd, credentials: 'same-origin' })).json();
      sizeOut.textContent = j.error ? j.error : (j.text + (j.fits ? '. Under the 1 MB cap.' : ''));
      sizeOut.classList.toggle('bad', !j.error && !j.fits);
    } catch (e) { sizeOut.textContent = 'The size check did not come back. Try again.'; }
  });
  form.addEventListener('submit', e => {
    const b = e.submitter; if (!b || b.name !== 'export') return;
    sizeOut.classList.remove('bad');
    sizeOut.textContent = b.value === 'all' ? 'Making all seven GIFs (about a minute)… the download starts when they are ready.'
                        : (b.value === 'gif' ? 'Making the GIF' : 'Making the slides') + ' (about ten seconds)… the download starts when it is ready.';
  });

  syncAgent();
  form.querySelectorAll('.ad-only-price').forEach(el => el.hidden = !f('frame_price').checked);
  setMode('all'); loadPhotos();
})();
</script>
