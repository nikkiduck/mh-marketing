<?php
/**
 * inc/flyer.php — the printed listing flyer on the agent portal (Nikki,
 * 2026-10-13): a Letter-size PDF of one of the agent's listings, after the
 * InDesign display template. Shares the Instagram tool's data layer
 * (inc/social.php: the account's listings, the listing's photos from the
 * site, the agent's uploads) and its gate.
 *
 *   portal/flyer.php          the page (listing, photos in order, words, QR)
 *   portal/flyer_render.php   POST → the flyer as HTML, for the preview frame
 *   portal/flyer_export.php   POST → the same HTML printed to PDF by Chromium
 *   inc/flyer_render.php      the layout (standalone, no DB)
 *
 * QR code: the listing's page on the public site (default), one of the
 * agent's dynamic codes (qr_codes, qr.monthaus.com/<code>), or none; drawn by
 * qrencode (Debian package, installed 2026-10-13) as SVG at error level H,
 * with the Mont Haus triangle mark over the centre like mh-qr.js does.
 */
require_once __DIR__ . '/social.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/flyer_render.php';

if (!defined('FLYER_QRENCODE')) define('FLYER_QRENCODE', '/usr/bin/qrencode');

/** The account's name, phone and email for the footer. */
function flyer_agent(mysqli $conn, array $ctx): array {
    $id   = (int)$ctx['acct']['id'];
    $cols = 'agent_name'
          . (mk_column_exists($conn, 'marketing_intakes', 'cell_phone') ? ', cell_phone' : ", '' AS cell_phone")
          . (mk_column_exists($conn, 'marketing_intakes', 'mh_email')   ? ', mh_email'   : ", '' AS mh_email");
    $st = $conn->prepare("SELECT {$cols} FROM marketing_intakes WHERE id = ? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $a = $st->get_result()->fetch_assoc() ?: [];
    $st->close();
    return [
        'name'  => trim((string)($a['agent_name'] ?? '')),
        'phone' => function_exists('portal_phone') ? portal_phone((string)($a['cell_phone'] ?? '')) : trim((string)($a['cell_phone'] ?? '')),
        'email' => strtolower(trim((string)($a['mh_email'] ?? ''))),
    ];
}

/** The QR choices: the listing's page, each of the account's active dynamic codes, none. [key => [label, url]] */
function flyer_qr_options(mysqli $conn, array $ctx, ?array $listing): array {
    $opts = [];
    $lurl = trim((string)($listing['url'] ?? ''));
    if ($lurl !== '') $opts['listing'] = ['This listing\'s page on monthaus.com', $lurl];
    if (mk_table_exists($conn, 'qr_codes')) {
        $in = implode(',', array_map('intval', $ctx['ids']));
        $r  = $conn->query("SELECT id, code, label FROM qr_codes WHERE intake_id IN ({$in}) AND is_active = 1 ORDER BY label, id");
        foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $q) {
            $opts['code:' . (int)$q['id']] = ['QR code: ' . ($q['label'] !== '' ? $q['label'] : $q['code']), qr_public_url((string)$q['code'])];
        }
    }
    $opts['none'] = ['No QR code', ''];
    return $opts;
}

/** The QR code for a URL as an inline SVG (black modules, no margin), '' when qrencode is missing or fails. */
function flyer_qr_svg(string $url): string {
    if ($url === '' || !is_executable(FLYER_QRENCODE)) return '';
    $out = []; $rc = 1;
    exec(escapeshellarg(FLYER_QRENCODE) . ' -t SVG -l H -m 0 -s 4 -o - ' . escapeshellarg($url) . ' 2>/dev/null', $out, $rc);
    $svg = implode("\n", $out);
    if ($rc !== 0 || !str_contains($svg, '<svg')) return '';
    $svg = substr($svg, strpos($svg, '<svg'));                       // drop the XML prologue and comment
    $svg = preg_replace('/\s(width|height)="[^"]*"/', '', $svg, 2);   // size from CSS
    return $svg;
}

/** The listing's photos the form may offer, as the Instagram tool lists them (site photos, then uploads). */
function flyer_photo_choices(mysqli $conn, array $ctx, array $l): array {
    $urls = social_photos((string)$l['market'], (string)$l['listing_key']);
    if (!$urls && (string)$l['primary_photo'] !== '') $urls = [(string)$l['primary_photo']];
    $out = array_map(fn($u) => ['id' => $u, 'thumb' => social_img($u, 400), 'own' => false], $urls);
    foreach (social_uploads($ctx) as $id) $out[] = ['id' => 'upload:' . $id, 'thumb' => social_upload_url($ctx, $id, 400), 'own' => true];
    return $out;
}

/**
 * The posted form as a normalised flyer for flyer_render(), or a message
 * (string) when there is nothing to render yet. The listing must be the
 * account's; each photo one the site listed for it or one of this account's
 * uploads (up to six, in the order posted); the QR choice one of
 * flyer_qr_options(); lengths capped.
 */
