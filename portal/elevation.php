<?php
/**
 * portal/elevation.php — Elevation Planner (My Tools), built into the portal
 * 2026-10-17 (Nikki: both tools in one page, one saved plan holds both, agent
 * only, Jost throughout with Cormorant on the headings, a printable PDF with
 * no headshot or contact block: it is a personal tool).
 *
 * Three tabs: Planner (part one), Lead Sources (part two) and Saved Plans.
 * The page is a skeleton; assets/js/elevation-page.js fills it from the
 * working plan embedded below (window.MH_ELEVATION) and saves a moment after
 * every change to portal/elevation_save.php. "Save this plan" takes a named
 * snapshot of both parts; Saved Plans opens, prints, renames or deletes them;
 * "Print or PDF" opens portal/elevation_print.php. All arithmetic is in
 * assets/js/elevation.js; the data layer is inc/elevation.php.
 *
 * Before sql/elevation_plans_v1.sql has run ($ready false) the page keeps
 * entries in the browser and says so. An admin preview can look but not save.
 */
require_once __DIR__ . '/../inc/elevation.php';

$ctx     = portal_context($conn);
$ready   = elevation_ready($conn);
$owner   = elevation_owner($ctx);
$working = $ready ? elevation_working($conn, $owner) : null;
$saved   = $ready ? elevation_saved($conn, $owner) : [];

$boot = [
    'csrf'        => (string)($_SESSION['csrf_token'] ?? ''),
    'ready'       => $ready,
    'preview'     => (bool)$ctx['preview'],
    'working'     => $working && $working['plan'] !== null && $working['leads'] !== null ? ['plan' => $working['plan'], 'leads' => $working['leads']] : null,
    'saved'       => array_values(array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'created_at' => $r['created_at'], 'date' => elevation_date_words($r['created_at']), 'plan' => $r['plan'], 'leads' => $r['leads']], $saved)),
    'saveUrl'     => portal_url($ctx, '/portal/elevation_save.php'),
    'printUrl'    => portal_url($ctx, '/portal/elevation_print.php'),
    'defaultName' => elevation_default_name(),
];
$v = fn(string $f) => (int)@filemtime(__DIR__ . '/../' . $f);

