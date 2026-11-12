<?php
/**
 * inc/plan_body.php — the builder for the Social Media Plan templates
 * (2026-11-02): the form on the left, the live preview on the right, as the
 * Instagram tool (inc/social_body.php), whose CSS (assets/css/social.css)
 * and patterns it reuses. One body for the four kinds; $pk says which:
 *
 *   property  a listing from the whole feed (the Listing dropdown), the
 *             variant (New Listing, Featured, Just Sold, Rental), and on a
 *             story the SET: pick the photos in order, an end card, a ZIP
 *   broker    the broker, their market, a hook (or a milestone); their
 *             headshot, a lifestyle shot (an upload) or a stock photo
 *   location  an office, an area or a town; the angle (office, team, local);
 *             the team's faces ticked on or off
 *   branding  a season and one of its taglines on a stock photo
 *
 * Expects: $pk, $ctx (the admin's social context), $csrf, the endpoint URLs
 * ($render_url, $export_url, $photos_url, $upload_url, $delete_url), and the
 * kind's data: $pk_listings (plan_listings()), $pk_agents (plan_agents()),
 * $pk_locations (plan_locations()) + $pk_team ([loc key => [agent ids]]),
 * $pk_seasons (plan_seasons()); $pk_preset from the query string (agent,
 * loc, listing, variant, hook, season, size); $pk_plan (plan_item_context(),
 * the week and slot the page was opened for, or null) and $pk_saved (the
 * saved piece: its form restores into the controls, its caption into the
 * Text step; Save to the week posts to plan_save.php). Nothing is drawn here
 * but the controls: the picture is plan_render.php's HTML in the iframe.
 */
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$pk_preset = (array)($pk_preset ?? []);
$pk_plan = $pk_plan ?? null; $pk_saved = $pk_saved ?? null;
$layouts = plan_layouts();
$offered_layouts = match ($pk) {
    'property' => ['center', 'photo'],      // the cover opens centred, badge and words in the middle, no logo (Nikki, 2026-11-09)
    'branding' => ['center', 'photo'],
    'broker'   => ['photo'],                 // the Broker Feature has its own style (plan_agent_styles(): Cutout alone since 2026-11-02)
    default    => ['split', 'photo', 'center'],
};
$default_layout = $offered_layouts[0];
$agent_styles = $pk === 'broker' ? plan_agent_styles() : [];
$agent_windows = [];   // style => format => [w, h] of the photo window, for the framing box
foreach ($agent_styles as $sk => $sn) foreach (['post', 'square', 'story'] as $fmt) { [, , $ww, $wh] = plan_agent_window($sk, $fmt); $agent_windows[$sk][$fmt] = [$ww, max(1, $wh)]; }
$default_size = isset(social_formats()[(string)($pk_preset['size'] ?? '')]) ? (string)$pk_preset['size'] : ($pk === 'property' ? 'post' : 'story');
// Where the logo starts: upper right over the photo on the split layout (its only place besides none), centred above
// centred words, else the Instagram tool's defaults (upper right on a post, lower left on a story); the badge likewise.
$default_logo  = $pk === 'property' ? 'none' : ($default_layout === 'split' ? 'top' : ($default_layout === 'center' ? 'center' : (($default_size === 'story' && $pk !== 'broker') ? 'bottom' : 'top')));   // the Broker Feature: with the words at every size (2026-11-05, the stack); the property cover: no logo, the M mark comes on the set's third slide (2026-11-09)
$default_badge = $pk === 'property' ? 'body' : (($default_size === 'story' && $default_logo !== 'top') ? 'right' : 'left');
$themes = plan_themes($pk); $labels = plan_labels(); $styles = social_line_styles(); $defaults = social_line_defaults();
$line_names = ['Text 1', 'Text 2', 'Text 3', 'Text 4'];   // the Broker Feature draws none (2026-11-05 evening)
?>
  <div class="sp-grid">
    <form id="spForm" class="sp-form" method="post" action="<?= $e($export_url) ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="kind" value="<?= $e($pk) ?>">
      <input type="hidden" name="focus_x" value="50">
      <input type="hidden" name="focus_y" value="50">
      <?php if ($pk === 'broker'): ?><input type="hidden" name="bd_fx" value="50"><input type="hidden" name="bd_fy" value="50"><?php endif; ?>
      <input type="hidden" name="frame" value="0">
      <?php if ($pk_plan): ?>
      <input type="hidden" name="plan_week" value="<?= $e($pk_plan['week']['key']) ?>">
      <input type="hidden" name="plan_slot" value="<?= (int)$pk_plan['slot'] ?>">
      <input type="hidden" name="plan_action" value="item">
      <?php endif; ?>
      <div id="spFrames"></div>

      <?php // The sections (Nikki, 2026-11-05: "forget the 1, 2, 3 steps ... remove all the explanatory text"): the subject with
            // its own framer (the broker's figure), the size, the text, the elements (logo, watermark, badge), the photo or backdrop. ?>
      <?php if ($pk === 'property'): ?>
      <section class="sp-step">
        <h2 class="sp-step-h">Listing</h2>
        <label class="sp-field">Which listing (every Mont Haus listing in the feed)
          <select name="listing" id="spSubject">
            <?php $want = (string)($pk_preset['listing'] ?? ''); $first = true;
            foreach (plan_listing_groups($pk_listings) as $gname => $group): ?>
            <optgroup label="<?= $e($gname) ?>">
              <?php foreach ($group as $l): $sel = $want !== '' ? $l['id'] === $want : $first; $first = false; ?>
              <option value="<?= $e($l['id']) ?>" data-group="<?= $e($gname) ?>"<?= $sel ? ' selected' : '' ?>><?= $e(implode('  ·  ', array_filter([($l['address'] !== '' ? $l['address'] : 'Address withheld') . ', ' . $l['city'], $l['price_text'], social_status_words((string)$l['status']), $l['agent']]))) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="sp-look">
          <span class="sp-look-l">Post type</span>
          <?php $pv = (string)($pk_preset['variant'] ?? 'featured'); foreach (plan_property_variants() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="variant" value="<?= $e($k) ?>"<?= $k === $pv ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-set">
          <label class="sp-check"><input type="checkbox" name="set" value="1"<?= $default_size === 'story' ? ' checked' : '' ?>> Make a set (several photos as one story or carousel)</label>
          <label class="sp-check sp-only-set"><input type="checkbox" name="endcard" value="1"> Add a closing card (the logo, the address, who listed it)</label>
        </div>
      </section>
      <?php elseif ($pk === 'broker'): ?>
      <section class="sp-step">
        <h2 class="sp-step-h">Broker</h2>
        <label class="sp-field">Which broker
          <select name="agent" id="spSubject">
            <?php $want = (int)($pk_preset['agent'] ?? 0); $first = true; foreach ($pk_agents as $a): $sel = $want ? $a['id'] === $want : $first; $first = false; ?>
            <option value="<?= (int)$a['id'] ?>" data-market="<?= $e($a['market']) ?>" data-figure="<?= $e($a['cutout_url'] !== '' ? $a['cutout_url'] : $a['headshot_url']) ?>"<?= $sel ? ' selected' : '' ?>><?= $e($a['name']) ?><?= $a['market'] !== '' ? ' · ' . $e($a['market']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="sp-two">
          <label class="sp-field">Their market, as it reads in the hook
            <input name="market" id="spMarket" list="spMarkets" maxlength="40" placeholder="Montrose">
            <datalist id="spMarkets">
              <?php foreach (mk_areas() as $a): ?><option value="<?= $e($a['area']) ?>"></option><?php foreach ($a['towns'] as $t): ?><option value="<?= $e($t['label']) ?>"></option><?php endforeach; endforeach; ?>
            </datalist>
          </label>
          <label class="sp-field">Hook
            <select name="hook" id="spHook">
              <?php $wh = (string)($pk_preset['hook'] ?? '0'); foreach (plan_hooks() as $i => $h): ?>
              <option value="<?= $i ?>"<?= (string)$i === $wh ? ' selected' : '' ?> data-tpl="<?= $e($h) ?>"><?= $e(plan_fill($h, ['name' => 'Jackson Horn', 'first' => 'Jackson', 'market' => 'Montrose'])) ?></option>
              <?php endforeach; ?>
              <optgroup label="Milestones">
                <?php foreach (plan_milestones() as $i => $h): ?>
                <option value="m<?= $i ?>"<?= 'm' . $i === $wh ? ' selected' : '' ?> data-tpl="<?= $e($h) ?>"><?= $e(plan_fill($h, ['name' => 'Jackson Horn', 'first' => 'Jackson', 'market' => 'Montrose'])) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <option value="custom"<?= $wh === 'custom' ? ' selected' : '' ?> data-tpl="">Custom: I will type the headline</option>
            </select>
          </label>
        </div>
        <div class="sp-framing">
          <div class="sp-framer empty" id="spFramer" style="aspect-ratio:1080 / 1920"><img id="spFramerImg" alt="" draggable="false"><span class="sp-framer-hint">Drag to move</span></div>
          <label class="sp-zoom">Zoom <input type="range" name="zoom" min="0.6" max="2" step="0.02" value="1"></label>
        </div>
      </section>
      <?php elseif ($pk === 'location'): ?>
      <section class="sp-step">
        <h2 class="sp-step-h">Location</h2>
        <label class="sp-field">Which location
          <select name="loc" id="spSubject">
            <?php $want = (string)($pk_preset['loc'] ?? 'office:aspen'); $groups = [];
            foreach ($pk_locations as $k => $l) $groups[$l['group']][$k] = $l;
            foreach ($groups as $gname => $list): ?>
            <optgroup label="<?= $e($gname) ?>">
              <?php foreach ($list as $k => $l): ?>
              <option value="<?= $e($k) ?>" data-label="<?= $e($l['label']) ?>" data-kind="<?= $e($l['kind']) ?>"<?= $k === $want ? ' selected' : '' ?>><?= $e($l['kind'] === 'office' ? $l['label'] . ' office' : $l['label']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="sp-look">
          <span class="sp-look-l">Angle</span>
          <?php foreach (['office' => 'The office', 'team' => 'Team in place', 'local' => 'Local angle'] as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="angle" value="<?= $e($k) ?>"<?= $k === (string)($pk_preset['angle'] ?? 'office') ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-team" id="spTeam">
          <div class="sp-team-list" id="spTeamList"></div>
        </div>
      </section>
      <?php elseif ($pk === 'branding'): ?>
      <section class="sp-step">
        <h2 class="sp-step-h">Season</h2>
        <div class="sp-two">
          <label class="sp-field">Season
            <select name="season" id="spSubject">
              <?php $ws = (string)($pk_preset['season'] ?? 'always'); foreach ($pk_seasons as $k => $s): ?>
              <option value="<?= $e($k) ?>"<?= $k === $ws ? ' selected' : '' ?>><?= $e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="sp-field">Tagline
            <select name="tagline" id="spTagline"></select>
          </label>
        </div>
      </section>
      <?php endif; ?>

      <section class="sp-step">
        <h2 class="sp-step-h">Size</h2>
        <div class="sp-look">
          <span class="sp-look-l">Size</span>
          <?php $size_opts = social_formats(); if ($pk === 'broker') $size_opts = ['story' => $size_opts['story']] + $size_opts;   // a Broker Feature is a story (Nikki, 2026-11-05): Story first, and the default
          foreach ($size_opts as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="size" value="<?= $e($k) ?>"<?= $k === $default_size ? ' checked' : '' ?>><span><?= $e($t) ?> · <?= $k === 'post' ? '4:5' : ($k === 'square' ? '1:1' : '9:16') ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php if ($agent_styles): ?>
        <input type="hidden" name="layout" value="photo">
        <input type="hidden" name="style" value="<?= $e(array_key_first($agent_styles)) ?>">
        <div class="sp-look">
          <span class="sp-look-l">Arrange</span>
          <?php $wa = ($pk_preset['arrange'] ?? 'stack') === 'left' ? 'left' : 'stack'; foreach (['stack' => 'Right stack', 'left' => 'Left'] as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="arrange" value="<?= $e($k) ?>"<?= $k === $wa ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="sp-look">
          <span class="sp-look-l">Layout</span>
          <?php foreach ($offered_layouts as $k): ?>
          <label class="sp-pillopt"><input type="radio" name="layout" value="<?= $e($k) ?>"<?= $k === $default_layout ? ' checked' : '' ?>><span><?= $e($t = $layouts[$k]) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="sp-look">
          <span class="sp-look-l">Look</span>
          <?php foreach ($themes as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="theme" value="<?= $e($k) ?>"<?= $k === 'dark' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h">Text</h2>
        <div class="sp-fields">
          <label class="sp-field">Headline (Enter for a new line) <textarea name="headline" maxlength="140" rows="2"></textarea></label>
          <?php foreach ($pk === 'broker' ? [] : $line_names as $i => $nm): ?>
          <div class="sp-line<?= $i === 2 ? ' sp-only-long sp-only-3' : ($i === 3 ? ' sp-only-long' : '') ?>">
            <?php if ($i === 0): /* Text 1 takes line breaks (2026-11-11); the other lines stay one line */ ?>
            <label class="sp-field"><?= $e($nm) ?> (Enter for a new line) <textarea name="line_text[]" maxlength="<?= PLAN_TEXT1_MAX ?>" rows="2"></textarea></label>
            <?php else: ?>
            <label class="sp-field"><?= $e($nm) ?> <input name="line_text[]" maxlength="90"></label>
            <?php endif; ?>
            <label class="sp-field sp-style"><?= $e($nm) ?> style
              <select name="line_style[]"><?php foreach ($styles as $k => $w): ?><option value="<?= $e($k) ?>"<?= $k === $defaults[$i] ? ' selected' : '' ?>><?= $e($w) ?></option><?php endforeach; ?></select>
            </label>
          </div>
          <?php endforeach; ?>
          <?php if ($pk_plan): ?>
          <label class="sp-field">Caption (saved with the week, not drawn) <textarea name="caption" maxlength="2200" rows="3"><?= $e($pk_saved['caption'] ?? '') ?></textarea></label>
          <?php endif; ?>
        </div>
        <?php if ($pk === 'broker'): ?>
        <div class="sp-look">
          <span class="sp-look-l">First line</span>
          <?php $w1 = ($pk_preset['hook1'] ?? 'italic') === 'serif' ? 'serif' : 'italic'; foreach (['serif' => 'Serif', 'italic' => 'Italic'] as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="hook1" value="<?= $e($k) ?>"<?= $k === $w1 ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Second line</span>
          <?php $wh = (string)($pk_preset['hook2'] ?? 'sans'); foreach (plan_hook_styles() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="hook2" value="<?= $e($k) ?>"<?= $k === (isset(plan_hook_styles()[$wh]) ? $wh : 'sans') ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-ranges">
          <label class="sp-range">Hook size <input type="range" name="hook_size" min="30" max="120" step="2" value="70"><output>70px</output></label>
          <label class="sp-range">Call line size <input type="range" name="call_size" min="24" max="100" step="2" value="62"><output>62px</output></label>
          <label class="sp-range">Badge size <input type="range" name="badge_size" min="14" max="40" step="1" value="22"><output>22px</output></label>
        </div>
        <?php endif; ?>
        <div class="sp-ranges">
          <label class="sp-range">Text shadow <input type="range" name="text_shadow" min="0" max="100" step="5" value="35"><output>35</output></label>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h">Elements</h2>
        <div class="sp-look">
          <span class="sp-look-l">Logo</span>
          <?php foreach (social_logo_kinds() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="logo_kind" value="<?= $e($k) ?>"<?= $k === 'full' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Logo place</span>
          <?php foreach (social_logo_places() as $k => $t): ?>
          <label class="sp-pillopt" data-logo="<?= $e($k) ?>"><input type="radio" name="logo" value="<?= $e($k) ?>"<?= $k === $default_logo ? ' checked' : '' ?>><span><?= $e($pk === 'broker' ? ['top' => 'With the words', 'bottom' => 'Lower right'][$k] ?? $t : $t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Logo colour</span>
          <?php foreach (plan_logo_colors() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="logo_color" value="<?= $e($k) ?>"<?= $k === 'auto' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-ranges">
          <label class="sp-range">Logo size <input type="range" name="logo_scale" min="50" max="200" step="2" value="130"><output>130%</output></label>
          <label class="sp-range">Nudge across <input type="range" name="logo_dx" min="-200" max="200" step="2" value="0"><output>0</output></label>
          <label class="sp-range">Nudge down <input type="range" name="logo_dy" min="-200" max="200" step="2" value="0"><output>0</output></label>
          <label class="sp-range">Logo shadow <input type="range" name="logo_shadow" min="0" max="100" step="5" value="0"><output>0</output></label>
          <button type="button" class="sp-link sp-reset" id="spResetLogo">Reset logo and shadows</button>
        </div>
        <div class="sp-look">
          <span class="sp-look-l">Watermark</span>
          <?php foreach (social_marks() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="mark" value="<?= $e($k) ?>"<?= $k === 'triangle' ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="sp-fields">
          <div class="sp-two">
            <label class="sp-field">Label on the photo
              <select name="label" id="spLabel">
                <option value="__none">No label</option>
                <?php foreach ($labels as $lb): ?><option value="<?= $e($lb) ?>"><?= $e($lb) ?></option><?php endforeach; ?>
                <option value="__custom">Custom…</option>
              </select>
            </label>
            <label class="sp-field" id="spCustomWrap" hidden>Your label <input name="label_custom" maxlength="28" placeholder="Now Open"></label>
          </div>
        </div>
        <?php if ($pk === 'broker'): ?>
        <div class="sp-look">
          <span class="sp-look-l">Badge</span>
          <?php $wt = in_array($pk_preset['badge_tone'] ?? '', ['slate', 'glass'], true) ? $pk_preset['badge_tone'] : 'white'; foreach (['white' => 'White', 'slate' => 'Slate', 'glass' => 'Glass'] as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="badge_tone" value="<?= $e($k) ?>"<?= $k === $wt ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="sp-look" id="spBadgePlace">
          <span class="sp-look-l"><?= $pk === 'broker' ? 'Badge place' : 'Badge' ?></span>
          <?php foreach (plan_badge_places() as $k => $t): ?>
          <label class="sp-pillopt"><input type="radio" name="badge" value="<?= $e($k) ?>"<?= $k === $default_badge ? ' checked' : '' ?>><span><?= $e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="sp-step">
        <h2 class="sp-step-h"><?= $pk === 'broker' ? 'Backdrop' : 'Photo' ?></h2>
        <div class="sp-photos" id="spPhotos"><div class="sp-wait">Loading photos…</div></div>
        <div class="sp-drop" id="spDrop">
          <span id="spDropMsg">Drop a photo here, or <label class="sp-link" for="spFile">choose one</label>.</span>
          <input type="file" id="spFile" accept="image/jpeg,image/png,image/webp,image/*" hidden>
        </div>
        <?php if ($pk === 'broker'): ?>
        <div class="sp-framing">
          <div class="sp-framer empty" id="spBdFramer" style="aspect-ratio:1080 / 1920"><img id="spBdImg" alt="" draggable="false"><span class="sp-framer-hint">Drag to move</span></div>
          <label class="sp-zoom">Zoom <input type="range" name="bd_zoom" min="1" max="3" step="0.02" value="1"></label>
          <div class="sp-ranges"><label class="sp-range">Overlay <input type="range" name="backdrop_veil" min="40" max="96" step="2" value="70"><output>70</output></label></div>
        </div>
        <?php else: ?>
        <div class="sp-framing">
          <div class="sp-framer empty" id="spFramer" style="aspect-ratio:1080 / 1440"><img id="spFramerImg" alt="" draggable="false"><span class="sp-framer-hint">Drag to move</span></div>
          <label class="sp-zoom">Zoom <input type="range" name="zoom" min="1" max="3" step="0.02" value="1"></label>
        </div>
        <?php endif; ?>
      </section>

      <div class="sp-actions">
        <?php if ($pk_plan): ?><button type="submit" class="sp-btn" formaction="plan_save.php" id="spSave">Save to the week</button><?php endif; ?>
        <button type="submit" class="sp-btn<?= $pk_plan ? ' sp-btn-soft' : '' ?>" id="spDownload">Download PNG</button>
        <p class="sp-help" id="spHelp">The download is a 1080 × 1440 PNG for a post, 1080 × 1080 for a square, or 1080 × 1920 for a story.</p>
      </div>
    </form>

    <aside class="sp-preview">
      <div class="sp-stage" id="spStage">
        <iframe id="spFrame" title="Preview" width="1080" height="1440" scrolling="no"></iframe>
        <div class="sp-busy" id="spBusy"></div>
      </div>
      <div class="sp-framepills" id="spFramePills" hidden></div>
      <div class="sp-framepills sp-framelogo" id="spFrameLogo" hidden>
        <span class="sp-look-l">Logo on this frame</span>
        <?php foreach (['auto' => 'Auto', 'none' => 'None', 'full' => 'Logo', 'mark' => 'M mark'] as $k => $t): ?>
        <label class="sp-pillopt"><input type="radio" name="_flogo" value="<?= $e($k) ?>"><span><?= $e($t) ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="sp-cap">This is a preview. Click Download to save it.</p>
      <p class="sp-cap-btn"><?php if ($pk_plan): ?><button type="submit" form="spForm" formaction="plan_save.php" class="sp-btn sp-btn-sm">Save to the week</button> <?php endif; ?><button type="submit" form="spForm" class="sp-btn sp-btn-sm<?= $pk_plan ? ' sp-btn-soft' : '' ?>" id="spDownload2">Download PNG</button></p>
    </aside>
  </div>

<script>
(function () {
  const KIND = <?= json_encode($pk) ?>;
  const AGENT_WINDOWS = <?= json_encode($agent_windows) ?>;   // the Broker Feature's photo window per style and size
  // The backdrop follows the broker's region (its first stock photo) until the admin picks one.
  const figureUrl = () => { const o = f('agent') && f('agent').selectedOptions[0]; return o ? (o.dataset.figure || '') : ''; };   // the broker's cutout (the headshot when none), the framer's image
  const RENDER = <?= json_encode($render_url) ?>, PHOTOS = <?= json_encode($photos_url) ?>, UPLOAD = <?= json_encode($upload_url) ?>, DELETE_URL = <?= json_encode($delete_url) ?>;
  const SIZES = { post: 1440, square: 1080, story: 1920 };
  const MAX_FRAMES = <?= (int)PLAN_MAX_FRAMES ?>;
  const SAVED = <?= json_encode($pk_saved['form'] ?? null, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;   // a week's saved piece: restored into the controls at the start (2026-11-03)
  const JSON_FLAGS = <?= json_encode(JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  <?php if ($pk === 'property'): ?>
  const LISTINGS = <?= json_encode(array_values(array_map(fn($l) => ['id' => $l['id'], 'headline' => $l['headline'], 'town' => $l['town'], 'price_text' => $l['price_text'], 'specs_text' => $l['specs_text'],
      'is_rental' => (int)$l['is_rental'], 'closed' => (bool)$l['closed'], 'new' => (bool)$l['new'], 'agent' => $l['agent'], 'city' => trim((string)$l['city'])], $pk_listings)), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  <?php elseif ($pk === 'broker'): ?>
  const AGENTS = <?= json_encode(array_values(array_map(fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'first' => $a['first'], 'title' => $a['title'], 'market' => $a['market'], 'region' => $a['region']], $pk_agents)), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  <?php elseif ($pk === 'location'): ?>
  const AGENTS = <?= json_encode(array_values(array_map(fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'first' => $a['first']], $pk_agents)), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const TEAM = <?= json_encode($pk_team, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  <?php elseif ($pk === 'branding'): ?>
  const SEASONS = <?= json_encode(array_map(fn($s) => ['name' => $s['name'], 'taglines' => $s['taglines']], $pk_seasons), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  <?php endif; ?>

  const form = document.getElementById('spForm'), frame = document.getElementById('spFrame'),
        stage = document.getElementById('spStage'), photos = document.getElementById('spPhotos'),
        busy = document.getElementById('spBusy'),
        drop = document.getElementById('spDrop'), dropMsg = document.getElementById('spDropMsg'),
        fileIn = document.getElementById('spFile'),
        framer = document.getElementById('spFramer'), fimg = document.getElementById('spFramerImg'),
        labelSel = document.getElementById('spLabel'), customWrap = document.getElementById('spCustomWrap'),
        framesBox = document.getElementById('spFrames'), pills = document.getElementById('spFramePills'),
        dl = document.getElementById('spDownload'), dl2 = document.getElementById('spDownload2'), help = document.getElementById('spHelp');
  const f = n => form.elements[n];
  const esc = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  const fmt = () => f('size').value, fmtH = () => SIZES[fmt()] || 1440;
  const layout = () => f('layout').value;
  const lineText = i => form.querySelectorAll('[name="line_text[]"]')[i];   // Text 1 is a textarea since 2026-11-11
  const lineStyle = i => form.querySelectorAll('select[name="line_style[]"]')[i];
  const subject = () => document.getElementById('spSubject') ? document.getElementById('spSubject').value : '';
  const LOGO_DEFAULT = s => KIND === 'property' ? 'none' : ((s === 'story' && KIND !== 'broker') ? 'bottom' : 'top');   // the Broker Feature's logo sits with the words at every size (2026-11-05); the property cover carries none (2026-11-09)
  const BADGE_DEFAULT = s => KIND === 'property' ? 'body' : (s === 'story' ? 'right' : 'left');
  let prevSize = fmt();
  let frames = [];          // the set's photo ids, in order (property only)
  let frameLogos = {};      // photo id → auto | none | full | mark: the logo on that frame (2026-11-02)
  let frameFx = {};         // photo id → {z, x, y}: the frame's own zoom and focus (2026-11-03: each frame a tab)
  let coverFx = null;       // the cover's zoom and focus while another frame's tab holds the controls
  const cur = () => +f('frame').value;   // the open tab: 0 the cover, 1… the frames, frames.length the end card
  const framingRead = () => ({ z: parseFloat(f('zoom').value) || 1, x: +f('focus_x').value, y: +f('focus_y').value });
  const framingWrite = v => { f('zoom').value = v.z; f('focus_x').value = v.x; f('focus_y').value = v.y; showRange(f('zoom')); framerApply(); };
  // What the controls hold belongs to the open tab: the cover's, or the frame's by its photo.
  function framingStore() {
    if (!setOn()) return;
    const i = cur(); if (i >= frames.length) return;
    if (i === 0) coverFx = framingRead(); else frameFx[frames[i]] = framingRead();
    syncFrameInputs();
  }
  const tileOf = id => allTiles.find(p => p.id === id);
  // Open a tab: the framer shows that frame's photo with its framing (the end card shows the cover).
  function tabShow(i) {
    f('frame').value = i;
    const id = frames[Math.min(i, frames.length - 1)], t = id ? tileOf(id) : null;
    framer.classList.toggle('empty', !t); fimg.src = t ? t.large : '';
    framingWrite(i === 0 || i >= frames.length ? (coverFx || { z: 1, x: 50, y: 50 }) : (frameFx[id] || { z: 1, x: 50, y: 50 }));
  }
  function syncFrameInputs() {
    const on = setOn();
    framesBox.innerHTML = on ? frames.map(id => { const v = frameFx[id] || { z: 1, x: 50, y: 50 };
      return '<input type="hidden" name="frames[]" value="' + esc(id) + '"><input type="hidden" name="frame_logo[]" value="' + esc(frameLogos[id] || 'auto') + '">'
           + '<input type="hidden" name="frame_zoom[]" value="' + v.z + '"><input type="hidden" name="frame_fx[]" value="' + v.x + '"><input type="hidden" name="frame_fy[]" value="' + v.y + '">'; }).join('')
      + (coverFx ? '<input type="hidden" name="cover_zoom" value="' + coverFx.z + '"><input type="hidden" name="cover_fx" value="' + coverFx.x + '"><input type="hidden" name="cover_fy" value="' + coverFx.y + '">' : '') : '';
  }
  const frameLogoBox = document.getElementById('spFrameLogo');
  // What a frame will carry: the explicit choice, else Auto: nothing on the slide after the cover, the M mark lower right from the
  // third slide on (Nikki, 2026-11-09; i = its index in frames, 1 = the first after the cover). Mirrors plan_post_from_request().
  const effLogo = (id, i) => { const c = frameLogos[id] || 'auto'; if (c !== 'auto') return c; return i >= 2 ? 'mark' : 'none'; };
  let allTiles = [];        // the photos the picker holds

  // ── The story set (property, story size) ──
  const setOn = () => KIND === 'property' && f('set') && f('set').checked;   // any size since 2026-11-02 (a post or square set is a carousel)
  function syncSet() {
    form.querySelectorAll('.sp-only-set').forEach(el => el.hidden = !setOn());
    const on = setOn();
    if (on && !coverFx) coverFx = framingRead();   // the set just switched on: the controls hold the cover's framing
    syncFrameInputs();
    photos.querySelectorAll('.sp-photo').forEach(t => {
      const id = t.querySelector('input').value, i = frames.indexOf(id);
      let b = t.querySelector('.sp-ord');
      if (on && i >= 0) {
        if (!b) { b = document.createElement('b'); b.className = 'sp-ord'; t.appendChild(b); }
        const l = i > 0 ? effLogo(id, i) : 'none';   // the badge shows the frame's logo: L for the stack, M for the mark
        b.textContent = (i + 1) + (l === 'full' ? ' L' : (l === 'mark' ? ' M' : ''));
        b.classList.toggle('wide', l !== 'none');
      }
      else if (b) b.remove();
    });
    const end = f('endcard') && f('endcard').checked;
    const n = on ? frames.length + (end ? 1 : 0) : 1;
    pills.hidden = !on || n < 2;
    if (on) {
      let cur = +f('frame').value; if (cur >= n) cur = 0; f('frame').value = cur;
      let html = '';
      for (let i = 0; i < n; i++) {
        const label = i === 0 ? 'Cover' : (end && i === n - 1 ? 'End' : String(i + 1));
        html += '<label class="sp-pillopt"><input type="radio" name="_pill" value="' + i + '"' + (i === cur ? ' checked' : '') + '><span>' + label + '</span></label>';
      }
      if (frames.length < MAX_FRAMES) html += '<button type="button" class="sp-tabbtn" data-add="1">+ Add photo</button>';
      if (cur > 0 && cur < frames.length && frames.length > 1) html += '<button type="button" class="sp-tabbtn" data-remove="1">Remove this frame</button>';
      html += '<p class="sp-tabhint">' + (cur === 0 ? 'The cover: the words on this photo. Click a photo below to change it; drag and zoom it above.'
                                        : (cur < frames.length ? 'Frame ' + (cur + 1) + ': the photo alone. Click a photo below to put it here; drag and zoom it; choose its logo.' : 'The closing card: the logo, the address and who listed it.')) + '</p>';
      pills.innerHTML = html;
      // The per-frame logo row: for an internal frame (not the cover, not the end card), showing its choice.
      const internal = cur > 0 && cur < frames.length;
      frameLogoBox.hidden = !internal;
      if (internal) { const c = frameLogos[frames[cur]] || 'auto'; const r = frameLogoBox.querySelector('input[value="' + c + '"]'); if (r) r.checked = true; }
    } else { f('frame').value = 0; frameLogoBox.hidden = true; pills.innerHTML = ''; }
    dl.textContent = dl2.textContent = on ? 'Download the set (ZIP)' : 'Download PNG';
    help.textContent = on ? 'The download is a ZIP of ' + n + ' PNGs at 1080 × ' + fmtH() + ', numbered in play order: post them as one ' + (fmt() === 'story' ? 'story' : 'carousel') + '.'
                          : 'The download is a 1080 × 1440 PNG for a post, 1080 × 1080 for a square, or 1080 × 1920 for a story.';
  }
  pills.addEventListener('input', e => { if (e.target.name === '_pill') { tabShow(+e.target.value); syncSet(); update(); } });
  pills.addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.add) {
      const next = allTiles.find(p => !frames.includes(p.id)); if (!next || frames.length >= MAX_FRAMES) return;
      frames.push(next.id); tabShow(frames.length - 1);
    } else if (b.dataset.remove) {
      const i = cur(); if (!(i > 0 && i < frames.length)) return;
      frames.splice(i, 1); tabShow(Math.min(i, frames.length - 1));
    }
    syncSet(); update();
  });
  frameLogoBox.addEventListener('input', e => {
    if (e.target.name !== '_flogo') return;
    const cur = +f('frame').value; if (!(cur > 0 && cur < frames.length)) return;
    frameLogos[frames[cur]] = e.target.value; syncSet(); update();
  });

  // ── Layout rules: which logo places and how many lines each layout shows ──
  function syncLayout() {
    const L = layout(), logo = f('logo');
    if (logo.value === LOGO_DEFAULT(prevSize) && prevSize !== fmt()) logo.value = LOGO_DEFAULT(fmt());
    if (f('badge').value === BADGE_DEFAULT(prevSize) && prevSize !== fmt()) f('badge').value = BADGE_DEFAULT(fmt());
    prevSize = fmt();
    const agent = KIND === 'broker';   // the Broker Feature places the logo in the band (Upper right) or at the lower right (its Lower right pill is the bottom value), or None
    form.querySelectorAll('[data-logo]').forEach(el => {
      const k = el.dataset.logo;
      el.hidden = agent ? k === 'center' : ((L === 'split' && (k === 'bottom' || k === 'center')) || (L !== 'center' && k === 'center'));
    });
    if (agent && logo.value === 'center') logo.value = 'bottom';
    if (L === 'split' && (logo.value === 'bottom' || logo.value === 'center')) logo.value = 'top';
    if (L !== 'center' && logo.value === 'center') logo.value = 'top';
    form.querySelectorAll('.sp-only-long').forEach(el => el.hidden = agent ? true : L !== 'split');
    form.querySelectorAll('.sp-only-3').forEach(el => el.hidden = !agent);
    if (agent) { const w = (AGENT_WINDOWS[f('style').value] || {})[fmt()] || [1080, 1440]; framer.style.aspectRatio = w[0] + ' / ' + w[1]; }
    if (agent) document.getElementById('spBadgePlace').hidden = f('arrange').value !== 'left';   // the stack places the badge itself
    if (agent && bdFramer) bdFramer.style.aspectRatio = framer.style.aspectRatio;
    else framer.style.aspectRatio = '1080 / ' + (L === 'split' ? ({ post: 780, square: 520, story: 860 })[fmt()] : fmtH());
    customWrap.hidden = labelSel.value !== '__custom';
  }

  // ── The words, from the subject ──
  const setLabel = v => { labelSel.value = v; if (labelSel.value !== v) labelSel.value = '__none'; };
  const setLines = arr => { for (let i = 0; i < 4; i++) { if (!lineText(i)) continue; lineText(i).value = (arr[i] && arr[i][0]) || ''; if (arr[i] && arr[i][1]) lineStyle(i).value = arr[i][1]; } };
  function fill() {
    if (KIND === 'property') {
      const l = LISTINGS.find(x => x.id === subject()); if (!l) return;
      const v = f('variant').value;
      f('headline').value = l.headline;
      setLines([[l.town, 'town'], [l.price_text, 'price'], [l.specs_text, 'details'], [l.agent ? 'Listed by ' + l.agent : '', 'note']]);
      setLabel({ new: 'New Listing', featured: l.is_rental ? 'Featured Rental' : 'Featured Listing', sold: 'Just Sold', rental: 'Featured Rental' }[v] || 'Featured Listing');
    } else if (KIND === 'broker') {
      const a = AGENTS.find(x => String(x.id) === subject()); if (!a) return;
      const mk = f('market'), market = mk.value.trim() || a.market;
      const opt = f('hook').selectedOptions[0], tpl = opt ? opt.dataset.tpl : '';
      if (tpl) f('headline').value = tpl.replace(/\{name\}/g, a.name).replace(/\{first\}/g, a.first).replace(/\{market\}/g, market);
      // The name, then the service area alone (Nikki, 2026-11-05: no title; 2026-11-02: no company name, the logo carries it).
      setLabel(String(f('hook').value).startsWith('m') ? 'Milestone' : 'Broker Spotlight');
    } else if (KIND === 'location') {
      const opt = f('loc').selectedOptions[0]; if (!opt) return;
      const label = opt.dataset.label, kind = opt.dataset.kind, angle = f('angle').value;
      const place = kind === 'office' ? label + ', Colorado' : (kind === 'area' ? label : label + ', Colorado');
      if (angle === 'team') {
        f('headline').value = kind === 'office' ? 'Our ' + label + ' team' : 'Our brokers in ' + label;
        setLines([['Mont Haus International Realty', 'town'], [place, 'details'], ['', 'plain'], ['', 'note']]);
        setLabel('Meet the Team');
      } else if (angle === 'local') {
        f('headline').value = label + ' real estate';
        setLines([['Mont Haus International Realty', 'town'], ['Type the local angle: a neighborhood, a notable sale, a trend', 'note'], ['', 'plain'], ['', 'note']]);
        setLabel('Local Market');
      } else {
        f('headline').value = kind === 'office' ? 'Mont Haus ' + label : 'Mont Haus in ' + label;
        setLines([[place, 'town'], ['Mont Haus International Realty', 'details'], ['', 'plain'], ['', 'note']]);
        setLabel(kind === 'office' ? 'Office Feature' : '__none');
      }
    } else if (KIND === 'branding') {
      const tl = f('tagline'); f('headline').value = tl.value || '';
      setLines([['Mont Haus International Realty', 'town'], ['', 'details'], ['', 'plain'], ['', 'note']]);
      setLabel('__none');
    }
  }
  function fillTaglines() {
    if (KIND !== 'branding') return;
    const s = SEASONS[subject()], tl = f('tagline'); if (!s) return;
    tl.innerHTML = s.taglines.map((t, i) => '<option value="' + esc(t) + '"' + (i === 0 ? ' selected' : '') + '>' + esc(t.replace(/\n/g, ' ')) + '</option>').join('');
  }
  // The team ticks for a location (location only): the brokers based there, the first six ticked when the angle is Team.
  function fillTeam(reset) {
    if (KIND !== 'location') return;
    const list = document.getElementById('spTeamList'), ids = TEAM[subject()] || [];
    const was = reset ? null : new Set([...form.querySelectorAll('input[name="team[]"]:checked')].map(i => +i.value));
    const tickAll = f('angle').value === 'team';
    list.innerHTML = ids.length ? ids.map((id, i) => {
      const a = AGENTS.find(x => x.id === id); if (!a) return '';
      const on = was ? was.has(id) : (tickAll && i < 6);
      return '<label class="sp-check"><input type="checkbox" name="team[]" value="' + id + '"' + (on ? ' checked' : '') + '> ' + esc(a.name) + '</label>';
    }).join('') : '<p class="sp-hint">Nobody on the roster is based here yet.</p>';
  }
  form.addEventListener('input', e => {
    if (e.target.name !== 'team[]') return;
    const on = form.querySelectorAll('input[name="team[]"]:checked');
    if (on.length > 6) { e.target.checked = false; alert('Six faces at most: the row has no room for more.'); }
  });

  // ── The logo sliders: show each value; the text shadow follows the look (35 on dark, 0 on light) until it is moved. ──
  function showRange(r) { const o = r.parentElement.querySelector('output'); if (o) o.textContent = r.name === 'logo_scale' ? r.value + '%' : (/^(hook_size|call_size|badge_size)$/.test(r.name) ? r.value + 'px' : r.value); }
  function syncShadowDefault() { const r = f('text_shadow'); if (!r.dataset.touched) { r.value = f('theme').value === 'light' ? 0 : 35; showRange(r); } }
  // The Broker Feature's text sizes (hook, call line, badge) start from the size's defaults and keep them until moved.
  function syncTextSizes() {
    if (KIND !== 'broker') return;
    const hook = { story: 70, post: 62, square: 50 }[fmt()] || 62;
    const h = f('hook_size'), c = f('call_size');
    if (!h.dataset.touched) { h.value = hook; showRange(h); }
    if (!c.dataset.touched) { c.value = Math.round((h.dataset.touched ? +h.value : hook) * 0.9); showRange(c); }
  }
  form.querySelectorAll('.sp-range input').forEach(showRange);
  document.getElementById('spResetLogo').addEventListener('click', () => {
    for (const [n, v] of [['logo_scale', 130], ['logo_dx', 0], ['logo_dy', 0], ['logo_shadow', 0]]) { f(n).value = v; delete f(n).dataset.touched; showRange(f(n)); }   // 130: the logo's starting size since 2026-11-05
    delete f('text_shadow').dataset.touched; syncShadowDefault();
    f('logo_color').value = 'auto';
    update();
  });

  // ── Preview ──
  let timer, seq = 0;
  function update() { clearTimeout(timer); timer = setTimeout(render, 250); }
  async function render() {
    const n = ++seq; busy.classList.add('on');
    try {
      const r = await fetch(RENDER, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      const html = await r.text();
      if (n === seq) frame.srcdoc = html;
    } catch (e) { /* the previous preview stays */ }
    finally { if (n === seq) busy.classList.remove('on'); }
  }
  function scale() {
    const w = stage.clientWidth;
    frame.height = fmtH(); frame.style.height = fmtH() + 'px';
    frame.style.transform = 'scale(' + (w / 1080) + ')';
    stage.style.height = Math.round(fmtH() * w / 1080) + 'px';
  }

  // ── Photos ──
  function photoTile(p, checked) {
    return '<label class="sp-photo' + (p.own ? ' own' : '') + (p.none ? ' sp-none-tile' : '') + '"' + (p.caption ? ' title="' + esc(p.caption) + '"' : '') + '><input type="radio" name="photo" value="' + esc(p.id) + '" data-large="' + esc(p.large) + '"' +
           (checked ? ' checked' : '') + '>' + (p.none ? '<span class="sp-none">None</span>' : '<img src="' + esc(p.thumb) + '" alt="' + esc(p.caption || '') + '" loading="lazy">') + (p.own ? '<i>Yours</i><button type="button" class="sp-del" data-id="' + esc(p.id) + '" title="Remove this photo" aria-label="Remove this photo">×</button>' : '') + '</label>';
  }
  async function loadPhotos(selectId) {
    if (!selectId) { coverFx = null; frameFx = {}; }   // a new subject: its own framing
    photos.innerHTML = '<div class="sp-wait">Loading photos…</div>';
    const u = new URL(PHOTOS, location.origin); u.searchParams.set('kind', KIND); u.searchParams.set('subject', subject());
    let j = { photos: [] };
    try { j = await (await fetch(u, { credentials: 'same-origin' })).json(); } catch (e) {}
    allTiles = j.photos || [];
    if (!allTiles.length) {
      photos.innerHTML = '<div class="sp-wait">No photos yet. Add your own below.</div>';
      frames = []; framerSet(''); syncSet(); update(); return;
    }
    const first = (selectId && allTiles.find(p => p.id === selectId)) ? selectId : (j.suggest && allTiles.find(p => p.id === j.suggest) ? j.suggest : allTiles[0].id);
    const groups = [];
    for (const p of allTiles) { const g = groups.find(x => x[0] === p.group); if (g) g[1].push(p); else groups.push([p.group || 'Photos', [p]]); }
    // Each group folds under its header (Nikki, 2026-11-05); the chosen photo's group starts open, the rest closed.
    let html = '';
    groups.forEach(([name, list], gi) => {
      const open = list.some(p => p.id === first);
      html += '<button type="button" class="sp-group sp-grp" data-grp="' + gi + '" aria-expanded="' + open + '">' + esc(name) + ' <b>' + list.length + '</b></button>'
            + list.map(p => photoTile(p, p.id === first).replace('<label class="sp-photo', '<label data-grp="' + gi + '"' + (open ? '' : ' hidden') + ' class="sp-photo')).join('');
    });
    photos.innerHTML = html;
    // A fresh listing: the set starts with its first photos in feed order (the cover first), up to the limit.
    if (KIND === 'property') {
      const own = allTiles.filter(p => !p.own).map(p => p.id);
      frames = (selectId && frames.length) ? frames.filter(id => allTiles.some(p => p.id === id)) : own.slice(0, Math.min(MAX_FRAMES, 5));
      if (selectId && !frames.includes(selectId)) frames.push(selectId);
      if (!frames.length) frames = [first];
      if (frames.length > MAX_FRAMES) frames = frames.slice(0, MAX_FRAMES);
      const cover = form.querySelector('input[name="photo"][value="' + CSS.escape(frames[0]) + '"]'); if (cover && setOn()) cover.checked = true;
    }
    const sel = allTiles.find(p => p.id === (form.querySelector('input[name="photo"]:checked') || {}).value);
    if (KIND === 'broker') { framerSet(figureUrl(), 'cutout:x'); bdSet(sel && sel.id !== 'none' ? sel.large : ''); }   // the framer moves and scales the broker; the tiles pick the backdrop, which the second framer frames
    else if (setOn()) { if (!coverFx) { framerSet(sel ? sel.large : '', sel ? sel.id : ''); coverFx = framingRead(); } tabShow(Math.min(cur(), frames.length)); }
    else framerSet(sel ? sel.large : '', sel ? sel.id : '');
    syncSet(); update();
  }
  // A group header folds or unfolds its tiles.
  photos.addEventListener('click', e => {
    const h = e.target.closest('.sp-grp'); if (!h) return;
    const open = h.getAttribute('aria-expanded') !== 'true';
    h.setAttribute('aria-expanded', String(open));
    photos.querySelectorAll('.sp-photo[data-grp="' + h.dataset.grp + '"]').forEach(t => t.hidden = !open);
  });
  // In set mode a click on a tile adds it to (or removes it from) the play order; the cover is the first.
  photos.addEventListener('click', e => {
    if (!setOn() || e.target.closest('.sp-del')) return;
    const tile = e.target.closest('.sp-photo'); if (!tile) return;
    e.preventDefault();
    const id = tile.querySelector('input').value;
    if (id === 'none') return;
    let i = cur(); if (i >= frames.length) i = 0;   // from the end card's tab, the cover takes it
    const j = frames.indexOf(id);
    if (j === i) return;
    if (j >= 0) frames[j] = frames[i];   // the photo was on another frame: the two swap
    frames[i] = id;
    const cover = form.querySelector('input[name="photo"][value="' + CSS.escape(frames[0]) + '"]');
    if (cover) cover.checked = true;
    tabShow(i); syncSet(); update();
  });
  async function upload(file) {
    if (!file) return;
    drop.classList.add('busy'); dropMsg.textContent = 'Uploading ' + file.name + '…';
    const fd = new FormData(); fd.append('csrf_token', f('csrf_token').value); fd.append('photo', file);
    let j = {};
    try { j = await (await fetch(UPLOAD, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); }
    catch (e) { j = { error: 'The upload did not go through. Please try again.' }; }
    drop.classList.remove('busy'); fileIn.value = '';
    if (j.error) { dropMsg.textContent = j.error; return; }
    dropMsg.innerHTML = 'Added. Drop another, or <label class="sp-link" for="spFile">choose one</label>.';
    if (setOn() && frames.length < MAX_FRAMES) frames.push(j.id);
    loadPhotos(j.id);
  }
  fileIn.addEventListener('change', e => upload(e.target.files[0]));
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => upload(e.dataTransfer.files[0]));
  photos.addEventListener('click', async e => {
    const b = e.target.closest('.sp-del'); if (!b) return;
    e.preventDefault(); e.stopPropagation();
    if (!confirm('Remove this photo from your uploads? Posts you already downloaded are not affected.')) return;
    const fd = new FormData(); fd.append('csrf_token', f('csrf_token').value); fd.append('id', b.dataset.id);
    let j = {};
    try { j = await (await fetch(DELETE_URL, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (err) { j = { error: 'That did not work. Please try again.' }; }
    if (j.error) { alert(j.error); return; }
    frames = frames.filter(id => id !== b.dataset.id);
    const wasSelected = (form.querySelector('input[name="photo"]:checked') || {}).value === b.dataset.id;
    loadPhotos(wasSelected ? undefined : (form.querySelector('input[name="photo"]:checked') || {}).value);
  });

  // ── Framing: the same CSS the picture uses, as a control ──
  function framerSet(src, id) {
    f('focus_x').value = 50; f('focus_y').value = KIND === 'broker' ? 0 : ((id && /^(headshot|face|cutout):/.test(id)) ? 22 : 50); f('zoom').value = 1;   // a headshot: from the top, the head never cut
    framer.classList.toggle('empty', !src);
    fimg.src = src || '';
    framerApply();
  }
  // The broker's figure as the renderer draws it (inc/plan_agent_render.php's numbers): the box is the whole picture, the
  // figure's height per size and arrangement, its left edge, anchored to the foot; focus_x moves it across (700px over
  // the range), focus_y drops it (300px). So dragging moves the figure the way the hand goes (2026-11-05: it went the other way).
  const FIG = { stack: { story: [1400, -140], post: [1050, -80], square: [740, -60] }, left: { story: [1300, -140], post: [980, -80], square: [690, -60] } };
  const figGeom = () => (FIG[(f('arrange') && f('arrange').value) === 'left' ? 'left' : 'stack'] || FIG.stack)[fmt()] || [1050, -80];
  function framerApply() {
    const Z = parseFloat(f('zoom').value), fx = +f('focus_x').value, fy = +f('focus_y').value;
    const bw = framer.clientWidth, bh = framer.clientHeight;
    if (KIND === 'broker') {
      const [figH, figLeft] = figGeom(), H = fmtH(), s = bw / 1080;
      const h = figH * Math.max(0.6, Math.min(2, Z)) * s;
      fimg.style.cssText = 'position:absolute;width:auto;height:' + h + 'px;object-fit:fill;transform:none;left:' + ((figLeft + (fx - 50) / 100 * 700) * s) + 'px;top:' + (bh - h + fy / 100 * 300 * s) + 'px';
      return;
    }
    fimg.style.width = (Z * 100) + '%'; fimg.style.height = (Z * 100) + '%';
    fimg.style.objectPosition = fx + '% ' + fy + '%';
    fimg.style.transform = 'translate(' + (-(Z - 1) * fx / 100 * bw) + 'px,' + (-(Z - 1) * fy / 100 * bh) + 'px)';
  }
  // The backdrop's framer (broker only): the photo semantics, on bd_zoom / bd_fx / bd_fy.
  const bdFramer = document.getElementById('spBdFramer'), bdImg = document.getElementById('spBdImg');
  function bdSet(src) {
    if (!bdFramer) return;
    f('bd_fx').value = 50; f('bd_fy').value = 50; f('bd_zoom').value = 1;
    bdFramer.classList.toggle('empty', !src); bdImg.src = src || ''; bdApply();
  }
  function bdApply() {
    if (!bdFramer) return;
    const Z = parseFloat(f('bd_zoom').value), fx = +f('bd_fx').value, fy = +f('bd_fy').value, bw = bdFramer.clientWidth, bh = bdFramer.clientHeight;
    bdImg.style.width = (Z * 100) + '%'; bdImg.style.height = (Z * 100) + '%';
    bdImg.style.objectPosition = fx + '% ' + fy + '%';
    bdImg.style.transform = 'translate(' + (-(Z - 1) * fx / 100 * bw) + 'px,' + (-(Z - 1) * fy / 100 * bh) + 'px)';
  }
  if (bdFramer) {
    let bdrag = null;
    bdFramer.addEventListener('pointerdown', e => { if (!bdImg.getAttribute('src')) return; bdFramer.setPointerCapture(e.pointerId); bdrag = { x: e.clientX, y: e.clientY, fx: +f('bd_fx').value, fy: +f('bd_fy').value }; bdFramer.classList.add('dragging'); });
    bdFramer.addEventListener('pointermove', e => {
      if (!bdrag) return;
      const Z = parseFloat(f('bd_zoom').value), bw = bdFramer.clientWidth, bh = bdFramer.clientHeight;
      const nat = (bdImg.naturalWidth && bdImg.naturalHeight) ? bdImg.naturalWidth / bdImg.naturalHeight : bw / bh;
      const ox = Math.max(1, Math.max(bw, bh * nat) * Z - bw), oy = Math.max(1, Math.max(bh, bw / nat) * Z - bh);
      f('bd_fx').value = Math.round(Math.max(0, Math.min(100, bdrag.fx - (e.clientX - bdrag.x) / ox * 100)));
      f('bd_fy').value = Math.round(Math.max(0, Math.min(100, bdrag.fy - (e.clientY - bdrag.y) / oy * 100)));
      bdApply(); update();
    });
    ['pointerup', 'pointercancel'].forEach(ev => bdFramer.addEventListener(ev, () => { bdrag = null; bdFramer.classList.remove('dragging'); }));
    bdFramer.addEventListener('wheel', e => { if (!bdImg.getAttribute('src')) return; e.preventDefault(); const z = f('bd_zoom'); z.value = Math.max(1, Math.min(3, parseFloat(z.value) - e.deltaY * 0.0025)).toFixed(2); bdApply(); update(); }, { passive: false });
    bdImg.addEventListener('load', bdApply);
    new ResizeObserver(bdApply).observe(bdFramer);
  }
  let drag = null;
  framer.addEventListener('pointerdown', e => {
    if (!fimg.getAttribute('src')) return;
    framer.setPointerCapture(e.pointerId);
    drag = { x: e.clientX, y: e.clientY, fx: +f('focus_x').value, fy: +f('focus_y').value };
    framer.classList.add('dragging');
  });
  framer.addEventListener('pointermove', e => {
    if (!drag) return;
    const Z = parseFloat(f('zoom').value), bw = framer.clientWidth, bh = framer.clientHeight;
    if (KIND === 'broker') {   // the hand moves the figure: across over 700px of the picture, down over 300px
      const s = bw / 1080;
      f('focus_x').value = Math.round(Math.max(0, Math.min(100, drag.fx + (e.clientX - drag.x) / s / 700 * 100)));
      f('focus_y').value = Math.round(Math.max(0, Math.min(100, drag.fy + (e.clientY - drag.y) / s / 300 * 100)));
      framerApply(); framingStore(); update(); return;
    }
    const nat = (fimg.naturalWidth && fimg.naturalHeight) ? fimg.naturalWidth / fimg.naturalHeight : bw / bh;
    const coverW = Math.max(bw, bh * nat), coverH = Math.max(bh, bw / nat);
    const ox = Math.max(1, coverW * Z - bw), oy = Math.max(1, coverH * Z - bh);
    f('focus_x').value = Math.round(Math.max(0, Math.min(100, drag.fx - (e.clientX - drag.x) / ox * 100)));
    f('focus_y').value = Math.round(Math.max(0, Math.min(100, drag.fy - (e.clientY - drag.y) / oy * 100)));
    framerApply(); framingStore(); update();
  });
  ['pointerup', 'pointercancel'].forEach(ev => framer.addEventListener(ev, () => { drag = null; framer.classList.remove('dragging'); }));
  framer.addEventListener('wheel', e => {
    if (!fimg.getAttribute('src')) return;
    e.preventDefault();
    const z = f('zoom'); z.value = Math.max(+z.min || 1, Math.min(+z.max || 3, parseFloat(z.value) - e.deltaY * 0.0025)).toFixed(2);
    framerApply(); update();
  }, { passive: false });
  fimg.addEventListener('load', framerApply);
  new ResizeObserver(framerApply).observe(framer);

  // ── Wiring ──
  form.addEventListener('input', e => {
    const n = e.target.name;
    if (n === 'listing' || n === 'agent' || n === 'loc') {
      if (n === 'agent') { const o = e.target.selectedOptions[0]; f('market').value = o ? o.dataset.market : ''; syncLayout(); }
      if (n === 'loc') fillTeam(true);
      fill(); loadPhotos(); return;
    }
    if (n === 'season') { fillTaglines(); fill(); loadPhotos(); return; }
    if (n === 'variant' || n === 'hook' || n === 'market' || n === 'tagline') fill();
    if (n === 'angle') { if (e.target.value === 'team') f('layout').value = 'split'; fillTeam(true); fill(); syncLayout(); }
    if (n === 'size') { syncLayout(); scale(); syncSet(); syncTextSizes(); }
    if (n === 'hook_size') { const c = f('call_size'); if (!c.dataset.touched) { c.value = Math.round(+e.target.value * 0.9); showRange(c); } }
    if (n === 'layout' || n === 'style' || n === 'arrange') { syncLayout(); framerApply(); }
    if (n === 'set' || n === 'endcard' || n === 'logo' || n === 'logo_kind') syncSet();
    if (n === 'label') customWrap.hidden = labelSel.value !== '__custom';
    if (n === 'photo') { if (setOn()) { frames = [e.target.value].concat(frames.filter(id => id !== e.target.value)).slice(0, MAX_FRAMES); syncSet(); } if (KIND !== 'broker') framerSet(e.target.dataset.large, e.target.value); else bdSet(e.target.value === 'none' ? '' : e.target.dataset.large); }
    if (n === 'theme') syncShadowDefault();
    if (['logo_scale', 'logo_dx', 'logo_dy', 'text_shadow', 'logo_shadow', 'backdrop_veil', 'hook_size', 'call_size', 'badge_size'].includes(n)) { showRange(e.target); e.target.dataset.touched = '1'; }
    if (n === 'zoom') { framerApply(); framingStore(); }
    if (n === 'bd_zoom') bdApply();
    update();
  });
  form.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'submit') e.preventDefault();
  });
  form.addEventListener('submit', e => { if (e.submitter && /plan_save/.test(e.submitter.getAttribute('formaction') || '')) return; busy.classList.add('on'); setTimeout(() => busy.classList.remove('on'), setOn() ? 45000 : 10000); });

  // ── A saved piece (Weekly Plans): every control from the saved form, then the photos with the saved pick and frames ──
  function restoreSaved() {
    if (!SAVED) return false;
    const skip = new Set(['photo', 'frames', 'frame_logo', 'team', 'kind', 'line_text', 'line_style', 'zoom', 'focus_x', 'focus_y', 'tagline']);
    const setField = (name, val) => {
      const els = form.elements[name]; if (!els) return;
      const list = (typeof RadioNodeList !== 'undefined' && els instanceof RadioNodeList) ? [...els] : [els];
      if (!list[0] || !list[0].type) return;
      if (list[0].type === 'radio') { const r = list.find(x => x.value === String(val)); if (r) r.checked = true; }
      else if (list[0].type === 'checkbox') list[0].checked = !!val;
      else list[0].value = val;
    };
    for (const [k, v] of Object.entries(SAVED)) { if (skip.has(k) || Array.isArray(v)) continue; setField(k, v); }
    ['set', 'endcard'].forEach(n => { if (f(n)) f(n).checked = !!SAVED[n]; });   // unticked boxes are absent from a post
    (SAVED.line_text || []).forEach((t, i) => { if (lineText(i)) lineText(i).value = t; });
    (SAVED.line_style || []).forEach((t, i) => { if (lineStyle(i)) lineStyle(i).value = t; });
    form.querySelectorAll('.sp-range input').forEach(r => { r.dataset.touched = '1'; showRange(r); });
    if (KIND === 'branding') { fillTaglines(); if (SAVED.tagline) setField('tagline', SAVED.tagline); }
    if (KIND === 'location') { fillTeam(true); form.querySelectorAll('input[name="team[]"]').forEach(c => c.checked = (SAVED.team || []).includes(c.value)); }
    prevSize = fmt();   // syncLayout keeps the saved logo place: the size is not a change
    frames = (SAVED.frames || []).slice(); frameLogos = {}; frameFx = {};
    frames.forEach((id, i) => {
      const c = (SAVED.frame_logo || [])[i]; if (c && c !== 'auto') frameLogos[id] = c;
      if (SAVED.frame_zoom) frameFx[id] = { z: parseFloat((SAVED.frame_zoom || [])[i]) || 1, x: +((SAVED.frame_fx || [])[i] ?? 50), y: +((SAVED.frame_fy || [])[i] ?? 50) };
    });
    if (SAVED.set) coverFx = { z: parseFloat(SAVED.cover_zoom ?? SAVED.zoom) || 1, x: +(SAVED.cover_fx ?? SAVED.focus_x ?? 50), y: +(SAVED.cover_fy ?? SAVED.focus_y ?? 50) };
    return true;
  }

  // The page header (title, hint, buttons) rides at the top of the form column, so the preview column starts at the very
  // top of the page and is sticky from the first scroll (Nikki, 2026-11-05: "have the stick start immediately").
  { const hd = document.querySelector('.mk-page-header'); if (hd && form.parentElement) { hd.classList.add('sp-head-in'); form.prepend(hd); } }
  new ResizeObserver(scale).observe(stage); scale();
  if (KIND === 'broker') { const o = f('agent').selectedOptions[0]; f('market').value = o ? o.dataset.market : ''; }
  const restored = restoreSaved();
  if (!restored) { fillTaglines(); fillTeam(true); }
  syncLayout(); syncTextSizes();
  if (!restored) fill();
  loadPhotos(restored ? SAVED.photo : undefined).then(() => {
    if (!restored || setOn()) return;   // a set's tabs were framed by loadPhotos
    f('zoom').value = SAVED.zoom || 1; f('focus_x').value = SAVED.focus_x ?? 50; f('focus_y').value = SAVED.focus_y ?? (KIND === 'broker' ? 0 : 50);
    if (bdFramer) { f('bd_zoom').value = SAVED.bd_zoom || 1; f('bd_fx').value = SAVED.bd_fx ?? 50; f('bd_fy').value = SAVED.bd_fy ?? 50; bdApply(); }
    framerApply(); update();
  });
})();
</script>
