<?php
/**
 * inc/video_body.php — the Video builder (2026-11-26): the preview at the
 * left (video_render.php's page in an iframe, playing and looping; after
 * Make video, the finished MP4 beside it), the controls at the right.
 * Nothing is drawn here but the controls.
 *
 * Expects: $ctx, $csrf, $vd_listings (plan_listings()), $vd_agents
 * (plan_agents()), $vd_clips (video_clips()), and the endpoint URLs
 * ($render_url, $export_url, $clip_url, $logo_url, $photos_url,
 * $upload_url, $delete_url, $saved_url), and $vd_open (a saved video to
 * open: video_saved_read(), or null) with $vd_copy (open it as a new one).
 */
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$vd_phones = ads_agent_phones($conn);
$vd_teams = mk_site_teams();
// The options of both Presented by dropdowns: nobody, the teams (a team with a logo on file shows the logo and needs no name), the brokers.
$vd_by_options = function () use ($vd_teams, $vd_agents, $vd_phones, $e): string {
    $h = '<option value="0" data-name="" data-phone="">None</option>';
    if ($vd_teams) {
        $h .= '<optgroup label="Teams">';
        foreach ($vd_teams as $t) { $has = video_team_logo($t['key']) !== null; $h .= '<option value="t:' . $e($t['key']) . '" data-team="' . $e($t['key']) . '" data-team-name="' . $e($t['name']) . '" data-logo="' . ($has ? 1 : 0) . '" data-name="' . $e($has ? '' : $t['name']) . '" data-phone="">' . $e($t['name']) . '</option>'; }
        $h .= '</optgroup><optgroup label="Brokers">';
    }
    foreach ($vd_agents as $a) $h .= '<option value="' . (int)$a['id'] . '" data-name="' . $e($a['name']) . '" data-phone="' . $e($vd_phones[$a['id']] ?? '') . '">' . $e($a['name']) . (isset($vd_phones[$a['id']]) ? ' · ' . $e($vd_phones[$a['id']]) : ' · no phone on the profile') . '</option>';
    return $h . ($vd_teams ? '</optgroup>' : '');
};
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
      <p class="vd-len" id="vdMoveTip" hidden>Drag a photo in the preview to move it.</p>
      <?php /* The caption for the post: a listing writes it (video_caption.php), the person changes and copies it; kept with a saved video. */ ?>
      <div class="vd-capbox">
        <label for="vdCaption">Caption</label>
        <textarea name="caption" id="vdCaption" rows="6" maxlength="<?= CAPTION_MAX ?>"></textarea>
        <p><button type="button" class="sp-btn" id="vdCapCopy">Copy caption</button><button type="button" class="sp-btn sp-btn-soft" id="vdCapFill" hidden>Fill from the listing</button></p>
      </div>
    </aside>

    <div class="vd-main">
      <?php /* What was opened or copied from the saved videos (template_video.php ?open= / ?copy=), and anything it could not bring back. */ ?>
      <p class="sp-help" id="vdOpenMsg" style="margin:0 0 12px" hidden></p>
      <section class="sp-step" id="vdVideoStep">
        <h2 class="sp-step-h">Video</h2>
        <div class="sp-look" style="margin-top:0">
          <span class="sp-look-l">Made from:</span>
          <label class="sp-pillopt"><input type="radio" name="kind" value="listing" checked><span>Listing</span></label>
          <label class="sp-pillopt"><input type="radio" name="kind" value="mine"><span>My Photos</span></label>
          <label class="sp-pillopt"><input type="radio" name="kind" value="clip"><span>Video Clip</span></label>
        </div>
        <label class="sp-field" id="vdListingField" style="margin-top:16px">Listing (every Mont Haus listing in the feed)
          <select name="listing" id="vdListing">
            <option value="" data-agent="0" id="vdNoListing" hidden>No listing: type the text yourself</option>
            <?php $first = true; foreach (plan_listing_groups($vd_listings) as $gname => $group): ?>
            <optgroup label="<?= $e($gname) ?>">
              <?php foreach ($group as $l): ?>
              <option value="<?= $e($l['id']) ?>" <?php [$p1, $p2] = video_listing_presenters($l, $vd_agents); ?>data-agent="<?= $e($p1) ?>" data-agent2="<?= $e($p2) ?>" data-label="<?= $e($vd_label($l)) ?>" data-headline="<?= $e($l['headline']) ?>" data-end="<?= $e(trim((string)$l['address']) !== '' ? social_short_address((string)$l['address']) . ($l['town'] !== '' ? "\n" . $l['town'] : '') : '') ?>"
                      data-line1="<?= $e($l['town']) ?>" data-line2="<?= $e($l['closed'] && $l['close_price'] !== null ? social_price_text($l['close_price']) : $l['price_text']) ?>" data-line3="<?= $e($l['specs_text']) ?>"<?= $first ? ' selected' : '' ?>><?= $e(implode('  ·  ', array_filter([($l['address'] !== '' ? $l['address'] : 'Address withheld') . ', ' . $l['city'], $l['price_text'], social_status_words((string)$l['status']), $l['agent']]))) ?></option>
              <?php $first = false; endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
      </section>

      <section class="sp-step" id="vdCoverStep">
        <h2 class="sp-step-h">Cover Design</h2>
        <label class="sp-field" id="vdCoverHold" style="max-width:240px;margin-bottom:12px">Cover Slide length
            <select name="cover_hold">
              <?php foreach (video_card_holds() as $k => $t): ?><option value="<?= $e($k) ?>"<?= (float)$k === VIDEO_COVER_SECONDS ? ' selected' : '' ?>><?= $e($t) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="sp-field">Headline (Line breaks allowed)
          <textarea name="headline" rows="2" maxlength="70"></textarea>
        </label>
        <div class="sp-two" style="margin-top:12px">
          <label class="sp-field">Text 1 <input name="line1" maxlength="60"></label>
          <label class="sp-field">Text 2 <input name="line2" maxlength="60"></label>
        </div>
        <label class="sp-field" style="margin-top:12px">Text 3 <input name="line3" maxlength="60"></label>
        <label class="sp-field" style="margin-top:14px">Badge
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
          <?php foreach (video_logo_kinds() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="logo_kind" value="<?= $e($k) ?>"<?= $k === 'full' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-ranges" id="vdLogoRanges">
          <label class="sp-range">Logo size <input type="range" name="logo_scale" min="50" max="200" step="2" value="130"><output>130%</output></label>
          <label class="sp-range">Nudge across <input type="range" name="logo_dx" min="-200" max="200" step="2" value="0"><output>0</output></label>
          <label class="sp-range">Logo shadow <input type="range" name="logo_shadow" min="0" max="100" step="5" value="0"><output>0</output></label>
          <label class="sp-range">Nudge down <input type="range" name="logo_dy" min="-200" max="200" step="2" value="0"><output>0</output></label>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Watermark</span>
          <?php foreach (social_marks() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="mark" value="<?= $e($k) ?>"<?= $k === 'none' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="sp-step" id="vdPhotosStep">
        <h2 class="sp-step-h" id="vdPhotosH">Photos</h2>
        <div class="sp-drop" id="vdDrop" style="margin:0 0 14px" hidden>
          <span id="vdDropMsg">Drop photos here, or <label class="sp-link" for="vdPhotoFile">choose them</label>.</span>
          <input type="file" id="vdPhotoFile" accept="image/jpeg,image/png,image/webp,image/*" multiple hidden>
        </div>
        <p class="sp-note">Click the photos in the order they should play, up to <?= VIDEO_MAX_PHOTOS ?>. Click one again to take it out.</p>
        <div class="sp-two" style="margin-top:12px">
          <label class="sp-field">Slide length
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
        <div class="vd-photos" id="vdPhotos"><div class="sp-wait">Loading photos…</div></div>
        <div id="vdCapsWrap" hidden>
          <div class="vd-subrow">
            <h3 class="vd-sub">Zoom, logo and text on each photo</h3>
            <label class="sp-check" style="margin:0"><input type="checkbox" name="words_all" value="1"> Use Cover Text on all slides</label>
            <?php if ($suggest_url !== ''): /* a listing's photos only: the texts come from its description, details and photos (inc/video_ai.php) */ ?>
            <button type="button" class="vd-mini" id="vdSuggest" hidden>Suggest Texts</button>
            <?php endif; ?>
          </div>
          <p class="sp-help" id="vdSuggestMsg" style="margin:6px 0 0" hidden></p>
          <div class="vd-caps" id="vdCaps"></div>
        </div>
      </section>

      <?php /* My Photos: the rows for each photo (zoom, logo, text) move here, under Cover Design, though the photos are chosen above it. */ ?>
      <section class="sp-step" id="vdSlidesStep" hidden>
        <h2 class="sp-step-h">Slides</h2>
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
          <button type="button" class="vd-mini" id="vdUpload">Upload a clip</button>
          <button type="button" class="vd-mini" id="vdClipDel">Remove this clip</button>
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
        <label class="sp-check"><input type="checkbox" name="words_all_clip" value="1"> Keep the Cover Text on for the whole clip</label>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h">Closing card</h2>
        <label class="sp-check"><input type="checkbox" name="end" value="1" checked> End on a closing card</label>
        <div id="vdEndFields">
          <label class="sp-field" style="margin-top:12px;max-width:240px">Closing Slide length
            <select name="end_hold">
              <?php foreach (video_card_holds() as $k => $t): ?><option value="<?= $e($k) ?>"<?= (float)$k === VIDEO_END_SECONDS ? ' selected' : '' ?>><?= $e($t) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="sp-field" id="vdEndBgField" style="margin-top:12px;max-width:240px">Background photo
            <select name="end_bg" id="vdEndBg"><option value="">None</option></select>
          </label>
          <label class="sp-field" style="margin-top:12px">Address (Line breaks allowed)
            <textarea name="end_address" rows="2" maxlength="90"></textarea>
          </label>
          <label class="sp-field" id="vdAgentField" style="margin-top:12px">Presented by
            <select name="agent" id="vdAgent"><?= $vd_by_options() ?></select>
          </label>
          <div class="sp-two" style="margin-top:12px">
            <label class="sp-field">Name <input name="end_name" maxlength="50"></label>
            <label class="sp-field">Phone <input name="end_phone" maxlength="40"></label>
          </div>
          <label class="sp-field" style="margin-top:12px">Add second broker (optional)
            <select name="agent2" id="vdAgent2"><?= $vd_by_options() ?></select>
          </label>
          <div class="sp-two" id="vdBy2" style="margin-top:12px" hidden>
            <label class="sp-field">Name <input name="end_name2" maxlength="50"></label>
            <label class="sp-field">Phone <input name="end_phone2" maxlength="40"></label>
          </div>
          <label class="sp-check" style="margin-top:12px"><input type="checkbox" name="end_headshot" value="1" checked> Show the headshot or team logo</label>
          <div class="vd-up" id="vdTeamLogo" hidden>
            <input type="file" id="vdLogoFile" accept=".png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml" hidden>
            <button type="button" class="vd-mini" id="vdLogoUp">Upload a team logo</button>
            <button type="button" class="vd-mini" id="vdLogoDel" hidden>Remove the logo</button>
            <label class="sp-check" style="margin:0"><input type="checkbox" id="vdLogoDark" checked> It is a dark logo: show it in white</label>
            <p class="sp-help" id="vdLogoMsg" style="flex-basis:100%;margin:0"></p>
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
          <button type="button" class="sp-btn sp-btn-soft" id="vdStop" hidden>Stop</button>
          <button type="button" class="sp-btn sp-btn-soft" id="vdSave">Save for later</button>
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
  const RENDER = <?= json_encode($render_url) ?>, EXPORT = <?= json_encode($export_url) ?>, CLIP = <?= json_encode($clip_url) ?>, LOGO = <?= json_encode($logo_url) ?>, PHOTOS = <?= json_encode($photos_url) ?>, UPLOAD = <?= json_encode($upload_url) ?>, DELETE_URL = <?= json_encode($delete_url) ?>, SUGGEST = <?= json_encode($suggest_url) ?>, CAPTION = <?= json_encode($caption_url ?? '') ?>;
  const CSRF = <?= json_encode($csrf) ?>, MAXP = <?= VIDEO_MAX_PHOTOS ?>, CHUNK = <?= VIDEO_CHUNK_BYTES ?>, MAXBYTES = <?= VIDEO_CLIP_MAX_BYTES ?>;
  const XF = <?= json_encode(VIDEO_XF / VIDEO_FPS) ?>, ENDS = <?= json_encode(VIDEO_END_SECONDS) ?>, COVERS = <?= json_encode(VIDEO_COVER_SECONDS) ?>;
  const stage = document.getElementById('vdStage'), frame = document.getElementById('vdFrame'), result = document.getElementById('vdResult');
  const tabP = document.getElementById('vdTabPreview'), tabR = document.getElementById('vdTabResult'), lenOut = document.getElementById('vdLen');
  const capsEl = document.getElementById('vdCaps'), capsWrap = document.getElementById('vdCapsWrap');
  const photosEl = document.getElementById('vdPhotos'), picked = document.getElementById('vdPicked');
  const msg = document.getElementById('vdMsg'), bar = document.getElementById('vdBar'), make = document.getElementById('vdMake'), dl = document.getElementById('vdDownload');
  const listing = document.getElementById('vdListing'), clipSel = document.getElementById('vdClip'), upMsg = document.getElementById('vdUpMsg');
  let order = [], caps = {}, zooms = {}, focus = {}, logoSet = {}, agentTouched = false, byTouched = false, by2Touched = false, addrTouched = false, endTouched = false, busy = false;
  // Three sources (Nikki, 2026-11-26): a listing's photos, My Photos (uploads and the stock library, no listing needed), a video clip.
  const isClip = () => val('kind') === 'clip', isMine = () => val('kind') === 'mine', isListing = () => !isClip() && !isMine();
  let gridFor = null;   // what the photo grid shows: 'mine', or 'l:' + a listing's id

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
  // stale = a newer preview is on its way; startAt = the photo (its place) the next preview begins on, so a change to
  // one photo is seen at once and not after the photos before it have played.
  let timer, seq = 0, stale = true, startAt = null;
  function update() { stale = true; clearTimeout(timer); timer = setTimeout(render, 400); length(); }
  const segOf = i => { try { const w = frame.contentWindow; return w && w.vseek ? w.document.querySelector('.seg[data-i="' + i + '"]') : null; } catch (e) { return null; } };
  frame.addEventListener('load', () => {
    stale = false;
    const seg = startAt === null ? null : segOf(startAt); startAt = null;
    if (seg) frame.contentWindow.vseek(+seg.dataset.s, true);
  });
  // A photo's zoom, seen as it is dragged (Nikki, 2026-11-27: "you can't see the zooming while you're doing it"): the
  // preview stops on that photo and takes the zoom straight away; let go and it plays on from that photo.
  function zoomLive(id, hold) {
    const i = order.indexOf(id), seg = stale ? null : segOf(i), img = seg && seg.querySelector('img');
    if (!img || !img.naturalWidth) { startAt = i; update(); return; }
    const w = frame.contentWindow;
    w.vz(img, (zooms[id] || 100) / 100);
    w.vseek(hold ? (+seg.dataset.a + +seg.dataset.b) / 2 : +seg.dataset.s, !hold);
    if (!busy) show('preview');
  }
  async function render() {
    const n = ++seq;
    pick();
    try {
      const r = await fetch(RENDER, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      const html = await r.text();
      if (n === seq) { frame.srcdoc = html; if (!busy) show('preview'); }
    } catch (e) { /* the previous preview stays */ }
  }
  // The length, worked out as the server's timeline does (inc/video_render.php, video_timeline()): a readout only.
  let lastSecs = 0;   // the length last worked out, kept with a saved video for its card
  function length() {
    let s = 0;
    if (isClip()) s = clipSel.value ? Math.max(3, +f('clip_length').value || 0) : 0;
    else s = order.length ? (+val('cover_hold') || COVERS) + (order.length - 1) * (+val('hold') || 3) + XF : 0;
    if (s && f('end').checked) s += +val('end_hold') || ENDS;
    lastSecs = s ? Math.round(s * 10) / 10 : 0;
    lenOut.textContent = s ? 'About ' + (Math.round(s * 10) / 10) + ' seconds, 1080 × 1920' : '';
  }

  // ── Photos: click to add in play order, click again to remove ──
  // photos[], captions[], zooms[], focus_x[], focus_y[] and logos[] go to the server side by side, in play order.
  // The logo shows on a photo when its "Logo?" is ticked: the cover starts ticked, the rest do not.
  const logoOn = id => id in logoSet ? logoSet[id] : order.indexOf(id) === 0;
  function pick() {
    picked.innerHTML = order.map(id => '<input type="hidden" name="photos[]" value="' + esc(id) + '"><input type="hidden" name="captions[]" value="' + esc(caps[id] || '') + '"><input type="hidden" name="zooms[]" value="' + (zooms[id] || 100) + '"><input type="hidden" name="focus_x[]" value="' + (focus[id] || [50, 50])[0] + '"><input type="hidden" name="focus_y[]" value="' + (focus[id] || [50, 50])[1] + '"><input type="hidden" name="logos[]" value="' + (logoOn(id) ? 1 : 0) + '">').join('');
  }
  function paint() {
    photosEl.querySelectorAll('.sp-photo').forEach(el => {
      const i = order.indexOf(el.dataset.id);
      el.classList.toggle('on', i >= 0); el.querySelector('.vd-n').textContent = i === 0 ? 'COVER' : (i > 0 ? i + 1 : '');
    });
    // One row per chosen photo, in play order: its zoom, and from the second photo on its own text (the first is the
    // cover, whose text is the Cover Design). What was set stays with its photo.
    capsWrap.hidden = !order.length; document.getElementById('vdMoveTip').hidden = isClip() || !order.length;
    // The closing card's Background photo: None, or one of the photos picked. The choice stays with its photo when the order changes.
    const bgSel = document.getElementById('vdEndBg');
    if (endBg && !order.includes(endBg)) endBg = '';
    bgSel.innerHTML = '<option value="">None</option>' + order.map((id, i) => '<option value="' + (i + 1) + '"' + (id === endBg ? ' selected' : '') + '>' + (i === 0 ? 'Cover photo' : 'Photo ' + (i + 1)) + '</option>').join('');
    document.getElementById('vdEndBgField').hidden = isClip() || !order.length; document.getElementById('vdSlidesStep').hidden = !isMine() || !order.length;
    capsEl.innerHTML = order.map((id, i) => {
      const t = photosEl.querySelector('.sp-photo[data-id="' + CSS.escape(id) + '"] img'), z = zooms[id] || 100;
      return '<div class="vd-cap" data-row="' + esc(id) + '">' + (order.length > 1 ? '<button type="button" class="vd-grip" title="Drag to change the order" aria-label="Drag to change the order">⋮⋮</button>' : '<span></span>') + '<span class="vd-cap-p" data-move="' + esc(id) + '" title="Drag to move this photo in the frame. Double-click to center it."><img src="' + esc(t ? t.src : '') + '" alt="" draggable="false"><b>' + (i === 0 ? 'COVER' : i + 1) + '</b></span><div class="vd-cap-b">'
        + '<div class="vd-row"><label class="vd-zoom">Zoom <input type="range" min="100" max="200" step="5" value="' + z + '" data-zoom="' + esc(id) + '"><output>' + z + '%</output></label><label class="vd-logo"><input type="checkbox" data-logo="' + esc(id) + '"' + (logoOn(id) ? ' checked' : '') + '> Logo?</label></div>'
        + (i > 0 ? '<textarea rows="2" maxlength="140" data-id="' + esc(id) + '" placeholder="Text for photo ' + (i + 1) + '">' + esc(caps[id] || '') + '</textarea>' : '')
        + '</div></div>';
    }).join('');
    syncAll();
  }
  // Moving a photo in the frame (Nikki, 2026-11-27: "drag and move each photo so we can control the starting position of
  // the zoom or pan"): drag the preview and the photo showing stops where it is and follows the pointer; drag a row's
  // thumbnail and the preview stops on that photo's start. Let go and it plays on. focus = { photo id: [x, y] }, 0 to 100
  // each way, 50 = centered: the share of the photo's spare width and height left out on the left and at the top.
  let drag = null, endBg = '';   // endBg = the photo (its id) ghosted behind the closing card
  function showing() {
    try {
      const w = frame.contentWindow; if (!w || !w.vnow) return -1;
      const t = w.vnow(); let at = -1;
      w.document.querySelectorAll('.seg[data-i]').forEach(g => { if (+g.dataset.a <= t && t < +g.dataset.b) at = +g.dataset.i; });
      return at;
    } catch (e) { return -1; }
  }
  function dragStart(e, i, atStart) {
    if (e.button || stale || isClip() || frame.hidden || i < 0 || !order[i]) return;
    const seg = segOf(i), img = seg && seg.querySelector('img'); if (!img || !img.naturalWidth) return;
    const w = frame.contentWindow, id = order[i];
    const t = Math.min(atStart ? +seg.dataset.s : Math.max(w.vnow(), +seg.dataset.s), +seg.dataset.b - 1);
    w.vseek(t, false);
    drag = { id, img, t, x: e.clientX, y: e.clientY, from: (focus[id] || [50, 50]).slice(), spare: w.vspare(img), k: 1080 / stage.clientWidth };
    try { e.currentTarget.setPointerCapture(e.pointerId); } catch (err) {}
    stage.classList.add('drag'); e.preventDefault();
  }
  function moveTo(id, img, x, y) {
    const c = n => Math.round(Math.max(0, Math.min(100, n)) * 10) / 10;
    focus[id] = [c(x), c(y)];
    frame.contentWindow.vz(img, (zooms[id] || 100) / 100, 0, focus[id][0] / 100, focus[id][1] / 100);
  }
  window.addEventListener('pointermove', e => {
    if (!drag) return;
    const d = drag, by = (px, spare, from) => spare > 1 ? from - px * d.k / spare * 100 : from;   // the photo follows the pointer: dragged right, less of it is left out on the left
    moveTo(d.id, d.img, by(e.clientX - d.x, d.spare[0], d.from[0]), by(e.clientY - d.y, d.spare[1], d.from[1]));
  });
  ['pointerup', 'pointercancel'].forEach(ev => window.addEventListener(ev, () => {
    if (!drag) return;
    const d = drag; drag = null; stage.classList.remove('drag');
    try { frame.contentWindow.vseek(d.t, true); } catch (e) {}
  }));
  stage.addEventListener('pointerdown', e => dragStart(e, showing(), false));
  capsEl.addEventListener('pointerdown', e => { const p = e.target.closest('[data-move]'); if (p) { if (!busy) show('preview'); dragStart(e, order.indexOf(p.dataset.move), true); } });
  // The play order: drag a row by its grip (Nikki, 2026-11-27: "a drag-to-reorder of the photos"). The row moves among
  // the others as the pointer passes them; let go and that is the order. What was set stays with its photo (text, zoom,
  // position, logo); the photo that lands first is the cover.
  let sort = null;
  capsEl.addEventListener('pointerdown', e => {
    const g = e.target.closest('.vd-grip'); if (!g || e.button) return;
    sort = g.closest('.vd-cap'); sort.classList.add('sorting'); capsEl.classList.add('sorting');
    e.preventDefault();
  });
  window.addEventListener('pointermove', e => {
    if (!sort) return;
    let before = null;
    for (const r of capsEl.querySelectorAll('.vd-cap')) {
      if (r === sort) continue;
      const b = r.getBoundingClientRect();
      if (e.clientY < b.top + b.height / 2) { before = r; break; }
    }
    if (before ? sort.nextElementSibling !== before : capsEl.lastElementChild !== sort) capsEl.insertBefore(sort, before);
  });
  ['pointerup', 'pointercancel'].forEach(ev => window.addEventListener(ev, () => {
    if (!sort) return;
    const id = sort.dataset.row; sort = null; capsEl.classList.remove('sorting');
    const ids = [...capsEl.querySelectorAll('.vd-cap')].map(r => r.dataset.row);
    const moved = ids.join('\n') !== order.join('\n');
    if (moved) { order = ids; startAt = order.indexOf(id); }
    paint(); if (moved) update();
  }));
  // A double-click puts the photo back in the middle.
  function center(i) {
    const seg = stale ? null : segOf(i), img = seg && seg.querySelector('img'); if (!order[i]) return;
    if (!img || !img.naturalWidth) { delete focus[order[i]]; startAt = i; update(); return; }
    moveTo(order[i], img, 50, 50); frame.contentWindow.vseek(+seg.dataset.s, true);
  }
  stage.addEventListener('dblclick', () => { if (!frame.hidden && !isClip()) center(showing()); });
  capsEl.addEventListener('dblclick', e => { const p = e.target.closest('[data-move]'); if (p) center(order.indexOf(p.dataset.move)); });
  // "Use Cover Text on all slides" hides the photos' own text boxes; what was typed is kept for when it is unticked.
  function syncAll() { capsEl.classList.toggle('no-text', f('words_all').checked); capsEl.classList.toggle('no-logo', val('logo_kind') === 'none'); syncSuggest(); }
  // Suggest Texts (a listing's photos only): a few words for each photo after the cover, written from the listing's
  // description, its details and the photos themselves. It fills EMPTY boxes only (Nikki, 2026-11-27: a second click
  // after adding a photo "overwrote all of them"): a box with anything in it, typed or suggested, is left alone. For a
  // different suggestion on one photo, clear its box and click again; texts already offered are sent so they are not repeated.
  const sugBtn = document.getElementById('vdSuggest'), sugMsg = document.getElementById('vdSuggestMsg');
  let suggested = {}, sugSeen = [], sugBusy = false;
  function syncSuggest() {
    if (!sugBtn) return;
    sugBtn.hidden = !isListing() || order.length < 2 || f('words_all').checked;
    if (sugBtn.hidden) sugMsg.hidden = true;
  }
  function sugSay(t, bad) { sugMsg.hidden = !t; sugMsg.textContent = t; sugMsg.classList.toggle('bad', !!bad); }
  if (sugBtn) sugBtn.addEventListener('click', async () => {
    if (sugBusy) return;
    const ids = order.slice(), free = ids.slice(1).filter(id => !(caps[id] || '').trim());
    if (!free.length) { sugSay('Every photo has text. Clear a box to have a new one suggested for it.'); return; }
    sugBusy = true; sugBtn.disabled = true; sugBtn.textContent = 'Writing…';
    sugSay('Reading the listing and the photos. This takes about 20 seconds.');
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('listing', listing.value);
    ids.forEach(id => fd.append('photos[]', id));
    // Not to be repeated: what was offered before, and what the other boxes hold now.
    [...new Set(sugSeen.concat(ids.slice(1).map(id => (caps[id] || '').trim()).filter(Boolean)))].forEach(t => fd.append('avoid[]', t));
    let j;
    try { j = await (await fetch(SUGGEST, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); }
    catch (e) { j = { error: 'The suggestions did not come back. Please try again.' }; }
    sugBusy = false; sugBtn.disabled = false; sugBtn.textContent = 'Suggest Texts';
    if (j.error || !j.texts) { sugSay(j.error || 'The suggestions did not come back. Please try again.', true); return; }
    let n = 0;
    free.forEach(id => {
      const t = j.texts[id];
      if (!t || !order.includes(id) || (caps[id] || '').trim()) return;   // typed into while this was on its way
      caps[id] = suggested[id] = t; sugSeen.push(t); n++;
    });
    paint(); update();
    sugSay(n ? 'Suggested from the listing\'s description and photos. Only empty boxes are filled: clear one and click again for a different suggestion.' : 'Nothing new came back. Please try again.', !n);
  });
  capsEl.addEventListener('change', e => {
    const d = e.target.dataset;
    if (d.logo) { logoSet[d.logo] = e.target.checked; startAt = order.indexOf(d.logo); update(); }
    if (d.zoom) zoomLive(d.zoom, false);
  });
  capsEl.addEventListener('input', e => {
    const d = e.target.dataset;
    if (d.id) { caps[d.id] = e.target.value; startAt = order.indexOf(d.id); update(); }
    if (d.zoom) { zooms[d.zoom] = +e.target.value; e.target.nextElementSibling.textContent = e.target.value + '%'; zoomLive(d.zoom, true); }
  });
  // Pressing on a zoom shows its photo before the handle has moved; letting go without a change plays on.
  capsEl.addEventListener('pointerdown', e => { const d = e.target.dataset; if (d && d.zoom) zoomLive(d.zoom, true); });
  capsEl.addEventListener('pointerup', e => { const d = e.target.dataset; if (d && d.zoom) zoomLive(d.zoom, false); });
  // The grid: a listing's photos (its first five picked to begin with) with the person's uploads apart below, or for My Photos the person's uploads and the
  // stock library, one folding group each, nothing picked to begin with. keep = the picks stay (after an upload or a removal).
  async function loadPhotos(keep) {
    const mine = isMine();
    if (!keep) { order = []; caps = {}; zooms = {}; focus = {}; endBg = ''; logoSet = {}; suggested = {}; sugSeen = []; }
    paint();
    gridFor = mine ? 'mine' : 'l:' + listing.value;
    if (!mine && !listing.value) { photosEl.innerHTML = '<div class="sp-wait">Choose a listing to see its photos.</div>'; update(); return; }
    if (!keep) photosEl.innerHTML = '<div class="sp-wait">Loading photos…</div>';
    const u = new URL(PHOTOS, location.href);
    if (mine) u.searchParams.set('kind', 'library'); else { u.searchParams.set('kind', 'property'); u.searchParams.set('subject', listing.value); }
    const asked = gridFor;
    let j = { photos: [] };
    try { j = await (await fetch(u, { credentials: 'same-origin' })).json(); } catch (e) {}
    if (asked !== gridFor) return;   // the source changed while this was on its way
    const tiles = j.photos || [];
    if (!tiles.length) { order = []; photosEl.innerHTML = '<div class="sp-wait">' + (mine ? 'No photos yet.' : 'No photos for this listing.') + '</div>'; paint(); update(); return; }
    if (!mine && !keep) order = tiles.filter(p => !p.own).slice(0, 5).map(p => p.id);   // a fresh listing starts with its first five photos
    order = order.filter(id => tiles.some(p => p.id === id));
    const tile = (p, extra) => '<label class="sp-photo"' + (extra || '') + ' data-id="' + esc(p.id) + '" title="' + esc(p.own ? 'Your upload' : (p.caption || '')) + '"><img src="' + esc(p.thumb) + '" alt="" loading="lazy"><span class="vd-n"></span>'
      + (mine && p.own ? '<button type="button" class="sp-del" data-id="' + esc(p.id) + '" title="Remove this photo" aria-label="Remove this photo">×</button>' : '') + '</label>';
    if (!mine) {
      // A listing: its own photos, then the person's uploads apart under a folding header, shut unless one of them is picked
      // (Nikki, 2026-11-27: "my random uploads are being blended into the end of the Listing photos").
      const own = tiles.filter(p => p.own), openOwn = own.some(p => order.includes(p.id));
      photosEl.innerHTML = tiles.filter(p => !p.own).map(p => tile(p)).join('')
        + (own.length ? '<button type="button" class="sp-group sp-grp" data-grp="own" aria-expanded="' + openOwn + '">Your photos <b>' + own.length + '</b></button>'
          + own.map(p => tile(p, ' data-grp="own"' + (openOwn ? '' : ' hidden'))).join('') : '');
    } else {
      const groups = [];
      for (const p of tiles) { const g = groups.find(x => x[0] === p.group); if (g) g[1].push(p); else groups.push([p.group || 'Photos', [p]]); }
      // A group is open when it is the person's own or holds a picked photo; with neither, the first one.
      const open = groups.map(([name, list]) => list[0].own || list.some(p => order.includes(p.id)));
      if (!open.includes(true)) open[0] = true;
      photosEl.innerHTML = groups.map(([name, list], gi) => '<button type="button" class="sp-group sp-grp" data-grp="' + gi + '" aria-expanded="' + open[gi] + '">' + esc(name) + ' <b>' + list.length + '</b></button>'
        + list.map(p => tile(p, ' data-grp="' + gi + '"' + (open[gi] ? '' : ' hidden'))).join('')).join('');
    }
    paint(); update();
  }
  // A group's header folds or unfolds its photos.
  photosEl.addEventListener('click', e => {
    const h = e.target.closest('.sp-grp'); if (!h) return;
    const open = h.getAttribute('aria-expanded') !== 'true';
    h.setAttribute('aria-expanded', String(open));
    photosEl.querySelectorAll('.sp-photo[data-grp="' + h.dataset.grp + '"]').forEach(t => t.hidden = !open);
  });
  // My Photos: the person's own photos, one or several at a time; each joins the end of the play order.
  const drop = document.getElementById('vdDrop'), dropMsg = document.getElementById('vdDropMsg'), photoFile = document.getElementById('vdPhotoFile');
  const dropAsk = 'Drop more here, or <label class="sp-link" for="vdPhotoFile">choose them</label>.';
  async function uploadPhotos(list) {
    const files = Array.from(list || []); if (!files.length) return;
    drop.classList.add('busy'); let added = 0, err = '';
    for (let k = 0; k < files.length; k++) {
      dropMsg.textContent = 'Uploading ' + (files.length > 1 ? (k + 1) + ' of ' + files.length : files[k].name) + '…';
      const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('photo', files[k]);
      let j;
      try { j = await (await fetch(UPLOAD, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); }
      catch (e) { j = { error: 'The upload did not go through. Please try again.' }; }
      if (j.error) { err = j.error; continue; }
      added++; if (order.length < MAXP) order.push(j.id);
    }
    drop.classList.remove('busy'); photoFile.value = '';
    dropMsg.innerHTML = (err ? esc(err) + ' ' : '') + (added ? (added > 1 ? added + ' photos added. ' : 'Added. ') : '') + dropAsk;
    if (added) loadPhotos(true);
  }
  photoFile.addEventListener('change', e => uploadPhotos(e.target.files));
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => uploadPhotos(e.dataTransfer.files));
  photosEl.addEventListener('click', async e => {
    const b = e.target.closest('.sp-del'); if (!b) return;
    e.preventDefault();
    if (!confirm('Remove this photo from your uploads? Videos you already downloaded are not affected.')) return;
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('id', b.dataset.id);
    let j = {};
    try { j = await (await fetch(DELETE_URL, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (err) { j = { error: 'That did not work. Please try again.' }; }
    if (j.error) { alert(j.error); return; }
    order = order.filter(id => id !== b.dataset.id);
    loadPhotos(true);
  });
  photosEl.addEventListener('click', e => {
    const t = e.target.closest('.sp-photo'); if (!t || e.target.closest('.sp-del')) return;
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
    fillCaption(false);
    if (!agentTouched) {
      const set = (n, v) => { f(n).value = f(n).querySelector('option[value="' + (v || '0') + '"]') ? (v || '0') : '0'; };
      set('agent', d.agent); set('agent2', d.agent2);
      if (!byTouched) fromAgent(1);
      if (!by2Touched) fromAgent(2);
      syncBy();
    }
  }
  // The caption is the listing's (video_caption.php) and changes with it. With the listing taken away, a caption nobody
  // changed goes with it. asked = the button: the listing's own caption again, over what is there.
  const capBox = document.getElementById('vdCaption'), capFill = document.getElementById('vdCapFill'), capCopy = document.getElementById('vdCapCopy');
  let capFrom = '', capSeq = 0;
  async function fillCaption(asked) {
    const id = listing.value, seq = ++capSeq;
    capFill.hidden = !id || !CAPTION;
    if (!id || !CAPTION) { if (capBox.value === capFrom) capBox.value = ''; capFrom = ''; return; }
    let j;
    try { j = await (await fetch(CAPTION + '?listing=' + encodeURIComponent(id), { credentials: 'same-origin' })).json(); } catch (e) { j = null; }
    if (seq !== capSeq) return;   // another listing was picked meanwhile
    if (!j || typeof j.caption !== 'string') { if (asked) say((j && j.error) || 'The caption could not be written. Please try again.', true); return; }
    capBox.value = capFrom = j.caption;
  }
  capFill.addEventListener('click', () => fillCaption(true));
  capCopy.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(capBox.value); } catch (e) { capBox.select(); document.execCommand('copy'); }
    capCopy.textContent = 'Copied'; setTimeout(() => { capCopy.textContent = 'Copy caption'; }, 1500);
  });
  // The presenter picked fills that side's name and phone; both stay editable.
  function fromAgent(k) {
    const d = (f(k === 2 ? 'agent2' : 'agent').selectedOptions[0] || { dataset: {} }).dataset;
    f(k === 2 ? 'end_name2' : 'end_name').value = d.name || ''; f(k === 2 ? 'end_phone2' : 'end_phone').value = d.phone || '';
  }
  // The second side's boxes show once someone is picked there; the team logo row when either side is a team.
  const logoMsg = document.getElementById('vdLogoMsg');
  function teamPicked() { return [f('agent'), f('agent2')].map(s => s.selectedOptions[0]).find(o => o && o.dataset.team) || null; }
  function syncBy() {
    document.getElementById('vdBy2').hidden = f('agent2').value === '0';
    const t = teamPicked();
    document.getElementById('vdTeamLogo').hidden = !t;
    if (t) {
      const has = t.dataset.logo === '1';
      document.getElementById('vdLogoUp').textContent = has ? 'Replace the team logo' : 'Upload a team logo';
      document.getElementById('vdLogoDel').hidden = !has;
      logoMsg.classList.remove('bad');
      logoMsg.textContent = has ? t.dataset.teamName + ' has a logo on file.' : t.dataset.teamName + ' has no logo yet: the card shows the name alone. PNG, JPG or SVG, up to 2 MB.';
    }
  }
  // A team's logo, kept for every later video.
  async function teamLogo(fd) {
    const t = teamPicked(); if (!t) return;
    fd.set('csrf_token', CSRF); fd.set('team', t.dataset.team);
    let j;
    try { j = await (await fetch(LOGO, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); }
    catch (e) { j = { error: 'The logo could not be sent. Please try again.' }; }
    if (j.error) { logoMsg.textContent = j.error; logoMsg.classList.add('bad'); return; }
    form.querySelectorAll('option[data-team="' + t.dataset.team + '"]').forEach(o => { o.dataset.logo = j.has ? '1' : '0'; o.dataset.name = j.has ? '' : o.dataset.teamName; });
    if (f('agent').selectedOptions[0].dataset.team && !byTouched) fromAgent(1);
    if (f('agent2').selectedOptions[0].dataset.team && !by2Touched) fromAgent(2);
    syncBy(); update();
  }
  const logoFile = document.getElementById('vdLogoFile');
  document.getElementById('vdLogoUp').addEventListener('click', () => logoFile.click());
  logoFile.addEventListener('change', () => {
    const fl = logoFile.files[0]; logoFile.value = ''; if (!fl) return;
    const fd = new FormData(); fd.set('logo', fl); fd.set('dark', document.getElementById('vdLogoDark').checked ? '1' : '0');
    teamLogo(fd);
  });
  document.getElementById('vdLogoDel').addEventListener('click', () => {
    if (!confirm('Remove this team\'s logo? It comes off every later video too.')) return;
    const fd = new FormData(); fd.set('remove', '1'); teamLogo(fd);
  });
  // The source decides the order of the cards: My Photos and Video Clip have their card (the uploader first) above
  // Cover Design; a listing's photos stay under it. With no listing to choose, the Listing box moves into Cover Design
  // as an optional way to fill the text in.
  const videoStep = document.getElementById('vdVideoStep'), coverStep = document.getElementById('vdCoverStep'), photosStep = document.getElementById('vdPhotosStep'), clipStep = document.getElementById('vdClipStep');
  const listingField = document.getElementById('vdListingField');
  let lastKind = null, restoring = false;   // restoring = a saved video is being opened: nothing is filled in from a listing
  function syncKind() {
    const c = isClip(), m = isMine(), k = val('kind');
    photosStep.hidden = c; clipStep.hidden = !c;
    document.getElementById('vdCoverHold').hidden = c;   // a clip has no cover photo to time
    document.getElementById('vdNoListing').hidden = !c && !m;
    drop.hidden = !m; document.getElementById('vdPhotosH').textContent = m ? 'My Photos' : 'Photos';
    if (m) coverStep.before(photosStep); else coverStep.after(photosStep);
    // My Photos: the photos are chosen above Cover Design, their zoom, logo and text set in a card of their own under it.
    const slidesStep = document.getElementById('vdSlidesStep');
    if (m) { slidesStep.appendChild(capsWrap); coverStep.after(slidesStep); } else photosStep.appendChild(capsWrap);
    slidesStep.hidden = !m || !order.length;
    if (c) coverStep.before(clipStep); else photosStep.after(clipStep);
    listingField.firstChild.nodeValue = c || m ? 'Fill in the text from a listing (optional)\n' : 'Listing (every Mont Haus listing in the feed)\n';
    if (c || m) { coverStep.querySelector('h2').after(listingField); listingField.style.margin = '0 0 12px'; }
    else { videoStep.appendChild(listingField); listingField.style.margin = '16px 0 0'; }
    // My Photos starts with no listing: what the listing had filled in and nobody changed is cleared with it.
    if (m && lastKind !== null && lastKind !== 'mine' && listing.value) {
      const d = (listing.selectedOptions[0] || { dataset: {} }).dataset;
      [['headline', d.headline], ['line1', d.line1], ['line2', d.line2], ['line3', d.line3], ['label', d.label]].forEach(([n, v]) => { if (f(n).value === (v || '')) f(n).value = ''; });
      listing.value = ''; fromListing();
    }
    // My Photos and Video Clip start with no closing card (Nikki, 2026-11-28); a listing starts with one. A tick set by hand stays.
    if (!endTouched && !restoring && lastKind !== k) { f('end').checked = !m && !c; document.getElementById('vdEndFields').hidden = m || c; }
    lastKind = k;
    if (!c && !m && !listing.value && !restoring) { listing.selectedIndex = 1; fromListing(); }
    if (!c && gridFor !== (m ? 'mine' : 'l:' + listing.value)) { loadPhotos(); return; }
    update();
  }
  form.addEventListener('change', e => {
    const n = e.target.name;
    if (e.target.dataset.zoom || e.target.dataset.id) return;   // a photo's zoom and text see to the preview themselves (above)
    if (n === 'caption') return;   // not in the video
    // Another source, or another listing's photos, is another video: it no longer saves over the one that was opened.
    if (n === 'kind' || (n === 'listing' && isListing())) savedId = '';
    if (n === 'kind') { syncKind(); return; }
    if (n === 'listing') { fromListing(); if (isListing()) { loadPhotos(); return; } }
    if (n === 'agent') { agentTouched = true; byTouched = false; fromAgent(1); syncBy(); }
    if (n === 'agent2') { agentTouched = true; by2Touched = false; fromAgent(2); syncBy(); }
    if (n === 'end') { endTouched = true; document.getElementById('vdEndFields').hidden = !f('end').checked; }
    if (n === 'end_bg') endBg = order[+e.target.value - 1] || '';
    if (n === 'words_all') syncAll();
    if (n === 'logo_kind') { document.getElementById('vdLogoRanges').hidden = val('logo_kind') === 'none'; syncAll(); }
    if (n === 'clip') { f('clip_start').value = 0; }
    update();
  });
  form.addEventListener('input', e => {
    const n = e.target.name;
    // The logo's sliders: the figure beside each, then the preview.
    if (e.target.closest('#vdLogoRanges')) { e.target.nextElementSibling.textContent = e.target.value + (n === 'logo_scale' ? '%' : ''); update(); return; }
    if (n === 'end_name' || n === 'end_phone') byTouched = true;
    if (n === 'end_name2' || n === 'end_phone2') by2Touched = true;
    if (n === 'end_address') addrTouched = true;
    if (['headline', 'line1', 'line2', 'line3', 'clip_start', 'clip_length', 'end_address', 'end_name', 'end_phone', 'end_name2', 'end_phone2', 'end_extra', 'end_site'].includes(n)) update();
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
  // Stop: the video being made is ended on the server; the form stays exactly as it is, ready for Make video again.
  const stopBtn = document.getElementById('vdStop');
  let making = null;
  function stopJob(job) {
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('stop', job);
    return fetch(EXPORT, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(() => {});
  }
  stopBtn.addEventListener('click', async () => {
    const run = making; if (!run || run.stopped) return;
    run.stopped = true; stopBtn.disabled = true; say('Stopping…');
    if (run.job) await stopJob(run.job);
    busy = false; make.disabled = false; stopBtn.hidden = true; progress(null);
    say('Stopped. Nothing on this page has changed: make your changes, then click Make video.');
  });
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (busy) return;
    busy = true; make.disabled = true; dl.hidden = true; tabR.disabled = true; show('preview');
    pick();
    say('Starting…'); progress(0);
    const run = making = { job: '', stopped: false };
    stopBtn.hidden = false; stopBtn.disabled = false;
    const stop = (t, bad) => { busy = false; make.disabled = false; stopBtn.hidden = true; progress(null); say(t, bad); };
    let job;
    try {
      const j = await (await fetch(EXPORT, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })).json();
      if (j.error) return run.stopped ? null : stop(j.error, true);
      job = run.job = j.job;
      saveVideo(true);   // every video made can be opened again later
      if (run.stopped) { stopJob(job); return; }   // Stop was pressed before the server had answered
    } catch (err) { return run.stopped ? null : stop('The video could not be started. Please try again.', true); }
    let misses = 0;
    const ask = async () => {
      if (run.stopped) return;
      let s;
      try { s = await (await fetch(EXPORT + '?job=' + encodeURIComponent(job), { credentials: 'same-origin' })).json(); misses = 0; }
      catch (err) { if (++misses > 15) return stop('The page lost touch with the server. Reload it; the video may still be finishing.', true); return setTimeout(ask, 2000); }
      if (run.stopped) return;
      if (s.state === 'stopped') return stop('Stopped.');
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

  // ── Saved videos: the form as it stands, kept to open again (Nikki, 2026-11-27) ──
  // snapshot() is everything a video is made from: the form's boxes by name, the photos picked in order and what was
  // set on each. restore() puts it back and fills nothing in from a listing. Saved by the button, and by itself each
  // time Make video is pressed; an opened one is saved over until the source or the listing is changed.
  const SAVED = <?= json_encode($saved_url ?? '') ?>;
  // The saved video this page was opened with (template_video.php ?open= or ?copy=), or null for a new one.
  const OPEN = <?= json_encode(!empty($vd_open) ? ['id' => $vd_open['id'], 'name' => $vd_open['name'], 'data' => $vd_open['data']] : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>, COPY = <?= !empty($vd_copy) ? 'true' : 'false' ?>;
  const openMsg = document.getElementById('vdOpenMsg');
  let savedId = '';
  const skip = n => n === 'csrf_token' || n === 'end_bg' || n.endsWith('[]');
  function snapshot() {
    const fields = {};
    form.querySelectorAll('[name]').forEach(el => {
      const n = el.name; if (skip(n)) return;
      if (el.type === 'radio') { if (el.checked) fields[n] = el.value; }
      else if (el.type === 'checkbox') fields[n] = el.checked ? '1' : '';
      else fields[n] = el.value;
    });
    const mine = o => Object.fromEntries(order.filter(id => id in o).map(id => [id, o[id]]));
    // thumb and secs are for the card on the first page only: the cover's small picture and the video's length.
    const t = order.length ? photosEl.querySelector('.sp-photo[data-id="' + CSS.escape(order[0]) + '"] img') : null;
    return { v: 1, fields, order: order.slice(), caps: mine(caps), zooms: mine(zooms), focus: mine(focus), logos: mine(logoSet), endBg, thumb: t ? t.src : '', secs: lastSecs };
  }
  const firstRow = t => String(t || '').split('\n')[0].trim();
  const savedName = () => firstRow(f('end_address').value) || firstRow(f('headline').value) || firstRow(f('line1').value) || 'Video';
  function openSay(t, bad) { openMsg.hidden = !t; openMsg.textContent = t; openMsg.classList.toggle('bad', !!bad); }
  async function saveVideo(quiet) {
    if (!SAVED) return false;
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('data', JSON.stringify(snapshot())); fd.append('name', savedName());
    if (savedId) fd.append('id', savedId);
    let j;
    try { j = await (await fetch(SAVED, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (e) { j = { error: 'That could not be saved. Please try again.' }; }
    if (!j || j.error || !j.id) { if (!quiet) say((j && j.error) || 'That could not be saved. Please try again.', true); return false; }
    savedId = j.id;
    // The address now names this saved video: a reload opens it, and does not copy or start again.
    try { history.replaceState(null, '', location.pathname + '?open=' + encodeURIComponent(savedId)); } catch (e) {}
    if (!quiet) say('Saved. It is on the Saved videos page, to open or replicate later.');
    return true;
  }
  document.getElementById('vdSave').addEventListener('click', () => saveVideo(busy));
  async function restore(d) {
    const fl = d.fields || {};
    let gone = '';
    form.querySelectorAll('[name]').forEach(el => {
      const n = el.name; if (skip(n) || !(n in fl)) return;
      const v = String(fl[n]);
      if (el.type === 'radio') { if ([...form.querySelectorAll('[name="' + n + '"]')].some(r => r.value === v)) el.checked = el.value === v; }
      else if (el.type === 'checkbox') el.checked = v === '1';
      else if (el.tagName === 'SELECT') {
        if ([...el.options].some(o => o.value === v)) el.value = v;
        else if (n === 'listing' || n === 'clip') { el.value = ''; gone = n; }
        else if (n === 'agent' || n === 'agent2') el.value = '0';
      }
      else el.value = v;
    });
    const obj = o => (o && typeof o === 'object' && !Array.isArray(o)) ? Object.assign({}, o) : {};
    order = (Array.isArray(d.order) ? d.order : []).map(String).slice(0, MAXP);
    caps = obj(d.caps); zooms = obj(d.zooms); focus = obj(d.focus); logoSet = obj(d.logos); endBg = String(d.endBg || ''); suggested = {}; sugSeen = [];
    if (isListing() && !listing.value) order = [];   // the listing has left the feed: its photos cannot be shown
    agentTouched = byTouched = by2Touched = addrTouched = endTouched = true;   // everything is as it was saved: nothing is filled in again
    document.getElementById('vdEndFields').hidden = !f('end').checked;
    document.getElementById('vdLogoRanges').hidden = val('logo_kind') === 'none';
    document.querySelectorAll('#vdLogoRanges input').forEach(i => { i.nextElementSibling.textContent = i.value + (i.name === 'logo_scale' ? '%' : ''); });
    syncBy();
    // The caption is as it was saved. A video saved before there was a caption box gets its listing's.
    capSeq++; capFrom = ''; capFill.hidden = !listing.value || !CAPTION;
    if (!('caption' in fl) && listing.value) fillCaption(false);
    dl.hidden = true; tabR.disabled = true; show('preview');
    const want = order.length;
    lastKind = val('kind'); gridFor = isMine() ? 'mine' : 'l:' + listing.value;
    restoring = true; syncKind(); restoring = false;
    if (!isClip()) await loadPhotos(true); else paint();
    const lost = want - order.length;
    return gone === 'listing' && !isMine() && !isClip() ? 'That listing is no longer in the feed, so its photos could not be loaded. The text is as it was saved.'
         : gone === 'clip' && isClip() ? 'That clip is no longer on the server. Upload it again; everything else is as it was saved.'
         : lost > 0 ? (lost === 1 ? 'One photo is' : lost + ' photos are') + ' no longer there and ' + (lost === 1 ? 'was' : 'were') + ' left out. Everything else is as it was saved.' : '';
  }

  scale();
  if (OPEN) restore(OPEN.data).then(note => {
    // Opened to edit: saved over. Replicated: a new video from the first time it is saved or made; the one copied is not touched.
    savedId = COPY ? '' : OPEN.id;
    openSay((COPY ? 'A copy of ' + OPEN.name + '. It is saved as a new video; the one you copied stays as it is.' : 'Editing ' + OPEN.name + '.') + (note ? ' ' + note : ''), !!note);
  });
  else { fromListing(); syncKind(); }
})();
</script>
