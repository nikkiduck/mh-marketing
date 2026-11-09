<?php
/**
 * inc/plan_store.php — the Weekly Plans store (Nikki, 2026-11-03: go into
 * each day's piece, make the post or set, change the suggestion, save and
 * come back to the week with it in place; reopen a saved piece with its
 * options loaded; save the week and plan the next, up to four ahead).
 *
 * One JSON file per ISO week under CREATIVES_DIR/social/plans/<YYYY-Www>.json
 * (the folder the social tools already write; www-data owns it with admin).
 * No table: one super admin, eight pieces a week, a few weeks ahead. Moving
 * it into MySQL later is a small job because every read and write goes
 * through the functions here.
 *
 *   { "week": "2026-W46", "status": "open" | "planned", "updated_at": "…",
 *     "items": { "<slot>": { "kind": "property" | …, "form": { the builder's
 *       posted fields }, "caption": "…", "summary": "one line for the table",
 *       "frames": 5, "saved_at": "…" }
 *              | { "kind": "market", "done": true, "saved_at": "…" } } }
 *
 * The slots are the plan's sample week in order (plan_slots(): Monday
 * property, Tuesday market + branding, Wednesday property, Thursday
 * location, Friday property + branding, Saturday broker). Since 2026-11-09
 * (Nikki: "I want to be able to change up the weekly plan") a week may give
 * a slot another content kind: "slots": { "<slot>": { "type", "variant",
 * "words" } }, chosen from plan_slot_options() in the week table's Content
 * dropdown (plan_week_slots() applies them; Other = words typed by Nikki
 * for a piece made outside the tools). An Other slot is not a plan
 * builder's, so it only carries a Done tick. The market slot is a LIST OF
 * SLIDES since 2026-11-09 (Nikki: "today I'm going to do 4 story slides,
 * 2 for Aspen and 2 for Snowmass Village; next week Basalt and
 * Carbondale"): { "kind": "market", "slides": [ { "place": a
 * market_card_defs() key, "ptype": SF | TC, "done": bool }, … ],
 * "saved_at" }, each slide a Market Snapshot card with its own Story link
 * and Done tick; the suggestion is two places a week, both property types
 * each (plan_market_pairs()), and the slot is ready when every slide is
 * ticked. An older { "done": true } market item reads as the suggestion,
 * all ticked.
 *
 * The saved "form" is the builder's own POST (plan_form_from_post(): the
 * token, the plan fields and the preview-only fields dropped), so
 * plan_post_from_request() renders it unchanged, plan_export.php downloads
 * it from the week page, and inc/plan_body.php's script restores it into
 * the controls (SAVED). Loaded by inc/plan.php; needs nothing itself.
 */

const PLAN_WEEKS_AHEAD = 4;   // this week and the next four on the week page

/** The week's slots in order: index => day, type (a plan_definition() key), variant (property), words. */
function plan_slots(): array {
    $out = [];
    foreach (plan_sample_week() as $day => $pieces) foreach ($pieces as $p) $out[] = ['day' => $day] + $p;
    return $out;
}

/**
 * The content kinds a slot may hold (the week table's Content dropdown,
 * 2026-11-09): key => type, variant (property), words. The key of a sample
 * week slot is plan_slot_option_key(); 'other' is a piece made outside the
 * tools (a reel, a repost), with Nikki's own words and a Done tick.
 */
function plan_slot_options(): array {
    return [
        'property:new'      => ['type' => 'property', 'variant' => 'new',      'words' => 'Property · New Listing'],
        'property:featured' => ['type' => 'property', 'variant' => 'featured', 'words' => 'Property · Featured Listing'],
        'property:sold'     => ['type' => 'property', 'variant' => 'sold',     'words' => 'Property · Just Sold or Rental'],
        'market'            => ['type' => 'market',   'words' => 'Market Stats'],
        'branding'          => ['type' => 'branding', 'words' => 'Branding'],
        'location'          => ['type' => 'location', 'words' => 'Location Feature'],
        'broker'            => ['type' => 'broker',   'words' => 'Broker Feature'],
        'other'             => ['type' => 'other',    'words' => 'Other'],
    ];
}

