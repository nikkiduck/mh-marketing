<?php
/**
 * inc/video_ai.php — Suggest Texts for a listing reel (Nikki, 2026-11-27:
 * "integrate our Anthropic API into this video template, just for the
 * Listing reels ... creates snippets from Listing description and details in
 * MLS"). One request to the Claude API writes a few words for each photo
 * after the cover, from the listing's public description, its details and
 * the photos themselves (the model is shown each photo, so the text on the
 * kitchen is about the kitchen).
 *
 *   video_ai_enabled()        a key, and the client library on disk
 *   video_listing_facts()     the description and details, from the site's feed
 *   video_ai_photo()          one photo as the model takes it (a small JPEG)
 *   video_ai_suggest()        the request: [photo number => text]
 *
 * The client is Anthropic's official PHP SDK. It is NOT vendored here: MH
 * Send carries it (send.monthaus.com/vendor, on the same server, beside this
 * folder), and this file loads that copy. The key is ANTHROPIC_API_KEY in
 * inc/db.php (the same "mh-ai-key" MH Send uses). Model, effort and the
 * refusal fallbacks follow MH Send's inc/ai.php; the rules of the house
 * voice and Fair Housing below are that file's, rewritten for a few words on
 * a photo. A change to those rules is made in both.
 *
 * What goes to the model: the listing's PUBLIC remarks and details, and the
 * photos picked. Never an agent's or a client's details. What comes back is
 * a suggestion in a text box the person edits or clears; nothing is saved.
 *
 * No table: there is no usage log or daily cap on this side yet (the tool is
 * super admin only). Each request writes one line to the PHP error log.
 * Both are wanted before the agents' side opens.
 */

const VIDEO_AI_MODEL  = 'claude-opus-5-5';
const VIDEO_AI_EFFORT = 'medium';
const VIDEO_AI_TEXT_MAX = 70;     // characters of one photo's text as it is kept
const VIDEO_AI_PHOTO_PX = 800;    // the width of the copy of each photo the model is shown

const VIDEO_AI_SYSTEM = <<<'TXT'
You write the short on-screen text for Instagram reels made by Mont Haus International Realty, a luxury real estate brokerage in the Colorado mountains (Aspen, Vail, Telluride and beyond). A reel shows a listing's photos one after another, each for about three seconds, with a few words centered over the photo. The cover (photo 1) already carries the address, the town, the price and the bed, bath and square foot counts. You write the words for the photos after it. The broker will read your suggestions, change them and decide what to use.

Facts
- Use only two sources: the listing's description and details as given, and what is plainly visible in the photo the text sits on. Never add a feature, a view, a distance, a figure, a brand, a date or a claim that is in neither.
- Tie each text to its own photo. If the description names something the photo shows (the kitchen, the deck, the ski access), say that. If the photo shows something the description does not mention, say plainly what is there and no more.
- Keep every name and number exactly as the listing gives it.

Fair Housing
- Describe the property, the land, the views, the features and the location. Never describe who lives somewhere, who a home is "perfect for", or the character of a neighborhood's residents.
- Avoid words that suggest a preference for or against any group: family status, age, religion, race, national origin, disability, sex. Common traps: "family-friendly", "great for families", "safe neighborhood", "exclusive community", "walking distance", "master bedroom" (say "primary bedroom").

Voice
- Each text is two to seven words and must be readable in two seconds: aim for 40 characters or fewer, never more than 60.
- One idea for each photo. A phrase, not a sentence: no period at the end, sentence case, no all-caps words.
- No exclamation marks, no emoji, no hashtags, no quotation marks. No em dashes; use a comma.
- Avoid stock real estate phrases: "nestled", "boasts", "stunning", "dream home", "hidden gem", "one of a kind", "luxury living", "don't miss", "welcome home".
- Do not repeat the address, the price or the bed, bath and square foot counts: the cover has them.
- Read in order, the texts should feel like one short walk through the property: do not lean on the same word twice.

Reply only in the JSON format requested.
TXT;

/** Where the SDK's autoloader is: MH Send's vendor folder, beside this project (MH_AI_AUTOLOAD overrides it). */
/**
 * How many times this person has asked for suggestions today; $add counts one
 * more. The agents' side stops at VIDEO_AI_DAILY a day each (every request is
 * a paid one). One small file in the person's video folder ({d, n}), no table.
 */
const VIDEO_AI_DAILY = 30;
function video_ai_used(array $ctx, bool $add = false): int {
    if (($dir = video_root($ctx)) === '') return 0;
    $f = $dir . '/suggest.json';
    $j = json_decode((string)@file_get_contents($f), true);
    $n = (is_array($j) && ($j['d'] ?? '') === date('Y-m-d')) ? (int)($j['n'] ?? 0) : 0;
    if ($add) { $n++; @file_put_contents($f, json_encode(['d' => date('Y-m-d'), 'n' => $n]), LOCK_EX); @chmod($f, 0664); }
    return $n;
}

