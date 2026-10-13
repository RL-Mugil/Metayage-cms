"""Renders the two sample pictures and the JSON body for the demo Insights article.

Outputs go to the directory given as argv[1] (default: the current directory):
  demo-cover.png, demo-inline.png, demo-article.json   (the JSON is completed with image URLs by the caller)
"""
import json
import os
import sys

from PIL import Image, ImageDraw, ImageFilter, ImageFont

OUT = sys.argv[1] if len(sys.argv) > 1 else "."
CYAN, BLUE, GOLD, GREEN, WHITE = (57, 197, 240), (20, 129, 196), (236, 180, 57), (48, 182, 125), (235, 246, 255)


def font(size, bold=False):
    for f in (("segoeuib.ttf",) if bold else ("segoeui.ttf",)) + ("arial.ttf",):
        try:
            return ImageFont.truetype(f, size)
        except OSError:
            pass
    return ImageFont.load_default()


def backdrop(w, h):
    im = Image.new("RGB", (w, h), (24, 28, 33))
    px = im.load()
    for y in range(h):
        for x in range(w):
            t = x / w
            px[x, y] = (int(24 + 10 * t), int(28 + 22 * t), int(33 + 40 * t))
    return im


def glow(base, draw_fn, blur=10):
    layer = Image.new("RGBA", base.size, (0, 0, 0, 0))
    draw_fn(ImageDraw.Draw(layer))
    out = base.convert("RGBA")
    for b in (blur * 2, blur, 0):
        out = Image.alpha_composite(out, layer.filter(ImageFilter.GaussianBlur(b)) if b else layer)
    return out.convert("RGB")


def cover():
    w, h = 1600, 900
    im = backdrop(w, h)

    def art(d):
        # timeline bar of twelve months, with the "first public disclosure" marker at the start
        x0, x1, y = 200, 1400, 640
        d.line([x0, y, x1, y], fill=CYAN + (230,), width=6)
        for m in range(13):
            x = x0 + (x1 - x0) * m / 12
            d.line([x, y - (26 if m in (0, 12) else 14), x, y + (26 if m in (0, 12) else 14)], fill=(CYAN if m not in (0, 12) else GOLD) + (255,), width=4 if m in (0, 12) else 3)
        d.ellipse([x0 - 26, y - 26, x0 + 26, y + 26], fill=GOLD + (255,))
        d.ellipse([x1 - 26, y - 26, x1 + 26, y + 26], outline=GREEN + (255,), width=6)
        d.rounded_rectangle([x0, y - 14, x1, y + 14], radius=14, outline=BLUE + (150,), width=3)
        # speaker / document icons above the start
        d.rounded_rectangle([x0 - 60, y - 220, x0 + 60, y - 90], radius=14, outline=GOLD + (255,), width=6)
        for i in range(4):
            d.line([x0 - 36, y - 190 + i * 24, x0 + 36 - (i % 2) * 20, y - 190 + i * 24], fill=GOLD + (220,), width=4)
        d.line([x0, y - 90, x0, y - 26], fill=GOLD + (200,), width=3)
        d.rounded_rectangle([x1 - 90, y - 220, x1 + 90, y - 90], radius=14, outline=GREEN + (255,), width=6)
        d.line([x1 - 40, y - 150, x1 - 10, y - 120], fill=GREEN + (255,), width=10)
        d.line([x1 - 10, y - 120, x1 + 50, y - 190], fill=GREEN + (255,), width=10)
        d.line([x1, y - 90, x1, y - 26], fill=GREEN + (200,), width=3)

    im = glow(im, art, 12)
    d = ImageDraw.Draw(im)
    d.text((200, 110), "FILE BEFORE YOU PUBLISH", font=font(64, True), fill=WHITE)
    d.text((200, 200), "Plan the date, then go public", font=font(40), fill=CYAN)
    d.text((200, 745), "PUBLIC DISCLOSURE", font=font(26, True), fill=GOLD)
    d.text((1400, 745), "FILING WINDOW", font=font(26, True), fill=GREEN, anchor="ra")
    return im


