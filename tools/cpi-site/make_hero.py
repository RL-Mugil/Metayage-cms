"""Procedurally renders an original semiconductor-wafer hero image (no stock photo, no licensing issues).

Run:  python tools/cpi-site/make_hero.py   ->  marketing-canpatentit/assets/hero.jpg
"""
import os

import numpy as np
from PIL import Image, ImageFilter

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, "marketing-canpatentit", "assets", "hero.jpg")
W, H = 1920, 1080
rng = np.random.default_rng(7)


def smoothstep(a, b, x):
    t = np.clip((x - a) / (b - a), 0, 1)
    return t * t * (3 - 2 * t)


def hsv_to_rgb(h, s, v):
    h = np.mod(h, 1.0) * 6
    i = np.floor(h).astype(int) % 6
    f = h - np.floor(h)
    p, q, t = v * (1 - s), v * (1 - s * f), v * (1 - s * (1 - f))
    r = np.choose(i, [v, q, p, p, t, v])
    g = np.choose(i, [t, v, v, q, p, p])
    b = np.choose(i, [p, p, t, v, v, q])
    return np.stack([r, g, b], axis=-1)


yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)

# ---------- Background: dark cleanroom gradient + soft machinery ----------
bg = np.zeros((H, W, 3), np.float32)
base = 0.045 + 0.05 * (1 - yy / H)
bg[..., 0] = base * 0.85
bg[..., 1] = base * 0.98
bg[..., 2] = base * 1.15

# vertical light columns (tool frame) and horizontal rails
for cx, wd, amp in [(900, 80, 0.05), (1080, 40, 0.04), (1750, 120, 0.05), (520, 140, 0.03)]:
    bg += (amp * np.exp(-((xx - cx) / wd) ** 2))[..., None] * np.array([0.5, 0.8, 1.0], np.float32)
for cy, wd, amp in [(180, 10, 0.06), (940, 14, 0.05)]:
    bg += (amp * np.exp(-((yy - cy) / wd) ** 2))[..., None] * np.array([0.6, 0.85, 1.0], np.float32)

# lens column above the wafer (metal cylinder with vertical highlight)
col_cx, col_w = 1420, 170
cyl = np.clip(1 - np.abs((xx - col_cx) / col_w), 0, 1)
col_mask = (np.abs(xx - col_cx) < col_w) & (yy < 330)
shade = (0.10 + 0.55 * cyl ** 3 + 0.12 * np.sin(yy / 14.0) ** 2)[..., None] * np.array([0.78, 0.86, 0.95], np.float32)
bg = np.where(col_mask[..., None], shade * (0.5 + 0.5 * smoothstep(0, 60, yy)[..., None]), bg)
ring = (np.abs(yy - 318) < 7) & (np.abs(xx - col_cx) < col_w + 20)
bg = np.where(ring[..., None], np.array([0.75, 0.9, 1.0], np.float32) * 0.8, bg)

# bokeh lights
bok = Image.new("RGB", (W, H))
bk = np.zeros((H, W, 3), np.float32)
for _ in range(34):
    cx, cy, r = rng.uniform(0, W), rng.uniform(0, H * 0.55), rng.uniform(14, 46)
    col = rng.choice([0, 1, 2], p=[0.55, 0.3, 0.15])
    c = [np.array([0.2, 0.7, 1.0]), np.array([0.2, 0.5, 0.9]), np.array([1.0, 0.75, 0.3])][col]
    d = np.sqrt((xx - cx) ** 2 + (yy - cy) ** 2)
    bk += (0.22 * smoothstep(r, r * 0.85, d) * rng.uniform(0.3, 1))[..., None] * c.astype(np.float32)
