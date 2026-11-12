<?php
/**
 * social_captions.php — every listing's Suggested Caption on one page (Nikki,
 * 2026-11-12: "a page ... for me to see them all at once and make edits"):
 * each live Mont Haus listing in the feed with its caption in a box, what to
 * look at, the count against Instagram's 2,200, and Save, Copy and Back to
 * the draft. Saved captions are what the Property tool's Suggested Caption
 * box shows. Store and rules: inc/captions.php. Super admin only.
 */
require_once __DIR__ . '/inc/plan.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
$ctx = plan_gate($conn);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$csrf = (string)$_SESSION['csrf_token'];

$groups = ['Sales' => [], 'Rentals' => []];
$store = captions_store();
foreach (plan_listings($conn) as $l) {
    if ($l['closed']) continue;
    $groups[(int)$l['is_rental'] ? 'Rentals' : 'Sales'][] = $l + ['cap' => caption_for($conn, $l, true, $store)];
}
$total = count($groups['Sales']) + count($groups['Rentals']);
$count = fn(string $src) => count(array_filter(array_merge(...array_values($groups)), fn($l) => $l['cap']['source'] === $src));

admin_tool_header([
    'title'   => 'Captions',
    'hint'    => 'The Suggested Caption of every live Mont Haus listing. Edit a caption and Save it; the Property tool shows the saved one. Nothing here posts anywhere.',
    'actions' => '<a class="btn btn-outline btn-sm" href="template_property.php"><i class="ti ti-photo"></i> Property Post + Set</a>'
               . '<a class="btn btn-outline btn-sm" href="social_media.php"><i class="ti ti-brand-instagram"></i> Social Media</a>',
    'active'  => 'social',
    'css'     => '
    .cp-bar { display:flex; gap:14px; align-items:center; flex-wrap:wrap; background:#fff; border:1px solid #dfe3e8; border-radius:10px; padding:10px 14px; margin-bottom:22px; font-size:13px; color:#434b57; position:sticky; top:78px; z-index:5; }
    .cp-bar input[type=search] { font-size:14px; padding:6px 10px; border:1px solid #c9d0d9; border-radius:8px; min-width:230px; }
    .cp-bar label { margin:0; display:inline-flex; gap:6px; align-items:center; cursor:pointer; }
    .cp-bar .cp-unsaved { margin-left:auto; color:#8a5a00; font-weight:600; }
    .cp-h { font-family:"Cormorant Garamond", Georgia, serif; font-weight:500; font-size:28px; margin:26px 0 12px; color:#1f242c; }
    .cp-card { display:grid; grid-template-columns:210px 1fr; gap:18px; background:#fff; border:1px solid #dfe3e8; border-radius:14px; padding:16px; margin-bottom:16px; }
    .cp-card.dirty { border-color:#c98a00; box-shadow:0 0 0 2px rgba(201,138,0,.15); }
    @media (max-width:760px) { .cp-card { grid-template-columns:1fr; } }
    .cp-side img { width:100%; aspect-ratio:4 / 3; object-fit:cover; border-radius:8px; background:#eceff3; display:block; }
    .cp-side h3 { font-size:15px; font-weight:600; margin:10px 0 2px; color:#1f242c; }
    .cp-side p { font-size:12.5px; color:#6b7280; margin:0 0 2px; }
    .cp-side a { font-size:12.5px; }
    .cp-tag { display:inline-block; font-size:11px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; padding:2px 8px; border-radius:20px; margin-top:8px; background:#eceff3; color:#434b57; }
    .cp-tag.saved { background:#e3f1e6; color:#22643a; } .cp-tag.built { background:#fdf0d5; color:#8a5a00; }
    .cp-notes { margin:10px 0 0; padding:0; list-style:none; font-size:12.5px; color:#8a5a00; }
    .cp-notes li { padding-left:14px; position:relative; margin-bottom:3px; } .cp-notes li::before { content:"!"; position:absolute; left:2px; font-weight:700; }
    .cp-main textarea { width:100%; min-height:330px; font:inherit; font-size:13.5px; line-height:1.5; padding:10px 12px; border:1px solid #c9d0d9; border-radius:8px; resize:vertical; color:#1f242c; }
    .cp-main textarea:focus { outline:none; border-color:#55637a; box-shadow:0 0 0 3px rgba(85,99,122,.15); }
    .cp-act { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:8px; }
    .cp-count { font-size:12.5px; color:#6b7280; margin-right:auto; } .cp-count.over { color:#b3261e; font-weight:600; }
    .cp-msg { font-size:12.5px; color:#22643a; } .cp-msg.bad { color:#b3261e; }
    ',
]);
if (!$total) { echo '<div class="note">No live Mont Haus listings are in the feed right now.</div>'; admin_tool_footer(); exit; }
?>
    <div class="cp-bar">
      <input type="search" id="cpFind" placeholder="Find an address, town or agent" aria-label="Find a listing">
      <label><input type="checkbox" id="cpOnlyNotes"> Only the ones with something to check</label>
      <span><?= (int)$total ?> listings · <span id="cpSavedN"><?= (int)$count('saved') ?></span> saved · <?= (int)$count('draft') ?> first drafts · <?= (int)$count('built') ?> built from the feed</span>
      <span class="cp-unsaved" id="cpUnsaved" hidden></span>
    </div>
    <?php foreach ($groups as $gname => $list): if (!$list) continue; ?>
    <h2 class="cp-h"><?= $e($gname) ?> (<?= count($list) ?>)</h2>
    <?php foreach ($list as $l): $c = $l['cap']; $addr = ($l['address'] !== '' ? $l['address'] : 'Address withheld') . ', ' . $l['city']; ?>
    <div class="cp-card" data-id="<?= $e($l['id']) ?>" data-find="<?= $e(strtolower($addr . ' ' . $l['agent'] . ' ' . $c['caption'])) ?>" data-notes="<?= $c['notes'] ? 1 : 0 ?>">
      <div class="cp-side">
        <?php if ($l['thumb'] !== ''): ?><img src="<?= $e($l['thumb']) ?>" alt="" loading="lazy"><?php endif; ?>
        <h3><?= $e($addr) ?></h3>
        <p><?= $e(implode(' · ', array_filter([$l['price_text'], social_status_words((string)$l['status']), 'MLS# ' . $l['mls_id']]))) ?></p>
        <p><?= $e($l['agent']) ?></p>
        <?php if ((string)$l['url'] !== ''): ?><a href="<?= $e($l['url']) ?>" target="_blank" rel="noopener">On monthaus.com ›</a><br><?php endif; ?>
        <a href="template_property.php?listing=<?= $e(rawurlencode($l['id'])) ?>&amp;variant=<?= (int)$l['is_rental'] ? 'rental' : 'featured' ?>">Make its post ›</a><br>
        <span class="cp-tag <?= $e($c['source']) ?>"><?= $e(['saved' => 'Saved', 'draft' => 'First draft', 'built' => 'Built from the feed'][$c['source']]) ?></span>
        <?php if ($c['notes']): ?><ul class="cp-notes"><?php foreach ($c['notes'] as $n): ?><li><?= $e($n) ?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
      <div class="cp-main">
        <textarea maxlength="2200" aria-label="Caption for <?= $e($addr) ?>"><?= $e($c['caption']) ?></textarea>
        <div class="cp-act">
          <span class="cp-count"></span><span class="cp-msg"></span>
          <button type="button" class="btn btn-outline btn-sm cp-reset"<?= $c['source'] === 'saved' ? '' : ' hidden' ?>>Back to the draft</button>
          <button type="button" class="btn btn-outline btn-sm cp-copy">Copy</button>
          <button type="button" class="btn btn-primary btn-sm cp-save">Save</button>
        </div>
      </div>
    </div>
    <?php endforeach; endforeach; ?>
    <script>
    (function () {
      const CSRF = <?= json_encode($csrf) ?>;
      const cards = [...document.querySelectorAll('.cp-card')];
      const unsaved = document.getElementById('cpUnsaved');
      function tally() {
        const n = cards.filter(c => c.classList.contains('dirty')).length;
        unsaved.hidden = !n; unsaved.textContent = n ? n + (n === 1 ? ' caption' : ' captions') + ' changed, not saved' : '';
        document.getElementById('cpSavedN').textContent = cards.filter(c => c.querySelector('.cp-tag').classList.contains('saved')).length;
      }
      async function post(card, data) {
        const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('id', card.dataset.id);
        for (const k in data) fd.append(k, data[k]);
        const r = await fetch('caption_save.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json(); if (!j.ok) throw new Error(j.error || 'Not saved'); return j;
      }
      function settle(card, j, words) {
        const ta = card.querySelector('textarea'), tag = card.querySelector('.cp-tag'), msg = card.querySelector('.cp-msg');
        ta.value = j.caption; ta.dataset.orig = j.caption; card.classList.remove('dirty');
        tag.className = 'cp-tag ' + j.source; tag.textContent = { saved: 'Saved', draft: 'First draft', built: 'Built from the feed' }[j.source];
        card.querySelector('.cp-reset').hidden = j.source !== 'saved';
        msg.className = 'cp-msg'; msg.textContent = words; setTimeout(() => { msg.textContent = ''; }, 2500);
        count(card); tally();
      }
      function fail(card, err) { const msg = card.querySelector('.cp-msg'); msg.className = 'cp-msg bad'; msg.textContent = err.message; }
      function count(card) { const ta = card.querySelector('textarea'), c = card.querySelector('.cp-count'); c.textContent = ta.value.length.toLocaleString() + ' / 2,200'; c.classList.toggle('over', ta.value.length > 2200); }
      cards.forEach(card => {
        const ta = card.querySelector('textarea'); ta.dataset.orig = ta.value; count(card);
        ta.addEventListener('input', () => { card.classList.toggle('dirty', ta.value !== ta.dataset.orig); count(card); tally(); });
        card.querySelector('.cp-save').addEventListener('click', () => post(card, { caption: ta.value }).then(j => settle(card, j, 'Saved')).catch(e => fail(card, e)));
        card.querySelector('.cp-reset').addEventListener('click', () => { if (confirm('Drop the saved caption and go back to the draft?')) post(card, { reset: 1 }).then(j => settle(card, j, 'Back to the draft')).catch(e => fail(card, e)); });
        card.querySelector('.cp-copy').addEventListener('click', async e => {
          try { await navigator.clipboard.writeText(ta.value); } catch (_) { ta.select(); document.execCommand('copy'); }
          const b = e.currentTarget; b.textContent = 'Copied'; setTimeout(() => { b.textContent = 'Copy'; }, 1400);
        });
      });
      function filter() {
        const q = document.getElementById('cpFind').value.trim().toLowerCase(), only = document.getElementById('cpOnlyNotes').checked;
        cards.forEach(c => { c.hidden = (q && !c.dataset.find.includes(q)) || (only && c.dataset.notes !== '1'); });
      }
      document.getElementById('cpFind').addEventListener('input', filter);
      document.getElementById('cpOnlyNotes').addEventListener('change', filter);
      window.addEventListener('beforeunload', e => { if (cards.some(c => c.classList.contains('dirty'))) { e.preventDefault(); e.returnValue = ''; } });
      tally();
    })();
    </script>
<?php admin_tool_footer();
