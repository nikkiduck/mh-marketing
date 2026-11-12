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
 *     "items": { "<slot>": { "kind": "property" | …, "saved_at": "…",
 *       "post":  { "form": { the builder's posted fields }, "caption": "…",
 *                  "summary": "one line for the table", "frames": 5, "saved_at": "…" },
 *       "story": { the same, for the story size } }
 *              | { "kind": "market", "slides": […], "saved_at": "…" }
 *              | { "kind": "other", "done": true, "saved_at": "…" } } }
 *
 * A builder slot holds TWO pieces since 2026-11-09 (Nikki: "when I have
 * both a story and post scheduled, one save cannot overwrite the other"):
 * the post (post or square size) and the story, each saved by the size of
 * the form (plan_piece_bucket()), each with its own Edit, Download and
 * Reset on the week page. An item saved before, with its form at the top
 * level, reads as the piece of its size (plan_week_load()).
 *
 * The slots are the plan's sample week (plan_slots(): Monday property,
 * Tuesday market + branding, Wednesday property, Thursday location, Friday
 * property + branding, Saturday broker), keyed by a STABLE id: the six
 * non-branding pieces keep their place in the sample week (0, 1, 3, 4, 5,
 * 7) and a Branding story is PLAN_BRANDING_BASE + the day's index (Monday
 * 100 … Sunday 106), because since 2026-11-09 (Nikki: "let me choose which
 * days") the week's Branding days are its own: "branding_days": ["Tuesday",
 * "Friday"] (the default when the key is absent), ticked in the Branding
 * Story bar above the table; a week loaded with items under the old ids 2
 * and 6 reads them as 101 and 104. Since 2026-11-09
 * (Nikki: "I want to be able to change up the weekly plan") a week may give
 * a slot another content kind: "slots": { "<slot>": { "type", "variant",
 * "words" } }, chosen from plan_slot_options() in the week table's Content
 * dropdown (plan_week_slots() applies them; Other = words typed by Nikki
 * for a piece made outside the tools). Since 2026-11-12 a week may also
 * show a piece under another DAY (Nikki: a post planned for Monday went up
 * on Thursday): "days": { "<slot>": "Thursday" } for the six regular slots
 * (the id stays, so everything saved stays with it); a Branding story's day
 * is its id, so moving one moves its record (plan_slot_set_day()). An Other slot is not a plan
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

const PLAN_BRANDING_BASE = 100;   // a Branding story's slot id is this + the day's index in PLAN_DAYS
const PLAN_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const PLAN_BRANDING_DEFAULT_DAYS = ['Tuesday', 'Friday'];   // the sample week's

/** The sample week's slots keyed by stable id (see the header): id => day, type (a plan_definition() key), variant (property), words. */
function plan_slots(): array {
    $out = []; $i = 0;
    foreach (plan_sample_week() as $day => $pieces) foreach ($pieces as $p) {
        $out[$p['type'] === 'branding' ? plan_branding_slot_id($day) : $i] = ['day' => $day] + $p;
        $i++;
    }
    return $out;
}

/** The slot id of the Branding story on a day (Monday 100 … Sunday 106); -1 for a word that is not a day. */
function plan_branding_slot_id(string $day): int { $k = array_search($day, PLAN_DAYS, true); return $k === false ? -1 : PLAN_BRANDING_BASE + $k; }

/** The slot a week starts from for an id: a sample week slot, or the Branding story of the id's day; null for an id that is neither. */
function plan_slot_base(int $id): ?array {
    $base = plan_slots()[$id] ?? null;
    if ($base) return $base;
    $d = PLAN_DAYS[$id - PLAN_BRANDING_BASE] ?? null;
    return $d ? ['day' => $d, 'type' => 'branding', 'words' => 'Branding'] : null;
}

