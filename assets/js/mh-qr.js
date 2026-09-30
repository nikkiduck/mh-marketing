/**
 * mh-qr.js — draws Mont Haus QR codes, optionally with the triangle or the M
 * in the centre (2026-09-30). Used by qr_codes.php; the test harness loads
 * this same file, so it tests the real drawing code rather than a copy.
 *
 * Needs qrcode-generator 1.4.4 (window.qrcode). verify() also needs jsQR
 * (window.jsQR); without it verify() returns null ("could not check").
 *
 * How the mark is fitted:
 *   - Error correction goes from Q to H (recovers ~30% instead of ~25%) when a
 *     mark is drawn. The encoded text is unchanged, so a code with a mark and
 *     the same code without one open the same address.
 *   - Modules under the mark, plus a margin, are left out, and the mark is
 *     drawn over a white outline of its own shape (the 82 Northway sign look).
 *   - Versions 7 and up carry an alignment pattern at the exact centre, which
 *     the mark would cover. canMark() is false there: shorten the code or
 *     download it without a mark. For the qr.monthaus.com/<code> address that
 *     is codes longer than 34 characters.
 *   - SVG and PNG are drawn from the same geometry (module list + polygon), so
 *     they cannot disagree.
 */
(function (root) {
  'use strict';

  var QUIET = 4;          // modules of white border; scanners need it
  var PAD = 0.6;          // white outline around the mark, in modules
  var CLEAR = PAD + 0.35; // a module whose centre is this close to the mark is left out

  // Outlines taken from the final logo files (Final Logos/Triangle Mark and
  // Final Logos/M Mark), in their own units. `width` is the mark's width as a
  // share of the code's width (quiet zone excluded).
  var MARKS = {
    triangle: {
      label: 'Triangle',
      width: 0.34,
      pts: [15.44,121.49, 31.7,121.49, 72.35,52.03, 77.94,61.44, 42.78,121.5,
            124.66,121.5, 124.65,121.49, 129.82,121.49, 72.54,23.76]
    },
    m: {
      label: 'M',
      width: 0.25,
      pts: [166.87,0.02, 143.16,0.02, 83.84,101.36, 74.96,86.39, 125.53,0, 23.61,0,
            23.62,0.02, 0,0.02, 0,157.5, 28.39,157.5, 28.39,58.39, 83.56,142.6,
            138.52,57.83, 138.71,157.5, 167.1,157.5]
    }
  };

  function bounds(p) {
    var x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
    for (var i = 0; i < p.length; i += 2) {
      x0 = Math.min(x0, p[i]); x1 = Math.max(x1, p[i]);
      y0 = Math.min(y0, p[i + 1]); y1 = Math.max(y1, p[i + 1]);
    }
    return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
  }

  function inside(p, x, y) {
    var c = false;
    for (var i = 0, j = p.length - 2; i < p.length; j = i, i += 2) {
      var xi = p[i], yi = p[i + 1], xj = p[j], yj = p[j + 1];
      if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) c = !c;
    }
    return c;
  }

  function edgeDist(p, x, y) {
    var best = Infinity;
    for (var i = 0, j = p.length - 2; i < p.length; j = i, i += 2) {
      var ax = p[j], ay = p[j + 1], bx = p[i], by = p[i + 1];
      var dx = bx - ax, dy = by - ay, L = dx * dx + dy * dy;
      var t = L ? Math.max(0, Math.min(1, ((x - ax) * dx + (y - ay) * dy) / L)) : 0;
      var ex = ax + t * dx - x, ey = ay + t * dy - y;
      best = Math.min(best, Math.sqrt(ex * ex + ey * ey));
    }
    return best;
  }

  // Everything both renderers need: which modules are dark, and where the
  // mark goes, all in module units with the quiet zone included.
  function layout(text, mark) {
    if (mark && !MARKS[mark]) throw new Error('Unknown mark: ' + mark);
    var q = root.qrcode(0, mark ? 'H' : 'Q');
    q.addData(text); q.make();
    var n = q.getModuleCount(), version = (n - 17) / 4, size = n + QUIET * 2;
    var out = { n: n, version: version, size: size, dark: [], mark: null };
    if (mark && version >= 7) throw new Error('Too long for a centre mark (QR version ' + version + ')');

    var poly = null;
    if (mark) {
      var M = MARKS[mark], b = bounds(M.pts), s = (n * M.width) / b.w;
      var ox = size / 2 - (b.x + b.w / 2) * s, oy = size / 2 - (b.y + b.h / 2) * s;
      poly = [];
      for (var i = 0; i < M.pts.length; i += 2) poly.push(ox + M.pts[i] * s, oy + M.pts[i + 1] * s);
      out.mark = { name: mark, poly: poly, pad: PAD };
    }
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) {
      if (!q.isDark(r, c)) continue;
      if (poly) {
        var cx = c + QUIET + 0.5, cy = r + QUIET + 0.5;
        if (inside(poly, cx, cy) || edgeDist(poly, cx, cy) < CLEAR) continue;
      }
      out.dark.push([c + QUIET, r + QUIET]);
    }
    return out;
  }

  function fmt(v) { return Math.round(v * 1000) / 1000; }

  function svg(text, mark) {
    var L = layout(text, mark), d = '';
    L.dark.forEach(function (m) { d += 'M' + m[0] + ' ' + m[1] + 'h1v1h-1z'; });
    var s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + L.size + ' ' + L.size + '">'
          + '<rect width="' + L.size + '" height="' + L.size + '" fill="#fff"/>'
          + '<path d="' + d + '" fill="#000" shape-rendering="crispEdges"/>';
    if (L.mark) {
      var pts = L.mark.poly.map(fmt).join(' ');
      s += '<polygon points="' + pts + '" fill="#fff" stroke="#fff" stroke-width="' + (L.mark.pad * 2)
         + '" stroke-linejoin="miter" stroke-miterlimit="4"/>'
         + '<polygon points="' + pts + '" fill="#000"/>';
    }
    return s + '</svg>';
  }

  // px = pixels per module.
  function canvas(text, mark, px) {
    var L = layout(text, mark), cv = document.createElement('canvas'), x = cv.getContext('2d');
    cv.width = cv.height = L.size * px;
    x.fillStyle = '#fff'; x.fillRect(0, 0, cv.width, cv.height);
    x.fillStyle = '#000';
    L.dark.forEach(function (m) { x.fillRect(m[0] * px, m[1] * px, px, px); });
    if (L.mark) {
      var p = L.mark.poly;
      x.save(); x.scale(px, px); x.beginPath(); x.moveTo(p[0], p[1]);
      for (var i = 2; i < p.length; i += 2) x.lineTo(p[i], p[i + 1]);
      x.closePath();
      x.fillStyle = '#fff'; x.strokeStyle = '#fff'; x.lineWidth = L.mark.pad * 2;
      x.lineJoin = 'miter'; x.miterLimit = 4; x.fill(); x.stroke();
      x.fillStyle = '#000'; x.fill(); x.restore();
    }
    return cv;
  }

  function canMark(text) {
    try { layout(text, 'triangle'); return true; } catch (e) { return false; }
  }

  // Decodes our own rendering at a large and a small size and checks it reads
  // back as exactly `text`. true/false, or null when jsQR is not loaded.
  function verify(text, mark) {
    if (!root.jsQR) return null;
    return [8, 3].every(function (px) {
      var cv = canvas(text, mark, px), d = cv.getContext('2d').getImageData(0, 0, cv.width, cv.height);
      var r = root.jsQR(d.data, d.width, d.height, { inversionAttempts: 'dontInvert' });
      return !!r && r.data === text;
    });
  }

  root.MHQR = { MARKS: MARKS, layout: layout, svg: svg, canvas: canvas, canMark: canMark, verify: verify };
})(window);
