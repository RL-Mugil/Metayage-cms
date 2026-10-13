"""Renders three more sample Insights articles (cover pictures + JSON) so pagination can be seen.

Usage: python make_demo_articles.py <output-dir>
Writes demo-a-cover.png / demo-a.json, demo-b-..., demo-c-...  (the caller uploads covers and fills cover_image).
"""
import json
import os
import sys

from PIL import Image, ImageDraw, ImageFilter, ImageFont

OUT = sys.argv[1] if len(sys.argv) > 1 else "."
CYAN, BLUE, GOLD, GREEN, WHITE = (57, 197, 240), (20, 129, 196), (236, 180, 57), (48, 182, 125), (235, 246, 255)
W, H = 1600, 900


def font(size, bold=False):
    for f in (("segoeuib.ttf",) if bold else ("segoeui.ttf",)) + ("arial.ttf",):
        try:
            return ImageFont.truetype(f, size)
        except OSError:
            pass
    return ImageFont.load_default()


def backdrop(shift):
    im = Image.new("RGB", (W, H))
    px = im.load()
    for y in range(H):
        for x in range(W):
            t = abs(((x / W + shift) % 1) * 2 - 1)  # triangle wave: no visible seam
            px[x, y] = (int(24 + 8 * t), int(28 + 24 * t), int(33 + 42 * t))
    return im


def glow(base, fn, blur=12):
    layer = Image.new("RGBA", base.size, (0, 0, 0, 0))
    fn(ImageDraw.Draw(layer))
    out = base.convert("RGBA")
    for b in (blur * 2, blur, 0):
        out = Image.alpha_composite(out, layer.filter(ImageFilter.GaussianBlur(b)) if b else layer)
    return out.convert("RGB")


def doc(d, x, y, w, h, col, lines=5):
    d.rounded_rectangle([x, y, x + w, y + h], radius=14, outline=col + (255,), width=6, fill=(15, 40, 60, 140))
    for i in range(lines):
        d.line([x + 24, y + 40 + i * 26, x + w - 24 - (i % 3) * 22, y + 40 + i * 26], fill=col + (200,), width=4)


def cover_a():  # provisional -> complete
    def art(d):
        doc(d, 230, 430, 260, 320, GOLD)
        doc(d, 1090, 400, 300, 370, GREEN, 6)
        d.line([520, 590, 1060, 590], fill=CYAN + (255,), width=8)
        d.polygon([(1060, 560), (1110, 590), (1060, 620)], fill=CYAN + (255,))
        d.ellipse([700, 520, 880, 700], outline=GOLD + (255,), width=6)
        for m in range(12):
            import math
            a = m * math.pi / 6
            d.line([790 + 74 * math.sin(a), 610 - 74 * math.cos(a), 790 + 86 * math.sin(a), 610 - 86 * math.cos(a)], fill=GOLD + (255,), width=4)
    im = glow(backdrop(0.1), art)
    d = ImageDraw.Draw(im)
    d.text((200, 130), "PROVISIONAL OR COMPLETE?", font=font(64, True), fill=WHITE)
    d.text((200, 220), "Choosing your first US filing", font=font(40), fill=CYAN)
    d.text((355, 790), "PROVISIONAL", font=font(26, True), fill=GOLD, anchor="ma")
    d.text((1240, 810), "COMPLETE", font=font(26, True), fill=GREEN, anchor="ma")
    d.text((790, 730), "12 MONTHS", font=font(24, True), fill=CYAN, anchor="ma")
    return im


def cover_b():  # freedom to operate
    def art(d):
        d.ellipse([450, 310, 830, 690], outline=GOLD + (255,), width=7)
        d.ellipse([690, 310, 1070, 690], outline=CYAN + (255,), width=7)
        d.ellipse([930, 310, 1310, 690], outline=GREEN + (255,), width=7)
        d.rounded_rectangle([710, 440, 1050, 590], radius=18, fill=(236, 180, 57, 45), outline=WHITE + (230,), width=5)
        d.line([765, 500, 995, 500], fill=WHITE + (200,), width=5)
        d.line([765, 540, 925, 540], fill=WHITE + (140,), width=5)
    im = glow(backdrop(0.35), art)
    d = ImageDraw.Draw(im)
    d.text((200, 130), "FREEDOM TO OPERATE", font=font(64, True), fill=WHITE)
    d.text((200, 220), "What a search can and cannot tell you", font=font(40), fill=CYAN)
    d.text((640, 735), "EXISTING PATENTS", font=font(24, True), fill=GOLD, anchor="ma")
    d.text((890, 735), "YOUR PRODUCT", font=font(24, True), fill=CYAN, anchor="ma")
    d.text((1140, 735), "TARGET MARKETS", font=font(24, True), fill=GREEN, anchor="ma")
    return im


