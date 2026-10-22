<?php
/**
 * inc/elevation.php — the Elevation Planner on the agent portal (2026-10-17).
 *
 * The planner is two linked tools in one page (portal/elevation.php):
 *   part one, the Planner: a take-home goal worked back to commission,
 *     closings, sales volume and the weekly conversations it takes;
 *   part two, Lead Sources: the activities that start those conversations,
 *     with counts, hours and dollars a month, against the target part one set.
 * All of the arithmetic lives in ONE place, assets/js/elevation.js
 * (`MHElevation.calc`, `.compute`, `.planTarget`), which the page, the
 * printed document and the PDF all run; nothing here recomputes it.
 *
 * This file is the data layer and the document:
 *   · rows in elevation_plans (sql/elevation_plans_v1.sql), per PERSON
 *     (`elevation_owner()`: the account id, never a team), one `working` row
 *     rewritten as they type and any number of `saved` snapshots;
 *   · `elevation_clean_plan()` / `elevation_clean_leads()`: everything the
 *     page posts is checked for shape, length and count before it is stored,
 *     so a row can never hold anything the page did not draw;
 *   · `elevation_render()`: the formatted plan as a Letter document, ONE
 *     render path for the print view (portal/elevation_print.php) and the PDF
 *     (portal/elevation_pdf.php, headless Chromium through the flyer's
 *     `flyer_export_pdf()`); `$export` inlines the fonts and logo as data URIs
 *     because Chromium opens the file with no session and no network.
 *
 * Until the migration has run, `elevation_ready()` is false: the page keeps
 * entries in the visitor's browser and says so, and the save endpoint refuses.
 */
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/schema.php';

const ELEVATION_MAX_JSON     = 160000;   // bytes of posted JSON, each part
const ELEVATION_MAX_CHANNELS = 40;
const ELEVATION_MAX_ROWS     = 60;       // activity rows per source, and software rows
const ELEVATION_MAX_MARKETS  = 40;
const ELEVATION_NAME_MAX     = 120;

function elevation_ready(mysqli $conn): bool { return mk_table_exists($conn, 'elevation_plans'); }

/** The account a plan belongs to: the signed-in person (or the previewed agent). Plans are personal, never a team's. */
function elevation_owner(array $ctx): int { return (int)$ctx['acct']['id']; }

// ── Cleaning what the page posts ─────────────────────────────────────────────

/** A number the page could have stored: a finite value from 0 up, or '' for an empty box. */
function elevation_num(mixed $v, bool $blank_ok): float|int|string {
    if ($v === '' || $v === null) return $blank_ok ? '' : 0;
    if (!is_numeric($v)) return $blank_ok ? '' : 0;
    $f = (float)$v;
    if (!is_finite($f) || $f < 0) return $blank_ok ? '' : 0;
    if ($f > 1e12) $f = 1e12;
    return $f == (int)$f && abs($f) < PHP_INT_MAX ? (int)$f : $f;
}

function elevation_str(mixed $v, int $max): string {
    $s = is_scalar($v) ? (string)$v : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
    return mb_substr($s, 0, $max);
}

function elevation_id(mixed $v): string {
    $s = is_scalar($v) ? (string)$v : '';
    return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $s) ? $s : '';
}

/** Part one's state, or null when it is not a plan at all. Keys and limits as the page writes them. */
function elevation_clean_plan(mixed $in): ?array {
    if (!is_array($in)) return null;
    $out = [];
    foreach (['goal', 'tax', 'expenses', 'split', 'price', 'rate', 'weeks', 'apptRate', 'agreeRate', 'closeRate', 'q1', 'q2', 'q3', 'q4'] as $k) {
        $out[$k] = elevation_num($in[$k] ?? 0, false);
    }
    $markets = [];
    foreach (is_array($in['markets'] ?? null) ? $in['markets'] : [] as $m) {
        $m = trim(elevation_str($m, 60));
        if ($m !== '' && !in_array($m, $markets, true)) $markets[] = $m;
        if (count($markets) >= ELEVATION_MAX_MARKETS) break;
    }
    $out['markets'] = $markets;
    foreach (['n1', 'n2', 'n3'] as $k) $out[$k] = elevation_str($in[$k] ?? '', 2000);
    return $out;
}

