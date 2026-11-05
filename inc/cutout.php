<?php
/**
 * inc/cutout.php — a broker's CUTOUT: the headshot with its background
 * removed, the figure the Social Media Plan's Broker Feature stands in
 * front of a backdrop (inc/plan_agent_render.php). Nikki, 2026-11-05: "when
 * I upload a new headshot for a broker, a cutout version is automatically
 * created and assigned to that broker, whether new or existing".
 *
 * The first 26 were made by hand on 2026-11-02 (Adobe's background removal
 * in a Claude session) as agent-photos/<slug>-cutout.png. From now on the
 * cutout is named after the headshot's own file, agent-photos/<headshot
 * name>-cutout.png (the headshot is <slug>-<row id>.jpg since 2026-11-04),
 * and made by Leonardo.Ai's API (Canva; remove.bg's standalone API closes
 * on 2026-12-01 and its own migration guide points here:
 * docs.leonardo.ai/docs/migrate-from-the-removebg-api-to-leonardoai). One
 * synchronous call, POST /api/rest/v2/generationssync with model
 * "remove-bg", the headshot as base64, a PNG back as base64; USD 0.1047 a
 * picture, pay as you go; the key is LEONARDO_API_KEY in inc/db.php beside
 * the other keys. Without the key nothing is made and the Broker Feature
 * falls back to the plain headshot.
 *
 *   mk_cutout_find()     the agent's cutout file, by the headshot's name,
 *                        else the 2026-11-02 one by slug; '' when none
 *   mk_cutout_refresh()  a new headshot: the old cutouts go (never a stale
 *                        one of the previous photo), the new one is made
 *   mk_cutout_make()     the provider call for one headshot file
 *   mk_cutout_drop()     remove the agent's cutouts
 *   mk_cutout_words()    a result as a sentence for the profile's flash
 *
 * Called from agent.php (the photo save, and the Make cutout button),
 * cron/import_profiles.php (imported headshots) and cron/make_cutouts.php
 * (the backfill and retry, by hand). MK_CUTOUT_ENDPOINT, when defined,
 * replaces the provider's URL (a test stub). Needs inc/photos.php
 * (mk_photo_file(), mk_photo_dir(), mk_photo_base()).
 */

const MK_CUTOUT_ENDPOINT_DEFAULT = 'https://cloud.leonardo.ai/api/rest/v2/generationssync';

/** The cutout's path for a headshot file: the same name with -cutout.png. */
function mk_cutout_path_for(string $headshotFile): string {
    $b = pathinfo($headshotFile, PATHINFO_FILENAME);
    return $b === '' ? '' : dirname($headshotFile) . '/' . $b . '-cutout.png';
}

/** The agent's cutout file: by the headshot's name, else the 2026-11-02 one by slug; '' when there is none. */
function mk_cutout_find(string $headshotUrl, string $slug): string {
    $f = mk_photo_file($headshotUrl);
    if ($f !== '') { $c = mk_cutout_path_for($f); if (is_file($c)) return $c; }
    if ($slug !== '') { $c = mk_photo_dir() . '/' . $slug . '-cutout.png'; if (is_file($c)) return $c; }
    return '';
}

/** The URL this portal serves a cutout file at, versioned by its time. */
function mk_cutout_url(string $file): string {
    return mk_photo_base() . '/' . basename($file) . '?v=' . (@filemtime($file) ?: time());
}

/** Remove the agent's cutouts: the one named after this headshot, and the slug one from 2026-11-02. */
function mk_cutout_drop(string $headshotUrl, string $slug): void {
    $f = mk_photo_file($headshotUrl);
    if ($f !== '' && ($c = mk_cutout_path_for($f)) !== '' && is_file($c)) @unlink($c);
    if ($slug !== '' && is_file($c = mk_photo_dir() . '/' . $slug . '-cutout.png')) @unlink($c);
}

/**
 * Make the cutout for one headshot file through the provider. Returns
 * ['ok' => true, 'file', 'url', 'bytes'] or ['error' => why]. Never throws;
 * a failure leaves no file, so the Broker Feature uses the headshot.
 */
