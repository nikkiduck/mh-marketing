/* elevation.js — the Elevation Planner's one source of truth (2026-10-17).
   Ported from the standalone pages in /Users/nikkiduck/Mont Haus Master/elevation-plan-project
   (elevation-plan.html and lead-sources.html, their CLAUDE.md has the formulas and the
   expected figures). Everything that computes or words a plan lives here:
     calc(plan)          part one: a take-home goal back to commission, closings and conversations
     compute(leads)      part two: a typical month of lead generation, times twelve
     planTarget(plan)    the conversations part one asks for, offered to part two
     planSummaryText / leadsSummaryText    the plain-text summaries (Copy summary)
     planSummaryHTML / leadsSummaryHTML    the same figures drawn as tiles and tables
     renderDocument(el, state, meta)       the printed plan (portal/elevation_print.php, the PDF)
   assets/js/elevation-page.js drives the portal page with it; inc/elevation.php inlines it
   into the document. No DOM is touched here except by renderDocument(). Keep calc() and
   compute() exactly as they are: the CLAUDE.md figures are the test. */
(function () {
  'use strict';

  var MARKETS = ['Aspen', 'Snowmass Village', 'Vail', 'Beaver Creek', 'Telluride', 'Crested Butte', 'Breckenridge', 'Steamboat Springs', 'Boulder', 'Cherry Creek', 'Montrose and Ridgeway'];
  var NUM_KEYS = ['goal', 'tax', 'expenses', 'split', 'price', 'rate', 'weeks', 'apptRate', 'agreeRate', 'closeRate'];
  var QUARTERS = [['Q1', 'q1'], ['Q2', 'q2'], ['Q3', 'q3'], ['Q4', 'q4']];
  var NOTES = [['n1', 'Where my next listings come from', 'Where your next listings come from'],
               ['n2', 'Who I will see every month', 'Who you will see every month'],
               ['n3', 'What I will stop doing', 'What you will stop doing']];

  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function uid() { return 'x' + Math.random().toString(36).slice(2, 8) + Date.now().toString(36).slice(-4); }
  function mkRow(name, qty, unit, hours, cost, conv, note) { return { id: uid(), name: name, qty: qty, unit: unit, hours: hours, cost: cost, conv: conv, note: note }; }
  function mkTool(name, use, cost) { return { id: uid(), name: name, use: use, cost: cost }; }

  // ---------- Example defaults (labelled as examples on the page; not market data) ----------
  function planDefaults() {
    return {
      goal: 400000, tax: 30, expenses: 60000, split: 70, price: 4000000, rate: 2.5, weeks: 48,
      apptRate: 10, agreeRate: 40, closeRate: 75,
      q1: 20, q2: 25, q3: 30, q4: 25,
      markets: ['Aspen'], n1: '', n2: '', n3: ''
    };
  }
  function leadsDefaults() {
    return {
      need: 350, budget: 15000, weeks: 48,
      channels: [
        { id: 'social', name: 'Social media', custom: false,
          hint: 'Direct messages, comments, group engagement and video replies that start a conversation. Posts and ads that only build awareness belong in your marketing plan.',
          rows: [
            mkRow('Direct messages to people who engage', 40, 'messages', 6, 0, 3, 'Past clients, local owners and builders who like or comment on posts'),
            mkRow('Local group and neighborhood comments', 20, 'comments', 4, 0, 1, '')
          ] },
        { id: 'networking', name: 'Networking', custom: false,
          hint: 'Events, association meetings and one-on-one coffees with referral partners.',
          rows: [
            mkRow('Association and chamber events', 3, 'events', 9, 150, 4, 'Ticket and parking costs'),
            mkRow('Coffees with referral partners', 6, 'coffees', 8, 60, 2, 'Estate attorneys, wealth managers, property managers and builders')
          ] },
        { id: 'mail', name: 'Direct mail', custom: false,
          hint: 'Postcards, letters, handwritten notes and anything else you send to a named list.',
          rows: [
            mkRow('Postcards', 500, 'postcards', 3, 450, 3, 'One neighborhood farm, mailed monthly. Cost covers printing and postage.'),
            mkRow('Handwritten letters', 40, 'letters', 6, 40, 2, 'Owners of homes held more than ten years')
          ] },
        { id: 'doors', name: 'Door knocking', custom: false,
          hint: 'Farm neighborhoods, condo buildings and the streets around your open houses.',
          rows: [
            mkRow('Condo building farm', 100, 'doors', 6, 0, 6, 'Saturday mornings')
          ] },
        { id: 'calls', name: 'Cold calling', custom: false,
          hint: 'Calls to owners you do not know yet. Scrub every list against the National Do Not Call Registry and the Colorado No-Call List.',
          rows: [
            mkRow('Calls to owners in a target area', 200, 'calls', 10, 0, 2, 'Two blocks of 90 minutes each week')
          ] },
        { id: 'other', name: 'Other methods', custom: false,
          hint: 'Open houses, referral programs, past-client calls and events you host.',
          rows: [
            mkRow('Open houses', 2, 'open houses', 8, 60, 1, ''),
            mkRow('Past-client check-in calls', 25, 'calls', 3, 0, 3, 'Anniversary of closing and annual market update')
          ] }
      ],
      tools: [
        mkTool('CRM', 'Contact records, reminders and follow-up', 99),
        mkTool('Dialer and call recording', 'Power dialing and call notes', 49),
        mkTool('Owner and contact data', 'Mailing and calling lists for the farm', 75),
        mkTool('Social scheduling', 'Reminders and message inbox', 25)
      ]
    };
  }

  /** A stored part-one state made whole: unknown keys dropped, missing ones from the examples. */
  function normalizePlan(saved) {
    var S = planDefaults();
    if (saved && typeof saved === 'object') { for (var k in saved) { if (k in S) S[k] = saved[k]; } }
    if (!Array.isArray(S.markets)) S.markets = [];
    NUM_KEYS.concat(['q1', 'q2', 'q3', 'q4']).forEach(function (k) { S[k] = S[k] === '' || S[k] == null ? 0 : Number(S[k]); if (!isFinite(S[k])) S[k] = 0; });
    ['n1', 'n2', 'n3'].forEach(function (k) { S[k] = S[k] == null ? '' : String(S[k]); });
    return S;
  }
  function validLeads(s) {
    if (!s || typeof s !== 'object' || !Array.isArray(s.channels) || !Array.isArray(s.tools)) return false;
    return s.channels.every(function (c) { return c && typeof c.id === 'string' && typeof c.name === 'string' && Array.isArray(c.rows); });
  }
  function normalizeLeads(saved) { return validLeads(saved) ? clone(saved) : leadsDefaults(); }

  // ---------- Formatting ----------
  var usd0 = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
  var int0 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
  var dec1 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1, minimumFractionDigits: 1 });
  var fmt1 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });
  function fmtN(n) { return fmt1.format(n); }
  function short(n) {
    if (n >= 1e9) return '$' + (n / 1e9).toFixed(2) + 'B';
    if (n >= 1e6) return '$' + (n / 1e6).toFixed(1) + 'M';
    if (n >= 1e3) return '$' + Math.round(n / 1e3) + 'K';
    return '$' + Math.round(n);
  }
  function wk(n) { return n >= 10 ? int0.format(Math.ceil(n)) : dec1.format(n); }
  function num(v) { var x = Number(v); return isFinite(x) && x > 0 ? x : 0; }
  function esc(v) {
    return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function text(v) { return v == null ? '' : String(v).trim(); }

  // ---------- Part one: the income goal back to activity (pure) ----------
  function calc(s) {
    var positive = ['goal', 'tax', 'split', 'price', 'rate', 'weeks', 'apptRate', 'agreeRate', 'closeRate'];
    for (var i = 0; i < positive.length; i++) { if (!(s[positive[i]] > 0)) return null; }
    if (s.tax >= 100 || s.split > 100 || s.apptRate > 100 || s.agreeRate > 100 || s.closeRate > 100) return null;
    var expenses = s.expenses > 0 ? s.expenses : 0;
    var profit = s.goal / (1 - s.tax / 100);
    var gci = (profit + expenses) / (s.split / 100);
    var perSide = s.price * s.rate / 100;
    var closings = Math.ceil(gci / perSide);
    var agreements = Math.ceil(closings / (s.closeRate / 100));
    var appointments = Math.ceil(agreements / (s.agreeRate / 100));
    var conversations = Math.ceil(appointments / (s.apptRate / 100));
    return {
      profit: profit, gci: gci, perSide: perSide, takeHomePerClosing: perSide * s.split / 100,
      closings: closings, volume: closings * s.price,
      agreements: agreements, appointments: appointments, conversations: conversations,
      weekly: { conversations: conversations / s.weeks, appointments: appointments / s.weeks, agreements: agreements / s.weeks, closings: closings / s.weeks }
    };
  }

  /** What part one asks of part two: the conversations and working weeks, or null while the plan is incomplete. */
  function planTarget(plan) {
    var r = plan ? calc(plan) : null;
    return r ? { conv: r.conversations, weeks: Number(plan.weeks) } : null;
  }

  // ---------- Part two: a typical month, times twelve (pure) ----------
  function compute(s) {
    var channels = s.channels.map(function (c) {
      var t = { id: c.id, name: c.name, conv: 0, hours: 0, cost: 0 };
      c.rows.forEach(function (r) { t.conv += num(r.conv); t.hours += num(r.hours); t.cost += num(r.cost); });
      return t;
    });
    var tools = 0;
    s.tools.forEach(function (t) { tools += num(t.cost); });
    var m = { conv: 0, hours: 0, activityCost: 0 };
    channels.forEach(function (c) { m.conv += c.conv; m.hours += c.hours; m.activityCost += c.cost; });
    m.cost = m.activityCost + tools;
    return {
      channels: channels, toolsMonthly: tools, monthly: m,
      yearly: { conv: m.conv * 12, hours: m.hours * 12, cost: m.cost * 12, activityCost: m.activityCost * 12, tools: tools * 12 }
    };
  }
  function perConv(cost, hours, conv) { return { cost: conv > 0 ? cost / conv : null, hours: conv > 0 ? hours / conv : null }; }

  /** The line under an activity row: what each conversation costs in dollars, hours and effort. */
  function rowCalcText(r) {
    var conv = num(r.conv), cost = num(r.cost), hours = num(r.hours), qty = num(r.qty);
    if (conv <= 0) return (qty > 0 || hours > 0 || cost > 0) ? 'Enter the conversations you expect to see the cost and time for each one.' : '';
    var parts = [];
    parts.push(cost > 0 ? usd0.format(cost / conv) + ' per conversation' : 'No spend');
    if (hours > 0) { var hp = hours / conv; parts.push(fmtN(hp) + (fmtN(hp) === '1' ? ' hour' : ' hours') + ' per conversation'); }
    if (qty > 0) {
      var every = qty / conv;
      parts.push('1 conversation for every ' + (every >= 10 ? Math.round(every) : fmtN(every)) + ' ' + (r.unit ? String(r.unit).trim() : 'done'));
    }
    return parts.join('  ·  ');
  }

  /** The source comparison: yearly figures per source with share and the lowest / highest cost flags. */
  function compareRows(c) {
    var withConv = c.channels.filter(function (x) { return x.conv > 0; });
    var minC = null, maxC = null;
    if (withConv.length >= 2) {
      withConv.forEach(function (x) { var e = x.cost / x.conv; if (minC === null || e < minC) minC = e; if (maxC === null || e > maxC) maxC = e; });
      if (minC === maxC) { minC = null; maxC = null; }
    }
    var totalConv = c.monthly.conv;
    return c.channels.map(function (x) {
      var pc = perConv(x.cost, x.hours, x.conv), flag = '';
      if (pc.cost != null && minC !== null) { if (pc.cost === minC) flag = 'low'; else if (pc.cost === maxC) flag = 'high'; }
      return { name: x.name, conv: x.conv * 12, hours: x.hours * 12, cost: x.cost * 12, costEach: pc.cost, hoursEach: pc.hours,
               share: totalConv > 0 ? x.conv / totalConv * 100 : 0, flag: flag };
    });
  }

  /** The pace toward the goal: the share covered and the sentence that explains it. */
  function pace(leads, c) {
    var need = num(leads.need), yc = c.yearly, avg = yc.conv > 0 ? yc.cost / yc.conv : null;
    var pct = need > 0 ? yc.conv / need : 0, msg;
    if (need <= 0) msg = 'Enter the conversations you need this year to see your pace.';
    else if (yc.conv >= need) msg = 'Your planned activity covers the goal with ' + fmtN(yc.conv - need) + ' conversations to spare.';
    else {
      var gap = need - yc.conv;
      msg = 'You are ' + fmtN(gap) + ' conversations short, about ' + fmtN(Math.ceil(gap / 12 * 10) / 10) + ' more a month.';
      if (avg != null && avg > 0) msg += ' At your current cost of ' + usd0.format(avg) + ' per conversation, closing the gap adds about ' + usd0.format(gap * avg) + ' a year.';
    }
    return { pct: pct, met: need > 0 && pct >= 1, msg: msg, avg: avg, need: need };
  }

  // ---------- Plain-text summaries (Copy summary) ----------
  function planSummaryText(S, r) {
    var lines = [];
    lines.push('ELEVATION PLAN');
    lines.push('');
    lines.push('Markets: ' + (S.markets.length ? S.markets.join(', ') : 'none selected'));
    lines.push('Take-home goal: ' + usd0.format(S.goal) + ' after ' + usd0.format(S.expenses || 0) + ' of expenses and a ' + S.tax + '% tax reserve');
    lines.push('Gross commission needed: ' + usd0.format(r.gci) + ' at a ' + S.split + '% split');
    lines.push('Closings needed: ' + int0.format(r.closings) + ' at ' + usd0.format(S.price) + ' average and ' + S.rate + '% per side');
    lines.push('Sales volume: ' + short(r.volume));
    lines.push('');
    lines.push('Yearly activity: ' + int0.format(r.conversations) + ' conversations, ' + int0.format(r.appointments) + ' appointments, ' + int0.format(r.agreements) + ' signed agreements, ' + int0.format(r.closings) + ' closings');
    lines.push('Weekly activity: ' + wk(r.weekly.conversations) + ' conversations, ' + wk(r.weekly.appointments) + ' appointments, ' + wk(r.weekly.agreements) + ' signed agreements');
    lines.push('');
    QUARTERS.forEach(function (q) {
      var f = (Number(S[q[1]]) || 0) / 100;
      lines.push(q[0] + ': ' + dec1.format(r.closings * f) + ' closings, ' + usd0.format(r.gci * f) + ' commission');
    });
    NOTES.forEach(function (n) {
      var v = S[n[0]];
      if (v && v.trim()) { lines.push(''); lines.push(n[1].toUpperCase()); lines.push(v.trim()); }
    });
    return lines.join('\n');
  }

  function leadsSummaryText(S, c) {
    var need = num(S.need), budget = num(S.budget), yc = c.yearly;
    var L = [];
    L.push('ELEVATION LEAD SOURCES');
    L.push('');
    L.push('Conversations needed this year: ' + fmtN(need));
    L.push('Conversations planned: ' + fmtN(yc.conv) + (need > 0 ? ' (' + Math.round(yc.conv / need * 100) + '% of the goal)' : ''));
    L.push('Time: ' + fmtN(c.monthly.hours) + ' hours a month' + (num(S.weeks) > 0 ? ', about ' + dec1.format(yc.hours / num(S.weeks)) + ' a week' : ''));
    L.push('Spend: ' + usd0.format(yc.cost) + ' a year (' + usd0.format(yc.activityCost) + ' activities, ' + usd0.format(yc.tools) + ' software)' + (budget > 0 ? ' against a ' + usd0.format(budget) + ' budget' : ''));
    if (yc.conv > 0) L.push('Cost per conversation: ' + usd0.format(yc.cost / yc.conv));
    S.channels.forEach(function (ch, i) {
      var t = c.channels[i];
      L.push('');
      L.push(ch.name.toUpperCase() + ': ' + fmtN(t.conv) + ' conversations, ' + fmtN(t.hours) + ' hours, ' + usd0.format(t.cost) + ' a month');
      ch.rows.forEach(function (r) {
        if (!(r.name && String(r.name).trim()) && !num(r.qty) && !num(r.conv)) return;
        var bits = [];
        if (num(r.qty)) bits.push(fmtN(num(r.qty)) + ' ' + (r.unit ? String(r.unit).trim() : 'done'));
        if (num(r.hours)) bits.push(fmtN(num(r.hours)) + ' hours');
        if (num(r.cost)) bits.push(usd0.format(num(r.cost)));
        if (num(r.conv)) bits.push(fmtN(num(r.conv)) + ' conversations');
        L.push('- ' + (String(r.name || '').trim() || 'Unnamed activity') + (bits.length ? ': ' + bits.join(', ') : '') + (r.note && String(r.note).trim() ? ' (' + String(r.note).trim() + ')' : ''));
      });
    });
    L.push('');
    L.push('SOFTWARE: ' + usd0.format(c.toolsMonthly) + ' a month');
    S.tools.forEach(function (t) {
      if (!(t.name && String(t.name).trim())) return;
      L.push('- ' + String(t.name).trim() + ': ' + usd0.format(num(t.cost)) + (t.use && String(t.use).trim() ? ' (' + String(t.use).trim() + ')' : ''));
    });
    return L.join('\n');
  }

  // ---------- The same figures, drawn (the page's summary and the printed document) ----------
  function tile(label, value, sub, hi) {
    return '<div class="ev-s-tile' + (hi ? ' hi' : '') + '"><span class="ev-s-label">' + esc(label) + '</span><span class="ev-s-value">' + esc(value) + '</span>' + (sub ? '<span class="ev-s-sub">' + esc(sub) + '</span>' : '') + '</div>';
  }
  function kv(label, value) { return '<div class="ev-s-kv"><span>' + esc(label) + '</span><b>' + esc(value) + '</b></div>'; }
  function scrollable(tableHtml) { return '<div class="ev-s-scroll">' + tableHtml + '</div>'; }
  function h(level, textValue) { return '<h' + level + ' class="ev-s-h' + level + '">' + esc(textValue) + '</h' + level + '>'; }

  function planSummaryHTML(S, r) {
    if (!r) return '<p class="ev-s-empty">Fill in every number in the Planner to see the summary. Rates must be between 1 and 100, and the tax reserve under 100.</p>';
    var out = '';
    out += '<div class="ev-s-tiles">'
      + tile('Take-home goal', usd0.format(S.goal), 'After ' + usd0.format(S.expenses || 0) + ' of expenses and a ' + S.tax + '% tax reserve', true)
      + tile('Gross commission needed', usd0.format(r.gci), 'At a ' + S.split + '% split')
      + tile('Closings needed', int0.format(r.closings), 'At ' + short(S.price) + ' average, ' + S.rate + '% per side')
      + tile('Sales volume', short(r.volume), int0.format(r.closings) + ' sides')
      + tile('Conversations a week', wk(r.weekly.conversations), int0.format(r.conversations) + ' over ' + S.weeks + ' weeks')
      + '</div>';
    out += '<div class="ev-s-cols">';
    out += '<div>' + h(3, 'The activity behind it')
      + '<table class="ev-s-table"><thead><tr><th>Step</th><th>A year</th><th>A week</th></tr></thead><tbody>'
      + '<tr><td>Conversations</td><td>' + int0.format(r.conversations) + '</td><td>' + wk(r.weekly.conversations) + '</td></tr>'
      + '<tr><td>Appointments</td><td>' + int0.format(r.appointments) + '</td><td>' + wk(r.weekly.appointments) + '</td></tr>'
      + '<tr><td>Signed agreements</td><td>' + int0.format(r.agreements) + '</td><td>' + wk(r.weekly.agreements) + '</td></tr>'
      + '<tr class="hi"><td>Closings</td><td>' + int0.format(r.closings) + '</td><td>' + wk(r.weekly.closings) + '</td></tr>'
      + '</tbody></table>'
      + '<p class="ev-s-note">Each closing pays you about ' + esc(usd0.format(r.takeHomePerClosing)) + ' before expenses and taxes, on ' + esc(usd0.format(r.perSide)) + ' of commission before your split.</p></div>';
    var total = 0;
    out += '<div>' + h(3, 'Quarter by quarter')
      + scrollable('<table class="ev-s-table"><thead><tr><th>Quarter</th><th>Share</th><th>Closings</th><th>Commission</th><th>Volume</th></tr></thead><tbody>'
      + QUARTERS.map(function (q) {
          var p = Number(S[q[1]]) || 0; total += p; var f = p / 100;
          return '<tr><td>' + q[0] + '</td><td>' + int0.format(p) + '%</td><td>' + dec1.format(r.closings * f) + '</td><td>' + usd0.format(r.gci * f) + '</td><td>' + short(r.volume * f) + '</td></tr>';
        }).join('')
      + '</tbody></table>')
      + (Math.round(total) === 100 ? '' : '<p class="ev-s-note warn">The quarters add up to ' + esc(int0.format(total)) + '%, not 100%.</p>') + '</div>';
    out += '</div>';
    out += h(3, 'Your numbers') + '<div class="ev-s-kvs">'
      + kv('Take-home goal', usd0.format(S.goal)) + kv('Business expenses a year', usd0.format(S.expenses || 0)) + kv('Tax reserve', S.tax + '%')
      + kv('Share after brokerage split', S.split + '%') + kv('Working weeks a year', String(S.weeks)) + kv('Average sale price', usd0.format(S.price))
      + kv('Commission per side', S.rate + '%') + kv('Conversations to appointments', S.apptRate + '%') + kv('Appointments to signed agreements', S.agreeRate + '%')
      + kv('Signed agreements that close', S.closeRate + '%') + '</div>';
    out += h(3, 'Where you will work') + '<p class="ev-s-p">' + (S.markets.length ? esc(S.markets.join(', ')) : '<span class="ev-s-muted">No focus markets chosen</span>') + '</p>';
    var notes = NOTES.filter(function (n) { return text(S[n[0]]) !== ''; });
    if (notes.length) {
      out += '<div class="ev-s-notes">' + notes.map(function (n) {
        return '<div class="ev-s-notebox"><span class="ev-s-label">' + esc(n[2]) + '</span><p class="ev-s-p">' + esc(text(S[n[0]])).replace(/\n/g, '<br>') + '</p></div>';
      }).join('') + '</div>';
    }
    return out;
  }

  function leadsSummaryHTML(S, c) {
    var need = num(S.need), budget = num(S.budget), weeks = num(S.weeks), yc = c.yearly, p = pace(S, c);
    var out = '';
    var spendSub = budget > 0 ? (budget - yc.cost >= 0 ? usd0.format(budget - yc.cost) + ' left of your ' + usd0.format(budget) + ' budget' : usd0.format(yc.cost - budget) + ' over your ' + usd0.format(budget) + ' budget') : 'No budget set';
    out += '<div class="ev-s-tiles four">'
      + tile('Conversations a year', fmtN(yc.conv), need > 0 ? Math.round(yc.conv / need * 100) + '% of the ' + fmtN(need) + ' you need' : 'Enter the conversations you need', true)
      + tile('Hours a week', weeks > 0 ? dec1.format(yc.hours / weeks) : '-', fmtN(c.monthly.hours) + ' hours a month')
      + '<div class="ev-s-tile' + (budget > 0 && budget - yc.cost < 0 ? ' over' : '') + '"><span class="ev-s-label">Spend a year</span><span class="ev-s-value">' + esc(usd0.format(yc.cost)) + '</span><span class="ev-s-sub">' + esc(spendSub) + '</span></div>'
      + tile('Cost per conversation', p.avg == null ? '-' : usd0.format(p.avg), 'Software included')
      + '</div>';
    out += '<div class="ev-s-pace"><div class="ev-s-pacehead"><span class="ev-s-label">Pace toward your goal</span><b>' + (need > 0 ? Math.round(p.pct * 100) + '%' : '-') + '</b></div>'
      + '<div class="ev-s-track"><div class="ev-s-fill' + (p.met ? ' met' : '') + '" style="width:' + Math.min(100, p.pct * 100).toFixed(1) + '%"></div></div>'
      + '<p class="ev-s-note">' + esc(p.msg) + '</p></div>';
    out += h(3, 'Which sources earn their time')
      + scrollable('<table class="ev-s-table wide"><thead><tr><th>Source</th><th>Conversations a year</th><th>Hours a year</th><th>Spend a year</th><th>Cost each</th><th>Hours each</th></tr></thead><tbody>'
      + compareRows(c).map(function (x) {
          return '<tr><td>' + esc(x.name) + '</td>'
            + '<td><span class="ev-s-share"><span class="ev-s-bar"><i style="width:' + x.share.toFixed(1) + '%"></i></span>' + fmtN(x.conv) + '</span></td>'
            + '<td>' + fmtN(x.hours) + '</td><td>' + usd0.format(x.cost) + '</td>'
            + '<td>' + (x.costEach == null ? '-' : usd0.format(x.costEach)) + (x.flag ? '<span class="ev-s-flag ' + x.flag + '">' + (x.flag === 'low' ? 'Lowest cost' : 'Highest cost') + '</span>' : '') + '</td>'
            + '<td>' + (x.hoursEach == null ? '-' : fmtN(x.hoursEach)) + '</td></tr>';
        }).join('')
      + '<tr><td>Software + subscriptions</td><td>-</td><td>-</td><td>' + usd0.format(yc.tools) + '</td><td>-</td><td>-</td></tr>'
      + '</tbody><tfoot><tr><td>Total</td><td>' + fmtN(yc.conv) + '</td><td>' + fmtN(yc.hours) + '</td><td>' + usd0.format(yc.cost) + '</td><td>' + (p.avg == null ? '-' : usd0.format(p.avg)) + '</td><td>' + (yc.conv > 0 ? fmtN(yc.hours / yc.conv) : '-') + '</td></tr></tfoot></table>')
      + '<p class="ev-s-note">Cost and hours per conversation for each source leave out software, which supports every source. The total row includes it.</p>';
    S.channels.forEach(function (ch, i) {
      var t = c.channels[i];
      var rows = ch.rows.filter(function (r) { return text(r.name) !== '' || num(r.qty) || num(r.conv) || num(r.hours) || num(r.cost); });
      out += '<div class="ev-s-source"><div class="ev-s-sourcehead">' + h(3, ch.name || 'Unnamed source')
        + '<span class="ev-s-sourcetotals">' + esc(fmtN(t.conv)) + ' conversations · ' + esc(fmtN(t.hours)) + ' hours · ' + esc(usd0.format(t.cost)) + ' a month</span></div>';
      if (!rows.length) { out += '<p class="ev-s-muted">No activity listed.</p></div>'; return; }
      out += scrollable('<table class="ev-s-table wide acts"><thead><tr><th>Activity, a typical month</th><th>How many</th><th>Hours</th><th>Cost</th><th>Conversations</th><th>Each</th></tr></thead><tbody>'
        + rows.map(function (r) {
            var calcText = rowCalcText(r);
            return '<tr><td><b>' + esc(text(r.name) || 'Unnamed activity') + '</b>' + (text(r.note) ? '<span class="ev-s-specifics">' + esc(text(r.note)) + '</span>' : '') + '</td>'
              + '<td>' + (num(r.qty) ? esc(fmtN(num(r.qty)) + ' ' + (text(r.unit) || 'done')) : '-') + '</td>'
              + '<td>' + (num(r.hours) ? fmtN(num(r.hours)) : '-') + '</td><td>' + (num(r.cost) ? usd0.format(num(r.cost)) : '-') + '</td>'
              + '<td>' + (num(r.conv) ? fmtN(num(r.conv)) : '-') + '</td><td class="each">' + esc(calcText).replace(/  ·  /g, '<br>') + '</td></tr>';
          }).join('') + '</tbody></table>') + '</div>';
    });
    var tools = S.tools.filter(function (t) { return text(t.name) !== '' || num(t.cost); });
    out += '<div class="ev-s-source"><div class="ev-s-sourcehead">' + h(3, 'Software and subscriptions') + '<span class="ev-s-sourcetotals">' + esc(usd0.format(c.toolsMonthly)) + ' a month</span></div>';
    out += tools.length
      ? scrollable('<table class="ev-s-table wide"><thead><tr><th>Tool or service</th><th>What it does for lead generation</th><th>A month</th><th>A year</th></tr></thead><tbody>'
        + tools.map(function (t) { return '<tr><td><b>' + esc(text(t.name) || 'Unnamed tool') + '</b></td><td>' + esc(text(t.use)) + '</td><td>' + usd0.format(num(t.cost)) + '</td><td>' + usd0.format(num(t.cost) * 12) + '</td></tr>'; }).join('')
        + '</tbody></table>') + '</div>'
      : '<p class="ev-s-muted">No software listed.</p></div>';
    return out;
  }

  /** The printed plan: the logo, the plan's name, the agent and the date; part one, then part two. No ledes, no footer (Nikki, 2026-10-18). meta: {agent, name, saved, date, logo}. */
  function renderDocument(el, state, meta) {
    var plan = normalizePlan(state && state.plan), leads = normalizeLeads(state && state.leads);
    var r = calc(plan), c = compute(leads);
    var html = '<header class="ev-d-head">'
      + (meta.logo ? '<img class="ev-d-logo" src="' + esc(meta.logo) + '" alt="Mont Haus International Realty">' : '')
      + '<div class="ev-d-title"><span class="ev-s-label">Elevation Plan</span><h1>' + esc(meta.saved ? meta.name : 'Working plan') + '</h1>'
      + '<p class="ev-d-by">' + esc(meta.agent) + ' · ' + esc(meta.date) + '</p></div></header>';   // the name and the date, nothing more (Nikki, 2026-10-18)
    html += '<section class="ev-d-part"><h2 class="ev-s-h2"><span>Part one</span>Elevation Plan</h2>' + planSummaryHTML(plan, r) + '</section>';
    html += '<section class="ev-d-part break"><h2 class="ev-s-h2"><span>Part two</span>Lead Sources</h2>' + leadsSummaryHTML(leads, c) + '</section>';
    el.innerHTML = html;
  }

  window.MHElevation = {
    MARKETS: MARKETS, NUM_KEYS: NUM_KEYS, QUARTERS: QUARTERS, NOTES: NOTES,
    clone: clone, uid: uid, mkRow: mkRow, mkTool: mkTool,
    planDefaults: planDefaults, leadsDefaults: leadsDefaults, normalizePlan: normalizePlan, normalizeLeads: normalizeLeads, validLeads: validLeads,
    fmt: { usd0: usd0, int0: int0, dec1: dec1, fmt1: fmt1, fmtN: fmtN, short: short, wk: wk, num: num, esc: esc, text: text },
    calc: calc, compute: compute, planTarget: planTarget, rowCalcText: rowCalcText, compareRows: compareRows, pace: pace,
    planSummaryText: planSummaryText, leadsSummaryText: leadsSummaryText, planSummaryHTML: planSummaryHTML, leadsSummaryHTML: leadsSummaryHTML,
    renderDocument: renderDocument
  };
})();
