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
 * and made by remove.bg's API (https://www.remove.bg/api: POST the image,
 * a PNG with transparency comes back; one credit a picture; the key is
 * REMOVEBG_API_KEY in inc/db.php beside the other keys). Without the key
 * nothing is made and the Broker Feature falls back to the plain headshot.
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

const MK_CUTOUT_ENDPOINT_DEFAULT = 'https://api.remove.bg/v1.0/removebg';

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
    if (!defined('REMOVEBG_API_KEY') || REMOVEBG_API_KEY === '') return ['error' => 'no REMOVEBG_API_KEY in inc/db.php yet'];
    if (!is_file($headshotFile)) return ['error' => 'the headshot file is not on this server'];
    if (!function_exists('curl_init')) return ['error' => 'PHP curl is not installed'];
    $out = mk_cutout_path_for($headshotFile);
    if ($out === '') return ['error' => 'the headshot has no file name'];
    $mime = (function_exists('mime_content_type') ? @mime_content_type($headshotFile) : '') ?: 'image/jpeg';
    $ch = curl_init(defined('MK_CUTOUT_ENDPOINT') ? MK_CUTOUT_ENDPOINT : MK_CUTOUT_ENDPOINT_DEFAULT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['X-Api-Key: ' . REMOVEBG_API_KEY, 'Accept: image/png, application/json'],
        CURLOPT_POSTFIELDS => ['image_file' => new CURLFile($headshotFile, $mime, basename($headshotFile)),
                               'size' => 'auto', 'type' => 'person', 'format' => 'png'],   // auto: the full resolution the plan allows; person: a portrait
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    if ($body === false) { error_log("cutout: request failed for " . basename($headshotFile) . ": {$err}"); return ['error' => 'the request to remove.bg failed: ' . $err]; }
    if ($code !== 200 || !str_starts_with($ctype, 'image/')) {
        $j = json_decode((string)$body, true);
        $why = (string)($j['errors'][0]['title'] ?? ('HTTP ' . $code));
        error_log("cutout: remove.bg said {$why} for " . basename($headshotFile));
        return ['error' => 'remove.bg: ' . $why];
    }
    if (strlen($body) < 1000 || substr($body, 0, 8) !== "\x89PNG\r\n\x1a\n") return ['error' => 'the reply was not a PNG'];
    if (@file_put_contents($out, $body) === false) return ['error' => 'the cutout could not be written to agent-photos'];
    @chmod($out, 0664);
    return ['ok' => true, 'file' => $out, 'url' => mk_cutout_url($out), 'bytes' => strlen($body)];
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
