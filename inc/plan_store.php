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
 * location, Friday property + branding, Saturday broker); a slot's kind is
 * fixed, what goes in it is not. The market slot is the Market Snapshot
 * tool's (not a plan builder), so it only carries a Done tick.
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

/** The slot's tool page, or '' for the market slot (the Market Snapshot's own page). */
function plan_slot_tool(array $slot): string {
    return ['property' => 'template_property.php', 'broker' => 'template_broker.php', 'location' => 'template_location.php', 'branding' => 'template_branding.php'][$slot['type']] ?? '';
}

function plan_store_dir(): string {
    $dir = defined('PLAN_STORE_DIR') ? rtrim(PLAN_STORE_DIR, '/') : rtrim(CREATIVES_DIR, '/') . '/social/plans';   // PLAN_STORE_DIR: a test harness's folder
    if (!is_dir($dir)) { @mkdir($dir, 02775, true); if (function_exists('social_open_dirs')) social_open_dirs($dir); }
    return $dir;
}

function plan_store_key_ok(string $key): bool { return (bool)preg_match('/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/', $key); }

/** The week's record (an empty one for a week never saved). Item keys are slot indexes. */
function plan_week_load(string $key): array {
    $empty = ['week' => $key, 'status' => 'open', 'updated_at' => '', 'items' => []];
    if (!plan_store_key_ok($key)) return $empty;
    $f = plan_store_dir() . '/' . $key . '.json';
    if (!is_file($f)) return $empty;
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j)) return $empty;
    $items = [];
    foreach ((array)($j['items'] ?? []) as $k => $it) if (is_array($it)) $items[(int)$k] = $it;
    return ['week' => $key, 'status' => ($j['status'] ?? '') === 'planned' ? 'planned' : 'open', 'updated_at' => (string)($j['updated_at'] ?? ''), 'items' => $items];
}

function plan_week_store(array $plan): bool {
    if (!plan_store_key_ok((string)$plan['week'])) return false;
    $plan['updated_at'] = date('c');
    ksort($plan['items']);
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

function plan_week_set_status(string $key, string $status): bool {
    $p = plan_week_load($key); $p['status'] = $status === 'planned' ? 'planned' : 'open'; return plan_week_store($p);
}

/** How many of the week's slots are ready: saved pieces plus the market slot when ticked done. */
function plan_week_ready(array $plan): int {
    $n = 0;
    foreach ($plan['items'] as $it) if (!empty($it['form']) || !empty($it['done'])) $n++;
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
        case 'broker':   $p += ['agent' => (int)($form['agent'] ?? 0), 'hook' => (string)($form['hook'] ?? '0'), 'hook2' => (string)($form['hook2'] ?? 'italic')]; break;
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
    $def = plan_slots()[$slot] ?? null;
    if (!$def || $def['type'] !== $kind) return null;
    $plan = plan_week_load($key);
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
