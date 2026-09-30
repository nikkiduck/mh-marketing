<?php
/**
 * inc/pipeline.php — Paperless Pipeline helpers shared by the parser, the
 * review page and the webhook.
 *
 * Ported from monthausint.com/hot-sheets (parse_pipeline_events.php,
 * pipeline_review.php, _pipeline_enrich.php). Changes:
 *   · agents resolve against marketing_intakes (mh_email, alt_email,
 *     mls_email, then name), not mh_brokers;
 *   · the MLS address match is against hs_listing_state;
 *   · enrichment (photo, city, MLS #, link) asks site.monthaus.com's
 *     api/listings.php?scope=lookup instead of Spark + Lofty, so a buyer-rep
 *     link goes to the site's listing page;
 *   · pl_map_status() no longer turns every unknown status into Active: a
 *     cancelled, withdrawn or expired deal maps to null, and null is never
 *     published (the hub would have announced it as an active pocket listing).
 */

require_once __DIR__ . '/hs_data.php';   // hs_norm_address(), hs_fuzzy_key()

/** Lower-cased email list from a comma-separated config constant. */
function pl_email_list(string $const): array {
    if (!defined($const)) return [];
    return array_values(array_filter(array_map(fn($e) => strtolower(trim($e)), explode(',', (string)constant($const)))));
}

/**
 * Paperless status → hs_manual_listings.status, or NULL when the deal is not
 * something to publish (cancelled, withdrawn, expired, terminated, unknown).
 * Categories are prefixed numerically ("30-pending"); the label is free text.
 */
function pl_map_status(?string $category, ?string $label): ?string {
    foreach ([strtolower(trim((string)$category)), strtolower(trim((string)$label))] as $s) {
        if ($s === '') continue;
        foreach (['cancel', 'withdraw', 'expire', 'terminat', 'fell', 'void', 'dead', 'lost'] as $neg) {
            if (str_contains($s, $neg)) return null;
        }
        if (str_contains($s, 'pending') || str_contains($s, 'under contract')) return 'Pending';
        if (str_contains($s, 'closed') || str_contains($s, 'sold'))            return 'Closed';
        if (str_contains($s, 'active') || str_contains($s, 'coming soon') || str_contains($s, 'listed')) return 'Active';
    }
    return null;
}

/** Zapier labels vary; try several spellings, then a punctuation-blind match. */
function pl_get(array $p, array $candidates, $default = null) {
    foreach ($candidates as $c) {
        if (array_key_exists($c, $p) && $p[$c] !== '' && $p[$c] !== null) return $p[$c];
    }
    $norm = [];
    foreach ($p as $k => $v) $norm[preg_replace('/[^a-z0-9]/', '', strtolower((string)$k))] = $v;
    foreach ($candidates as $c) {
        $k = preg_replace('/[^a-z0-9]/', '', strtolower($c));
        if (isset($norm[$k]) && $norm[$k] !== '' && $norm[$k] !== null) return $norm[$k];
    }
    return $default;
}

/** Paperless timestamps carry microseconds; strip before parsing. */
function pl_date(?string $v, string $fmt = 'Y-m-d'): ?string {
    if (!$v) return null;
    $t = strtotime(preg_replace('/\.\d+(?=[+\-Z]|$)/', '', trim($v)));
    return $t ? date($fmt, $t) : null;
}

/** Roster lookups for agent matching: [by_email, by_name] → display name. */
function pl_roster(mysqli $conn): array {
    $by_email = []; $by_name = [];
    $r = $conn->query("SELECT COALESCE(NULLIF(TRIM(agent_name),''), mls_full_name) AS name,
                              mh_email, alt_email, mls_email, agent_name, mls_full_name
                         FROM marketing_intakes
                        WHERE is_active = 1 AND status <> 'archived'" . mk_team_sql($conn));
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $x) {
        $name = trim((string)$x['name']);
        if ($name === '') continue;
        foreach (['mh_email', 'alt_email', 'mls_email'] as $c) {
            $e = strtolower(trim((string)$x[$c]));
            if ($e !== '' && !isset($by_email[$e])) $by_email[$e] = $name;
        }
        foreach (['agent_name', 'mls_full_name'] as $c) {
            $n = strtolower(trim((string)$x[$c]));
            if ($n !== '' && !isset($by_name[$n])) $by_name[$n] = $name;
        }
    }
    return [$by_email, $by_name];
}

/**
 * Paperless Agents array → ['matched' => [roster names], 'unmatched' => [...]].
 * Assistants and inactive entries are skipped; excluded (admin / TC) and
 * conditional (managing broker) addresses are never credited automatically.
 */
