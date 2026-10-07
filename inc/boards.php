<?php
/**
 * inc/boards.php — the MLS boards, read from the public site's registry
 * (2026-10-02). Include-only; no database.
 *
 * site.monthaus.com owns the one list of boards (its anyprop_boards.php,
 * MH_BOARDS) and publishes it at api/boards.php. This portal used to carry
 * five copies of the same facts (alias map, two label tables, the roster
 * alert's labels, the Hot Sheet towns, the import's board names) and every new
 * board missed one of them. Now cron/sync_anyprop_roster.php calls
 * mk_boards_refresh() at the start of every run (hourly), which caches the
 * JSON in BOARDS_CACHE_FILE (outside the docroot, written by the cron as
 * admin, read by Apache), and everything else reads the cache:
 *
 *   mk_market_slug($osn)     Anyprop OriginatingSystemName → market slug
 *   mk_board_known($osn)     is this board in the registry at all?
 *   mk_board_label($slug)    "Altitude"      mk_board_labels()  [slug => label]
 *   mk_market_office($slug)  default office slug for the board's agents, or null
 *   mk_board_towns()         [town => slug], Hot Sheet dedupe + Pipeline city list
 *   mk_board_names()         every spelling (slug, OSN, alias) → slug, for CSV imports
 *
 * A board the registry does not know is NOT a slug: the roster sync skips its
 * members and logs a ⚠ (cron/check_health.php emails it), instead of storing
 * identities under a raw OSN that the site will never match (vbor and summit,
 * 2026-10-01/02). Adding a board = one entry on the site; this portal follows
 * on its next roster sync.
 *
 * MK_BOARDS_FALLBACK is a copy of the registry as of 2026-10-02, used ONLY
 * while the cache has never been written (first deploy, test harnesses). It
 * is not a place to add boards.
 *
 * Config: SITE_BOARDS_URL in inc/config.php, or (default) boards.php beside
 * SITE_AGENT_SYNC_URL, so go-live changes one address. The token is
 * AGENT_SYNC_TOKEN in inc/db.php, the same one "Sync to Website" uses.
 */
require_once __DIR__ . '/config.php';

if (!defined('BOARDS_CACHE_FILE')) {
    define('BOARDS_CACHE_FILE', getenv('MH_BOARDS_CACHE') ?: '/var/log/mh-marketing/boards.json');
}

const MK_BOARDS_FALLBACK = [
    'aspen'      => ['osn' => 'agsmls',     'label' => 'Aspen',      'status' => 'live',    'office' => 'aspen', 'aliases' => [], 'area' => 'roaring-fork-valley',
                     'towns' => ['aspen', 'snowmass village', 'old snowmass', 'woody creek', 'basalt', 'carbondale', 'redstone', 'marble',
                                 'glenwood springs', 'new castle', 'silt', 'rifle', 'parachute', 'battlement mesa']],
    'vail'       => ['osn' => 'vbor',       'label' => 'Vail',       'status' => 'live',    'office' => 'vail', 'aliases' => [], 'area' => 'vail-valley',
                     'towns' => ['vail', 'avon', 'beaver creek', 'bachelor gulch', 'arrowhead', 'edwards', 'singletree', 'cordillera', 'eagle',
                                 'eagle-vail', 'gypsum', 'minturn', 'red cliff', 'wolcott', 'mccoy', 'bond']],
    'telluride'  => ['osn' => 'tridemls',   'label' => 'Telluride',  'status' => 'live',    'office' => null, 'aliases' => [], 'area' => 'southwest-colorado',
                     'towns' => ['telluride', 'mountain village', 'ophir', 'placerville', 'sawpit', 'norwood', 'rico']],
    'altitude'   => ['osn' => 'summit',     'label' => 'Altitude',   'status' => 'live',    'office' => null, 'aliases' => [], 'area' => 'summit-county',
                     'towns' => ['breckenridge', 'blue river', 'frisco', 'dillon', 'silverthorne', 'keystone', 'copper mountain', 'heeney',
                                 'steamboat springs', 'oak creek', 'hayden', 'clark', 'yampa', 'craig', 'maybell',
                                 'fairplay', 'alma', 'como', 'jefferson', 'hartsel']],
    'elevate'    => ['osn' => 'ppmls',      'label' => 'Elevate',    'status' => 'live',    'office' => 'colorado-springs', 'aliases' => [], 'area' => 'front-range',
                     'towns' => ['colorado springs', 'monument', 'manitou springs', 'woodland park', 'fountain', 'peyton', 'falcon',
                                 'black forest', 'palmer lake']],
    'cren'       => ['osn' => 'cren',       'label' => 'CREN',       'status' => 'live',    'office' => 'aspen', 'aliases' => [], 'area' => 'southwest-colorado', 'towns' => []],
    'recolorado' => ['osn' => 'ccbr_idx',   'label' => 'REColorado', 'status' => 'live',    'office' => 'aspen', 'area' => 'front-range',
                     'aliases' => ['denver', 'remetrodenver'],
                     'towns' => ['denver', 'aurora', 'lakewood', 'littleton', 'centennial', 'englewood', 'arvada', 'westminster', 'thornton',
                                 'northglenn', 'broomfield', 'golden', 'wheat ridge', 'commerce city', 'brighton', 'parker', 'castle rock',
                                 'castle pines', 'highlands ranch', 'lone tree', 'greenwood village', 'cherry hills village', 'larkspur',
                                 'franktown', 'sedalia', 'elizabeth', 'kiowa', 'morrison', 'evergreen', 'conifer', 'bailey',
                                 'boulder', 'louisville', 'lafayette', 'superior', 'erie', 'longmont']],
];

