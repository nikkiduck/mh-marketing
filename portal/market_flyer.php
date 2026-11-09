<?php
/**
 * portal/market_flyer.php — the Market Report as a one-page PDF flyer for a
 * client (Nikki, 2026-11-08): GET market=<tab> &pt=SF|TC &m=YYYY-MM →
 * application/pdf attachment, the document inc/market_flyer_render.php draws,
 * printed by headless Chromium (flyer_export_pdf()). The footer carries the
 * signed-in agent's square headshot, name and contact lines (or an admin's
 * previewed agent, ?preview=); an admin with no agent profile gets Jonathan
 * Boxer's (MARKET_FLYER_FALLBACK_EMAIL; Nikki, 2026-11-09: a flyer always
 * carries an agent's contact lines).
 * &html=1 (admins) returns the HTML itself, for a look in the browser.
 *
 * Admin-only until Nikki has reviewed the style (MARKET_FLYER_LIVE in
 * inc/market.php): agents see no button and this page turns them away.
 */
require_once __DIR__ . '/../inc/market.php';
require_once __DIR__ . '/../inc/flyer.php';                 // flyer_export_pdf(), flyer_agent()
require_once __DIR__ . '/../inc/market_flyer_render.php';

/** The agent whose contact lines the flyer carries when nobody is signed in as one (an admin with no profile; Nikki, 2026-11-09: "use Jonathan Boxer's info"). */
const MARKET_FLYER_FALLBACK_EMAIL = 'jonathan.boxer@monthaus.com';

$ctx = market_gate($conn);
if (!MARKET_FLYER_LIVE && !is_elevated_admin()) portal_stop('Coming soon', 'The Market Report flyer is still being set up. It will appear on the report when it is ready.');

$month = market_pick_month((string)($_GET['m'] ?? ''));
$data  = $month !== '' ? market_load($month) : null;
if (!$data) portal_stop('Nothing to print yet', 'No market data is on file for that month.');
market_attach_feed($data);   // the Communities towns from our own feed, the report's second town table

$market = preg_replace('/[^a-z]/', '', (string)($_GET['market'] ?? ''));
$pt     = (($_GET['pt'] ?? 'SF') === 'TC') ? 'TC' : 'SF';
$place  = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['place'] ?? ''));   // a card key (market_cards()), or blank for the whole market

// The agent on the footer: the signed-in (or previewed) agent, else Jonathan Boxer.
$flyerAgent = function (array $acct) use ($conn): array {
    $agent = flyer_agent($conn, ['acct' => $acct]) + ['photo' => '', 'photo_url' => ''];
    // The face crop when there is one, else the full headshot; both live in agent-photos/ (inc/photos.php).
    $url = (string)(($acct['headshot_face_url'] ?? '') ?: ($acct['headshot_url'] ?? ''));
    if ($url !== '') {
        $disk = __DIR__ . '/../agent-photos/' . basename(parse_url($url, PHP_URL_PATH) ?: '');
        if (is_file($disk)) { $agent['photo'] = $disk; $agent['photo_url'] = $url; }
    }
    return $agent;
};
$agent = $ctx ? $flyerAgent((array)$ctx['acct']) : ['name' => ''];
if ($agent['name'] === '') {
    $st = $conn->prepare("SELECT id, headshot_face_url, headshot_url FROM marketing_intakes WHERE LOWER(mh_email) = ? AND status = 'active' ORDER BY (headshot_face_url IS NULL), id LIMIT 1");
    $em = MARKET_FLYER_FALLBACK_EMAIL;
    $st->bind_param('s', $em);
    $st->execute();
    $fb = $st->get_result()->fetch_assoc();
    $st->close();
    $agent = $fb ? $flyerAgent($fb) : ['name' => '', 'phone' => '', 'email' => '', 'photo' => '', 'photo_url' => ''];
}

$f = market_flyer_model($data, $market, $pt, $agent, $place);
if (!$f) portal_stop('Nothing to print', 'There are no figures for that market in ' . ph(market_month_label($month)) . '.');

if (isset($_GET['html']) && is_elevated_admin()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo market_flyer_render($f, SITE_URL, false);
    exit;
}

$pdf = flyer_export_pdf(market_flyer_render($f, SITE_URL, true));
if ($pdf === null) {
    portal_stop('The flyer could not be made', 'Something went wrong making the PDF. Please try again in a minute; if it keeps happening, email '
        . PORTAL_MARKETING_EMAIL . ' and we will sort it out.');
}
$name = market_flyer_filename($f);
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo $pdf;
