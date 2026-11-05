<?php
/**
 * tests/render_plan_sample.php — renders the Social Media Plan templates with
 * sample data to HTML files, for design work and a headless Chromium
 * screenshot. No database, no sign-in: inc/plan_render.php (the split
 * layout) and inc/social_render.php (everything full-photo or centred).
 *
 *   php tests/render_plan_sample.php [outdir] [asset base]
 *
 * The asset base defaults to this checkout as a file:// URL; pass
 * https://marketing.monthaus.com (or a local server's origin) to render
 * against served assets. The faces and headshots are the live portal's.
 */
require __DIR__ . '/../inc/plan_render.php';
require __DIR__ . '/../inc/plan_agent_render.php';

$out  = rtrim($argv[1] ?? sys_get_temp_dir(), '/');
$base = $argv[2] ?? ('file://' . realpath(__DIR__ . '/..'));
$live = 'https://marketing.monthaus.com';
$head = $live . '/agent-photos/jackson-horn.jpg';
$faces = [];
foreach ([['Jackson Horn', 'jackson-horn'], ['Sierrah Smith', 'sierrah-smith'], ['Whit Ward', 'whit-ward'], ['Allison Byford', 'allison-byford'], ['Bryan Peterson', 'bryan-peterson'], ['Trent Jones', 'trent-jones']] as [$n, $s]) {
    $faces[] = ['name' => $n, 'first' => explode(' ', $n)[0], 'url' => $live . '/agent-photos/' . $s . '-square.jpg', 'file' => null];
}
$stock = fn(string $n) => $live . '/assets/stock/' . $n . '.jpg';
$listingPhoto = 'https://monthaus.com/img.php?u=' . rawurlencode('https://photos.anpcdn.com/base/57be43_e1f413326388da72af2e955330ca24d3.jpg') . '&w=1600';

$broker = ['theme' => 'dark', 'mark' => 'triangle', 'logo_kind' => 'full', 'logo' => 'top', 'badge' => 'left', 'label' => 'Broker Spotlight',
           'photo_url' => $head, 'zoom' => 1.0, 'focus_x' => 50, 'focus_y' => 30,
           'headline' => 'Thinking of buying or selling in Montrose? Call Jackson Horn with Mont Haus.',
           'lines' => [['text' => 'Jackson Horn', 'style' => 'town'], ['text' => 'Mont Haus International Realty · Montrose', 'style' => 'details'], ['text' => 'Broker Associate', 'style' => 'plain']],
           'team' => []];
$location = ['theme' => 'dark', 'mark' => 'triangle', 'logo_kind' => 'full', 'logo' => 'top', 'badge' => 'left', 'label' => 'Meet the Team',
             'photo_url' => $stock('ridgway-mount-sneffels-fall-198784448'), 'zoom' => 1.0, 'focus_x' => 50, 'focus_y' => 50,
             'headline' => 'Our brokers in Southwest Colorado',
             'lines' => [['text' => 'Mont Haus International Realty', 'style' => 'town'], ['text' => 'Telluride · Ridgway · Ouray · Montrose · Durango', 'style' => 'details']],
             'team' => array_slice($faces, 0, 3)];
$office = ['theme' => 'light', 'mark' => 'mmark', 'logo_kind' => 'mark', 'logo' => 'top', 'badge' => 'right', 'label' => 'Office Feature',
           'photo_url' => $stock('aspen-aerial-fall-1110909378'), 'zoom' => 1.2, 'focus_x' => 50, 'focus_y' => 40,
           'headline' => "Mont Haus Aspen", 'lines' => [['text' => 'Aspen, Colorado', 'style' => 'town'], ['text' => 'Mont Haus International Realty', 'style' => 'details'], ['text' => 'The valley from the office window, every day.', 'style' => 'note'], ['text' => '', 'style' => 'plain']],
           'team' => []];