/** The option key of a slot: its type, plus the variant for a property slot. */
function plan_slot_option_key(array $slot): string {
    return $slot['type'] . ($slot['type'] === 'property' ? ':' . (string)($slot['variant'] ?? 'featured') : '');
}

/**
 * The week's slots as the week has them: the sample week, with the week's
 * own content choices applied ('custom' => true on a changed slot; an
 * Other slot's words are Nikki's). What every page and save works from.
 */
function plan_week_slots(array $plan): array {
    $slots = plan_slots();
    foreach ((array)($plan['slots'] ?? []) as $i => $c) {
        if (!isset($slots[$i])) continue;
        $slots[$i] = ['day' => $slots[$i]['day'], 'type' => $c['type'], 'words' => $c['words'], 'custom' => true] + ($c['type'] === 'property' ? ['variant' => $c['variant']] : []);
    }
    return $slots;
}

/** The slot's tool page, or '' for the market slot (the Market Snapshot's own page) and an Other slot. */
function plan_slot_tool(array $slot): string {
    return ['property' => 'template_property.php', 'broker' => 'template_broker.php', 'location' => 'template_location.php', 'branding' => 'template_branding.php'][$slot['type']] ?? '';
}

/**
 * Market Stats slides (2026-11-09). The places two a week, in this order,
 * starting from PLAN_MARKET_PAIR_ANCHOR (the week Nikki named: Aspen +
 * Snowmass Village, then Basalt + Carbondale); every place gets both
 * property types, so four slides a week. The counties Pitkin and Garfield
 * are not in the rotation (their towns are); add them to a week by hand.
 */
const PLAN_MARKET_PAIR_ANCHOR = '2026-W46';
function plan_market_pairs(): array {
    return [['town-aspen', 'town-snowmass'], ['town-basalt', 'town-carbondale'], ['town-glenwood', 'town-missouri-heights'], ['town-woody-creek', 'town-old-snowmass'],
            ['eagle', 'san-miguel'], ['summit', 'routt'], ['montrose', 'ouray'], ['la-plata', 'denver-metro']];
}

/** The suggested slides for a week (its plan_week() index); $n = the nth market slot of the week, which steps the pair on. */
function plan_market_slides_suggest(int $weekIndex, int $n = 0): array {
    $pair = plan_rotate(plan_market_pairs(), $weekIndex - plan_week(PLAN_MARKET_PAIR_ANCHOR)['index'] + $n);
    $defs = market_card_defs(); $out = [];
    foreach ((array)$pair as $place) {
        if (!isset($defs[$place])) continue;
        foreach (array_keys(market_card_ptypes()) as $pt) $out[] = ['place' => $place, 'ptype' => $pt, 'done' => false];
    }
    return $out;
}

/** The market slot's slides as the week has them: the saved list, else the suggestion (an older Done tick = the suggestion, all ticked). */
function plan_market_slides(?array $item, int $weekIndex, int $n = 0): array {
    if ($item && isset($item['slides']) && is_array($item['slides'])) return array_values($item['slides']);
    $s = plan_market_slides_suggest($weekIndex, $n);
    if ($item && !empty($item['done'])) foreach ($s as &$x) $x['done'] = true;
    return $s;
}

/** The property types short, for the slide list (Nikki, 2026-11-09: "to save space"): SF, T+C. */
function plan_market_ptype_short(string $pt): string { return ['SF' => 'SF', 'TC' => 'T+C'][$pt] ?? $pt; }

/** "Aspen · SF" */
function plan_market_slide_words(array $s): string {
    $d = market_card_defs()[(string)$s['place']] ?? null;
    return ($d ? $d['title'] : (string)$s['place']) . ' · ' . plan_market_ptype_short((string)$s['ptype']);
}

/** The Market Snapshot tool with the slide's place and property type picked. */
function plan_market_slide_link(array $s): string {
    return 'market_snapshot.php?' . http_build_query(['market' => $s['place'], 'ptype' => $s['ptype']]);
}