function flyer_post_from_request(mysqli $conn, array $ctx, array $in): array|string {
    $l = social_listing($conn, $ctx, (string)($in['listing'] ?? ''));
    if (!$l) return 'Choose one of your listings to see your flyer.';

    $allowed = (array)($_SESSION['social_photos'][$l['id']] ?? []);
    if ((string)$l['primary_photo'] !== '') $allowed[] = (string)$l['primary_photo'];
    $photos = [];
    foreach (array_slice((array)($in['photo'] ?? []), 0, 6) as $i => $ph) {
        $ph = (string)$ph;
        if (str_starts_with($ph, 'upload:') && ($f = social_upload_file($ctx, substr($ph, 7)))) {
            $photos[] = ['id' => $ph, 'url' => social_upload_url($ctx, substr($ph, 7), 1600), 'file' => $f];
        } elseif (in_array($ph, $allowed, true)) {
            $photos[] = ['id' => $ph, 'url' => social_img($ph, $i === 0 ? 2048 : 1600), 'file' => null];
        }
    }

    $txt   = fn(string $k, int $max, string $default = '') => mb_substr(trim((string)($in[$k] ?? $default)), 0, $max);
    $agent = flyer_agent($conn, $ctx);
    $qrs   = flyer_qr_options($conn, $ctx, $l);
    $qk    = (string)($in['qr'] ?? '');
    if (!isset($qrs[$qk])) $qk = array_key_first($qrs);
    $qurl  = $qrs[$qk][1];

    return [
        'listing' => $l,
        'title'   => $txt('title', 80),
        'address' => $txt('address', 90, trim((string)$l['address']) . (trim((string)$l['city']) !== '' ? ', ' . trim((string)$l['city']) : '')),
        'price'   => $txt('price', 40, (string)$l['price_text']),
        'beds'    => $txt('beds', 10, $l['beds'] !== null && (float)$l['beds'] > 0 ? social_num($l['beds']) : ''),
        'baths'   => $txt('baths', 10, $l['baths'] !== null && (float)$l['baths'] > 0 ? social_num($l['baths']) : ''),
        'sqft'    => $txt('sqft', 12, $l['sqft'] !== null && (int)$l['sqft'] > 0 ? number_format((int)$l['sqft']) : ''),
        'notes'   => mb_substr(trim((string)($in['notes'] ?? '')), 0, 900),
        'photos'  => $photos,
        'agent'   => [
            'name'  => $txt('agent_name', 60, $agent['name']),
            'phone' => $txt('agent_phone', 30, $agent['phone']),
            'email' => $txt('agent_email', 80, $agent['email']),
        ],
        'qr'      => $qk,
        'qr_url'  => $qurl,
        'qr_svg'  => flyer_qr_svg($qurl),
    ];
}

/** "1100-n-frontage-rd-w-1513-flyer-20261013.pdf" */
function flyer_filename(array $p): string {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)$p['listing']['address']), '-')) ?: 'listing';
    return mb_substr($slug, 0, 60) . '-flyer-' . date('Ymd') . '.pdf';
}

/**
 * The flyer as PDF bytes: the HTML written to a private temp file and printed
 * by headless Chromium (page size from @page). Null when Chromium failed
 * (logged to the PHP error log). The same lock as the Instagram export: one
 * Chromium at a time.
 */
function flyer_export_pdf(string $html): ?string {
    $dir = rtrim(sys_get_temp_dir(), '/') . '/mh-social';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { error_log('flyer_export: cannot create ' . $dir); return null; }
    $id  = bin2hex(random_bytes(8));
    $in  = "{$dir}/{$id}.html";
    $out = "{$dir}/{$id}.pdf";
    if (file_put_contents($in, $html) === false) { error_log('flyer_export: cannot write ' . $in); return null; }

    $lock = fopen("{$dir}/.lock", 'c');
    if ($lock) flock($lock, LOCK_EX);
    set_time_limit(120);
    $cmd = 'HOME=' . escapeshellarg($dir) . ' /usr/bin/timeout 60 ' . escapeshellarg(SOCIAL_CHROMIUM)
         . ' --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage --no-first-run --disable-extensions --mute-audio'
         . ' --virtual-time-budget=10000 --no-pdf-header-footer --print-to-pdf-no-header'
         . ' --user-data-dir=' . escapeshellarg("{$dir}/profile")
         . ' --print-to-pdf=' . escapeshellarg($out) . ' ' . escapeshellarg('file://' . $in) . ' 2>&1';
    exec($cmd, $lines, $rc);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }

    $pdf = is_file($out) ? file_get_contents($out) : false;
    @unlink($in);
    @unlink($out);
    if ($pdf === false || $pdf === '') {
        error_log('flyer_export: chromium rc=' . $rc . ' ' . implode(' | ', array_slice($lines, -3)));
        return null;
    }
    return $pdf;
}
