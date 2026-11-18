<?php
/**
 * inc/flyer_body.php — the Listing Flyer builder's body (the form and its script),
 * split out of portal/flyer.php on 2026-10-21 so the admin side can draw the same
 * tool for a chosen agent (template_flyer.php; inc/admin_tool_shell.php).
 * Expects what portal/flyer.php computes: $ctx, $listings and the rest of its
 * variables, the endpoint URLs ($render_url, $export_url, $photos_url,
 * $upload_url) built with portal_url() so an admin's ?preview= carries
 * through, and $_SESSION['csrf_token']. $tp_no_listings (HTML) replaces the
 * "under your name" message when set. Nothing here is page-specific; the
 * heading, lead and back link stay in the shells.
 */
?>
<?php if (!$listings): ?>
  <div class="pt-flash err"><?= $tp_no_listings ?? 'We do not see any live listings under your name yet. If that is wrong, email <a href="mailto:' . PORTAL_MARKETING_EMAIL . '">' . PORTAL_MARKETING_EMAIL . '</a>.' ?></div>
<?php else: ?>
  <div class="sp-grid fl-grid">
    <form id="flForm" class="sp-form" method="post" action="<?= ph($export_url) ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <div id="flOrder"></div>

      <section class="sp-step">
        <h2 class="sp-step-h"><span>1</span>Listing</h2>
        <label class="sp-field">Which listing
          <select name="listing">
            <?php // Same groups as the Instagram tool: sales, rentals, then the last week's closings (2026-10-24).
            $groups = ['Listings'    => array_filter($listings, fn($l) => !(int)$l['is_rental'] && empty($l['closed'])),
                       'Rentals'     => array_filter($listings, fn($l) => (int)$l['is_rental'] && empty($l['closed'])),
                       'Just Closed' => array_filter($listings, fn($l) => !empty($l['closed']))];
            foreach ($groups as $gname => $group): if (!$group) continue; ?>
            <optgroup label="<?= ph($gname) ?>">
              <?php foreach ($group as $l): ?>
              <option value="<?= ph($l['id']) ?>"><?= ph(implode('  ·  ', array_filter([$l['address'] . ', ' . $l['city'], $l['price_text'], flyer_status_words((string)$l['status'])]))) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
      </section>

      <section class="sp-step">
        <?php // The note sits ABOVE the heading and the two sources are tabs (Nikki, 2026-11-17: nobody should miss that their own photos print better). ?>
        <div class="fl-tip">
          <strong>For the best print, upload the photographer's original photos.</strong>
          The MLS photos are small and can look soft on paper. Photos you upload are kept at full print quality.
          <button type="button" class="fl-tip-go" data-tab="own">Upload your own ›</button>
        </div>
        <h2 class="sp-step-h"><span>2</span>Photos</h2>
        <div class="fl-tabs" role="tablist">
          <button type="button" class="fl-tab on" role="tab" data-tab="mls" aria-selected="true">MLS photos<em></em></button>
          <button type="button" class="fl-tab" role="tab" data-tab="own" aria-selected="false">Upload your own<em></em></button>
        </div>
        <p class="fl-hint">Tap photos in the order you want them: the first is the big one, then two across, then three. Up to six, from either tab. Tap again to take one out.</p>
        <div class="fl-pane" id="flPaneMls" role="tabpanel">
          <div class="sp-photos fl-photos" id="flPhotos"><div class="sp-wait">Loading photos…</div></div>
        </div>
        <div class="fl-pane" id="flPaneOwn" role="tabpanel" hidden>
          <div class="sp-drop fl-drop" id="flDrop">
            <span id="flDropMsg">Drop your photos here, or <label class="sp-link" for="flFile">choose them</label>.</span>
            <small>JPG, PNG or WEBP, up to 20 MB each. The photographer's full-size files print best.</small>
            <input type="file" id="flFile" accept="image/jpeg,image/png,image/webp,image/*" multiple hidden>
          </div>
          <div class="sp-photos fl-photos" id="flOwn"></div>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h"><span>3</span>Text</h2>
        <div class="sp-fields">
          <label class="sp-field">Headline (optional) <input name="title" maxlength="80" placeholder="Beautiful Simba Run Update"></label>
          <label class="sp-field">Address line <input name="address" maxlength="90"></label>
          <div class="fl-four">
            <label class="sp-field">Price <input name="price" maxlength="40"></label>
            <label class="sp-field">Beds <input name="beds" maxlength="10"></label>
            <label class="sp-field">Baths <input name="baths" maxlength="10"></label>
            <label class="sp-field">Sq Ft <input name="sqft" maxlength="12"></label>
          </div>
          <label class="sp-field">Banner under the details
            <select name="banner" id="flBanner">
              <?php foreach (flyer_banners() as $k => [$w, $c]): ?><option value="<?= ph($k) ?>"><?= ph($w) ?></option><?php endforeach; ?>
              <option value="__none">No banner</option>
              <option value="__custom">Custom…</option>
            </select>
          </label>
          <div class="sp-two" id="flBannerCustom" hidden>
            <label class="sp-field">Banner words <input name="banner_custom" maxlength="24" placeholder="Coming Soon"></label>
            <label class="sp-field">Banner colour
              <select name="banner_color"><?php foreach (flyer_banner_colors() as $k => $hex): ?><option value="<?= ph($k) ?>"><?= ph(flyer_banner_color_label($k)) ?></option><?php endforeach; ?></select>
            </label>
          </div>
          <label class="sp-field">Notes under the details (optional) <textarea name="notes" maxlength="900" rows="3" placeholder="A few lines about the home, or leave this empty."></textarea></label>
          <h3 class="sp-sub">Contact</h3>
          <div class="fl-three">
            <label class="sp-field">Name <input name="agent_name" maxlength="60" value="<?= ph($agent['name']) ?>"></label>
            <label class="sp-field">Phone <input name="agent_phone" maxlength="30" value="<?= ph($agent['phone']) ?>"></label>
            <label class="sp-field">Email <input name="agent_email" maxlength="80" value="<?= ph($agent['email']) ?>"></label>
          </div>
          <label class="sp-field">QR code <select name="qr" id="flQr"></select></label>
        </div>
      </section>

      <div class="sp-actions">
        <button type="submit" class="sp-btn">Download PDF</button>
        <p class="sp-help">The download is a Letter-size PDF (8.5 × 11 in), ready to print or email.</p>
      </div>
    </form>

    <aside class="sp-preview">
      <div class="sp-stage fl-stage" id="flStage">
        <iframe id="flFrame" title="Preview" width="816" height="1056" scrolling="no"></iframe>
        <div class="sp-busy" id="flBusy"></div>
      </div>
      <p class="sp-cap">This is a preview. Click Download to save as a PDF.</p>
      <p class="sp-cap-btn"><button type="submit" form="flForm" class="sp-btn sp-btn-sm">Download PDF</button></p>
    </aside>
  </div>