/** Part two's state, or null. Sources and rows keep the ids the page gave them (they key the inputs). */
function elevation_clean_leads(mixed $in): ?array {
    if (!is_array($in) || !is_array($in['channels'] ?? null) || !is_array($in['tools'] ?? null)) return null;
    $out = ['need' => elevation_num($in['need'] ?? '', true), 'budget' => elevation_num($in['budget'] ?? '', true), 'weeks' => elevation_num($in['weeks'] ?? '', true), 'channels' => [], 'tools' => []];
    foreach ($in['channels'] as $c) {
        if (!is_array($c) || elevation_id($c['id'] ?? '') === '') continue;
        $rows = [];
        foreach (is_array($c['rows'] ?? null) ? $c['rows'] : [] as $r) {
            if (!is_array($r) || elevation_id($r['id'] ?? '') === '') continue;
            $rows[] = ['id' => elevation_id($r['id']), 'name' => elevation_str($r['name'] ?? '', 120), 'qty' => elevation_num($r['qty'] ?? '', true),
                       'unit' => elevation_str($r['unit'] ?? '', 30), 'hours' => elevation_num($r['hours'] ?? '', true), 'cost' => elevation_num($r['cost'] ?? '', true),
                       'conv' => elevation_num($r['conv'] ?? '', true), 'note' => elevation_str($r['note'] ?? '', 200)];
            if (count($rows) >= ELEVATION_MAX_ROWS) break;
        }
        $out['channels'][] = ['id' => elevation_id($c['id']), 'name' => elevation_str($c['name'] ?? '', 60), 'custom' => !empty($c['custom']),
                              'hint' => elevation_str($c['hint'] ?? '', 300), 'rows' => $rows];
        if (count($out['channels']) >= ELEVATION_MAX_CHANNELS) break;
    }
    foreach ($in['tools'] as $t) {
        if (!is_array($t) || elevation_id($t['id'] ?? '') === '') continue;
        $out['tools'][] = ['id' => elevation_id($t['id']), 'name' => elevation_str($t['name'] ?? '', 80), 'use' => elevation_str($t['use'] ?? '', 160), 'cost' => elevation_num($t['cost'] ?? '', true)];
        if (count($out['tools']) >= ELEVATION_MAX_ROWS) break;
    }
    return $out;
}

/** A posted JSON part, decoded and cleaned; null when it is missing, too long, not JSON or not that shape. */
function elevation_part_from_post(string $field, string $part): ?array {
    $raw = $_POST[$field] ?? null;
    if (!is_string($raw) || $raw === '' || strlen($raw) > ELEVATION_MAX_JSON) return null;
    $v = json_decode($raw, true);
    if (!is_array($v)) return null;
    return $part === 'plan' ? elevation_clean_plan($v) : elevation_clean_leads($v);
}

function elevation_clean_name(mixed $v): string {
    $s = trim(preg_replace('/\s+/u', ' ', elevation_str($v, 400)) ?? '');
    return mb_substr($s, 0, ELEVATION_NAME_MAX);
}

/** "Elevation Plan, Oct 17, 2026": what a snapshot is called until it is renamed. */
function elevation_default_name(): string { return 'Elevation Plan, ' . date('M j, Y'); }

// ── Rows ─────────────────────────────────────────────────────────────────────

/** A row as the page and the document use it: the two states decoded, dates as given. */
function elevation_row(array $r): array {
    $plan  = json_decode((string)($r['plan'] ?? ''), true);
    $leads = json_decode((string)($r['leads'] ?? ''), true);
    return ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'name' => (string)$r['name'],
            'plan' => is_array($plan) ? $plan : null, 'leads' => is_array($leads) ? $leads : null,
            'created_at' => (string)$r['created_at'], 'updated_at' => (string)$r['updated_at']];
}

function elevation_working(mysqli $conn, int $owner): ?array {
    $st = $conn->prepare("SELECT id, intake_id, kind, name, plan, leads, created_at, updated_at FROM elevation_plans WHERE intake_id = ? AND kind = 'working' ORDER BY id DESC LIMIT 1");
    $st->bind_param('i', $owner);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ? elevation_row($r) : null;
}

