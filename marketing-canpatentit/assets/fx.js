/* Interactive 3D effects: a mouse-reactive wireframe in hero sections and tilting cards.
   Plain canvas + CSS, no libraries. Honours prefers-reduced-motion and pauses off-screen. */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

  /* ---------- Geometry ---------- */
  function icosphere(level) {
    var t = (1 + Math.sqrt(5)) / 2;
    var v = [[-1, t, 0], [1, t, 0], [-1, -t, 0], [1, -t, 0], [0, -1, t], [0, 1, t], [0, -1, -t], [0, 1, -t], [t, 0, -1], [t, 0, 1], [-t, 0, -1], [-t, 0, 1]];
    var f = [[0, 11, 5], [0, 5, 1], [0, 1, 7], [0, 7, 10], [0, 10, 11], [1, 5, 9], [5, 11, 4], [11, 10, 2], [10, 7, 6], [7, 1, 8], [3, 9, 4], [3, 4, 2], [3, 2, 6], [3, 6, 8], [3, 8, 9], [4, 9, 5], [2, 4, 11], [6, 2, 10], [8, 6, 7], [9, 8, 1]];
    function norm(p) { var l = Math.sqrt(p[0] * p[0] + p[1] * p[1] + p[2] * p[2]); return [p[0] / l, p[1] / l, p[2] / l]; }
    v = v.map(norm);
    for (var n = 0; n < level; n++) {
      var cache = {}, nf = [];
      var mid = function (a, b) {
        var k = a < b ? a + '_' + b : b + '_' + a;
        if (cache[k] === undefined) { cache[k] = v.length; v.push(norm([(v[a][0] + v[b][0]) / 2, (v[a][1] + v[b][1]) / 2, (v[a][2] + v[b][2]) / 2])); }
        return cache[k];
      };
      f.forEach(function (tr) {
        var a = mid(tr[0], tr[1]), b = mid(tr[1], tr[2]), c = mid(tr[2], tr[0]);
        nf.push([tr[0], a, c], [tr[1], b, a], [tr[2], c, b], [a, b, c]);
      });
      f = nf;
    }
    var seen = {}, e = [];
    f.forEach(function (tr) {
      for (var i = 0; i < 3; i++) {
        var a = tr[i], b = tr[(i + 1) % 3], k = a < b ? a + '_' + b : b + '_' + a;
        if (!seen[k]) { seen[k] = 1; e.push([a, b]); }
      }
    });
    return { v: v, e: e };
  }

  function torus(U, V, R, r) {
    var v = [], e = [], i, j;
    for (i = 0; i < U; i++) {
      for (j = 0; j < V; j++) {
        var a = (i / U) * Math.PI * 2, b = (j / V) * Math.PI * 2;
        v.push([(R + r * Math.cos(b)) * Math.cos(a), r * Math.sin(b), (R + r * Math.cos(b)) * Math.sin(a)]);
      }
    }
    for (i = 0; i < U; i++) {
      for (j = 0; j < V; j++) {
        var k = i * V + j;
        e.push([k, i * V + (j + 1) % V], [k, ((i + 1) % U) * V + j]);
      }
    }
    return { v: v, e: e };
  }

  /* Per-page shapes: each service and technology page gets its own wireframe object. */
  function lattice() {
    var v = [], e = [], x, y, z, idx = function (a, b, c) { return a * 9 + b * 3 + c; };
    for (x = 0; x < 3; x++) { for (y = 0; y < 3; y++) { for (z = 0; z < 3; z++) { v.push([(x - 1) * 0.7, (y - 1) * 0.7, (z - 1) * 0.7]); } } }
    for (x = 0; x < 3; x++) { for (y = 0; y < 3; y++) { for (z = 0; z < 3; z++) {
      if (x < 2) { e.push([idx(x, y, z), idx(x + 1, y, z)]); }
      if (y < 2) { e.push([idx(x, y, z), idx(x, y + 1, z)]); }
      if (z < 2) { e.push([idx(x, y, z), idx(x, y, z + 1)]); }
    } } }
    return { v: v, e: e };
  }
  function helix() {
    var v = [], e = [], N = 44, i;
    for (i = 0; i < N; i++) {
      var y = -1.3 + (2.6 * i) / (N - 1), t = i * 0.42;
      v.push([Math.cos(t) * 0.6, y, Math.sin(t) * 0.6]);
      v.push([Math.cos(t + Math.PI) * 0.6, y, Math.sin(t + Math.PI) * 0.6]);
    }
    for (i = 0; i < N - 1; i++) { e.push([i * 2, (i + 1) * 2], [i * 2 + 1, (i + 1) * 2 + 1]); }
    for (i = 0; i < N; i += 2) { e.push([i * 2, i * 2 + 1]); }
    return { v: v, e: e };
  }
  function rings() {
    var v = [], e = [], N = 48, k, i;
    for (k = 0; k < 3; k++) {
      for (i = 0; i < N; i++) {
        var a = (i / N) * Math.PI * 2, c = Math.cos(a), s = Math.sin(a), r = 1 - k * 0.14;
        v.push(k === 0 ? [c * r, s * r, 0] : k === 1 ? [c * r, 0, s * r] : [0, c * r, s * r]);
        e.push([k * N + i, k * N + (i + 1) % N]);
      }
    }
    return { v: v, e: e };
  }
  function crystal() {
    var v = [[1.15, 0, 0], [-1.15, 0, 0], [0, 1.15, 0], [0, -1.15, 0], [0, 0, 1.15], [0, 0, -1.15]], e = [], i, j;
    for (i = 0; i < 6; i++) { for (j = i + 1; j < 6; j++) { if (Math.floor(i / 2) !== Math.floor(j / 2)) { e.push([i, j]); } } }
    var c = [[-1, -1, -1], [1, -1, -1], [1, 1, -1], [-1, 1, -1], [-1, -1, 1], [1, -1, 1], [1, 1, 1], [-1, 1, 1]];
    c.forEach(function (p) { v.push([p[0] * 0.5, p[1] * 0.5, p[2] * 0.5]); });
    [[0, 1], [1, 2], [2, 3], [3, 0], [4, 5], [5, 6], [6, 7], [7, 4], [0, 4], [1, 5], [2, 6], [3, 7]].forEach(function (p) { e.push([6 + p[0], 6 + p[1]]); });
    return { v: v, e: e };
  }
  function sheets() {
    var v = [], e = [], k;
    for (k = 0; k < 5; k++) {
      var z = -0.8 + k * 0.4, a = k * 0.13, c = Math.cos(a), s = Math.sin(a), b = v.length;
      [[-0.9, -0.65], [0.9, -0.65], [0.9, 0.65], [-0.9, 0.65], [-0.6, -0.15], [0.45, -0.15], [-0.6, 0.2], [0.2, 0.2]].forEach(function (p) { v.push([p[0] * c - p[1] * s, p[0] * s + p[1] * c, z]); });
      e.push([b, b + 1], [b + 1, b + 2], [b + 2, b + 3], [b + 3, b], [b + 4, b + 5], [b + 6, b + 7]);
    }
    return { v: v, e: e };
  }
  function layers() {
    var v = [], e = [], l, a, b, xs = [-1.0, -0.33, 0.33, 1.0], N = 3;
    for (l = 0; l < 4; l++) { for (a = 0; a < N; a++) { for (b = 0; b < N; b++) { v.push([xs[l], (a - 1) * 0.6, (b - 1) * 0.6]); } } }
    for (l = 0; l < 3; l++) {
      for (a = 0; a < N * N; a++) {
        e.push([l * 9 + a, (l + 1) * 9 + a], [l * 9 + a, (l + 1) * 9 + (a + 1) % 9], [l * 9 + a, (l + 1) * 9 + (a + 4) % 9]);
      }
    }
    return { v: v, e: e };
  }
  function network() {
    var v = [], e = [], N = 34, i, j, seen = {};
    for (i = 0; i < N; i++) {
      var y = 1 - (2 * i + 1) / N, r = Math.sqrt(1 - y * y), t = i * 2.399963;
      v.push([Math.cos(t) * r, y, Math.sin(t) * r]);
    }
    for (i = 0; i < N; i++) {
      var d = [];
      for (j = 0; j < N; j++) { if (j !== i) { d.push([j, Math.pow(v[i][0] - v[j][0], 2) + Math.pow(v[i][1] - v[j][1], 2) + Math.pow(v[i][2] - v[j][2], 2)]); } }
      d.sort(function (a, b) { return a[1] - b[1]; });
      for (j = 0; j < 3; j++) {
        var k = i < d[j][0] ? i + '_' + d[j][0] : d[j][0] + '_' + i;
        if (!seen[k]) { seen[k] = 1; e.push([i, d[j][0]]); }
      }
    }
    return { v: v, e: e };
  }
  var SHAPES = { sphere: function () { return icosphere(1); }, torus: function () { return torus(30, 12, 1, 0.42); }, lattice: lattice, helix: helix, rings: rings, crystal: crystal, sheets: sheets, layers: layers, network: network };

  /* ---------- Scene ---------- */
  var pointer = { x: 0.5, y: 0.5 };
  window.addEventListener('pointermove', function (ev) { pointer.x = ev.clientX / window.innerWidth; pointer.y = ev.clientY / window.innerHeight; }, { passive: true });

  function Scene(canvas) {
    var shapeName = canvas.getAttribute('data-shape') || 'sphere';
    var shape = (SHAPES[shapeName] || SHAPES.sphere)();
    // Scale every shape so its farthest vertex sits at radius 1; sizes then match across pages.
    var maxR = 0;
    shape.v.forEach(function (p) { maxR = Math.max(maxR, Math.sqrt(p[0] * p[0] + p[1] * p[1] + p[2] * p[2])); });
    if (maxR > 0) { shape.v = shape.v.map(function (p) { return [p[0] / maxR, p[1] / maxR, p[2] / maxR]; }); }
    var inner = (shapeName === 'sphere' || shapeName === 'torus') ? icosphere(0) : null;
    var isPage = !!canvas.closest('.cpi-page-hero');
    var ctx = canvas.getContext('2d');
    var w = 0, h = 0, dpr = 1, rx = 0.3, ry = 0, visible = true, last = 0;
    var dust = [];
    for (var i = 0; i < 46; i++) { dust.push({ a: Math.random() * 6.28, b: Math.random() * 3.14 - 1.57, r: 1.25 + Math.random() * 0.9, s: 0.1 + Math.random() * 0.25, z: Math.random() }); }
    var host = canvas.parentElement;

    function resize() {
      var rect = host.getBoundingClientRect();
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      w = Math.max(1, rect.width); h = Math.max(1, rect.height);
      canvas.width = Math.round(w * dpr); canvas.height = Math.round(h * dpr);
      canvas.style.width = w + 'px'; canvas.style.height = h + 'px';
    }

    function rot(p, ax, ay) {
      var cy = Math.cos(ay), sy = Math.sin(ay), cx = Math.cos(ax), sx = Math.sin(ax);
      var x = p[0] * cy + p[2] * sy, z = -p[0] * sy + p[2] * cy, y = p[1] * cx - z * sx;
      z = p[1] * sx + z * cx;
      return [x, y, z];
    }

    function draw(t) {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, w, h);
      var wide = w > 820;
      var cx = wide ? w * (isPage ? 0.86 : 0.72) : w * 0.5, cy = wide ? h * 0.5 : h * 0.62;
      var size = Math.min(w * (wide ? (isPage ? 0.15 : 0.3) : 0.42), h * (isPage ? 0.3 : 0.42));
      var autoY = t * 0.00018, autoX = Math.sin(t * 0.0003) * 0.12;
      var tx = 0.25 + (pointer.y - 0.5) * (coarse ? 0 : 0.9) + autoX;
      var ty = autoY + (pointer.x - 0.5) * (coarse ? 0 : 1.6);
      rx += (tx - rx) * 0.05; ry += (ty - ry) * 0.05;

      function project(p, scale) {
        var q = rot(p, rx, ry), f = 3 / (3 - q[2] * 0.9);
        return { x: cx + q[0] * scale * f, y: cy + q[1] * scale * f, z: q[2], f: f };
      }
      function render(geo, scale, alpha, inverse) {
        var pts = geo.v.map(function (p) { return project(inverse ? [p[0], -p[1], p[2]] : p, scale); });
        ctx.lineCap = 'round';
        geo.e.forEach(function (ed) {
          var a = pts[ed[0]], b = pts[ed[1]], d = (a.z + b.z) / 2, k = (d + 1) / 2;
          ctx.strokeStyle = 'rgba(57,197,240,' + (alpha * (0.12 + 0.8 * k)).toFixed(3) + ')';
          ctx.lineWidth = 0.6 + 1.1 * k;
          ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
        });
        pts.forEach(function (p) {
          var k = (p.z + 1) / 2, r = 1.2 + 3.2 * k * p.f * (size / 220);
          var g = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, r * 3.2);
          g.addColorStop(0, 'rgba(160,230,255,' + (alpha * (0.35 + 0.65 * k)).toFixed(3) + ')');
          g.addColorStop(1, 'rgba(57,197,240,0)');
          ctx.fillStyle = g; ctx.beginPath(); ctx.arc(p.x, p.y, r * 3.2, 0, 6.283); ctx.fill();
        });
      }
      // soft halo behind the shape
      var halo = ctx.createRadialGradient(cx, cy, size * 0.2, cx, cy, size * 1.7);
      halo.addColorStop(0, 'rgba(20,129,196,0.28)'); halo.addColorStop(1, 'rgba(20,129,196,0)');
      ctx.fillStyle = halo; ctx.fillRect(0, 0, w, h);

      render(shape, size, 1, false);
      if (inner) { render(inner, size * 0.42, 0.7, true); }

      // orbiting dust gives depth cues
      dust.forEach(function (d) {
        var a = d.a + t * 0.0001 * (1 + d.s * 4), p = [Math.cos(a) * Math.cos(d.b) * d.r, Math.sin(d.b) * d.r, Math.sin(a) * Math.cos(d.b) * d.r];
        var q = project(p, size);
        var k = (q.z / d.r + 1) / 2;
        ctx.fillStyle = 'rgba(236,180,57,' + (0.15 + 0.5 * k).toFixed(3) + ')';
        ctx.beginPath(); ctx.arc(q.x, q.y, 0.8 + 1.6 * k, 0, 6.283); ctx.fill();
      });
    }

    function frame(t) {
      if (visible && !document.hidden) { draw(t); }
      requestAnimationFrame(frame);
    }

    resize();
    window.addEventListener('resize', resize);
    if ('IntersectionObserver' in window) { new IntersectionObserver(function (en) { visible = en[0].isIntersecting; }).observe(host); }
    // gentle scroll parallax
    if (!reduce) {
      window.addEventListener('scroll', function () {
        var r = host.getBoundingClientRect();
        if (r.bottom > 0 && r.top < window.innerHeight) { canvas.style.transform = 'translate3d(0,' + Math.round(-r.top * 0.12) + 'px,0)'; }
      }, { passive: true });
    }
    if (reduce) { draw(2600); } else { requestAnimationFrame(frame); }
  }

  /* ---------- Home hero: the US patent process as a 3D path ---------- */
  var STAGES = [
    { n: '1', t: 'Idea & search', s: 'Prior-art check' },
    { n: '2', t: 'Provisional', s: 'Priority date secured' },
    { n: '3', t: 'Non-provisional', s: 'Claims and specification' },
    { n: '4', t: 'Examination', s: 'USPTO examiner review' },
    { n: '5', t: 'Office action', s: 'Respond and amend' },
    { n: '6', t: 'Granted', s: 'US patent issues' }
  ];

  function ProcessScene(canvas) {
    var ctx = canvas.getContext('2d'), host = canvas.parentElement;
    var w = 0, h = 0, dpr = 1, rx = 0.18, ry = -0.1, visible = true;
    // Node positions rise from lower left to upper right and weave in depth.
    var nodes = STAGES.map(function (st, i) {
      var k = i / (STAGES.length - 1);
      return { st: st, p: [-1.15 + 2.3 * k, 0.62 - 1.24 * k, 0.55 * Math.sin(k * Math.PI * 1.6)] };
    });
    // Smooth path through the nodes (Catmull-Rom), sampled for the travelling pulse.
    var path = [];
    (function () {
      var P = nodes.map(function (n) { return n.p; });
      for (var i = 0; i < P.length - 1; i++) {
        var p0 = P[Math.max(0, i - 1)], p1 = P[i], p2 = P[i + 1], p3 = P[Math.min(P.length - 1, i + 2)];
        for (var s = 0; s < 24; s++) {
          var t = s / 24, t2 = t * t, t3 = t2 * t, q = [];
          for (var d = 0; d < 3; d++) {
            q.push(0.5 * ((2 * p1[d]) + (-p0[d] + p2[d]) * t + (2 * p0[d] - 5 * p1[d] + 4 * p2[d] - p3[d]) * t2 + (-p0[d] + 3 * p1[d] - 3 * p2[d] + p3[d]) * t3));
          }
          path.push({ p: q, seg: i + t });
        }
      }
      path.push({ p: P[P.length - 1], seg: P.length - 1 });
    })();

    function resize() {
      var r = host.getBoundingClientRect();
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      w = Math.max(1, r.width); h = Math.max(1, r.height);
      canvas.width = Math.round(w * dpr); canvas.height = Math.round(h * dpr);
      canvas.style.width = w + 'px'; canvas.style.height = h + 'px';
    }
    function rot(p, ax, ay) {
      var cy = Math.cos(ay), sy = Math.sin(ay), cx = Math.cos(ax), sx = Math.sin(ax);
      var x = p[0] * cy + p[2] * sy, z = -p[0] * sy + p[2] * cy, y = p[1] * cx - z * sx;
      z = p[1] * sx + z * cx;
      return [x, y, z];
    }
    function rrect(x, y, ww, hh, r) {
      ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + ww, y, x + ww, y + hh, r); ctx.arcTo(x + ww, y + hh, x, y + hh, r);
      ctx.arcTo(x, y + hh, x, y, r); ctx.arcTo(x, y, x + ww, y, r); ctx.closePath();
    }

    function draw(t) {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, w, h);
      var wide = w > 900;
      var cx = wide ? w * 0.755 : w * 0.5, cy = wide ? h * 0.5 : h - 175;
      var size = wide ? Math.min(w * 0.25, h * 0.4) : Math.min(w * 0.24, 150);
      var tx = 0.2 + (pointer.y - 0.5) * (coarse ? 0 : 0.35) + Math.sin(t * 0.0004) * 0.04;
      var ty = -0.12 + (pointer.x - 0.5) * (coarse ? 0 : 0.6) + Math.sin(t * 0.00032) * 0.1;
      rx += (tx - rx) * 0.05; ry += (ty - ry) * 0.05;
      function project(p) {
        var q = rot(p, rx, ry), f = 3 / (3 - q[2] * 0.8);
        return { x: cx + q[0] * size * f, y: cy + q[1] * size * f, z: q[2], f: f };
      }

      // Pulse position: a filing travelling the whole process, then a short hold on "Granted".
      var CYCLE = 14000, HOLD = 2400, ph = reduce ? 1 : (t % CYCLE) / (CYCLE - HOLD);
      var prog = Math.min(1, ph) * (nodes.length - 1);

      var halo = ctx.createRadialGradient(cx, cy, size * 0.2, cx, cy, size * 1.9);
      halo.addColorStop(0, 'rgba(20,129,196,0.30)'); halo.addColorStop(1, 'rgba(20,129,196,0)');
      ctx.fillStyle = halo; ctx.fillRect(0, 0, w, h);

      // ground grid for depth
      ctx.lineWidth = 1;
      for (var g = -4; g <= 4; g++) {
        var a = project([g * 0.35, 0.95, -0.9]), b = project([g * 0.35, 0.95, 0.9]), c = project([-1.4, 0.95, g * 0.22]), d = project([1.4, 0.95, g * 0.22]);
        ctx.strokeStyle = 'rgba(57,197,240,0.10)';
        ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.moveTo(c.x, c.y); ctx.lineTo(d.x, d.y); ctx.stroke();
      }

      // the path: dim base, bright part already travelled
      var pp = path.map(function (s) { return { s: project(s.p), seg: s.seg }; });
      ctx.lineCap = 'round'; ctx.lineJoin = 'round';
      ctx.strokeStyle = 'rgba(57,197,240,0.28)'; ctx.lineWidth = 2;
      ctx.beginPath(); pp.forEach(function (q, i) { if (i) { ctx.lineTo(q.s.x, q.s.y); } else { ctx.moveTo(q.s.x, q.s.y); } }); ctx.stroke();
      ctx.strokeStyle = 'rgba(236,180,57,0.95)'; ctx.lineWidth = 3;
      ctx.beginPath();
      var started = false, head = null;
      pp.forEach(function (q) {
        if (q.seg <= prog) { if (started) { ctx.lineTo(q.s.x, q.s.y); } else { ctx.moveTo(q.s.x, q.s.y); started = true; } head = q.s; }
      });
      ctx.stroke();
      if (head) {
        var gl = ctx.createRadialGradient(head.x, head.y, 0, head.x, head.y, 22);
        gl.addColorStop(0, 'rgba(255,224,140,0.95)'); gl.addColorStop(1, 'rgba(236,180,57,0)');
        ctx.fillStyle = gl; ctx.beginPath(); ctx.arc(head.x, head.y, 22, 0, 6.283); ctx.fill();
      }

      // nodes, far to near
      var items = nodes.map(function (n, i) { return { n: n, i: i, q: project(n.p) }; }).sort(function (a, b) { return a.q.z - b.q.z; });
      items.forEach(function (it) {
        var q = it.q, done = it.i <= prog + 0.001, last = it.i === nodes.length - 1;
        var cw = size * 0.34 * q.f, ch = cw * 1.28, x = q.x - cw / 2, y = q.y - ch / 2;
        var depth = 0.55 + 0.45 * ((q.z + 1) / 2);
        ctx.save();
        ctx.globalAlpha = depth;
        ctx.shadowColor = done ? 'rgba(236,180,57,0.55)' : 'rgba(57,197,240,0.35)'; ctx.shadowBlur = 18 * q.f;
        rrect(x, y, cw, ch, 5 * q.f);
        ctx.fillStyle = last && done ? 'rgba(60,46,12,0.92)' : 'rgba(18,30,42,0.92)'; ctx.fill();
        ctx.shadowBlur = 0;
        ctx.lineWidth = 1.6; ctx.strokeStyle = done ? 'rgba(236,180,57,0.95)' : 'rgba(57,197,240,0.75)'; ctx.stroke();
        // page lines, like a document
        ctx.strokeStyle = done ? 'rgba(236,180,57,0.35)' : 'rgba(150,215,245,0.28)'; ctx.lineWidth = 1.2;
        for (var l = 0; l < 4; l++) {
          var ly = y + ch * (0.5 + l * 0.11);
          ctx.beginPath(); ctx.moveTo(x + cw * 0.16, ly); ctx.lineTo(x + cw * (l === 3 ? 0.55 : 0.84), ly); ctx.stroke();
        }
        // step number, or a check once the filing has passed
        ctx.fillStyle = done ? 'rgba(236,180,57,1)' : 'rgba(160,230,255,1)';
        ctx.font = '600 ' + Math.round(cw * 0.34) + 'px Inter, Arial, sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(done && !last ? '✓' : it.n.st.n, q.x, y + ch * 0.27);
        if (last) {
          ctx.strokeStyle = done ? 'rgba(236,180,57,1)' : 'rgba(160,230,255,0.8)'; ctx.lineWidth = 1.5;
          ctx.beginPath(); ctx.arc(q.x, y + ch * 0.27, cw * 0.26, 0, 6.283); ctx.stroke();
        }
        ctx.restore();
        // labels sit under each card
        ctx.globalAlpha = Math.min(1, depth + 0.2);
        ctx.textAlign = 'center'; ctx.textBaseline = 'top';
        var fs = Math.max(11, Math.min(15, size * 0.062 * q.f));
        ctx.font = '600 ' + fs.toFixed(1) + 'px Inter, Arial, sans-serif';
        ctx.fillStyle = done ? '#f3d27a' : '#e9f6fb';
        ctx.fillText(it.n.st.t, q.x, y + ch + 8);
        ctx.font = '400 ' + (fs * 0.82).toFixed(1) + 'px Inter, Arial, sans-serif';
        ctx.fillStyle = 'rgba(205,228,240,0.78)';
        ctx.fillText(it.n.st.s, q.x, y + ch + 8 + fs * 1.25);
        ctx.globalAlpha = 1;
      });
    }

    function frame(t) { if (visible && !document.hidden) { draw(t); } requestAnimationFrame(frame); }
    resize();
    window.addEventListener('resize', resize);
    if ('IntersectionObserver' in window) { new IntersectionObserver(function (en) { visible = en[0].isIntersecting; }).observe(host); }
    if (reduce) { draw(0); } else { requestAnimationFrame(frame); }
  }

  Array.prototype.forEach.call(document.querySelectorAll('canvas.cpi-3d'), function (c) {
    if (!c.getContext) { return; }
    if (c.getAttribute('data-shape') === 'process') { ProcessScene(c); } else { Scene(c); }
  });

  /* ---------- Cursor glow in dark hero sections ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('.cpi-hero, .cpi-page-hero, .cpi-domain-band, .cpi-founder-band, .cpi-cta-band'), function (el) {
    el.addEventListener('pointermove', function (ev) {
      var r = el.getBoundingClientRect();
      el.style.setProperty('--mx', ((ev.clientX - r.left) / r.width * 100).toFixed(1) + '%');
      el.style.setProperty('--my', ((ev.clientY - r.top) / r.height * 100).toFixed(1) + '%');
    }, { passive: true });
  });

  /* ---------- 3D tilt on cards ---------- */
  if (!reduce && !coarse) {
    Array.prototype.forEach.call(document.querySelectorAll('.cpi-card, .cpi-teaser, .cpi-related, .cpi-service-item, .cpi-domain-row'), function (el) {
      el.classList.add('cpi-tilt');
      el.addEventListener('pointermove', function (ev) {
        var r = el.getBoundingClientRect();
        var x = (ev.clientX - r.left) / r.width - 0.5, y = (ev.clientY - r.top) / r.height - 0.5;
        el.style.transform = 'perspective(800px) rotateX(' + (-y * 9).toFixed(2) + 'deg) rotateY(' + (x * 11).toFixed(2) + 'deg) translateZ(14px)';
        el.style.setProperty('--gx', ((x + 0.5) * 100).toFixed(1) + '%');
        el.style.setProperty('--gy', ((y + 0.5) * 100).toFixed(1) + '%');
      });
      el.addEventListener('pointerleave', function () { el.style.transform = ''; });
    });
  }
})();