/** Keep the market slot's slides (only known places and types; the order as given). */
function plan_market_slides_save(string $key, int $slot, array $slides): bool {
    $defs = market_card_defs(); $pts = market_card_ptypes(); $clean = [];
    foreach ($slides as $s) {
        if (!is_array($s) || !isset($defs[(string)($s['place'] ?? '')]) || !isset($pts[(string)($s['ptype'] ?? '')])) continue;
        $clean[] = ['place' => (string)$s['place'], 'ptype' => (string)$s['ptype'], 'done' => !empty($s['done'])];
    }
    return plan_item_save($key, $slot, ['kind' => 'market', 'slides' => $clean, 'saved_at' => date('c')]);
}

/** A market item is ready when it has slides and every one is ticked (or it is an older Done tick). */
function plan_market_item_ready(array $it): bool {
    if (!isset($it['slides'])) return !empty($it['done']);
    if (!is_array($it['slides']) || !$it['slides']) return false;
    foreach ($it['slides'] as $s) if (empty($s['done'])) return false;
    return true;
}

function plan_store_dir(): string {
    $dir = defined('PLAN_STORE_DIR') ? rtrim(PLAN_STORE_DIR, '/') : rtrim(CREATIVES_DIR, '/') . '/social/plans';   // PLAN_STORE_DIR: a test harness's folder
    if (!is_dir($dir)) { @mkdir($dir, 02775, true); if (function_exists('social_open_dirs')) social_open_dirs($dir); }
    return $dir;
}

function plan_store_key_ok(string $key): bool { return (bool)preg_match('/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/', $key); }

/** The week's record (an empty one for a week never saved). Item keys are slot indexes. */
function plan_week_load(string $key): array {
    $empty = ['week' => $key, 'status' => 'open', 'updated_at' => '', 'items' => [], 'slots' => []];
    if (!plan_store_key_ok($key)) return $empty;
    $f = plan_store_dir() . '/' . $key . '.json';
    if (!is_file($f)) return $empty;
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j)) return $empty;
    $items = [];
    foreach ((array)($j['items'] ?? []) as $k => $it) if (is_array($it)) $items[(int)$k] = $it;
    $slots = []; $opts = plan_slot_options();
    foreach ((array)($j['slots'] ?? []) as $k => $c) {
        if (!is_array($c)) continue;
        $ok = (string)($c['type'] ?? '') . ((($c['type'] ?? '') === 'property') ? ':' . (string)($c['variant'] ?? '') : '');
        if (!isset($opts[$ok])) continue;
        $slots[(int)$k] = $opts[$ok];
        if ($ok === 'other' && trim((string)($c['words'] ?? '')) !== '') $slots[(int)$k]['words'] = trim((string)$c['words']);
    }
    return ['week' => $key, 'status' => ($j['status'] ?? '') === 'planned' ? 'planned' : 'open', 'updated_at' => (string)($j['updated_at'] ?? ''), 'items' => $items, 'slots' => $slots];
}

