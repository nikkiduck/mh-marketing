<?php
/**
 * inc/former_slugs.php — the web addresses an agent used to have (Nikki,
 * 2026-11-14: "can I change their agent url without messing anything up?").
 *
 * An agent's page is monthaus.com/{slug}; the slug is the Web address field
 * on the profile and the `key` of the roster feed. Changing it used to leave
 * the old address a 404, and the site could not follow the change at all for
 * an agent with no MLS id (it matches by slug, then by board identity). So
 * the moment the field changes, the address it had is written down here, and
 * the roster feed sends each agent's list as `former_keys`. The site then
 * (a) recognises the agent by a former address when nothing else matches and
 * (b) 301s every former address to the current one (site webroot/broker.php).
 * This is the recorded fact, not a guess from names or emails.
 *
 * Store: one JSON file, CREATIVES_DIR/roster/former_slugs.json (the What's
 * New pattern: no table, changed under a lock): { "<intake id>": ["old", …] },
 * oldest first. Rules: an address someone holds NOW is nobody's former
 * address (claiming one drops it from every list, the agent's own included,
 * so going back to an old address just works); a list keeps its last
 * FS_KEEP entries.
 *
 * Only the profile's Web address form changes an existing slug (agent.php,
 * update_web); every other writer fills an empty one. A new writer that
 * changes one must call fs_note_change() too.
 */
require_once __DIR__ . '/config.php';   // CREATIVES_DIR

const FS_KEEP = 10;

/** The store's path. $set: another path, for a test. */
function fs_path(?string $set = null): string {
    static $path = null;
    if ($set !== null) $path = $set;
    if ($path !== null) return $path;
    $dir = rtrim(CREATIVES_DIR, '/') . '/roster';
    if (!is_dir($dir)) { @mkdir($dir, 02775, true); @chmod($dir, 02775); }
    return $dir . '/former_slugs.json';
}

/** A slug as the site accepts one. */
function fs_valid($s): bool { return is_string($s) && (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $s); }

/** The store made safe to use: [intake id => [slug, …]], bad entries dropped. */
function fs_clean($j): array {
    $out = [];
    foreach (is_array($j) ? $j : [] as $id => $list) {
        $id = (int)$id;
        $list = array_values(array_unique(array_filter(is_array($list) ? $list : [], 'fs_valid')));
        if ($id > 0 && $list) $out[$id] = array_slice($list, -FS_KEEP);
    }
    return $out;
}

/** Every agent's former addresses: [intake id => [slug, …]]. Empty when there are none or the file cannot be read. */
function fs_all(): array {
    $raw = @file_get_contents(fs_path());
    return ($raw === false || trim($raw) === '') ? [] : fs_clean(json_decode($raw, true));
}

/** One agent's former addresses, oldest first. */
function fs_for(int $id): array { return fs_all()[$id] ?? []; }

/** Change the store under a lock: $fn takes it by reference. False when it could not be written; a file that cannot be read is never written over. */
function fs_update(callable $fn): bool {
    $fh = @fopen(fs_path(), 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $raw = (string)stream_get_contents($fh);
    $j = trim($raw) === '' ? [] : json_decode($raw, true);
    if (!is_array($j)) { flock($fh, LOCK_UN); fclose($fh); return false; }
    $store = fs_clean($j);
    $fn($store);
    $store = fs_clean($store);
    ftruncate($fh, 0); rewind($fh);
    $ok = fwrite($fh, json_encode((object)$store, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    @chmod(fs_path(), 0664);
    return $ok;
}

/**
 * An agent's web address changed from $old to $new: $new is live now, so it
 * leaves every list; $old joins the agent's list. Nothing to do when they are
 * the same; a blank or bad $old only releases $new.
 */
function fs_note_change(int $id, string $old, string $new): bool {
    if ($id <= 0 || $old === $new) return true;
    return fs_update(function (array &$store) use ($id, $old, $new) {
        if (fs_valid($new)) foreach ($store as $k => $list) $store[$k] = array_values(array_diff($list, [$new]));
        if (fs_valid($old)) $store[$id] = array_merge(array_values(array_diff($store[$id] ?? [], [$old])), [$old]);
    });
}

/**
 * The feed's `former_keys` for every agent: [intake id => [slug, …]], with
 * any address that is somebody's current one left out ($live: every slug in
 * use, as a list), so the site never redirects a live address away.
 */
function fs_for_feed(array $live): array {
    $live = array_flip(array_map('strval', $live));
    $out = [];
    foreach (fs_all() as $id => $list) {
        $list = array_values(array_filter($list, fn($s) => !isset($live[$s])));
        if ($list) $out[$id] = $list;
    }
    return $out;
}