/** Where the registry is fetched from ('' when neither constant is set). */
function mk_boards_url(): string {
    if (defined('SITE_BOARDS_URL') && SITE_BOARDS_URL !== '') return SITE_BOARDS_URL;
    if (defined('SITE_AGENT_SYNC_URL') && SITE_AGENT_SYNC_URL !== '') return preg_replace('~/[^/]*$~', '/boards.php', SITE_AGENT_SYNC_URL);
    return '';
}

/**
 * The site's JSON → [slug => entry], or a string saying what is wrong with it.
 * Strict on purpose: a half-formed payload must never replace a good cache.
 */
function mk_boards_parse(?array $d): array|string {
    if (!is_array($d['boards'] ?? null) || !$d['boards']) return 'no boards in the payload';
    $out = [];
    foreach ($d['boards'] as $b) {
        $slug = (string)($b['slug'] ?? '');
        if (!preg_match('/^[a-z0-9]{1,20}$/', $slug)) return "bad slug '{$slug}'";
        $label = trim((string)($b['label'] ?? ''));
        if ($label === '') return "board {$slug} has no label";
        $status = (string)($b['status'] ?? '');
        if (!in_array($status, ['live', 'pending'], true)) return "board {$slug} has status '{$status}'";
        $office = $b['office'] ?? null;
        if ($office !== null && !is_string($office)) return "board {$slug} has a bad office";
        foreach (['aliases', 'towns'] as $k) {
            if (!is_array($b[$k] ?? null)) return "board {$slug} has no {$k} list";
            foreach ($b[$k] as $v) if (!is_string($v)) return "board {$slug}: {$k} must be strings";
        }
        // `area` (2026-10-07): the Hot Sheet area an unlisted town of this
        // board falls into (the site's COMMUNITY_BOARD_AREA). Optional: a site
        // older than that day sends none, and the fallback below carries it.
        $area = $b['area'] ?? null;
        if ($area !== null && (!is_string($area) || !preg_match('/^[a-z0-9-]{0,60}$/', $area))) return "board {$slug} has a bad area";
        $out[$slug] = [
            'osn'     => strtolower(trim((string)($b['osn'] ?? $slug))),
            'label'   => $label,
            'status'  => $status,
            'office'  => $office === null || $office === '' ? null : $office,
            'aliases' => array_values(array_map('strval', $b['aliases'])),
            'towns'   => array_values(array_map(fn($t) => strtolower(trim($t)), $b['towns'])),
            'area'    => $area === null || $area === '' ? null : $area,
        ];
    }
    return $out;
}

/**
 * Fetch the registry from the site and (unless $write is false, the roster
 * sync's --dry-run) replace the cache. The parsed boards are used for the rest
 * of this request either way. Returns ['ok' => bool, 'message' => string].
 */
