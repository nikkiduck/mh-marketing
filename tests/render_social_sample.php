<?php
/**
 * tests/render_social_sample.php — renders the social post templates with
 * sample data to HTML files, for design work and for a headless Chromium
 * screenshot. No database, no sign-in: inc/social_render.php only.
 *
 *   php tests/render_social_sample.php [outdir] [asset base]
 *
 * The asset base defaults to this checkout as a file:// URL, so fonts and
 * logos come from the working folder; pass https://marketing.monthaus.com
 * to render against the live assets. Four files: featured and openhouse,
 * each dark and light.
 */
require __DIR__ . '/../inc/social_render.php';

$out  = rtrim($argv[1] ?? sys_get_temp_dir(), '/');
$base = $argv[2] ?? ('file://' . realpath(__DIR__ . '/..'));
$photo = 'https://site.monthaus.com/img.php?u=' . rawurlencode('https://photos.anpcdn.com/base/57be43_e1f413326388da72af2e955330ca24d3.jpg') . '&w=1600';

$lines = [
    ['text' => 'Aspen, Colorado', 'style' => 'town'],
    ['text' => '$9,500,000', 'style' => 'price'],
    ['text' => social_specs_text(3, 3, 2969), 'style' => 'details'],
    ['text' => 'A West End Victorian, steps from downtown', 'style' => 'note'],
];
$common = ['photo_url' => $photo, 'zoom' => 1.25, 'focus_x' => 55, 'focus_y' => 40, 'headline' => '633 W Main Street', 'lines' => $lines];
$when = ['date_text' => social_date_text('2026-10-18'), 'time_text' => social_time_range_text('11:00', '14:00')];
$posts = [
    'photo'           => $common + ['type' => 'photo', 'label' => 'Just Listed', 'date_text' => '', 'time_text' => ''],
    'photo-openhouse' => $common + ['type' => 'photo_openhouse', 'label' => 'Open House'] + $when,
    'featured'        => $common + ['type' => 'featured', 'label' => 'Featured Listing', 'date_text' => '', 'time_text' => ''],
    'openhouse'       => $common + ['type' => 'openhouse', 'label' => 'Open House'] + $when,
    'story'           => $common + ['type' => 'story', 'label' => 'Just Listed', 'date_text' => '', 'time_text' => ''],
    'story-m'         => $common + ['type' => 'story', 'mark' => 'mmark', 'label' => 'Just Listed', 'date_text' => '', 'time_text' => ''],
    'story-logo'      => $common + ['type' => 'story', 'story_logo' => true, 'label' => 'Just Listed', 'date_text' => '', 'time_text' => ''],
    'story-oh'        => $common + ['type' => 'story_openhouse', 'story_logo' => true, 'label' => 'Open House', 'when_text' => ''] + $when,
    'post-other'      => ['type' => 'photo_other', 'label' => '', 'photo_url' => 'https://marketing.monthaus.com/assets/images/social-placeholder.jpg', 'zoom' => 1, 'focus_x' => 50, 'focus_y' => 50, 'headline' => 'Alpine Roots. Global Vision.', 'lines' => [['text' => 'Mont Haus International Realty', 'style' => 'town']], 'date_text' => '', 'time_text' => ''],
    'story-openhouse' => $common + ['type' => 'story_openhouse', 'label' => 'Open House'] + $when,
];
foreach ($posts as $k => $p) {
    foreach (['dark', 'light'] as $theme) {
        file_put_contents("$out/social-$k-$theme.html", social_render($p + ['theme' => $theme], $base));
        echo "$out/social-$k-$theme.html\n";
    }
}
