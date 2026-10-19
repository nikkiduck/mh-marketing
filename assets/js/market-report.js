/* market-report.js — the Monthly Market Report (inc/market_report_body.php, 2026-10-18).
   Ported from JB's Claude dashboard artifact: the same tabs, overview table, metro panels,
   county tiles, 24-month charts and town table, drawn from window.MH_MARKET (the month's
   five datasets plus the words for the month) instead of the dashboard runtime, and the
   charts in plain SVG instead of d3 (no library, no CDN). One state object: market and
   property type; the market is kept in the hash (#aspen) as the artifact did. */
(function () {
  'use strict';
  const B = window.MH_MARKET;
  if (!B) return;
  const $ = (s, el) => (el || document).querySelector(s);
  const h = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  const NS = 'http://www.w3.org/2000/svg';
  const sv = (tag, attrs) => { const e = document.createElementNS(NS, tag); for (const k in attrs) e.setAttribute(k, attrs[k]); return e; };

  // ── Words for numbers (the artifact's rules: one decimal, a trailing .0 dropped, U+2212 minus) ──
  const money = (v) => {
    if (v == null) return '–';
    if (v >= 1e6) return '$' + (v >= 1e7 ? (v / 1e6).toFixed(1) : (v / 1e6).toFixed(2)) + 'M';
    return '$' + Math.round(v / 1e3) + 'K';
  };
  const num = (v) => v == null ? '–' : Math.round(v).toLocaleString('en-US');
  const dec1 = (v) => v == null ? '–' : (+v).toFixed(1);
  const signed = (f) => f == null ? '' : (f > 0 ? '+' : f < 0 ? '−' : '') + (Math.abs(f) * 100).toFixed(1).replace(/\.0$/, '') + '%';
  const whole = (n) => n == null ? '' : (n > 0 ? '+' : n < 0 ? '−' : '') + Math.abs(n) + '%';
  const pts = (f) => f == null ? '' : (f > 0 ? '+' : f < 0 ? '−' : '') + Math.abs(f).toFixed(1) + ' pts';
  const arrow = (f) => f == null ? '' : f > 0.0005 ? '▲ ' : f < -0.0005 ? '▼ ' : '';
  const dirCls = (f) => f == null ? 'muted' : f > 0.0005 ? 'up' : f < -0.0005 ? 'down' : 'muted';
  const parseM = (m) => new Date(m + '-01T00:00:00Z');
  const monthLab = (d) => d.toLocaleString('en-US', { month: 'short', year: 'numeric', timeZone: 'UTC' });
  const monthTick = (d) => d.toLocaleString('en-US', { month: 'short', timeZone: 'UTC' }) + " '" + String(d.getUTCFullYear()).slice(2);
  const ch = (a, b) => (a == null || b == null || b === 0) ? null : (a - b) / b;
  const ptypeWords = () => state.ptype === 'Single family' ? 'Single-family homes' : 'Townhomes and condos';

  // ── The derived figures the artifact's two calcs made ──
  const C = {};   // county key -> changes
  for (const r of B.county) {
    C[r.key] = {
      sold_chg: ch(r.sold, r.sold_ly), median_chg: ch(r.median, r.median_ly), average_chg: ch(r.average, r.average_ly),
      dom_chg: ch(r.days_on_market, r.days_on_market_ly), msi_chg: ch(r.months_supply, r.months_supply_ly),
      inventory_chg: ch(r.inventory, r.inventory_ly), new_listings_chg: ch(r.new_listings, r.new_listings_ly),
      pct_of_list_pts: (r.pct_of_list == null || r.pct_of_list_ly == null) ? null : r.pct_of_list - r.pct_of_list_ly,
      ytd_sold_chg: ch(r.ytd_sold, r.ytd_sold_ly), ytd_median_chg: ch(r.ytd_median, r.ytd_median_ly),
    };
  }
  const T = {};   // town key -> changes
  for (const r of B.towns) T[r.key] = { sold_chg: ch(r.sold, r.sold_ly), median_chg: ch(r.median, r.median_ly), ytd_median_chg: ch(r.ytd_median, r.ytd_median_ly), ytd_sold_chg: ch(r.ytd_sold, r.ytd_sold_ly) };

  const boards = B.boards.slice().sort((a, b) => a.order - b.order);
  const byM = Object.fromEntries(boards.map((b) => [b.market, b]));
  const state = { market: 'overview', ptype: 'Single family' };
  try { const want = location.hash.slice(1); if (/^[a-z]+$/.test(want) && (want === 'overview' || byM[want])) state.market = want; } catch (e) {}

  const setMarket = (m) => { state.market = m; try { history.replaceState(null, '', '#' + m); } catch (e) { location.hash = m; } draw(); window.scrollTo({ top: Math.max(0, $('#mr').getBoundingClientRect().top + window.scrollY - 16), behavior: 'smooth' }); };
  const setPtype = (p) => { state.ptype = p; draw(); };
  $('#mr-ptype').addEventListener('click', (ev) => { const b = ev.target.closest('button[data-p]'); if (b) setPtype(b.dataset.p); });
  $('#mr-tabs').addEventListener('click', (ev) => { const b = ev.target.closest('button[data-m]'); if (b) setMarket(b.dataset.m); });
  $('#mr-ov-table').addEventListener('click', (ev) => { const tr = ev.target.closest('tr[data-m]'); if (tr) setMarket(tr.dataset.m); });
  const monthSel = $('#mr-month');
  if (monthSel) monthSel.addEventListener('change', () => { const u = new URL(location.href); u.searchParams.set('m', monthSel.value); location.href = u.pathname + u.search + location.hash; });

  function credit(b, container) {
    const box = h('div', 'mr-credit');
    box.appendChild(h('div', 'who', b.mls_full));
    if (b.credit) box.appendChild(h('p', null, b.credit));
    if (b.county_credit) box.appendChild(h('p', null, 'County figures: ' + b.county_credit));
    if (b.source_url) { const p = h('p'); const a = h('a', null, b.source_url); a.href = b.source_url; a.target = '_blank'; a.rel = 'noopener'; p.appendChild(a); box.appendChild(p); }
    container.appendChild(box);
  }

  function tile(label, value, chgText, ytdEl) {
    const t = h('div', 'mr-tile');
    t.appendChild(h('div', 'lab', label));
    t.appendChild(h('div', 'v', value));
    const c = h('div', 'chg');
    if (chgText) { c.appendChild(h('b', null, chgText)); c.appendChild(document.createTextNode(' vs ' + B.ly_short)); }
    else c.textContent = 'No sales ' + B.ly_short;
    t.appendChild(c);
    if (ytdEl) t.appendChild(ytdEl);
    return t;
  }

  // ── Charts: closed sales as bars, median price as a line; 24 months ──
  function niceTicks(lo, hi, n) {
    const span = (hi - lo) || 1, raw = span / n, p = Math.pow(10, Math.floor(Math.log10(raw))), err = raw / p;
    const step = (err >= 7.07 ? 10 : err >= 3.16 ? 5 : err >= 1.41 ? 2 : 1) * p;
    const a = Math.floor(lo / step) * step, b = Math.ceil(hi / step) * step, out = [];
    for (let v = a; v <= b + step / 2; v += step) out.push(+v.toFixed(10));
    return out;
  }
  function measure(svg, texts) {
    const t = sv('text', { 'font-size': 12, visibility: 'hidden' }); svg.appendChild(t);
    let w = 0; for (const s of texts) { t.textContent = s; w = Math.max(w, t.getComputedTextLength ? t.getComputedTextLength() : s.length * 7); }
    t.remove(); return w;
  }
  function chart(el, rows, kind, tipEl) {
    el.replaceChildren();
    const W = Math.max(260, el.clientWidth || 0), H = 200;
    const svg = sv('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', width: '100%' }); el.appendChild(svg);
    const data = rows.map((r) => Object.assign({}, r, { d: parseM(r.month) }));
    const vals = data.map((r) => kind === 'sold' ? r.sold : r.median).filter((v) => v != null);
    if (!vals.length) { const t = sv('text', { x: 0, y: 20, fill: '#6f6857', 'font-size': 12 }); t.textContent = 'No sales in this period'; svg.appendChild(t); return; }
    const ticks = niceTicks(kind === 'sold' ? 0 : Math.min.apply(null, vals) * 0.85, Math.max.apply(null, vals) * 1.08, 4);
    const y0 = ticks[0], y1 = ticks[ticks.length - 1] || 1, yb = H - 24, yt = 8;
    const y = (v) => yb - (v - y0) / (y1 - y0) * (yb - yt);
    const fmt = kind === 'sold' ? num : money;
    const ml = Math.ceil(measure(svg, ticks.map(fmt))) + 10, mr = 6;
    const n = data.length, pin = 0.25, pout = 0.1, step = (W - ml - mr) / (n - pin + 2 * pout), bw = step * (1 - pin);
    const x = (i) => ml + step * pout + i * step;
    const g = sv('g', {}); svg.appendChild(g);
    for (const t of ticks) {
      g.appendChild(sv('line', { x1: ml, x2: W - mr, y1: y(t), y2: y(t), stroke: '#ece7dd', 'stroke-width': 1 }));
      const lab = sv('text', { x: ml - 8, y: y(t), dy: '0.32em', 'text-anchor': 'end', 'font-size': 12, fill: '#6f6857' }); lab.textContent = fmt(t); g.appendChild(lab);
    }
    const xt = data.map((r, i) => [r, i]).filter(([r, i]) => i % 6 === 0 || i === n - 1);
    xt.forEach(([r, i], k) => { const lab = sv('text', { x: x(i) + bw / 2, y: H - 6, 'text-anchor': k === xt.length - 1 ? 'end' : 'middle', 'font-size': 12, fill: '#6f6857' }); lab.textContent = monthTick(r.d); g.appendChild(lab); });
    const col = '#0d0c0a', mutedBar = '#cfc6b3';
    if (kind === 'sold') {
      data.forEach((r, i) => { if (r.sold == null) return; g.appendChild(sv('rect', { x: x(i), width: bw, y: y(r.sold), height: Math.max(0, y(0) - y(r.sold)), rx: 3, fill: i === n - 1 ? '#c9a96a' : mutedBar })); });
    } else {
      let d = '', pen = false;
      data.forEach((r, i) => { if (r.median == null) { pen = false; return; } d += (pen ? 'L' : 'M') + (x(i) + bw / 2).toFixed(1) + ' ' + y(r.median).toFixed(1); pen = true; });
      g.appendChild(sv('path', { d, fill: 'none', stroke: col, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
      data.forEach((r, i) => { if (r.median == null) return; g.appendChild(sv('circle', { cx: x(i) + bw / 2, cy: y(r.median), r: i === n - 1 ? 4.5 : 2, fill: i === n - 1 ? '#c9a96a' : col })); });
    }
    const hl = sv('rect', { fill: col, opacity: 0, y: 4, height: H - 28, rx: 3 }); g.appendChild(hl);
    const hit = sv('rect', { x: ml, y: 0, width: W - ml - mr, height: H, fill: 'transparent' }); svg.appendChild(hit);
    const move = (ev) => {
      const sb = svg.getBoundingClientRect(); const mx = (ev.clientX - sb.left) * (W / sb.width);
      const i = Math.max(0, Math.min(n - 1, Math.floor((mx - ml - step * pout) / step)));
      const r = data[i]; hl.setAttribute('x', x(i) - 2); hl.setAttribute('width', bw + 4); hl.setAttribute('opacity', 0.06);
      tipEl.hidden = false; tipEl.replaceChildren(h('div', 'd', monthLab(r.d)), h('div', null, kind === 'sold' ? num(r.sold) + ' closed' : (r.median == null ? 'No sales' : 'Median ' + money(r.median))));
      const box = tipEl.parentElement.getBoundingClientRect(), eb = el.getBoundingClientRect();
      const px = eb.left - box.left + (x(i) / W) * eb.width, tw = tipEl.offsetWidth;
      tipEl.style.left = Math.max(0, Math.min(box.width - tw, px - tw / 2)) + 'px'; tipEl.style.top = (eb.top - box.top - 8) + 'px';
    };
    hit.addEventListener('mousemove', move);
    hit.addEventListener('touchstart', (ev) => { if (ev.touches[0]) move(ev.touches[0]); }, { passive: true });
    hit.addEventListener('mouseleave', () => { tipEl.hidden = true; hl.setAttribute('opacity', 0); });
  }

  // ── Tabs, overview, market ──
  function drawTabs() {
    const nav = $('#mr-tabs');
    const items = [{ market: 'overview', name: 'All markets' }].concat(boards);
    nav.replaceChildren.apply(nav, items.map((b) => {
      const btn = h('button', 'mr-tab', b.name); btn.type = 'button'; btn.dataset.m = b.market;
      btn.setAttribute('role', 'tab'); btn.setAttribute('aria-selected', String(state.market === b.market)); return btn;
    }));
  }

  function metroCard(f) {
    const card = h('div', 'mr-card'); card.id = 'mr-metro-' + f.market;
    const head = h('div');
    head.appendChild(h('h3', 'mr-h3', f.region + ', ' + B.label));
    head.appendChild(h('p', 'mr-note', f.scope + ", from " + f.mls + "'s own monthly report. " + (f.market === 'recolorado' ? 'Change is against ' + B.ly_label + '.' : 'PPAR publishes the headline without year-over-year change.')));
    card.appendChild(head);
    const tiles = h('div', 'mr-tiles');
    const add = (lab, val, chText) => {
      const t = h('div', 'mr-tile'); t.appendChild(h('div', 'lab', lab)); t.appendChild(h('div', 'v', val));
      if (chText) { const c = h('div', 'chg'); c.appendChild(h('b', null, chText)); c.appendChild(document.createTextNode(' vs ' + B.ly_short)); t.appendChild(c); }
      tiles.appendChild(t);
    };
    add('Closed sales', num(f.closed), whole(f.closed_change_pct));
    add('Median price', money(f.median), whole(f.median_change_pct));
    if (f.median_days_in_mls != null) add('Median days in MLS', num(f.median_days_in_mls), f.days_change == null ? '' : (f.days_change > 0 ? '+' : '−') + Math.abs(f.days_change) + ' days');
    if (f.avg_days_on_market != null) add('Avg days on market', num(f.avg_days_on_market));
    if (f.active != null) add('Active listings', num(f.active), whole(f.active_change_pct));
    if (f.weeks_of_inventory != null) add('Weeks of inventory', num(f.weeks_of_inventory));
    card.appendChild(tiles);
    return card;
  }

  function drawOverview() {
    const rows = B.county;
    const list = rows.filter((r) => r.ptype === state.ptype && byM[r.market]).sort((a, b) => (byM[a.market].order - byM[b.market].order) || ((b.median || 0) - (a.median || 0)));
    $('#mr-ov-heading').textContent = ptypeWords() + ', ' + B.label + ', by county';
    const tb = $('#mr-ov-table tbody'); tb.replaceChildren();
    for (const r of list) {
      const c = C[r.key] || {};
      const tr = h('tr'); tr.dataset.key = r.key; tr.dataset.m = r.market;
      [[r.county, 'l'], [r.mls, 'l'], [num(r.sold)], [arrow(c.sold_chg) + signed(c.sold_chg), dirCls(c.sold_chg)], [money(r.median)], [arrow(c.median_chg) + signed(c.median_chg), dirCls(c.median_chg)],
       [num(r.days_on_market)], [r.pct_of_list == null ? '–' : r.pct_of_list.toFixed(1) + '%'], [dec1(r.months_supply)], [num(r.inventory)]]
        .forEach(([t, cls]) => tr.appendChild(h('td', cls || null, t)));
      tb.appendChild(tr);
    }
    const take = $('#mr-take');
    const top = rows.filter((r) => r.ptype === 'Single family' && r.median != null).sort((a, b) => b.median - a.median)[0];
    if (top) {
      const tc = C[top.key] || {};
      take.replaceChildren(document.createTextNode(top.county + ' has the highest single-family median of the markets we cover, '),
        h('span', null, money(top.median)), document.createTextNode(' in ' + B.name + ' ('), h('span', null, signed(tc.median_chg) || 'even'),
        document.createTextNode(' vs a year ago) on '), h('span', null, num(top.sold)),
        document.createTextNode(' closed sales. Pick a market for county detail, trends and its MLS source.'));
    } else take.textContent = '';
    const mw = $('#mr-ov-metros'); mw.replaceChildren();
    for (const f of B.front) mw.appendChild(metroCard(f));
    const oc = $('#mr-ov-credits'); oc.replaceChildren();
    boards.forEach((b) => credit(b, oc));
  }

  function drawMarket() {
    const b = byM[state.market];
    if (!b) { state.market = 'overview'; draw(); return; }
    $('#mr-mk-mls').textContent = b.mls;
    $('#mr-mk-name').textContent = b.name;
    $('#mr-mk-areas').textContent = b.areas || '';
    const post = $('#mr-mk-post');
    if (post) { const u = new URL(B.urls.post, location.origin); u.searchParams.set('market', b.market); u.searchParams.set('month', B.month); post.href = u.pathname + u.search; }
    const rows = B.county;
    const counties = rows.filter((r) => r.market === b.market && r.ptype === state.ptype);
    // The headline names the market's county with the most single-family sales this month (the artifact's rule).
    const sf = rows.filter((r) => r.market === b.market && r.ptype === 'Single family').sort((a, z) => (z.sold || 0) - (a.sold || 0))[0];
    const take = $('#mr-take');
    if (sf) {
      const sc = C[sf.key] || {};
      take.replaceChildren(document.createTextNode(sf.county + ' single-family median: '), h('span', null, money(sf.median)),
        document.createTextNode(' ('), h('span', null, signed(sc.median_chg) || 'even'), document.createTextNode(' vs a year ago) on '),
        h('span', null, num(sf.sold)), document.createTextNode(' closed sales, with '), h('span', null, dec1(sf.months_supply)),
        document.createTextNode(' months of supply.'));
    } else take.textContent = '';

    const ex = $('#mr-mk-extra'); ex.replaceChildren();
    const fr = B.front.find((f) => f.market === b.market);
    if (fr) ex.appendChild(metroCard(fr));

    const host = $('#mr-mk-counties'); host.replaceChildren();
    for (const r of counties) {
      const c = C[r.key] || {};
      const card = h('div', 'mr-card'); card.id = 'mr-county-' + r.county.toLowerCase().replace(/[^a-z]+/g, '-');
      const head = h('div');
      head.appendChild(h('h3', 'mr-h3', r.county + ' · ' + ptypeWords()));
      if (r.sold < 10) head.appendChild(h('p', 'mr-flag', 'Small sample: ' + r.sold + (r.sold === 1 ? ' sale' : ' sales') + ' this month. Read monthly changes with care; year to date is steadier.'));
      card.appendChild(head);
      const tiles = h('div', 'mr-tiles');
      const ytd = (txt, chTxt) => { const d = h('div', 'ytd'); d.appendChild(document.createTextNode('YTD ')); d.appendChild(h('span', null, txt)); if (chTxt) { d.appendChild(document.createTextNode(', ')); d.appendChild(h('span', null, chTxt)); } return d; };
      tiles.appendChild(tile('Closed sales', num(r.sold), signed(c.sold_chg), ytd(num(r.ytd_sold), signed(c.ytd_sold_chg))));
      tiles.appendChild(tile('Median price', money(r.median), signed(c.median_chg), ytd(money(r.ytd_median), signed(c.ytd_median_chg))));
      tiles.appendChild(tile('Average price', money(r.average), signed(c.average_chg), null));
      tiles.appendChild(tile('Days on market', num(r.days_on_market), signed(c.dom_chg), ytd(num(r.ytd_days_on_market))));
      tiles.appendChild(tile('% of list received', r.pct_of_list == null ? '–' : r.pct_of_list.toFixed(1) + '%', pts(c.pct_of_list_pts), null));
      tiles.appendChild(tile('Months supply', dec1(r.months_supply), signed(c.msi_chg), null));
      tiles.appendChild(tile('Homes for sale', num(r.inventory), signed(c.inventory_chg), null));
      tiles.appendChild(tile('New listings', num(r.new_listings), signed(c.new_listings_chg), null));
      card.appendChild(tiles);

      const tr = B.trend.filter((t) => t.county === r.county && t.ptype === state.ptype).sort((a, z) => a.month < z.month ? -1 : 1);
      if (tr.length) {
        const grid = h('div', 'mr-grid2');
        for (const kind of ['sold', 'median']) {
          const box = h('div', 'mr-chartbox');
          box.appendChild(h('div', 'lab mr-note', (kind === 'sold' ? 'Closed sales per month, ' : 'Median price per month, ') + B.trend_from + ' to ' + B.trend_to));
          const chEl = h('div', 'mr-chart'); box.appendChild(chEl);
          const tip = h('div', 'mr-tip'); tip.hidden = true; box.appendChild(tip);
          grid.appendChild(box);
          requestAnimationFrame(() => chart(chEl, tr, kind, tip));
        }
        card.appendChild(grid);
        card.appendChild(h('p', 'mr-note', "Monthly trend values are as CAR first published them each month; the tiles above use CAR's current figures, which include late-reported sales."));
      }
      host.appendChild(card);
    }

    const tcard = $('#mr-mk-towns-card');
    const towns = B.towns.filter((t) => t.market === b.market && t.ptype === state.ptype);
    tcard.hidden = !towns.length;
    if (towns.length) {
      const tb = $('#mr-mk-towns tbody'); tb.replaceChildren();
      const order = ['Aspen', 'Snowmass Village', 'Woody Creek', 'Old Snowmass', 'Basalt', 'Missouri Heights', 'Carbondale', 'Glenwood Springs'];
      towns.sort((a, z) => order.indexOf(a.town) - order.indexOf(z.town)).forEach((t) => {
        const c = T[t.key] || {};
        const trr = h('tr'); trr.dataset.key = t.key;
        [[t.town, 'l'], [num(t.sold)], [arrow(c.sold_chg) + signed(c.sold_chg), dirCls(c.sold_chg)], [t.sold ? money(t.median) : '–'], [arrow(c.median_chg) + signed(c.median_chg), dirCls(c.median_chg)],
         [num(t.ytd_sold)], [money(t.ytd_median)], [arrow(c.ytd_median_chg) + signed(c.ytd_median_chg), dirCls(c.ytd_median_chg)], [t.sold ? num(t.days_on_market) : '–'], [dec1(t.months_supply)], [num(t.inventory)]]
          .forEach(([s, cls]) => trr.appendChild(h('td', cls || null, s)));
        tb.appendChild(trr);
      });
    }
    drawFeedTowns(b);
    const mc = $('#mr-mk-credit'); mc.replaceChildren(); credit(b, mc);
  }

  // Town by town from our own feed (inc/market_feed.php): the Communities towns this board serves,
  // for the chosen class; no minimum (Nikki, 2026-10-18). Inventory shows once the site has a snapshot.
  function drawFeedTowns(b) {
    const card = $('#mr-mk-feed-card');
    const cls = state.ptype === 'Single family' ? 'SF' : 'TC';
    const towns = (B.feed_towns || []).filter((t) => t.market === b.market && t[cls] && ((t[cls].month && t[cls].month.sold) || (t[cls].ytd && t[cls].ytd.sold)));
    card.hidden = !towns.length;
    if (!towns.length) return;
    const tb = $('#mr-mk-feed tbody'); tb.replaceChildren();
    const bases = {};
    towns.sort((x, y) => (y[cls].ytd.sold - x[cls].ytd.sold) || x.label.localeCompare(y.label)).forEach((t) => {
      const p = t[cls], m = p.month || {}, ly = p.ly || {}, ytd = p.ytd || {}, yl = p.ytd_ly || {}, inv = p.inventory;
      const mc = ch(m.median, ly.median), sc = ch(m.sold, ly.sold), yc = ch(ytd.median, yl.median);
      if (m.dom_basis) bases[m.dom_basis] = true; else if (ytd.dom_basis) bases[ytd.dom_basis] = true;
      const tr = h('tr'); tr.dataset.key = t.key;
      [[t.label, 'l'], [num(m.sold)], [arrow(sc) + signed(sc), dirCls(sc)], [m.sold ? money(m.median) : '–'], [arrow(mc) + signed(mc), dirCls(mc)],
       [m.price_per_sqft != null ? '$' + num(m.price_per_sqft) : '–'], [m.sold ? num(m.avg_dom) : '–'],
       [num(ytd.sold)], [money(ytd.median)], [arrow(yc) + signed(yc), dirCls(yc)], [inv && inv.active != null ? num(inv.active) : '–']]
        .forEach(([s, c]) => tr.appendChild(h('td', c || null, s)));
      tb.appendChild(tr);
    });
    const words = { board: 'as the board counts them', contract: 'on market to contract', close: 'on market to closing (this board sends no contract date)' };
    const list = Object.keys(bases).map((k) => words[k] || k);
    $('#mr-mk-feed-dom').textContent = list.length ? 'Days on market: ' + list.join('; ') + '.' : '';
    $('#mr-mk-feed-note').textContent = (B.feed_note || '').replace(/\s*Days on market:[^.]*\./, '') + ' For sale is the count at the month end, from the first month-end snapshot on.';
  }

  function draw() {
    document.querySelectorAll('#mr-ptype button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.p === state.ptype)));
    if (state.market !== 'overview' && !byM[state.market]) state.market = 'overview';
    $('#mr-take').classList.remove('mr-skel');
    drawTabs();
    const ov = state.market === 'overview';
    $('#mr-overview').hidden = !ov; $('#mr-market').hidden = ov;
    if (ov) drawOverview(); else drawMarket();
  }

  let w = window.innerWidth;
  window.addEventListener('resize', () => { if (window.innerWidth !== w) { w = window.innerWidth; if (state.market !== 'overview') drawMarket(); } });
  window.addEventListener('hashchange', () => { const want = location.hash.slice(1); if (want && want !== state.market && (want === 'overview' || byM[want])) { state.market = want; draw(); } });
  draw();
})();