$opts = ['logo_color' => 'white', 'logo_scale' => 1.3, 'logo_dx' => -20, 'logo_dy' => 10, 'logo_shadow' => 60, 'text_shadow' => 50];
$splits = [
    'broker-badge-body' => ['badge' => 'body'] + $broker + ['format' => 'story'],
    'broker-options'    => $opts + $broker + ['format' => 'post'],
    'location-light-white-logo' => ['logo_color' => 'white', 'logo_shadow' => 40] + $office + ['format' => 'post'],
    'broker-post'       => $broker + ['format' => 'post'],
    'broker-story'      => $broker + ['format' => 'story'],
    'broker-square'     => $broker + ['format' => 'square'],
    'broker-mark-none'  => ['logo' => 'none', 'mark' => 'none', 'label' => ''] + $broker + ['format' => 'post'],
    'location-team'     => $location + ['format' => 'story'],
    'location-team-6'   => ['team' => $faces] + $location + ['format' => 'post'],
    'location-team-sq'  => ['team' => array_slice($faces, 0, 4)] + $location + ['format' => 'square'],
    'location-office'   => $office + ['format' => 'post'],
];
foreach ($splits as $k => $p) {
    foreach (['dark', 'light'] as $theme) {
        file_put_contents("$out/plan-$k-$theme.html", plan_render_split(['theme' => $theme] + $p, $base));
        echo "$out/plan-$k-$theme.html\n";
    }
}

// The property story set and the branding story, through social_render() as inc/plan.php composes them.
$common = ['theme' => 'dark', 'mark' => 'triangle', 'logo_kind' => 'full', 'badge' => 'left', 'format' => 'story', 'photo_url' => $listingPhoto, 'zoom' => 1.0, 'focus_x' => 50, 'focus_y' => 50,
           'date_text' => '', 'time_text' => '', 'when_text' => '', 'type' => 'story_other'];
$set = [
    'set-01-cover' => $common + ['layout' => 'corner', 'logo' => 'bottom', 'label' => 'New Listing', 'headline' => '633 W Main St',
                                 'lines' => [['text' => 'Aspen, Colorado', 'style' => 'town'], ['text' => '$9,500,000', 'style' => 'price']]],
    'set-02'       => $common + ['layout' => 'corner', 'logo' => 'none', 'label' => '', 'headline' => '',
                                 'lines' => [['text' => '633 W Main St', 'style' => 'town'], ['text' => 'Aspen  ·  $9,500,000', 'style' => 'details']]],
    'set-03-end'   => $common + ['layout' => 'center', 'logo' => 'center', 'label' => 'New Listing', 'headline' => "633 W Main St\nAspen",
                                 'lines' => [['text' => '$9,500,000', 'style' => 'price'], ['text' => 'Listed by Jonathan Boxer', 'style' => 'note']]],
    'full-badge-body' => ['badge' => 'body', 'focus_y' => 20] + $common + ['layout' => 'corner', 'logo' => 'top', 'badge' => 'body', 'label' => 'Broker Spotlight', 'photo_url' => $head, 'focus_y' => 20, 'headline' => 'Thinking of buying or selling in Montrose? Call Jackson Horn with Mont Haus.',
                                 'lines' => [['text' => 'Jackson Horn', 'style' => 'town'], ['text' => 'Mont Haus International Realty · Montrose', 'style' => 'details']]],
    'branding-light'  => ['theme' => 'light'] + $common + ['layout' => 'center', 'logo' => 'center', 'label' => '', 'photo_url' => $stock('vail-aspens-fall-1746156502'), 'headline' => "Gold season.",
                                 'lines' => [['text' => 'Mont Haus International Realty', 'style' => 'town']]],
    'branding-options' => ['badge' => 'body'] + $common + ['layout' => 'center', 'logo' => 'center', 'label' => 'Coming Soon', 'photo_url' => $stock('vail-aspens-fall-1746156502'), 'headline' => "Gold season.", 'logo_scale' => 1.4, 'logo_dy' => -30, 'logo_shadow' => 70, 'text_shadow' => 80,
                                 'lines' => [['text' => 'Mont Haus International Realty', 'style' => 'town']]],
    'branding'     => $common + ['layout' => 'center', 'logo' => 'center', 'label' => '', 'photo_url' => $stock('vail-aspens-fall-1746156502'), 'headline' => "Gold season.",
                                 'lines' => [['text' => 'Mont Haus International Realty', 'style' => 'town']]],
];
foreach ($set as $k => $p) {
    file_put_contents("$out/plan-$k.html", social_render($p, $base));
    echo "$out/plan-$k.html\n";
}
// The set's photo frames (2026-11-02): the photo alone, the logo lower right on every other one.
$frame = ['theme' => 'dark', 'logo_kind' => 'full', 'photo_url' => $listingPhoto, 'zoom' => 1.0, 'focus_x' => 50, 'focus_y' => 50];
foreach (['frame-story-logo' => $frame + ['format' => 'story', 'show_logo' => true], 'frame-story-plain' => $frame + ['format' => 'story', 'show_logo' => false],
          'frame-post-logo' => $frame + ['format' => 'post', 'show_logo' => true, 'logo_color' => 'black', 'logo_scale' => 0.8, 'logo_shadow' => 50],
          'frame-square-mark' => $frame + ['format' => 'square', 'show_logo' => true, 'logo_kind' => 'mark']] as $k => $p) {
    file_put_contents("$out/plan-$k.html", plan_render_photo_frame($p, $base));
    echo "$out/plan-$k.html\n";
}

