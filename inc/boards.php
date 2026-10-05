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
    'aspen'      => ['osn' => 'agsmls',     'label' => 'Aspen',      'status' => 'live',    'office' => 'aspen', 'aliases' => [],
                     'towns' => ['aspen', 'snowmass village', 'old snowmass', 'woody creek', 'basalt', 'carbondale', 'redstone', 'marble',
                                 'glenwood springs', 'new castle', 'silt', 'rifle', 'parachute', 'battlement mesa']],
    'vail'       => ['osn' => 'vbor',       'label' => 'Vail',       'status' => 'live',    'office' => 'vail', 'aliases' => [],
                     'towns' => ['vail', 'avon', 'beaver creek', 'bachelor gulch', 'arrowhead', 'edwards', 'singletree', 'cordillera', 'eagle',
                                 'eagle-vail', 'gypsum', 'minturn', 'red cliff', 'wolcott', 'mccoy', 'bond']],
    'telluride'  => ['osn' => 'tridemls',   'label' => 'Telluride',  'status' => 'live',    'office' => null, 'aliases' => [],
                     'towns' => ['telluride', 'mountain village', 'ophir', 'placerville', 'sawpit', 'norwood', 'rico']],
    'altitude'   => ['osn' => 'summit',     'label' => 'Altitude',   'status' => 'live',    'office' => null, 'aliases' => [],
                     'towns' => ['breckenridge', 'blue river', 'frisco', 'dillon', 'silverthorne', 'keystone', 'copper mountain', 'heeney',
                                 'steamboat springs', 'oak creek', 'hayden', 'clark', 'yampa']],
    'elevate'    => ['osn' => 'ppmls',      'label' => 'Elevate',    'status' => 'live',    'office' => 'colorado-springs', 'aliases' => [],
                     'towns' => ['colorado springs', 'monument', 'manitou springs', 'woodland park', 'fountain', 'peyton', 'falcon',
                                 'black forest', 'palmer lake']],
    'cren'       => ['osn' => 'cren',       'label' => 'CREN',       'status' => 'pending', 'office' => 'aspen', 'aliases' => [], 'towns' => []],
    'recolorado' => ['osn' => 'recolorado', 'label' => 'REColorado', 'status' => 'live',    'office' => 'aspen',
                     'aliases' => ['denver', 'remetrodenver'], 'towns' => []],
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
        $out[$slug] = [
            'osn'     => strtolower(trim((string)($b['osn'] ?? $slug))),
            'label'   => $label,
            'status'  => $status,
            'office'  => $office === null || $office === '' ? null : $office,
            'aliases' => array_values(array_map('strval', $b['aliases'])),
            'towns'   => array_values(array_map(fn($t) => strtolower(trim($t)), $b['towns'])),
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
    $n = count($boards);
    if (!$write) return ['ok' => true, 'message' => "{$n} boards from the site (cache not written: dry run)"];

    $json = json_encode(['fetched_at' => gmdate('Y-m-d\TH:i:s\Z'), 'generated_at' => $d['generated_at'] ?? null, 'boards' => $boards],
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
