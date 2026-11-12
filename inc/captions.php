<?php
/**
 * inc/captions.php — the Suggested Caption of a listing's Instagram post
 * (Nikki, 2026-11-12), admin only for now: the Property tool's Suggested
 * Caption box and the Captions page (social_captions.php) read and write it.
 *
 * The shape is Nikki's sample (633 W Main St):
 *
 *   Featured Listing: <short catchy name>        (a rental: Now Booking: …)
 *
 *   📍 address, town
 *   🏡 N Bedrooms, N Bathrooms
 *   📐 N SF                                       (land: N Acres)
 *   💵 $price                                     (a rental shows no rate)
 *
 *   the listing's description
 *
 *   ➡️ Get in touch with @handle or @handle for more information and to
 *   arrange your private showing.                 (a rental: … to book your stay.)
 *
 *   #five #hashtags                              (caption_tags(): one rule)
 *
 * One caption per listing, keyed "market|listing_key" like plan_listings():
 *   saved   what Nikki saved, in CREATIVES_DIR/social/captions.json (one
 *           JSON file, no table, like the Weekly Plans store)
 *   draft   the hand-written first drafts of 2026-11-12, inc/caption_drafts.json
 *           (the catchy names were written per listing; `notes` say what
 *           Nikki should look at; the file holds no hashtags since they
 *           became five by rule)
 *   built   anything else: caption_build() from the feed's fields, the site's
 *           description and the agents' Instagram handles on their profiles.
 *           Its headline is the short address until someone writes the name.
 *           Kept in the store's `built` so the site is asked once per listing.
 *
 * Who is tagged: the Mont Haus agent(s) on the listing through agent_mls_ids
 * (a team id credits every member, so a Weber Boxer Group sale tags Jonathan
 * and Scott and never the outside co-list agent); an agent with no Instagram
 * on the profile is named instead. A RENTAL of the Weber Boxer Group tags
 * Sara Perkowski alone (Nikki, 2026-11-12): CAPTION_TEAM_RENTAL below.
 */

const CAPTION_MAX = 2200;   // Instagram's caption limit
/** Rentals: these agents (the Weber Boxer Group's sales side) are not tagged; when that leaves nobody, CAPTION_TEAM_RENTAL_AGENT is. */
const CAPTION_TEAM_SALES_AGENTS = ['jonathan boxer', 'scott weber', 'weber boxer group'];
const CAPTION_TEAM_RENTAL_AGENT = 'sara perkowski';

function captions_path(): string {
    $dir = rtrim(CREATIVES_DIR, '/') . '/social';
    if (!is_dir($dir)) { @mkdir($dir, 02775, true); if (function_exists('social_open_dirs')) social_open_dirs($dir); }
    return $dir . '/captions.json';
}

/** The store: ['saved' => [id => [caption, saved_at, by]], 'built' => [id => [caption, built_at]]]. */
function captions_store(): array {
    $j = json_decode((string)@file_get_contents(captions_path()), true);
    return ['saved' => (array)($j['saved'] ?? []), 'built' => (array)($j['built'] ?? [])];
}