function pl_resolve_agents($agents_json, array $by_email, array $by_name, array $excluded): array {
    $list = is_array($agents_json) ? $agents_json : json_decode((string)$agents_json, true);
    if (!is_array($list)) return ['matched' => [], 'unmatched' => []];
    $matched = []; $unmatched = [];
    foreach ($list as $a) {
        if (!is_array($a)) continue;
        if (isset($a['is_active']) && !$a['is_active']) continue;
        if (!empty($a['is_assistant'])) continue;
        $email = strtolower(trim((string)($a['email'] ?? '')));
        $name  = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
        if ($email !== '' && in_array($email, $excluded, true)) continue;
        if ($email !== '' && isset($by_email[$email]))            { $matched[$by_email[$email]] = true; continue; }
        if ($name !== '' && isset($by_name[strtolower($name)]))   { $matched[$by_name[strtolower($name)]] = true; continue; }
        if ($name !== '') $unmatched[$name . ($email ? " <{$email}>" : '')] = true;
    }
    return ['matched' => array_keys($matched), 'unmatched' => array_keys($unmatched)];
}

/** Address index over Mont Haus MLS listings, for the advisory duplicate check. */
function pl_listing_index(mysqli $conn): array {
    $idx = ['exact' => [], 'fuzzy' => []];
    $r = $conn->query("SELECT market, listing_key, mls_id, address FROM hs_listing_state WHERE address <> ''");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $x) {
        $n = hs_norm_address($x['address']);
        if ($n === '') continue;
        $idx['exact'][$n] = $x;
        if (($fk = hs_fuzzy_key($n)) !== '') $idx['fuzzy'][$fk] = $x;
    }
    return $idx;
}

function pl_match_listing(array $idx, string $address): array {
    $n = hs_norm_address($address);
    if ($n !== '' && isset($idx['exact'][$n])) return [$idx['exact'][$n], 'exact'];
    $fk = $n !== '' ? hs_fuzzy_key($n) : '';
    if ($fk !== '' && isset($idx['fuzzy'][$fk])) return [$idx['fuzzy'][$fk], 'fuzzy'];
    return [null, 'none'];
}

/**
 * Photo / city / MLS # / listing page for an address, from the site's feed.
 * Best-effort: every field may be null, and the review page reports what came
 * back. Searches every board and any status (a sold listing still has a photo),
 * preferring the same unit, then live listings.
 */
function pl_enrich_address(string $address): array {
    $out = ['mls_number' => null, 'photo' => null, 'city' => null, 'postal_code' => null,
            'url' => null, 'market' => null, 'status' => null, 'error' => null];
    $needle = trim($address);
    if (preg_match('/^\s*(\d+\s+[^,#]+)/', $needle, $m)) $needle = trim($m[1]);
    $needle = trim(preg_replace('/\s+\b(unit|apt|apartment|suite|ste)\b.*$/i', '', $needle));
    if ($needle === '') { $out['error'] = 'no street address to search'; return $out; }
    if (!defined('LISTINGS_FEED_URL') || !defined('LISTINGS_FEED_TOKEN')) { $out['error'] = 'listings feed not configured'; return $out; }

    $ch = curl_init(LISTINGS_FEED_URL . '?' . http_build_query(['scope' => 'lookup', 'q' => $needle]));
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . LISTINGS_FEED_TOKEN, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $d = json_decode($body, true);
    if ($code !== 200 || !is_array($d['listings'] ?? null)) { $out['error'] = "lookup failed (HTTP {$code})"; return $out; }
    $hits = $d['listings'];
    if (!$hits) return $out;

    // Same unit first: "Unit 13" in Paperless should not borrow unit 7's photo.
    $unit_re = '~(?:\b(?:unit|apt|apartment|suite|ste)\b|#)\s*#?\s*([A-Za-z0-9\-]+)~i';
    if (preg_match($unit_re, $address, $um)) {
        $u = strtolower($um[1]);
        $same = array_values(array_filter($hits, fn($l) => preg_match($unit_re, (string)$l['address'], $lm) && strtolower($lm[1]) === $u));
        if ($same) $hits = $same;
    }
    $best = $hits[0];   // the site orders Mont Haus first, then live, then newest
    $photo_src = $best;
    if (($best['primary_photo'] ?? '') === '') foreach ($hits as $h) if (($h['primary_photo'] ?? '') !== '') { $photo_src = $h; break; }

    $out['mls_number']  = $best['mls_id'] ?: null;
    $out['photo']       = ($photo_src['primary_photo'] ?? '') ?: null;
    $out['city']        = $best['city'] ?: null;
    $out['postal_code'] = $best['postal_code'] ?: null;
    $out['url']         = $best['url'] ?: null;
    $out['market']      = $best['market'] ?: null;
    $out['status']      = $best['status'] ?: null;
    return $out;
}
