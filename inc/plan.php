<?php
/**
 * inc/plan.php — the Social Media Plan templates on the admin side (Nikki,
 * 2026-11-02): a series of admin-only tools built on the Instagram template
 * foundation (inc/social.php, inc/social_render.php) that follow Nikki's
 * "Mont Haus Social Media Plan" (the docx of 2026-11-02; its words are in
 * plan_definition() and docs/HANDOFF-social-plan.md): eight pieces a week,
 * three property posts and stories, a market stats story, a location
 * feature, a broker feature and two branding stories.
 *
 *   social_plan.php          the plan: this week's six days, each with the
 *                            rotation's pick and a button to its tool
 *   template_property.php    Property post + SET (a multi-frame slideshow at
 *                            any size, a story or a carousel, downloaded as a
 *                            ZIP of PNGs), from EVERY Mont Haus listing in the
 *                            feed, not one agent's
 *   template_broker.php      Broker Feature: a hook with the broker's name
 *                            and market swapped in, their headshot or a
 *                            lifestyle shot, post or story
 *   template_location.php    Location Feature: an office, an area or a town;
 *                            the office or a view, the team based there, or
 *                            a local real estate angle
 *   template_branding.php    Branding: the seasonal taglines on a stock photo
 *   plan_render.php          POST → one frame as HTML, for the preview
 *   plan_export.php          POST → the PNG, or the story set as a ZIP
 *   plan_photos.php          GET  → the photos to pick from, by subject
 *
 * Every page and endpoint is admins only (require_role('admin')) and acts in
 * the admin's OWN social context (social_context() with ?preview=admin, as
 * General Social does): the uploads are the person's (users.id), the stock
 * library is shared, the export copies go to CREATIVES_DIR/social/admin-<uid>/.
 * The portal's upload, delete and asset endpoints are reused unchanged
 * through social_url(). The pictures are drawn by social_render() wherever
 * the existing look fits (full photo, centred words) and by
 * plan_render_split() (inc/plan_render.php) for the split layout the broker
 * and location features add: the photo on top, the words on the ground
 * below, an optional row of the team's faces. ONE render path per picture,
 * as everywhere: the preview iframe and Chromium's PNG are the same HTML.
 */
require_once __DIR__ . '/social.php';
require_once __DIR__ . '/plan_render.php';
require_once __DIR__ . '/plan_agent_render.php';   // the Featured Agent designs (2026-11-02 evening)
require_once __DIR__ . '/boards.php';          // mk_areas(): the website's Communities, the locations and the brokers' markets
require_once __DIR__ . '/market_data.php';     // market_card_defs(): the Market Snapshot's places, for the stats rotation

const PLAN_NEW_DAYS    = 21;   // a listing is "new" this long after the feed first saw it (or its new_listing change)
const PLAN_CLOSED_DAYS = 30;   // a closing stays in the Just Sold list this long (the agents' tools keep 7)
const PLAN_MAX_FRAMES  = 9;    // photos in a set: the cover plus up to 8 more (Nikki, 2026-11-02); the end card is extra

// ── Access ───────────────────────────────────────────────────────────────────

/**
 * The admin-side look of the plan builders (Nikki, 2026-11-02, "just for me,
 * the admin"): smaller pills, lighter numbers in the step circles, room
 * between a dropdown and the row under it, and the logo sliders. Passed as
 * `css` to admin_tool_header() by the four template pages; the agents' tools
 * keep social.css as it is.
 */