/** Change the store under a lock: $fn takes it by reference. */
function captions_update(callable $fn): bool {
    $fh = @fopen(captions_path(), 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $j = json_decode((string)stream_get_contents($fh), true);
    $store = ['saved' => (array)($j['saved'] ?? []), 'built' => (array)($j['built'] ?? [])];
    $fn($store);
    ftruncate($fh, 0); rewind($fh);
    $ok = fwrite($fh, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    return $ok;
}

/** The first drafts: [id => [caption, notes[]]]. */
function captions_drafts(): array {
    static $d = null;
    if ($d === null) { $j = json_decode((string)@file_get_contents(__DIR__ . '/caption_drafts.json'), true); $d = (array)($j['captions'] ?? []); }
    return $d;
}

function caption_id_ok(string $id): bool { return (bool)preg_match('/^[a-z0-9_]{1,30}\|[A-Za-z0-9_.-]{1,80}$/', $id); }

/** Line ends as \n, no trailing space, at most CAPTION_MAX characters. */
function caption_clean(string $text): string {
    $t = str_replace(["\r\n", "\r"], "\n", $text);
    $t = preg_replace('/[ \t]+\n/', "\n", $t);
    return mb_substr(trim($t), 0, CAPTION_MAX);
}

function caption_save(string $id, string $text, string $by): bool {
    $text = caption_clean($text);
    return captions_update(function (array &$s) use ($id, $text, $by) {
        if ($text === '') unset($s['saved'][$id]);
        else $s['saved'][$id] = ['caption' => $text, 'saved_at' => gmdate('c'), 'by' => $by];
    });
}

/** Drop the saved caption: the listing is back on its draft (or the built one). */
function caption_reset(string $id): bool {
    return captions_update(function (array &$s) use ($id) { unset($s['saved'][$id], $s['built'][$id]); });
}

/** A profile's Instagram as a bare handle: the URL, the @ and a trailing slash go. '' when it is not a handle. */
function caption_handle(string $raw): string {
    $h = trim($raw);
    $h = preg_replace('~^https?://(www\.)?instagram\.com/~i', '', $h);
    $h = trim(explode('?', $h)[0], "/@ \t");
    return preg_match('/^[A-Za-z0-9._]{1,30}$/', $h) ? strtolower($h) : '';
}

/** The roster by board identity: ["market|mls_agent_id" => [[name, handle], …]] (a team id carries every member). */
function caption_roster(mysqli $conn): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    $r = $conn->query("SELECT a.market, a.mls_agent_id, mi.agent_name, mi.social_instagram
                         FROM agent_mls_ids a JOIN marketing_intakes mi ON mi.id = a.intake_id
                        WHERE a.mls_agent_id <> '' AND mi.agent_name <> '' ORDER BY mi.agent_name");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $x) {
        $map[$x['market'] . '|' . $x['mls_agent_id']][] = ['name' => trim((string)$x['agent_name']), 'handle' => caption_handle((string)$x['social_instagram'])];
    }
    return $map;
}

/** Who the caption names: ['@handle' or 'Full Name', …], our agents only, list side first. */
function caption_people(mysqli $conn, array $l): array {
    $roster = caption_roster($conn);
    $people = [];
    foreach (['list_agent_mls_id', 'colist_agent_mls_id'] as $col) {
        foreach ($roster[$l['market'] . '|' . (string)($l[$col] ?? '')] ?? [] as $p) $people[strtolower($p['name'])] = $p;
    }
    if ((int)($l['is_rental'] ?? 0)) {
        $rest = array_diff_key($people, array_flip(CAPTION_TEAM_SALES_AGENTS));
        if (count($rest) < count($people)) {
            $people = $rest;
            if (!$people) {
                foreach ($roster as $list) foreach ($list as $p) if (strtolower($p['name']) === CAPTION_TEAM_RENTAL_AGENT) $people[CAPTION_TEAM_RENTAL_AGENT] = $p;
            }
        }
    }
    $out = [];
    foreach ($people as $p) $out[] = $p['handle'] !== '' ? '@' . $p['handle'] : $p['name'];
    return array_values(array_unique($out));
}

/** The listing's public description from the site (the photos scope carries it), '' when the site does not answer. */
function caption_remarks(string $market, string $key): string {
    if (!defined('LISTINGS_FEED_URL') || !defined('LISTINGS_FEED_TOKEN') || (string)LISTINGS_FEED_TOKEN === '') return '';
    $ch = curl_init(LISTINGS_FEED_URL . '?scope=photos&market=' . rawurlencode($market) . '&key=' . rawurlencode($key));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . LISTINGS_FEED_TOKEN, 'Accept: application/json']]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = ($code === 200 && $body) ? json_decode($body, true) : null;
    return trim(preg_replace('/\s+/', ' ', (string)($j['remarks'] ?? '')));
}