portal_header($ctx, 'Elevation Planner', 'elevation');
?>
<link rel="stylesheet" href="/assets/css/elevation.css?v=<?= $v('assets/css/elevation.css') ?>">
<div class="ev">
  <h1 class="pt-h1">Elevation Planner</h1>
  <p class="pt-lead">Your business plan for the year: the take-home you are aiming for, what it takes in closings and conversations, and the lead sources that will start them. Save a plan whenever you like and come back to compare.</p>
  <?php if (!$ready): ?>
    <div class="pt-note">Saving plans to your account is being switched on. Until then your entries stay in this browser, on this device.</div>
  <?php elseif ($ctx['preview']): ?>
    <div class="pt-note">Preview: you can look through <?= ph($ctx['acct']['agent_name']) ?>'s plan, but nothing you change here is saved.</div>
  <?php endif; ?>

  <div class="ev-tabbar">
    <div class="ev-tabs" role="tablist" aria-label="Elevation Planner">
      <button type="button" role="tab" class="ev-tab on" id="ev-tabbtn-planner" data-tab="planner" aria-selected="true" aria-controls="ev-tab-planner">Planner</button>
      <button type="button" role="tab" class="ev-tab" id="ev-tabbtn-leads" data-tab="leads" aria-selected="false" aria-controls="ev-tab-leads">Lead Sources</button>
      <button type="button" role="tab" class="ev-tab" id="ev-tabbtn-saved" data-tab="saved" aria-selected="false" aria-controls="ev-tab-saved">Saved Plans <span class="ev-count" id="ev-saved-count"><?= count($saved) ?></span></button>
    </div>
    <span class="ev-savestate" id="ev-savestate" role="status" aria-live="polite"></span>
  </div>

  <!-- ── Part one: the Planner ─────────────────────────────────────────── -->
  <section class="ev-panel" id="ev-tab-planner" role="tabpanel" aria-labelledby="ev-tabbtn-planner">
    <p class="ev-intro">Set your take-home goal and see the closings, signed agreements and conversations it takes to reach it this year. <span class="ev-muted">The starting numbers are examples. Replace them with your own.</span></p>

    <div class="ev-glance" aria-live="polite" aria-label="Year at a glance">
      <div class="ev-stat"><span class="ev-label">Gross commission needed</span><span class="ev-value" id="p-o-gci">-</span><span class="ev-sub" id="p-o-gci-sub"></span></div>
      <div class="ev-stat"><span class="ev-label">Closings needed</span><span class="ev-value" id="p-o-sides">-</span><span class="ev-sub">Sides, rounded up</span></div>
      <div class="ev-stat"><span class="ev-label">Sales volume</span><span class="ev-value" id="p-o-vol">-</span><span class="ev-sub" id="p-o-vol-sub"></span></div>
      <div class="ev-stat"><span class="ev-label">Conversations a week</span><span class="ev-value" id="p-o-weekly">-</span><span class="ev-sub" id="p-o-weekly-sub"></span></div>
    </div>

    <div class="ev-work">
      <section class="ev-block" aria-labelledby="p-h-numbers">
        <h2 class="ev-h2" id="p-h-numbers">Your numbers</h2>
        <div class="ev-fields">
          <label class="ev-field wide"><span class="ev-label">Take-home goal, after expenses and taxes</span>
            <span class="ev-control"><span>$</span><input id="p-goal" data-key="goal" type="number" inputmode="decimal" min="0" step="5000"></span></label>
          <label class="ev-field"><span class="ev-label">Business expenses a year</span>
            <span class="ev-control"><span>$</span><input id="p-expenses" data-key="expenses" type="number" inputmode="decimal" min="0" step="1000"></span></label>
          <label class="ev-field"><span class="ev-label">Tax reserve</span>
            <span class="ev-control"><input id="p-tax" data-key="tax" type="number" inputmode="decimal" min="0" max="99" step="1"><span>%</span></span></label>
          <label class="ev-field"><span class="ev-label">Your share after brokerage split</span>
            <span class="ev-control"><input id="p-split" data-key="split" type="number" inputmode="decimal" min="1" max="100" step="1"><span>%</span></span></label>
          <label class="ev-field"><span class="ev-label">Working weeks a year</span>
            <span class="ev-control"><input id="p-weeks" data-key="weeks" type="number" inputmode="decimal" min="1" max="52" step="1"></span></label>
          <label class="ev-field"><span class="ev-label">Average sale price</span>
            <span class="ev-control"><span>$</span><input id="p-price" data-key="price" type="number" inputmode="decimal" min="0" step="100000"></span></label>
          <label class="ev-field"><span class="ev-label">Commission per side, before split</span>
            <span class="ev-control"><input id="p-rate" data-key="rate" type="number" inputmode="decimal" min="0" max="10" step="0.1"><span>%</span></span></label>
        </div>
        <p class="ev-label ev-group-title">Conversion rates</p>
        <div class="ev-fields">
          <label class="ev-field wide"><span class="ev-label">Conversations that become appointments</span>
            <span class="ev-control"><input id="p-apptRate" data-key="apptRate" type="number" inputmode="decimal" min="1" max="100" step="1"><span>%</span></span></label>
          <label class="ev-field"><span class="ev-label">Appointments that become signed agreements</span>
            <span class="ev-control"><input id="p-agreeRate" data-key="agreeRate" type="number" inputmode="decimal" min="1" max="100" step="1"><span>%</span></span></label>
          <label class="ev-field"><span class="ev-label">Signed agreements that close</span>
            <span class="ev-control"><input id="p-closeRate" data-key="closeRate" type="number" inputmode="decimal" min="1" max="100" step="1"><span>%</span></span></label>
        </div>
      </section>

      <section class="ev-block" aria-labelledby="p-h-activity">
        <h2 class="ev-h2" id="p-h-activity">The activity behind it</h2>
        <div class="ev-funnel" id="p-funnel"></div>
        <div class="ev-callout" id="p-callout"></div>
      </section>
    </div>

    <section class="ev-block" aria-labelledby="p-h-quarters">
      <h2 class="ev-h2" id="p-h-quarters">Quarter by quarter</h2>
      <p class="ev-note">Resort markets close unevenly. Set the share of your year each quarter should carry.</p>
      <div class="ev-table-scroll">
        <table class="ev-table">
          <thead><tr><th>Quarter</th><th>Share of year</th><th>Closings</th><th>Commission</th><th>Volume</th></tr></thead>
          <tbody id="p-quarters"></tbody>
        </table>
      </div>
      <p class="ev-warn" id="p-q-warn" role="status"></p>
    </section>

    <section class="ev-block" aria-labelledby="p-h-focus">
      <h2 class="ev-h2" id="p-h-focus">Where you will work</h2>
      <div class="ev-chips" id="p-markets" role="group" aria-label="Focus markets"></div>
      <div class="ev-prompts">
        <label class="ev-field"><span class="ev-label">Where your next listings come from</span>
          <textarea class="ev-ta" id="p-n1" data-note="n1" placeholder="Past clients, referral partners, builders, estate attorneys, wealth managers"></textarea></label>
        <label class="ev-field"><span class="ev-label">Who you will see every month</span>
          <textarea class="ev-ta" id="p-n2" data-note="n2" placeholder="Name the owners, second-home buyers and local professionals you will meet in person"></textarea></label>
        <label class="ev-field"><span class="ev-label">What you will stop doing</span>
          <textarea class="ev-ta" id="p-n3" data-note="n3" placeholder="Low-price showings, unpaid marketing, chasing leads that never qualify"></textarea></label>
      </div>
    </section>

    <section class="ev-block ev-summary-block" aria-labelledby="p-h-summary">
      <h2 class="ev-h2" id="p-h-summary">Plan summary</h2>
      <div class="ev-summary" id="p-summary"></div>
      <div class="ev-actions">
        <button class="pt-btn main" type="button" data-act="snapshot">Save this plan</button>
        <button class="pt-btn quiet" type="button" data-act="print">Print or PDF</button>
        <button class="pt-btn quiet" type="button" data-act="copy" data-part="plan">Copy summary</button>
        <button class="pt-btn quiet" type="button" data-act="reset-plan">Reset to examples</button>
        <span class="ev-status" id="p-status" role="status"></span>
      </div>
      <p class="ev-note">Saving keeps both parts together, the Planner and Lead Sources, under one name.</p>
    </section>
  </section>

  <!-- ── Part two: Lead Sources ────────────────────────────────────────── -->
  <section class="ev-panel" id="ev-tab-leads" role="tabpanel" aria-labelledby="ev-tabbtn-leads" hidden>
    <p class="ev-intro">List every way you start conversations with owners, buyers and referral partners. Enter a typical month for each one and see the conversations, hours and dollars it adds up to over a year. <span class="ev-muted">This covers lead generation only, which is outreach that starts a one-to-one conversation. Listing marketing, advertising and brand spending belong in your marketing budget. The starting rows are examples. Replace them with your own.</span></p>

    <section class="ev-block" aria-labelledby="l-h-goal">
      <h2 class="ev-h2" id="l-h-goal">Your goal and budget</h2>
      <div class="ev-fields three">
        <label class="ev-field"><span class="ev-label">Conversations you need this year</span>
          <span class="ev-control"><input id="l-need" data-lkey="need" type="number" inputmode="decimal" min="0" step="10"></span></label>
        <label class="ev-field"><span class="ev-label">Lead generation budget this year</span>
          <span class="ev-control"><span>$</span><input id="l-budget" data-lkey="budget" type="number" inputmode="decimal" min="0" step="500"></span></label>
        <label class="ev-field"><span class="ev-label">Working weeks a year</span>
          <span class="ev-control"><input id="l-weeks" data-lkey="weeks" type="number" inputmode="decimal" min="1" max="52" step="1"></span></label>
      </div>
      <button class="ev-btn ev-plan-link" id="l-use-plan" type="button" hidden></button>
    </section>

    <div class="ev-glance" aria-live="polite" aria-label="Year at a glance">
      <div class="ev-stat"><span class="ev-label">Conversations a year</span><span class="ev-value" id="l-o-conv">-</span><span class="ev-sub" id="l-o-conv-sub"></span></div>
      <div class="ev-stat"><span class="ev-label">Hours a week</span><span class="ev-value" id="l-o-hrs">-</span><span class="ev-sub" id="l-o-hrs-sub"></span></div>
      <div class="ev-stat"><span class="ev-label">Spend a year</span><span class="ev-value" id="l-o-spend">-</span><span class="ev-sub" id="l-o-spend-sub"></span></div>
      <div class="ev-stat"><span class="ev-label">Cost per conversation</span><span class="ev-value" id="l-o-each">-</span><span class="ev-sub">Software included</span></div>
    </div>

    <section class="ev-pace" aria-labelledby="l-h-pace">
      <div class="ev-pace-head"><h2 class="ev-h2" id="l-h-pace">Pace toward your goal</h2><span class="ev-pace-pct" id="l-pace-pct" aria-live="polite">-</span></div>
      <div class="ev-track" role="img" aria-label="Share of needed conversations covered by your planned activity"><div class="ev-fill" id="l-pace-fill" style="width:0%"></div></div>
      <p class="ev-callout" id="l-pace-text"></p>
    </section>

    <section class="ev-block" aria-labelledby="l-h-compare">
      <h2 class="ev-h2" id="l-h-compare">Which sources earn their time</h2>
      <div class="ev-table-scroll">
        <table class="ev-table compare">
          <thead><tr><th>Source</th><th>Conversations a year</th><th>Hours a year</th><th>Spend a year</th><th>Cost each</th><th>Hours each</th></tr></thead>
          <tbody id="l-compare-body"></tbody>
          <tfoot id="l-compare-foot"></tfoot>
        </table>
      </div>
      <p class="ev-note">Cost and hours per conversation for each source leave out software, which supports every source. The total row includes it.</p>
    </section>

    <div class="ev-block" id="l-channels"></div>

    <section class="ev-block" aria-labelledby="l-h-add">
      <h2 class="ev-h2" id="l-h-add">Add another lead source</h2>
      <div class="ev-add-channel">
        <label class="ev-field"><span class="ev-label">Name of the source</span>
          <span class="ev-control"><input id="l-new-channel" type="text" maxlength="60" placeholder="Client appreciation events, builder partnerships, a podcast"></span></label>
        <button class="pt-btn main" id="l-add-channel" type="button">Add source</button>
      </div>
    </section>

    <section class="ev-block" aria-labelledby="l-h-tools">
      <h2 class="ev-h2" id="l-h-tools">Software and subscriptions</h2>
      <p class="ev-note">Enter the monthly cost of tools that support lead generation, such as a CRM, a dialer or an owner data list. Count printing and postage inside the activity that uses them, so nothing is counted twice.</p>
      <div class="ev-rows" id="l-tools"></div>
      <div class="ev-ch-actions"><button class="ev-btn" id="l-add-tool" type="button">Add software or a subscription</button></div>
    </section>

    <section class="ev-block ev-summary-block" aria-labelledby="l-h-summary">
      <h2 class="ev-h2" id="l-h-summary">Plan summary</h2>
      <div class="ev-summary" id="l-summary"></div>
      <div class="ev-actions">
        <button class="pt-btn main" type="button" data-act="snapshot">Save this plan</button>
        <button class="pt-btn quiet" type="button" data-act="print">Print or PDF</button>
        <button class="pt-btn quiet" type="button" data-act="copy" data-part="leads">Copy summary</button>
        <button class="pt-btn quiet" type="button" data-act="reset-leads">Reset to examples</button>
        <span class="ev-status" id="l-status" role="status"></span>
      </div>
      <p class="ev-note">Saving keeps both parts together, the Planner and Lead Sources, under one name.</p>
    </section>
  </section>

  <!-- ── Saved plans ───────────────────────────────────────────────────── -->
  <section class="ev-panel" id="ev-tab-saved" role="tabpanel" aria-labelledby="ev-tabbtn-saved" hidden>
    <p class="ev-intro">Every plan you have saved, newest first. Open one to work on it again, print it or download it as a PDF.</p>
    <div class="ev-saved-list" id="ev-saved-list"></div>
    <p class="ev-status" id="s-status" role="status"></p>
  </section>

  <dialog class="ev-dialog" id="ev-dialog" aria-labelledby="ev-dialog-h">
    <form method="dialog">
      <h2 class="ev-h2" id="ev-dialog-h">Save this plan</h2>
      <p class="ev-note" id="ev-dialog-p">Both parts, the Planner and Lead Sources, are saved together under this name.</p>
      <label class="ev-field"><span class="ev-label">Name</span>
        <span class="ev-control"><input id="ev-dialog-name" type="text" maxlength="120" autocomplete="off"></span></label>
      <div class="ev-actions">
        <button class="pt-btn main" type="submit" value="ok" id="ev-dialog-ok">Save</button>
        <button class="pt-btn quiet" type="submit" value="cancel">Cancel</button>
      </div>
    </form>
  </dialog>
</div>
<script>window.MH_ELEVATION = <?= json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="/assets/js/elevation.js?v=<?= $v('assets/js/elevation.js') ?>"></script>
<script src="/assets/js/elevation-page.js?v=<?= $v('assets/js/elevation-page.js') ?>"></script>
<?php portal_footer();