function mk_boards_refresh(bool $write = true): array {
    $url = mk_boards_url();
    if ($url === '') return ['ok' => false, 'message' => 'SITE_BOARDS_URL / SITE_AGENT_SYNC_URL is not set in inc/config.php'];
    if (!defined('AGENT_SYNC_TOKEN') || strlen((string)AGENT_SYNC_TOKEN) < 24) {
        return ['ok' => false, 'message' => 'AGENT_SYNC_TOKEN is missing from inc/db.php'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . AGENT_SYNC_TOKEN, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $code !== 200) return ['ok' => false, 'message' => "{$url}: " . ($err ?: "HTTP {$code}")];

    $d = json_decode($body, true);
    $boards = mk_boards_parse(is_array($d) ? $d : null);
    if (is_string($boards)) return ['ok' => false, 'message' => "{$url}: {$boards}"];

    mk_board_registry($boards);   // this request uses the fresh copy
    $areas = mk_areas_parse($d['areas'] ?? null);   // [] when the site is older than 2026-10-06
    if ($areas) mk_areas($areas);
    $n = count($boards);
    if (!$write) return ['ok' => true, 'message' => "{$n} boards from the site (cache not written: dry run)"];

    $json = json_encode(['fetched_at' => gmdate('Y-m-d\TH:i:s\Z'), 'generated_at' => $d['generated_at'] ?? null,
                         'boards' => $boards, 'areas' => $areas],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $tmp = BOARDS_CACHE_FILE . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) === false || !@chmod($tmp, 0644) || !@rename($tmp, BOARDS_CACHE_FILE)) {
        @unlink($tmp);
        return ['ok' => false, 'message' => "{$n} boards fetched but " . BOARDS_CACHE_FILE . ' is not writable; the old cache stays'];
    }
    return ['ok' => true, 'message' => "{$n} boards from the site → " . BOARDS_CACHE_FILE];
}

/**
 * [slug => entry]. The cache file when it exists and parses, else the
 * built-in fallback. Pass $set to replace it for this request (refresh does).
 */
function mk_board_registry(?array $set = null): array {
    static $reg = null;
    if ($set !== null) { $reg = $set; mk_board_names(true); mk_boards_source('site'); return $reg; }
    if ($reg !== null) return $reg;
    if (is_readable(BOARDS_CACHE_FILE)) {
        $d = json_decode((string)file_get_contents(BOARDS_CACHE_FILE), true);
        $parsed = is_array($d) && is_array($d['boards'] ?? null)
            ? mk_boards_parse(['boards' => array_map(fn($slug, $b) => $b + ['slug' => $slug], array_keys($d['boards']), $d['boards'])])
            : 'unreadable';
        if (is_array($parsed)) { mk_boards_source('cache'); return $reg = $parsed; }
    }
    mk_boards_source('fallback');
    return $reg = MK_BOARDS_FALLBACK;
}

/** Where this request's registry came from: 'site' (just fetched), 'cache' (the file) or 'fallback' (built-in copy). */
function mk_boards_source(?string $set = null): string {
    static $src = null;
    if ($set !== null) { $src = $set; return $src; }
    if ($src === null) mk_board_registry();
    return $src;
}

/** Seconds since the cache was last written, or null when it never was. */
function mk_boards_cache_age(): ?int {
    clearstatcache(true, BOARDS_CACHE_FILE);
    $t = @filemtime(BOARDS_CACHE_FILE);
    return $t ? max(0, time() - $t) : null;
}

/** Lower-case letters and digits only, as the site normalises board names. */
function mk_board_norm(string $osn): string {
    return substr(preg_replace('/[^a-z0-9]+/', '', strtolower($osn)), 0, 20);
}

/** Every spelling the registry knows (slug, OSN, alias) → slug. */
function mk_board_names(bool $reset = false): array {
    static $map = null;
    if ($reset) { $map = null; return []; }
    if ($map === null) {
        $map = [];
        foreach (mk_board_registry() as $slug => $b) {
            foreach (array_merge([$slug, $b['osn']], $b['aliases']) as $n) $map[mk_board_norm($n)] = $slug;
        }
    }
    return $map;
}

/** True when the registry has this board (by OSN, slug or alias). */
function mk_board_known(string $osn): bool {
    return isset(mk_board_names()[mk_board_norm($osn)]);
}

/**
 * Anyprop OriginatingSystemName → market slug. Must agree with the site's
 * mh_board_slug(), which is why it reads the same registry. An unregistered
 * board keeps its normalised OSN; the roster sync checks mk_board_known()
 * first and never stores one.
 */
function mk_market_slug(string $osn): string {
    $s = mk_board_norm($osn);
    return mk_board_names()[$s] ?? $s;
}

