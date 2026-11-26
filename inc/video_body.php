<?php
/**
 * inc/video_body.php — the Video builder (2026-11-26): the preview at the
 * left (video_render.php's page in an iframe, playing and looping; after
 * Make video, the finished MP4 beside it), the controls at the right.
 * Nothing is drawn here but the controls.
 *
 * Expects: $ctx, $csrf, $vd_listings (plan_listings()), $vd_agents
 * (plan_agents()), $vd_clips (video_clips()), and the endpoint URLs
 * ($render_url, $export_url, $clip_url, $photos_url).
 */
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$vd_phones = ads_agent_phones($conn);
$vd_label = fn(array $l) => $l['closed'] ? 'Just Sold' : ($l['new'] ? 'New Listing' : ((int)$l['is_rental'] ? 'Featured Rental' : 'Featured Listing'));
?>
<form id="vdForm" method="post" action="<?= $e($export_url) ?>" autocomplete="off">
  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
  <div id="vdPicked"></div>
  <div class="vd">
    <aside class="vd-side">
      <div class="vd-stage" id="vdStage"><iframe id="vdFrame" title="Preview" scrolling="no"></iframe><video id="vdResult" controls playsinline hidden></video></div>
      <div class="vd-tabs"><button type="button" class="vd-tab on" id="vdTabPreview">Preview</button><button type="button" class="vd-tab" id="vdTabResult" disabled>Finished video</button></div>
      <p class="vd-len" id="vdLen"></p>
    </aside>

    <div class="vd-main">
      <section class="sp-step">
        <h2 class="sp-step-h">Video</h2>
        <div class="sp-look">
          <span class="sp-look-l">Made from</span>
          <label class="sp-pillopt"><input type="radio" name="kind" value="photos" checked><span>Listing photos</span></label>
          <label class="sp-pillopt"><input type="radio" name="kind" value="clip"><span>A video clip</span></label>
        </div>
        <label class="sp-field" style="margin-top:16px">Listing (every Mont Haus listing in the feed)
          <select name="listing" id="vdListing">
            <option value="" data-agent="0" id="vdNoListing" hidden>No listing: type the text yourself</option>
            <?php $first = true; foreach (plan_listing_groups($vd_listings) as $gname => $group): ?>
            <optgroup label="<?= $e($gname) ?>">
              <?php foreach ($group as $l): ?>
              <option value="<?= $e($l['id']) ?>" data-agent="<?= (int)ads_agent_for_listing($l, $vd_agents) ?>" data-label="<?= $e($vd_label($l)) ?>" data-headline="<?= $e($l['headline']) ?>" data-end="<?= $e(trim((string)$l['address']) !== '' ? social_short_address((string)$l['address']) . ($l['town'] !== '' ? "\n" . $l['town'] : '') : '') ?>"
                      data-line1="<?= $e($l['town']) ?>" data-line2="<?= $e($l['closed'] && $l['close_price'] !== null ? social_price_text($l['close_price']) : $l['price_text']) ?>" data-line3="<?= $e($l['specs_text']) ?>"<?= $first ? ' selected' : '' ?>><?= $e(implode('  ·  ', array_filter([($l['address'] !== '' ? $l['address'] : 'Address withheld') . ', ' . $l['city'], $l['price_text'], social_status_words((string)$l['status']), $l['agent']]))) ?></option>
              <?php $first = false; endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
      </section>

      <section class="sp-step" id="vdPhotosStep">
        <h2 class="sp-step-h">Photos</h2>
        <p class="sp-note">Click the photos in the order they should play, up to <?= VIDEO_MAX_PHOTOS ?>. Click one again to take it out.</p>
        <div class="sp-two" style="margin-top:12px">
          <label class="sp-field">Each photo stays
            <select name="hold">
              <?php foreach (video_holds() as $k => $t): ?><option value="<?= $e($k) ?>"<?= (string)$k === '3' ? ' selected' : '' ?>><?= $e($t) ?></option><?php endforeach; ?>
            </select>
          </label>
          <div class="sp-look" style="margin-top:0;align-self:end">
            <span class="sp-look-l">Movement</span>
            <?php foreach (video_motions() as $k => $t): ?>
            <label class="sp-pillopt"><input type="radio" name="motion" value="<?= $e($k) ?>"<?= $k === 'mix' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <p class="sp-note" style="margin-top:8px">Mix slides across a wide photo and zooms slowly on the rest.</p>
        <div class="vd-photos" id="vdPhotos"><div class="sp-wait">Loading photos…</div></div>
      </section>

      <section class="sp-step" id="vdClipStep" hidden>
        <h2 class="sp-step-h">Video clip</h2>
        <label class="sp-field">Which clip
          <select name="clip" id="vdClip">
            <option value=""<?= $vd_clips ? '' : ' selected' ?>>Upload a clip to begin</option>
            <?php $first = true; foreach ($vd_clips as $c): $p = video_clip_public($c); ?>
            <option value="<?= $e($p['id']) ?>" data-duration="<?= $e($p['duration']) ?>"<?= $first ? ' selected' : '' ?>><?= $e($p['text']) ?></option>
            <?php $first = false; endforeach; ?>
          </select>
        </label>
        <div class="vd-up">
          <input type="file" id="vdFile" accept="video/*,.mp4,.mov,.m4v" hidden>
          <button type="button" class="sp-btn sp-btn-soft" id="vdUpload">Upload a clip</button>
          <button type="button" class="sp-btn sp-btn-soft" id="vdClipDel">Remove this clip</button>
          <p class="sp-help" id="vdUpMsg">MP4 or MOV from a phone or camera, up to <?= (int)(VIDEO_CLIP_MAX_BYTES / 1048576) ?> MB. Uploads are kept <?= VIDEO_CLIP_DAYS ?> days.</p>
        </div>
        <div class="sp-two" style="margin-top:14px">
          <label class="sp-field">Start at (seconds into the clip)
            <input type="number" name="clip_start" value="0" min="0" step="0.5">
          </label>
          <label class="sp-field">Use this many seconds (<?= VIDEO_CLIP_MIN_SECONDS ?> to <?= VIDEO_CLIP_MAX_SECONDS ?>)
            <input type="number" name="clip_length" value="15" min="<?= VIDEO_CLIP_MIN_SECONDS ?>" max="<?= VIDEO_CLIP_MAX_SECONDS ?>" step="0.5">
          </label>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">A wide clip</span>
          <label class="sp-pillopt"><input type="radio" name="clip_fit" value="fill" checked><span>Fills the frame (sides cropped)</span></label>
          <label class="sp-pillopt"><input type="radio" name="clip_fit" value="blur"><span>Whole picture, blurred above and below</span></label>
        </div>
        <label class="sp-check"><input type="checkbox" name="clip_mute" value="1"> Remove the clip's sound</label>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h">Text</h2>
        <div class="sp-look">
          <span class="sp-look-l">Text stays</span>
          <?php foreach (video_words_shows() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="words_show" value="<?= $e($k) ?>"<?= $k === 'open' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Text place</span>
          <?php foreach (social_layouts() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="layout" value="<?= $e($k) ?>"<?= $k === 'corner' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <label class="sp-field" style="margin-top:14px">Headline (Enter for a second line)
          <textarea name="headline" rows="2" maxlength="70"></textarea>
        </label>
        <div class="sp-two" style="margin-top:12px">
          <label class="sp-field">Text 1 <input name="line1" maxlength="60"></label>
          <label class="sp-field">Text 2 <input name="line2" maxlength="60"></label>
        </div>
        <label class="sp-field" style="margin-top:12px">Text 3 <input name="line3" maxlength="60"></label>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h">Badge, logo and watermark</h2>
        <label class="sp-field">Badge
          <select name="label">
            <option value="">None</option>
            <?php foreach (plan_labels() as $t): ?><option value="<?= $e($t) ?>"><?= $e($t) ?></option><?php endforeach; ?>
          </select>
        </label>
        <div class="sp-look">
          <span class="sp-look-l">Badge place</span>
          <?php foreach (video_badge_places() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="badge" value="<?= $e($k) ?>"<?= $k === 'text' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look sp-badgecolor">
          <span class="sp-look-l">Badge color</span>
          <?php foreach (social_badge_colors() as $k => [$t, $bg]): ?>
          <label class="sp-pillopt"><input type="radio" name="badge_color" value="<?= $e($k) ?>"<?= $k === 'slate' ? ' checked' : '' ?>><span><i class="sp-swatch" style="background:<?= $e($bg) ?>"></i><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Logo</span>
          <?php foreach (social_logo_kinds() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="logo_kind" value="<?= $e($k) ?>"<?= $k === 'full' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Logo place</span>
          <?php foreach (video_logo_places() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="logo" value="<?= $e($k) ?>"<?= $k === 'right' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Watermark</span>
          <?php foreach (social_marks() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="mark" value="<?= $e($k) ?>"<?= $k === 'none' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h">Closing card</h2>
        <label class="sp-check"><input type="checkbox" name="end" value="1" checked> End on a closing card</label>
        <div id="vdEndFields">
          <label class="sp-field" style="margin-top:12px">Address (Enter for a second line)
            <textarea name="end_address" rows="2" maxlength="90"></textarea>
          </label>
          <label class="sp-field" id="vdAgentField" style="margin-top:12px">Presented by
            <select name="agent" id="vdAgent">
              <option value="0" data-name="" data-phone="">Nobody</option>
              <?php foreach ($vd_agents as $a): ?>
              <option value="<?= (int)$a['id'] ?>" data-name="<?= $e($a['name']) ?>" data-phone="<?= $e($vd_phones[$a['id']] ?? '') ?>"><?= $e($a['name']) ?><?= isset($vd_phones[$a['id']]) ? ' · ' . $e($vd_phones[$a['id']]) : ' · no phone on the profile' ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="sp-check"><input type="checkbox" name="end_headshot" value="1" checked> Show the headshot</label>
          <div class="sp-two" style="margin-top:12px">
            <label class="sp-field">Name <input name="end_name" maxlength="50"></label>
            <label class="sp-field">Phone <input name="end_phone" maxlength="40"></label>
          </div>
          <div class="sp-two" style="margin-top:12px">
            <label class="sp-field">More contact text (optional) <input name="end_extra" maxlength="50" placeholder="An email or an Instagram name"></label>
            <label class="sp-field">Website <input name="end_site" maxlength="40" value="monthaus.com"></label>
          </div>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h">Make the video</h2>
        <div class="sp-actions">
          <button type="submit" class="sp-btn" id="vdMake">Make video</button>
          <a class="sp-btn sp-btn-soft" id="vdDownload" href="#" hidden>Download MP4</a>
        </div>
        <div class="vd-bar" id="vdBar" hidden><i></i></div>
        <p class="sp-help" id="vdMsg">The preview shows the timing and the text; the finished video is made on the server, which takes a minute or two. It has no music: add a track in Instagram when you post.</p>
      </section>
    </div>
  </div>
</form>

<script>
(function () {
  const form = document.getElementById('vdForm');
  const f = n => form.querySelector('[name="' + n + '"]');
  const val = n => (form.querySelector('[name="' + n + '"]:checked') || f(n) || {}).value || '';
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const RENDER = <?= json_encode($render_url) ?>, EXPORT = <?= json_encode($export_url) ?>, CLIP = <?= json_encode($clip_url) ?>, PHOTOS = <?= json_encode($photos_url) ?>;
  const CSRF = <?= json_encode($csrf) ?>, MAXP = <?= VIDEO_MAX_PHOTOS ?>, CHUNK = <?= VIDEO_CHUNK_BYTES ?>, MAXBYTES = <?= VIDEO_CLIP_MAX_BYTES ?>;
  const XF = <?= json_encode(VIDEO_XF / VIDEO_FPS) ?>, ENDS = <?= json_encode(VIDEO_END_SECONDS) ?>;
  const stage = document.getElementById('vdStage'), frame = document.getElementById('vdFrame'), result = document.getElementById('vdResult');
  const tabP = document.getElementById('vdTabPreview'), tabR = document.getElementById('vdTabResult'), lenOut = document.getElementById('vdLen');
  const photosEl = document.getElementById('vdPhotos'), picked = document.getElementById('vdPicked');
  const msg = document.getElementById('vdMsg'), bar = document.getElementById('vdBar'), make = document.getElementById('vdMake'), dl = document.getElementById('vdDownload');
  const listing = document.getElementById('vdListing'), clipSel = document.getElementById('vdClip'), upMsg = document.getElementById('vdUpMsg');
  let order = [], agentTouched = false, byTouched = false, addrTouched = false, busy = false;
  const isClip = () => val('kind') === 'clip';

  // ── The preview ──
  function scale() { frame.style.transform = 'scale(' + (stage.clientWidth / 1080) + ')'; }
  new ResizeObserver(scale).observe(stage);
  function show(which) {
    const r = which === 'result';
    frame.hidden = r; result.hidden = !r;
    tabP.classList.toggle('on', !r); tabR.classList.toggle('on', r);
    if (!r) result.pause();
  }
  tabP.addEventListener('click', () => show('preview'));
  tabR.addEventListener('click', () => { if (!tabR.disabled) show('result'); });
  let timer, seq = 0;
  function update() { clearTimeout(timer); timer = setTimeout(render, 400); length(); }
  async function render() {
    const n = ++seq;
    picked.innerHTML = order.map(id => '<input type="hidden" name="photos[]" value="' + esc(id) + '">').join('');
    try {
      const r = await fetch(RENDER, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      const html = await r.text();
      if (n === seq) { frame.srcdoc = html; if (!busy) show('preview'); }
    } catch (e) { /* the previous preview stays */ }
  }
  // The length, worked out as the server's timeline does (inc/video_render.php, video_timeline()): a readout only.
  function length() {
    let s = 0;
    if (isClip()) s = clipSel.value ? Math.max(3, +f('clip_length').value || 0) : 0;
    else s = order.length ? order.length * ((+val('hold') || 3) + XF) - (order.length - 1) * XF : 0;
    if (s && f('end').checked) s += ENDS;
    lenOut.textContent = s ? 'About ' + (Math.round(s * 10) / 10) + ' seconds, 1080 × 1920' : '';
  }

  // ── Photos: click to add in play order, click again to remove ──
  function paint() {
    photosEl.querySelectorAll('.sp-photo').forEach(el => {
      const i = order.indexOf(el.dataset.id);
      el.classList.toggle('on', i >= 0); el.querySelector('.vd-n').textContent = i >= 0 ? i + 1 : '';
    });
  }
  async function loadPhotos() {
    order = [];
    if (!listing.value) { photosEl.innerHTML = '<div class="sp-wait">Choose a listing to see its photos.</div>'; update(); return; }
    photosEl.innerHTML = '<div class="sp-wait">Loading photos…</div>';
    const u = new URL(PHOTOS, location.href); u.searchParams.set('kind', 'property'); u.searchParams.set('subject', listing.value);
    let j = { photos: [] };
    try { j = await (await fetch(u, { credentials: 'same-origin' })).json(); } catch (e) {}
    const tiles = j.photos || [];
    if (!tiles.length) { photosEl.innerHTML = '<div class="sp-wait">No photos for this listing.</div>'; update(); return; }
    order = tiles.filter(p => !p.own).slice(0, 5).map(p => p.id);   // a fresh listing starts with its first five photos
    photosEl.innerHTML = tiles.map(p => '<label class="sp-photo" data-id="' + esc(p.id) + '" title="' + esc(p.own ? 'Your upload' : (p.caption || '')) + '"><img src="' + esc(p.thumb) + '" alt="" loading="lazy"><span class="vd-n"></span></label>').join('');
    paint(); update();
  }
  photosEl.addEventListener('click', e => {
    const t = e.target.closest('.sp-photo'); if (!t) return;
    e.preventDefault();
    const i = order.indexOf(t.dataset.id);
    if (i >= 0) order.splice(i, 1); else if (order.length < MAXP) order.push(t.dataset.id); else { say('A video takes up to ' + MAXP + ' photos. Take one out first.', true); return; }
    paint(); update();
  });

  // ── The listing fills the words and the broker (until the broker is chosen by hand) ──
  function fromListing() {
    const o = listing.selectedOptions[0] || { dataset: {} }, d = o.dataset;
    if (listing.value) {
      f('headline').value = d.headline || ''; f('line1').value = d.line1 || ''; f('line2').value = d.line2 || ''; f('line3').value = d.line3 || '';
      f('label').value = Array.from(f('label').options).some(x => x.value === d.label) ? d.label : '';
    }
    if (!addrTouched) f('end_address').value = d.end || '';
    if (!agentTouched) { f('agent').value = f('agent').querySelector('option[value="' + (d.agent || '0') + '"]') ? (d.agent || '0') : '0'; if (!byTouched) fromAgent(); }
  }
  // The broker picked fills the card's name and phone; both stay editable.
  function fromAgent() {
    const d = (f('agent').selectedOptions[0] || { dataset: {} }).dataset;
    f('end_name').value = d.name || ''; f('end_phone').value = d.phone || '';
  }
  function syncKind() {
    const c = isClip();
    document.getElementById('vdPhotosStep').hidden = c; document.getElementById('vdClipStep').hidden = !c;
    document.getElementById('vdNoListing').hidden = !c;
    if (!c && !listing.value) { listing.selectedIndex = 1; fromListing(); loadPhotos(); return; }
    update();
  }
  form.addEventListener('change', e => {
    const n = e.target.name;
    if (n === 'kind') { syncKind(); return; }
    if (n === 'listing') { fromListing(); if (!isClip()) { loadPhotos(); return; } }
    if (n === 'agent') { agentTouched = true; byTouched = false; fromAgent(); }
    if (n === 'end') document.getElementById('vdEndFields').hidden = !f('end').checked;
    if (n === 'clip') { f('clip_start').value = 0; }
    update();
  });
  form.addEventListener('input', e => {
    const n = e.target.name;
    if (n === 'end_name' || n === 'end_phone') byTouched = true;
    if (n === 'end_address') addrTouched = true;
    if (['headline', 'line1', 'line2', 'line3', 'clip_start', 'clip_length', 'end_address', 'end_name', 'end_phone', 'end_extra', 'end_site'].includes(n)) update();
  });

  // ── Uploading a clip, in pieces (a phone clip is far over PHP's upload limits) ──
  const file = document.getElementById('vdFile');
  document.getElementById('vdUpload').addEventListener('click', () => file.click());
  file.addEventListener('change', async () => {
    const fl = file.files[0]; file.value = '';
    if (!fl) return;
    const up = t => { upMsg.textContent = t; upMsg.classList.remove('bad'); }, bad = t => { upMsg.textContent = t; upMsg.classList.add('bad'); };
    if (fl.size > MAXBYTES) { bad('That file is ' + Math.round(fl.size / 1048576) + ' MB; the limit is ' + Math.round(MAXBYTES / 1048576) + ' MB. Trim it first.'); return; }
    const id = Array.from(crypto.getRandomValues(new Uint8Array(8))).map(b => b.toString(16).padStart(2, '0')).join('');
    document.getElementById('vdUpload').disabled = true;
    try {
      for (let at = 0; at < fl.size; at += CHUNK) {
        const end = Math.min(fl.size, at + CHUNK), last = end >= fl.size;
        up('Uploading ' + Math.round(at / fl.size * 100) + '%…' + (last ? ' then checking the file.' : ''));
        const u = new URL(CLIP, location.href); u.searchParams.set('id', id); u.searchParams.set('offset', at); u.searchParams.set('last', last ? '1' : '0'); u.searchParams.set('name', fl.name);
        let j = null;
        for (let tries = 0; tries < 3 && !j; tries++) {   // a dropped piece is sent again; the server refuses one it already has
          try { j = await (await fetch(u, { method: 'POST', body: fl.slice(at, end), headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': CSRF }, credentials: 'same-origin' })).json(); }
          catch (e) { await new Promise(r => setTimeout(r, 1500)); }
        }
        if (!j) throw new Error('The upload was interrupted. Please try again.');
        if (j.error) throw new Error(j.error);
        if (j.done) {
          const o = new Option(j.clip.text, j.clip.id, true, true); o.dataset.duration = j.clip.duration;
          clipSel.insertBefore(o, clipSel.options[1] || null); clipSel.options[0].textContent = 'Choose a clip';
          f('clip_start').value = 0; f('clip_length').value = Math.min(15, Math.floor(j.clip.duration - 0.2));
          up('Uploaded: ' + j.clip.text); update();
        }
      }
    } catch (e) { bad(e.message); }
    document.getElementById('vdUpload').disabled = false;
  });
  document.getElementById('vdClipDel').addEventListener('click', async () => {
    const id = clipSel.value; if (!id || !confirm('Remove this clip from the server?')) return;
    const fd = new FormData(); fd.set('csrf_token', CSRF); fd.set('delete', id);
    try { await fetch(CLIP, { method: 'POST', body: fd, credentials: 'same-origin' }); } catch (e) {}
    clipSel.selectedOptions[0].remove(); clipSel.selectedIndex = clipSel.options.length > 1 ? 1 : 0; update();
  });

  // ── Make video: started on the server, then asked about every two seconds ──
  function say(t, bad) { msg.textContent = t; msg.classList.toggle('bad', !!bad); }
  function progress(p) { bar.hidden = p === null; if (p !== null) bar.firstElementChild.style.width = Math.max(2, p) + '%'; }
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (busy) return;
    busy = true; make.disabled = true; dl.hidden = true; tabR.disabled = true; show('preview');
    picked.innerHTML = order.map(id => '<input type="hidden" name="photos[]" value="' + esc(id) + '">').join('');
    say('Starting…'); progress(0);
    const stop = (t, bad) => { busy = false; make.disabled = false; progress(null); say(t, bad); };
    let job;
    try {
      const j = await (await fetch(EXPORT, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })).json();
      if (j.error) return stop(j.error, true);
      job = j.job;
    } catch (err) { return stop('The video could not be started. Please try again.', true); }
    let misses = 0;
    const ask = async () => {
      let s;
      try { s = await (await fetch(EXPORT + '?job=' + encodeURIComponent(job), { credentials: 'same-origin' })).json(); misses = 0; }
      catch (err) { if (++misses > 15) return stop('The page lost touch with the server. Reload it; the video may still be finishing.', true); return setTimeout(ask, 2000); }
      if (s.state === 'failed') return stop(s.error || 'The video could not be made. Please try again in a minute.', true);
      if (s.state === 'done') {
        const src = EXPORT + '?job=' + encodeURIComponent(job) + '&file=1';
        result.src = src; dl.href = src + '&dl=1'; dl.hidden = false; tabR.disabled = false;
        stop('Ready: ' + s.name + ', ' + s.size + ', ' + s.seconds + ' seconds. Made in ' + s.took + ' seconds. Press play to check it, then download.');
        show('result');
        return;
      }
      progress(s.pct || 0); say((s.step || 'Working') + '… ' + (s.pct || 0) + '%');
      setTimeout(ask, 2000);
    };
    ask();
  });

  scale(); fromListing(); syncKind(); loadPhotos();
})();
</script>