/** The person's snapshots, newest first. */
function elevation_saved(mysqli $conn, int $owner): array {
    $st = $conn->prepare("SELECT id, intake_id, kind, name, plan, leads, created_at, updated_at FROM elevation_plans WHERE intake_id = ? AND kind = 'saved' ORDER BY created_at DESC, id DESC");
    $st->bind_param('i', $owner);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return array_map('elevation_row', $rows);
}

/** One row by id, the owner's only: anyone else's id is simply not found. */
function elevation_get(mysqli $conn, int $owner, int $id): ?array {
    $st = $conn->prepare("SELECT id, intake_id, kind, name, plan, leads, created_at, updated_at FROM elevation_plans WHERE id = ? AND intake_id = ? LIMIT 1");
    $st->bind_param('ii', $id, $owner);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ? elevation_row($r) : null;
}

function elevation_json(array $v): string {
    return (string)json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Rewrite (or start) the person's working row. */
function elevation_save_working(mysqli $conn, int $owner, array $plan, array $leads): bool {
    $pj = elevation_json($plan); $lj = elevation_json($leads);
    $cur = elevation_working($conn, $owner);
    if ($cur) {
        $st = $conn->prepare("UPDATE elevation_plans SET plan = ?, leads = ? WHERE id = ? AND intake_id = ?");
        $st->bind_param('ssii', $pj, $lj, $cur['id'], $owner);
    } else {
        $st = $conn->prepare("INSERT INTO elevation_plans (intake_id, kind, name, plan, leads) VALUES (?, 'working', '', ?, ?)");
        $st->bind_param('iss', $owner, $pj, $lj);
    }
    $ok = $st->execute();
    $st->close();
    return (bool)$ok;
}

/** A named snapshot of both parts; returns its id. */
function elevation_snapshot(mysqli $conn, int $owner, string $name, array $plan, array $leads): int {
    $pj = elevation_json($plan); $lj = elevation_json($leads);
    if ($name === '') $name = elevation_default_name();
    $st = $conn->prepare("INSERT INTO elevation_plans (intake_id, kind, name, plan, leads) VALUES (?, 'saved', ?, ?, ?)");
    $st->bind_param('isss', $owner, $name, $pj, $lj);
    $ok = $st->execute();
    $st->close();
    return $ok ? (int)$conn->insert_id : 0;
}

function elevation_rename(mysqli $conn, int $owner, int $id, string $name): bool {
    if ($name === '') return false;
    $st = $conn->prepare("UPDATE elevation_plans SET name = ? WHERE id = ? AND intake_id = ? AND kind = 'saved'");
    $st->bind_param('sii', $name, $id, $owner);
    $ok = $st->execute();
    $st->close();
    return (bool)$ok;
}

function elevation_delete(mysqli $conn, int $owner, int $id): bool {
    $st = $conn->prepare("DELETE FROM elevation_plans WHERE id = ? AND intake_id = ? AND kind = 'saved'");
    $st->bind_param('ii', $id, $owner);
    $ok = $st->execute();
    $st->close();
    return (bool)$ok;
}

// ── The document ─────────────────────────────────────────────────────────────

/** "Elevation-Plan-jackson-horn-2026-10-17.pdf" */
function elevation_filename(array $ctx, array $row): string {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($ctx['acct']['slug'] ?: $ctx['acct']['agent_name'])));
    $slug = trim((string)$slug, '-') ?: 'plan';
    $when = $row['kind'] === 'saved' && $row['created_at'] !== '' ? substr($row['created_at'], 0, 10) : date('Y-m-d');
    return 'Elevation-Plan-' . $slug . '-' . $when . '.pdf';
}

/** "October 17, 2026" from a DATETIME the database wrote. */
function elevation_date_words(string $dt): string {
    $t = $dt !== '' ? strtotime($dt) : false;
    return date('F j, Y', $t !== false ? $t : time());
}