function video_ai_autoload(): string {
    return defined('MH_AI_AUTOLOAD') ? (string)MH_AI_AUTOLOAD : __DIR__ . '/../../send.monthaus.com/vendor/autoload.php';
}

/** True when a key is configured and the client library is on disk. */
function video_ai_enabled(): bool {
    return defined('ANTHROPIC_API_KEY') && (string)ANTHROPIC_API_KEY !== '' && is_file(video_ai_autoload());
}

/**
 * What the listing says of itself: ['remarks' => the public description,
 * 'details' => [label => value]]. The description, the lot and the year
 * built come from the site's feed (api/listings.php?scope=detail, by MLS
 * number); the rest is the row this portal already holds. With the feed
 * down, the remarks kept in the session when the photos were listed
 * (social_photos()) stand in.
 */
function video_listing_facts(array $l): array {
    $remarks = ''; $extra = [];
    if (defined('LISTINGS_FEED_URL') && defined('LISTINGS_FEED_TOKEN') && (string)LISTINGS_FEED_TOKEN !== '' && trim((string)$l['mls_id']) !== '') {
        $ch = curl_init(LISTINGS_FEED_URL . '?scope=detail&market=' . rawurlencode((string)$l['market']) . '&mls_ids=' . rawurlencode(trim((string)$l['mls_id'])));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . LISTINGS_FEED_TOKEN, 'Accept: application/json']]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = ($code === 200 && $body) ? json_decode($body, true) : null;
        foreach ((array)($j['listings'] ?? []) as $d) {
            if ((string)($d['listing_key'] ?? '') !== (string)$l['listing_key']) continue;   // the number can match a twin; only this listing's own record
            $remarks = trim((string)($d['remarks'] ?? ''));
            if ((float)($d['lot_size_acres'] ?? 0) > 0) $extra['Lot'] = rtrim(rtrim(number_format((float)$d['lot_size_acres'], 2), '0'), '.') . ' acres';
            if ((int)($d['year_built'] ?? 0) > 0) $extra['Year built'] = (string)(int)$d['year_built'];
            if (trim((string)($d['subdivision'] ?? '')) !== '') $extra['Subdivision'] = trim((string)$d['subdivision']);
            if (trim((string)($d['property_class'] ?? '')) !== '') $extra['Kind'] = trim((string)$d['property_class']);
        }
    }
    if ($remarks === '') $remarks = trim((string)($_SESSION['social_remarks'][$l['market'] . '|' . $l['listing_key']] ?? ''));
    $details = array_filter([
        'Address' => trim((string)$l['address']),
        'Town' => trim((string)$l['city']),
        'Status' => trim((string)$l['status']),
        'Type' => trim((string)$l['property_type']) . ((int)$l['is_rental'] ? ' (a rental)' : ''),
        'Bedrooms' => (float)$l['beds'] > 0 ? (string)(0 + $l['beds']) : '',
        'Bathrooms' => (float)$l['baths'] > 0 ? (string)(0 + $l['baths']) : '',
        'Square feet' => (int)$l['sqft'] > 0 ? number_format((int)$l['sqft']) : '',
    ] + $extra, 'strlen');
    return ['remarks' => mb_substr($remarks, 0, 6000), 'details' => $details];
}

/**
 * One photo as the model is shown it: ['mediaType' => 'image/jpeg', 'data' =>
 * base64], or null when it cannot be had. A board photo is the site's
 * VIDEO_AI_PHOTO_PX copy (img.php), fetched here; an upload is its 400 px
 * thumbnail on disk. $photo is the pair plan_photo() gives, $id the posted id.
 */
function video_ai_photo(array $ctx, string $id, array $photo): ?array {
    [$url, $file] = $photo;
    $bytes = '';
    if (str_starts_with($id, 'upload:') && ($t = social_upload_file($ctx, substr($id, 7), true))) $bytes = (string)@file_get_contents($t);
    elseif ($file === null && preg_match('#^https://#', (string)$url)) {
        $ch = curl_init(str_replace('&w=1600', '&w=' . VIDEO_AI_PHOTO_PX, (string)$url));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
        $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200 && is_string($b)) $bytes = $b;
    }
    if ($bytes === '' || strlen($bytes) > 3 * 1048576) return null;
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
    return ['mediaType' => $mime, 'data' => base64_encode($bytes)];
}

/**
 * The request: a text for each photo after the cover.
 *   $facts   video_listing_facts()
 *   $photos  the photos in play order, the cover first: each video_ai_photo() or null
 *   $avoid   texts already suggested and passed over ("suggest again")
 * Returns [photo's place (1 = the second photo) => text]. Throws
 * RuntimeException with a message fit to show.
 */