/** Short label for a market slug ("Altitude"); an unknown slug is shown capitalised. */
function mk_board_label(string $slug): string {
    return mk_board_registry()[$slug]['label'] ?? ucfirst($slug);
}

/** [slug => label] in registry order, for board pickers. */
function mk_board_labels(): array {
    return array_map(fn($b) => $b['label'], mk_board_registry());
}

/** Default office slug for a board's agents, when the row has none yet. */
function mk_market_office(string $slug): ?string {
    return mk_board_registry()[$slug]['office'] ?? null;
}

/** [town => slug]: when one property is on two boards, keep the copy from the board its town belongs to. */
function mk_board_towns(): array {
    $m = [];
    foreach (mk_board_registry() as $slug => $b) foreach ($b['towns'] as $t) $m[$t] = $slug;
    return $m;
}

/** Labels of the boards with status live, for alert emails. */
function mk_board_live_labels(): array {
    return array_values(array_map(fn($b) => $b['label'], array_filter(mk_board_registry(), fn($b) => $b['status'] === 'live')));
}

/**
 * The Hot Sheet area key a board's listings fall into when their town is not
 * in the Communities list, or null. A cache written before the site sent
 * `area` (2026-10-07) has none, so the built-in copy fills in until the next
 * roster sync rewrites it.
 */
function mk_board_area(string $slug): ?string {
    return mk_board_registry()[$slug]['area'] ?? MK_BOARDS_FALLBACK[$slug]['area'] ?? null;
}

/* ── Service areas (2026-10-06) ──────────────────────────────────────────────
 * The website's Communities list (its _communities.php), published by the
 * same api/boards.php as `areas` and cached in the same file. agent.php's
 * "Follow Up Boss" section renders it as the Service areas checkboxes; the
 * ticked town keys are stored in marketing_intakes.fub_areas and sent in the
 * roster feed, and the site gives a lead on another firm's listing to an
 * agent who ticked its town. A town's key is its label slugged.
 *
 *   mk_areas()       [['key' => area key, 'area' => name, 'towns' => [['key', 'label'], …]], …]
 *   mk_area_keys()   every town key, for validating a form post
 *
 * The Hot Sheets (2026-10-07) are one email per AREA of this same list, so
 * each area carries a key too (its name slugged, as the site makes it):
 *
 *   mk_area_names()        [area key => name], in the list's order
 *   mk_area_name($key)     "Vail Valley"
 *   mk_areas_decode($json) the area keys a stored JSON list holds that the list knows
 *   mk_town_area($town)    the area key a Communities town key belongs to
 *
 * MK_AREAS_FALLBACK is the list as of 2026-10-06, used only while the cache
 * carries no `areas` (before the first roster sync after that deploy). Edit
 * the list on the website, not here.
 */
const MK_AREAS_FALLBACK = [
    ['key' => 'roaring-fork-valley', 'area' => 'Roaring Fork Valley', 'towns' => [['key' => 'aspen', 'label' => 'Aspen'], ['key' => 'snowmass-village', 'label' => 'Snowmass Village'], ['key' => 'old-snowmass', 'label' => 'Old Snowmass'], ['key' => 'woody-creek', 'label' => 'Woody Creek'], ['key' => 'basalt', 'label' => 'Basalt'], ['key' => 'carbondale', 'label' => 'Carbondale'], ['key' => 'glenwood-springs', 'label' => 'Glenwood Springs']]],
    ['key' => 'vail-valley',         'area' => 'Vail Valley',         'towns' => [['key' => 'vail', 'label' => 'Vail'], ['key' => 'beaver-creek', 'label' => 'Beaver Creek'], ['key' => 'avon', 'label' => 'Avon'], ['key' => 'edwards', 'label' => 'Edwards'], ['key' => 'minturn', 'label' => 'Minturn'], ['key' => 'eagle', 'label' => 'Eagle'], ['key' => 'gypsum', 'label' => 'Gypsum']]],
    ['key' => 'summit-county',       'area' => 'Summit County',       'towns' => [['key' => 'breckenridge', 'label' => 'Breckenridge'], ['key' => 'keystone', 'label' => 'Keystone'], ['key' => 'copper-mountain', 'label' => 'Copper Mountain'], ['key' => 'frisco', 'label' => 'Frisco'], ['key' => 'dillon', 'label' => 'Dillon'], ['key' => 'silverthorne', 'label' => 'Silverthorne'], ['key' => 'steamboat-springs', 'label' => 'Steamboat Springs']]],
    ['key' => 'gunnison-valley',     'area' => 'Gunnison Valley',     'towns' => [['key' => 'crested-butte', 'label' => 'Crested Butte'], ['key' => 'gunnison', 'label' => 'Gunnison']]],
    ['key' => 'southwest-colorado',  'area' => 'Southwest Colorado',  'towns' => [['key' => 'telluride', 'label' => 'Telluride'], ['key' => 'mountain-village', 'label' => 'Mountain Village'], ['key' => 'ridgway', 'label' => 'Ridgway'], ['key' => 'ouray', 'label' => 'Ouray'], ['key' => 'montrose', 'label' => 'Montrose'], ['key' => 'durango', 'label' => 'Durango'], ['key' => 'pagosa-springs', 'label' => 'Pagosa Springs']]],
    ['key' => 'front-range',         'area' => 'Front Range',         'towns' => [['key' => 'greater-denver', 'label' => 'Greater Denver'], ['key' => 'boulder', 'label' => 'Boulder'], ['key' => 'evergreen', 'label' => 'Evergreen'], ['key' => 'colorado-springs', 'label' => 'Colorado Springs']]],
];

