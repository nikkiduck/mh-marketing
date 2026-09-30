<?php
/**
 * inc/site_sync.php — push the roster to site.monthaus.com on demand.
 *
 * The website normally pulls this portal's feed on a nightly cron. The "Sync to
 * Website" button on the roster asks it to do that pull right now, so a headshot
 * or bio edited here shows up on the public site without waiting or SSHing
 * (2026-09-28).
 *
 * The work still happens on the website: this only calls its
 * /api/sync_agents.php, which runs the same sync_agents_from_marketing.php the
 * cron runs and hands back its log. Needs, in inc/db.php:
 *
 *   define('AGENT_SYNC_TOKEN', '...same value as AGENT_SYNC_TOKEN in the site config...');
 *
 * and SITE_AGENT_SYNC_URL in inc/config.php. Missing either one is reported,
 * never guessed at.
 */

/**
 * Run the website's agent sync.
 *
 * @return array ['ok' => bool, 'lines' => string[], 'log' => string]
 *               `lines` is what to show in the flash; `log` is the raw output.
 */
function mk_site_sync(bool $dry = false): array
{
    if (!defined('SITE_AGENT_SYNC_URL') || SITE_AGENT_SYNC_URL === '') {
        return ['ok' => false, 'log' => '', 'lines' => ['SITE_AGENT_SYNC_URL is not set in inc/config.php, so there is nowhere to send this.']];
    }
    if (!defined('AGENT_SYNC_TOKEN') || strlen((string)AGENT_SYNC_TOKEN) < 24) {
        return ['ok' => false, 'log' => '',
                'lines' => ['AGENT_SYNC_TOKEN is missing from inc/db.php, or is shorter than 24 characters.',
                            'It has to be the same value as AGENT_SYNC_TOKEN in the website\'s config.php.']];
    }

    // This page now waits on another server, so it needs longer than the usual
    // 30 seconds. A little more than the curl timeout below, so curl is what
    // gives up first and we can say why.
    @set_time_limit(320);

    $ch = curl_init(SITE_AGENT_SYNC_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($dry ? ['dry' => 1] : []),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . AGENT_SYNC_TOKEN, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,   // photo downloads; the endpoint allows the same
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200) {
        $why = $err ?: 'HTTP ' . $code;
        $hint = match ($code) {
            401 => 'The token here and on the website do not match, or the website never saw the Authorization header.',
            404 => 'The website does not have api/sync_agents.php yet.',
            405 => 'The website answered "POST only", so something rewrote the request on the way in.',
            503 => 'AGENT_SYNC_TOKEN is missing from the website\'s config.php.',
            default => trim(substr(strip_tags($body), 0, 200)),
        };
        return ['ok' => false, 'log' => $body,
                'lines' => array_values(array_filter(["The website did not run the sync ({$why}).", $hint]))];
    }

    $json = json_decode($body, true);
    $log  = is_array($json) ? (string)($json['log'] ?? '') : $body;
    if (!is_array($json) || empty($json['ok'])) {
        return ['ok' => false, 'log' => $log ?: $body,
                'lines' => ['The website answered, but not with a result it could read back.',
                            trim(substr(strip_tags($log ?: $body), 0, 200))]];
    }
    // The sync prints its ✓ tally only when it finishes. Without one it stopped
    // partway (a feed it could not fetch, say), whatever the HTTP code said.
    return ['ok' => strpos($log, '✓') !== false, 'log' => $log, 'lines' => mk_site_sync_summary($log, $dry)];
}

/**
 * The sync prints one line per agent it touched and ends with a tally
 * ("✓ 0 created, 3 updated, 12 unchanged, 2 photos pulled, 0 skipped").
 * Keep the tally, every line that needs a person, and a few of the changes.
 */
function mk_site_sync_summary(string $log, bool $dry = false): array
{
    $strip = fn(string $l) => trim(preg_replace('/^\[[\d\- :]+\]\s*/', '', $l));

    $tally = ''; $trouble = []; $changed = [];
    foreach (preg_split('/\R/', trim($log)) ?: [] as $raw) {
        $l = $strip($raw);
        if ($l === '') continue;
        if (strpos($l, '✓') === 0 || preg_match('/^✓ \d+ created/', $l)) { $tally = ltrim($l, "✓ \t"); continue; }
        if (strpos($l, '✗') !== false || preg_match('/^[!?]\s|^\s*[!?]\s/', $l)
            || stripos($l, 'could not') !== false || stripos($l, 'skipped') === 0) { $trouble[] = ltrim($l, "✗!? \t"); continue; }
        if (preg_match('/^[+~]\s?/', $l)) { $changed[] = ltrim($l, "+~ \t"); }
    }
    $out = [];
    if ($tally !== '') {
        $out[] = $tally;
    } elseif ($trouble) {
        // It stopped partway: lead with what went wrong, not with the tally.
        $out[] = 'The website stopped partway: ' . array_shift($trouble);
    } else {
        $all   = array_values(array_filter(array_map($strip, preg_split('/\R/', trim($log)) ?: [])));
        $out[] = $all ? end($all) : 'The website returned no output.';
    }
    foreach (array_slice($trouble, 0, 6) as $t) $out[] = $t;
    if (count($trouble) > 6) $out[] = '...and ' . (count($trouble) - 6) . ' more warnings in the log.';
    foreach (array_slice($changed, 0, 8) as $c) $out[] = $c;
    if (count($changed) > 8) $out[] = '...and ' . (count($changed) - 8) . ' more, all in the log.';
    if ($dry) $out[] = 'Dry run: the website was not changed.';
    return $out;
}