/**
 * The formatted plan as a self-contained Letter document (both parts, Lead
 * Sources on a new page). The markup is drawn by MHElevation.renderDocument()
 * in assets/js/elevation.js from the row's two states, so the figures are the
 * ones the page showed. $export: fonts and the logo inlined for Chromium;
 * otherwise the print view in the browser, with a toolbar the printer never sees.
 */
function elevation_render(array $row, array $ctx, bool $export, string $back = ''): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $base = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
    $fontUrl = function (string $file) use ($base, $export): string {
        $disk = __DIR__ . '/../assets/fonts/mh/' . $file;
        if ($export && is_file($disk)) return 'data:font/woff2;base64,' . base64_encode((string)file_get_contents($disk));
        return $base . '/assets/fonts/mh/' . $file;
    };
    // The full stacked logo from Final Logos (Nikki, 2026-10-18: "the proper MH logo"), added as its own file.
    $logoDisk = __DIR__ . '/../assets/images/monthaus_black-logo_FINAL.svg';
    $logo = $export && is_file($logoDisk) ? 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($logoDisk)) : $base . '/assets/images/monthaus_black-logo_FINAL.svg';

    // elevation.css carries the .ev-s-* summary blocks the page shares; elevation-print.css the sheet, header and print rules.
    $css = (string)@file_get_contents(__DIR__ . '/../assets/css/elevation.css') . "\n" . (string)@file_get_contents(__DIR__ . '/../assets/css/elevation-print.css');
    $js  = (string)@file_get_contents(__DIR__ . '/../assets/js/elevation.js');
    $fonts = "@font-face{font-family:'Public Sans';font-style:normal;font-weight:100 900;src:url(" . $fontUrl('PublicSans-VariableFont_wght.ttf') . ") format('truetype')}\n"
           . "@font-face{font-family:'Cormorant Garamond';font-style:normal;font-weight:300 700;src:url(" . $fontUrl('CormorantGaramond-normal.woff2') . ") format('woff2')}\n";

    $meta = [
        'agent'   => (string)$ctx['acct']['agent_name'],
        'name'    => $row['kind'] === 'saved' ? $row['name'] : 'Working plan',
        'saved'   => $row['kind'] === 'saved',
        'date'    => elevation_date_words($row['kind'] === 'saved' ? $row['created_at'] : $row['updated_at']),
        'logo'    => $logo,
    ];
    $state = ['plan' => $row['plan'], 'leads' => $row['leads']];
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
    $title = ($meta['saved'] ? $row['name'] : 'Elevation Plan') . ' · ' . $meta['agent'];

    $toolbar = '';
    if (!$export) {
        $pdf = '/portal/elevation_pdf.php?id=' . ($row['kind'] === 'saved' ? (int)$row['id'] : 'working');
        if (!empty($ctx['preview'])) $pdf .= '&preview=' . (int)$ctx['acct']['id'];
        $toolbar = '<div class="ev-toolbar" id="toolbar">'
            . '<a class="ev-tb-back" href="' . $e($back !== '' ? $back : '/portal/elevation.php') . '">&larr; Back to the planner</a>'
            . '<span class="ev-tb-sp"></span>'
            . '<button type="button" class="ev-tb-btn" onclick="window.print()">Print</button>'
            . '<a class="ev-tb-btn main" href="' . $e($pdf) . '">Download PDF</a>'
            . '</div>';
    }

    return '<!DOCTYPE html>' . "\n" . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<title>' . $e($title) . '</title>'
        . '<style>' . $fonts . $css . '</style></head>'
        . '<body class="' . ($export ? 'ev-export' : 'ev-screen') . '">' . $toolbar
        . '<div class="ev-doc" id="doc"></div>'
        . '<script>' . $js . '</script>'
        . '<script>window.MHElevation.renderDocument(document.getElementById("doc"), ' . json_encode($state, $jsonFlags) . ', ' . json_encode($meta, $jsonFlags) . ');</script>'
        . '</body></html>';
}

/** The document as PDF bytes through the flyer's Chromium call, or null when Chromium failed (logged there). */
function elevation_pdf(string $html): ?string {
    require_once __DIR__ . '/flyer.php';
    return flyer_export_pdf($html);
}