// The Featured Agent design (2026-11-02): the Cutout style, every size, both looks, and the call line in each of its faces.
$agent = ['mark' => 'triangle', 'logo' => 'top', 'logo_kind' => 'full', 'logo_scale' => 1.3, 'badge' => 'left', 'label' => 'Broker Spotlight', 'photo_url' => $head, 'zoom' => 1.0, 'focus_x' => 50, 'focus_y' => 0,   // the tool's defaults of 2026-11-05: the logo lower right at 130 %, the name off
          'headline' => 'Thinking of buying or selling in Montrose? Call Jackson Horn.',
          'lines' => [['text' => 'Jackson Horn', 'style' => 'town'], ['text' => 'Montrose', 'style' => 'details']]];
$cutout = $argv[3] ?? ($live . '/agent-photos/jackson-horn-cutout.png');   // the cutout PNG's URL (pass a local one to design against)
$agentBd = ['backdrop_url' => $stock('ridgway-mount-sneffels-fall-198784448'), 'backdrop_veil' => 70];
foreach (plan_agent_styles() as $style => $name) {
    foreach (['story', 'post', 'square'] as $format) {
        foreach (['dark', 'light'] as $theme) {
            $p = ['style' => $style, 'format' => $format, 'theme' => $theme, 'photo_url' => $cutout] + $agentBd + $agent;
            file_put_contents("$out/agent-$style-$format-$theme.html", plan_render_agent($p, $base));
            echo "$out/agent-$style-$format-$theme.html\n";
        }
    }
}
foreach (['under' => ['who_place' => 'under', 'arrange' => 'left'], 'corner-big' => ['who_place' => 'corner', 'who_name' => 60, 'who_area' => 90, 'arrange' => 'left', 'logo' => 'bottom'],
          'stack-glass' => ['badge_tone' => 'glass'], 'stack-slate-serif' => ['badge_tone' => 'slate', 'hook1' => 'serif', 'hook2' => 'italic'], 'stack-logo-low' => ['logo' => 'bottom']] as $k => $extra) {   // 2026-11-05: the left page and the stack's variants   // the name and area under the hook, or lower right and larger (2026-11-05)
    $p = $extra + ['style' => 'cutout', 'format' => 'story', 'theme' => 'dark', 'photo_url' => $cutout, 'hook2' => 'italic'] + $agentBd + $agent;
    file_put_contents("$out/agent-who-$k.html", plan_render_agent($p, $base));
    echo "$out/agent-who-$k.html\n";
}
foreach (plan_hook_styles() as $h2 => $name) {   // the call line: serif, italic, sans (Nikki, 2026-11-02)
    $p = ['style' => 'cutout', 'format' => 'story', 'theme' => 'dark', 'photo_url' => $cutout, 'hook2' => $h2] + $agentBd + $agent;
    file_put_contents("$out/agent-hook2-$h2.html", plan_render_agent($p, $base));
    echo "$out/agent-hook2-$h2.html\n";
}
