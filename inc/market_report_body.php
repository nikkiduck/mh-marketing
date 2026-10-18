<?php
/**
 * inc/market_report_body.php — the Monthly Market Report's body, drawn by
 * portal/market.php (under the portal header) and market_report.php (under
 * the admin header). See inc/market.php.
 *
 * Expects: $data (market_load()), $boot (market_boot()). The markup is the
 * artifact's page, renamed to .mr-* classes; assets/js/market-report.js
 * fills it from window.MH_MARKET (the month's five datasets and the words
 * for the month), so the only month-specific text on the server side is the
 * title. Markets are tabs; the hash (#aspen) opens one, as the artifact did.
 */
$v = fn(string $f) => (int)@filemtime(__DIR__ . '/../' . $f);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="/assets/css/market.css?v=<?= $v('assets/css/market.css') ?>">
<div class="mr" id="mr">
  <div class="mr-top">
    <div class="mr-head">
      <p class="mr-eyebrow">Mont Haus International Realty · Monthly market report</p>
      <h1 class="mr-title" id="mr-title">Colorado markets, <?= $e($boot['label']) ?></h1>
    </div>
    <div class="mr-topright">
      <?php if (count($boot['months']) > 1): ?>
      <label class="mr-month">Month
        <select id="mr-month">
          <?php foreach ($boot['months'] as $mm): ?>
          <option value="<?= $e($mm['m']) ?>"<?= $mm['m'] === $boot['month'] ? ' selected' : '' ?>><?= $e($mm['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <?php if (!empty($boot['urls']['post'])): ?>
      <a class="mr-postlink" id="mr-postlink" href="<?= $e($boot['urls']['post']) ?>">Make a snapshot post ›</a>
      <?php endif; ?>
    </div>
  </div>
  <p class="mr-take mr-skel" id="mr-take">–</p>

  <div class="mr-bar">
    <nav class="mr-tabs" id="mr-tabs" role="tablist" aria-label="Market"></nav>
    <div class="mr-seg" id="mr-ptype" role="group" aria-label="Property type">
      <button type="button" data-p="Single family">Single family</button>
      <button type="button" data-p="Townhouse-condo">Townhouse-condo</button>
    </div>
  </div>

  <section class="mr-sec" id="mr-overview" hidden>
    <div class="mr-card">
      <div>
        <h2 class="mr-h3" id="mr-ov-heading"><?= $e($boot['label']) ?> by county</h2>
        <p class="mr-note">One row per county we cover, grouped by the MLS that serves it. Change is against <?= $e($boot['ly_label']) ?>. Select a row to open that market.</p>
      </div>
      <div class="mr-tablewrap">
        <table class="mr-table" id="mr-ov-table">
          <thead><tr>
            <th class="l">County</th>
            <th class="l">MLS</th>
            <th>Closed sales</th>
            <th>vs <?= $e($boot['ly_short']) ?></th>
            <th>Median price</th>
            <th>vs <?= $e($boot['ly_short']) ?></th>
            <th>Days on market</th>
            <th>% of list</th>
            <th>Months supply</th>
            <th>For sale</th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <div class="mr-grid2" id="mr-ov-metros"></div>

    <div class="mr-card">
      <h2 class="mr-h3">Sources</h2>
      <div class="mr-credits" id="mr-ov-credits"></div>
    </div>
  </section>

  <section class="mr-sec" id="mr-market" hidden>
    <div class="mr-mkhead">
      <div>
        <p class="mr-eyebrow" id="mr-mk-mls">–</p>
        <h2 class="mr-h2" id="mr-mk-name">–</h2>
        <p class="mr-note" id="mr-mk-areas">–</p>
      </div>
      <?php if (!empty($boot['urls']['post'])): ?>
      <a class="mr-btn" id="mr-mk-post" href="<?= $e($boot['urls']['post']) ?>">Snapshot post for this market ›</a>
      <?php endif; ?>
    </div>
    <div class="mr-sec" id="mr-mk-extra"></div>
    <div class="mr-sec" id="mr-mk-counties"></div>
    <div class="mr-card" id="mr-mk-towns-card" hidden>
      <div>
        <h3 class="mr-h3">Town by town, <?= $e($boot['label']) ?></h3>
        <p class="mr-note">From the Aspen Glenwood MLS town reports. Monthly figures in small towns swing on a handful of sales; the year-to-date columns are steadier.</p>
      </div>
      <div class="mr-tablewrap">
        <table class="mr-table" id="mr-mk-towns">
          <thead><tr>
            <th class="l">Town</th>
            <th>Closed</th>
            <th>vs <?= $e($boot['ly_short']) ?></th>
            <th>Median</th>
            <th>vs <?= $e($boot['ly_short']) ?></th>
            <th>Closed YTD</th>
            <th>Median YTD</th>
            <th>YTD vs <?= $e($boot['ly_year']) ?></th>
            <th>Days on market</th>
            <th>Months supply</th>
            <th>For sale</th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
    <div class="mr-card">
      <h3 class="mr-h3">Source</h3>
      <div class="mr-credits" id="mr-mk-credit"></div>
    </div>
  </section>
  <p class="mr-note mr-asof">Figures as the boards published them<?= $boot['as_of'] !== '' ? ', current as of ' . $e($boot['as_of']) : '' ?>. Monthly trend values are as CAR first published them each month; the tiles use CAR's current figures, which include late-reported sales.</p>
</div>
<script>window.MH_MARKET = <?= json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="/assets/js/market-report.js?v=<?= $v('assets/js/market-report.js') ?>"></script>
