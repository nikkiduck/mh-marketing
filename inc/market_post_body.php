<?php
/**
 * inc/market_post_body.php — the Market Snapshot builder's body (2026-10-18),
 * drawn by portal/market_post.php (the Templates area, under the portal
 * header) and market_snapshot.php (the admin side). See inc/market.php.
 *
 * Expects: $render_url, $export_url (POST endpoints), $report_url (the
 * report, for the way back), $csrf, $months (market_months()), $month (the
 * one preselected), $market (preselected card), $stock (social_stock():
 * name => caption; empty on a Mac, where the photos are not on disk),
 * $board_names (market id => name, the picker's groups). $market is a card
 * key (market_card_key() turns the report's market ids into one). The places
 * are a dropdown grouped by board (21 of them since the one-place-per-card
 * redesign of 2026-10-18); the background photos are thumbnails with the
 * location under each (market_stock_location()), None first.
 *
 * Like the Instagram tool: the form on the left, a live preview on the right
 * (an iframe whose srcdoc is what portal/market_render.php returns for the
 * form as it stands, fetched on every change, debounced, scaled to fit), and
 * Download PNG posts the same form to portal/market_export.php. The post is
 * never drawn here; the social.css classes are reused.
 */
$v = fn(string $f) => (int)@filemtime(__DIR__ . '/../' . $f);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$defs = market_cards($data ?? []);   // the static cards plus the feed towns of the month
$market = market_card_key($market, $data ?? []);
$stock_thumb = $stock_thumb ?? fn(string $name) => social_stock_url($name, 400);   // the harness points this at a local picture
$boards = (array)($board_names ?? []);
$suggest = [];   // market => its suggested backdrop, when that photo is on the server
foreach ($defs as $k => $d) if (!empty($d['photo']) && isset($stock[$d['photo']])) $suggest[$k] = $d['photo'];
?>
<link rel="stylesheet" href="/assets/css/social.css?v=<?= $v('assets/css/social.css') ?>">
<style>
  .sp-kind:first-of-type { margin-top: 0; }
  .sp-form .sp-field.sp-kind { margin-bottom: 18px; }   /* room under the Location dropdown before Leads with (Nikki, 2026-10-18) */
  .sp-form .sp-field.sp-kind select { font-size: 15px; padding: 8px 34px 8px 12px; max-width: 340px; width: 100%; appearance: none; -webkit-appearance: none;
      background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%238a6d36' stroke-width='1.6'/%3E%3C/svg%3E") no-repeat right 12px center; }
  .sp-form .sp-field.sp-kind { font-size: 13px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted, #6f6857); }
  .mp-photos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 12px; margin-top: 10px; }
  @media (min-width: 560px) { .mp-photos { grid-template-columns: repeat(5, 1fr); } }
  .mp-photo { position: relative; cursor: pointer; display: block; text-align: center; }
  .mp-photo input { position: absolute; opacity: 0; width: 0; height: 0; }
  .mp-photo img, .mp-none { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 8px; border: 3px solid transparent; background: #e8e2d6; transition: border-color .15s; }
  .mp-none { background: linear-gradient(135deg, #141210 50%, #F1ECE2 50%); }
  .mp-photo input:checked + img, .mp-photo input:checked + .mp-none { border-color: var(--gold-deep, #8a6d36); }
  .mp-photo input:focus-visible + img, .mp-photo input:focus-visible + .mp-none { outline: 2px solid var(--gold-deep, #8a6d36); outline-offset: 2px; }
  .mp-photo small { display: block; margin-top: 5px; font-size: 12.5px; line-height: 1.25; color: var(--muted, #6f6857); }
  .mp-photo input:checked ~ small { color: var(--gold-deep, #8a6d36); font-weight: 500; }
  .mp-note { color: var(--muted, #6f6857); font-size: 14px; margin: 12px 0 0; }
  .mp-note a { color: var(--gold-deep, #8a6d36); }
  .sp-stage.story { max-width: 300px; }
</style>
  <div class="sp-grid">
    <form id="mpForm" class="sp-form" method="post" action="<?= $e($export_url) ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">

      <section class="sp-step">
        <h2 class="sp-step-h"><span>1</span>Location</h2>
        <label class="sp-field sp-kind">Location
          <select name="market" id="mpMarket">
            <?php $groups = []; foreach ($defs as $k => $d) $groups[$d['market']][$k] = $d;
            foreach ($groups as $mk => $cards): ?>
            <optgroup label="<?= $e($boards[$mk] ?? $mk) ?>">
              <?php foreach ($cards as $k => $d): ?>
              <option value="<?= $e($k) ?>"<?= $k === $market ? ' selected' : '' ?> data-suggest="<?= $e($suggest[$k] ?? '') ?>"><?= $e($d['title']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if (count($months) > 1): ?>
        <label class="sp-field sp-kind">Month
          <select name="month">
            <?php foreach ($months as $mm): ?><option value="<?= $e($mm) ?>"<?= $mm === $month ? ' selected' : '' ?>><?= $e(market_month_label($mm)) ?></option><?php endforeach; ?>
          </select>
        </label>
        <?php else: ?>
        <input type="hidden" name="month" value="<?= $e($month) ?>">
        <?php endif; ?>
        <div class="sp-look">
          <span class="sp-look-l">Leads with</span>
          <?php foreach (market_card_ptypes() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="ptype" value="<?= $e($k) ?>"<?= $k === 'SF' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <p class="mp-note">The figures are <?= $e(market_month_label($month)) ?>'s, from the <a href="<?= $e($report_url) ?>">Market Report</a>. A place with fewer than ten closed sales in the month shows year-to-date figures instead.</p>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h"><span>2</span>Look</h2>
        <div class="sp-look">
          <span class="sp-look-l">Size</span>
          <?php foreach (market_card_formats() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="format" value="<?= $e($k) ?>"<?= $k === 'post' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Look</span>
          <?php foreach (market_card_themes() as $k => $t): ?>
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
          <span class="sp-look-l">Mont Haus logo</span>
          <label class="sp-pillopt"><input type="radio" name="logo" value="top" checked><span>Upper right</span></label>
          <label class="sp-pillopt"><input type="radio" name="logo" value="bottom"><span>Lower left</span></label>
        </div>
        <div class="sp-look"><span class="sp-look-l">Background photo</span></div>
        <div class="mp-photos" id="mpPhotos">
          <label class="mp-photo"><input type="radio" name="backdrop" value=""<?= ($suggest[$market] ?? '') === '' ? ' checked' : '' ?>><span class="mp-none"></span><small>None</small></label>
          <?php foreach ($stock as $name => $caption): ?>
          <label class="mp-photo" title="<?= $e($caption) ?>"><input type="radio" name="backdrop" value="<?= $e($name) ?>"<?= ($suggest[$market] ?? '') === $name ? ' checked' : '' ?>><img src="<?= $e($stock_thumb($name)) ?>" alt="<?= $e($caption) ?>" loading="lazy"><small><?= $e(market_stock_location($name, $caption)) ?></small></label>
          <?php endforeach; ?>
        </div>
        <?php if (!$stock): ?><p class="mp-note">The Mont Haus stock photos are on the server; on this copy the ground is plain.</p><?php endif; ?>
      </section>

      <div class="sp-actions">
        <button type="submit" class="sp-btn">Download PNG</button>
        <p class="sp-help">The download is a 1080 × 1350 PNG for a post, or 1080 × 1920 for a story.</p>
      </div>
    </form>

    <aside class="sp-preview">
      <div class="sp-stage" id="mpStage">
        <iframe id="mpFrame" title="Preview" width="1080" height="1350" scrolling="no"></iframe>
        <div class="sp-busy" id="mpBusy"></div>
      </div>
      <p class="sp-cap">This is a preview. Click Download to save as a PNG.</p>
      <p class="sp-cap-btn"><button type="submit" form="mpForm" class="sp-btn sp-btn-sm">Download PNG</button></p>
    </aside>
  </div>

<script>
(function () {
  const RENDER = <?= json_encode($render_url) ?>;
  const form = document.getElementById('mpForm'), frame = document.getElementById('mpFrame'),
        stage = document.getElementById('mpStage'), busy = document.getElementById('mpBusy'),
        photos = document.getElementById('mpPhotos');
  const f = n => form.elements[n];
  const height = () => f('format').value === 'story' ? 1920 : 1350;
  let timer, seq = 0, backdropTouched = false;
  function update() { clearTimeout(timer); timer = setTimeout(render, 200); }
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
    const w = stage.clientWidth, H = height();
    frame.height = H; frame.style.height = H + 'px';
    frame.style.transform = 'scale(' + (w / 1080) + ')';
    stage.style.height = Math.round(H * w / 1080) + 'px';
    stage.classList.toggle('story', H === 1920);
  }
  // The suggested photo follows the location until the person picks one themselves.
  function suggestBackdrop() {
    if (backdropTouched) return;
    const m = form.querySelector('#mpMarket option:checked');
    const want = m ? m.dataset.suggest : '';
    const tile = photos.querySelector('input[name="backdrop"][value="' + want.replace(/"/g, '') + '"]');
    if (tile) tile.checked = true;
  }
  form.addEventListener('input', e => {
    const n = e.target.name;
    if (n === 'backdrop') backdropTouched = true;
    if (n === 'market') suggestBackdrop();
    if (n === 'format') scale();
    update();
  });
  form.addEventListener('submit', () => { busy.classList.add('on'); setTimeout(() => busy.classList.remove('on'), 10000); });
  new ResizeObserver(scale).observe(stage); scale();
  render();
})();
</script>