<script>
(function () {
  const L = <?= json_encode($listings, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const QR = <?= json_encode($qr_by_listing, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const RENDER = <?= json_encode($render_url) ?>, PHOTOS = <?= json_encode($photos_url) ?>, UPLOAD = <?= json_encode($upload_url) ?>;
  const form = document.getElementById('flForm'), frame = document.getElementById('flFrame'), stage = document.getElementById('flStage'),
        photos = document.getElementById('flPhotos'), busy = document.getElementById('flBusy'), order = document.getElementById('flOrder'),
        drop = document.getElementById('flDrop'), dropMsg = document.getElementById('flDropMsg'), fileIn = document.getElementById('flFile'),
        qrSel = document.getElementById('flQr'), own = document.getElementById('flOwn'), step = photos.closest('.sp-step');
  const f = n => form.elements[n];
  const esc = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  const listing = () => L.find(l => l.id === f('listing').value);
  let picked = [];   // photo ids in order

  const DEFAULT_BANNER = <?= json_encode(array_combine(array_column($listings, 'id'), array_map('flyer_default_banner', $listings)) ?: new stdClass) ?>;
  const bannerSel = document.getElementById('flBanner'), bannerCustom = document.getElementById('flBannerCustom');
  function showBanner() { bannerCustom.hidden = bannerSel.value !== '__custom'; }
  function fill(l) {
    if (!l) return;
    f('title').value = ''; f('title').dataset.touched = '';   // the suggestion arrives with the photos
    bannerSel.value = DEFAULT_BANNER[l.id] || '__none'; showBanner();
    f('address').value = l.headline + (l.city ? ', ' + l.city : '');   // l.headline = the short address (St, Rd, #unit)
    f('price').value = l.price_text;
    f('beds').value = l.beds && +l.beds > 0 ? String(+l.beds) : '';
    f('baths').value = l.baths && +l.baths > 0 ? String(+l.baths) : '';
    f('sqft').value = l.sqft && +l.sqft > 0 ? (+l.sqft).toLocaleString('en-US') : '';
    qrSel.innerHTML = Object.entries(QR[l.id] || {}).map(([k, v]) => '<option value="' + esc(k) + '">' + esc(v) + '</option>').join('');
  }
  let timer, seq = 0;
  function update() { clearTimeout(timer); timer = setTimeout(render, 300); }
  async function render() {
    const n = ++seq; busy.classList.add('on');
    try {
      const r = await fetch(RENDER, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      const html = await r.text();
      if (n === seq) frame.srcdoc = html;
    } catch (e) {}
    finally { if (n === seq) busy.classList.remove('on'); }
  }
  function scale() {
    const w = stage.clientWidth;
    frame.style.transform = 'scale(' + (w / 816) + ')';
    stage.style.height = Math.round(1056 * w / 816) + 'px';
  }

  // ── Photos in order ──
  function tile(p) {
    return '<button type="button" class="fl-photo' + (p.own ? ' own' : '') + '" data-id="' + esc(p.id) + '"><img src="' + esc(p.thumb) + '" alt="" loading="lazy"><i></i></button>';
  }
  // The two sources as tabs: the board's photos, and the agent's own. Each tab shows how many of its photos are on the flyer.
  function showTab(name) {
    step.querySelectorAll('.fl-tab').forEach(t => { const on = t.dataset.tab === name; t.classList.toggle('on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    document.getElementById('flPaneMls').hidden = name !== 'mls';
    document.getElementById('flPaneOwn').hidden = name !== 'own';
  }
  step.addEventListener('click', e => { const t = e.target.closest('[data-tab]'); if (t) showTab(t.dataset.tab); });
  function syncOrder() {
    order.innerHTML = picked.map(id => '<input type="hidden" name="photo[]" value="' + esc(id) + '">').join('');
    step.querySelectorAll('.fl-photo').forEach(el => {
      const i = picked.indexOf(el.dataset.id);
      el.classList.toggle('on', i >= 0);
      el.querySelector('i').textContent = i >= 0 ? String(i + 1) : '';
    });
    const mine = picked.filter(id => id.indexOf('upload:') === 0).length;
    step.querySelector('.fl-tab[data-tab="mls"] em').textContent = picked.length - mine ? (picked.length - mine) + ' on flyer' : '';
    step.querySelector('.fl-tab[data-tab="own"] em').textContent = mine ? mine + ' on flyer' : '';
  }
  step.addEventListener('click', e => {
    const el = e.target.closest('.fl-photo'); if (!el) return;
    const id = el.dataset.id, i = picked.indexOf(id);
    if (i >= 0) picked.splice(i, 1);
    else if (picked.length < 6) picked.push(id);
    else return;
    syncOrder(); update();
  });
  async function loadPhotos() {
    const l = listing(); if (!l) return;
    photos.innerHTML = '<div class="sp-wait">Loading photos…</div>';
    const u = new URL(PHOTOS, location.origin); u.searchParams.set('listing', l.id);
    let j = { photos: [] };
    try { j = await (await fetch(u, { credentials: 'same-origin' })).json(); } catch (e) {}
    const all = j.photos || [], mls = all.filter(p => !p.own);
    own.innerHTML = all.filter(p => p.own).map(tile).join('');   // the agent's earlier uploads, whichever listing is chosen
    if (!mls.length) {
      photos.innerHTML = '<div class="sp-wait">This listing has no photos in the feed yet. Upload your own in the other tab.</div>';
      picked = []; showTab('own'); syncOrder(); update(); return;
    }
    // A headline from the opening of the listing description, unless the agent has typed one.
    if (j.headline && !f('title').dataset.touched) f('title').value = j.headline;
    photos.innerHTML = mls.map(tile).join('');
    picked = j.photos.filter(p => !p.own).slice(0, 6).map(p => p.id);   // the first six board photos to start
    syncOrder(); update();
  }
  async function uploadAll(files) {   // several at once, one after the other
    for (const file of Array.from(files || [])) await upload(file);
  }
  async function upload(file) {
    if (!file) return;
    drop.classList.add('busy'); dropMsg.textContent = 'Uploading ' + file.name + '…';
    const fd = new FormData(); fd.append('csrf_token', f('csrf_token').value); fd.append('photo', file);
    let j = {};
    try { j = await (await fetch(UPLOAD, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); }
    catch (e) { j = { error: 'The upload did not go through. Please try again.' }; }
    drop.classList.remove('busy'); fileIn.value = '';
    if (j.error) { dropMsg.textContent = j.error; return; }
    dropMsg.innerHTML = 'Added to your flyer. Drop more here, or <label class="sp-link" for="flFile">choose them</label>.';
    own.insertAdjacentHTML('afterbegin', tile({ id: j.id, thumb: j.thumb, own: true }));
    // A new upload goes on the flyer: in the place of the first MLS photo still on it (the big one first), else at the end while there is room.
    const at = picked.findIndex(id => id.indexOf('upload:') !== 0);
    if (at >= 0) picked[at] = j.id; else if (picked.length < 6) picked.push(j.id);
    syncOrder(); update();
  }
  fileIn.addEventListener('change', e => uploadAll(e.target.files));
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => uploadAll(e.dataTransfer.files));

  // ── Wiring ──
  form.addEventListener('input', e => {
    if (e.target.name === 'listing') { fill(listing()); loadPhotos(); return; }
    if (e.target.name === 'title') f('title').dataset.touched = '1';
    if (e.target.name === 'banner') showBanner();
    update();
  });
  form.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'submit') e.preventDefault();
  });
  form.addEventListener('submit', () => { busy.classList.add('on'); setTimeout(() => busy.classList.remove('on'), 15000); });

  new ResizeObserver(scale).observe(stage); scale();
  fill(listing());
  loadPhotos();
})();
</script>
<?php endif;
