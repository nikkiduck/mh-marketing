<?php
/**
 * inc/social_body.php — the Instagram Post/Story builder's body (the form and its script),
 * split out of portal/social.php on 2026-10-21 so the admin side can draw the same
 * tool for a chosen agent (template_social.php; inc/admin_tool_shell.php).
 * Expects what portal/social.php computes: $ctx, $listings and the rest of its
 * variables, the endpoint URLs ($render_url, $export_url, $photos_url,
 * $upload_url) built with portal_url() so an admin's ?preview= carries
 * through, and $_SESSION['csrf_token']. $tp_no_listings (HTML) replaces the
 * "under your name" message when set. Nothing here is page-specific; the
 * heading, lead and back link stay in the shells. $sp_general = true draws the
 * General Social tool (2026-10-28): no listing, the kind fixed to Other, a
 * Square size, the Text layout switch (lower left / centred, a multi-line
 * headline) and the Center logo place; the listing tool offers Listing and
 * Open House, with the logo upper right, lower left or none.
 */
$sp_general = !empty($sp_general);
?>
<?php if (!$listings && !$sp_general): ?>
  <div class="pt-flash err"><?= $tp_no_listings ?? 'We do not see any live listings under your name yet. If that is wrong, email <a href="mailto:' . PORTAL_MARKETING_EMAIL . '">' . PORTAL_MARKETING_EMAIL . '</a>.' ?></div>