function plan_admin_css(): string {
    return '
    .sp-step-h span { color:#d6dee8; }
    .sp-pillopt span { padding:4px 11px; font-size:12.5px; border-width:1.5px; }
    .sp-look { margin-top:9px; gap:6px; }
    .sp-look-l { font-size:13px; min-width:84px; }
    .sp-field + .sp-look, .sp-two + .sp-look, .sp-field + .sp-hint, .sp-two + .sp-hint, .sp-look + .sp-team { margin-top:18px; }
    .sp-set { margin-top:18px; }
    .sp-ranges { display:grid; grid-template-columns:1fr; gap:4px 18px; margin-top:12px; padding:10px 12px 8px; background:#f6f7f9; border-radius:10px; }
    @media (min-width:640px) { .sp-ranges { grid-template-columns:1fr 1fr; } }
    .sp-range { display:flex; align-items:center; gap:10px; font-size:12.5px; color:#6b7a8f; margin:0; }
    .sp-range input[type=range] { flex:1; min-width:0; accent-color:#4a5569; }
    .sp-range output { min-width:40px; text-align:right; color:#1f242c; font-variant-numeric:tabular-nums; }
    .sp-reset { background:none; border:0; padding:4px 0 0; font:inherit; font-size:12.5px; text-align:left; }
    ';
}

/** Admins only; the admin's own social context (uploads, stock, export copies), a CSRF token made. */
function plan_gate(mysqli $conn): array {
    require_login();
    require_role('admin');
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_GET['preview'] = 'admin';
    $ctx = social_context($conn);
    social_gate();
    return $ctx;
}

// ── The plan itself ──────────────────────────────────────────────────────────

/**
 * The plan's content types, as the docx states them: key => words. `tool` is
 * the admin page; `per_week` the frequency line; `notes` the guidelines.
 */
function plan_definition(): array {
    return [
        'property' => ['name' => 'Property Posts + Stories', 'per_week' => '3 per week (post and story)', 'tool' => 'template_property.php',
                       'what' => 'New Listings, Featured Listings, Just Sold; sales and/or rentals.',
                       'notes' => ['Each story should be a multi-image slideshow, not a single photo.']],
        'market'   => ['name' => 'Market Stats', 'per_week' => '1 story per week', 'tool' => 'market_snapshot.php',
                       'what' => 'Stats from our key markets, including submarkets.',
                       'notes' => ['Each market gets its own story: Aspen is one story and Basalt is a separate story.', 'Rotate through markets week to week.']],
        'location' => ['name' => 'Location Feature', 'per_week' => '1 story per week', 'tool' => 'template_location.php',
                       'what' => 'An office, the team in place, or a local real estate angle.',
                       'notes' => ['Office feature: images of the office or views from the office.', 'Team in place: brokers based at that location.',
                                   'Local real estate angle: something specific to that market (a neighborhood, a notable sale, a local trend).']],
        'broker'   => ['name' => 'Broker Feature', 'per_week' => '1 story per week', 'tool' => 'template_broker.php',
                       'what' => 'Spotlight one broker and tie them to their market.',
                       'notes' => ['Celebrate broker milestones: notable closings, production recognition, awards, designations, or other industry achievements.',
                                   'Rotate through the hooks, swapping in the broker\'s name and market.',
                                   'Broker photography: show brokers in their element (at listings, around town, outdoors, with recognizable local backdrops), not just straight headshots. Plan seasonal lifestyle shoots to build a photo library for each broker.']],
        'branding' => ['name' => 'Branding', 'per_week' => '2 stories per week', 'tool' => 'template_branding.php',
                       'what' => 'Follows the Mont Haus seasonal branding strategy.', 'notes' => []],
    ];
}

/**
 * The sample week from the plan: day => the pieces. Each piece: type (a
 * plan_definition() key), variant (the property kind), and the words.
 */
function plan_sample_week(): array {
    return [
        'Monday'    => [['type' => 'property', 'variant' => 'new',      'words' => 'Property (New Listing)']],
        'Tuesday'   => [['type' => 'market',   'words' => 'Market Stats'], ['type' => 'branding', 'words' => 'Branding']],
        'Wednesday' => [['type' => 'property', 'variant' => 'featured', 'words' => 'Property (Featured Listing)']],
        'Thursday'  => [['type' => 'location', 'words' => 'Location Feature']],
        'Friday'    => [['type' => 'property', 'variant' => 'sold',     'words' => 'Property (Just Sold or Rental)'], ['type' => 'branding', 'words' => 'Branding']],
        'Saturday'  => [['type' => 'broker',   'words' => 'Broker Feature']],
    ];
}

/**
 * The broker hooks from the plan, with {name}, {first} and {market} to fill.
 * The plan's examples read "Jackson Horn" and "Montrose".
 */
function plan_hooks(): array {
    return [
        // Without "with Mont Haus" since 2026-11-02 (Nikki): the logo carries the brand on every design.
        'Thinking of buying or selling in {market}? Call {name}.',
        'Know {market} like a local? {name} does.',
        'Your {market} real estate expert: {name}.',
        'Looking for the right home in {market}? Start with {name}.',
        'Curious what your {market} home is worth? Ask {name}.',
        'Local knowledge. Global reach. Meet {name}.',
    ];
}

/** Milestone openers for the broker feature (the plan: notable closings, production recognition, awards, designations). */
function plan_milestones(): array {
    return [
        'Congratulations, {first}.',
        'Just closed: another one for {name}.',
        'Top producer: {name}, {market}.',
        'Award winning: {name}, {market}.',
        'Newly designated: {name}, {market}.',
    ];
}

/** "{name}" → words. Unknown keys are left as they are. */
function plan_fill(string $tpl, array $vars): string {
    foreach ($vars as $k => $v) $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
    return $tpl;
}

/**
 * The seasonal branding presets: key => season words, taglines (the first is
 * the default), and the stock file-name words that suggest a photo for it.
 * The taglines are drafts for Nikki to edit (the plan's "seasonal branding
 * strategy" lives outside this tool); the brand line "Alpine Roots. Global
 * Vision." is the one the General Social tool starts with.
 */
function plan_seasons(): array {
    return [
        'winter'   => ['name' => 'Winter',   'months' => [12, 1, 2], 'words' => ['winter', 'ski', 'snow', 'chairlift', 'gondola'],
                       'taglines' => ["Alpine Roots.\nGlobal Vision.", "Home is where\nthe snow falls.", "First tracks.\nFirst call.", "Winter, the way\nColorado does it."]],
        'spring'   => ['name' => 'Spring',   'months' => [3, 4, 5],  'words' => ['summer', 'river', 'lake', 'ranch'],
                       'taglines' => ["Alpine Roots.\nGlobal Vision.", "New season.\nNew address.", "The valley wakes up.\nSo does the market.", "Mud season is\nmoving season."]],
        'summer'   => ['name' => 'Summer',   'months' => [6, 7, 8],  'words' => ['summer', 'panorama', 'lake', 'river', 'ranch'],
                       'taglines' => ["Alpine Roots.\nGlobal Vision.", "Long days.\nHigh country.", "Summer in\nthe mountains.", "Find your\nhigh ground."]],
        'fall'     => ['name' => 'Fall',     'months' => [9, 10, 11], 'words' => ['fall', 'aspens', 'colour', 'color'],
                       'taglines' => ["Alpine Roots.\nGlobal Vision.", "Gold season.", "Fall for\nthe mountains.", "The aspens turn.\nSo does the market."]],
        'holidays' => ['name' => 'Holidays', 'months' => [],         'words' => ['winter', 'snow', 'dusk', 'twilight'],
                       'taglines' => ["Happy Holidays\nfrom all of us\nat Mont Haus.", "Warm wishes from\nthe mountains.", "Here's to home.", "Happy New Year\nfrom Mont Haus."]],
        'always'   => ['name' => 'Any season', 'months' => [],       'words' => ['bw', 'highway', 'aerial'],
                       'taglines' => ["Alpine Roots.\nGlobal Vision.", "Local knowledge.\nGlobal reach.", "Mountain real estate,\ndone properly.", "Where you live\nis how you live."]],
    ];
}

/** The season for a date: by month (late November and December lean to Holidays after the 20th of November). */
function plan_season_for(DateTimeImmutable $d): string {
    $m = (int)$d->format('n'); $day = (int)$d->format('j');
    if ($m === 12 || ($m === 11 && $day >= 20)) return 'holidays';
    foreach (plan_seasons() as $k => $s) if (in_array($m, $s['months'], true)) return $k;
    return 'always';
}

/**
 * The week the plan page shows: the Monday of ?week=YYYY-Www (ISO) or of this
 * week; `index` counts weeks from the Monday of ISO week 1 of 2026, which is
 * what every rotation steps by (markets, brokers, locations, hooks), so the
 * same week always suggests the same picks and the next week moves on by one.
 */
function plan_week(string $want = ''): array {
    $tz = new DateTimeZone('America/Denver');
    $now = new DateTimeImmutable('now', $tz);
    if (preg_match('/^(\d{4})-W(\d{2})$/', $want, $m) && (int)$m[2] >= 1 && (int)$m[2] <= 53) {
        $mon = (new DateTimeImmutable('now', $tz))->setISODate((int)$m[1], (int)$m[2], 1)->setTime(0, 0);
    } else {
        $mon = $now->setISODate((int)$now->format('o'), (int)$now->format('W'), 1)->setTime(0, 0);
    }
    $anchor = (new DateTimeImmutable('2025-12-29 00:00', $tz));   // Monday of ISO week 1, 2026
    $index  = (int)floor(($mon->getTimestamp() - $anchor->getTimestamp()) / 604800);
    $sat    = $mon->modify('+5 days');
    $label  = $mon->format('F j') . ' to ' . ($sat->format('n') === $mon->format('n') ? $sat->format('j') : $sat->format('F j')) . ', ' . $sat->format('Y');
    return ['monday' => $mon, 'saturday' => $sat, 'index' => $index, 'key' => $mon->format('o-\WW'), 'label' => $label,
            'prev' => $mon->modify('-7 days')->format('o-\WW'), 'next' => $mon->modify('+7 days')->format('o-\WW'),
            'this' => $now->format('o-\WW'), 'season' => plan_season_for($mon->modify('+3 days'))];
}

/** The rotation's pick from a list for a week index: the (index mod count)th item, or null for an empty list. */
function plan_rotate(array $list, int $index) {
    $list = array_values($list);
    if (!$list) return null;
    return $list[(($index % count($list)) + count($list)) % count($list)];
}

// ── The brokers ──────────────────────────────────────────────────────────────

/**
 * The brokers a feature can spotlight: every active, approved agent (not a
 * team, not staff), with the words the templates pre-fill. Keyed by id.
 *   name, first, slug, title, office (slug), service_area, areas (the
 *   Communities town keys ticked on the profile), market (plan_agent_market()),
 *   headshot_url, face_url (absolute, this portal's), region (the stock
 *   library's group for their market).
 */
function plan_agents(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $has = fn(string $c) => mk_column_exists($conn, 'marketing_intakes', $c);
    $cols = ['id', 'agent_name', 'slug', 'headshot_url', 'headshot_face_url'];
    foreach (['agent_title', 'office', 'service_area', 'fub_areas', 'web_status', 'entity_type', 'status'] as $c) if ($has($c)) $cols[] = $c;
    $where = "is_active = 1" . ($has('status') ? " AND COALESCE(status, '') <> 'archived'" : '')
           . ($has('entity_type') ? " AND COALESCE(entity_type, 'agent') = 'agent'" : '')
           . ($has('web_status') ? " AND web_status = 'approved'" : '');
    $r = $conn->query("SELECT " . implode(', ', $cols) . " FROM marketing_intakes WHERE {$where} ORDER BY agent_name");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $a) {
        $name = trim((string)$a['agent_name']);
        if ($name === '') continue;
        $areas = json_decode((string)($a['fub_areas'] ?? ''), true);
        $areas = is_array($areas) ? array_values(array_filter(array_map('strval', $areas))) : [];
        $entry = [
            'id'           => (int)$a['id'],
            'name'         => $name,
            'first'        => explode(' ', $name)[0],
            'slug'         => (string)$a['slug'],
            'title'        => trim(preg_replace('/\s*\n\s*/', ' · ', (string)($a['agent_title'] ?? ''))),   // "CEO\nEmploying Broker" → one line
            'office'       => (string)($a['office'] ?? ''),
            'service_area' => trim((string)($a['service_area'] ?? '')),
            'areas'        => $areas,
            'headshot_url' => trim((string)$a['headshot_url']),
            'face_url'     => trim((string)$a['headshot_face_url']),
        ];
        // The cutout (2026-11-02): agent-photos/<slug>-cutout.png, the headshot with its background removed (Adobe), when made.
        $cut = $entry['slug'] !== '' ? mk_photo_dir() . '/' . $entry['slug'] . '-cutout.png' : '';
        $entry['cutout_url'] = ($cut !== '' && is_file($cut)) ? mk_photo_base() . '/' . $entry['slug'] . '-cutout.png?v=' . filemtime($cut) : '';
        $entry['market'] = plan_agent_market($entry);
        // The stock library leads with the market's region (Jackson Horn: Montrose, so the San Juans, though
        // his ticked towns begin alphabetically with Aspen), else the first ticked town's, else the office's.
        $entry['region'] = plan_region_for_market($entry['market']) ?? plan_region_for_towns($areas) ?? plan_office_region($entry['office']);
        $cache[$entry['id']] = $entry;
    }
    return $cache;
}

/**
 * The market words a broker is tied to ("Montrose" in the plan's examples):
 * the first place named in the profile's service area text ("Montrose and the
 * Western Slope; Aspen and the Roaring Fork Valley" → "Montrose"; "Telluride ·
 * Ridgway · Ouray" → "Telluride"), else the first ticked town, else the office.
 */
function plan_agent_market(array $a): string {
    $t = (string)($a['service_area'] ?? '');
    if ($t !== '') {
        $first = preg_split('/\s*(?:;|,|·|\/|\band\b|&)\s*/u', $t)[0] ?? '';
        $first = trim(preg_replace('/^(the|greater)\s+/i', '', trim($first)));
        $first = preg_replace('/\s+(metro|area|valley|county)$/i', '', $first);   // "Denver Metro" → "Denver"
        if ($first !== '' && mb_strlen($first) <= 30) return $first;
    }
    $towns = plan_town_labels();
    foreach ((array)($a['areas'] ?? []) as $k) if (isset($towns[$k])) return $towns[$k];
    return plan_office_label((string)($a['office'] ?? '')) ?: 'Colorado';
}

/** The Communities town keys → labels, every area. */
function plan_town_labels(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (mk_areas() as $a) foreach ($a['towns'] as $t) $cache[$t['key']] = $t['label'];
    return $cache;
}

/** The office slugs the roster uses (marketing_intakes.office) → words. */
function plan_offices(): array {
    return ['aspen' => 'Aspen', 'vail' => 'Vail', 'colorado-springs' => 'Colorado Springs', 'denver' => 'Denver'];
}
function plan_office_label(string $slug): string { return plan_offices()[$slug] ?? ''; }

/** The Communities area key → the stock library's region name (social_stock_region_order()). */
function plan_area_region(string $area_key): string {
    return ['roaring-fork-valley' => 'Aspen & Roaring Fork Valley', 'vail-valley' => 'Vail Valley', 'summit-county' => 'Summit County',
            'routt-county' => 'Steamboat & Routt County', 'lake-county' => 'Summit County', 'gunnison-valley' => 'Crested Butte & Gunnison County',
            'southwest-colorado' => 'Telluride, Ridgway & the San Juans', 'front-range' => 'Front Range'][$area_key] ?? 'Colorado';
}
function plan_office_region(string $office): string {
    return ['aspen' => 'Aspen & Roaring Fork Valley', 'vail' => 'Vail Valley', 'colorado-springs' => 'Front Range', 'denver' => 'Front Range'][$office] ?? 'Colorado';
}
/** The region of the Communities town or area whose label is these words ("Montrose", "Vail Valley"), or null. */
function plan_region_for_market(string $words): ?string {
    $w = mb_strtolower(trim($words));
    if ($w === '') return null;
    foreach (mk_areas() as $a) {
        if (mb_strtolower($a['area']) === $w) return plan_area_region($a['key']);
        foreach ($a['towns'] as $t) if (mb_strtolower($t['label']) === $w) return plan_area_region($a['key']);
    }
    return null;
}

/** The region of the first ticked town's area, or null when none is ticked. */
function plan_region_for_towns(array $town_keys): ?string {
    foreach ($town_keys as $k) { $a = mk_town_area($k); if ($a !== null && $a !== '') return plan_area_region($a); }
    return null;
}

// ── The locations ────────────────────────────────────────────────────────────

/**
 * What a Location Feature can be about, keyed "office:aspen", "area:vail-valley",
 * "town:telluride": label, kind, the Communities town keys it covers (how the
 * team based there is found), the stock region, a suggested stock photo, and
 * the words the headline starts with. Offices first (the plan: "images of the
 * office or views from the office"), then the website's areas, then towns.
 */
function plan_locations(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $areas = mk_areas();
    $office_area = ['aspen' => 'roaring-fork-valley', 'vail' => 'vail-valley', 'colorado-springs' => 'front-range', 'denver' => 'front-range'];
    $office_town = ['aspen' => 'aspen', 'vail' => 'vail', 'colorado-springs' => 'colorado-springs', 'denver' => 'greater-denver'];
    foreach (plan_offices() as $slug => $label) {
        $ak = $office_area[$slug];
        $towns = [];
        foreach ($areas as $a) if ($a['key'] === $ak) $towns = array_column($a['towns'], 'key');
        $cache['office:' . $slug] = ['key' => 'office:' . $slug, 'kind' => 'office', 'label' => $label, 'office' => $slug, 'towns' => $towns,
                                     'town' => $office_town[$slug], 'region' => plan_office_region($slug), 'group' => 'Offices'];
    }
    foreach ($areas as $a) {
        $cache['area:' . $a['key']] = ['key' => 'area:' . $a['key'], 'kind' => 'area', 'label' => $a['area'], 'office' => '', 'towns' => array_column($a['towns'], 'key'),
                                       'town' => '', 'region' => plan_area_region($a['key']), 'group' => 'Areas'];
    }
    foreach ($areas as $a) foreach ($a['towns'] as $t) {
        $cache['town:' . $t['key']] = ['key' => 'town:' . $t['key'], 'kind' => 'town', 'label' => $t['label'], 'office' => '', 'towns' => [$t['key']],
                                       'town' => $t['key'], 'region' => plan_area_region($a['key']), 'group' => $a['area']];
    }
    foreach ($cache as &$l) $l['photo'] = plan_location_photo($l);
    unset($l);
    return $cache;
}

/** A location by key, or null. */
function plan_location(string $key): ?array { return plan_locations()[$key] ?? null; }

/**
 * The stock photo suggested for a location: the first library photo whose
 * name starts with the town (vail-, telluride-), else the Market Snapshot's
 * suggestion for a card with that title, else the first of the region.
 */
function plan_location_photo(array $loc): string {
    $stock = social_stock();
    if (!$stock) return '';
    $town = (string)($loc['town'] ?? '');
    $prefixes = [];
    if ($town !== '') $prefixes[] = $town === 'greater-denver' ? 'denver' : ($town === 'steamboat-springs' ? 'steamboat' : ($town === 'snowmass-village' ? 'snowmass' : $town));
    foreach ($prefixes as $pre) foreach ($stock as $name => $cap) if (str_starts_with($name, $pre . '-')) return $name;
    foreach (market_card_defs() as $d) if (strcasecmp((string)$d['title'], (string)$loc['label']) === 0 && isset($stock[$d['photo']])) return $d['photo'];
    foreach (social_stock_grouped()[$loc['region']] ?? [] as $name => $cap) return $name;
    return '';
}

/**
 * The brokers based at a location: for an office, the agents whose roster
 * office is that one; for an area or a town, the agents who ticked one of its
 * towns under Service areas on their profile. In roster order (by name).
 */
function plan_location_team(array $loc, array $agents): array {
    $out = [];
    foreach ($agents as $a) {
        $in = $loc['kind'] === 'office' ? ($a['office'] === $loc['office']) : (bool)array_intersect($a['areas'], $loc['towns']);
        if ($in) $out[] = $a;
    }
    return $out;
}

// ── The listings: every Mont Haus listing in the feed ────────────────────────

/**
 * The Mont Haus listings a property post can be about, company-wide (the
 * admin's tool, not one agent's): hs_listing_state rows in the feed and live
 * (Active, Active Under Contract, Pending, Coming Soon), or closed within
 * PLAN_CLOSED_DAYS. Keyed "market|listing_key" like social_listings(), with
 * the same pre-fill words plus `agent` (the list agent, and co-list), `new`
 * (first seen, or a new_listing change, within PLAN_NEW_DAYS) and `closed`.
 * Cross-board copies collapsed. Live new ones first, then live by price,
 * closings last.
 */
function plan_listings(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (!mk_table_exists($conn, 'hs_listing_state')) return $cache;
    $since_d  = gmdate('Y-m-d', time() - PLAN_CLOSED_DAYS * 86400);
    $since_dt = gmdate('Y-m-d H:i:s', time() - PLAN_CLOSED_DAYS * 86400);
    $new_dt   = gmdate('Y-m-d H:i:s', time() - PLAN_NEW_DAYS * 86400);
    $has_changes = mk_table_exists($conn, 'hs_listing_changes');
    $new_sql = $has_changes
        ? "(st.first_seen_at >= '{$new_dt}' OR EXISTS (SELECT 1 FROM hs_listing_changes hn WHERE hn.market = st.market AND hn.listing_key = st.listing_key
                                                         AND hn.change_type = 'new_listing' AND hn.detected_at >= '{$new_dt}'))"
        : "(st.first_seen_at >= '{$new_dt}')";
    $closed_sql = "(LOWER(st.status) IN ('closed','sold','leased') AND (st.close_date >= '{$since_d}'"
                . ($has_changes ? " OR EXISTS (SELECT 1 FROM hs_listing_changes hc WHERE hc.market = st.market AND hc.listing_key = st.listing_key
                                                 AND hc.change_type = 'closed' AND hc.detected_at >= '{$since_dt}')" : '') . "))";
    $r = $conn->query("SELECT st.market, st.listing_key, st.mls_id, st.status, st.is_rental, st.property_type, st.address, st.city,
                              st.list_price, st.close_price, st.close_date, st.beds, st.baths, st.sqft, st.primary_photo, st.url,
                              st.list_agent_name, st.colist_agent_name, st.first_seen_at, {$new_sql} AS is_new
                         FROM hs_listing_state st
                        WHERE st.in_feed = 1 AND (st.status IN ('Active','Active Under Contract','Pending','Coming Soon') OR {$closed_sql})
                        ORDER BY (LOWER(st.status) IN ('closed','sold','leased')), is_new DESC, (st.market = 'recolorado'), st.list_price DESC, st.address");
    $seen = [];
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $l) {
        $k = strtolower(preg_replace('/\s+/', ' ', trim((string)$l['address']))) . '|' . strtolower(trim((string)$l['city'])) . '|' . (int)$l['is_rental'] . '|' . (string)$l['list_price'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $id = $l['market'] . '|' . $l['listing_key'];
        $closed = social_is_closed((string)$l['status']);
        $addr = trim((string)$l['address']);
        // A team's feed name reads "Weber Boxer Group | Jonathan Boxer Scott Weber": the team name alone.
        $side = fn(string $n) => trim(explode('|', $n)[0]);
        $agent = $side((string)$l['list_agent_name']);
        if ($side((string)$l['colist_agent_name']) !== '') $agent .= ' & ' . $side((string)$l['colist_agent_name']);
        $cache[$id] = $l + [
            'id'         => $id,
            'closed'     => $closed,
            'new'        => !$closed && (int)$l['is_new'] === 1,
            'headline'   => $addr !== '' ? social_short_address($addr) : trim((string)$l['city']),   // a confidential listing has no address in the feed
            'address'    => $addr,
            'price_text' => ((int)$l['is_rental'] || $closed) ? '' : social_price_text($l['list_price']),
            'specs_text' => social_specs_text($l['beds'], $l['baths'], $l['sqft']),
            'town'       => trim((string)$l['city']) !== '' ? trim((string)$l['city']) . ', Colorado' : '',
            'agent'      => $agent,
            'thumb'      => (string)$l['primary_photo'] !== '' ? social_img((string)$l['primary_photo'], 400) : '',
        ];
    }
    return $cache;
}

/** One listing by "market|listing_key", or null. */
function plan_listing(mysqli $conn, string $id): ?array { return plan_listings($conn)[$id] ?? null; }

/** The dropdown's groups, in order: New Listings, Listings, Rentals, Just Sold (each only when it has rows). */
function plan_listing_groups(array $listings): array {
    $g = ['New Listings' => [], 'Listings' => [], 'Rentals' => [], 'Just Sold' => []];
    foreach ($listings as $l) {
        if ($l['closed'])             $g['Just Sold'][] = $l;
        elseif ((int)$l['is_rental']) $g['Rentals'][] = $l;
        elseif ($l['new'])            $g['New Listings'][] = $l;
        else                          $g['Listings'][] = $l;
    }
    return array_filter($g);
}

/** The property variants (the plan's content types) → the default label. */
function plan_property_variants(): array {
    return ['new' => 'New Listing', 'featured' => 'Featured Listing', 'sold' => 'Just Sold', 'rental' => 'Featured Rental'];
}

/** The ready-made labels the plan tools offer (the Instagram tool's plus the plan's own). */
function plan_labels(): array {
    return array_values(array_unique(array_merge(['New Listing'], social_labels(),
        ['Broker Spotlight', 'Meet the Team', 'Milestone', 'Office Feature', 'Our Team', 'Local Market', 'Now Open'])));
}

// ── Photos ───────────────────────────────────────────────────────────────────

/** A photo tile for the picker: {id, thumb, large, own, caption, group}. */
function plan_tile(string $id, string $thumb, string $large, string $group, string $caption = '', bool $own = false): array {
    return ['id' => $id, 'thumb' => $thumb, 'large' => $large, 'own' => $own, 'caption' => $caption, 'group' => $group];
}

/** The person's own uploads as tiles. */
function plan_upload_tiles(array $ctx): array {
    $out = [];
    foreach (social_uploads($ctx) as $id) $out[] = plan_tile('upload:' . $id, social_upload_url($ctx, $id, 400), social_upload_url($ctx, $id, 1600), 'Your photos', '', true);
    return $out;
}

/** The stock library as tiles, one group per region, $first region ahead of the rest. */
function plan_stock_tiles(?string $first = null): array {
    $groups = social_stock_grouped();
    if ($first !== null && isset($groups[$first])) $groups = [$first => $groups[$first]] + $groups;
    $out = [];
    foreach ($groups as $region => $list) foreach ($list as $name => $caption) {
        $out[] = plan_tile('stock:' . $name, social_stock_url($name, 400), social_stock_url($name, 2048), $region, $caption);
    }
    return $out;
}

/** The stock photos whose names carry any of the season's words, as one "Suggested for {season}" group (at most 12). */
function plan_season_tiles(string $season): array {
    $s = plan_seasons()[$season] ?? null;
    if (!$s) return [];
    $out = [];
    foreach (social_stock() as $name => $caption) {
        foreach ($s['words'] as $w) if (str_contains($name, '-' . $w)) { $out[] = plan_tile('stock:' . $name, social_stock_url($name, 400), social_stock_url($name, 2048), 'Suggested for ' . strtolower($s['name']), $caption); break; }
        if (count($out) >= 12) break;
    }
    return $out;
}

/** A broker's headshot and face crop as tiles (only the ones this portal hosts). */
function plan_agent_tiles(array $a): array {
    $out = [];
    if ($a['cutout_url'] !== '') $out[] = plan_tile('cutout:' . $a['id'], $a['cutout_url'], $a['cutout_url'], 'Headshot', $a['name'] . ', cut out (for the Cutout style)');
    if (mk_photo_file($a['headshot_url']) !== '') $out[] = plan_tile('headshot:' . $a['id'], $a['headshot_url'], $a['headshot_url'], 'Headshot', $a['name'] . ', the profile headshot');
    if (mk_photo_file($a['face_url']) !== '' && $a['face_url'] !== $a['headshot_url']) $out[] = plan_tile('face:' . $a['id'], $a['face_url'], $a['face_url'], 'Headshot', $a['name'] . ', the square crop');
    return $out;
}

/** A listing's photos from the site as tiles (primary first), remembered in the session so a posted one can be checked. */
function plan_listing_tiles(array $l): array {
    $urls = social_photos((string)$l['market'], (string)$l['listing_key']);
    if (!$urls && (string)$l['primary_photo'] !== '') $urls = [(string)$l['primary_photo']];
    return array_map(fn($u) => plan_tile($u, social_img($u, 400), social_img($u, 1600), 'Listing photos'), $urls);
}

/**
 * The photo a form posted, resolved to [url for the preview, file on disk for
 * the export or null]: one of this person's uploads, a stock photo, a broker's
 * headshot or face crop (when $agent is given), one the site listed for the
 * listing (when $listing is given; else its primary), or the Mont Haus
 * mountains placeholder. Never a free URL.
 */
function plan_photo(array $ctx, string $photo, ?array $listing = null, ?array $agent = null): array {
    if (str_starts_with($photo, 'upload:') && ($f = social_upload_file($ctx, substr($photo, 7)))) {
        return [social_upload_url($ctx, substr($photo, 7), 1600), $f];
    }
    if (str_starts_with($photo, 'stock:') && ($f = social_stock_file(substr($photo, 6)))) {
        return [social_stock_url(substr($photo, 6), 2048), $f];
    }
    if ($agent && preg_match('/^(headshot|face|cutout):(\d+)$/', $photo, $m) && (int)$m[2] === (int)$agent['id']) {
        $u = $m[1] === 'face' ? $agent['face_url'] : ($m[1] === 'cutout' ? $agent['cutout_url'] : $agent['headshot_url']);
        $f = mk_photo_file($u);
        if ($f !== '' && is_file($f)) return [$u, realpath($f) ?: $f];
    }
    if ($listing) {
        $allowed = (array)($_SESSION['social_photos'][$listing['id']] ?? []);
        if ((string)$listing['primary_photo'] !== '') $allowed[] = (string)$listing['primary_photo'];
        if (!in_array($photo, $allowed, true)) $photo = (string)$listing['primary_photo'];
        if ($photo !== '') return [social_img($photo, 1600), null];
    }
    return [rtrim(SITE_URL, '/') . '/assets/images/social-placeholder.jpg', realpath(__DIR__ . '/../assets/images/social-placeholder.jpg') ?: null];
}

// ── The post ─────────────────────────────────────────────────────────────────

/** The plan tools' kinds. */
function plan_kinds(): array { return ['property' => 'Property', 'broker' => 'Broker Feature', 'location' => 'Location Feature', 'branding' => 'Branding']; }

/** The layouts: the full photo with the words lower left (the Instagram look), the framed photo (a band, the photo window, the words; inc/plan_render.php), or centred words. */
function plan_layouts(): array { return ['photo' => 'Full photo', 'split' => 'Framed', 'center' => 'Centered']; }

/** Where the label badge may sit in the plan tools: the Instagram tool's two corners, or above the headline (2026-11-02: a badge in the corner covered a broker's face). */
function plan_badge_places(): array { return social_badge_places() + ['body' => 'Above headline']; }

/** The looks a tool offers: both, everywhere. (The Broker Feature was dark only for a few hours on 2026-11-02, until its designs stopped fading the photo.) */
function plan_themes(string $kind): array { return social_themes(); }

/** The logo colours the plan tools offer: the look's own (white on dark, black on light), or forced. */
function plan_logo_colors(): array { return ['auto' => 'Auto', 'white' => 'White', 'black' => 'Black']; }

/** The social_render() template key for a size: the "other" family (no listing rules, no open house). */
function plan_social_type(string $format): string {
    return match ($format) { 'story' => 'story_other', 'square' => 'square_other', default => 'photo_other' };
}

/**
 * The posted form as the frames to draw, or a message (string) when there is
 * nothing to draw yet. Every value is checked here: the kind, the size, the
 * look, the subject (a listing, a broker or a location this portal knows),
 * the photo (plan_photo()), the label, the words and their styles, lengths,
 * the frame. Returns:
 *   kind, set (true for a story set), preview (the frame index to show),
 *   frames: [['renderer' => 'social' | 'split', 'post' => the normalised
 *            post, 'name' => the file's name without extension], …]
 */
function plan_post_from_request(mysqli $conn, array $ctx, array $in): array|string {
    $kind   = isset(plan_kinds()[(string)($in['kind'] ?? '')]) ? (string)$in['kind'] : 'property';
    $format = isset(social_formats()[(string)($in['size'] ?? '')]) ? (string)$in['size'] : 'post';
    $theme  = isset(plan_themes($kind)[(string)($in['theme'] ?? '')]) ? (string)$in['theme'] : 'dark';
    $mark   = isset(social_marks()[(string)($in['mark'] ?? '')]) ? (string)$in['mark'] : 'triangle';
    $logoKind = isset(social_logo_kinds()[(string)($in['logo_kind'] ?? '')]) ? (string)$in['logo_kind'] : 'full';
    $layout = isset(plan_layouts()[(string)($in['layout'] ?? '')]) ? (string)$in['layout'] : ($kind === 'branding' ? 'center' : 'photo');
    // The Broker Feature has its own designs (inc/plan_agent_render.php) in place of the layouts: `style`.
    $style = $kind === 'broker' ? (isset(plan_agent_styles()[(string)($in['style'] ?? '')]) ? (string)$in['style'] : 'editorial') : '';
    $agent = null;   // set below; the Cutout style reads it
    $logo   = (string)($in['logo'] ?? '');
    if (!isset(social_logo_places()[$logo])) $logo = $format === 'story' ? 'bottom' : 'top';
    if ($layout === 'split' && !in_array($logo, ['top', 'none'], true)) $logo = 'top';
    if ($logo === 'center' && $layout !== 'center') $logo = 'top';
    $badge  = isset(plan_badge_places()[(string)($in['badge'] ?? '')]) ? (string)$in['badge'] : ($format === 'story' && $logo !== 'top' ? 'right' : 'left');
    // The logo's colour, size, nudge and the shadows (2026-11-02): checked by social_logo_options() in the renderers; carried as posted.
    $logoOpts = ['logo_color' => (string)($in['logo_color'] ?? 'auto'), 'logo_scale' => round((int)($in['logo_scale'] ?? 100) / 100, 2),
                 'logo_dx' => (int)($in['logo_dx'] ?? 0), 'logo_dy' => (int)($in['logo_dy'] ?? 0),
                 'text_shadow' => ($in['text_shadow'] ?? '') === '' ? null : (int)$in['text_shadow'], 'logo_shadow' => (int)($in['logo_shadow'] ?? 0)];

    // The subject.
    $listing = null; $loc = null; $team = [];
    if ($kind === 'property') {
        $listing = plan_listing($conn, (string)($in['listing'] ?? ''));
        if (!$listing) return 'Choose a listing to see the post.';
    } elseif ($kind === 'broker') {
        $agent = plan_agents($conn)[(int)($in['agent'] ?? 0)] ?? null;
        if (!$agent) return 'Choose a broker to see the feature.';
    } elseif ($kind === 'location') {
        $loc = plan_location((string)($in['loc'] ?? ''));
        if (!$loc) return 'Choose a location to see the feature.';
        $agents = plan_agents($conn);
        foreach (array_slice(array_map('intval', (array)($in['team'] ?? [])), 0, 8) as $id) if (isset($agents[$id])) $team[] = $agents[$id];
    }

    // The label on the photo: a ready-made one, none, or the admin's own words.
    $pick = (string)($in['label'] ?? '');
    if ($pick === '__custom')                     $label = mb_substr(trim((string)($in['label_custom'] ?? '')), 0, 28);
    elseif ($pick === '__none' || $pick === '')   $label = '';
    elseif (in_array($pick, plan_labels(), true)) $label = $pick;
    else                                          $label = '';

    // The words: a headline (Enter = a new line on the centred and split layouts) and up to four lines, each in a style.
    $styles = social_line_styles(); $defaults = social_line_defaults();
    $texts = (array)($in['line_text'] ?? []); $kinds = (array)($in['line_style'] ?? []);
    $nlines = $kind === 'broker' ? 3 : ($layout === 'split' ? 4 : 2);
    $lines = [];
    for ($i = 0; $i < $nlines; $i++) {
        $s = (string)($kinds[$i] ?? $defaults[$i]);
        $lines[] = ['text' => mb_substr(trim((string)($texts[$i] ?? '')), 0, 90), 'style' => isset($styles[$s]) ? $s : $defaults[$i]];
    }
    $headline = mb_substr(trim(str_replace("\r", '', (string)($in['headline'] ?? ''))), 0, 140);
    if ($layout === 'photo') $headline = trim(preg_replace('/\s*\n\s*/', ' ', $headline));   // one line of words over the fade

    $photoIn = (string)($in['photo'] ?? '');
    // The Cutout style wants the cutout: a headshot or face crop picked for it becomes the cutout when one exists.
    if ($kind === 'broker' && $style === 'cutout' && $agent && $agent['cutout_url'] !== '' && preg_match('/^(headshot|face):/', $photoIn)) $photoIn = 'cutout:' . $agent['id'];
    [$photo_url, $photo_file] = plan_photo($ctx, $photoIn, $listing, $agent);
    $frame = [
        'zoom' => max(1.0, min(3.0, round((float)($in['zoom'] ?? 1), 2))),
        'focus_x' => max(0, min(100, (int)($in['focus_x'] ?? 50))),
        'focus_y' => max(0, min(100, (int)($in['focus_y'] ?? ($kind === 'broker' ? 0 : 50)))),   // a headshot starts from the top: the head is never cut
    ];
    $common = ['theme' => $theme, 'mark' => $mark, 'logo_kind' => $logoKind, 'logo' => $logo, 'badge' => $badge, 'format' => $format,
               'label' => $label, 'photo' => (string)($in['photo'] ?? ''), 'photo_url' => $photo_url, 'photo_file' => $photo_file] + $frame + $logoOpts;
    $slug = fn(string $s) => mb_substr(strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $s), '-')), 0, 60) ?: 'post';

    if ($kind === 'broker') $headline = mb_substr(trim(str_replace("\r", '', (string)($in['headline'] ?? ''))), 0, 140);   // the hook keeps its line breaks in every agent style
    // The Cutout style's backdrop (2026-11-02): a stock location photo or one of the person's uploads, under a veil; '' = none.
    $bdIn = (string)($in['backdrop'] ?? '');
    $backdrop = ['backdrop_url' => '', 'backdrop_file' => null, 'backdrop_veil' => max(30, min(97, (int)($in['backdrop_veil'] ?? 82)))];
    if ($kind === 'broker' && (str_starts_with($bdIn, 'stock:') || str_starts_with($bdIn, 'upload:'))) {
        [$bu, $bf] = plan_photo($ctx, $bdIn, null, null);
        if (!str_ends_with($bu, '/social-placeholder.jpg')) { $backdrop['backdrop_url'] = $bu; $backdrop['backdrop_file'] = $bf; }   // the placeholder means an id that is not theirs
    }
    $main = $kind === 'broker'
        ? ['renderer' => 'agent', 'post' => $common + $backdrop + ['style' => $style, 'headline' => $headline, 'lines' => $lines]]
        : ($layout === 'split'
        ? ['renderer' => 'split', 'post' => $common + ['headline' => $headline, 'lines' => $lines, 'team' => plan_team_for_render($team), 'kind' => $kind]]
        : ['renderer' => 'social', 'post' => $common + ['type' => plan_social_type($format), 'layout' => $layout === 'center' ? 'center' : 'corner',
                                                         'headline' => $headline, 'lines' => $lines, 'date_text' => '', 'time_text' => '', 'when_text' => '']]);
    $main['name'] = $slug($headline !== '' ? $headline : ($label ?: $kind));

    $out = ['kind' => $kind, 'set' => false, 'preview' => 0, 'frames' => [$main]];

    // The property SET (the plan: "each story should be a multi-image slideshow, not a single photo"; a post
    // or square set is a carousel, 2026-11-02): the cover is the post's words on the first photo; each further
    // photo is the photo alone, every other one with the logo lower right (plan_render_photo_frame()); an end
    // card, when asked for, closes with the logo centred, the address and the broker.
    if ($kind === 'property' && !empty($in['set'])) {
        $frames = [];
        $seen = [];
        $choices = (array)($in['frame_logo'] ?? []);   // per frame (2026-11-02, Nikki): auto | none | full | mark, aligned with frames[]
        foreach (array_values((array)($in['frames'] ?? [])) as $idx => $pid) {
            $pid = (string)$pid;
            if ($pid === '' || isset($seen[$pid]) || count($frames) >= PLAN_MAX_FRAMES) continue;
            $seen[$pid] = true;
            [$u, $f] = plan_photo($ctx, $pid, $listing, null);
            $choice = (string)($choices[$idx] ?? 'auto');
            $frames[] = ['id' => $pid, 'url' => $u, 'file' => $f, 'choice' => in_array($choice, ['none', 'full', 'mark'], true) ? $choice : 'auto'];
        }
        if (!$frames) $frames[] = ['id' => (string)($in['photo'] ?? ''), 'url' => $photo_url, 'file' => $photo_file, 'choice' => 'auto'];
        $cover = $main;
        $cover['post']['photo'] = $frames[0]['id']; $cover['post']['photo_url'] = $frames[0]['url']; $cover['post']['photo_file'] = $frames[0]['file'];
        $cover['name'] = '01-cover';
        $set = [$cover];
        $addrLine = $headline !== '' ? $headline : (string)$listing['headline'];
        $n = 2;
        foreach (array_slice($frames, 1) as $i => $fr) {
            // The photo alone. The logo lower right as the admin chose for the frame (none, the stack or the M mark), or on
            // Auto the every-other pattern: the first after the cover, the third, the fifth ... in the cover's logo kind
            // (none at all when the cover has none).
            $eff = $fr['choice'] === 'auto' ? (($logo !== 'none' && $i % 2 === 0) ? $logoKind : 'none') : $fr['choice'];
            $set[] = ['renderer' => 'frame', 'name' => sprintf('%02d', $n++),
                      'post' => ['logo_kind' => $eff === 'mark' ? 'mark' : 'full', 'show_logo' => $eff !== 'none'] + $common
                              + ['photo' => $fr['id'], 'photo_url' => $fr['url'], 'photo_file' => $fr['file'], 'zoom' => 1.0, 'focus_x' => 50, 'focus_y' => 50]];
        }
        if (!empty($in['endcard'])) {
            $agentName = (string)$listing['agent'];
            $endLines = [['text' => $lines[1]['text'] ?? '', 'style' => 'price'],
                         ['text' => $agentName !== '' ? 'Listed by ' . $agentName : 'Mont Haus International Realty', 'style' => 'note']];
            $set[] = ['renderer' => 'social', 'name' => sprintf('%02d-end', $n),
                      'post' => $common + ['type' => plan_social_type($format), 'layout' => 'center', 'logo' => 'center', 'label' => $label, 'badge' => $badge === 'body' ? 'body' : 'left',
                                           'photo' => $frames[0]['id'], 'photo_url' => $frames[0]['url'], 'photo_file' => $frames[0]['file'],
                                           'headline' => trim($addrLine . "\n" . trim((string)$listing['city'])),
                                           'lines' => $endLines, 'date_text' => '', 'time_text' => '', 'when_text' => '']];
        }
        $out['set'] = true;
        $out['frames'] = $set;
        $out['preview'] = max(0, min(count($set) - 1, (int)($in['frame'] ?? 0)));
        $out['name'] = $slug((string)$listing['headline']) . '-' . ($format === 'story' ? 'story' : ($format === 'square' ? 'square' : 'post')) . '-set';
    }
    return $out;
}

