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
      var cx = wide ? w * 0.72 : w * 0.5, cy = wide ? h * 0.5 : h * 0.72;
      var size = wide ? Math.min(w * 0.27, h * 0.4) : Math.min(w * 0.36, h * 0.24);
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

