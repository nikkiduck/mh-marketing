/* elevation-page.js — drives portal/elevation.php (2026-10-17).
   Reads window.MH_ELEVATION (the working plan, the saved list, the token) and
   window.MHElevation (assets/js/elevation.js: every formula and summary).
   Three tabs. Inputs write into one state object {plan, leads}; outputs refresh in
   place so a field keeps focus while typing (rows re-render only on add or remove);
   a save is queued a moment after each change and posted to elevation_save.php.
   The browser's localStorage mirrors the state as a fallback: used when the
   account has no working plan yet (a first visit), when a save fails, and before
   the migration has run. */
(function () {
  'use strict';
  var E = window.MHElevation, B = window.MH_ELEVATION || {}, F = E.fmt;
  var usd0 = F.usd0, int0 = F.int0, dec1 = F.dec1, fmtN = F.fmtN, short = F.short, wk = F.wk, num = F.num, esc = F.esc;
  var LS_KEY = 'mh-elevation-v1';
  var CAN_SAVE = !!B.ready && !B.preview;

  function $(id) { return document.getElementById(id); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  // ---------- State ----------
  var S = { plan: null, leads: null };
  var dirty = false, saveTimer = null, saving = null;   // declared before load(), which may mark a browser-only plan for saving
  (function load() {
    var local = null;
    try { var raw = localStorage.getItem(LS_KEY); if (raw) local = JSON.parse(raw); } catch (e) {}
    var src = B.working || (local && typeof local === 'object' ? local : null);
    S.plan = E.normalizePlan(src && src.plan);
    S.leads = E.normalizeLeads(src && src.leads);
    if (!B.working && local && CAN_SAVE) dirty = true;   // a plan that lived in this browser moves to the account
  })();
  var saved = Array.isArray(B.saved) ? B.saved : [];

  function mirror() { try { localStorage.setItem(LS_KEY, JSON.stringify(S)); } catch (e) {} }

  // ---------- Saving ----------
  function setSaveState(kind, text) {
    var el = $('ev-savestate'); if (!el) return;
    el.className = 'ev-savestate ' + kind; el.textContent = text;
  }
  function changed() {
    mirror();
    if (!CAN_SAVE) { setSaveState(B.preview ? 'off' : 'local', B.preview ? 'Preview: not saved' : 'Kept in this browser'); return; }
    dirty = true;
    setSaveState('pending', 'Unsaved changes');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNow, 900);
  }
  function post(fields) {
    var fd = new FormData();
    fd.append('csrf_token', B.csrf || '');
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(B.saveUrl, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Could not save'); return j; }); });
  }
  function saveNow() {
    clearTimeout(saveTimer);
    if (!CAN_SAVE || !dirty) return Promise.resolve();
    if (saving) return saving.then(function () { return dirty ? saveNow() : null; });
    dirty = false;
    setSaveState('saving', 'Saving');
    saving = post({ action: 'working', plan: JSON.stringify(S.plan), leads: JSON.stringify(S.leads) })
      .then(function () { saving = null; if (!dirty) setSaveState('saved', 'Saved'); })
      .catch(function (err) { saving = null; dirty = true; setSaveState('error', 'Not saved: kept in this browser'); console.warn('Elevation save failed', err); });
    return saving;
  }
  // Leaving the page with a save pending: send it on the way out.
  window.addEventListener('pagehide', function () {
    if (!CAN_SAVE || !dirty || !navigator.sendBeacon) return;
    var fd = new FormData();
    fd.append('csrf_token', B.csrf || ''); fd.append('action', 'working'); fd.append('plan', JSON.stringify(S.plan)); fd.append('leads', JSON.stringify(S.leads));
    navigator.sendBeacon(B.saveUrl, fd);
  });
  if (CAN_SAVE) setSaveState(B.working ? 'saved' : 'pending', B.working ? 'Saved' : '');
  else setSaveState(B.preview ? 'off' : 'local', B.preview ? 'Preview: not saved' : 'Kept in this browser');

  // ---------- Tabs ----------
  var TABS = ['planner', 'leads', 'saved'];
  function showTab(name, push) {
    if (TABS.indexOf(name) === -1) name = 'planner';
    TABS.forEach(function (t) {
      var on = t === name;
      $('ev-tab-' + t).hidden = !on;
      var b = $('ev-tabbtn-' + t); b.setAttribute('aria-selected', on ? 'true' : 'false'); b.classList.toggle('on', on);
    });
    if (push) { try { history.replaceState(null, '', '#' + (name === 'leads' ? 'lead-sources' : name)); } catch (e) {} }
  }
  function tabFromHash() {
    var h = (location.hash || '').replace('#', '');
    return h === 'lead-sources' ? 'leads' : (h === 'saved' ? 'saved' : 'planner');
  }
  document.querySelector('.ev-tabs').addEventListener('click', function (e) {
    var b = e.target.closest('[data-tab]'); if (!b) return;
    showTab(b.dataset.tab, true);
    window.scrollTo({ top: 0, behavior: 'auto' });
  });
  window.addEventListener('hashchange', function () { showTab(tabFromHash(), false); });

  // ---------- Part one ----------
  var P = S.plan;
  function fillPlanInputs() {
    E.NUM_KEYS.forEach(function (k) { $('p-' + k).value = P[k]; });
    ['n1', 'n2', 'n3'].forEach(function (k) { $('p-' + k).value = P[k] || ''; });
  }
  function renderMarkets() {
    $('p-markets').innerHTML = E.MARKETS.map(function (m) {
      var on = P.markets.indexOf(m) !== -1;
      return '<button type="button" class="ev-chip" data-market="' + esc(m) + '" aria-pressed="' + on + '">' + esc(m) + '</button>';
    }).join('');
  }
  function quarterWarn(total) { $('p-q-warn').textContent = Math.round(total) === 100 ? '' : 'The quarters add up to ' + int0.format(total) + '%. Adjust them to reach 100%.'; }
  function renderPlanSummary(r) { $('p-summary').innerHTML = E.planSummaryHTML(P, r); }
  function renderPlanResults() {
    var r = E.calc(P);
    if (!r) {
      ['p-o-gci', 'p-o-sides', 'p-o-vol', 'p-o-weekly'].forEach(function (id) { $(id).textContent = '-'; });
      ['p-o-gci-sub', 'p-o-vol-sub', 'p-o-weekly-sub'].forEach(function (id) { $(id).textContent = ''; });
      $('p-funnel').innerHTML = '';
      $('p-callout').textContent = 'Fill in every number above. Rates must be between 1 and 100, and the tax reserve under 100.';
      $('p-quarters').innerHTML = '';
      $('p-q-warn').textContent = '';
      renderPlanSummary(null);
      renderPlanLink();
      return;
    }
    $('p-o-gci').textContent = usd0.format(r.gci);
    $('p-o-gci-sub').textContent = 'Before your split, to take home ' + short(P.goal);
    $('p-o-sides').textContent = int0.format(r.closings);
    $('p-o-vol').textContent = short(r.volume);
    $('p-o-vol-sub').textContent = 'At ' + short(P.price) + ' average';
    $('p-o-weekly').textContent = wk(r.weekly.conversations);
    $('p-o-weekly-sub').textContent = int0.format(r.conversations) + ' over ' + P.weeks + ' weeks';

    var steps = [['Conversations', r.conversations, r.weekly.conversations], ['Appointments', r.appointments, r.weekly.appointments],
                 ['Signed agreements', r.agreements, r.weekly.agreements], ['Closings', r.closings, r.weekly.closings]];
    $('p-funnel').innerHTML = steps.map(function (st, i) {
      var pct = Math.max(3, st[1] / r.conversations * 100);
      return '<div class="ev-step' + (i === steps.length - 1 ? ' last' : '') + '">' +
        '<div class="ev-step-head"><strong>' + st[0] + '</strong><span class="ev-num">' + int0.format(st[1]) +
        ' <span class="ev-per">a year, ' + wk(st[2]) + ' a week</span></span></div>' +
        '<div class="ev-track"><div class="ev-fill" style="width:' + pct.toFixed(1) + '%"></div></div></div>';
    }).join('');
    $('p-callout').innerHTML = 'Each closing pays you about <b>' + esc(usd0.format(r.takeHomePerClosing)) +
      '</b> before expenses and taxes, on <b>' + esc(usd0.format(r.perSide)) + '</b> of commission before your split.';

    var total = 0;
    $('p-quarters').innerHTML = E.QUARTERS.map(function (q) {
      var p = Number(P[q[1]]) || 0; total += p; var f = p / 100;
      return '<tr><td>' + q[0] + '</td><td><span class="ev-qin"><input type="number" inputmode="decimal" min="0" max="100" step="1" id="q-' + q[1] + '" data-q="' + q[1] + '" value="' + p + '" aria-label="' + q[0] + ' share of year in percent"> %</span></td>' +
        '<td>' + dec1.format(r.closings * f) + '</td><td>' + usd0.format(r.gci * f) + '</td><td>' + short(r.volume * f) + '</td></tr>';
    }).join('');
    quarterWarn(total);
    renderPlanSummary(r);
    renderPlanLink();
  }
  // Quarter inputs keep focus while typing: only the computed cells change.
  function updateQuarterText() {
    var r = E.calc(P); if (!r) return;
    var rows = $('p-quarters').rows, total = 0;
    E.QUARTERS.forEach(function (q, i) {
      var p = Number(P[q[1]]) || 0; total += p;
      var f = p / 100, c = rows[i].cells;
      c[2].textContent = dec1.format(r.closings * f);
      c[3].textContent = usd0.format(r.gci * f);
      c[4].textContent = short(r.volume * f);
    });
    quarterWarn(total);
    renderPlanSummary(r);
  }

  // ---------- Part two ----------
  var L = S.leads;
  function findCh(id) { for (var i = 0; i < L.channels.length; i++) if (L.channels[i].id === id) return L.channels[i]; return null; }
  function findById(list, id) { for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i]; return null; }

  function rowHTML(c, r) {
    var p = 'r-' + r.id + '-';
    return '<div class="ev-row" data-row="' + esc(r.id) + '" data-ch="' + esc(c.id) + '">' +
      '<div class="ev-row-top">' +
        '<label class="ev-field"><span class="ev-label">Activity</span><span class="ev-control"><input id="' + p + 'name" data-f="name" type="text" maxlength="120" value="' + esc(r.name) + '" placeholder="Postcards, handwritten letters, coffee with attorneys"></span></label>' +
        '<button class="ev-btn small" type="button" data-act="del-row" aria-label="Remove this activity">Remove</button>' +
      '</div>' +
      '<div class="ev-row-nums">' +
        '<div class="ev-field ev-qty-field"><span class="ev-label">Done in a typical month</span><div class="ev-pair">' +
          '<span class="ev-control"><input id="' + p + 'qty" data-f="qty" type="number" inputmode="decimal" min="0" step="1" value="' + esc(r.qty) + '" aria-label="How many in a typical month"></span>' +
          '<span class="ev-control"><input id="' + p + 'unit" data-f="unit" type="text" maxlength="30" value="' + esc(r.unit) + '" placeholder="postcards, calls, events" aria-label="What you are counting"></span>' +
        '</div></div>' +
        '<label class="ev-field"><span class="ev-label">Hours a month</span><span class="ev-control"><input id="' + p + 'hours" data-f="hours" type="number" inputmode="decimal" min="0" step="0.5" value="' + esc(r.hours) + '"></span></label>' +
        '<label class="ev-field"><span class="ev-label">Cost a month</span><span class="ev-control"><span>$</span><input id="' + p + 'cost" data-f="cost" type="number" inputmode="decimal" min="0" step="10" value="' + esc(r.cost) + '"></span></label>' +
        '<label class="ev-field"><span class="ev-label">Conversations a month</span><span class="ev-control"><input id="' + p + 'conv" data-f="conv" type="number" inputmode="decimal" min="0" step="0.5" value="' + esc(r.conv) + '"></span></label>' +
      '</div>' +
      '<label class="ev-field"><span class="ev-label">Specifics</span><span class="ev-control"><input id="' + p + 'note" data-f="note" type="text" maxlength="200" value="' + esc(r.note) + '" placeholder="The list, the area, the message, who you will reach"></span></label>' +
      '<p class="ev-calc" data-calc></p>' +
    '</div>';
  }
  function channelHTML(c) {
    var rows = c.rows.length ? c.rows.map(function (r) { return rowHTML(c, r); }).join('') : '<p class="ev-empty">No activity yet. Add the first one.</p>';
    return '<section class="ev-channel" data-ch="' + esc(c.id) + '" aria-labelledby="l-h-' + esc(c.id) + '">' +
      '<div class="ev-ch-head">' +
        '<div class="ev-ch-title"><h2 class="ev-h2" id="l-h-' + esc(c.id) + '">' + esc(c.name) + '</h2><p class="ev-note">' + esc(c.hint || 'Add the specifics of what you do and how often.') + '</p></div>' +
        '<dl class="ev-ch-totals">' +
          '<div><dt>Conversations</dt><dd data-sub="conv">0</dd></div>' +
          '<div><dt>Hours</dt><dd data-sub="hours">0</dd></div>' +
          '<div><dt>Cost</dt><dd data-sub="cost">$0</dd></div>' +
        '</dl>' +
      '</div>' +
      '<p class="ev-label">Per month</p>' +
      '<div class="ev-rows">' + rows + '</div>' +
      '<div class="ev-ch-actions">' +
        '<button class="ev-btn" type="button" data-act="add-row">Add an activity</button>' +
        (c.custom ? '<button class="ev-btn small" type="button" data-act="del-channel">Remove this source</button>' : '') +
      '</div>' +
    '</section>';
  }
  function renderChannels() { $('l-channels').innerHTML = L.channels.map(channelHTML).join(''); }
  function toolHTML(t) {
    var p = 't-' + t.id + '-';
    return '<div class="ev-tool" data-tool="' + esc(t.id) + '">' +
      '<label class="ev-field t-name"><span class="ev-label">Tool or service</span><span class="ev-control"><input id="' + p + 'name" data-f="name" type="text" maxlength="80" value="' + esc(t.name) + '" placeholder="CRM, dialer, data list"></span></label>' +
      '<label class="ev-field t-use"><span class="ev-label">What it does for lead generation</span><span class="ev-control"><input id="' + p + 'use" data-f="use" type="text" maxlength="160" value="' + esc(t.use) + '"></span></label>' +
      '<label class="ev-field t-cost"><span class="ev-label">Cost a month</span><span class="ev-control"><span>$</span><input id="' + p + 'cost" data-f="cost" type="number" inputmode="decimal" min="0" step="5" value="' + esc(t.cost) + '"></span></label>' +
      '<button class="ev-btn small" type="button" data-act="del-tool" aria-label="Remove this software">Remove</button>' +
    '</div>';
  }
  function renderTools() { $('l-tools').innerHTML = L.tools.length ? L.tools.map(toolHTML).join('') : '<p class="ev-empty">No software yet. Add the tools you pay for.</p>'; }
  function fillTargets() { $('l-need').value = L.need; $('l-budget').value = L.budget; $('l-weeks').value = L.weeks; }
  function setText(id, t) { $(id).textContent = t; }

  function updateOutputs() {
    var c = E.compute(L), need = num(L.need), budget = num(L.budget), weeks = num(L.weeks), yc = c.yearly, p = E.pace(L, c);
    setText('l-o-conv', fmtN(yc.conv));
    setText('l-o-conv-sub', need > 0 ? Math.round(yc.conv / need * 100) + '% of the ' + fmtN(need) + ' you need' : 'Enter the conversations you need');
    setText('l-o-hrs', weeks > 0 ? dec1.format(yc.hours / weeks) : '-');
    setText('l-o-hrs-sub', fmtN(c.monthly.hours) + ' hours a month');
    setText('l-o-spend', usd0.format(yc.cost));
    var sub = $('l-o-spend-sub');
    if (budget > 0) {
      var diff = budget - yc.cost;
      sub.textContent = diff >= 0 ? usd0.format(diff) + ' left of your ' + usd0.format(budget) + ' budget' : usd0.format(-diff) + ' over your ' + usd0.format(budget) + ' budget';
      sub.classList.toggle('over', diff < 0);
    } else { sub.textContent = 'No budget set'; sub.classList.remove('over'); }
    setText('l-o-each', p.avg == null ? '-' : usd0.format(p.avg));

    $('l-pace-fill').style.width = Math.min(100, p.pct * 100) + '%';
    $('l-pace-fill').classList.toggle('met', p.met);
    setText('l-pace-pct', need > 0 ? Math.round(p.pct * 100) + '%' : '-');
    setText('l-pace-text', p.msg);

    c.channels.forEach(function (ch) {
      var el = document.querySelector('.ev-channel[data-ch="' + ch.id + '"]'); if (!el) return;
      el.querySelector('[data-sub="conv"]').textContent = fmtN(ch.conv);
      el.querySelector('[data-sub="hours"]').textContent = fmtN(ch.hours);
      el.querySelector('[data-sub="cost"]').textContent = usd0.format(ch.cost);
    });
    L.channels.forEach(function (ch) {
      ch.rows.forEach(function (r) {
        var rowEl = document.querySelector('.ev-row[data-row="' + r.id + '"] [data-calc]');
        if (rowEl) rowEl.textContent = E.rowCalcText(r);
      });
    });

    $('l-compare-body').innerHTML = E.compareRows(c).map(function (x) {
      return '<tr><td>' + esc(x.name) + '</td>' +
        '<td><span class="ev-share"><span class="ev-bar" aria-hidden="true"><i style="width:' + x.share.toFixed(1) + '%"></i></span>' + fmtN(x.conv) + '</span></td>' +
        '<td>' + fmtN(x.hours) + '</td><td>' + usd0.format(x.cost) + '</td>' +
        '<td>' + (x.costEach == null ? '-' : usd0.format(x.costEach)) + (x.flag ? '<span class="ev-flag ' + x.flag + '">' + (x.flag === 'low' ? 'Lowest cost' : 'Highest cost') + '</span>' : '') + '</td>' +
        '<td>' + (x.hoursEach == null ? '-' : fmtN(x.hoursEach)) + '</td></tr>';
    }).join('') + '<tr><td>Software + subscriptions</td><td>-</td><td>-</td><td>' + usd0.format(yc.tools) + '</td><td>-</td><td>-</td></tr>';
    $('l-compare-foot').innerHTML = '<tr><td>Total</td><td>' + fmtN(yc.conv) + '</td><td>' + fmtN(yc.hours) + '</td><td>' + usd0.format(yc.cost) + '</td><td>' +
      (p.avg == null ? '-' : usd0.format(p.avg)) + '</td><td>' + (yc.conv > 0 ? fmtN(yc.hours / yc.conv) : '-') + '</td></tr>';

    $('l-summary').innerHTML = E.leadsSummaryHTML(L, c);
  }

  // The Planner's conversation target, offered to Lead Sources (same page, so always current).
  function renderPlanLink() {
    var t = E.planTarget(P), b = $('l-use-plan');
    if (t) { b.hidden = false; b.textContent = 'Use the target from my Planner (' + fmtN(t.conv) + ' conversations)'; b._t = t; }
    else { b.hidden = true; b._t = null; }
  }

  // ---------- Saved plans ----------
  function savedCardHTML(item) {
    var plan = E.normalizePlan(item.plan), leads = E.normalizeLeads(item.leads), r = E.calc(plan), c = E.compute(leads);
    var bits = [];
    if (r) bits.push(usd0.format(plan.goal) + ' take-home', int0.format(r.closings) + ' closings', int0.format(r.conversations) + ' conversations');
    bits.push(fmtN(c.yearly.conv) + ' planned from lead sources', usd0.format(c.yearly.cost) + ' a year');
    return '<div class="ev-saved" data-id="' + (item.id | 0) + '">' +
      '<div class="ev-saved-text"><b>' + esc(item.name) + '</b><span>Saved ' + esc(item.date || item.created_at) + '</span><small>' + esc(bits.join(' · ')) + '</small></div>' +
      '<div class="ev-saved-actions">' +
        '<button type="button" class="ev-btn" data-act="open">Open</button>' +
        '<button type="button" class="ev-btn" data-act="print-saved">Print or PDF</button>' +
        '<button type="button" class="ev-btn" data-act="rename"' + (CAN_SAVE ? '' : ' disabled') + '>Rename</button>' +
        '<button type="button" class="ev-btn small" data-act="delete"' + (CAN_SAVE ? '' : ' disabled') + '>Delete</button>' +
      '</div></div>';
  }
  function renderSaved() {
    $('ev-saved-count').textContent = String(saved.length);
    $('ev-saved-list').innerHTML = saved.length ? saved.map(savedCardHTML).join('')
      : '<p class="ev-empty">' + (B.ready ? 'No saved plans yet. Use "Save this plan" under either summary to keep a copy you can come back to.' : 'Saved plans will appear here once saving is switched on.') + '</p>';
  }

  // ---------- Dialog (a name for a plan) ----------
  function askName(title, help, initial, okLabel) {
    var d = $('ev-dialog'), inp = $('ev-dialog-name');
    $('ev-dialog-h').textContent = title; $('ev-dialog-p').textContent = help; $('ev-dialog-ok').textContent = okLabel || 'Save';
    inp.value = initial || '';
    if (!d.showModal) { var v = window.prompt(title, initial || ''); return Promise.resolve(v === null ? null : v.trim()); }
    return new Promise(function (resolve) {
      function done() { d.removeEventListener('close', done); resolve(d.returnValue === 'ok' ? inp.value.trim() : null); }
      d.addEventListener('close', done);
      d.showModal();
      setTimeout(function () { inp.focus(); inp.select(); }, 30);
    });
  }

  // ---------- Status lines and two-step buttons ----------
  var statusTimers = {};
  function flash(id, msg) {
    var el = $(id); if (!el) return;
    el.textContent = msg;
    clearTimeout(statusTimers[id]);
    statusTimers[id] = setTimeout(function () { el.textContent = ''; }, 3500);
  }
  function statusFor(btn) {
    var panel = btn.closest('.ev-panel');
    return panel && panel.id === 'ev-tab-leads' ? 'l-status' : (panel && panel.id === 'ev-tab-saved' ? 's-status' : 'p-status');
  }
  function twoStep(btn, armedText, action) {
    if (btn.dataset.armed === '1') { clearTimeout(btn._t); btn.dataset.armed = ''; btn.textContent = btn._idle; action(); return; }
    btn._idle = btn.textContent;
    btn.dataset.armed = '1'; btn.textContent = armedText;
    btn._t = setTimeout(function () { btn.dataset.armed = ''; btn.textContent = btn._idle; }, 4000);
  }
  function focusId(id) { var el = $(id); if (el) el.focus(); }
  function printUrl(id) { return B.printUrl + (B.printUrl.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(id); }
  function copyText(txt, statusId) {
    function fallback() {
      var ta = document.createElement('textarea'); ta.value = txt; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      var ok = false; try { ok = document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
      flash(statusId, ok ? 'Copied' : 'Could not copy on this browser.');
    }
    try { navigator.clipboard.writeText(txt).then(function () { flash(statusId, 'Copied'); }, fallback); } catch (e) { fallback(); }
  }

  // ---------- Events ----------
  document.addEventListener('input', function (e) {
    var t = e.target; if (!t || !t.dataset) return;
    if (t.dataset.key) { P[t.dataset.key] = t.value === '' ? 0 : Number(t.value); renderPlanResults(); changed(); return; }
    if (t.dataset.q) { P[t.dataset.q] = t.value === '' ? 0 : Number(t.value); updateQuarterText(); changed(); return; }
    if (t.dataset.note) { P[t.dataset.note] = t.value; var r = E.calc(P); renderPlanSummary(r); changed(); return; }
    if (t.dataset.lkey) { L[t.dataset.lkey] = t.value === '' ? '' : Number(t.value); updateOutputs(); changed(); return; }
    var f = t.dataset.f; if (!f) return;
    var isText = (f === 'name' || f === 'unit' || f === 'note' || f === 'use');
    var val = isText ? t.value : (t.value === '' ? '' : Number(t.value));
    var rowEl = t.closest('[data-row]');
    if (rowEl) { var ch = findCh(rowEl.dataset.ch), r2 = ch && findById(ch.rows, rowEl.dataset.row); if (r2) { r2[f] = val; updateOutputs(); changed(); } return; }
    var toolEl = t.closest('[data-tool]');
    if (toolEl) { var tl = findById(L.tools, toolEl.dataset.tool); if (tl) { tl[f] = val; updateOutputs(); changed(); } }
  });

  $('p-markets').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-market]'); if (!b) return;
    var m = b.dataset.market, i = P.markets.indexOf(m);
    if (i === -1) P.markets.push(m); else P.markets.splice(i, 1);
    b.setAttribute('aria-pressed', i === -1 ? 'true' : 'false');
    renderPlanSummary(E.calc(P)); changed();
  });

  function addChannel() {
    var inp = $('l-new-channel'), name = inp.value.trim();
    if (!name) { flash('l-status', 'Enter a name for the new source.'); inp.focus(); return; }
    var nr = E.mkRow('', '', '', '', '', '', '');
    L.channels.push({ id: E.uid(), name: name, custom: true, hint: 'Describe what you do, how often, and who you reach.', rows: [nr] });
    inp.value = '';
    renderChannels(); updateOutputs(); changed(); focusId('r-' + nr.id + '-name');
  }
  $('l-new-channel').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addChannel(); } });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button'); if (!btn || btn.disabled) return;
    var act = btn.dataset.act, id = btn.id;
    if (act === 'add-row') {
      var chEl = btn.closest('[data-ch]'), ch = findCh(chEl.dataset.ch), nr = E.mkRow('', '', '', '', '', '', '');
      ch.rows.push(nr); renderChannels(); updateOutputs(); changed(); focusId('r-' + nr.id + '-name');
    } else if (act === 'del-row') {
      var rowEl = btn.closest('[data-row]'), c2 = findCh(rowEl.dataset.ch);
      c2.rows = c2.rows.filter(function (r) { return r.id !== rowEl.dataset.row; });
      renderChannels(); updateOutputs(); changed();
    } else if (act === 'del-channel') {
      var chId = btn.closest('[data-ch]').dataset.ch;
      twoStep(btn, 'Click again to remove', function () {
        L.channels = L.channels.filter(function (c) { return c.id !== chId; });
        renderChannels(); updateOutputs(); changed();
      });
    } else if (act === 'del-tool') {
      var tId = btn.closest('[data-tool]').dataset.tool;
      L.tools = L.tools.filter(function (t) { return t.id !== tId; });
      renderTools(); updateOutputs(); changed();
    } else if (id === 'l-add-tool') {
      var nt = E.mkTool('', '', '');
      L.tools.push(nt); renderTools(); updateOutputs(); changed(); focusId('t-' + nt.id + '-name');
    } else if (id === 'l-add-channel') {
      addChannel();
    } else if (id === 'l-use-plan') {
      var tg = btn._t; if (!tg) return;
      L.need = tg.conv; if (tg.weeks > 0) L.weeks = tg.weeks;
      fillTargets(); updateOutputs(); changed(); flash('l-status', 'Target updated from your Planner');
    } else if (act === 'copy') {
      var txt = btn.dataset.part === 'leads' ? E.leadsSummaryText(L, E.compute(L)) : (function () { var r = E.calc(P); return r ? E.planSummaryText(P, r) : ''; })();
      if (!txt) { flash(statusFor(btn), 'Fill in every number first.'); return; }
      copyText(txt, statusFor(btn));
    } else if (act === 'reset-plan') {
      twoStep(btn, 'Click again to reset', function () {
        S.plan = P = E.planDefaults(); fillPlanInputs(); renderMarkets(); renderPlanResults(); changed(); flash('p-status', 'Back to the example numbers');
      });
    } else if (act === 'reset-leads') {
      twoStep(btn, 'Click again to reset', function () {
        S.leads = L = E.leadsDefaults(); fillTargets(); renderChannels(); renderTools(); updateOutputs(); changed(); flash('l-status', 'Back to the example rows');
      });
    } else if (act === 'print') {
      if (!B.ready) { flash(statusFor(btn), 'Printing is available once saving is switched on. Use your browser\'s Print for now.'); return; }
      var st = statusFor(btn);
      var win = window.open('', '_blank');   // opened on the click, so the browser allows it; filled after the save
      flash(st, 'Opening your plan');
      saveNow().then(function () {
        if (win) win.location.href = printUrl('working'); else window.location.href = printUrl('working');
      });
    } else if (act === 'snapshot') {
      if (!CAN_SAVE) { flash(statusFor(btn), B.preview ? 'Preview: plans cannot be saved from here.' : 'Saving is not switched on yet.'); return; }
      var st2 = statusFor(btn);
      askName('Save this plan', 'Both parts, the Planner and Lead Sources, are saved together under this name.', B.defaultName || 'Elevation Plan', 'Save').then(function (name) {
        if (name === null) return;
        flash(st2, 'Saving');
        post({ action: 'snapshot', name: name || B.defaultName || '', plan: JSON.stringify(P), leads: JSON.stringify(L) })
          .then(function (j) { saved = j.saved || saved; renderSaved(); flash(st2, 'Saved as "' + (j.item ? j.item.name : name) + '". Find it under Saved Plans.'); })
          .catch(function (err) { flash(st2, err.message || 'Could not save.'); });
      });
    } else if (act === 'open' || act === 'print-saved' || act === 'rename' || act === 'delete') {
      var card = btn.closest('[data-id]'), sid = card ? Number(card.dataset.id) : 0, item = null;
      saved.forEach(function (x) { if (Number(x.id) === sid) item = x; });
      if (!item) return;
      if (act === 'print-saved') { window.open(printUrl(sid), '_blank'); return; }
      if (act === 'open') {
        twoStep(btn, 'Replace my working plan?', function () {
          S.plan = P = E.normalizePlan(item.plan); S.leads = L = E.normalizeLeads(item.leads);
          fillPlanInputs(); renderMarkets(); renderPlanResults(); fillTargets(); renderChannels(); renderTools(); updateOutputs(); changed();
          showTab('planner', true); window.scrollTo({ top: 0, behavior: 'auto' });
          flash('p-status', 'Opened "' + item.name + '". Changes here are yours to save again whenever you like.');
        });
        return;
      }
      if (act === 'rename') {
        askName('Rename this plan', 'A name that tells you which plan this is.', item.name, 'Rename').then(function (name) {
          if (name === null || name === '' || name === item.name) return;
          post({ action: 'rename', id: sid, name: name }).then(function (j) { saved = j.saved || saved; renderSaved(); flash('s-status', 'Renamed'); })
            .catch(function (err) { flash('s-status', err.message || 'Could not rename.'); });
        });
        return;
      }
      twoStep(btn, 'Click again to delete', function () {
        post({ action: 'delete', id: sid }).then(function (j) { saved = j.saved || saved.filter(function (x) { return Number(x.id) !== sid; }); renderSaved(); flash('s-status', 'Deleted "' + item.name + '"'); })
          .catch(function (err) { flash('s-status', err.message || 'Could not delete.'); });
      });
    }
  });

  // ---------- First draw ----------
  fillPlanInputs(); renderMarkets(); renderPlanResults();
  fillTargets(); renderChannels(); renderTools(); updateOutputs();
  renderSaved();
  showTab(tabFromHash(), false);
  if (dirty) saveNow();
})();