/** An area name slugged the way the site keys it ('Roaring Fork Valley' → 'roaring-fork-valley'). */
function mk_area_slug(string $name): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
}

/** The site's `areas` → the list above, or [] when it is missing or malformed (never a half list). */
function mk_areas_parse($v): array {
    if (!is_array($v) || !$v) return [];
    $out = [];
    foreach ($v as $a) {
        $name = trim((string)($a['area'] ?? ''));
        if ($name === '' || !is_array($a['towns'] ?? null) || !$a['towns']) return [];
        // The area key arrived with the 2026-10-07 site; an older payload gets the slug of the name, which is the same thing.
        $akey = (string)($a['key'] ?? mk_area_slug($name));
        if (!preg_match('/^[a-z0-9-]{1,60}$/', $akey)) return [];
        $towns = [];
        foreach ($a['towns'] as $t) {
            $key = (string)($t['key'] ?? ''); $label = trim((string)($t['label'] ?? ''));
            if (!preg_match('/^[a-z0-9-]{1,60}$/', $key) || $label === '') return [];
            $towns[] = ['key' => $key, 'label' => $label];
        }
        $out[] = ['key' => $akey, 'area' => $name, 'towns' => $towns];
    }
    return $out;
}

/** The area list: the cache when it carries one, else the built-in copy. Pass $set to replace it for this request. */
function mk_areas(?array $set = null): array {
    static $areas = null;
    if ($set !== null) { $areas = $set; return $areas; }
    if ($areas !== null) return $areas;
    if (is_readable(BOARDS_CACHE_FILE)) {
        $d = json_decode((string)file_get_contents(BOARDS_CACHE_FILE), true);
        $parsed = mk_areas_parse(is_array($d) ? ($d['areas'] ?? null) : null);
        if ($parsed) return $areas = $parsed;
    }
    return $areas = MK_AREAS_FALLBACK;
}

/** Every town key the list knows. */
function mk_area_keys(): array {
    $keys = [];
    foreach (mk_areas() as $a) foreach ($a['towns'] as $t) $keys[] = $t['key'];
    return $keys;
}

/** [area key => area name], in the list's order: the Hot Sheet areas. */
function mk_area_names(): array {
    $out = [];
    foreach (mk_areas() as $a) $out[$a['key']] = $a['area'];
    return $out;
}

/** "Vail Valley" for 'vail-valley'; an unknown key is shown as words. */
function mk_area_name(string $key): string {
    return mk_area_names()[$key] ?? ucwords(str_replace('-', ' ', $key));
}

/** The area keys a stored JSON list holds, in the list's order, dropping any the list no longer knows. */
function mk_areas_decode(?string $json): array {
    $v = json_decode((string)$json, true);
    if (!is_array($v)) return [];
    $have = array_map('strval', $v);
    return array_values(array_filter(array_keys(mk_area_names()), fn($k) => in_array($k, $have, true)));
}

/** The area key a Communities town key ('beaver-creek') belongs to, or null. */
function mk_town_area(string $town_key): ?string {
    foreach (mk_areas() as $a) foreach ($a['towns'] as $t) if ($t['key'] === $town_key) return $a['key'];
    return null;
}