def cover_c():  # seed round readiness
    def art(d):
        for i, (label, ok) in enumerate((("Filings", True), ("Ownership", True), ("Disclosures", True), ("Open source", False), ("Trademarks", False))):
            y = 400 + i * 86
            d.rounded_rectangle([220, y, 860, y + 62], radius=12, outline=(CYAN if ok else BLUE) + (230,), width=4, fill=(15, 40, 60, 130))
            d.ellipse([240, y + 11, 280, y + 51], outline=(GREEN if ok else GOLD) + (255,), width=5)
            if ok:
                d.line([248, y + 32, 257, y + 42], fill=GREEN + (255,), width=6)
                d.line([257, y + 42, 274, y + 20], fill=GREEN + (255,), width=6)
        for i in range(6):
            h = 80 + i * 55
            d.rounded_rectangle([1040 + i * 80, 800 - h, 1100 + i * 80, 800], radius=8, outline=CYAN + (255,), width=4, fill=(57, 197, 240, 40 + i * 14))
        d.line([1040, 700, 1460, 440], fill=GOLD + (255,), width=6)
        d.ellipse([1446, 426, 1474, 454], fill=GOLD + (255,))
    im = glow(backdrop(0.6), art)
    d = ImageDraw.Draw(im)
    d.text((200, 130), "READY FOR THE SEED ROUND", font=font(64, True), fill=WHITE)
    d.text((200, 220), "Get your IP in order before diligence", font=font(40), fill=CYAN)
    for i, label in enumerate(("Filings", "Ownership", "Disclosures", "Open source", "Trademarks")):
        d.text((310, 431 + i * 86), label, font=font(26), fill=WHITE, anchor="lm")
    return im


