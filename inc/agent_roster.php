<?php
/**
 * inc/agent_roster.php — shared helpers for the one agent roster.
 *
 * Used by cron/sync_anyprop_roster.php, cron/import_site_agents.php and
 * api/roster.php. Include-only; queries only through the $conn it is handed.
 * See docs/AGENT_ROSTER_PLAN.md.
 */

// Subdomains we will never hand to an agent ({slug}.monthaus.com later).
const MK_RESERVED_SLUGS = ['www','mail','smtp','ftp','admin','api','site','app','dev','staging',
                           'marketing','newdev','listings','search','blog','info','support','test'];

// Boards (slug map, labels, default offices, Hot Sheet towns) come from the
// site's registry: inc/boards.php. Nothing board-specific is typed in here.
require_once __DIR__ . '/boards.php';

/**
 * " AND [alias.]entity_type = 'agent'" once agent_roster_v2_teams.sql has run,
 * '' before. Every query that looks for an AGENT (a person who is an MLS
 * agent) appends this. It keeps out:
 *   · teams (Weber Boxer Group): a MARKETING entity that advertises and is
 *     billed, never an MLS agent, never on the website, never matched to a
 *     person by email or name; its members have their own rows;
 *   · staff (leadership_v1.sql, 2026-10-01: Nikki Boxer, Kellee Anderson):
 *     people on the Leadership page who are not agents, so never on Our
 *     Agents, listings, FUB rotation, Hot Sheets, Pipeline, billing or MLS
 *     matching.
 * Was mk_team_sql() ("<> 'team'"); renamed when staff arrived, so every caller
 * now means "agents only". mk_team_sql() remains as an alias.
 */
function mk_agents_only_sql(mysqli $conn, string $alias = ''): string {
    static $has = null;
    if ($has === null) {
        $r = $conn->query("SHOW COLUMNS FROM marketing_intakes LIKE 'entity_type'");
        $has = $r && $r->fetch_row();
    }
    return $has ? ' AND ' . ($alias !== '' ? $alias . '.' : '') . "entity_type = 'agent'" : '';
}
/** Old name of mk_agents_only_sql(); kept so nothing breaks. */
function mk_team_sql(mysqli $conn, string $alias = ''): string { return mk_agents_only_sql($conn, $alias); }

/** DNS-safe base slug from a name. */
function mk_slug_base(string $name): string {
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    if ($ascii === false) $ascii = $name;
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $ascii), '-'));
    $base = substr($base, 0, 50);
    if ($base === '') $base = 'agent';
    if (in_array($base, MK_RESERVED_SLUGS, true)) $base .= '-mh';
    return $base;
}

/** A slug no other marketing_intakes row holds. */
function mk_make_slug(mysqli $conn, string $name, int $exclude_id = 0): string {
    $base = mk_slug_base($name);
    $slug = $base; $n = 2;
    $s = $conn->prepare("SELECT id FROM marketing_intakes WHERE slug = ? AND id <> ? LIMIT 1");
    if (!$s) throw new RuntimeException('slug lookup prepare failed: ' . $conn->error);
    while (true) {
        $s->bind_param('si', $slug, $exclude_id);
        $s->execute();
        $taken = (bool)$s->get_result()->fetch_row();
        if (!$taken) { $s->close(); return $slug; }
        $slug = $base . '-' . $n++;
    }
}

/**
 * Give every live, named row without a slug one. Returns [id => slug] assigned.
 * Name source: agent_name, else mls_full_name.
 *
 * Archived rows are skipped: a slug puts a row in the website feed, and an
 * intake archived before this existed was never on the website. (An agent who
 * WAS on the site keeps the slug they already have when archived, and the feed
 * then publishes them as inactive, which is what takes them down.)
 */
function mk_assign_missing_slugs(mysqli $conn, bool $dry = false): array {
    $out = [];
    $res = $conn->query("SELECT id, COALESCE(NULLIF(TRIM(agent_name),''), mls_full_name) AS nm
                           FROM marketing_intakes
                          WHERE (slug IS NULL OR slug = '')
                            AND is_active = 1 AND status <> 'archived'
                            AND COALESCE(NULLIF(TRIM(agent_name),''), mls_full_name) IS NOT NULL"
                        . mk_agents_only_sql($conn) . "   -- teams and staff have no agent profile
                          ORDER BY id");
    if (!$res) throw new RuntimeException('slug backfill query failed: ' . $conn->error);
    $up = $conn->prepare("UPDATE marketing_intakes SET slug = ? WHERE id = ?");
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
        $slug = mk_make_slug($conn, (string)$r['nm'], (int)$r['id']);
        $out[(int)$r['id']] = $slug;
        if (!$dry) { $id = (int)$r['id']; $up->bind_param('si', $slug, $id); $up->execute(); }
    }
    $up->close();
    return $out;
}

/** US numbers → +1XXXXXXXXXX. Anything else is returned trimmed, unchanged. */
function mk_e164(?string $phone): string {
    $p = trim((string)$phone);
    if ($p === '') return '';
    $d = preg_replace('/\D+/', '', $p);
    if (strlen($d) === 10) return '+1' . $d;
    if (strlen($d) === 11 && $d[0] === '1') return '+' . $d;
    return $p;
}

/** The bio allowlist agent.php applies on save. One definition, used by every writer. */
function mk_clean_bio(?string $html): string {
    // The public site's editor (Trix) writes <div> blocks. strip_tags would drop
    // them and run the paragraphs together, so they become <p> first.
    // strip_tags removes a <script> tag but keeps what is inside it; drop the
    // contents of script and style blocks entirely first.
    $v = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', (string)$html);
    $v = preg_replace(['#<div\b[^>]*>#i', '#</div>#i'], ['<p>', '</p>'], $v);
    $v = strip_tags($v, '<p><br><strong><b><em><i><u><ol><ul><li><a><h3><h4><blockquote>');
    // strip_tags keeps attributes. This text is published on monthaus.com, so
    // drop event handlers and script URLs from whatever tags survive.
    $v = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $v);
    $v = preg_replace('/(href\s*=\s*["\']?)\s*(javascript|data|vbscript):[^"\'>\s]*/i', '$1#', $v);
    $v = trim($v);
    // An editor that has been cleared leaves markup with no text in it.
    if (trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5), " \t\n\r\0\x0B\xC2\xA0") === '') $v = '';
    return $v;
}