bg += np.asarray(Image.fromarray(np.clip(bk * 255, 0, 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(5)), np.float32) / 255

# ---------- Wafer ----------
cx0, cy0 = 1390.0, 600.0
R = 395.0
tilt = 0.58          # vertical squash (viewing angle)
rot = np.deg2rad(-9)  # in-plane rotation of the ellipse
dx, dy = xx - cx0, yy - cy0
# undo rotation and squash -> unit-disk wafer coordinates
ur = (dx * np.cos(rot) + dy * np.sin(rot)) / R
vr = (-dx * np.sin(rot) + dy * np.cos(rot)) / (R * tilt)
rad = np.sqrt(ur ** 2 + vr ** 2)
cover = smoothstep(1.0 + 0.004, 1.0 - 0.004, rad)

# stage / chuck: darker metallic ring and soft shadow around the wafer
chuck_rad = rad / 1.16
chuck = smoothstep(1.0, 0.985, chuck_rad)
chuck_col = (0.05 + 0.10 * (1 - chuck_rad) + 0.05 * np.sin(chuck_rad * 70)) ** 1
bg = bg * (1 - 0.8 * smoothstep(1.5, 1.0, chuck_rad)[..., None] * 0.6)
bg = np.where((chuck > 0)[..., None], np.stack([chuck_col * 0.8, chuck_col * 0.92, chuck_col * 1.05], -1), bg)

# wafer thickness: a lower copy of the ellipse, darker
dy2 = dy - 20
ur2 = (dx * np.cos(rot) + dy2 * np.sin(rot)) / R
vr2 = (-dx * np.sin(rot) + dy2 * np.cos(rot)) / (R * tilt)
side = smoothstep(1.003, 0.997, np.sqrt(ur2 ** 2 + vr2 ** 2))
bg = np.where((side > 0)[..., None], bg * (1 - side[..., None]) + side[..., None] * np.array([0.12, 0.14, 0.18], np.float32), bg)

# dies
die_w, die_h = 0.055, 0.07
gx, gy = (ur + 0.0) / die_w, (vr + 0.0) / die_h
ix, iy = np.floor(gx), np.floor(gy)
fx, fy = gx - ix, gy - iy
scribe = smoothstep(0.0, 0.07, np.minimum(fx, 1 - fx)) * smoothstep(0.0, 0.07, np.minimum(fy, 1 - fy))
# per-die random variation
seed = (ix * 73856093 + iy * 19349663).astype(np.int64)
rand = ((seed * 2654435761) % 1000) / 1000.0
# iridescence: diffraction-like hue sweep across the wafer + per-die jitter
hue = 0.60 + 0.34 * np.sin(ur * 2.2 + 0.8) * 0.5 + 0.28 * vr + 0.06 * rand
hue = np.mod(hue, 1.0)
sat = 0.30 + 0.30 * smoothstep(0.2, 0.9, np.abs(ur + 0.3 * vr))
val = 0.28 + 0.55 * smoothstep(-0.9, 0.7, -ur * 0.7 - vr * 0.4) + 0.14 * rand
iri = hsv_to_rgb(hue, np.clip(sat, 0, 1), np.clip(val, 0, 1))
silver = np.stack([val * 0.78, val * 0.88, val * 1.0], -1)
iri = 0.62 * iri + 0.38 * silver
# fine structure inside each die (gratings)
inner = 0.8 + 0.2 * np.sin(fx * 60) * np.sin(fy * 80)
wafer = iri * (scribe * inner)[..., None] + np.array([0.04, 0.05, 0.07], np.float32) * (1 - scribe)[..., None]
# broad specular sheen from the upper-left and an edge rim light
sheen = np.exp(-(((ur + 0.55) / 0.55) ** 2 + ((vr + 0.45) / 0.35) ** 2))
wafer += (0.45 * sheen)[..., None] * np.array([0.8, 0.95, 1.0], np.float32)
rim = smoothstep(0.93, 1.0, rad) * (0.5 + 0.5 * np.clip(-ur - vr * 0.6, 0, 1))
wafer += (0.7 * rim)[..., None] * np.array([0.8, 0.95, 1.0], np.float32)
# wafer flat/notch at the top-left
notch = np.exp(-(((ur + 0.45) ** 2 + (vr + 0.89) ** 2) / 0.0012))
wafer *= (1 - 0.9 * notch)[..., None]
bg = bg * (1 - cover[..., None]) + np.clip(wafer * 1.3, 0, 1) * cover[..., None]

# reflected glow on the chuck below the wafer
glow = np.exp(-(((xx - cx0) / 650) ** 2 + ((yy - (cy0 + 340)) / 120) ** 2))
bg += (0.10 * glow)[..., None] * np.array([0.3, 0.6, 1.0], np.float32)

# ---------- Post ----------
img = np.clip(bg, 0, 1)
vig = 1 - 0.55 * smoothstep(0.55, 1.25, np.sqrt(((xx - W / 2) / (W / 2)) ** 2 + ((yy - H / 2) / (H / 2)) ** 2))
img *= vig[..., None]
img += rng.normal(0, 0.012, img.shape).astype(np.float32)  # film grain
im = Image.fromarray((np.clip(img, 0, 1) * 255).astype(np.uint8))
im = im.filter(ImageFilter.GaussianBlur(0.6))
os.makedirs(os.path.dirname(OUT), exist_ok=True)
im.save(OUT, quality=80, optimize=True, progressive=True)
print("wrote", OUT, os.path.getsize(OUT) // 1024, "KB")