function video_ai_suggest(array $facts, array $photos, array $avoid = []): array {
    if (!video_ai_enabled()) throw new RuntimeException('Text suggestions are not switched on.');
    $n = count($photos);
    if ($n < 2) throw new RuntimeException('Pick at least two photos: the cover has its own text.');
    if ($facts['remarks'] === '' && count($facts['details']) < 3) throw new RuntimeException('This listing has no description in the feed to draw on.');

    $content = [];
    foreach (array_values($photos) as $i => $p) {
        $content[] = ['type' => 'text', 'text' => 'Photo ' . ($i + 1) . ($i === 0 ? ' (the cover, which has its own text):' : ':') . ($p ? '' : ' (this photo could not be loaded; write from the description alone)')];
        if ($p) $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $p['mediaType'], 'data' => $p['data']]];
    }
    $lines = [];
    foreach ($facts['details'] as $k => $v) $lines[] = "- $k: $v";
    $seen = array_slice(array_values(array_filter(array_map(fn($t) => mb_substr(trim(preg_replace('/\s+/', ' ', (string)(is_scalar($t) ? $t : ''))), 0, 120), $avoid), 'strlen')), -40);
    $content[] = ['type' => 'text', 'text' =>
        "The listing's details:\n" . implode("\n", $lines) . "\n\n"
        . ($facts['remarks'] !== '' ? "The listing's public description:\n" . $facts['remarks'] . "\n\n" : "The listing has no written description: write from the details and the photos alone.\n\n")
        . 'Write the on-screen text for photos 2 to ' . $n . ', one text for each, in order.'
        . ($seen ? "\n\nThe broker has already been shown the texts below and wants different ones. Come at each photo another way; do not reword these.\n- " . implode("\n- ", $seen) : '')
        . "\n\n" . 'Return {"texts": [{"photo": 2, "text": ...}, ...]} with exactly one entry for each photo from 2 to ' . $n . '.'];
    $schema = ['type' => 'object', 'properties' => ['texts' => ['type' => 'array', 'items' => ['type' => 'object',
        'properties' => ['photo' => ['type' => 'integer'], 'text' => ['type' => 'string']], 'required' => ['photo', 'text'], 'additionalProperties' => false]]],
        'required' => ['texts'], 'additionalProperties' => false];

    require_once video_ai_autoload();
    @set_time_limit(180);
    try {
        $client = new \Anthropic\Client(apiKey: (string)ANTHROPIC_API_KEY, baseUrl: defined('MH_AI_BASE_URL') ? (string)MH_AI_BASE_URL : null, requestOptions: ['timeout' => 150.0, 'maxRetries' => 2]);
        // The beta endpoint, for server-side fallbacks: a request the model declines for policy
        // reasons is tried on a substitute model instead of coming back as a refusal (as MH Send does).
        $message = $client->beta->messages->create(
            maxTokens: 16000,
            messages: [['role' => 'user', 'content' => $content]],
            model: VIDEO_AI_MODEL,
            system: VIDEO_AI_SYSTEM,
            outputConfig: ['effort' => VIDEO_AI_EFFORT, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
    } catch (\Anthropic\Core\Exceptions\AuthenticationException | \Anthropic\Core\Exceptions\PermissionDeniedException $e) {
        error_log('video_ai: key refused: ' . $e->getMessage());
        throw new RuntimeException('Text suggestions are not set up correctly: the API key was refused. Please tell the marketing team.');
    } catch (\Anthropic\Core\Exceptions\RateLimitException | \Anthropic\Core\Exceptions\OverloadedException $e) {
        throw new RuntimeException('The writing assistant is busy right now. Try again in a minute.');
    } catch (\Anthropic\Core\Exceptions\APIConnectionException $e) {
        throw new RuntimeException('The writing assistant could not be reached. Try again in a minute.');
    } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
        error_log('video_ai: ' . get_class($e) . ': ' . $e->getMessage());
        throw new RuntimeException('The writing assistant returned an error. It has been logged; try again, or write the texts by hand.');
    }
    error_log(sprintf('video_ai: suggest, %d photos, %d tokens in, %d out, %s', $n, (int)$message->usage->inputTokens, (int)$message->usage->outputTokens, (string)$message->stopReason));

    if ($message->stopReason === 'refusal') throw new RuntimeException('The writing assistant declined that request. Write these texts by hand.');
    $text = '';
    foreach ($message->content as $block) if ($block->type === 'text') { $text = $block->text; break; }
    $data = json_decode($text, true);
    if ($message->stopReason === 'max_tokens' || !is_array($data) || !is_array($data['texts'] ?? null)) throw new RuntimeException('The answer came back incomplete. Try again.');

    // House rules applied to whatever came back: one line, no em dash, no closing period, no quotation marks, the length kept.
    $out = [];
    foreach ($data['texts'] as $t) {
        $at = (int)($t['photo'] ?? 0) - 1;
        if ($at < 1 || $at >= $n || isset($out[$at])) continue;
        $s = trim(preg_replace('/\s+/u', ' ', (string)($t['text'] ?? '')));
        $s = preg_replace(['/\s*\x{2014}\s*/u', '/\s+\x{2013}\s+/u'], ', ', $s);
        $s = rtrim(trim($s, " \"\u{201C}\u{201D}"), '.!');
        if ($s !== '') $out[$at] = mb_substr($s, 0, VIDEO_AI_TEXT_MAX);
    }
    if (!$out) throw new RuntimeException('The answer was not in the expected form. Try again.');
    ksort($out);
    return $out;
}
