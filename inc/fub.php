<?php
/**
 * inc/fub.php — each agent's Follow Up Boss standing, read from the public
 * site (2026-10-06). Include-only; no database.
 *
 * The site is the one system that talks to FUB: its sync_fub_users.php
 * matches FUB's user list to the roster by email every hour, and its
 * api/fub_users.php reports the result per agent key: whether they are a
 * FUB user, whether they accepted the invitation (status Active / Invited),
 * with which email, and when it was last checked. agent.php shows that in
 * the "Follow Up Boss" section so Nikki can see an invitation that was never
 * accepted without opening FUB. This portal holds no FUB key.
 *
 *   mk_fub_site_status($slug)   ['agent' => row|null, 'checked_at', 'source', 'error']
 *   mk_fub_refresh()            cron: fetch and cache FUB_STATUS_CACHE_FILE
 *   mk_fub_areas_decode($json)  the ticked town keys as a list
 *
 * The profile page fetches live (6 s) and falls back to the cache the roster
 * cron writes hourly (Apache cannot write /var/log/mh-marketing; the cron runs
 * as admin). URL: SITE_FUB_USERS_URL in inc/config.php, or fub_users.php
 * beside SITE_AGENT_SYNC_URL. Token: AGENT_SYNC_TOKEN (inc/db.php).
 */
require_once __DIR__ . '/config.php';

if (!defined('FUB_STATUS_CACHE_FILE')) {
    define('FUB_STATUS_CACHE_FILE', getenv('MH_FUB_CACHE') ?: '/var/log/mh-marketing/fub_users.json');
}

function mk_fub_status_url(): string {
    if (defined('SITE_FUB_USERS_URL') && SITE_FUB_USERS_URL !== '') return SITE_FUB_USERS_URL;
    if (defined('SITE_AGENT_SYNC_URL') && SITE_AGENT_SYNC_URL !== '') return preg_replace('~/[^/]*$~', '/fub_users.php', SITE_AGENT_SYNC_URL);
    return '';
}

/** The site's payload (decoded), or a string saying what went wrong. */
function mk_fub_status_fetch(int $timeout = 6): array|string {
    $url = mk_fub_status_url();
    if ($url === '') return 'SITE_FUB_USERS_URL / SITE_AGENT_SYNC_URL is not set in inc/config.php';
    if (!defined('AGENT_SYNC_TOKEN') || strlen((string)AGENT_SYNC_TOKEN) < 24) return 'AGENT_SYNC_TOKEN is missing from inc/db.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . AGENT_SYNC_TOKEN, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $code !== 200) return "{$url}: " . ($err ?: "HTTP {$code}");
    $d = json_decode($body, true);
    if (!is_array($d) || !is_array($d['agents'] ?? null)) return "{$url}: no agents in the payload";
    return $d;
}

/** Cron: fetch and replace the cache. Returns ['ok' => bool, 'message' => string]. */
function mk_fub_refresh(bool $write = true): array {
    $d = mk_fub_status_fetch(20);
    if (is_string($d)) return ['ok' => false, 'message' => $d];
    $n = count($d['agents']);
    if (!$write) return ['ok' => true, 'message' => "{$n} agents' FUB standing from the site (cache not written: dry run)"];
    $d['fetched_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $json = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $tmp  = FUB_STATUS_CACHE_FILE . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) === false || !@chmod($tmp, 0644) || !@rename($tmp, FUB_STATUS_CACHE_FILE)) {
        @unlink($tmp);
        return ['ok' => false, 'message' => "{$n} agents fetched but " . FUB_STATUS_CACHE_FILE . ' is not writable; the old cache stays'];
    }
    return ['ok' => true, 'message' => "{$n} agents' FUB standing from the site → " . FUB_STATUS_CACHE_FILE];
}

/**
 * One agent's standing: live from the site, else the cron's cache.
 * 'agent' is null when neither answered or the site does not know the key
 * (not yet synced there); 'error' then says why.
 */
function mk_fub_site_status(string $slug): array {
    $out = ['agent' => null, 'checked_at' => null, 'source' => null, 'error' => null];
    $d = $slug !== '' ? mk_fub_status_fetch() : 'no web address yet';
    if (is_array($d)) {
        $out['source'] = 'site';
    } else {
        $out['error'] = $d;
        if (is_readable(FUB_STATUS_CACHE_FILE)) {
            $c = json_decode((string)file_get_contents(FUB_STATUS_CACHE_FILE), true);
            if (is_array($c) && is_array($c['agents'] ?? null)) { $d = $c; $out['source'] = 'cache'; }
        }
    }
    if (!is_array($d)) return $out;
    $out['checked_at'] = $d['checked_at'] ?? null;
    if (isset($d['agents'][$slug]) && is_array($d['agents'][$slug])) {
        $a = $d['agents'][$slug];
        $out['agent'] = [
            'in_fub'      => !empty($a['in_fub']),
            'fub_user_id' => (int)($a['fub_user_id'] ?? 0),
            'status'      => (string)($a['status'] ?? ''),
            'email'       => (string)($a['email'] ?? ''),
            'usable'      => !empty($a['usable']),
            'areas'       => is_array($a['areas'] ?? null) ? array_map('strval', $a['areas']) : [],
        ];
    } elseif ($out['error'] === null) {
        $out['error'] = 'The website has not synced this agent yet (hourly).';
    }
    return $out;
}

/** The ticked town keys stored in marketing_intakes.fub_areas. */
function mk_fub_areas_decode(?string $json): array {
    $v = json_decode((string)$json, true);
    return is_array($v) ? array_values(array_filter(array_map('strval', $v), fn($k) => preg_match('/^[a-z0-9-]{1,60}$/', $k))) : [];
}