ARTICLES = {
    "a": (cover_a, {
        "title": "Provisional or complete? Choosing your first US filing",
        "excerpt": "Both start the clock, but they do different jobs. A plain-language comparison of provisional and complete applications, and how to pick the right first step.",
        "cover_alt": "A provisional application and a complete application joined by a twelve-month ring",
        "meta_description": "What a US provisional application does and does not do, when a complete application makes sense, and a common path from one to the other.",
        "body_html": """<p>Founders often ask which kind of US application to file first. The honest answer is that they do different jobs, and the right choice depends on how developed the invention is, how soon something will become public and what the budget allows.</p>
<h2>What a provisional gives you</h2>
<ul><li>A filing date for what the application adequately describes</li><li>Twelve months to file a complete (non-provisional) application that can claim that date</li><li>Lower cost and less formality than a complete application</li><li>The ability to say “patent pending” once it is on file</li></ul>
<h2>What it does not do</h2>
<p>A provisional is never examined and never becomes a patent by itself. If you do not follow it with a complete application within twelve months, it simply expires. Its value also depends on its content: a thin description protects only what it actually describes, so a rushed provisional can leave later claims without support.</p>
<h2>When a complete application makes sense</h2>
<ul><li>The invention is well developed and the details are stable</li><li>You want examination to start sooner</li><li>You have already made a public disclosure and want to move straight to the main filing</li></ul>
<h2>A common path</h2>
<ol><li>File a detailed provisional before the first talk, demo or launch.</li><li>Keep building, testing and talking to investors with a date secured.</li><li>Within twelve months, file the complete application, and decide at the same time about filing in other countries through the Paris Convention or the PCT.</li></ol>
<p>If you are unsure which route fits, the self-check on this site is a good first step, and a consultation can help you choose.</p>""",
    }),
    "b": (cover_b, {
        "title": "What a freedom-to-operate search can and cannot tell you",
        "excerpt": "Owning a patent does not mean you are free to sell your product. What a freedom-to-operate search looks at, what its results mean and where its limits lie.",
        "cover_alt": "Three overlapping circles for existing patents, your product and your target markets",
        "meta_description": "What a freedom-to-operate search covers, how it differs from a patentability search, the options if it finds overlap, and its limits.",
        "body_html": """<p>A patent on your own improvement does not give you the right to sell the whole product. Other people may hold patents on other features, components or combinations. A freedom-to-operate (FTO) search looks at that question before you launch.</p>
<h2>How it differs from a patentability search</h2>
<p>A patentability search focuses on what is unique in your invention and whether it looks new. An FTO search covers every feature and combination in the product you sell, in the countries where you sell it. Neither replaces the other.</p>
<h2>What the results look like</h2>
<ul><li>A set of patents that may be relevant to the product and the target markets</li><li>A comparison of their claims against the features of your product</li><li>Observations and recommendations on which ones matter and why</li></ul>
<h2>If the search finds overlap</h2>
<ul><li>Redesign the product so at least one claim limitation is absent, supported by a legal opinion</li><li>Negotiate a licence with the patent owner before launch</li><li>Cross-license, where you hold overlapping patents of your own</li></ul>
<h2>Limits to keep in mind</h2>
<ul><li>No search can be exhaustive, and results depend on the quality of the product description you provide.</li><li>Patents are national, so the result applies to the countries searched.</li><li>Applications are generally published about 18 months after filing, so very recent filings may not yet be visible.</li><li>A search is a snapshot in time: it should be refreshed as the product and the patent landscape change.</li></ul>
<p>A full freedom-to-operate opinion is a distinct, scoped piece of legal work. A search tells you where to look; an opinion applies the law to your product.</p>""",
    }),
    "c": (cover_c, {
        "title": "Getting your IP ready for a seed round",
        "excerpt": "Investors ask about IP early. A practical checklist of what to put in order first: filings, ownership, disclosures, open source and trademarks.",
        "cover_alt": "A checklist of filings, ownership, disclosures, open source and trademarks beside a rising bar chart",
        "meta_description": "A practical checklist for founders preparing for investor IP diligence: filings, ownership, disclosure history, open source and trademarks.",
        "body_html": """<p>Diligence teams routinely ask the same IP questions. The cheapest time to find gaps is before they do, and most of the work is organisation rather than new filings.</p>
<h2>1. Know what you have filed</h2>
<p>Keep one list of every application, with dates, jurisdictions, status and upcoming deadlines. Include provisionals, and note when each twelve-month window ends.</p>
<h2>2. Prove ownership</h2>
<ul><li>Employees and contractors should have signed agreements assigning their inventions to the company.</li><li>If a university, former employer or accelerator may have a claim, resolve it in writing.</li><li>Assignments should be recorded with the patent office so the chain of title is clear.</li></ul>
<h2>3. Map your disclosures</h2>
<p>List every talk, paper, demo, repository and pitch where the technology was shown, with dates. Public disclosure can affect rights, particularly outside the United States, and investors will want to understand it.</p>
<h2>4. Review open source</h2>
<p>Know which open-source components you use and under which licences, and which parts of your own code are public.</p>
<h2>5. Check your trademarks</h2>
<p>Confirm that the company name and product names are cleared and, where it matters, registered in the markets you serve.</p>
<h2>6. Tell one consistent story</h2>
<p>Be ready to explain what is protected, what is a trade secret, what is still to be filed and why. A short, honest summary reads better than a long list.</p>
<p>If you are preparing for a round, a consultation can help you prioritise what to fix first.</p>""",
    }),
}

for key, (fn, meta) in ARTICLES.items():
    fn().save(os.path.join(OUT, f"demo-{key}-cover.png"))
    meta = dict(meta)
    meta["author"] = "CanPatentIt"
    with open(os.path.join(OUT, f"demo-{key}.json"), "w", encoding="utf-8") as f:
        json.dump(meta, f, ensure_ascii=False)
print("wrote", len(ARTICLES), "demo articles to", OUT)