/** The week's Branding days, in week order; the sample week's two when the week has not said. */
function plan_branding_days(array $plan): array {
    $days = array_key_exists('branding_days', $plan) && is_array($plan['branding_days']) ? $plan['branding_days'] : PLAN_BRANDING_DEFAULT_DAYS;
    return array_values(array_filter(PLAN_DAYS, fn($d) => in_array($d, $days, true)));
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
    $slots = array_filter(plan_slots(), fn($s) => $s['type'] !== 'branding');   // the six pieces every week has
    foreach (plan_branding_days($plan) as $d) $slots[plan_branding_slot_id($d)] = ['day' => $d, 'type' => 'branding', 'words' => 'Branding'];
    foreach ((array)($plan['slots'] ?? []) as $i => $c) {
        if (!isset($slots[$i])) continue;
        $slots[$i] = ['day' => $slots[$i]['day'], 'type' => $c['type'], 'words' => $c['words'], 'custom' => true] + ($c['type'] === 'property' ? ['variant' => $c['variant']] : []);
    }
    // The week's own day for a piece (the Day picker, 2026-11-12): a regular slot keeps its id and shows under the day chosen.
    // (A Branding story's day is its id; moving one moves its record: plan_slot_set_day().)
    foreach ((array)($plan['days'] ?? []) as $i => $d) if (isset($slots[$i]) && $i < PLAN_BRANDING_BASE && in_array($d, PLAN_DAYS, true)) $slots[$i]['day'] = $d;
    // Week order: by day, a day's regular piece before its Branding story (ids under PLAN_BRANDING_BASE first)
    uksort($slots, fn($a, $b) => [array_search($slots[$a]['day'], PLAN_DAYS, true), $a >= PLAN_BRANDING_BASE ? 1 : 0, $a] <=> [array_search($slots[$b]['day'], PLAN_DAYS, true), $b >= PLAN_BRANDING_BASE ? 1 : 0, $b]);
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
    $empty = ['week' => $key, 'status' => 'open', 'updated_at' => '', 'items' => [], 'slots' => []];   // no branding_days key = the default days
    if (!plan_store_key_ok($key)) return $empty;
    $f = plan_store_dir() . '/' . $key . '.json';
    if (!is_file($f)) return $empty;
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j)) return $empty;
    $legacy = [2 => plan_branding_slot_id('Tuesday'), 6 => plan_branding_slot_id('Friday')];   // the Branding stories' ids before 2026-11-09
    $items = [];
    foreach ((array)($j['items'] ?? []) as $k => $it) {
        if (!is_array($it)) continue;
        if (!empty($it['form']) && is_array($it['form'])) {   // saved before the two pieces: the form at the top level is the piece of its size
            $piece = array_intersect_key($it, array_flip(['form', 'caption', 'summary', 'frames', 'saved_at']));
            $it = ['kind' => (string)($it['kind'] ?? ''), 'saved_at' => (string)($it['saved_at'] ?? ''), plan_piece_bucket($it['form']) => $piece];
        }
        $items[$legacy[(int)$k] ?? (int)$k] = $it;
    }
    $slots = []; $opts = plan_slot_options();
    foreach ((array)($j['slots'] ?? []) as $k => $c) {
        $k = $legacy[(int)$k] ?? (int)$k;
        if (!is_array($c)) continue;
        $ok = (string)($c['type'] ?? '') . ((($c['type'] ?? '') === 'property') ? ':' . (string)($c['variant'] ?? '') : '');
        if (!isset($opts[$ok])) continue;
        $slots[(int)$k] = $opts[$ok];
        if ($ok === 'other' && trim((string)($c['words'] ?? '')) !== '') $slots[(int)$k]['words'] = trim((string)$c['words']);
    }
    $out = ['week' => $key, 'status' => ($j['status'] ?? '') === 'planned' ? 'planned' : 'open', 'updated_at' => (string)($j['updated_at'] ?? ''), 'items' => $items, 'slots' => $slots];
    if (array_key_exists('branding_days', $j) && is_array($j['branding_days'])) $out['branding_days'] = array_values(array_intersect(PLAN_DAYS, array_map('strval', $j['branding_days'])));
    foreach ((array)($j['days'] ?? []) as $k => $d) if ((int)$k < PLAN_BRANDING_BASE && in_array($d, PLAN_DAYS, true)) $out['days'][(int)$k] = (string)$d;   // a piece moved to another day (2026-11-12)
    return $out;
}