/**
 * The caption's hashtags: FIVE, always (Nikki, 2026-11-12: Instagram now
 * limits a caption to five; "focus on the town name and relevant tags").
 * One rule for every caption, from the listing's own facts:
 *   a sale    #town #townrealestate #coloradorealestate
 *             + #landforsale (land) | #luxuryrealestate (CAPTION_LUXURY_FROM
 *               and up) | #homeforsale
 *             + #justlisted (while the feed calls it new) | #skihome (a ski
 *               town, not land) | #mountainlife
 *   a closing #town #townrealestate #coloradorealestate #justsold #mountainlife
 *   a rental  #town #townrental #vacationrental
 *             + #skihome (a ski town) | #coloradorental, then #mountainlife
 * The town is the listing's city, letters only (Snowmass Village reads
 * #snowmass). No #sellersrep, no per-listing tags: five leaves no room.
 */
const CAPTION_LUXURY_FROM = 2000000;
const CAPTION_TOWN_TAGS = ['snowmassvillage' => 'snowmass'];
const CAPTION_SKI_TOWNS = ['aspen', 'snowmass', 'vail', 'avon', 'beavercreek', 'edwards', 'minturn', 'telluride', 'mountainvillage', 'breckenridge', 'frisco',
                           'silverthorne', 'dillon', 'keystone', 'coppermountain', 'steamboatsprings', 'crestedbutte', 'mtcrestedbutte', 'winterpark'];
function caption_tags(array $l): string {
    $rental = (int)($l['is_rental'] ?? 0) === 1;
    $closed = !empty($l['closed']);
    $land   = stripos((string)($l['property_type'] ?? ''), 'land') !== false;
    $new    = !$closed && (!empty($l['new']) || (int)($l['is_new'] ?? 0) === 1);
    $town   = strtolower(preg_replace('/[^A-Za-z]/', '', (string)($l['city'] ?? '')));
    $town   = CAPTION_TOWN_TAGS[$town] ?? $town;
    if ($town === '') $town = 'colorado';
    $ski    = in_array($town, CAPTION_SKI_TOWNS, true);
    if ($rental)     $tags = [$town, $town . 'rental', 'vacationrental', $ski ? 'skihome' : 'coloradorental', 'mountainlife'];
    elseif ($closed) $tags = [$town, $town . 'realestate', 'coloradorealestate', 'justsold', 'mountainlife'];
    else             $tags = [$town, $town . 'realestate', 'coloradorealestate',
                              $land ? 'landforsale' : ((float)($l['list_price'] ?? 0) >= CAPTION_LUXURY_FROM ? 'luxuryrealestate' : 'homeforsale'),
                              $new ? 'justlisted' : (($ski && !$land) ? 'skihome' : 'mountainlife')];
    $tags = array_values(array_unique($tags));   // a listing with no city: #colorado, and the pair it would repeat
    if (count($tags) < 5) $tags[] = 'coloradohomes';
    return '#' . implode(' #', $tags);
}

/** The caption with its closing hashtag lines (if any) replaced by the listing's five. Hashtags inside the words ("Unit #4") are left alone. */
function caption_retag(string $caption, array $l): string {
    $lines = explode("\n", rtrim(str_replace("\r", '', $caption)));
    while ($lines && (trim(end($lines)) === '' || preg_match('/^(#[\p{L}\p{N}_]+\s*)+$/u', trim(end($lines))))) array_pop($lines);
    return ($lines ? implode("\n", $lines) . "\n\n" : '') . caption_tags($l);
}