<?php else: ?>
  <div class="sp-grid">
    <form id="spForm" class="sp-form" method="post" action="<?= ph($export_url) ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="focus_x" value="50">
      <input type="hidden" name="focus_y" value="50">

      <section class="sp-step">
        <h2 class="sp-step-h"><span>1</span>Pick Template</h2>
        <input type="hidden" name="type" value="photo">
        <div class="sp-look">
          <span class="sp-look-l">Size</span>
          <label class="sp-pillopt"><input type="radio" name="size" value="post" checked><span>Post · 4:5</span></label>
          <?php if ($sp_general): ?><label class="sp-pillopt"><input type="radio" name="size" value="square"><span>Square · 1:1</span></label><?php endif; ?>
          <label class="sp-pillopt"><input type="radio" name="size" value="story"><span>Story · 9:16</span></label>
        </div>
        <?php if ($sp_general): ?>
        <input type="hidden" name="kind" value="other">
        <div class="sp-look">
          <span class="sp-look-l">Text</span>
          <?php foreach (social_layouts() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="layout" value="<?= ph($k) ?>"<?= $k === 'corner' ? ' checked' : '' ?>><span><?= ph($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <label class="sp-field sp-kind">Template
          <select name="kind">
            <option value="listing">Listing</option>
            <option value="openhouse">Open House</option>
          </select>
        </label>
        <?php endif; ?>
        <div class="sp-look">
          <span class="sp-look-l">Look</span>
          <?php foreach ($themes as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="theme" value="<?= ph($k) ?>"<?= $k === 'dark' ? ' checked' : '' ?>><span><?= ph($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Watermark</span>
          <?php foreach (social_marks() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="mark" value="<?= ph($k) ?>"<?= $k === 'triangle' ? ' checked' : '' ?>><span><?= ph($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Logo</span>
          <?php foreach (social_logo_kinds() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="logo_kind" value="<?= ph($k) ?>"<?= $k === 'full' ? ' checked' : '' ?>><span><?= ph($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Logo place</span>
          <?php // Upper right (a post's default), lower left (a story's), centred (the centred layout only, General Social), none (2026-10-28).
          foreach (social_logo_places() as $k => $t): if ($k === 'center' && !$sp_general) continue; ?>
          <label class="sp-pillopt<?= $k === 'center' ? ' sp-only-center' : '' ?>"<?= $k === 'center' ? ' hidden' : '' ?>><input type="radio" name="logo" value="<?= ph($k) ?>"<?= $k === 'top' ? ' checked' : '' ?>><span><?= ph($t) ?></span></label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="sp-step sp-only-listing">
        <h2 class="sp-step-h"><span>2</span>Listing</h2>
        <label class="sp-field">Which listing
          <select name="listing">
            <?php // Sales first, then rentals, under headers (Nikki, 2026-10-12: a team's list is long);
            // closings of the last week last (Nikki, 2026-10-24), so the Just Sold post can still be made.
            $groups = ['Listings'    => array_filter($listings, fn($l) => !(int)$l['is_rental'] && empty($l['closed'])),
                       'Rentals'     => array_filter($listings, fn($l) => (int)$l['is_rental'] && empty($l['closed'])),
                       'Just Closed' => array_filter($listings, fn($l) => !empty($l['closed']))];
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
        <h2 class="sp-step-h"><span><?= $sp_general ? 2 : 3 ?></span>Photo</h2>
        <p class="sp-hint" id="spPhotoHint" data-other="Choose a stock photo or upload your own below." data-listing="Choose a listing photo or upload your own below.">Choose a listing photo or upload your own below.</p>
        <div class="sp-photos" id="spPhotos"><div class="sp-wait">Loading photos…</div></div>
        <div class="sp-drop" id="spDrop">
          <span id="spDropMsg" data-listing="Or use your own: drop a photo here, or <label class=&quot;sp-link&quot; for=&quot;spFile&quot;>choose one</label>." data-other="Upload your own photo: drop it here, or <label class=&quot;sp-link&quot; for=&quot;spFile&quot;>choose one</label>.">Or use your own: drop a photo here, or <label class="sp-link" for="spFile">choose one</label>.</span>
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
        <h2 class="sp-step-h"><span><?= $sp_general ? 3 : 4 ?></span>Text</h2>
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
          <label class="sp-field sp-only-openhouse" hidden>Date and time, as it will read (press Enter for a new line) <textarea name="oh_when" maxlength="90" rows="2" placeholder="Today 11am-2pm"></textarea></label>
          <?php if ($sp_general): ?>
          <label class="sp-field">Headline <span class="sp-only-center" hidden>(press Enter for a new line)</span> <textarea name="headline" maxlength="120" rows="2"></textarea></label>
          <?php else: ?>
          <label class="sp-field">Headline <input name="headline" maxlength="80"></label>
          <?php endif; ?>
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
        <p class="sp-help">The download is a 1080 × 1440 PNG for a post, <?= $sp_general ? '1080 × 1080 for a square, ' : '' ?>or 1080 × 1920 for a story.</p>
      </div>
    </form>

    <aside class="sp-preview">
      <div class="sp-stage" id="spStage">
        <iframe id="spFrame" title="Preview" width="1080" height="1440" scrolling="no"></iframe>
        <div class="sp-busy" id="spBusy"></div>
      </div>
      <p class="sp-cap">This is a preview. Click Download to save as a PNG.</p>
      <p class="sp-cap-btn"><button type="submit" form="spForm" class="sp-btn sp-btn-sm">Download PNG</button></p>
    </aside>
  </div>

<script>
(function () {
  const L = <?= json_encode($listings, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const RENDER = <?= json_encode($render_url) ?>, PHOTOS = <?= json_encode($photos_url) ?>, UPLOAD = <?= json_encode($upload_url) ?>, DELETE_URL = <?= json_encode(social_url($ctx, '/portal/social_delete.php')) ?>;
  const PHOTO_H = <?= json_encode(array_map('social_photo_height', array_combine(array_keys($templates), array_keys($templates)))) ?>;
  const SIZES = { post: 1440, square: 1080, story: 1920 };
  const fmt = () => f('size').value, fmtH = () => SIZES[fmt()] || 1440;
  const LOGO_DEFAULT = s => s === 'story' ? 'bottom' : 'top';   // where the logo goes unless the agent moved it
  let prevSize = 'post';                                         // set properly once the form is wired (below)
  const kind = () => f('kind').value, isOther = () => kind() === 'other';
  const PLACEHOLDER = { id: 'placeholder', thumb: '/assets/images/social-placeholder.jpg', large: '/assets/images/social-placeholder.jpg', own: false, caption: 'Aspen Highlands and the Maroon Bells, early snow on fall colour', group: 'Aspen & Roaring Fork Valley' };
  // The template key: size + kind (photo = a post).
  function setType() {
    const base = fmt() === 'story' ? 'story' : (fmt() === 'square' ? 'square' : 'photo');
    f('type').value = base + (kind() === 'listing' ? '' : '_' + kind());
  }
  // The logo follows the size's default until the agent picks a place; Center only goes with centred words.
  function syncLogo() {
    const logo = f('logo'), layout = f('layout');
    if (logo.value === LOGO_DEFAULT(prevSize) && prevSize !== fmt()) logo.value = LOGO_DEFAULT(fmt());
    prevSize = fmt();
    const centered = !!layout && layout.value === 'center';
    form.querySelectorAll('.sp-only-center').forEach(el => el.hidden = !centered);
    if (!centered && logo.value === 'center') logo.value = 'top';
  }
  // The date and time line, built from the pickers until the agent types their own ("Today 11am-2pm").
  const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  function tText(hm) { if (!/^\d{2}:\d{2}$/.test(hm)) return ''; const h = +hm.slice(0, 2), m = hm.slice(3); const h12 = ((h + 11) % 12) + 1, ap = h < 12 ? 'AM' : 'PM'; return m === '00' ? h12 + ' ' + ap : h12 + ':' + m + ' ' + ap; }
  function whenFromPickers() {
    const d = f('oh_date').value, parts = [];
    if (/^\d{4}-\d{2}-\d{2}$/.test(d)) { const dt = new Date(+d.slice(0, 4), +d.slice(5, 7) - 1, +d.slice(8, 10)); parts.push(DAYS[dt.getDay()] + ', ' + MONTHS[dt.getMonth()] + ' ' + dt.getDate()); }
    const s = tText(f('oh_start').value), e = tText(f('oh_end').value);
    if (s && e) parts.push(s + ' to ' + e); else if (s) parts.push('From ' + s);
    return parts.join(fmt() === 'story' ? '\n' : '  ·  ');   // a story: the time under the date
  }
  function syncWhen() { if (!f('oh_when').dataset.touched) f('oh_when').value = whenFromPickers(); }
  const isOH = t => t.indexOf('openhouse') !== -1, isFull = t => t.indexOf('photo') === 0 || t.indexOf('square') === 0 || t.indexOf('story') === 0;
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
    if (isOther()) {   // Other: Mont Haus words to start, every line the agent's (Nikki, 2026-10-15)
      f('headline').value = 'Alpine Roots. Global Vision.';
      lineText(0).value = 'Mont Haus International Realty'; [1, 2, 3].forEach(i => lineText(i).value = '');
      return;
    }
    if (!l) return;
    f('headline').value = l.headline;
    lineText(0).value = l.town; lineText(1).value = l.price_text; lineText(2).value = l.specs_text;
  }
  function showGroups() {
    const t = f('type').value;
    form.querySelectorAll('.sp-only-openhouse').forEach(el => el.hidden = !isOH(t));
    form.querySelectorAll('.sp-only-listing').forEach(el => el.hidden = isOther());
    dropMsg.innerHTML = isOther() ? dropMsg.dataset.other : dropMsg.dataset.listing;
    const hint = document.getElementById('spPhotoHint'); hint.textContent = isOther() ? hint.dataset.other : hint.dataset.listing;
    form.querySelectorAll('.sp-only-long').forEach(el => el.hidden = isFull(t));
    customWrap.hidden = labelSel.value !== '__custom';
    framer.style.aspectRatio = '1080 / ' + PHOTO_H[t];
  }
  // The default label follows the template and the listing (a rental's is Featured
  // Rental, Nikki 2026-10-12; a closing's is Just Sold, 2026-10-24) unless the agent chose something else.
  const DEFAULT_LABELS = ['Featured Listing', 'Featured Rental', 'Just Sold', 'Open House', '__none'];
  function defaultLabel() {
    if (isOH(f('type').value)) return 'Open House';
    if (isOther()) return '__none';
    const l = listing();
    if (l && l.closed) return 'Just Sold';
    return (l && +l.is_rental) ? 'Featured Rental' : 'Featured Listing';
  }
  function labelForType() {
    if (DEFAULT_LABELS.includes(labelSel.value)) labelSel.value = defaultLabel();
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
    frame.height = fmtH(); frame.style.height = fmtH() + 'px';
    frame.style.transform = 'scale(' + (w / 1080) + ')';
    stage.style.height = Math.round(fmtH() * w / 1080) + 'px';
  }

  // ── Photos: the listing's, then the agent's own ──
  function photoTile(p, checked) {
    // A stock photo carries its caption as the tooltip (hover) so the agent knows where it is (Nikki, 2026-10-15).
    return '<label class="sp-photo' + (p.own ? ' own' : '') + '"' + (p.caption ? ' title="' + esc(p.caption) + '"' : '') + '><input type="radio" name="photo" value="' + esc(p.id) + '" data-large="' + esc(p.large) + '"' +
           (checked ? ' checked' : '') + '><img src="' + esc(p.thumb) + '" alt="' + esc(p.caption || '') + '" loading="lazy">' + (p.own ? '<i>Yours</i><button type="button" class="sp-del" data-id="' + esc(p.id) + '" title="Remove this photo" aria-label="Remove this photo">×</button>' : '') + '</label>';
  }
  async function loadPhotos(selectId) {
    const l = listing(); if (!l && !isOther()) return;
    photos.innerHTML = '<div class="sp-wait">Loading photos…</div>';
    const u = new URL(PHOTOS, location.origin); u.searchParams.set('listing', isOther() ? '__none' : l.id);
    let j = { photos: [] };
    try { j = await (await fetch(u, { credentials: 'same-origin' })).json(); } catch (e) {}
    const all = j.photos || [];
    if (!all.length && !isOther()) {
      photos.innerHTML = '<div class="sp-wait">This listing has no photos in the feed yet. Add your own below.</div>';
      framerSet(''); update(); return;
    }
    // In groups (2026-10-18): the person's own uploads first, then the listing's photos or the stock library.
    const mine = all.filter(p => p.own), rest = all.filter(p => !p.own);
    // Own photos last, beside the upload box (Nikki, 2026-10-18). The stock library is grouped by
    // region, one heading each, in the order the server sends them (Nikki, 2026-10-19).
    const byRegion = [];
    for (const p of (isOther() ? [PLACEHOLDER].concat(rest) : [])) {
      const g = byRegion.find(x => x[0] === p.group);
      if (g) g[1].push(p); else byRegion.push([p.group || 'Mont Haus stock photos', [p]]);
    }
    const groups = isOther()
      ? byRegion.concat([['Your photos', mine]])
      : [['Listing photos', rest], ['Your photos', mine]];
    const first = selectId || (isOther() ? PLACEHOLDER.id : (rest[0] || mine[0] || {}).id);
    let html = '';
    for (const [name, list] of groups) {
      if (!list.length) continue;
      html += '<div class="sp-group">' + esc(name) + '</div>' + list.map(p => photoTile(p, p.id === first)).join('');
    }
    photos.innerHTML = html;
    const sel = all.concat([PLACEHOLDER]).find(p => p.id === first);
    framerSet(sel ? sel.large : ''); update();
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
    loadPhotos(j.id);   // redraw the groups with the new upload selected
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
  // The x on an own photo removes it (2026-10-18): a confirm, then the file is gone and the grid redraws.
  photos.addEventListener('click', async e => {
    const b = e.target.closest('.sp-del'); if (!b) return;
    e.preventDefault(); e.stopPropagation();
    if (!confirm('Remove this photo from your uploads? Posts you already downloaded are not affected.')) return;
    const fd = new FormData(); fd.append('csrf_token', f('csrf_token').value); fd.append('id', b.dataset.id);
    let j = {};
    try { j = await (await fetch(DELETE_URL, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (err) { j = { error: 'That did not work. Please try again.' }; }
    if (j.error) { alert(j.error); return; }
    const wasSelected = (form.querySelector('input[name="photo"]:checked') || {}).value === b.dataset.id;
    loadPhotos(wasSelected ? undefined : (form.querySelector('input[name="photo"]:checked') || {}).value);
  });
  form.addEventListener('input', e => {
    const n = e.target.name;
    if (n === 'listing') { fill(listing()); labelForType(); loadPhotos(); return; }
    if (n === 'size') { setType(); syncLogo(); showGroups(); scale(); syncWhen(); }
    if (n === 'layout') syncLogo();
    if (n === 'kind') { setType(); showGroups(); labelForType(); fill(listing()); scale(); loadPhotos(); return; }
    if (n === 'oh_when') f('oh_when').dataset.touched = '1';
    if (n === 'oh_date' || n === 'oh_start' || n === 'oh_end') syncWhen();
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
  setType();
  prevSize = fmt(); syncLogo();
  showGroups();
  syncWhen();
  fill(listing());
  labelForType();
  loadPhotos();
})();
</script>
<?php endif;
