<?php
/**
 * render_market_sample.php — the Market Report and every Market Snapshot
 * card, rendered from the data files alone, with no database and no portal.
 *
 *   php tests/render_market_sample.php [out dir]
 *
 * Writes tests/market-preview/ (git-ignored): report.html (the body under a
 * plain portal-like shell), one HTML file per card in both looks, the story
 * size for each, and a contact sheet index.html. Serve the project folder
 * (python3 -m http.server, the "marketing-static" launch entry) and open
 * /tests/market-preview/ to look at them: the assets resolve from /assets.
 * On a Mac the stock photos are not on disk, so the backdrop is the plain
 * ground; the server has them. The figures printed for each card are the
 * ones to check against the artifact's CLAUDE.md, section 10.
 */
require_once dirname(__DIR__) . '/inc/market_data.php';
require_once dirname(__DIR__) . '/inc/market_card_render.php';

$out = rtrim($argv[1] ?? (__DIR__ . '/market-preview'), '/');
if (!is_dir($out) && !mkdir($out, 0775, true)) { fwrite(STDERR, "cannot create $out\n"); exit(1); }

$month = market_pick_month($argv[2] ?? '');
if ($month === '') { fwrite(STDERR, "no month on file under inc/market_data\n"); exit(1); }
$data = market_load($month);
if (!$data) { fwrite(STDERR, "could not load $month\n"); exit(1); }
echo "Month {$month} (" . market_month_label($month) . ")\n";
// The feed towns, from a saved site response and the cached registry (MARKET_FEED and MARKET_AREAS point at the files);
// without them the samples show the CAR cards and tables only.
$feedFile = (string)getenv('MARKET_FEED'); $areasFile = (string)getenv('MARKET_AREAS');
if ($feedFile !== '' && is_file($feedFile) && $areasFile !== '' && is_file($areasFile)) {
    $feed = json_decode((string)file_get_contents($feedFile), true); $reg = json_decode((string)file_get_contents($areasFile), true);
    $data['towns_feed'] = market_feed_towns((array)$feed, (array)($reg['areas'] ?? []), array_column($data['boards'], 'market'));
    $data['counties_feed'] = market_feed_counties((array)$feed, array_column($data['boards'], 'market'));
    $data['feed_note'] = (string)($feed['note'] ?? '');
    echo '  feed counties: ' . count($data['counties_feed']) . "\n";
    echo '  feed towns: ' . count($data['towns_feed']) . ': ' . implode(', ', array_map(fn($t) => $t['label'] . ' (' . $t['market'] . ')', $data['towns_feed'])) . "\n";
} else { $data['towns_feed'] = []; $data['counties_feed'] = []; $data['feed_note'] = ''; }

$files = [];
foreach (array_keys(market_cards($data)) as $market) {
    foreach (['SF'] as $pt) {
        $card = market_card_model($data, $market, ['ptype' => $pt]);
        if (!$card) { echo "  {$market} {$pt}: no card\n"; continue; }
        if ($pt === 'SF') {
            echo "  {$market}: {$card['title']} | {$card['hero']['value']} {$card['hero']['change']}\n";
            foreach ($card['stats'] as [$l, $v, $n]) echo "      {$l}: {$v} ({$n})\n";
        }
        foreach (['dark', 'light'] as $theme) {
            $name = "{$market}-{$pt}-{$theme}.html";
            file_put_contents("$out/$name", market_card_render($card, ['format' => 'post', 'theme' => $theme, 'mark' => 'triangle'], ''));
            $files[] = [$name, 1350];
        }
        if ($pt === 'SF') {
            $name = "{$market}-story.html";
            file_put_contents("$out/$name", market_card_render($card, ['format' => 'story', 'theme' => 'dark', 'mark' => 'triangle'], ''));
            $files[] = [$name, 1920];
            $picked = market_card_model($data, $market, ['ptype' => 'SF', 'stats' => ['pct', 'median', 'supply']]);   // an agent's own three rows
            $name = "{$market}-picked-light.html";
            file_put_contents("$out/$name", market_card_render($picked, ['format' => 'post', 'theme' => 'light', 'mark' => 'mmark', 'backdrop' => '/assets/images/social-placeholder.jpg'], ''));
            $files[] = [$name, 1350];
            if ($pt === 'SF' && $market === 'pitkin') echo '  pitkin offers: ' . implode(', ', $card['pool_keys']) . "; picked: " . implode(', ', $picked['stat_keys']) . "\n";
        }
    }
}

// The report under a plain shell with the portal's stylesheet.
$boot = market_boot($data, ['post' => 'index.html', 'self' => 'report.html']);
ob_start();
include dirname(__DIR__) . '/inc/market_report_body.php';
$body = ob_get_clean();
file_put_contents("$out/report.html", '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>Market Report sample</title><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">'
    . '<link rel="stylesheet" href="/assets/css/portal.css"></head><body><main class="pt-main">' . $body . '</main></body></html>');

$tiles = '';
foreach ($files as [$name, $hgt]) {
    $tiles .= '<figure><a href="' . htmlspecialchars($name) . '"><iframe src="' . htmlspecialchars($name) . '" width="1080" height="' . $hgt . '" style="transform:scale(.25);transform-origin:0 0;border:0;pointer-events:none" loading="lazy"></iframe></a><figcaption>' . htmlspecialchars($name) . '</figcaption></figure>';
}
file_put_contents("$out/index.html", '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Market samples</title><style>body{font:14px system-ui;margin:20px}figure{display:inline-block;margin:0 12px 24px 0;width:270px;height:0;padding-bottom:0;vertical-align:top}figure a{display:block;width:270px;height:338px;overflow:hidden;background:#eee}figcaption{font-size:12px;color:#555;margin-top:4px}</style></head><body>'
    . '<p><a href="report.html">report.html</a> · ' . count($files) . ' cards</p>' . $tiles . '</body></html>');
echo "Wrote " . (count($files) + 2) . " files to {$out}\n";

// The builder under the same shell (its preview needs a server that answers POST /__render:
// a local php -S router outside the repo did that on 2026-10-18; under python's server the
// frame shows the server's 501 page and the form is still there to look at).
require_once dirname(__DIR__) . '/inc/social_render.php';   // social_marks(), standalone
$render_url = '/__render'; $export_url = '/__render'; $report_url = 'report.html'; $csrf = 'sample';
$months = $data['months']; $market = 'pitkin'; $board_names = array_column($data['boards'], 'name', 'market');
$stock = array_slice(json_decode((string)file_get_contents(dirname(__DIR__) . '/assets/stock/stock.json'), true) ?: [], 0, 14, true);   // the first 14 names, with the placeholder standing in for every picture
$stock_thumb = fn(string $n) => '/assets/images/social-placeholder.jpg';
$uploads = ['sample0000000001', 'sample0000000002']; $upload_url = '/__upload'; $delete_url = '/__delete';   // two of the person's own photos and the drop box (2026-10-19)
$upload_thumb = fn(string $id) => '/assets/images/social-placeholder.jpg';
ob_start();
include dirname(__DIR__) . '/inc/market_post_body.php';
$body = ob_get_clean();
file_put_contents("$out/builder.html", '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>Market Snapshot sample</title><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">'
    . '<link rel="stylesheet" href="/assets/css/portal.css"></head><body><main class="pt-main"><p class="pt-soon">From the Market Report.</p><h1 class="pt-h1">Market Snapshot</h1>'
    . '<p class="pt-lead">One place\'s month on a card: the average sale price, closed sales, days on market and supply, Pick the place and the look, then download.</p>' . $body . '</main></body></html>');
echo "Wrote builder.html\n";