function plan_week_store(array $plan): bool {
    if (!plan_store_key_ok((string)$plan['week'])) return false;
    $plan['updated_at'] = date('c');
    ksort($plan['items']);
    if (empty($plan['slots'])) unset($plan['slots']); else ksort($plan['slots']);
    if (empty($plan['days'])) unset($plan['days']); else ksort($plan['days']);
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

/** Which of a slot's two pieces a builder form is: 'story' at the story size, else 'post' (a post or a square). */
function plan_piece_bucket(array $form): string { return (string)($form['size'] ?? '') === 'story' ? 'story' : 'post'; }

/** The bucket a builder page or a week-page link means: ?piece=post|story, else by ?size= (the suggestion buttons carry the size). */
function plan_piece_from_get(array $get): string {
    $p = (string)($get['piece'] ?? '');
    return $p === 'story' || $p === 'post' ? $p : (((string)($get['size'] ?? '')) === 'story' ? 'story' : 'post');
}

/** A slot item's saved pieces: ['post' => piece, 'story' => piece], only the ones there (each has a form). */
function plan_item_pieces(?array $item): array {
    $out = [];
    foreach (['post', 'story'] as $b) if ($item && !empty($item[$b]['form'])) $out[$b] = $item[$b];
    return $out;
}

/** Keep one piece of a builder slot (the other piece, if any, stays). $piece: form, caption, summary, frames, saved_at. */
function plan_piece_save(string $key, int $slot, string $kind, string $bucket, array $piece): bool {
    $p = plan_week_load($key);
    $it = $p['items'][$slot] ?? [];
    if (($it['kind'] ?? '') !== $kind) $it = ['kind' => $kind];   // another kind's record (a Done tick, slides) makes way
    $it['kind'] = $kind; $it[$bucket] = $piece; $it['saved_at'] = (string)($piece['saved_at'] ?? date('c'));
    $p['items'][$slot] = $it;
    return plan_week_store($p);
}

/** Drop one piece of a builder slot; the item goes when nothing is left. */
function plan_piece_clear(string $key, int $slot, string $bucket): bool {
    $p = plan_week_load($key);
    if (!isset($p['items'][$slot])) return true;
    unset($p['items'][$slot][$bucket]);
    if (!plan_item_pieces($p['items'][$slot])) unset($p['items'][$slot]);
    return plan_week_store($p);
}

/**
 * Give a slot another content kind for this week (the Content dropdown):
 * $option is a plan_slot_options() key, $words the typed words for Other.
 * Back to the sample week's own kind = no record. A piece saved in the slot
 * under a different kind is dropped (the builder's form belongs to its
 * tool); a Done tick is dropped too, since it was for another piece.
 */
function plan_slot_set_kind(string $key, int $slot, string $option, string $words = ''): bool {
    $opts = plan_slot_options(); $base = plan_slot_base($slot);
    if (!$base || !isset($opts[$option])) return false;
    $p = plan_week_load($key);
    $before = plan_week_slots($p)[$slot] ?? null;
    if (!$before) return false;   // a Branding day the week has not ticked
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

/**
 * Move a piece to another day of the week (the Day picker, Nikki,
 * 2026-11-12: a post planned for Monday went up on Thursday). Nothing saved
 * is lost. A regular slot keeps its id and records the day in the week's
 * `days` (its usual day = no record). A Branding story's id IS its day, so
 * its record (the saved piece, a content choice) moves to the new day's id,
 * the week's Branding days change with it, and a Link in Bio cover made from
 * it follows; a day that already has a Branding story cannot take another.
 * Returns the slot's id after the move, or -1 when it cannot be done.
 */
function plan_slot_set_day(string $key, int $slot, string $day): int {
    if (!in_array($day, PLAN_DAYS, true)) return -1;
    $p = plan_week_load($key);
    $slots = plan_week_slots($p);
    if (!isset($slots[$slot])) return -1;
    if ($slots[$slot]['day'] === $day) return $slot;
    if ($slot < PLAN_BRANDING_BASE) {
        if ($day === plan_slot_base($slot)['day']) unset($p['days'][$slot]); else $p['days'][$slot] = $day;
        return plan_week_store($p) ? $slot : -1;
    }
    $to = plan_branding_slot_id($day);
    if ($to < 0 || isset($slots[$to])) return -1;
    $p['branding_days'] = array_values(array_diff(array_merge(plan_branding_days($p), [$day]), [PLAN_DAYS[$slot - PLAN_BRANDING_BASE]]));
    foreach (['items', 'slots'] as $part) if (isset($p[$part][$slot])) { $p[$part][$to] = $p[$part][$slot]; unset($p[$part][$slot]); }
    if (!plan_week_store($p)) return -1;
    if (function_exists('bio_update')) bio_update(function (array &$items) use ($key, $slot, $to) {
        foreach ($items as &$x) if (($x['source'] ?? '') === 'plan' && ($x['week'] ?? '') === $key && (int)($x['slot'] ?? -1) === $slot) $x['slot'] = $to;
    });
    return $to;
}

/**
 * The week's Branding days (the Branding Story bar, 2026-11-09). A day
 * unticked loses its Branding slot: a piece saved there, or a content
 * choice made for it, goes with it.
 */
function plan_week_set_branding_days(string $key, array $days): bool {
    $p = plan_week_load($key);
    $p['branding_days'] = array_values(array_intersect(PLAN_DAYS, array_map('strval', $days)));
    $keep = plan_week_slots($p);
    foreach (array_keys($p['items']) as $i) if ($i >= PLAN_BRANDING_BASE && !isset($keep[$i])) unset($p['items'][$i]);
    foreach (array_keys($p['slots']) as $i) if ($i >= PLAN_BRANDING_BASE && !isset($keep[$i])) unset($p['slots'][$i]);
    return plan_week_store($p);
}

function plan_week_set_status(string $key, string $status): bool {
    $p = plan_week_load($key); $p['status'] = $status === 'planned' ? 'planned' : 'open'; return plan_week_store($p);
}

/** How many of the week's slots are ready: a slot with a saved piece (post or story), the market slot when every slide is ticked, an Other slot when ticked. */
function plan_week_ready(array $plan): int {
    $n = 0;
    foreach ($plan['items'] as $it) if (plan_item_pieces($it) || (isset($it['slides']) ? plan_market_item_ready($it) : !empty($it['done']))) $n++;
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
 * saved piece when there is one; null when the page was opened on its own.
 *   week (plan_week()), slot, def (plan_week_slots()[slot]), plan, piece
 *   (post | story), item (that piece: form, caption, summary, frames,
 *   saved_at; or null)
 */
function plan_item_context(array $get, string $kind): ?array {
    $key = (string)($get['week'] ?? '');
    if (!plan_store_key_ok($key) || !isset($get['slot'])) return null;
    $slot = (int)$get['slot'];
    $plan = plan_week_load($key);
    $def = plan_week_slots($plan)[$slot] ?? null;   // the week's own content choice for the slot (2026-11-09)
    if (!$def || $def['type'] !== $kind) return null;
    $bucket = plan_piece_from_get($get);   // the post or the story piece (2026-11-09; the suggestion buttons carry ?size=, Edit carries ?piece=)
    $piece = plan_item_pieces($plan['items'][$slot] ?? null)[$bucket] ?? null;
    return ['week' => plan_week($key), 'slot' => $slot, 'def' => $def, 'plan' => $plan, 'piece' => $bucket, 'item' => $piece];
}

/** One line for the week table: what the saved piece is. */
function plan_item_summary(mysqli $conn, string $kind, array $form): string {
    $e = fn($s) => trim((string)$s);
    $size = ['post' => 'Post', 'square' => 'Square Post', 'story' => 'Story'][(string)($form['size'] ?? '')] ?? 'Post';
    $words = '';
    if ($kind === 'property') {
        $l = plan_listing($conn, (string)($form['listing'] ?? ''));
        // What the badge says (2026-11-12: the Post type pills went, the badge label is the piece's kind); with no badge, the slot's variant.
        $lab = (string)($form['label'] ?? '');
        $v = $lab === '__custom' ? trim((string)($form['label_custom'] ?? '')) : (($lab === '' || $lab === '__none') ? (plan_property_variants()[(string)($form['variant'] ?? '')] ?? '') : $lab);
        $words = $l ? (($l['address'] !== '' ? $l['address'] : 'Address withheld') . ', ' . $l['city'] . ($l['agent'] !== '' ? ' · ' . $l['agent'] : '')) : 'A listing no longer in the feed';   // the broker, not the price (Nikki, 2026-11-09)
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
          . ($pk_plan['item'] ? ' (the ' . $pk_plan['piece'] . ' saved ' . plan_when((string)$pk_plan['item']['saved_at']) . ', loaded below)' : '')
          . '. Save to the week puts it in place and takes you back. ';
    $back = '<a class="btn btn-outline btn-sm" href="social_plan.php?week=' . $e($w['key']) . '"><i class="ti ti-arrow-left"></i> Back to the week</a>';
    return ['hint' => $hint, 'back' => $back];
}
