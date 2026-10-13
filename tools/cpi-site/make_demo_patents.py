"""Renders generic patent-drawing style figures and the JSON for demo showcase entries.

Usage: python make_demo_patents.py <output-dir>
Writes demo-p1.png ... demo-p5.png and demo-patents.json. Entries are clearly labelled SAMPLE so they
can be deleted from the admin panel; the one real entry (WO/2020/136668) has no figure until uploaded.
"""
import json
import os
import random
import sys

from PIL import Image, ImageDraw, ImageFont

OUT = sys.argv[1] if len(sys.argv) > 1 else "."
S = 2
W, H = 900, 675


def font(size):
    for f in ("arial.ttf", "segoeui.ttf"):
        try:
            return ImageFont.truetype(f, size * S)
        except OSError:
            pass
    return ImageFont.load_default()


def figure(seed, labels, fig_no):
    rnd = random.Random(seed)
    im = Image.new("RGB", (W * S, H * S), (255, 255, 255))
    d = ImageDraw.Draw(im)
    f = font(15)
    ink = (25, 25, 25)

    def box(x, y, w, h, text, num):
        d.rectangle([x * S, y * S, (x + w) * S, (y + h) * S], outline=ink, width=2 * S, fill=(246, 246, 246))
        d.multiline_text(((x + w / 2) * S, (y + h / 2 - 6) * S), text, font=f, fill=ink, anchor="mm", align="center")
        d.text(((x + w - 4) * S, (y + h + 14) * S), str(num), font=f, fill=ink, anchor="rm")

    def arrow(p, q):
        d.line([p[0] * S, p[1] * S, q[0] * S, q[1] * S], fill=ink, width=2 * S)
        dx, dy = q[0] - p[0], q[1] - p[1]
        n = max((dx * dx + dy * dy) ** 0.5, 1)
        ux, uy = dx / n, dy / n
        d.polygon([(q[0] * S, q[1] * S), ((q[0] - 12 * ux + 6 * uy) * S, (q[1] - 12 * uy - 6 * ux) * S), ((q[0] - 12 * ux - 6 * uy) * S, (q[1] - 12 * uy + 6 * ux) * S)], fill=ink)

    # one central block with four around it, patent-drawing style
    cx, cy = 450, 330
    box(cx - 90, cy - 38, 180, 76, "CENTRAL\nMODULE", 100)
    spots = [(70, 70), (650, 70), (70, 480), (650, 480)]
    rnd.shuffle(labels)
    for i, (x, y) in enumerate(spots):
        t = labels[i % len(labels)]
        box(x, y, 180, 76, t, 102 + 2 * i)
        tx = x + 90 if x < cx else x + 90
        ty = y + 76 if y < cy else y
        arrow((cx + (-60 if x < cx else 60), cy + (-38 if y < cy else 38)), (tx, ty))
    box(cx - 90, 600 - 76, 180, 60, labels[-1], 110)
    arrow((cx, cy + 38), (cx, 600 - 76))
    d.text((W * S // 2, (H - 18) * S), f"FIG. {fig_no}", font=font(20), fill=ink, anchor="mm")
    return im.resize((W, H), Image.LANCZOS)


SAMPLES = [
    ("SAMPLE-0001", "Sample entry: method for compressing neural network inference", "Artificial Intelligence", "USA", ["MODEL", "QUANTISER", "RUNTIME", "SCHEDULER", "MEMORY"]),
    ("SAMPLE-0002", "Sample entry: secure key rotation in a distributed system", "Cryptography", "USA", ["KEY STORE", "ROTATION\nSERVICE", "CLIENT", "AUDIT LOG", "POLICY"]),
    ("SAMPLE-0003", "Sample entry: battery management for an electric vehicle", "Electric Vehicle", "India", ["CELL\nMONITOR", "THERMAL\nCONTROL", "CHARGER", "CONTROLLER", "DISPLAY"]),
    ("SAMPLE-0004", "Sample entry: portable imaging device for diagnostics", "Healthcare", "Others", ["SENSOR", "PROCESSOR", "CLASSIFIER", "ALERT\nGENERATOR", "BUFFER"]),
    ("SAMPLE-0005", "Sample entry: centralised configuration of network devices", "Network Management", "USA", ["DEVICE\nREGISTRY", "CONFIG\nENGINE", "MONITOR", "DATABASE", "INTERFACE"]),
]
SAMPLE_ABSTRACT = (
    "This is a sample entry that shows how a patent appears in the showcase. It is not a real patent.\n\n"
    "Replace it with a real patent from the admin panel (Patent showcase tab), or delete it."
)

entries = []
for i, (pid, title, domain, country, labels) in enumerate(SAMPLES, start=1):
    figure(i, list(labels), i).save(os.path.join(OUT, f"demo-p{i}.png"))
    entries.append({"patent_number": pid, "title": title, "domain": domain, "country": country, "abstract": SAMPLE_ABSTRACT,
                    "image_alt": f"Generic block diagram, FIG. {i}", "image_file": f"demo-p{i}.png"})

# The reference entry, written from the abstract published on metayage.com (Metayage's own showcase).
entries.insert(0, {
    "patent_number": "WO/2020/136668",
    "title": "System and method for generating a modified design creative",
    "domain": "Artificial Intelligence", "country": "India",
    "abstract": ("A system for recognizing one or more objects of a design creative within an environment. A deep neural network model analyzes the objects, "
                 "locates the design creative within media content, and identifies an object in the creative together with its attributes.\n\n"
                 "A compliance rule is applied to judge the distinctness and effectiveness of a brand product. An attention sequence and a heatmap are generated "
                 "for the media content, and a first recommendation is produced from the compliance rule, the heatmap and the attention sequence.\n\n"
                 "Using the same model, a modified design creative is then generated automatically for the environment."),
    "image_alt": None, "image_file": None,
})

with open(os.path.join(OUT, "demo-patents.json"), "w", encoding="utf-8") as f:
    json.dump(entries, f, ensure_ascii=False)
print("wrote", len(entries), "entries to", OUT)
