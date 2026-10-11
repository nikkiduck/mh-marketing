<?php
/**
 * tests/render_social_sample.php — renders both social post templates with
 * sample data to HTML files, for design work and for a headless Chromium
 * screenshot. No database, no sign-in: inc/social_render.php only.
 *
 *   php tests/render_social_sample.php [outdir] [asset base]
 *
 * The asset base defaults to this checkout as a file:// URL, so fonts and
 * logos come from the working folder; pass https://marketing.monthaus.com
 * to render against the live assets.
 */
require __DIR__ . '/../inc/social_render.php';

$out  = rtrim($argv[1] ?? sys_get_temp_dir(), '/');
$base = $argv[2] ?? ('file://' . realpath(__DIR__ . '/..'));
$photo = 'https://site.monthaus.com/img.php?u=' . rawurlencode('https://photos.anpcdn.com/base/57be43_e1f413326388da72af2e955330ca24d3.jpg') . '&w=1600';

$agent = ['name' => 'Jonathan Boxer', 'title' => 'CEO', 'phone' => '(970) 948-4802',
          'headshot' => 'https://marketing.monthaus.com/agent-photos/jonathan-boxer-square.jpg'];

$posts = [
    'featured' => ['type' => 'featured', 'label' => 'Featured Listing', 'photo_url' => $photo, 'focus_x' => 50, 'focus_y' => 50,
        'headline' => '633 W Main Street', 'subline' => 'Aspen, Colorado', 'price' => '$9,500,000',
        'specs' => social_specs_text(3, 3, 2969), 'note' => 'A West End Victorian, steps from downtown',
        'agent' => $agent, 'show_headshot' => true],
    'openhouse' => ['type' => 'openhouse', 'photo_url' => $photo, 'focus_x' => 50, 'focus_y' => 50,
        'headline' => '633 W Main Street', 'subline' => 'Aspen, Colorado', 'price' => '$9,500,000',
        'specs' => social_specs_text(3, 3, 2969), 'note' => '',
        'date_text' => social_date_text('2026-10-18'), 'time_text' => social_time_range_text('11:00', '14:00'),
        'agent' => $agent, 'show_headshot' => true],
];
foreach ($posts as $k => $p) {
    file_put_contents("$out/social-$k.html", social_render($p, $base));
    echo "$out/social-$k.html\n";
}