function mk_cutout_make(string $headshotFile): array {
    if (!defined('LEONARDO_API_KEY') || LEONARDO_API_KEY === '') return ['error' => 'no LEONARDO_API_KEY in inc/db.php yet'];
    if (!is_file($headshotFile)) return ['error' => 'the headshot file is not on this server'];
    if (!function_exists('curl_init')) return ['error' => 'PHP curl is not installed'];
    $out = mk_cutout_path_for($headshotFile);
    if ($out === '') return ['error' => 'the headshot has no file name'];
    $bytes = (string)file_get_contents($headshotFile);
    if ($bytes === '') return ['error' => 'the headshot file is empty'];
    // Leonardo's sync call: the image as base64 in, the PNG as base64 out, nothing kept in their library (ephemeral).
    $body = json_encode([
        'model' => 'remove-bg', 'ephemeral' => true, 'public' => false, 'base64' => true,   // public is required in practice ("public must be boolean", 2026-11-05), whatever the guide says
        'parameters' => ['size' => 'auto', 'format' => 'png', 'type' => 'person',   // auto: the full resolution the plan allows; person: a portrait
                         'guidances' => ['image_reference' => [['image' => ['type' => 'BASE64', 'data' => base64_encode($bytes)]]]]],
    ]);
    $ch = curl_init(defined('MK_CUTOUT_ENDPOINT') ? MK_CUTOUT_ENDPOINT : MK_CUTOUT_ENDPOINT_DEFAULT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . LEONARDO_API_KEY, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => $body,
    ]);
    $reply = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($reply === false) { error_log("cutout: request failed for " . basename($headshotFile) . ": {$err}"); return ['error' => 'the request to Leonardo.Ai failed: ' . $err]; }
    $j = json_decode((string)$reply, true);
    // Errors come back as HTTP 200 as often as a 4xx, and as a bare list of error objects ([{message, extensions}],
    // seen 2026-11-05) or an errors[] key: read the body first, either shape. A success sits under generateSync
    // ({"generateSync": {id, cost, results}}; the guide shows it unwrapped).
    $errs = is_array($j) ? (isset($j[0]['message']) ? $j : (array)($j['errors'] ?? [])) : [];
    if (is_array($j) && isset($j['generateSync']) && is_array($j['generateSync'])) $j = $j['generateSync'];
    if (!is_array($j) || $errs || $code !== 200 || empty($j['results'])) {
        $why = (string)($errs[0]['message'] ?? $j['error'] ?? $j['message'] ?? ('HTTP ' . $code . ', no results'));
        error_log("cutout: Leonardo.Ai said {$why} for " . basename($headshotFile));
        return ['error' => 'Leonardo.Ai: ' . $why];
    }
    $png = base64_decode((string)($j['results'][0]['dataB64'] ?? ''), true);
    if ($png === false || strlen($png) < 1000 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") return ['error' => 'the reply carried no PNG'];
    if (@file_put_contents($out, $png) === false) return ['error' => 'the cutout could not be written to agent-photos'];
    @chmod($out, 0664);
    return ['ok' => true, 'file' => $out, 'url' => mk_cutout_url($out), 'bytes' => strlen($png), 'cost' => (string)($j['cost']['amount'] ?? '')];
}

/** A new headshot for an agent: the old cutouts go, the new one is made when the key is set. */
function mk_cutout_refresh(string $headshotUrl, string $slug): array {
    mk_cutout_drop($headshotUrl, $slug);
    $f = mk_photo_file($headshotUrl);
    if ($f === '') return ['error' => 'the headshot is not hosted on this portal'];
    return mk_cutout_make($f);
}

/** The result as a sentence for the profile page's flash. */
function mk_cutout_words(array $r): string {
    if (!empty($r['ok'])) return 'Cutout made for the Broker Feature (' . number_format($r['bytes'] / 1024) . ' KB).';
    return 'Cutout not made: ' . ($r['error'] ?? 'unknown') . '. The Broker Feature uses the plain headshot until it is.';
}
