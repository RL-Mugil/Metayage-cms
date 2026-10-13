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

  /* ---------- Scene ---------- */
  var pointer = { x: 0.5, y: 0.5 };
  window.addEventListener('pointermove', function (ev) { pointer.x = ev.clientX / window.innerWidth; pointer.y = ev.clientY / window.innerHeight; }, { passive: true });

  function Scene(canvas) {
    var shape = canvas.getAttribute('data-shape') === 'torus' ? torus(30, 12, 1, 0.42) : icosphere(1);
    var inner = icosphere(0);
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
      var isTorus = canvas.getAttribute('data-shape') === 'torus';
      var cx = wide ? w * (isTorus ? 0.82 : 0.72) : w * 0.5, cy = wide ? h * 0.5 : h * 0.62;
      var size = Math.min(w * (wide ? (isTorus ? 0.2 : 0.3) : 0.42), h * (isTorus ? 0.36 : 0.42));
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
      render(inner, size * 0.42, 0.7, true);

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

  Array.prototype.forEach.call(document.querySelectorAll('canvas.cpi-3d'), function (c) { if (c.getContext) { Scene(c); } });

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