function plan_week_store(array $plan): bool {
    if (!plan_store_key_ok((string)$plan['week'])) return false;
    $plan['updated_at'] = date('c');
    ksort($plan['items']);
    if (empty($plan['slots'])) unset($plan['slots']); else ksort($plan['slots']);
    $f = plan_store_dir() . '/' . $plan['week'] . '.json';
    $ok = @file_put_contents($f, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    if ($ok) @chmod($f, 0664);
    return $ok;
}

function plan_item_save(string $key, int $slot, array $item): bool {
    $p = plan_week_load($key); $p['items'][$slot] = $item; return plan_week_store($p);
}

function plan_item_clear(string $key, int $slot): bool {
    $p = plan_week_load($key); unset($p['items'][$slot]); return plan_week_store($p);
}

/**
 * Give a slot another content kind for this week (the Content dropdown):
 * $option is a plan_slot_options() key, $words the typed words for Other.
 * Back to the sample week's own kind = no record. A piece saved in the slot
 * under a different kind is dropped (the builder's form belongs to its
 * tool); a Done tick is dropped too, since it was for another piece.
 */
function plan_slot_set_kind(string $key, int $slot, string $option, string $words = ''): bool {
    $opts = plan_slot_options(); $base = plan_slots()[$slot] ?? null;
    if (!$base || !isset($opts[$option])) return false;
    $p = plan_week_load($key);
    $before = plan_week_slots($p)[$slot];
    if ($option === plan_slot_option_key($base)) unset($p['slots'][$slot]);
    else {
        $c = ['type' => $opts[$option]['type'], 'words' => $opts[$option]['words']];
        if ($c['type'] === 'property') $c['variant'] = $opts[$option]['variant'];
        if ($option === 'other') $c['words'] = mb_substr(trim(preg_replace('/\s+/', ' ', $words)), 0, 80) ?: 'Other';
        $p['slots'][$slot] = $c;
    }
    $after = plan_week_slots($p)[$slot];
    if (plan_slot_option_key($after) !== plan_slot_option_key($before) && isset($p['items'][$slot])) unset($p['items'][$slot]);
    return plan_week_store($p);
}

function plan_week_set_status(string $key, string $status): bool {
    $p = plan_week_load($key); $p['status'] = $status === 'planned' ? 'planned' : 'open'; return plan_week_store($p);
}

/** How many of the week's slots are ready: saved pieces, the market slot when every slide is ticked, an Other slot when ticked. */
function plan_week_ready(array $plan): int {
    $n = 0;
    foreach ($plan['items'] as $it) if (!empty($it['form']) || (isset($it['slides']) ? plan_market_item_ready($it) : !empty($it['done']))) $n++;
    return $n;
}

/**
 * The week page's strip: this week and the next PLAN_WEEKS_AHEAD (plus the
 * week shown, when it is outside them), each with its record and a short
 * label ("Nov 3").
 */
function plan_week_strip(array $week): array {
    $tz = new DateTimeZone('America/Denver');
    $cur = plan_week($week['this']);
    $out = [];
    for ($i = 0; $i <= PLAN_WEEKS_AHEAD; $i++) {
        $mon = $cur['monday']->modify('+' . (7 * $i) . ' days');
        $key = $mon->format('o-\WW');
        $out[$key] = ['key' => $key, 'short' => $mon->format('M j'), 'plan' => plan_week_load($key), 'is_this' => $i === 0, 'ahead' => $i];
    }
    if (!isset($out[$week['key']])) {
        $out = [$week['key'] => ['key' => $week['key'], 'short' => $week['monday']->format('M j'), 'plan' => plan_week_load($week['key']), 'is_this' => false, 'ahead' => (int)round(($week['monday']->getTimestamp() - $cur['monday']->getTimestamp()) / 604800)]] + $out;
        ksort($out);
    }
    return $out;
}

/** The builder's POST as the form to keep: every field but the token, the plan fields and the preview-only ones. */
function plan_form_from_post(array $in): array {
    $drop = ['csrf_token', 'plan_week', 'plan_slot', 'plan_action', '_pill', '_flogo', 'frame', 'preview', 'caption'];
    $out = [];
    foreach ($in as $k => $v) {
        if (in_array($k, $drop, true)) continue;
        $out[$k] = is_array($v) ? array_values(array_map('strval', $v)) : (string)$v;
    }
    return $out;
}

/**
 * The builder presets a saved form gives the page's PHP (the subject
 * dropdowns and pills drawn checked); the script restores the rest.
 */
function plan_preset_from_form(string $kind, array $form): array {
    $p = ['size' => (string)($form['size'] ?? '')];
    switch ($kind) {
        case 'property': $p += ['listing' => (string)($form['listing'] ?? ''), 'variant' => (string)($form['variant'] ?? 'featured')]; break;
        case 'broker':   $p += ['agent' => (int)($form['agent'] ?? 0), 'hook' => (string)($form['hook'] ?? '0'), 'hook2' => (string)($form['hook2'] ?? 'sans'),
                       'arrange' => (string)($form['arrange'] ?? 'stack'), 'hook1' => (string)($form['hook1'] ?? 'italic'), 'badge_tone' => (string)($form['badge_tone'] ?? 'white')]; break;
        case 'location': $p += ['loc' => (string)($form['loc'] ?? 'office:aspen'), 'angle' => (string)($form['angle'] ?? 'office')]; break;
        case 'branding': $p += ['season' => (string)($form['season'] ?? 'always')]; break;
    }
    return $p;
}

/**
 * The week and slot a builder page was opened for (?week=&slot=), with the
 * saved item when there is one; null when the page was opened on its own.
 *   week (plan_week()), slot, def (plan_slots()[slot]), plan, item (or null)
 */
function plan_item_context(array $get, string $kind): ?array {
    $key = (string)($get['week'] ?? '');
    if (!plan_store_key_ok($key) || !isset($get['slot'])) return null;
    $slot = (int)$get['slot'];
    $plan = plan_week_load($key);
    $def = plan_week_slots($plan)[$slot] ?? null;   // the week's own content choice for the slot (2026-11-09)
    if (!$def || $def['type'] !== $kind) return null;
    $item = $plan['items'][$slot] ?? null;
    return ['week' => plan_week($key), 'slot' => $slot, 'def' => $def, 'plan' => $plan, 'item' => ($item && !empty($item['form'])) ? $item : null];
}

/** One line for the week table: what the saved piece is. */
function plan_item_summary(mysqli $conn, string $kind, array $form): string {
    $e = fn($s) => trim((string)$s);
    $size = ['post' => 'Post', 'square' => 'Square', 'story' => 'Story'][(string)($form['size'] ?? '')] ?? 'Post';
    $words = '';
    if ($kind === 'property') {
        $l = plan_listing($conn, (string)($form['listing'] ?? ''));
        $v = plan_property_variants()[(string)($form['variant'] ?? '')] ?? '';
        $words = $l ? (($l['address'] !== '' ? $l['address'] : 'Address withheld') . ', ' . $l['city'] . ($l['price_text'] !== '' ? ' · ' . $l['price_text'] : '')) : 'A listing no longer in the feed';
        if ($v !== '') $words = $v . ': ' . $words;
        if (!empty($form['set'])) $size .= ' set of ' . count((array)($form['frames'] ?? [])) . (!empty($form['endcard']) ? ' + end card' : '');
    } elseif ($kind === 'broker') {
        $a = plan_agents($conn)[(int)($form['agent'] ?? 0)] ?? null;
        $words = ($a ? $a['name'] : 'A broker no longer on the roster') . ($e($form['market'] ?? '') !== '' ? ' · ' . $e($form['market']) : '');
        $h = $e(str_replace("\n", ' ', (string)($form['headline'] ?? '')));
        if ($h !== '') $words .= ' · "' . $h . '"';
    } elseif ($kind === 'location') {
        $loc = plan_location((string)($form['loc'] ?? ''));
        $angle = ['office' => 'the office', 'team' => 'team in place', 'local' => 'local angle'][(string)($form['angle'] ?? '')] ?? '';
        $words = ($loc ? ($loc['kind'] === 'office' ? $loc['label'] . ' office' : $loc['label']) : 'A location') . ($angle !== '' ? ' · ' . $angle : '');
    } elseif ($kind === 'branding') {
        $s = plan_seasons()[(string)($form['season'] ?? '')] ?? null;
        $h = $e(str_replace("\n", ' ', (string)($form['headline'] ?? '')));
        $words = ($s ? $s['name'] : 'Branding') . ($h !== '' ? ': "' . $h . '"' : '');
    }
    return $words . ' · ' . $size;
}

/** "Nov 3, 2:14 pm" for a saved_at stamp, in Mountain time. */
function plan_when(string $iso): string {
    if ($iso === '') return '';
    try { return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('America/Denver'))->format('M j, g:i a'); }
    catch (Throwable $t) { return ''; }
}

/** The header bits a builder page shows when opened for a week's slot: the hint's first sentence and the Back button. */
function plan_builder_header(?array $pk_plan): array {
    if (!$pk_plan) return ['hint' => '', 'back' => ''];
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $w = $pk_plan['week'];
    $hint = 'Weekly Plans · week of ' . $w['label'] . ' · ' . $pk_plan['def']['day'] . ', ' . $pk_plan['def']['words']
          . ($pk_plan['item'] ? ' (saved ' . plan_when((string)$pk_plan['item']['saved_at']) . ', loaded below)' : '')
          . '. Save to the week puts it in place and takes you back. ';
    $back = '<a class="btn btn-outline btn-sm" href="social_plan.php?week=' . $e($w['key']) . '"><i class="ti ti-arrow-left"></i> Back to the week</a>';
    return ['hint' => $hint, 'back' => $back];
}
