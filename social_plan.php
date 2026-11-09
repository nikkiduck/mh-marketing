<?php
/**
 * social_plan.php — Weekly Plans (This Week's Plan until 2026-11-03; the
 * Social Media Plan, Nikki, 2026-11-02): the plan's sample week, Monday to
 * Saturday, with the week's suggested pick for each piece and a button that
 * opens the right template with it preselected. Since 2026-11-03 each piece
 * can be SAVED to the week (inc/plan_store.php): the builder's Save to the
 * week keeps its form, the row then shows what was saved with Edit,
 * Download and Reset, the market slot has a Done tick, the week can be
 * marked planned and the next one opened, and the strip at the top shows
 * this week and the four ahead with how many of their eight pieces are
 * ready. Since 2026-11-09 the Content column is a dropdown: a day's piece
 * can be another of the plan's kinds for this week, or Other with typed
 * words (plan_week_slots()); the suggestions follow the choice. The picks step through rotations by the ISO week
 * (plan_week(): markets through the Market Snapshot's places, brokers
 * through the approved roster, locations through the offices and areas,
 * hooks through the plan's six), so the same week always suggests the same
 * thing and next week moves on by one; ?week=YYYY-Www looks ahead or back.
 * The listing suggestions are the feed's newest new listing, the highest
 * priced live one and the latest closing. The plan's words and notes sit
 * under the table. Admins only; the tools are in inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
$ctx = plan_gate($conn);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$week  = plan_week((string)($_GET['week'] ?? ''));
$idx   = $week['index'];
$def   = plan_definition();
$agents = plan_agents($conn);
$withPhoto = array_values(array_filter($agents, fn($a) => mk_photo_file($a['headshot_url']) !== ''));
$locs = plan_locations();
$locPool = array_values(array_filter($locs, fn($l) => in_array($l['kind'], ['office', 'area'], true)));
$angles = ['office', 'team', 'local'];
$cards = market_card_defs();
$listings = plan_listings($conn);
$groups = plan_listing_groups($listings);
$season = plan_seasons()[$week['season']];
$lwords = fn(?array $l) => $l ? (($l['address'] !== '' ? $l['address'] : 'Address withheld') . ', ' . $l['city'] . ($l['price_text'] !== '' ? ' · ' . $l['price_text'] : '') . ($l['agent'] !== '' ? ' · ' . $l['agent'] : '')) : 'Nothing in the feed to suggest';
$plan  = plan_week_load($week['key']);
$slots = plan_week_slots($plan);   // the week's own content choices (2026-11-09)
$ready = plan_week_ready($plan);
$strip = plan_week_strip($week);
$csrf  = (string)$_SESSION['csrf_token'];
$wq    = '&week=' . rawurlencode($week['key']) . '&slot=';   // appended to a builder link with the slot index
$lq = fn(?array $l, string $v, string $size) => $l ? 'template_property.php?' . http_build_query(['listing' => $l['id'], 'variant' => $v, 'size' => $size]) : '';

// The week's slots, each with its suggestion: [piece words, the pick's words, [[button words, href], …]].
// Since 2026-11-09 a slot's content can be changed for the week (plan_week_slots(); the Content dropdown), so the
// suggestion is built per slot by kind; the nth slot of a kind in the week steps its rotation on by n (two broker
// slots suggest two brokers, two branding slots the season's first and second tagline).
$pickOf = function (array $keys, int $n) use ($groups): ?array {
    $pool = [];
    foreach ($keys as $g) foreach ($groups[$g] ?? [] as $l) if (!isset($pool[$l['id']])) $pool[$l['id']] = $l;
    return array_values($pool)[$n] ?? null;
};
$suggest = function (array $slot, int $n) use ($idx, $cards, $pickOf, $lwords, $lq, $season, $week, $withPhoto, $agents, $locPool, $angles, $e): array {
    switch ($slot['type']) {
        case 'property':
            $v = (string)($slot['variant'] ?? 'featured');
            if ($v === 'new') { $l = $pickOf(['New Listings', 'Listings'], $n); return [$lwords($l), $l ? [['Post', $lq($l, 'new', 'post')], ['Story', $lq($l, 'new', 'story')]] : []]; }   // the story size starts with the set ticked
            if ($v === 'sold') {
                $l = $pickOf(['Just Sold'], $n); $r = $l ? null : $pickOf(['Rentals'], $n);
                $words = $l ? 'Just Sold: ' . $lwords($l) : ($r ? 'Rental: ' . $lwords($r) : 'No closing in the last ' . PLAN_CLOSED_DAYS . ' days and no rental in the feed');
                return [$words, $l ? [['Post', $lq($l, 'sold', 'post')], ['Story', $lq($l, 'sold', 'story')]] : ($r ? [['Post', $lq($r, 'rental', 'post')], ['Story', $lq($r, 'rental', 'story')]] : [])];
            }
            $l = $pickOf(['Listings', 'New Listings'], $n); return [$lwords($l), $l ? [['Post', $lq($l, 'featured', 'post')], ['Story', $lq($l, 'featured', 'story')]] : []];
        case 'market':
            $mKey = plan_rotate(array_keys($cards), $idx + $n); $mNext = plan_rotate(array_keys($cards), $idx + $n + 1);
            return [($cards[$mKey]['title'] ?? '') . ($n === 0 ? ' (next week: ' . ($cards[$mNext]['title'] ?? '') . ')' : ''), [['Story', 'market_snapshot.php?market=' . rawurlencode((string)$mKey)]]];
        case 'branding':
            $t = $season['taglines'][$n] ?? $season['taglines'][0];
            return [$season['name'] . ': "' . str_replace("\n", ' ', $t) . '"', [['Story', 'template_branding.php?season=' . rawurlencode($week['season']) . '&size=story']]];
        case 'location':
            $loc = plan_rotate($locPool, $idx + $n);
            $angle = $loc ? ($loc['kind'] === 'office' ? plan_rotate($angles, intdiv($idx + $n, max(1, count($locPool)))) : plan_rotate(['team', 'local'], intdiv($idx + $n, max(1, count($locPool))))) : 'office';
            return [$loc ? (($loc['kind'] === 'office' ? $loc['label'] . ' office' : $loc['label']) . ' · ' . ['office' => 'the office', 'team' => 'team in place', 'local' => 'local angle'][$angle]) : '',
                    $loc ? [['Story', 'template_location.php?' . http_build_query(['loc' => $loc['key'], 'angle' => $angle, 'size' => 'story'])]] : []];
        case 'broker':
            $broker = plan_rotate($withPhoto ?: array_values($agents), $idx + $n);
            $hooks = plan_hooks(); $hookIdx = count($hooks) ? ((($idx + $n) % count($hooks)) + count($hooks)) % count($hooks) : 0;
            $hook = $broker ? plan_fill($hooks[$hookIdx], ['name' => $broker['name'], 'first' => $broker['first'], 'market' => $broker['market']]) : '';
            return [$broker ? $broker['name'] . ' · ' . $broker['market'] . ' · "' . $hook . '"' : 'No approved broker on the roster',
                    $broker ? [['Story', 'template_broker.php?' . http_build_query(['agent' => $broker['id'], 'hook' => $hookIdx, 'size' => 'story'])], ['Post', 'template_broker.php?' . http_build_query(['agent' => $broker['id'], 'hook' => $hookIdx, 'size' => 'post'])]] : []];
    }
    return ['Made outside the plan tools; tick Done when it is up.', []];   // other
};
$rows = []; $seen = [];
foreach ($slots as $i => $slot) {
    $n = $seen[$slot['type']] ?? 0; $seen[$slot['type']] = $n + 1;
    [$pick, $buttons] = $suggest($slot, $n);
    $rows[$i] = [$slot['day'], $slot['words'], $pick, $buttons];
}
$slotOpts = plan_slot_options();

admin_tool_header([
    'title'   => 'Weekly Plans',
    'hint'    => 'The Social Media Plan: eight pieces a week (3 property, 1 market stats, 1 location, 1 broker, 2 branding). Open a day\'s piece, make it or change the suggestion, and save it to the week; mark the week planned and go on to the next.',
    'actions' => '<a class="btn btn-outline btn-sm" href="social_media.php"><i class="ti ti-brand-instagram"></i> Social Media</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'active'  => 'social',
    'css'     => '
    .pl-strip { display:flex; gap:8px; flex-wrap:wrap; margin:0 0 18px; }
    .pl-strip a { display:flex; flex-direction:column; gap:2px; min-width:118px; padding:9px 13px; border:1px solid #dfe3e8; border-radius:12px; background:#fff; text-decoration:none; color:#1f242c; }
    .pl-strip a:hover { border-color:#4a5566; }
    .pl-strip a.cur { border-color:#4a5566; box-shadow:0 0 0 2px rgba(74,85,102,.18); }
    .pl-strip b { font-size:14px; font-weight:600; }
    .pl-strip small { font-size:12px; color:#6b7a8f; }
    .pl-strip small.done { color:#2f7a4f; font-weight:600; }
    .pl-week { display:flex; align-items:center; gap:14px; margin:0 0 14px; flex-wrap:wrap; }
    .pl-week h2 { font-family:"Cormorant Garamond",Georgia,serif; font-weight:500; font-size:26px; margin:0; color:#1f242c; }
    .pl-week a { font-size:13px; color:#4a5569; text-decoration:none; border:1px solid #dfe3e8; border-radius:999px; padding:5px 12px; background:#fff; }
    .pl-week a:hover { border-color:#4a5566; }
    .pl-week .pl-this { color:#6b7a8f; font-size:13px; }
    .pl-flash { background:#e9f3ec; border:1px solid #bfe0c9; color:#1f5a35; border-radius:10px; padding:9px 14px; font-size:13.5px; margin:0 0 14px; }
    .pl-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #dfe3e8; border-radius:14px; overflow:hidden; }
    .pl-table th, .pl-table td { text-align:left; padding:12px 14px; border-top:1px solid #dfe3e8; vertical-align:top; font-size:14px; }
    .pl-table th { font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:#6b7a8f; font-weight:600; border-top:0; background:#eceff3; }
    .pl-table td.day { font-weight:600; color:#1f242c; white-space:nowrap; width:100px; }
    .pl-table td.piece { color:#434b57; white-space:nowrap; width:230px; }
    .pl-kind select { font:inherit; font-size:13.5px; color:#434b57; background:#fff; border:1px solid #dfe3e8; border-radius:8px; padding:5px 8px; max-width:100%; cursor:pointer; }
    .pl-kind select:hover, .pl-kind select:focus { border-color:#4a5566; outline:0; }
    .pl-kind .pl-other { display:flex; gap:6px; margin-top:6px; }
    .pl-kind .pl-other[hidden] { display:none; }
    .pl-kind .pl-other input { font:inherit; font-size:13.5px; flex:1; min-width:0; border:1px solid #dfe3e8; border-radius:8px; padding:5px 8px; }
    .pl-kind .pl-other button { font:inherit; font-size:13px; font-weight:600; color:#4a5569; background:#fff; border:1px solid #dfe3e8; border-radius:999px; padding:5px 11px; cursor:pointer; }
    .pl-kind .pl-other button:hover { border-color:#4a5566; }
    .pl-table td.pick { color:#1f242c; }
    .pl-table td.pick .sug { color:#6b7a8f; }
    .pl-table td.pick .sug b { color:#434b57; font-weight:600; }
    .pl-table td.pick .cap { display:block; margin-top:4px; color:#6b7a8f; font-size:13px; white-space:pre-line; }
    .pl-table td.st { white-space:nowrap; width:120px; }
    .pl-st { display:inline-block; font-size:11.5px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; border-radius:999px; padding:3px 9px; }
    .pl-st.saved { background:#e9f3ec; color:#1f5a35; }
    .pl-st.sug { background:#eceff3; color:#6b7a8f; }
    .pl-table td.st small { display:block; margin-top:4px; color:#6b7a8f; font-size:12px; }
    .pl-table td.go { white-space:nowrap; text-align:right; }
    .pl-table td.go a, .pl-table td.go button { display:inline-block; font-size:13px; font-weight:600; color:#fff; background:#4a5566; border:0; border-radius:999px; padding:7px 14px; text-decoration:none; margin-left:6px; cursor:pointer; font-family:inherit; }
    .pl-table td.go a:hover, .pl-table td.go button:hover { background:#353e4b; }
    .pl-table td.go a.soft, .pl-table td.go button.soft { background:#fff; color:#4a5569; border:1px solid #dfe3e8; }
    .pl-table td.go a.soft:hover, .pl-table td.go button.soft:hover { border-color:#4a5566; background:#fff; }
    .pl-table td.go form { display:inline; }
    .pl-table td.go label.tick { font-size:13px; color:#434b57; margin-left:6px; cursor:pointer; }
    .pl-table tr.alt td { border-top-style:dashed; }
    .pl-foot { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin:16px 0 0; font-size:14px; color:#434b57; }
    .pl-foot form { display:inline; }
    .pl-foot button, .pl-foot a.go { font-size:13px; font-weight:600; color:#fff; background:#4a5566; border:0; border-radius:999px; padding:8px 16px; cursor:pointer; font-family:inherit; text-decoration:none; }
    .pl-foot button:hover, .pl-foot a.go:hover { background:#353e4b; }
    .pl-foot button.soft { background:#fff; color:#4a5569; border:1px solid #dfe3e8; }
    .pl-foot .planned { color:#1f5a35; font-weight:600; }
    .pl-types { display:grid; grid-template-columns:1fr; gap:14px; margin-top:30px; }
    @media (min-width:760px) { .pl-types { grid-template-columns:1fr 1fr; } }
    .pl-type { background:#fff; border:1px solid #dfe3e8; border-radius:14px; padding:16px 18px; }
    .pl-type h3 { font-family:"Cormorant Garamond",Georgia,serif; font-weight:500; font-size:22px; margin:0 0 2px; color:#1f242c; }
    .pl-type .freq { font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:#55637a; margin:0 0 8px; }
    .pl-type p, .pl-type li { font-size:14px; color:#434b57; margin:0 0 6px; line-height:1.5; }
    .pl-type ul { margin:0; padding-left:18px; }
    .pl-type a.tool { font-size:13px; font-weight:600; color:#4a5569; text-decoration:none; }
    .pl-type a.tool:hover { text-decoration:underline; }
    .pl-rot { margin-top:26px; font-size:13px; color:#6b7a8f; line-height:1.6; }
    .pl-rot b { color:#434b57; font-weight:600; }
    @media (max-width:760px) { .pl-table td.piece, .pl-table td.day, .pl-table td.st { white-space:normal; width:auto; } .pl-table td.go { text-align:left; } .pl-table td.go a, .pl-table td.go button { margin:4px 6px 0 0; } }
    ',
]);
// The Content dropdown (2026-11-09): a plan kind saves at once (a confirm when the day has a saved piece or a Done
// tick, which the change drops); Other opens the words box and saves on its button.
?>
<script>
function plKind(sel) {
  var other = sel.form.querySelector('.pl-other');
  if (sel.value === 'other') { other.hidden = false; other.querySelector('input').focus(); return; }
  other.hidden = true;
  if (sel.value === sel.dataset.was) return;
  if (sel.dataset.saved === '1' && !confirm('Changing the content drops what is saved for this day. Go on?')) { sel.value = sel.dataset.was; if (sel.value === 'other') other.hidden = false; return; }
  sel.form.submit();
}
</script>
<?php
$sv = (string)($_GET['saved'] ?? ''); $cl = (string)($_GET['cleared'] ?? '');
$flashSlot = $sv !== '' ? (int)$sv : ($cl !== '' ? (int)$cl : -1);
$dayWords = fn(int $i) => isset($slots[$i]) ? $slots[$i]['day'] . '\'s ' . $slots[$i]['words'] : '';
?>
    <div class="pl-strip">
      <?php foreach ($strip as $w): $n = plan_week_ready($w['plan']); ?>
      <a href="social_plan.php?week=<?= $e($w['key']) ?>" class="<?= $w['key'] === $week['key'] ? 'cur' : '' ?>">
        <b><?= $w['is_this'] ? 'This week' : ($w['ahead'] === 1 ? 'Next week' : ($w['ahead'] < 0 ? 'Past' : 'In ' . $w['ahead'] . ' weeks')) ?></b>
        <small><?= $e($w['short']) ?></small>
        <small class="<?= $w['plan']['status'] === 'planned' ? 'done' : '' ?>"><?= $w['plan']['status'] === 'planned' ? 'Planned ✓' : ($n ? $n . ' of ' . count($slots) . ' ready' : 'Nothing saved') ?></small>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="pl-week">
      <a href="social_plan.php?week=<?= $e($week['prev']) ?>">‹ Previous week</a>
      <h2>Week of <?= $e($week['label']) ?></h2>
      <a href="social_plan.php?week=<?= $e($week['next']) ?>">Next week ›</a>
      <?php if ($week['key'] !== $week['this']): ?><a href="social_plan.php">This week</a><?php else: ?><span class="pl-this">This week · <?= $e($season['name']) ?> season</span><?php endif; ?>
    </div>
    <?php if ($flashSlot >= 0 && isset($slots[$flashSlot])): ?>
    <p class="pl-flash"><?= $sv !== '' ? 'Saved to the week: ' . $e($dayWords($flashSlot)) . '.' : 'Back to the suggestion: ' . $e($dayWords($flashSlot)) . '.' ?></p>
    <?php endif; ?>
    <table class="pl-table">
      <thead><tr><th>Day</th><th>Content</th><th>Suggestion, or what is saved</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php $prevDay = ''; foreach ($rows as $i => [$day, $piece, $pick, $buttons]):
        $slot = $slots[$i]; $item = $plan['items'][$i] ?? null; $tool = plan_slot_tool($slot);
        $saved = $item && !empty($item['form']); $done = $item && !empty($item['done']); ?>
        <tr<?= $day === $prevDay ? ' class="alt"' : '' ?>>
          <td class="day"><?= $day === $prevDay ? '' : $e($day) ?></td>
          <td class="piece">
            <form method="post" action="plan_save.php" class="pl-kind"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="plan_week" value="<?= $e($week['key']) ?>"><input type="hidden" name="plan_slot" value="<?= $i ?>"><input type="hidden" name="plan_action" value="kind">
              <?php $cur = plan_slot_option_key($slot); $usual = plan_slot_option_key(plan_slots()[$i]); ?>
              <select name="slot_kind" data-was="<?= $e($cur) ?>" data-saved="<?= $saved || $done ? 1 : 0 ?>" onchange="plKind(this)" aria-label="<?= $e($day) ?> content">
                <?php foreach ($slotOpts as $k => $o): ?><option value="<?= $e($k) ?>"<?= $k === $cur ? ' selected' : '' ?>><?= $e($o['words']) ?><?= $k === $usual ? ' (usual)' : '' ?></option><?php endforeach; ?>
              </select>
              <span class="pl-other"<?= $cur === 'other' ? '' : ' hidden' ?>><input type="text" name="slot_words" maxlength="80" placeholder="What is it?" value="<?= $e($cur === 'other' ? $slot['words'] : '') ?>" aria-label="Other content"><button type="submit" class="soft">Save</button></span>
            </form>
          </td>
          <td class="pick">
            <?php if ($saved): ?>
              <?= $e($item['summary'] ?? '') ?>
              <?php if (trim((string)($item['caption'] ?? '')) !== ''): ?><span class="cap"><?= $e(mb_strimwidth(trim((string)$item['caption']), 0, 160, '…')) ?></span><?php endif; ?>
              <span class="sug"><br><b>Suggestion was:</b> <?= $e($pick) ?></span>
            <?php else: ?>
              <?= $e($pick) ?>
            <?php endif; ?>
          </td>
          <td class="st">
            <?php if ($saved || $done): ?><span class="pl-st saved"><?= $done && !$saved ? 'Done' : 'Saved' ?></span><small><?= $e(plan_when((string)($item['saved_at'] ?? ''))) ?></small>
            <?php else: ?><span class="pl-st sug">Suggested</span><?php endif; ?>
          </td>
          <td class="go">
            <?php if ($tool === ''): ?>
              <?php foreach ($buttons as [$w, $href]): ?><a href="<?= $e($href) ?>"><?= $e($w) ?> ›</a><?php endforeach; ?>
              <form method="post" action="plan_save.php"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="plan_week" value="<?= $e($week['key']) ?>"><input type="hidden" name="plan_slot" value="<?= $i ?>"><input type="hidden" name="plan_action" value="done"><input type="hidden" name="done" value="<?= $done ? 0 : 1 ?>">
                <label class="tick"><input type="checkbox" onchange="this.form.submit()"<?= $done ? ' checked' : '' ?>> Done</label></form>
            <?php elseif ($saved): ?>
              <a href="<?= $e($tool . '?week=' . rawurlencode($week['key']) . '&slot=' . $i) ?>">Edit ›</a>
              <form method="post" action="plan_export.php"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="plan_week" value="<?= $e($week['key']) ?>"><input type="hidden" name="plan_slot" value="<?= $i ?>"><button type="submit" class="soft"><?= (int)($item['frames'] ?? 1) > 1 ? 'Download ZIP' : 'Download PNG' ?></button></form>
              <form method="post" action="plan_save.php" onsubmit="return confirm('Drop what is saved for this day and go back to the suggestion?')"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="plan_week" value="<?= $e($week['key']) ?>"><input type="hidden" name="plan_slot" value="<?= $i ?>"><input type="hidden" name="plan_action" value="clear"><button type="submit" class="soft">Reset</button></form>
            <?php else: ?>
              <?php foreach ($buttons as [$w, $href]): ?><a href="<?= $e($href . $wq . $i) ?>"><?= $e($w) ?> ›</a><?php endforeach; ?>
              <?php if (!$buttons): ?><a href="<?= $e($tool . '?week=' . rawurlencode($week['key']) . '&slot=' . $i) ?>" class="soft">Choose ›</a><?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php $prevDay = $day; endforeach; ?>
      </tbody>
    </table>
    <div class="pl-foot">
      <span><?= $ready ?> of <?= count($slots) ?> pieces ready<?= $plan['updated_at'] !== '' ? ' · last saved ' . $e(plan_when($plan['updated_at'])) : '' ?></span>
      <?php if ($plan['status'] === 'planned'): ?>
        <span class="planned">This week is planned ✓</span>
        <form method="post" action="plan_save.php"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="plan_week" value="<?= $e($week['key']) ?>"><input type="hidden" name="plan_action" value="status"><input type="hidden" name="status" value="open"><button type="submit" class="soft">Reopen</button></form>
        <a class="go" href="social_plan.php?week=<?= $e($week['next']) ?>">Plan next week ›</a>
      <?php else: ?>
        <form method="post" action="plan_save.php"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="plan_week" value="<?= $e($week['key']) ?>"><input type="hidden" name="plan_action" value="status"><input type="hidden" name="status" value="planned"><button type="submit"<?= $ready < count($slots) ? ' onclick="return confirm(\'' . (count($slots) - $ready) . ' of the ' . count($slots) . ' pieces are not saved yet. Mark the week planned anyway?\')"' : '' ?>>Mark this week planned</button></form>
      <?php endif; ?>
    </div>

    <div class="pl-types">
      <?php foreach ($def as $k => $d): ?>
      <div class="pl-type">
        <h3><?= $e($d['name']) ?></h3>
        <p class="freq"><?= $e($d['per_week']) ?></p>
        <p><?= $e($d['what']) ?></p>
        <?php if ($d['notes']): ?><ul><?php foreach ($d['notes'] as $n): ?><li><?= $e($n) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <p style="margin-top:10px"><a class="tool" href="<?= $e($d['tool']) ?>">Open the <?= $e($k === 'market' ? 'Market Snapshot' : $d['name']) ?> tool ›</a></p>
      </div>
      <?php endforeach; ?>
    </div>

    <p class="pl-rot">
      <b>How the suggestions rotate.</b> Markets step through the Market Snapshot's <?= count($cards) ?> places in order (<?= $e(implode(', ', array_slice(array_column($cards, 'title'), 0, 6))) ?>, …),
      brokers through the <?= count($withPhoto) ?> approved agents with a headshot by name, locations through the <?= count($locPool) ?> offices and areas, hooks through the plan's six,
      and the branding season follows the calendar. Each is one week on from the week before, so a week always suggests the same thing whenever you look at it. A saved piece keeps what you chose; Reset returns the day to its suggestion.
    </p>
<?php admin_tool_footer();
