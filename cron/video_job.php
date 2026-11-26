<?php
/**
 * cron/video_job.php — the worker ONE video is made in (the Video tool,
 * 2026-11-26). Not on the crontab: video_job_start() (inc/video.php) starts
 * it, detached, when the page's Make video is pressed:
 *
 *   /usr/bin/php cron/video_job.php <job folder>
 *
 * The folder holds job.json (the normalised video, already checked by
 * video_from_request()); this writes status.json as it goes (the page reads
 * it every two seconds) and leaves out.mp4. No database, no session: only
 * inc/video_export.php.
 *
 * One video at a time on the box: a lock beside the people's folders
 * (CREATIVES_DIR/video/.lock), waited for. The worker also marks itself and
 * its children as the first thing to stop if the box runs out of memory, so
 * a video that is too much for it fails alone and Apache is left standing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../inc/video_export.php';

$dir = rtrim((string)($argv[1] ?? ''), '/');
$job = $dir !== '' && is_file("{$dir}/job.json") ? json_decode((string)file_get_contents("{$dir}/job.json"), true) : null;
if (!is_array($job)) { fwrite(STDERR, "no job.json in '{$dir}'\n"); exit(1); }

set_time_limit(0);
@file_put_contents('/proc/self/oom_score_adj', '800');
$pid = getmypid(); $t0 = time();
$write = function (array $s) use ($dir, $pid): void {
    $tmp = "{$dir}/status.json.{$pid}";
    if (file_put_contents($tmp, json_encode($s + ['pid' => $pid, 'updated' => time()])) !== false) { @chmod($tmp, 0664); rename($tmp, "{$dir}/status.json"); }
};
$write(['state' => 'queued', 'step' => 'Starting', 'pct' => 0]);

$lock = fopen(dirname($dir, 3) . '/.lock', 'c');
while ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    $write(['state' => 'queued', 'step' => 'Waiting for another video to finish', 'pct' => 0]);
    sleep(2);
    if (time() - $t0 > 1800) { $write(['state' => 'failed', 'error' => 'Another video was still being made after half an hour. Please try again.']); exit(1); }
}

$t1 = time();
try {
    $r = video_build($job, $dir, fn(string $step, int $pct) => $write(['state' => 'running', 'step' => $step, 'pct' => $pct]));
} catch (Throwable $e) {
    error_log('video_job: ' . $e->getMessage());
    $r = ['ok' => false, 'error' => 'The video could not be made. Please try again in a minute.'];
    video_tidy($dir);
}
if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
if (empty($r['ok'])) { video_tidy($dir); $write(['state' => 'failed', 'error' => (string)$r['error']]); exit(1); }
@chmod($r['file'], 0664);
$write(['state' => 'done', 'step' => 'Ready', 'pct' => 100, 'name' => (string)($job['name'] ?? 'MH_video'), 'seconds' => $r['seconds'], 'took' => time() - $t1]);
