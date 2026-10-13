"""Renders original themed hero art for each service and technology page (no stock photos).

Run:  python tools/cpi-site/make_heroes.py  ->  marketing-canpatentit/assets/heroes/<slug>.jpg
Art is composed around x~1000/1600 so page text on the left stays readable; a CSS overlay and the
page's 3D shape (assets/fx.js) sit on top.
"""
import math
import os
import random

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, "marketing-canpatentit", "assets", "heroes")
W, H, S = 1600, 640, 2  # final size, supersample factor
CX, CY = 1000, 330

CYAN, BLUE, GOLD, GREEN, WHITE = (57, 197, 240), (20, 129, 196), (236, 180, 57), (48, 182, 125), (225, 245, 255)


def font(size):
    for f in ("segoeui.ttf", "arial.ttf", "consola.ttf"):
        try:
            return ImageFont.truetype(f, int(size * S))
        except OSError:
            pass
    return ImageFont.load_default()


def mono(size):
    for f in ("consola.ttf", "cour.ttf"):
        try:
            return ImageFont.truetype(f, int(size * S))
        except OSError:
            pass
    return ImageFont.load_default()


def base(seed):
    rng = np.random.default_rng(seed)
    yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
    g = 0.05 + 0.05 * (1 - yy / H)
    img = np.stack([g * 0.85, g * 0.98, g * 1.15], -1)
    glow = np.exp(-(((xx - CX) / 520) ** 2 + ((yy - CY) / 260) ** 2))
    img += (0.16 * glow)[..., None] * np.array([0.15, 0.55, 0.9], np.float32)
    for _ in range(26):
        bx, by, br = rng.uniform(500, W), rng.uniform(0, H), rng.uniform(10, 34)
        d = np.sqrt((xx - bx) ** 2 + (yy - by) ** 2)
        c = np.array([0.2, 0.65, 1.0] if rng.random() < 0.75 else [1.0, 0.75, 0.3], np.float32)
        t = np.clip((br - d) / (br * 0.15), 0, 1)
        img += (0.10 * t * rng.uniform(0.3, 1))[..., None] * c
    return Image.fromarray((np.clip(img, 0, 1) * 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(3)).convert("RGBA")


class Art:
    def __init__(self):
        self.im = Image.new("RGBA", (W * S, H * S), (0, 0, 0, 0))
        self.d = ImageDraw.Draw(self.im)

    def line(self, p, q, col=CYAN, w=1.5, a=200):
        self.d.line([p[0] * S, p[1] * S, q[0] * S, q[1] * S], fill=col + (int(a),), width=max(1, int(w * S)))

    def poly(self, pts, col=CYAN, w=1.5, a=200, fill=None, closed=True):
        pp = [(x * S, y * S) for x, y in pts]
        if fill is not None:
            self.d.polygon(pp, fill=fill)
        if closed:
            pp = pp + [pp[0]]
        self.d.line(pp, fill=col + (int(a),), width=max(1, int(w * S)), joint="curve")

    def circle(self, c, r, col=CYAN, w=1.5, a=200, fill=None):
        box = [(c[0] - r) * S, (c[1] - r) * S, (c[0] + r) * S, (c[1] + r) * S]
        self.d.ellipse(box, outline=col + (int(a),), width=max(1, int(w * S)), fill=fill)

    def dot(self, c, r, col=CYAN, a=255):
        self.d.ellipse([(c[0] - r) * S, (c[1] - r) * S, (c[0] + r) * S, (c[1] + r) * S], fill=col + (int(a),))

    def rect(self, x0, y0, x1, y1, col=CYAN, w=1.5, a=200, fill=None, radius=0):
        box = [x0 * S, y0 * S, x1 * S, y1 * S]
        if radius:
            self.d.rounded_rectangle(box, radius=radius * S, outline=col + (int(a),), width=max(1, int(w * S)), fill=fill)
        else:
            self.d.rectangle(box, outline=col + (int(a),), width=max(1, int(w * S)), fill=fill)

    def text(self, xy, s, col=CYAN, size=14, a=200, f=None):
        self.d.text((xy[0] * S, xy[1] * S), s, fill=col + (int(a),), font=f or font(size), anchor="mm")

    def arc(self, c, r, start, end, col=CYAN, w=1.5, a=200):
        self.d.arc([(c[0] - r) * S, (c[1] - r) * S, (c[0] + r) * S, (c[1] + r) * S], start, end, fill=col + (int(a),), width=max(1, int(w * S)))


def finish(bg, art, name, seed=1):
    small = art.im.resize((W, H), Image.LANCZOS)
    out = bg
    for blur, boost in ((26, 0.9), (9, 0.9), (0, 1.0)):
        layer = small.filter(ImageFilter.GaussianBlur(blur)) if blur else small
        if boost != 1.0:
            r, g, b, a = layer.split()
            a = a.point(lambda v: min(255, int(v * boost)))
            layer = Image.merge("RGBA", (r, g, b, a))
        out = Image.alpha_composite(out, layer)
    arr = np.asarray(out.convert("RGB"), np.float32) / 255
    yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
    vig = 1 - 0.5 * np.clip((np.sqrt(((xx - W / 2) / (W / 2)) ** 2 + ((yy - H / 2) / (H / 2)) ** 2) - 0.6) / 0.7, 0, 1)
    arr *= vig[..., None]
    arr += np.random.default_rng(seed).normal(0, 0.010, arr.shape).astype(np.float32)
    os.makedirs(OUT, exist_ok=True)
    path = os.path.join(OUT, name + ".jpg")
    Image.fromarray((np.clip(arr, 0, 1) * 255).astype(np.uint8)).save(path, quality=78, optimize=True, progressive=True)
    print(name, os.path.getsize(path) // 1024, "KB")


# ---------------------------------------------------------------- technology areas
def ai():
    a, rnd = Art(), random.Random(3)
    xs = [700, 850, 1000, 1150, 1300]
    cols = [[CY + 10 * (i - 3) for i in range(rnd.randint(5, 8))] for _ in xs]
    layers = []
    for i, x in enumerate(xs):
        n = [5, 7, 8, 7, 4][i]
        ys = [CY + (k - (n - 1) / 2) * 62 + rnd.uniform(-8, 8) for k in range(n)]
        layers.append([(x + rnd.uniform(-14, 14), y) for y in ys])
    for i in range(len(layers) - 1):
        for p in layers[i]:
            for q in layers[i + 1]:
                a.line(p, q, CYAN, 0.8, 38)
    path = [layers[i][rnd.randrange(len(layers[i]))] for i in range(len(layers))]
    for p, q in zip(path, path[1:]):
        a.line(p, q, GOLD, 2.4, 230)
    for i, layer in enumerate(layers):
        for p in layer:
            hot = p in path
            a.dot(p, 7 if hot else 5, GOLD if hot else CYAN, 255)
            a.circle(p, 11, GOLD if hot else BLUE, 1, 140)
    return a


def semis():
    a, rnd = Art(), random.Random(5)
    cx, cy, s = CX, CY, 150
    a.rect(cx - s, cy - s, cx + s, cy + s, CYAN, 2.4, 230, fill=(10, 24, 36, 150), radius=10)
    a.rect(cx - s + 40, cy - s + 40, cx + s - 40, cy + s - 40, BLUE, 1.6, 220, fill=(20, 60, 90, 120))
    for k in range(-3, 4):
        a.line((cx - 70, cy + k * 22), (cx + 70, cy + k * 22), CYAN, 0.8, 90)
        a.line((cx + k * 22, cy - 70), (cx + k * 22, cy + 70), CYAN, 0.8, 90)
    a.rect(cx - 28, cy - 28, cx + 28, cy + 28, GOLD, 2, 240, fill=(236, 180, 57, 60))
    pins = 12
    for side in range(4):
        for i in range(pins):
            t = -s + 24 + i * ((2 * s - 48) / (pins - 1))
            if side == 0:
                p0, p1 = (cx + t, cy - s), (cx + t, cy - s - 26)
            elif side == 1:
                p0, p1 = (cx + t, cy + s), (cx + t, cy + s + 26)
            elif side == 2:
                p0, p1 = (cx - s, cy + t), (cx - s - 26, cy + t)
            else:
                p0, p1 = (cx + s, cy + t), (cx + s + 26, cy + t)
            a.line(p0, p1, CYAN, 2.2, 220)
            # Manhattan trace outward
            dx, dy = p1[0] - p0[0], p1[1] - p0[1]
            q = p1
            for _ in range(2):
                step = rnd.randint(30, 110)
                nxt = (q[0] + (dx / 26) * step, q[1] + (dy / 26) * step) if _ == 0 else (q[0] + rnd.choice([-1, 1]) * step * abs(dy) / 26, q[1] + rnd.choice([-1, 1]) * step * abs(dx) / 26)
                a.line(q, nxt, CYAN, 1.2, 150)
                q = nxt
            a.dot(q, 3.5, GOLD if rnd.random() < 0.25 else CYAN, 255)
    for r, ph in ((250, 0), (290, 0.5)):
        a.circle((cx, cy), r, GOLD, 1, 90)
        for k in range(10):
            ang = ph + k * math.pi * 2 / 10
            a.dot((cx + r * math.cos(ang), cy + r * math.sin(ang)), 4, GOLD, 255)
    return a


def medtech():
    a = Art()
    x0, x1, amp = 680, 1330, 95
    pts1, pts2 = [], []
    for i in range(0, 261):
        x = x0 + (x1 - x0) * i / 260
        t = i / 260 * math.pi * 5
        pts1.append((x, CY + amp * math.sin(t), math.cos(t)))
        pts2.append((x, CY - amp * math.sin(t), -math.cos(t)))
    for i in range(0, 261, 7):
        p, q = pts1[i], pts2[i]
        z = (p[2] + 1) / 2
        a.line(p[:2], q[:2], GREEN, 2, 60 + 150 * z)
        a.dot(p[:2], 3 + 3 * z, CYAN, 200)
        a.dot(q[:2], 3 + 3 * (1 - z), CYAN, 200)
    for pts, col in ((pts1, CYAN), (pts2, BLUE)):
        for p, q in zip(pts, pts[1:]):
            z = (p[2] + 1) / 2
            a.line(p[:2], q[:2], col, 1.5 + 3.5 * z, 90 + 160 * z)
    # molecule hexagons
    def hexa(c, r):
        pts = [(c[0] + r * math.cos(math.pi / 3 * k), c[1] + r * math.sin(math.pi / 3 * k)) for k in range(6)]
        a.poly(pts, GOLD, 1.5, 170)
        for p in pts:
            a.dot(p, 3.5, GOLD, 230)
    hexa((1240, 150), 34)
    hexa((1305, 188), 34)
    hexa((900, 520), 30)
    # ECG trace
    ecg = []
    for i in range(0, 700):
        x = 640 + i
        ph = (i % 140)
        y = 560
        if 50 < ph < 56: y -= (ph - 50) * 4
        elif 56 <= ph < 62: y -= 24 - (ph - 56) * 9
        elif 62 <= ph < 68: y += (ph - 62) * 4 - 30
        ecg.append((x, y))
    for p, q in zip(ecg, ecg[1:]):
        a.line(p, q, GREEN, 1.6, 140)
    return a


def climate():
    a = Art()
    r = 34
    cols, rows = 9, 6
    for row in range(rows):
        for col in range(cols):
            cx = 720 + col * r * 1.75 + (r * 0.875 if row % 2 else 0)
            cy = 180 + row * r * 1.52
            charge = (col / (cols - 1) + row * 0.05) % 1.0
            colr = tuple(int(GREEN[i] * (1 - charge) + GOLD[i] * charge) for i in range(3))
            pts = [(cx + r * 0.95 * math.cos(math.pi / 3 * k + math.pi / 6), cy + r * 0.95 * math.sin(math.pi / 3 * k + math.pi / 6)) for k in range(6)]
            a.poly(pts, colr, 1.6, 200, fill=colr + (int(30 + 60 * charge),))
            a.circle((cx, cy), 7 + 6 * charge, colr, 1.4, 220, fill=colr + (90,))
    # power-line curves with pulses
    for k, base_y in enumerate((120, 540, 585)):
        prev = None
        for i in range(0, 221):
            x = 640 + i * 4
            y = base_y + 24 * math.sin(i / 14 + k)
            if prev:
                a.line(prev, (x, y), CYAN, 1.4, 130)
            prev = (x, y)
        for j in range(3):
            x = 700 + j * 260 + k * 40
            a.dot((x, base_y + 24 * math.sin((x - 640) / 4 / 14 + k)), 5, GOLD, 255)
    return a


def enterprise():
    a = Art()
    cx, cy = CX, CY
    shield = [(cx, cy - 230), (cx + 170, cy - 170), (cx + 170, cy + 20), (cx, cy + 230), (cx - 170, cy + 20), (cx - 170, cy - 170)]
    a.poly(shield, BLUE, 2, 160, fill=(20, 80, 120, 40))
    a.poly([(cx + 0.82 * (x - cx), cy + 0.82 * (y - cy)) for x, y in shield], CYAN, 1.2, 110)
    a.rect(cx - 70, cy - 20, cx + 70, cy + 100, GOLD, 3, 250, fill=(236, 180, 57, 40), radius=14)
    a.arc((cx, cy - 20), 46, 180, 360, GOLD, 5, 250)
    a.line((cx - 46, cy - 20), (cx - 46, cy - 5), GOLD, 5, 250)
    a.line((cx + 46, cy - 20), (cx + 46, cy - 5), GOLD, 5, 250)
    a.circle((cx, cy + 40), 11, GOLD, 3, 255, fill=(236, 180, 57, 120))
    a.line((cx, cy + 50), (cx, cy + 74), GOLD, 4, 255)
    rnd = random.Random(11)
    f = mono(15)
    for col in range(9):
        x = 1240 + col * 36
        for row in range(14):
            y = 60 + row * 40 + rnd.uniform(-3, 3)
            a.text((x, y), rnd.choice("01"), CYAN, 15, 40 + int(170 * (1 - row / 14) * rnd.random()), f)
    for i in range(9):
        ang = i / 9 * math.pi * 2
        p = (cx + 285 * math.cos(ang), cy + 200 * math.sin(ang))
        a.line((cx + 170 * math.cos(ang), cy + 130 * math.sin(ang)), p, CYAN, 1, 90)
        a.dot(p, 4.5, CYAN, 230)
    return a


# ---------------------------------------------------------------- services
def patentability():
    a, rnd = Art(), random.Random(2)
    for k in range(5):
        ox, oy = 700 + k * 40, 150 + k * 28
        a.poly([(ox, oy), (ox + 260, oy - 20), (ox + 285, oy + 300), (ox + 25, oy + 320)], BLUE, 1.5, 120, fill=(15, 40, 60, 90))
        for l in range(9):
            t = 40 + l * 28
            a.line((ox + 30 + l * 1.5, oy + t - 10 + 0), (ox + 220 + l * 2, oy + t - 14), CYAN, 1, 60)
    lc, lr = (1080, 330), 150
    a.circle(lc, lr, CYAN, 5, 250, fill=(10, 30, 45, 120))
    a.circle(lc, lr - 12, BLUE, 1.5, 150)
    a.line((lc[0] + lr * 0.72, lc[1] + lr * 0.72), (lc[0] + lr * 1.5, lc[1] + lr * 1.5), CYAN, 14, 240)
    for l in range(8):
        y = lc[1] - 100 + l * 28
        wdt = rnd.randint(120, 220)
        col = GOLD if l in (2, 5) else CYAN
        a.line((lc[0] - wdt / 2, y), (lc[0] + wdt / 2, y), col, 4 if col == GOLD else 2, 240 if col == GOLD else 140)
    return a


def provisional():
    a = Art()
    cx, cy = CX, CY
    a.poly([(cx - 110, cy - 150), (cx + 60, cy - 150), (cx + 110, cy - 100), (cx + 110, cy + 160), (cx - 110, cy + 160)], CYAN, 2.4, 230, fill=(15, 45, 70, 120))
    a.poly([(cx + 60, cy - 150), (cx + 60, cy - 100), (cx + 110, cy - 100)], CYAN, 1.5, 200)
    for l in range(9):
        y = cy - 70 + l * 26
        a.line((cx - 80, y), (cx + 80 - (l % 3) * 30, y), CYAN, 1.6, 110)
    a.line((cx - 80, cy - 100), (cx + 20, cy - 100), GOLD, 4, 240)
    ring_r = 235
    for m in range(12):
        ang = -math.pi / 2 + m * math.pi / 6
        p0 = (cx + (ring_r - 12) * math.cos(ang), cy + (ring_r - 12) * math.sin(ang))
        p1 = (cx + (ring_r + 12) * math.cos(ang), cy + (ring_r + 12) * math.sin(ang))
        a.line(p0, p1, GOLD if m == 0 else CYAN, 4 if m == 0 else 2, 255 if m == 0 else 150)
        a.text((cx + (ring_r + 34) * math.cos(ang), cy + (ring_r + 34) * math.sin(ang)), str(m + 1) if m else "0", GOLD if m == 0 else CYAN, 14, 200 if m else 255)
    a.circle((cx, cy), ring_r, CYAN, 1.2, 90)
    a.arc((cx, cy), ring_r, -90, 270 - 30, GOLD, 3, 200)
    a.circle((cx + 70, cy + 110), 44, GOLD, 4, 240, fill=(236, 180, 57, 40))
    a.circle((cx + 70, cy + 110), 34, GOLD, 1.5, 200)
    a.line((cx + 52, cy + 110), (cx + 66, cy + 124), GOLD, 5, 255)
    a.line((cx + 66, cy + 124), (cx + 92, cy + 92), GOLD, 5, 255)
    return a


def prosecution():
    a = Art()
    nodes = {"root": (CX, 120)}
    kids = [(780, 270), (CX, 270), (1220, 270)]
    grand = [(690, 450), (870, 450), (CX - 80, 450), (CX + 80, 450), (1130, 450), (1310, 450)]
    for k in kids:
        a.line(nodes["root"], k, CYAN, 1.8, 170)
    for i, g in enumerate(grand):
        a.line(kids[i // 2], g, CYAN, 1.4, 130)
    def node(c, label, col=CYAN, big=False):
        w, h = (88, 46) if big else (64, 38)
        a.rect(c[0] - w / 2, c[1] - h / 2, c[0] + w / 2, c[1] + h / 2, col, 2.2, 240, fill=(12, 36, 56, 190), radius=8)
        a.text(c, label, col, 17 if big else 14, 255)
    node(nodes["root"], "1", GOLD, True)
    for i, k in enumerate(kids):
        node(k, str(i + 2))
    for i, g in enumerate(grand):
        node(g, str(i + 5), GREEN if i == 2 else CYAN)
    a.line((grand[2][0] - 14, grand[2][1] + 40), (grand[2][0] - 4, grand[2][1] + 52), GREEN, 4, 255)
    a.line((grand[2][0] - 4, grand[2][1] + 52), (grand[2][0] + 18, grand[2][1] + 28), GREEN, 4, 255)
    a.rect(660, 530, 1340, 585, BLUE, 1.5, 150, radius=8)
    for i, t in enumerate(("FILED", "EXAMINATION", "RESPONSE", "ALLOWANCE")):
        a.text((740 + i * 175, 558), t, GOLD if i == 2 else CYAN, 14, 230)
    return a


def strategy():
    a, rnd = Art(), random.Random(9)
    cx, cy, R = CX - 40, CY, 210
    for k in range(1, 6):
        a.poly([(cx + R * k / 5 * math.cos(-math.pi / 2 + i * math.tau / 5), cy + R * k / 5 * math.sin(-math.pi / 2 + i * math.tau / 5)) for i in range(5)], BLUE, 1, 90)
    vals = [0.9, 0.62, 0.8, 0.5, 0.74]
    pts = []
    for i in range(5):
        ang = -math.pi / 2 + i * math.tau / 5
        a.line((cx, cy), (cx + R * math.cos(ang), cy + R * math.sin(ang)), BLUE, 1, 110)
        pts.append((cx + R * vals[i] * math.cos(ang), cy + R * vals[i] * math.sin(ang)))
    a.poly(pts, CYAN, 3, 250, fill=(57, 197, 240, 55))
    for p in pts:
        a.dot(p, 7, GOLD, 255)
        a.circle(p, 13, GOLD, 1.2, 150)
    for i, t in enumerate(("PATENTS", "TRADEMARKS", "DESIGNS", "TRADE SECRETS", "LICENCES")):
        ang = -math.pi / 2 + i * math.tau / 5
        a.text((cx + (R + 38) * math.cos(ang), cy + (R + 30) * math.sin(ang)), t, CYAN, 13, 210)
    for i in range(7):
        x = 1290 + i * 34
        h = 40 + i * 30 + rnd.randint(-10, 14)
        a.rect(x, 520 - h, x + 20, 520, CYAN, 1.5, 200, fill=(57, 197, 240, 40 + i * 14))
    a.line((1290, 480), (1512, 330), GOLD, 3, 220)
    a.dot((1512, 330), 6, GOLD, 255)
    return a


# ---------------------------------------------------------------- other pages
def about():
    """Metayage offices as a connected constellation (Bengaluru, Coimbatore, Hyderabad, Chennai, Redmond)."""
    a = Art()
    pts = {"Redmond": (760, 190), "Hyderabad": (1130, 300), "Bengaluru": (1060, 440), "Chennai": (1215, 450), "Coimbatore": (990, 520)}
    names = list(pts)
    for i in range(len(names)):
        for j in range(i + 1, len(names)):
            p, q = pts[names[i]], pts[names[j]]
            mid = ((p[0] + q[0]) / 2, (p[1] + q[1]) / 2 - 60 - 0.12 * abs(p[0] - q[0]))
            prev = p
            for k in range(1, 31):
                t = k / 30
                x = (1 - t) ** 2 * p[0] + 2 * (1 - t) * t * mid[0] + t * t * q[0]
                y = (1 - t) ** 2 * p[1] + 2 * (1 - t) * t * mid[1] + t * t * q[1]
                a.line(prev, (x, y), CYAN, 1.1, 55 + 60 * math.sin(t * math.pi))
                prev = (x, y)
    for n, p in pts.items():
        a.circle(p, 20, CYAN, 1.2, 120)
        a.dot(p, 8, GOLD if n == "Redmond" else CYAN, 255)
        a.text((p[0], p[1] + 38), n.upper(), CYAN, 13, 220)
    for r in (150, 220, 290):
        a.circle((1030, 380), r, BLUE, 1, 45)
    return a


def services_overview():
    a = Art()
    hub = (CX, CY)
    spokes = [(740, 170), (1260, 170), (740, 490), (1260, 490)]
    for s in spokes:
        a.line(hub, s, CYAN, 1.6, 120)
    pts = [(hub[0] + 62 * math.cos(math.pi / 3 * k + math.pi / 6), hub[1] + 62 * math.sin(math.pi / 3 * k + math.pi / 6)) for k in range(6)]
    a.poly(pts, GOLD, 3, 250, fill=(236, 180, 57, 40))
    a.text(hub, "IP", GOLD, 34, 255)
    for i, s in enumerate(spokes):
        a.circle(s, 58, CYAN, 2.4, 230, fill=(12, 36, 56, 170))
        if i == 0:   # magnifier
            a.circle((s[0] - 4, s[1] - 4), 16, CYAN, 3, 250)
            a.line((s[0] + 8, s[1] + 8), (s[0] + 24, s[1] + 24), CYAN, 4, 250)
        elif i == 1:  # document
            a.poly([(s[0] - 18, s[1] - 24), (s[0] + 8, s[1] - 24), (s[0] + 18, s[1] - 14), (s[0] + 18, s[1] + 24), (s[0] - 18, s[1] + 24)], CYAN, 3, 250)
            for l in range(3):
                a.line((s[0] - 10, s[1] - 6 + l * 9), (s[0] + 10, s[1] - 6 + l * 9), CYAN, 2, 200)
        elif i == 2:  # shield
            a.poly([(s[0], s[1] - 26), (s[0] + 22, s[1] - 16), (s[0] + 22, s[1] + 4), (s[0], s[1] + 26), (s[0] - 22, s[1] + 4), (s[0] - 22, s[1] - 16)], CYAN, 3, 250)
        else:         # growth
            for b in range(4):
                a.rect(s[0] - 22 + b * 12, s[1] + 20 - 12 * (b + 1), s[0] - 14 + b * 12, s[1] + 20, CYAN, 2, 240, fill=(57, 197, 240, 90))
    for t, p in zip(("SEARCH", "FILE", "PROTECT", "GROW"), spokes):
        a.text((p[0], p[1] + 84), t, GOLD, 13, 230)
    return a


def faq():
    a = Art()
    def bubble(x, y, w, h, col, label=""):
        a.rect(x, y, x + w, y + h, col, 2.2, 240, fill=(12, 36, 56, 150), radius=18)
        a.poly([(x + 28, y + h), (x + 48, y + h), (x + 30, y + h + 22)], col, 2.2, 240, fill=(12, 36, 56, 150))
        if label:
            a.text((x + w / 2, y + h / 2), label, col, 40, 255)
    bubble(800, 150, 240, 120, CYAN, "?")
    bubble(1040, 250, 270, 130, GOLD, "?")
    bubble(780, 380, 200, 100, BLUE, "?")
    for k in range(3):
        a.line((830, 190 + k * 18), (1000 - k * 30, 190 + k * 18), CYAN, 1.5, 90)
    a.circle((1190, 140), 36, GOLD, 2.4, 220, fill=(236, 180, 57, 40))
    a.text((1190, 140), "A", GOLD, 28, 255)
    a.circle((1000, 520), 28, CYAN, 2, 200)
    a.text((1000, 520), "A", CYAN, 22, 255)
    return a


def insights():
    a = Art()
    for k, (ox, oy, ang) in enumerate(((760, 200, -10), (880, 170, -3), (1000, 160, 5), (1120, 190, 12))):
        c, s = math.cos(math.radians(ang)), math.sin(math.radians(ang))
        def P(x, y):
            return (ox + x * c - y * s, oy + x * s + y * c)
        a.poly([P(0, 0), P(150, 0), P(150, 240), P(0, 240)], CYAN if k != 2 else GOLD, 2.2, 230, fill=(12, 36, 56, 170))
        a.poly([P(14, 14), P(136, 14), P(136, 84), P(14, 84)], BLUE, 1.5, 180, fill=(20, 129, 196, 70))
        for l in range(6):
            a.line(P(14, 110 + l * 20), P(136 - (l % 3) * 28, 110 + l * 20), CYAN, 1.5, 120)
    a.poly([(1080, 360), (1112, 360), (1112, 420), (1096, 406), (1080, 420)], GOLD, 2, 250, fill=(236, 180, 57, 90))
    return a


def quiz():
    a = Art()
    xs = [720 + i * 110 for i in range(6)]
    for i in range(5):
        a.line((xs[i], 330), (xs[i + 1], 330), CYAN if i < 3 else BLUE, 3, 210 if i < 3 else 110)
    for i, x in enumerate(xs):
        done = i < 3
        a.circle((x, 330), 30, GOLD if i == 3 else (CYAN if done else BLUE), 3, 250, fill=(12, 36, 56, 170))
        if done:
            a.line((x - 11, 331), (x - 3, 340), CYAN, 4, 255)
            a.line((x - 3, 340), (x + 12, 320), CYAN, 4, 255)
        else:
            a.text((x, 330), str(i + 1), GOLD if i == 3 else BLUE, 22, 255)
    a.rect(800, 130, 1220, 230, CYAN, 2.2, 220, fill=(12, 36, 56, 150), radius=14)
    a.text((1010, 165), "What best describes", CYAN, 20, 240)
    a.text((1010, 197), "your invention?", CYAN, 20, 240)
    for k, lab in enumerate(("A", "B", "C")):
        a.circle((800 + 70 + k * 160, 450), 26, GOLD if k == 1 else CYAN, 2.4, 240, fill=(236, 180, 57, 60) if k == 1 else None)
        a.text((870 + k * 160, 450), lab, GOLD if k == 1 else CYAN, 20, 255)
    return a


def book():
    a = Art()
    x0, y0, cw, ch = 760, 190, 66, 54
    a.rect(x0 - 20, y0 - 70, x0 + cw * 7 + 20, y0 + ch * 5 + 24, CYAN, 2.4, 230, fill=(12, 36, 56, 150), radius=14)
    a.line((x0 - 20, y0 - 16), (x0 + cw * 7 + 20, y0 - 16), CYAN, 1.6, 150)
    a.text((x0 + 110, y0 - 44), "OCTOBER", GOLD, 18, 255)
    for i, d in enumerate("SMTWTFS"):
        a.text((x0 + i * cw + cw / 2, y0), d, CYAN, 13, 200)
    n = 0
    for r in range(5):
        for c in range(7):
            n += 1
            if n > 31:
                break
            cx, cy = x0 + c * cw + cw / 2, y0 + 34 + r * ch
            if n == 16:
                a.circle((cx, cy), 20, GOLD, 3, 255, fill=(236, 180, 57, 90))
            a.text((cx, cy), str(n), GOLD if n == 16 else CYAN, 16, 255 if n == 16 else 150)
    ck = (1310, 200)
    a.circle(ck, 70, CYAN, 3, 240, fill=(12, 36, 56, 190))
    for m in range(12):
        ang = m * math.pi / 6
        a.line((ck[0] + 58 * math.sin(ang), ck[1] - 58 * math.cos(ang)), (ck[0] + 66 * math.sin(ang), ck[1] - 66 * math.cos(ang)), CYAN, 2.2, 220)
    a.line(ck, (ck[0], ck[1] - 44), GOLD, 4, 255)
    a.line(ck, (ck[0] + 32, ck[1] + 14), GOLD, 4, 255)
    a.dot(ck, 6, GOLD, 255)
    return a


JOBS = {
    "about": about, "services": services_overview, "faq": faq, "insights": insights,
    "patentability-check": quiz, "book": book,
    "ai-machine-learning": ai, "semiconductors-quantum": semis, "medtech-biotechnology": medtech,
    "climate-energy": climate, "enterprise-software-security": enterprise,
    "patentability-prior-art": patentability, "provisional-applications": provisional,
    "us-patent-prosecution": prosecution, "ip-strategy-diligence": strategy,
}

if __name__ == "__main__":
    for i, (slug, fn) in enumerate(JOBS.items()):
        finish(base(40 + i), fn(), slug, i + 1)
