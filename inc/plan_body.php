<?php
/**
 * inc/plan_body.php — the builder for the Social Media Plan templates
 * (2026-11-02): the form on the left, the live preview on the right, as the
 * Instagram tool (inc/social_body.php), whose CSS (assets/css/social.css)
 * and patterns it reuses. One body for the four kinds; $pk says which:
 *
 *   property  a listing from the whole feed (the Listing dropdown), the
 *             variant (New Listing, Featured, Just Sold, Rental), and on a
 *             story the SET: pick the photos in order, an end card, a ZIP
 *   broker    the broker, their market, a hook (or a milestone); their
 *             headshot, a lifestyle shot (an upload) or a stock photo
 *   location  an office, an area or a town; the angle (office, team, local);
 *             the team's faces ticked on or off
 *   branding  a season and one of its taglines on a stock photo
 *
 * Expects: $pk, $ctx (the admin's social context), $csrf, the endpoint URLs
 * ($render_url, $export_url, $photos_url, $upload_url, $delete_url), and the
 * kind's data: $pk_listings (plan_listings()), $pk_agents (plan_agents()),
 * $pk_locations (plan_locations()) + $pk_team ([loc key => [agent ids]]),
 * $pk_seasons (plan_seasons()); $pk_preset from the query string (agent,
 * loc, listing, variant, hook, season, size). Nothing is drawn here but the
 * controls: the picture is plan_render.php's HTML in the iframe.
 */
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$pk_preset = (array)($pk_preset ?? []);
$layouts = plan_layouts();
$offered_layouts = match ($pk) {
    'property' => ['photo', 'center'],
    'branding' => ['center', 'photo'],
    default    => ['split', 'photo', 'center'],
};
$default_layout = $offered_layouts[0];
$default_size = isset(social_formats()[(string)($pk_preset['size'] ?? '')]) ? (string)$pk_preset['size'] : ($pk === 'property' ? 'post' : 'story');
// Where the logo starts: upper right over the photo on the split layout (its only place besides none), centred above
// centred words, else the Instagram tool's defaults (upper right on a post, lower left on a story); the badge likewise.
$default_logo  = $default_layout === 'split' ? 'top' : ($default_layout === 'center' ? 'center' : ($default_size === 'story' ? 'bottom' : 'top'));
$default_badge = ($default_size === 'story' && $default_logo !== 'top') ? 'right' : 'left';
$themes = plan_themes($pk); $labels = plan_labels(); $styles = social_line_styles(); $defaults = social_line_defaults();
$line_names = ['Text 1', 'Text 2', 'Text 3', 'Text 4'];
$step = 1;
?>
  <div class="sp-grid">
    <form id="spForm" class="sp-form" method="post" action="<?= $e($export_url) ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="kind" value="<?= $e($pk) ?>">
      <input type="hidden" name="focus_x" value="50">
      <input type="hidden" name="focus_y" value="50">
      <input type="hidden" name="frame" value="0">
      <div id="spFrames"></div>

      <section class="sp-step">
        <h2 class="sp-step-h"><span><?= $step++ ?></span>Pick Template</h2>
        <div class="sp-look">
          <span class="sp-look-l">Size</span>
          <?php foreach (social_formats() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="size" value="<?= $e($k) ?>"<?= $k === $default_size ? ' checked' : '' ?>><span><?= $e($t) ?> · <?= $k === 'post' ? '4:5' : ($k === 'square' ? '1:1' : '9:16') ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Layout</span>
          <?php foreach ($offered_layouts as $k): ?>
          <label class="sp-pillopt"><input type="radio" name="layout" value="<?= $e($k) ?>"<?= $k === $default_layout ? ' checked' : '' ?>><span><?= $e($layouts[$k]) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Look</span>
          <?php foreach ($themes as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="theme" value="<?= $e($k) ?>"<?= $k === 'dark' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Watermark</span>
          <?php foreach (social_marks() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="mark" value="<?= $e($k) ?>"<?= $k === 'triangle' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
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
          <?php foreach (social_logo_places() as $k => $t): ?>
          <label class="sp-pillopt" data-logo="<?= $e($k) ?>"><input type="radio" name="logo" value="<?= $e($k) ?>"<?= $k === $default_logo ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Logo colour</span>
          <?php foreach (plan_logo_colors() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="logo_color" value="<?= $e($k) ?>"<?= $k === 'auto' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-ranges">
          <label class="sp-range">Logo size <input type="range" name="logo_scale" min="50" max="160" step="2" value="100"><output>100%</output></label>
          <label class="sp-range">Nudge across <input type="range" name="logo_dx" min="-200" max="200" step="2" value="0"><output>0</output></label>
          <label class="sp-range">Nudge down <input type="range" name="logo_dy" min="-200" max="200" step="2" value="0"><output>0</output></label>
          <label class="sp-range">Text shadow <input type="range" name="text_shadow" min="0" max="100" step="5" value="35"><output>35</output></label>
          <label class="sp-range">Logo shadow <input type="range" name="logo_shadow" min="0" max="100" step="5" value="0"><output>0</output></label>
          <button type="button" class="sp-link sp-reset" id="spResetLogo">Reset logo and shadows</button>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Badge</span>
          <?php foreach (plan_badge_places() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="badge" value="<?= $e($k) ?>"<?= $k === $default_badge ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if ($pk === 'property'): ?>
      <section class="sp-step">
        <h2 class="sp-step-h"><span><?= $step++ ?></span>Listing</h2>
        <label class="sp-field">Which listing (every Mont Haus listing in the feed)
          <select name="listing" id="spSubject">
            <?php $want = (string)($pk_preset['listing'] ?? ''); $first = true;
            foreach (plan_listing_groups($pk_listings) as $gname => $group): ?>
            <optgroup label="<?= $e($gname) ?>">
              <?php foreach ($group as $l): $sel = $want !== '' ? $l['id'] === $want : $first; $first = false; ?>
              <option value="<?= $e($l['id']) ?>" data-group="<?= $e($gname) ?>"<?= $sel ? ' selected' : '' ?>><?= $e(implode('  ·  ', array_filter([($l['address'] !== '' ? $l['address'] : 'Address withheld') . ', ' . $l['city'], $l['price_text'], social_status_words((string)$l['status']), $l['agent']]))) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="sp-look">
          <span class="sp-look-l">Post type</span>
          <?php $pv = (string)($pk_preset['variant'] ?? 'featured'); foreach (plan_property_variants() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="variant" value="<?= $e($k) ?>"<?= $k === $pv ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-set sp-only-story" hidden>
          <label class="sp-check"><input type="checkbox" name="set" value="1" checked> Make a story <b>set</b>: a multi-image slideshow (the plan: not a single photo)</label>
          <label class="sp-check sp-only-set"><input type="checkbox" name="endcard" value="1" checked> End with a closing card: the logo, the address and who listed it</label>
          <p class="sp-hint sp-only-set">Tick the photos below in the order they should play; the first is the cover with the words, the rest carry a one-line caption. Up to <?= PLAN_MAX_FRAMES ?> photos.</p>
        </div>
      </section>
      <?php elseif ($pk === 'broker'): ?>
      <section class="sp-step">
        <h2 class="sp-step-h"><span><?= $step++ ?></span>Broker</h2>
        <label class="sp-field">Which broker
          <select name="agent" id="spSubject">
            <?php $want = (int)($pk_preset['agent'] ?? 0); $first = true; foreach ($pk_agents as $a): $sel = $want ? $a['id'] === $want : $first; $first = false; ?>
            <option value="<?= (int)$a['id'] ?>" data-market="<?= $e($a['market']) ?>"<?= $sel ? ' selected' : '' ?>><?= $e($a['name']) ?><?= $a['market'] !== '' ? ' · ' . $e($a['market']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="sp-two">
          <label class="sp-field">Their market, as it reads in the hook
            <input name="market" id="spMarket" list="spMarkets" maxlength="40" placeholder="Montrose">
            <datalist id="spMarkets">
              <?php foreach (mk_areas() as $a): ?><option value="<?= $e($a['area']) ?>"></option><?php foreach ($a['towns'] as $t): ?><option value="<?= $e($t['label']) ?>"></option><?php endforeach; endforeach; ?>
            </datalist>
          </label>
          <label class="sp-field">Hook
            <select name="hook" id="spHook">
              <?php $wh = (int)($pk_preset['hook'] ?? 0); foreach (plan_hooks() as $i => $h): ?>
              <option value="<?= $i ?>"<?= $i === $wh ? ' selected' : '' ?> data-tpl="<?= $e($h) ?>"><?= $e(plan_fill($h, ['name' => 'Jackson Horn', 'first' => 'Jackson', 'market' => 'Montrose'])) ?></option>
              <?php endforeach; ?>
              <optgroup label="Milestones">
                <?php foreach (plan_milestones() as $i => $h): ?>
                <option value="m<?= $i ?>" data-tpl="<?= $e($h) ?>"><?= $e(plan_fill($h, ['name' => 'Jackson Horn', 'first' => 'Jackson', 'market' => 'Montrose'])) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <option value="custom" data-tpl="">Custom: I will type the headline</option>
            </select>
          </label>
        </div>
        <p class="sp-hint">The hook becomes the headline with the broker's name and market swapped in; edit it below. The plan rotates through the hooks week to week.</p>
      </section>
      <?php elseif ($pk === 'location'): ?>
      <section class="sp-step">
        <h2 class="sp-step-h"><span><?= $step++ ?></span>Location</h2>
        <label class="sp-field">Which location
          <select name="loc" id="spSubject">
            <?php $want = (string)($pk_preset['loc'] ?? 'office:aspen'); $groups = [];
            foreach ($pk_locations as $k => $l) $groups[$l['group']][$k] = $l;
            foreach ($groups as $gname => $list): ?>
            <optgroup label="<?= $e($gname) ?>">
              <?php foreach ($list as $k => $l): ?>
              <option value="<?= $e($k) ?>" data-label="<?= $e($l['label']) ?>" data-kind="<?= $e($l['kind']) ?>"<?= $k === $want ? ' selected' : '' ?>><?= $e($l['kind'] === 'office' ? $l['label'] . ' office' : $l['label']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="sp-look">
          <span class="sp-look-l">Angle</span>
          <?php foreach (['office' => 'The office', 'team' => 'Team in place', 'local' => 'Local angle'] as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="angle" value="<?= $e($k) ?>"<?= $k === (string)($pk_preset['angle'] ?? 'office') ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-team" id="spTeam">
          <p class="sp-hint">Brokers based here (their roster office, or the Service areas ticked on their profile). Tick up to six to show their faces; the photo + text block layout draws them.</p>
          <div class="sp-team-list" id="spTeamList"></div>
        </div>
      </section>
      <?php elseif ($pk === 'branding'): ?>
      <section class="sp-step">
        <h2 class="sp-step-h"><span><?= $step++ ?></span>Season</h2>
        <div class="sp-two">
          <label class="sp-field">Season
            <select name="season" id="spSubject">
              <?php $ws = (string)($pk_preset['season'] ?? 'always'); foreach ($pk_seasons as $k => $s): ?>
              <option value="<?= $e($k) ?>"<?= $k === $ws ? ' selected' : '' ?>><?= $e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="sp-field">Tagline
            <select name="tagline" id="spTagline"></select>
          </label>
        </div>
        <p class="sp-hint">Drafts to start from; the words are yours to edit below. The seasonal branding strategy itself lives outside this tool.</p>
      </section>
      <?php endif; ?>

      <section class="sp-step">
        <h2 class="sp-step-h"><span><?= $step++ ?></span>Photo</h2>
        <p class="sp-hint" id="spPhotoHint"><?= $pk === 'property' ? 'Choose a listing photo or upload your own below.' : ($pk === 'broker' ? 'The headshot, one of your photos (a lifestyle shot, the plan asks), or a stock photo of their market.' : 'Choose a stock photo or upload your own below.') ?></p>
        <div class="sp-photos" id="spPhotos"><div class="sp-wait">Loading photos…</div></div>
        <div class="sp-drop" id="spDrop">
          <span id="spDropMsg">Or use your own: drop a photo here, or <label class="sp-link" for="spFile">choose one</label>.</span>
          <small>JPG, PNG or WEBP, up to 20 MB.</small>
          <input type="file" id="spFile" accept="image/jpeg,image/png,image/webp,image/*" hidden>
        </div>
        <div class="sp-framing">
          <div class="sp-framer empty" id="spFramer" style="aspect-ratio:1080 / 1440"><img id="spFramerImg" alt="" draggable="false"><span class="sp-framer-hint">Drag to move</span></div>
          <label class="sp-zoom">Zoom <input type="range" name="zoom" min="1" max="3" step="0.02" value="1"></label>
          <small>Drag the photo to choose what shows; zoom in to crop closer.</small>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h"><span><?= $step++ ?></span>Text</h2>
        <div class="sp-fields">
          <div class="sp-two">
            <label class="sp-field">Label on the photo
              <select name="label" id="spLabel">
                <option value="__none">No label</option>
                <?php foreach ($labels as $lb): ?><option value="<?= $e($lb) ?>"><?= $e($lb) ?></option><?php endforeach; ?>
                <option value="__custom">Custom…</option>
              </select>
            </label>
            <label class="sp-field" id="spCustomWrap" hidden>Your label <input name="label_custom" maxlength="28" placeholder="Now Open"></label>
          </div>
          <label class="sp-field">Headline <span class="sp-only-multi">(press Enter for a new line)</span> <textarea name="headline" maxlength="140" rows="2"></textarea></label>
          <?php foreach ($line_names as $i => $nm): ?>
          <div class="sp-line<?= $i >= 2 ? ' sp-only-long' : '' ?>">
            <label class="sp-field"><?= $e($nm) ?> <input name="line_text[]" maxlength="90"></label>
            <label class="sp-field sp-style"><?= $e($nm) ?> style
              <select name="line_style[]"><?php foreach ($styles as $k => $w): ?><option value="<?= $e($k) ?>"<?= $k === $defaults[$i] ? ' selected' : '' ?>><?= $e($w) ?></option><?php endforeach; ?></select>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="sp-actions">
        <button type="submit" class="sp-btn" id="spDownload">Download PNG</button>
        <p class="sp-help" id="spHelp">The download is a 1080 × 1440 PNG for a post, 1080 × 1080 for a square, or 1080 × 1920 for a story.</p>
      </div>
    </form>

    <aside class="sp-preview">
      <div class="sp-stage" id="spStage">
        <iframe id="spFrame" title="Preview" width="1080" height="1440" scrolling="no"></iframe>
        <div class="sp-busy" id="spBusy"></div>
      </div>
      <div class="sp-framepills" id="spFramePills" hidden></div>
      <p class="sp-cap">This is a preview. Click Download to save it.</p>
      <p class="sp-cap-btn"><button type="submit" form="spForm" class="sp-btn sp-btn-sm" id="spDownload2">Download PNG</button></p>
    </aside>
  </div>

<script>
(function () {
  const KIND = <?= json_encode($pk) ?>;
  const RENDER = <?= json_encode($render_url) ?>, PHOTOS = <?= json_encode($photos_url) ?>, UPLOAD = <?= json_encode($upload_url) ?>, DELETE_URL = <?= json_encode($delete_url) ?>;
  const SIZES = { post: 1440, square: 1080, story: 1920 };
  const MAX_FRAMES = <?= (int)PLAN_MAX_FRAMES ?>;
  const JSON_FLAGS = <?= json_encode(JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  <?php if ($pk === 'property'): ?>
  const LISTINGS = <?= json_encode(array_values(array_map(fn($l) => ['id' => $l['id'], 'headline' => $l['headline'], 'town' => $l['town'], 'price_text' => $l['price_text'], 'specs_text' => $l['specs_text'],
      'is_rental' => (int)$l['is_rental'], 'closed' => (bool)$l['closed'], 'new' => (bool)$l['new'], 'agent' => $l['agent'], 'city' => trim((string)$l['city'])], $pk_listings)), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  <?php elseif ($pk === 'broker'): ?>
  const AGENTS = <?= json_encode(array_values(array_map(fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'first' => $a['first'], 'title' => $a['title'], 'market' => $a['market']], $pk_agents)), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  <?php elseif ($pk === 'location'): ?>
  const AGENTS = <?= json_encode(array_values(array_map(fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'first' => $a['first']], $pk_agents)), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const TEAM = <?= json_encode($pk_team, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  <?php elseif ($pk === 'branding'): ?>
  const SEASONS = <?= json_encode(array_map(fn($s) => ['name' => $s['name'], 'taglines' => $s['taglines']], $pk_seasons), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  <?php endif; ?>

  const form = document.getElementById('spForm'), frame = document.getElementById('spFrame'),
        stage = document.getElementById('spStage'), photos = document.getElementById('spPhotos'),
        busy = document.getElementById('spBusy'),
        drop = document.getElementById('spDrop'), dropMsg = document.getElementById('spDropMsg'),
        fileIn = document.getElementById('spFile'),
        framer = document.getElementById('spFramer'), fimg = document.getElementById('spFramerImg'),
        labelSel = document.getElementById('spLabel'), customWrap = document.getElementById('spCustomWrap'),
        framesBox = document.getElementById('spFrames'), pills = document.getElementById('spFramePills'),
        dl = document.getElementById('spDownload'), dl2 = document.getElementById('spDownload2'), help = document.getElementById('spHelp');
  const f = n => form.elements[n];
  const esc = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  const fmt = () => f('size').value, fmtH = () => SIZES[fmt()] || 1440;
  const layout = () => f('layout').value;
  const lineText = i => form.querySelectorAll('input[name="line_text[]"]')[i];
  const lineStyle = i => form.querySelectorAll('select[name="line_style[]"]')[i];
  const subject = () => document.getElementById('spSubject') ? document.getElementById('spSubject').value : '';
  const LOGO_DEFAULT = s => s === 'story' ? 'bottom' : 'top';
  const BADGE_DEFAULT = s => s === 'story' ? 'right' : 'left';
  let prevSize = fmt();
  let frames = [];          // the story set's photo ids, in order (property + story only)
  let allTiles = [];        // the photos the picker holds

  // ── The story set (property, story size) ──
  const setOn = () => KIND === 'property' && fmt() === 'story' && f('set') && f('set').checked;
  function syncSet() {
    const story = KIND === 'property' && fmt() === 'story';
    form.querySelectorAll('.sp-only-story').forEach(el => el.hidden = !story);
    form.querySelectorAll('.sp-only-set').forEach(el => el.hidden = !setOn());
    const on = setOn();
    framesBox.innerHTML = on ? frames.map(id => '<input type="hidden" name="frames[]" value="' + esc(id) + '">').join('') : '';
    photos.querySelectorAll('.sp-photo').forEach(t => {
      const id = t.querySelector('input').value, i = frames.indexOf(id);
      let b = t.querySelector('.sp-ord');
      if (on && i >= 0) { if (!b) { b = document.createElement('b'); b.className = 'sp-ord'; t.appendChild(b); } b.textContent = i + 1; }
      else if (b) b.remove();
    });
    const end = f('endcard') && f('endcard').checked;
    const n = on ? frames.length + (end ? 1 : 0) : 1;
    pills.hidden = !on || n < 2;
    if (on) {
      let cur = +f('frame').value; if (cur >= n) cur = 0; f('frame').value = cur;
      let html = '';
      for (let i = 0; i < n; i++) {
        const label = i === 0 ? 'Cover' : (end && i === n - 1 ? 'End' : String(i + 1));
        html += '<label class="sp-pillopt"><input type="radio" name="_pill" value="' + i + '"' + (i === cur ? ' checked' : '') + '><span>' + label + '</span></label>';
      }
      pills.innerHTML = html;
    } else f('frame').value = 0;
    dl.textContent = dl2.textContent = on ? 'Download story set (ZIP)' : 'Download PNG';
    help.textContent = on ? 'The download is a ZIP of ' + n + ' PNGs at 1080 × 1920, numbered in play order: post them as one story.'
                          : 'The download is a 1080 × 1440 PNG for a post, 1080 × 1080 for a square, or 1080 × 1920 for a story.';
  }
  pills.addEventListener('input', e => { if (e.target.name === '_pill') { f('frame').value = e.target.value; update(); } });

  // ── Layout rules: which logo places and how many lines each layout shows ──
  function syncLayout() {
    const L = layout(), logo = f('logo');
    if (logo.value === LOGO_DEFAULT(prevSize) && prevSize !== fmt()) logo.value = LOGO_DEFAULT(fmt());
    if (f('badge').value === BADGE_DEFAULT(prevSize) && prevSize !== fmt()) f('badge').value = BADGE_DEFAULT(fmt());
    prevSize = fmt();
    form.querySelectorAll('[data-logo]').forEach(el => {
      const k = el.dataset.logo;
      el.hidden = (L === 'split' && (k === 'bottom' || k === 'center')) || (L !== 'center' && k === 'center');
    });
    if (L === 'split' && (logo.value === 'bottom' || logo.value === 'center')) logo.value = 'top';
    if (L !== 'center' && logo.value === 'center') logo.value = 'top';
    form.querySelectorAll('.sp-only-long').forEach(el => el.hidden = L !== 'split');
    form.querySelectorAll('.sp-only-multi').forEach(el => el.hidden = L === 'photo');
    framer.style.aspectRatio = '1080 / ' + (L === 'split' ? ({ post: 780, square: 520, story: 860 })[fmt()] : fmtH());
    customWrap.hidden = labelSel.value !== '__custom';
  }

  // ── The words, from the subject ──
  const setLabel = v => { labelSel.value = v; if (labelSel.value !== v) labelSel.value = '__none'; };
  const setLines = arr => { for (let i = 0; i < 4; i++) { lineText(i).value = (arr[i] && arr[i][0]) || ''; if (arr[i] && arr[i][1]) lineStyle(i).value = arr[i][1]; } };
  function fill() {
    if (KIND === 'property') {
      const l = LISTINGS.find(x => x.id === subject()); if (!l) return;
      const v = f('variant').value;
      f('headline').value = l.headline;
      setLines([[l.town, 'town'], [l.price_text, 'price'], [l.specs_text, 'details'], [l.agent ? 'Listed by ' + l.agent : '', 'note']]);
      setLabel({ new: 'New Listing', featured: l.is_rental ? 'Featured Rental' : 'Featured Listing', sold: 'Just Sold', rental: 'Featured Rental' }[v] || 'Featured Listing');
    } else if (KIND === 'broker') {
      const a = AGENTS.find(x => String(x.id) === subject()); if (!a) return;
      const mk = f('market'), market = mk.value.trim() || a.market;
      const opt = f('hook').selectedOptions[0], tpl = opt ? opt.dataset.tpl : '';
      if (tpl) f('headline').value = tpl.replace(/\{name\}/g, a.name).replace(/\{first\}/g, a.first).replace(/\{market\}/g, market);
      setLines([[a.name, 'town'], ['Mont Haus International Realty · ' + market, 'details'], [a.title || '', 'plain'], ['', 'note']]);
      setLabel(String(f('hook').value).startsWith('m') ? 'Milestone' : 'Broker Spotlight');
    } else if (KIND === 'location') {
      const opt = f('loc').selectedOptions[0]; if (!opt) return;
      const label = opt.dataset.label, kind = opt.dataset.kind, angle = f('angle').value;
      const place = kind === 'office' ? label + ', Colorado' : (kind === 'area' ? label : label + ', Colorado');
      if (angle === 'team') {
        f('headline').value = kind === 'office' ? 'Our ' + label + ' team' : 'Our brokers in ' + label;
        setLines([['Mont Haus International Realty', 'town'], [place, 'details'], ['', 'plain'], ['', 'note']]);
        setLabel('Meet the Team');
      } else if (angle === 'local') {
        f('headline').value = label + ' real estate';
        setLines([['Mont Haus International Realty', 'town'], ['Type the local angle: a neighborhood, a notable sale, a trend', 'note'], ['', 'plain'], ['', 'note']]);
        setLabel('Local Market');
      } else {
        f('headline').value = kind === 'office' ? 'Mont Haus ' + label : 'Mont Haus in ' + label;
        setLines([[place, 'town'], ['Mont Haus International Realty', 'details'], ['', 'plain'], ['', 'note']]);
        setLabel(kind === 'office' ? 'Office Feature' : '__none');
      }
    } else if (KIND === 'branding') {
      const tl = f('tagline'); f('headline').value = tl.value || '';
      setLines([['Mont Haus International Realty', 'town'], ['', 'details'], ['', 'plain'], ['', 'note']]);
      setLabel('__none');
    }
  }
  function fillTaglines() {
    if (KIND !== 'branding') return;
    const s = SEASONS[subject()], tl = f('tagline'); if (!s) return;
    tl.innerHTML = s.taglines.map((t, i) => '<option value="' + esc(t) + '"' + (i === 0 ? ' selected' : '') + '>' + esc(t.replace(/\n/g, ' ')) + '</option>').join('');
  }
  // The team ticks for a location (location only): the brokers based there, the first six ticked when the angle is Team.
  function fillTeam(reset) {
    if (KIND !== 'location') return;
    const list = document.getElementById('spTeamList'), ids = TEAM[subject()] || [];
    const was = reset ? null : new Set([...form.querySelectorAll('input[name="team[]"]:checked')].map(i => +i.value));
    const tickAll = f('angle').value === 'team';
    list.innerHTML = ids.length ? ids.map((id, i) => {
      const a = AGENTS.find(x => x.id === id); if (!a) return '';
      const on = was ? was.has(id) : (tickAll && i < 6);
      return '<label class="sp-check"><input type="checkbox" name="team[]" value="' + id + '"' + (on ? ' checked' : '') + '> ' + esc(a.name) + '</label>';
    }).join('') : '<p class="sp-hint">Nobody on the roster is based here yet.</p>';
  }
  form.addEventListener('input', e => {
    if (e.target.name !== 'team[]') return;
    const on = form.querySelectorAll('input[name="team[]"]:checked');
    if (on.length > 6) { e.target.checked = false; alert('Six faces at most: the row has no room for more.'); }
  });

  // ── The logo sliders: show each value; the text shadow follows the look (35 on dark, 0 on light) until it is moved. ──
  function showRange(r) { const o = r.parentElement.querySelector('output'); if (o) o.textContent = r.name === 'logo_scale' ? r.value + '%' : r.value; }
  function syncShadowDefault() { const r = f('text_shadow'); if (!r.dataset.touched) { r.value = f('theme').value === 'light' ? 0 : 35; showRange(r); } }
  form.querySelectorAll('.sp-range input').forEach(showRange);
  document.getElementById('spResetLogo').addEventListener('click', () => {
    for (const [n, v] of [['logo_scale', 100], ['logo_dx', 0], ['logo_dy', 0], ['logo_shadow', 0]]) { f(n).value = v; delete f(n).dataset.touched; showRange(f(n)); }
    delete f('text_shadow').dataset.touched; syncShadowDefault();
    f('logo_color').value = 'auto';
    update();
  });

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

  // ── Photos ──
  function photoTile(p, checked) {
    return '<label class="sp-photo' + (p.own ? ' own' : '') + '"' + (p.caption ? ' title="' + esc(p.caption) + '"' : '') + '><input type="radio" name="photo" value="' + esc(p.id) + '" data-large="' + esc(p.large) + '"' +
           (checked ? ' checked' : '') + '><img src="' + esc(p.thumb) + '" alt="' + esc(p.caption || '') + '" loading="lazy">' + (p.own ? '<i>Yours</i><button type="button" class="sp-del" data-id="' + esc(p.id) + '" title="Remove this photo" aria-label="Remove this photo">×</button>' : '') + '</label>';
  }
  async function loadPhotos(selectId) {
    photos.innerHTML = '<div class="sp-wait">Loading photos…</div>';
    const u = new URL(PHOTOS, location.origin); u.searchParams.set('kind', KIND); u.searchParams.set('subject', subject());
    let j = { photos: [] };
    try { j = await (await fetch(u, { credentials: 'same-origin' })).json(); } catch (e) {}
    allTiles = j.photos || [];
    if (!allTiles.length) {
      photos.innerHTML = '<div class="sp-wait">No photos yet. Add your own below.</div>';
      frames = []; framerSet(''); syncSet(); update(); return;
    }
    const first = (selectId && allTiles.find(p => p.id === selectId)) ? selectId : (j.suggest && allTiles.find(p => p.id === j.suggest) ? j.suggest : allTiles[0].id);
    const groups = [];
    for (const p of allTiles) { const g = groups.find(x => x[0] === p.group); if (g) g[1].push(p); else groups.push([p.group || 'Photos', [p]]); }
    let html = '';
    for (const [name, list] of groups) html += '<div class="sp-group">' + esc(name) + '</div>' + list.map(p => photoTile(p, p.id === first)).join('');
    photos.innerHTML = html;
    // A fresh listing: the set starts with its first photos in feed order (the cover first), up to the limit.
    if (KIND === 'property') {
      const own = allTiles.filter(p => !p.own).map(p => p.id);
      frames = (selectId && frames.length) ? frames.filter(id => allTiles.some(p => p.id === id)) : own.slice(0, Math.min(MAX_FRAMES, 4));
      if (selectId && !frames.includes(selectId)) frames.push(selectId);
      if (!frames.length) frames = [first];
      if (frames.length > MAX_FRAMES) frames = frames.slice(0, MAX_FRAMES);
      const cover = form.querySelector('input[name="photo"][value="' + CSS.escape(frames[0]) + '"]'); if (cover && setOn()) cover.checked = true;
    }
    const sel = allTiles.find(p => p.id === (form.querySelector('input[name="photo"]:checked') || {}).value);
    framerSet(sel ? sel.large : '', sel ? sel.id : ''); syncSet(); update();
  }
  // In set mode a click on a tile adds it to (or removes it from) the play order; the cover is the first.
  photos.addEventListener('click', e => {
    if (!setOn() || e.target.closest('.sp-del')) return;
    const tile = e.target.closest('.sp-photo'); if (!tile) return;
    e.preventDefault();
    const id = tile.querySelector('input').value, i = frames.indexOf(id);
    if (i >= 0) { if (frames.length > 1) frames.splice(i, 1); }
    else if (frames.length < MAX_FRAMES) frames.push(id);
    else { alert('Up to ' + MAX_FRAMES + ' photos in a set. Untick one first.'); return; }
    const cover = form.querySelector('input[name="photo"][value="' + CSS.escape(frames[0]) + '"]');
    if (cover) { cover.checked = true; framerSet(cover.dataset.large); }
    syncSet(); update();
  });
  async function upload(file) {
    if (!file) return;
    drop.classList.add('busy'); dropMsg.textContent = 'Uploading ' + file.name + '…';
    const fd = new FormData(); fd.append('csrf_token', f('csrf_token').value); fd.append('photo', file);
    let j = {};
    try { j = await (await fetch(UPLOAD, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); }
    catch (e) { j = { error: 'The upload did not go through. Please try again.' }; }
    drop.classList.remove('busy'); fileIn.value = '';
    if (j.error) { dropMsg.textContent = j.error; return; }
    dropMsg.innerHTML = 'Added. Drop another, or <label class="sp-link" for="spFile">choose one</label>.';
    if (setOn() && frames.length < MAX_FRAMES) frames.push(j.id);
    loadPhotos(j.id);
  }
  fileIn.addEventListener('change', e => upload(e.target.files[0]));
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => upload(e.dataTransfer.files[0]));
  photos.addEventListener('click', async e => {
    const b = e.target.closest('.sp-del'); if (!b) return;
    e.preventDefault(); e.stopPropagation();
    if (!confirm('Remove this photo from your uploads? Posts you already downloaded are not affected.')) return;
    const fd = new FormData(); fd.append('csrf_token', f('csrf_token').value); fd.append('id', b.dataset.id);
    let j = {};
    try { j = await (await fetch(DELETE_URL, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (err) { j = { error: 'That did not work. Please try again.' }; }
    if (j.error) { alert(j.error); return; }
    frames = frames.filter(id => id !== b.dataset.id);
    const wasSelected = (form.querySelector('input[name="photo"]:checked') || {}).value === b.dataset.id;
    loadPhotos(wasSelected ? undefined : (form.querySelector('input[name="photo"]:checked') || {}).value);
  });

  // ── Framing: the same CSS the picture uses, as a control ──
  function framerSet(src, id) {
    f('focus_x').value = 50; f('focus_y').value = (id && /^(headshot|face):/.test(id)) ? 22 : 50; f('zoom').value = 1;   // a headshot: start on the face
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
    const coverW = Math.max(bw, bh * nat), coverH = Math.max(bh, bw / nat);
    const ox = Math.max(1, coverW * Z - bw), oy = Math.max(1, coverH * Z - bh);
    f('focus_x').value = Math.round(Math.max(0, Math.min(100, drag.fx - (e.clientX - drag.x) / ox * 100)));
    f('focus_y').value = Math.round(Math.max(0, Math.min(100, drag.fy - (e.clientY - drag.y) / oy * 100)));
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
    if (n === 'listing' || n === 'agent' || n === 'loc') {
      if (n === 'agent') { const o = e.target.selectedOptions[0]; f('market').value = o ? o.dataset.market : ''; }
      if (n === 'loc') fillTeam(true);
      fill(); loadPhotos(); return;
    }
    if (n === 'season') { fillTaglines(); fill(); loadPhotos(); return; }
    if (n === 'variant' || n === 'hook' || n === 'market' || n === 'tagline') fill();
    if (n === 'angle') { if (e.target.value === 'team') f('layout').value = 'split'; fillTeam(true); fill(); syncLayout(); }
    if (n === 'size') { syncLayout(); scale(); syncSet(); }
    if (n === 'layout') syncLayout();
    if (n === 'set' || n === 'endcard') syncSet();
    if (n === 'label') customWrap.hidden = labelSel.value !== '__custom';
    if (n === 'photo') { if (setOn()) { frames = [e.target.value].concat(frames.filter(id => id !== e.target.value)).slice(0, MAX_FRAMES); syncSet(); } framerSet(e.target.dataset.large, e.target.value); }
    if (n === 'theme') syncShadowDefault();
    if (['logo_scale', 'logo_dx', 'logo_dy', 'text_shadow', 'logo_shadow'].includes(n)) { showRange(e.target); e.target.dataset.touched = '1'; }
    if (n === 'zoom') framerApply();
    update();
  });
  form.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'submit') e.preventDefault();
  });
  form.addEventListener('submit', () => { busy.classList.add('on'); setTimeout(() => busy.classList.remove('on'), setOn() ? 45000 : 10000); });

  new ResizeObserver(scale).observe(stage); scale();
  if (KIND === 'broker') { const o = f('agent').selectedOptions[0]; f('market').value = o ? o.dataset.market : ''; }
  fillTaglines();
  fillTeam(true);
  syncLayout();
  fill();
  loadPhotos();
})();
</script>