/** The team row for plan_render_split(): name, the face crop's URL and its file for the export. */
function plan_team_for_render(array $team): array {
    $out = [];
    foreach ($team as $a) {
        $u = $a['face_url'] !== '' ? $a['face_url'] : $a['headshot_url'];
        if ($u === '') continue;
        $f = mk_photo_file($u);
        $out[] = ['name' => $a['name'], 'first' => $a['first'], 'url' => $u, 'file' => ($f !== '' && is_file($f)) ? (realpath($f) ?: $f) : null];
    }
    return $out;
}

/** One frame as HTML through its renderer (the preview, or the export with the photo read from disk). */
function plan_render_frame(array $frame, ?string $base = null, bool $export = false): string {
    return match ($frame['renderer']) {
        'agent' => plan_render_agent($frame['post'], $base, $export),
        'split' => plan_render_split($frame['post'], $base, $export),
        'frame' => plan_render_photo_frame($frame['post'], $base, $export),
        default => social_render($frame['post'], $base, $export),
    };
}

/** The frame's height in px. */
function plan_frame_height(array $frame): int { return social_format_height((string)($frame['post']['format'] ?? 'post')); }

/** "jackson-horn-broker-spotlight-story-20261102-1432.png" */
function plan_filename(array $r, array $frame): string {
    $fmt = (string)($frame['post']['format'] ?? 'post');
    $label = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)($frame['post']['label'] ?? '')), '-'));
    return $frame['name'] . ($label !== '' ? '-' . $label : '') . ($fmt !== 'post' ? '-' . $fmt : '') . '-' . date('Ymd-Hi') . '.png';
}

/**
 * The set as a ZIP of PNGs (01-cover.png, 02.png, …, 0N-end.png), each
 * frame screenshotted by Chromium in turn (social_export_png(); one at a
 * time, about 3 s each). Null when any frame failed or ZipArchive is missing.
 */
function plan_export_zip(array $r): ?string {
    if (!class_exists('ZipArchive')) { error_log('plan_export: ZipArchive is not available'); return null; }
    $tmp = tempnam(sys_get_temp_dir(), 'mh-set-');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return null; }
    set_time_limit(300);
    foreach ($r['frames'] as $frame) {
        $png = social_export_png(plan_render_frame($frame, null, true), plan_frame_height($frame));
        if ($png === null) { $zip->close(); @unlink($tmp); return null; }
        $zip->addFromString($frame['name'] . '.png', $png);
    }
    $zip->close();
    $bytes = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bytes !== '' ? $bytes : null;
}