/** A caption from the feed's fields. $remarks: the description ('' = none). The headline's name is the short address until someone writes one. */
function caption_build(mysqli $conn, array $l, string $remarks = ''): string {
    $rental = (int)($l['is_rental'] ?? 0) === 1;
    $closed = !empty($l['closed']);
    $land   = stripos((string)($l['property_type'] ?? ''), 'land') !== false;
    $addr   = trim((string)($l['address'] ?? ''));
    $city   = trim((string)($l['city'] ?? ''));
    $short  = $addr !== '' ? social_short_address($addr) : $city;
    $head   = ($closed ? ($rental ? 'Just Leased: ' : 'Just Sold: ') : ($rental ? 'Now Booking: ' : 'Featured Listing: ')) . $short;
    $n = fn($v, string $w) => rtrim(rtrim(number_format((float)$v, 1), '0'), '.') . ' ' . $w . ((float)$v == 1.0 ? '' : 's');
    $lines = ["\u{1F4CD} " . implode(', ', array_filter([$short !== $city ? $short : '', $city]))];
    if (!$land && (float)($l['beds'] ?? 0) > 0) $lines[] = "\u{1F3E1} " . $n($l['beds'], 'Bedroom') . ((float)($l['baths'] ?? 0) > 0 ? ', ' . $n($l['baths'], 'Bathroom') : '');
    if (!$land && (int)($l['sqft'] ?? 0) > 0)   $lines[] = "\u{1F4D0} " . number_format((int)$l['sqft']) . ' SF';
    if (!$rental && !$closed && (float)($l['list_price'] ?? 0) > 0) $lines[] = "\u{1F4B5} $" . number_format((float)$l['list_price']);
    $who = caption_people($conn, $l);
    if (!$who && trim((string)($l['agent'] ?? '')) !== '') $who = [trim((string)$l['agent'])];
    $cta = "\u{27A1}\u{FE0F} Get in touch with " . ($who ? implode(' or ', $who) : 'Mont Haus International Realty')
         . ($rental ? ' for availability and to book your stay.' : ' for more information and to arrange your private showing.');
    $tags = caption_tags($l);
    $top = $head . "\n\n" . implode("\n", $lines) . "\n\n";
    $end = $cta . "\n\n" . $tags;
    $room = CAPTION_MAX - mb_strlen($top) - mb_strlen($end) - 2;
    if (mb_strlen($remarks) > $room) {   // keep whole sentences
        $cut = mb_substr($remarks, 0, max(0, $room));
        $remarks = preg_match('/^(.*[.!?])(?=\s|$)/su', $cut, $m) ? $m[1] : rtrim($cut);
    }
    return $top . ($remarks !== '' ? $remarks . "\n\n" : '') . $end;
}

/**
 * The listing's caption: ['caption', 'source' => saved | draft | built, 'notes' => […], 'saved_at'].
 * $fetch: a built caption may ask the site for the description (once; kept in the store).
 */
function caption_for(mysqli $conn, array $l, bool $fetch = true, ?array $store = null): array {
    $id = (string)$l['id'];
    $store = $store ?? captions_store();
    $draft = captions_drafts()[$id] ?? null;
    $notes = (array)($draft['notes'] ?? []);
    if (isset($store['saved'][$id]['caption'])) {
        return ['caption' => (string)$store['saved'][$id]['caption'], 'source' => 'saved', 'notes' => $notes, 'saved_at' => (string)($store['saved'][$id]['saved_at'] ?? '')];
    }
    // A draft's and a built caption's hashtags are the rule's, read now (#justlisted comes and goes with the feed); a saved caption is Nikki's, as she left it.
    if ($draft && empty($l['closed'])) return ['caption' => caption_retag((string)$draft['caption'], $l), 'source' => 'draft', 'notes' => $notes, 'saved_at' => ''];
    if (isset($store['built'][$id]['caption']) && empty($l['closed'])) return ['caption' => caption_retag((string)$store['built'][$id]['caption'], $l), 'source' => 'built', 'notes' => ['Built from the feed: the headline needs its catchy name'], 'saved_at' => ''];
    $remarks = $fetch ? caption_remarks((string)$l['market'], (string)$l['listing_key']) : '';
    $cap = caption_build($conn, $l, $remarks);
    if ($remarks !== '' && empty($l['closed'])) captions_update(function (array &$s) use ($id, $cap) { $s['built'][$id] = ['caption' => $cap, 'built_at' => gmdate('c')]; });
    return ['caption' => $cap, 'source' => 'built', 'notes' => [empty($l['closed']) ? 'Built from the feed: the headline needs its catchy name' : 'Closed: built from the feed'], 'saved_at' => ''];
}
