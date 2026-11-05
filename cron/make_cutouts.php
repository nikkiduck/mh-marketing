<?php
/**
 * cron/make_cutouts.php — the broker cutouts for the Broker Feature, by
 * hand (not scheduled): every approved agent with a headshot hosted here
 * and no cutout gets one through Leonardo.Ai (inc/cutout.php). Run it after
 * LEONARDO_API_KEY lands, after a run of cron/import_profiles.php, or to
 * retry the ones a bad connection skipped.
 *
 *   php cron/make_cutouts.php [--dry-run] [--force] [--id=N]
 *
 *   --dry-run  say what would be made, make nothing
 *   --force    remake cutouts that exist (a credit each)
 *   --id=N     one agent (the marketing_intakes id)
 */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/plan.php';       // plan_agents(): the approved roster with headshot_url
require_once __DIR__ . '/../inc/cutout.php';

$dry = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);
$only = 0;
foreach ($argv as $a) if (preg_match('/^--id=(\d+)$/', $a, $m)) $only = (int)$m[1];

$agents = plan_agents($conn);
if ($only) $agents = array_filter($agents, fn($a) => (int)$a['id'] === $only);
echo "Cutouts for " . count($agents) . " agent(s)" . ($dry ? ' (DRY RUN)' : '') . ($force ? ' (--force: remaking the ones that exist)' : '') . "\n";
if (!defined('LEONARDO_API_KEY') || LEONARDO_API_KEY === '') echo "   ! LEONARDO_API_KEY is not set in inc/db.php: nothing can be made\n";
$made = $kept = $skipped = $failed = 0;
foreach ($agents as $a) {
    $file = mk_photo_file($a['headshot_url']);
    if ($file === '' || !is_file($file)) { echo "   - {$a['name']}: no headshot hosted here\n"; $skipped++; continue; }
    $have = mk_cutout_find($a['headshot_url'], $a['slug']);
    if ($have !== '' && !$force) { echo "   = {$a['name']}: has " . basename($have) . "\n"; $kept++; continue; }
    echo "   + {$a['name']}: " . basename($file) . " → " . basename(mk_cutout_path_for($file)) . ($dry ? " (would make)" : '') . "\n";
    if ($dry) continue;
    $r = mk_cutout_refresh($a['headshot_url'], $a['slug']);
    if (!empty($r['ok'])) { echo "     ✓ " . number_format($r['bytes'] / 1024) . " KB\n"; $made++; }
    else { echo "     ✗ " . $r['error'] . "\n"; $failed++; }
}
echo "\nmade {$made}, already there {$kept}, no headshot {$skipped}, failed {$failed}\n";