def inline():
    w, h = 1200, 640
    im = backdrop(w, h)

    def art(d):
        for row, (y, label, col, reach) in enumerate(((220, "UNITED STATES", GREEN, 0.9), (420, "MANY OTHER COUNTRIES", GOLD, 0.0))):
            d.rounded_rectangle([140, y - 28, 1060, y + 28], radius=28, outline=BLUE + (200,), width=3)
            d.ellipse([140 - 16, y - 16, 140 + 16, y + 16], fill=CYAN + (255,))
            if reach:
                d.rounded_rectangle([140, y - 22, 140 + 920 * reach, y + 22], radius=22, fill=col + (120,))
                d.ellipse([140 + 920 * reach - 14, y - 14, 140 + 920 * reach + 14, y + 14], fill=col + (255,))
            else:
                d.line([140, y - 34, 140, y + 34], fill=col + (255,), width=6)
                d.line([120, y - 20, 160, y + 20], fill=col + (255,), width=6)
                d.line([120, y + 20, 160, y - 20], fill=col + (255,), width=6)

    im = glow(im, art, 9)
    d = ImageDraw.Draw(im)
    d.text((140, 120), "UNITED STATES", font=font(30, True), fill=GREEN)
    d.text((140, 150), "Generally a one-year window after your own disclosure", font=font(24), fill=WHITE)
    d.text((140, 320), "MANY OTHER COUNTRIES", font=font(30, True), fill=GOLD)
    d.text((140, 350), "Often no window: disclosure can end rights at once", font=font(24), fill=WHITE)
    d.text((140, 530), "General illustration only. Rules vary by country and by facts.", font=font(20), fill=(170, 190, 205))
    return im


COVER, INLINE = "demo-cover.png", "demo-inline.png"
cover().save(os.path.join(OUT, COVER))
inline().save(os.path.join(OUT, INLINE))

body = """<p>Founders often tell the story of a new technology before anyone has thought about protecting it: a conference talk, a demo, a paper, an open-source release or a launch post. Each of these can affect patent rights, and the effect can be permanent. A short conversation before you go public is usually the cheapest protection there is.</p>
<h2>What counts as public?</h2>
<ul>
<li>A talk, poster or paper, including preprints</li>
<li>A product demo to an audience not bound by confidentiality</li>
<li>An open-source release or a public code repository</li>
<li>Website, blog or social posts that explain how the technology works</li>
<li>Pitch decks shared without a confidentiality agreement</li>
<li>Offers to sell, and first sales, of a product that uses the invention</li>
</ul>
<h2>The US grace period, and why not to lean on it</h2>
<p>In the United States there is generally a one-year window after your own public disclosure in which you can still file a patent application. Many other countries have no equivalent: if the invention is public before the filing date, it may no longer count as new. That means a disclosure that is recoverable in the US can still cost you rights abroad.</p>
<p>The details depend on the facts and on each country’s rules, which is exactly why timing is worth a conversation before the event rather than after it.</p>
<figure><img src="__INLINE__" alt="Two timelines comparing a one-year disclosure window in the United States with no window in many other countries"><figcaption>A simplified picture of the difference. Real rules vary by country and circumstance.</figcaption></figure>
<h2>A practical checklist</h2>
<ol>
<li>Write down what is new about your approach, in plain words.</li>
<li>Before any talk, paper, demo or launch, list what it will reveal.</li>
<li>Talk to a patent professional while there is still time to choose a filing date.</li>
<li>Consider a provisional application to secure an early date on the core of the invention.</li>
<li>Use confidentiality agreements when sharing details with partners and investors.</li>
<li>Keep a dated record of every disclosure, however informal.</li>
</ol>
<p>If you are planning a launch or a paper, the self-check on this site is a good first step, and a consultation can help you choose a sensible filing date.</p>"""

with open(os.path.join(OUT, "demo-article.json"), "w", encoding="utf-8") as f:
    json.dump({
        "title": "File before you publish: how early disclosure can cost you patent rights",
        "excerpt": "Talks, demos, papers and launches can affect patent rights, and the effect can be permanent. A short guide for founders on what counts as public and what to do first.",
        "cover_alt": "A twelve-month timeline from a first public disclosure to the end of a filing window",
        "author": "CanPatentIt",
        "meta_description": "Why talks, demos, papers and launches can affect patent rights, how the US grace period differs from other countries, and a practical checklist for founders.",
        "body_html": body,
    }, f, ensure_ascii=False)
print("wrote demo assets to", OUT)
