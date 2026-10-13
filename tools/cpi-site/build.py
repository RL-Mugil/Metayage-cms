"""Static site generator for canpatentit.com.

Run:  python tools/cpi-site/build.py
Writes finished HTML, sitemap and search index into marketing-canpatentit/ and the article frame
used by the backend InsightPublisher into backend/resources/cpi/.
Admin pages (login.html, admin.html, assets/admin*) are never touched.
"""
import html
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
OUT = os.path.join(ROOT, "marketing-canpatentit")
sys.path.insert(0, HERE)
from content import SERVICES, AREAS, FAQS, TECH_NOTE, RELATED_SERVICES, SHOWCASE_URL  # noqa: E402

SITE = "https://canpatentit.com"
EMAIL = "mugilvannan@myipstrategy.com"
TODAY = "2026-10-14"
esc = html.escape


def _turnstile_key():
    """Public site key: env var, else the value already published in the current homepage."""
    key = os.environ.get("TURNSTILE_SITE_KEY", "").strip()
    if key:
        return key
    try:
        cur = open(os.path.join(OUT, "index.html"), encoding="utf-8").read()
        m = re.search(r'name="turnstile-site-key" content="([^"]*)"', cur)
        return m.group(1) if m else ""
    except OSError:
        return ""


TURNSTILE = _turnstile_key()

EXTRA_ICONS = (
    '<symbol id="i-calendar" viewBox="0 0 24 24"><path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="2"/></symbol>'
    '<symbol id="i-moon" viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></symbol>'
    '<symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2m-7.07-2.93 1.41-1.41M17.66 6.34l1.41-1.41M2 12h2M20 12h2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41"/></symbol>'
)
ICONS = open(os.path.join(HERE, "icons.svg.html"), encoding="utf-8").read().replace("</defs>", EXTRA_ICONS + "</defs>")


def icon(name, cls="i"):
    return f'<svg class="{cls}" aria-hidden="true"><use href="#i-{name}"/></svg>'


PAGES = []  # {path, title, desc, text} for sitemap + search


def url_for(path):
    return "/" if path == "" else "/" + path


def header(current):
    def link(path, label):
        cur = ' aria-current="page"' if current == path else ""
        return f'<a href="{url_for(path)}"{cur}>{label}</a>'

    svc = "".join(link("services/" + s["slug"], esc(s["short"])) for s in SERVICES)
    tech = "".join(link("technology/" + a["slug"], esc(a["short"])) for a in AREAS)
    return f'''<header class="cpi-header">
    <a class="cpi-brand" href="/" aria-label="CanPatentIt home"><img src="/assets/logo.png" alt="CanPatentIt" width="192" height="64"></a>
    <nav class="cpi-nav" id="site-nav" aria-label="Main navigation">
      <div class="cpi-dd"><button type="button" class="cpi-dd-btn" aria-expanded="false">Services {icon("chevron-down")}</button><div class="cpi-dd-menu">{link("services", "All services")}{svc}</div></div>
      <div class="cpi-dd"><button type="button" class="cpi-dd-btn" aria-expanded="false">Technology {icon("chevron-down")}</button><div class="cpi-dd-menu">{tech}</div></div>
      {link("about", "About")}
      {link("insights", "Insights")}
      {link("patent-showcase", "Patent showcase")}
      {link("faq", "FAQ")}
      <a class="cpi-nav-cta" href="/book">Book a consultation {icon("arrow-up-right")}</a>
    </nav>
    <div class="cpi-tools">
      <button class="cpi-icon-btn" id="search-open" type="button" aria-label="Search the site">{icon("search")}</button>
      <button class="cpi-icon-btn" id="theme-toggle" type="button" aria-label="Switch colour theme">{icon("moon")}</button>
      <button class="cpi-menu-button" id="menu-button" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="site-nav">{icon("menu")}</button>
    </div>
  </header>'''


def footer():
    svc = "".join(f'<li><a href="/services/{s["slug"]}">{esc(s["short"])}</a></li>' for s in SERVICES)
    tech = "".join(f'<li><a href="/technology/{a["slug"]}">{esc(a["short"])}</a></li>' for a in AREAS)
    return f'''<footer class="cpi-footer cpi-footer-full">
    <div class="cpi-footer-top"><a class="cpi-brand cpi-footer-brand" href="/" aria-label="CanPatentIt home"><img src="/assets/logo.png" alt="CanPatentIt" width="168" height="56" loading="lazy"></a><p>Protect what you’re building.<br>Make room for what’s next.</p><a class="cpi-nav-cta" href="/book">Book a consultation {icon("arrow-up-right")}</a></div>
    <div class="cpi-footer-cols">
      <div><h3>Services</h3><ul>{svc}</ul></div>
      <div><h3>Technology</h3><ul>{tech}</ul></div>
      <div><h3>Explore</h3><ul><li><a href="/about">About</a></li><li><a href="/insights">Insights</a></li><li><a href="/patent-showcase">Patent showcase</a></li><li><a href="/faq">FAQ</a></li><li><a href="/patentability-check">Patentability self-check</a></li><li><a href="/book">Book a consultation</a></li></ul></div>
      <div><h3>Legal</h3><ul><li><a href="/privacy">Privacy</a></li><li><a href="/terms">Terms</a></li><li><a href="#" data-cookie-settings>Cookie settings</a></li><li><a href="mailto:{EMAIL}">{EMAIL}</a></li></ul></div>
    </div>
    <div class="cpi-footer-bottom"><span>© <span id="year">2026</span> CanPatentIt</span><span>US patent strategy for deep technology</span></div>
    <p class="cpi-footer-note">CanPatentIt is a brand of <a href="https://metayage.com" rel="noopener" target="_blank">Metayage Private Limited</a>. CanPatentIt provides patent strategy services. Nothing on this site is legal advice or creates an attorney–client relationship, and no outcome is guaranteed.</p>
  </footer>'''


def shell(path, title, desc, body, current=None, extra_head="", jsonld=None, noindex=False, scripts="", og_image=None):
    og = og_image or f"{SITE}/assets/og-image.png"
    canon = SITE + url_for(path)
    full_title = title if title.startswith("CanPatentIt") else f"{title} | CanPatentIt"
    ld = ""
    for obj in jsonld or []:
        ld += f'\n  <script type="application/ld+json">{json.dumps(obj, ensure_ascii=False)}</script>'
    robots = '\n  <meta name="robots" content="noindex, nofollow">' if noindex else ""
    return f'''<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{esc(full_title)}</title>
  <meta name="description" content="{esc(desc)}">{robots}
  <meta name="theme-color" content="#f8f9fa">
  <link rel="canonical" href="{canon}">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <link rel="apple-touch-icon" href="/assets/favicon.png">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="CanPatentIt">
  <meta property="og:url" content="{canon}">
  <meta property="og:title" content="{esc(full_title)}">
  <meta property="og:description" content="{esc(desc)}">
  <meta property="og:image" content="{og}">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{esc(full_title)}">
  <meta name="twitter:description" content="{esc(desc)}">
  <meta name="twitter:image" content="{og}">
  <meta name="contact-endpoint" content="https://login.myipstrategy.com/api/public/contact">
  <meta name="api-base" content="https://login.myipstrategy.com/api">
  <meta name="turnstile-site-key" content="{TURNSTILE}">
  <meta name="ga-measurement-id" content="">
  <script src="/assets/theme.js"></script>
  <link rel="stylesheet" href="/assets/site.css">
  <link rel="stylesheet" href="/assets/pages.css">{extra_head}{ld}
</head>
<body>
{ICONS}
<a class="skip-link" href="#main">Skip to content</a>
<div class="cpi-site">
  {header(current if current is not None else path)}
  <main id="main">
{body}
  </main>
  {footer()}
</div>
<div class="cpi-search" id="search-modal" role="dialog" aria-modal="true" aria-label="Search" hidden>
  <div class="cpi-search-box">
    <input id="search-input" type="search" placeholder="Search services, technologies, articles…" autocomplete="off" aria-label="Search the site">
    <ul id="search-results" role="listbox"></ul>
    <button type="button" id="search-close" class="cpi-icon-btn" aria-label="Close search">{icon("x")}</button>
  </div>
</div>
<div class="cpi-cookie" id="cookie-banner" role="dialog" aria-label="Cookie notice" hidden>
  <p>We use optional analytics cookies to understand how this site is used. See our <a href="/privacy">Privacy Policy</a>.</p>
  <button type="button" id="cookie-reject">Decline</button>
  <button type="button" class="primary" id="cookie-accept">Accept</button>
</div>
<script src="/assets/site.js" defer></script>
<script src="/assets/fx.js" defer></script>{scripts}
</body>
</html>
'''


def strip_tags(s):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html.unescape(s))).strip()


def register(path, title, desc, body):
    PAGES.append({"path": path, "title": title, "desc": desc, "text": strip_tags(body)[:600]})


def write(path, content):
    name = "index.html" if path == "" else path + ".html"
    full = os.path.join(OUT, name)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with open(full, "w", encoding="utf-8", newline="\n") as f:
        f.write(content)


def crumbs(items):
    """items: list of (label, path or None). Returns (html, jsonld)."""
    parts, ld = [], []
    for i, (label, path) in enumerate(items):
        parts.append(f'<a href="{url_for(path)}">{esc(label)}</a>' if path is not None and i < len(items) - 1 else f"<span>{esc(label)}</span>")
        ld.append({"@type": "ListItem", "position": i + 1, "name": label, "item": SITE + (url_for(path) if path is not None else "")})
    h = '<nav class="cpi-crumbs" aria-label="Breadcrumb">' + " <i>/</i> ".join(parts) + "</nav>"
    return h, {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": ld}


SHAPES = {
    "ai-machine-learning": "layers", "semiconductors-quantum": "lattice", "medtech-biotechnology": "helix",
    "climate-energy": "rings", "enterprise-software-security": "crystal",
    "patentability-prior-art": "sphere", "provisional-applications": "sheets",
    "us-patent-prosecution": "torus", "ip-strategy-diligence": "network",
    # Other pages: art in assets/heroes/<slug>.jpg and their own 3D shape.
    "about": "sphere", "services": "rings", "faq": "crystal", "insights": "sheets",
    "patentability-check": "layers", "book": "torus", "patent-showcase": "lattice",
}


def page_hero(crumb_html, eyebrow, h1, lead, actions=True, slug=None):
    buttons = f'<div class="cpi-hero-actions"><a class="cpi-button-lime" href="/book">Book a consultation {icon("arrow-right")}</a><a class="cpi-hero-secondary" href="/patentability-check">Try the self-check {icon("arrow-right")}</a></div>' if actions else ""
    shape = SHAPES.get(slug, "torus")
    art = f' has-art" style="--hero-art:url(\'/assets/heroes/{slug}.jpg\')' if slug in SHAPES else ""
    return f'''<section class="cpi-page-hero{art}"><canvas class="cpi-3d" data-shape="{shape}" aria-hidden="true"></canvas><div class="cpi-section">{crumb_html}<div class="cpi-eyebrow cpi-eyebrow-light"><span></span> {esc(eyebrow)}</div><h1>{h1}</h1><p>{esc(lead)}</p>{buttons}</div></section>'''


def faq_block(items, heading="Common questions"):
    rows = "".join(f'<details class="cpi-faq"><summary>{esc(q)}</summary><div><p>{esc(a)}</p></div></details>' for q, a in items)
    return f'<div class="cpi-faq-list"><h2>{esc(heading)}</h2>{rows}</div>'


def faq_ld(items):
    return {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [{"@type": "Question", "name": q, "acceptedAnswer": {"@type": "Answer", "text": a}} for q, a in items]}


def cta_band(text="Tell us what you’re building and we’ll suggest a sensible next step."):
    return f'''<section class="cpi-cta-band"><div class="cpi-section"><h2>{esc(text)}</h2><div class="cpi-hero-actions"><a class="cpi-button-lime" href="/book">Book a consultation {icon("arrow-right")}</a><a class="cpi-text-link" href="/#assessment">Send a short description {icon("arrow-right")}</a></div></div></section>'''


def detail_page(kind, item, siblings):
    base = "services" if kind == "service" else "technology"
    path = f"{base}/{item['slug']}"
    top = ("Services", "services") if kind == "service" else ("Technology areas", "#technology")
    c_html, c_ld = crumbs([("Home", ""), (top[0], top[1]), (item["short"], path)])
    secs = "".join(f'<section class="cpi-prose-sec"><h2>{esc(h)}</h2>{b}</section>' for h, b in item["sections"])
    bl_h, bl = item["bullets"]
    bullets = f'<aside class="cpi-side-card"><h3>{esc(bl_h)}</h3><ul>' + "".join(f"<li>{icon('check')} {esc(x)}</li>" for x in bl) + "</ul></aside>"
    rel = "".join(f'<a class="cpi-related" href="/{base}/{s["slug"]}"><span>{esc(s["short"])}</span>{icon("arrow-up-right")}</a>' for s in siblings if s["slug"] != item["slug"])
    domains = ""
    if item.get("domains"):
        chips = "".join(f'<a class="cpi-chip" href="{SHOWCASE_URL}" rel="noopener" target="_blank">{esc(d)}</a>' for d in item["domains"])
        domains = f'<section class="cpi-prose-sec"><h2>Related domains in Metayage’s patent showcase</h2><p>Metayage, the company behind CanPatentIt, organises its patent showcase by technology domain. The domains closest to this area are:</p><div class="cpi-chips">{chips}</div></section>'
    body = page_hero(c_html, "Services" if kind == "service" else "Technology area", esc(item["title"]), item["lead"], slug=item["slug"]) + f'''
    <section class="cpi-section cpi-detail"><div class="cpi-prose">{secs}{domains}<!--cpi:faq-->{faq_block(item["faq"])}<!--/cpi:faq--><p class="cpi-fine">{esc(TECH_NOTE)}</p></div>{bullets}</section>
    <section class="cpi-section cpi-related-wrap"><h2>{"Other services" if kind == "service" else "Other technology areas"}</h2><div class="cpi-related-grid">{rel}</div></section>
    {cta_band()}'''
    ld = [c_ld]
    if kind == "service":
        ld.append({"@context": "https://schema.org", "@type": "Service", "name": item["title"], "description": item["desc"], "provider": {"@type": "ProfessionalService", "name": "CanPatentIt", "url": SITE}, "areaServed": "US"})
    faq_ld_tag = "<!--cpi:faqld--><script type=\"application/ld+json\">" + json.dumps(faq_ld(item["faq"]), ensure_ascii=False) + "</script><!--/cpi:faqld-->"
    write(path, shell(path, item["title"], item["desc"], body, jsonld=ld, extra_head="  " + faq_ld_tag))
    register(path, item["title"], item["desc"], body)


def build_home():
    main = open(os.path.join(HERE, "home_main.html"), encoding="utf-8").read()
    # service cards link to their pages
    parts = main.split('<article class="cpi-service-item">')
    out = [parts[0]]
    for i, part in enumerate(parts[1:]):
        s = SERVICES[i]
        part = re.sub(r'<a href="#assessment" aria-label="[^"]*">', f'<a href="/services/{s["slug"]}" aria-label="Read about {esc(s["short"])}">', part, count=1)
        out.append('<article class="cpi-service-item">' + part)
    main = "".join(out)
    # technology rows become links
    parts = main.split('<article class="cpi-domain-row">')
    out = [parts[0]]
    for i, part in enumerate(parts[1:]):
        a = AREAS[i]
        part = part.replace("</article>", "</a>", 1)
        out.append(f'<a class="cpi-domain-row" href="/technology/{a["slug"]}">' + part)
    main = "".join(out)
    extra = f'''
    <section class="cpi-section cpi-teasers" id="tools">
      <a class="cpi-teaser" href="/patentability-check"><span class="cpi-section-number">01</span><h3>Patentability self-check</h3><p>Answer a few questions about your invention and get plain-language guidance on what to do next.</p><span class="cpi-text-link">Start the self-check {icon("arrow-right")}</span></a>
      <a class="cpi-teaser" href="/book"><span class="cpi-section-number">02</span><h3>Book a consultation</h3><p>Pick a time that suits you. We start with the technology, not a pitch.</p><span class="cpi-text-link">See available times {icon("arrow-right")}</span></a>
      <a class="cpi-teaser" href="/insights"><span class="cpi-section-number">03</span><h3>Insights</h3><p>Short, practical notes on patents for deep-tech founders and the people who back them.</p><span class="cpi-text-link">Read the insights {icon("arrow-right")}</span></a>
    </section>'''
    company = f'''
    <section class="cpi-section cpi-company"><div><span class="cpi-section-number">MY</span><h2>A brand of Metayage Private Limited</h2></div><div><p>CanPatentIt is a brand of Metayage, an intellectual property services and consulting company founded in 2006 and working as a strategic IP partner to founders, faculty, students and incubators.</p><a class="cpi-text-link" href="/about#metayage">About Metayage and CanPatentIt {icon("arrow-right")}</a></div></section>'''
    main = main.replace('<section class="cpi-founder-band"', extra + company + '\n    <section class="cpi-founder-band"', 1)
    desc = "Patent strategy and protection for US founders building AI, software, hardware, and other deep technology."
    ld = [{"@context": "https://schema.org", "@type": "ProfessionalService", "name": "CanPatentIt", "url": SITE + "/", "logo": SITE + "/assets/logo.png", "description": "US patent strategy for deep technology founders.", "email": EMAIL, "parentOrganization": {"@type": "Organization", "name": "Metayage Private Limited", "url": "https://metayage.com"}},
          {"@context": "https://schema.org", "@type": "WebSite", "name": "CanPatentIt", "url": SITE + "/"}]
    write("", shell("", "CanPatentIt | Deep Tech IP Strategy for US Founders", desc, main, jsonld=ld, current=""))
    register("", "Home", desc, main)


def build_about():
    c_html, c_ld = crumbs([("Home", ""), ("About", "about")])
    body = page_hero(c_html, "About", "Technical thinking, <em>IP with intent.</em>", "A US patent strategy practice for the people building and backing what comes next.", slug="about") + f'''
    <section class="cpi-section cpi-detail"><div class="cpi-prose">
      <section class="cpi-prose-sec"><h2>Who we help</h2><p>Deep-tech founders, CTOs and engineers who need an IP plan that respects the runway, and the investors and advisers who want a clear view of what is owned, what is protectable and where risk remains.</p></section>
      <section class="cpi-prose-sec" id="approach"><h2>Our approach</h2>
        <div class="cpi-steps">
          <div><span>01</span><h3>Speak engineering</h3><p>We begin with the system, the problem and what makes your approach different. If we cannot explain your technology back to you, we have not earned a draft yet.</p></div>
          <div><span>02</span><h3>Move with intent</h3><p>Clear priorities and a practical path to the next filing decision, sized to your stage and budget.</p></div>
          <div><span>03</span><h3>Protect with context</h3><p>Filings shaped around the product, the market and the questions future investors and partners will ask.</p></div>
          <div><span>04</span><h3>Stay in step</h3><p>Plain-language updates and deadline tracking, so decisions never arrive as surprises.</p></div>
        </div></section>
      <section class="cpi-prose-sec" id="metayage"><h2>A brand of Metayage Private Limited</h2>
        <p>CanPatentIt is a brand of <a href="https://metayage.com" rel="noopener" target="_blank">Metayage Private Limited</a>, an intellectual property services and consulting company that describes itself as a strategic IP partner: an extended in-house IP department that also works as an external IP firm.</p>
        <p>Metayage IP was founded in 2006 to bridge the gap between ideas and their implementation. The name comes from a French word for an old farming arrangement in which a farmer works a landowner’s land and shares in the harvest. It is a fitting picture of the relationship we aim for with clients: working together to grow the value of what you build.</p>
        <p>Metayage works with founders, faculty, students and incubators, and its offices sit inside incubators on engineering college campuses in Bengaluru, Coimbatore, Hyderabad and Chennai, with a US office in Redmond, Washington. CanPatentIt carries that approach to US patent strategy for deep-technology companies.</p>
        <p><a class="cpi-text-link" href="https://metayage.com" rel="noopener" target="_blank">Visit metayage.com {icon("arrow-up-right")}</a></p></section>
      <section class="cpi-prose-sec"><h2>What we will not do</h2><ul><li>Promise that anything will be granted</li><li>Publish unverified statistics, client names or testimonials</li><li>Ask you to disclose more than you are comfortable sharing in a first conversation</li></ul></section>
      <p class="cpi-fine">{esc(TECH_NOTE)}</p></div>
      <aside class="cpi-side-card"><h3>Start a conversation</h3><p>Tell us what you are building and we will suggest a sensible next step.</p><a class="cpi-button-lime" href="/book">Book a consultation {icon("arrow-right")}</a><p class="cpi-fine">Or write to <a href="mailto:{EMAIL}">{EMAIL}</a></p></aside></section>
    {cta_band()}'''
    desc = "CanPatentIt is a Metayage brand offering US patent strategy for deep-tech founders and investors. How we work and who we help."
    write("about", shell("about", "About CanPatentIt", desc, body, jsonld=[c_ld]))
    register("about", "About", desc, body)


def build_services_overview():
    c_html, c_ld = crumbs([("Home", ""), ("Services", "services")])
    cards = "".join(f'<a class="cpi-card" href="/services/{s["slug"]}"><span class="cpi-card-ic">{icon(s["icon"])}</span><h3>{esc(s["short"])}</h3><p>{esc(s["desc"])}</p><span class="cpi-text-link">Learn more {icon("arrow-right")}</span></a>' for s in SERVICES)
    tech = "".join(f'<a class="cpi-related" href="/technology/{a["slug"]}"><span>{esc(a["short"])}</span>{icon("arrow-up-right")}</a>' for a in AREAS)
    extra_cards = "".join(f'<a class="cpi-card" href="{u}" rel="noopener" target="_blank"><h3>{esc(t)}</h3><p>{esc(d)}</p><span class="cpi-text-link">On metayage.com {icon("arrow-up-right")}</span></a>' for t, u, d in RELATED_SERVICES)
    body = page_hero(c_html, "Services", "Clarity at every <em>IP decision.</em>", "From a first idea to the next financing conversation, make your next move with context.", slug="services") + f'''
    <section class="cpi-section cpi-card-grid-wrap"><div class="cpi-card-grid">{cards}</div></section>
    <section class="cpi-section cpi-related-wrap"><h2>Technology areas we work in</h2><div class="cpi-related-grid">{tech}</div></section>
    <section class="cpi-section cpi-related-wrap"><h2>Also from Metayage</h2><p class="cpi-fine" style="max-width:680px;margin-bottom:18px">CanPatentIt focuses on US patent strategy. Our parent company, Metayage, also offers these services, which we can introduce you to.</p><div class="cpi-card-grid">{extra_cards}</div><p class="cpi-fine" style="margin-top:18px">Metayage’s patent showcase is organised across 14 technology domains: Artificial Intelligence, Telecommunication, Electric Vehicle, Healthcare, Cryptography, 3D/AR/VR, Blockchain, Manufacturing, Business Intelligence, Network Management, Food and Beverages, Aerospace Engineering, Bio-Medical Engineering and Polymer Technology. <a href="{SHOWCASE_URL}" rel="noopener" target="_blank">Browse the patent showcase</a>.</p></section>
    {cta_band()}'''
    desc = "US patent services for deep-tech founders: patentability and prior art, provisional applications, prosecution, and IP strategy."
    write("services", shell("services", "Patent Services for Deep Tech", desc, body, jsonld=[c_ld]))
    register("services", "Services", desc, body)


NL = chr(10)


def build_faq():
    """The FAQ page is written by the backend (InsightPublisher::publishFaq) from questions edited in the
    admin panel. Here we only emit its frame, so a deploy never overwrites edited questions."""
    c_html, c_ld = crumbs([("Home", ""), ("FAQ", "faq")])
    body = page_hero(c_html, "FAQ", "Questions, <em>answered plainly.</em>", "Straight answers about how we work and what to expect.", slug="faq") + f'''
    <section class="cpi-section cpi-detail cpi-faq-page"><div class="cpi-prose">@@FAQ_BLOCK@@<p class="cpi-fine">{esc(TECH_NOTE)}</p></div>
    <aside class="cpi-side-card"><h3>Still unsure?</h3><p>Ask us directly. A first conversation can start with the problem you are solving.</p><a class="cpi-button-lime" href="/book">Book a consultation {icon("arrow-right")}</a></aside></section>
    {cta_band()}'''
    desc = "Answers to common questions about US patent strategy, provisional applications, disclosure and how CanPatentIt works."
    out = shell("faq", "Frequently Asked Questions", desc, body, jsonld=[c_ld], extra_head=NL + "  <!--@@FAQ_LD@@-->")
    dest = os.path.join(ROOT, "backend", "resources", "cpi", "faq-shell.html")
    with open(dest, "w", encoding="utf-8", newline=NL) as f:
        f.write(out)
    stale = os.path.join(OUT, "faq.html")
    if os.path.exists(stale):
        os.remove(stale)
    register("faq", "FAQ", desc, "Frequently asked questions about US patent strategy and how CanPatentIt works.")


def build_showcase():
    """Patent showcase index (filters + grid are filled in by showcase.js from /patent-showcase/index.json)
    and the frame the backend uses for each patent page."""
    c_html, c_ld = crumbs([("Home", ""), ("Patent showcase", "patent-showcase")])
    body = page_hero(c_html, "Patent showcase", "Patents we have <em>worked on.</em>", "A selection of patents, by technology domain and country. Examples of the work, not a promise of results.", slug="patent-showcase") + f'''
    <section class="cpi-section cpi-showcase">
      <aside class="cpi-showcase-filters" aria-label="Filters">
        <h2>Select country</h2>
        <ul class="cpi-filter" id="country-filter" role="list"><li><p class="cpi-fine">Loading…</p></li></ul>
        <h2>Select domain</h2>
        <ul class="cpi-filter" id="domain-filter" role="list"></ul>
      </aside>
      <div class="cpi-showcase-main">
        <h2 class="cpi-showcase-title" id="showcase-title" aria-live="polite">Patents related to all domains (all countries)</h2>
        <div id="showcase-grid" class="cpi-post-grid cpi-patent-grid" aria-live="polite"></div>
        <nav class="cpi-pager" id="showcase-pager" aria-label="Pagination" hidden></nav>
        <noscript><p class="cpi-fine">Enable JavaScript to browse the patent showcase.</p></noscript>
      </div>
    </section>
    <section class="cpi-section cpi-fine-wrap"><p class="cpi-fine">Patent details are shown for illustration. {esc(TECH_NOTE)}</p></section>
    {cta_band()}'''
    desc = "A selection of patents by technology domain and country, shown as examples of the work done by Metayage and CanPatentIt."
    write("patent-showcase", shell("patent-showcase", "Patent Showcase", desc, body, jsonld=[c_ld], scripts='\n<script src="/assets/showcase.js" defer></script>'))
    register("patent-showcase", "Patent showcase", desc, body)

    out = shell("patent-showcase/@@SLUG@@", "@@TITLE@@", "@@DESC@@", "@@BODY@@", current="patent-showcase", extra_head=NL + "  <!--@@HEAD@@-->", og_image="@@OGIMAGE@@", scripts=NL + '<script src="/assets/showcase-detail.js" defer></script>')
    dest = os.path.join(ROOT, "backend", "resources", "cpi", "patent-shell.html")
    with open(dest, "w", encoding="utf-8", newline=NL) as f:
        f.write(out)


def build_insights():
    c_html, c_ld = crumbs([("Home", ""), ("Insights", "insights")])
    body = page_hero(c_html, "Insights", "Practical notes on <em>deep-tech IP.</em>", "Short articles for founders, engineers and investors. General information, not legal advice.", slug="insights") + '''
    <section class="cpi-section cpi-insights-wrap"><div class="cpi-insights-bar" id="insights-bar" hidden><p class="cpi-fine" id="insights-count"></p><div class="cpi-view-toggle" role="group" aria-label="Choose layout"><button type="button" id="view-grid" aria-pressed="true" aria-label="Grid view" title="Grid view"></button><button type="button" id="view-list" aria-pressed="false" aria-label="List view" title="List view"></button></div></div><div id="insights-list" class="cpi-post-grid" aria-live="polite"><p class="cpi-fine">Loading articles…</p></div><nav class="cpi-pager" id="insights-pager" aria-label="Pagination" hidden></nav><noscript><p class="cpi-fine">Enable JavaScript to see the article list.</p></noscript></section>
    ''' + cta_band()
    desc = "Practical articles on US patent strategy for deep-tech founders, engineers and investors."
    write("insights", shell("insights", "Insights", desc, body, jsonld=[c_ld], scripts='\n<script src="/assets/insights.js" defer></script>'))
    register("insights", "Insights", desc, body)


def build_quiz():
    c_html, c_ld = crumbs([("Home", ""), ("Patentability self-check", "patentability-check")])
    body = page_hero(c_html, "Self-check", "Can you <em>patent it?</em>", "Answer a few questions about your invention. You will get plain-language guidance, not a legal opinion.", actions=False, slug="patentability-check") + f'''
    <section class="cpi-section cpi-quiz-wrap"><div class="cpi-quiz" id="quiz" aria-live="polite"><p class="cpi-fine">Loading the self-check…</p></div>
    <noscript><p class="cpi-fine">The self-check needs JavaScript. You can also <a href="/book">book a consultation</a>.</p></noscript>
    <p class="cpi-fine">{esc(TECH_NOTE)} Answers stay in your browser unless you choose to send them to us.</p></section>'''
    desc = "A short self-check that gives plain-language guidance on whether and when to talk to a patent professional about your invention."
    write("patentability-check", shell("patentability-check", "Patentability Self-Check", desc, body, jsonld=[c_ld], scripts='\n<script src="/assets/quiz.js" defer></script>'))
    register("patentability-check", "Patentability self-check", desc, body)


def build_book():
    c_html, c_ld = crumbs([("Home", ""), ("Book a consultation", "book")])
    body = page_hero(c_html, "Book a consultation", "Let’s talk about <em>your technology.</em>", "Choose a time that suits you. Times are shown in your own time zone.", actions=False, slug="book") + f'''
    <section class="cpi-section cpi-book-wrap">
      <div class="cpi-book" id="book" aria-live="polite">
        <div class="cpi-book-step"><h2>1. Pick a day</h2><div id="book-days" class="cpi-days"><p class="cpi-fine">Loading availability…</p></div></div>
        <div class="cpi-book-step"><h2>2. Pick a time</h2><p class="cpi-fine" id="book-tz"></p><div id="book-slots" class="cpi-slots"><p class="cpi-fine">Choose a day first.</p></div></div>
        <form class="cpi-form cpi-book-form" id="book-form" novalidate hidden>
          <h2>3. Your details</h2>
          <p class="cpi-fine" id="book-chosen"></p>
          <div class="cpi-field-pair">
            <label for="b-name">Your name<input id="b-name" name="name" autocomplete="name" maxlength="100" required><span class="cpi-field-error" id="b-name-error"></span></label>
            <label for="b-email">Work email<input id="b-email" name="email" type="email" autocomplete="email" maxlength="150" required><span class="cpi-field-error" id="b-email-error"></span></label>
          </div>
          <label for="b-company">Company<input id="b-company" name="organization" autocomplete="organization" maxlength="150"></label>
          <label for="b-topic">What would you like to discuss?<textarea id="b-topic" name="topic" rows="3" maxlength="1000" placeholder="A few lines are enough. Keep confidential details high-level."></textarea></label>
          <div class="cpi-honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
          <div id="turnstile-widget"></div>
          <button class="cpi-button-lime" id="book-submit" type="submit">Confirm booking {icon("arrow-right")}</button>
          <p class="cpi-form-status" id="book-status" role="status" aria-live="polite"></p>
        </form>
        <div class="cpi-form-success" id="book-success" role="status" hidden><div class="cpi-success-icon">{icon("check")}</div><h3>You’re booked.</h3><p id="book-success-text"></p></div>
      </div>
      <aside class="cpi-side-card"><h3>Prefer email?</h3><p>Write to <a href="mailto:{EMAIL}">{EMAIL}</a> or use the <a href="/#assessment">short enquiry form</a>.</p><h3>Before the call</h3><p>No preparation is needed. A description of the problem and how your approach works is plenty.</p><p class="cpi-fine">{esc(TECH_NOTE)} A call does not create an attorney–client relationship.</p></aside>
    </section>'''
    desc = "Book a consultation to talk through your technology and sensible next steps for US patent protection."
    write("book", shell("book", "Book a Consultation", desc, body, jsonld=[c_ld], scripts='\n<script src="/assets/booking.js" defer></script>'))
    register("book", "Book a consultation", desc, body)


def legal_page(path, title, desc, inner):
    c_html, c_ld = crumbs([("Home", ""), (title, path)])
    body = f'<article class="cpi-legal"><div class="cpi-crumb-wrap">{c_html}</div><h1>{esc(title)}</h1><p>Last updated: 14 October 2026</p><p class="cpi-fine">This is a general draft and should be reviewed by a qualified lawyer before you rely on it.</p>{inner}</article>'
    write(path, shell(path, title, desc, body, jsonld=[c_ld]))
    register(path, title, desc, body)


def build_legal():
    legal_page("privacy", "Privacy Policy", "How CanPatentIt collects, uses and protects personal data.", f'''
      <p>This policy explains how CanPatentIt, a brand of Metayage Private Limited (“we”, “us”), handles personal data on canpatentit.com.</p>
      <h2>1. What we collect</h2>
      <ul><li><strong>Enquiry form:</strong> name, work email, company, company stage, technology area and the description you write.</li>
      <li><strong>Patentability self-check:</strong> answers stay in your browser. If you choose to send them to us, we receive your answers together with your contact details.</li>
      <li><strong>Consultation booking:</strong> name, email, company, the topic you enter and the time you choose.</li>
      <li><strong>Technical data:</strong> IP address, browser type and request logs, kept for security and abuse prevention.</li>
      <li><strong>Analytics (only with your consent):</strong> anonymised usage data such as pages viewed and approximate region.</li></ul>
      <h2>2. How we use it</h2>
      <ul><li>To reply to you, schedule and hold consultations and discuss a possible engagement.</li><li>To secure the site and prevent spam and abuse.</li><li>To improve the site, where you have accepted analytics cookies.</li></ul>
      <p>Please keep confidential invention details high-level in forms. Submitting a form or booking a call does not create an attorney–client relationship.</p>
      <h2>3. Cookies and local storage</h2>
      <p>The site stores your cookie choice and your colour-theme preference in your browser. Analytics cookies are set only if you accept, and you can change your choice using “Cookie settings” in the footer.</p>
      <h2>4. Service providers</h2>
      <p>We use trusted providers to host the site, send email and provide bot protection (Cloudflare Turnstile) and, if enabled, analytics (Google). They process data only on our instructions or under their own privacy terms.</p>
      <h2>5. Retention and security</h2>
      <p>Enquiries and bookings are kept only as long as needed to respond and for reasonable record keeping. Data is protected with encryption in transit and access controls.</p>
      <h2>6. Your rights</h2>
      <p>Subject to applicable law, you may ask to access, correct or delete your personal data or withdraw consent. Email <a href="mailto:{EMAIL}">{EMAIL}</a> with “Privacy request” in the subject.</p>
      <h2>7. Changes</h2><p>We may update this policy and will change the date above when we do.</p>''')
    legal_page("terms", "Terms of Use", "Terms for using the CanPatentIt website.", f'''
      <p>canpatentit.com is operated by Metayage Private Limited under the CanPatentIt brand. By using the site you agree to these terms.</p>
      <h2>1. Information only</h2><p>Content on this site, including the patentability self-check and articles, is general information about patent strategy. It is not legal advice, and using the site, taking the self-check or booking a call does not create an attorney–client relationship. An engagement begins only when we agree in writing.</p>
      <h2>2. No guarantees</h2><p>Patentability and outcomes depend on the facts of each invention and on examination by the relevant patent office. Nothing on this site guarantees that any invention can or will be patented.</p>
      <h2>3. Confidential information</h2><p>Do not send trade secrets or unpublished technical details through website forms. Share only a high-level description until we have agreed how to exchange confidential material.</p>
      <h2>4. Bookings</h2><p>Consultation times are subject to confirmation and may be rescheduled or cancelled by either party using the links in the confirmation email.</p>
      <h2>5. Acceptable use</h2><p>You agree not to misuse the site, attempt to disrupt it, or submit unlawful, misleading or automated content.</p>
      <h2>6. Intellectual property</h2><p>The site’s text, design and logo belong to CanPatentIt and may not be copied without permission.</p>
      <h2>7. Liability</h2><p>To the extent permitted by law, the site is provided “as is” and we are not liable for losses arising from its use.</p>
      <h2>8. Contact</h2><p>Questions about these terms: <a href="mailto:{EMAIL}">{EMAIL}</a>.</p>''')


def build_404():
    body = '<section class="cpi-notfound"><h1>404</h1><h2>Page not found</h2><p>The page you are looking for does not exist or has moved.</p><p><a class="cpi-button-lime" href="/">Back to the homepage</a></p></section>'
    write("404", shell("404", "Page not found", "This page could not be found.", body, noindex=True, current="__none__"))


def build_article_shell():
    """Frame used by the backend InsightPublisher; the @@TOKENS@@ are replaced at publish time."""
    head = "\n  <!--@@HEAD@@-->"
    out = shell("insights/@@SLUG@@", "@@TITLE@@", "@@DESC@@", "@@BODY@@", current="insights", extra_head=head, og_image="@@OGIMAGE@@")
    dest = os.path.join(ROOT, "backend", "resources", "cpi", "article-shell.html")
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    with open(dest, "w", encoding="utf-8", newline="\n") as f:
        f.write(out)


def build_meta_files():
    sm = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']
    for p in PAGES:
        pr = "1.0" if p["path"] == "" else ("0.3" if p["path"] in ("privacy", "terms") else "0.7")
        sm.append(f'  <url><loc>{SITE}{url_for(p["path"])}</loc><lastmod>{TODAY}</lastmod><priority>{pr}</priority></url>')
    sm.append("</urlset>")
    open(os.path.join(OUT, "sitemap-pages.xml"), "w", encoding="utf-8", newline="\n").write("\n".join(sm) + "\n")
    idx = f'<?xml version="1.0" encoding="UTF-8"?>\n<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n  <sitemap><loc>{SITE}/sitemap-pages.xml</loc></sitemap>\n  <sitemap><loc>{SITE}/insights/sitemap-insights.xml</loc></sitemap>\n  <sitemap><loc>{SITE}/patent-showcase/sitemap-patents.xml</loc></sitemap>\n</sitemapindex>\n'
    open(os.path.join(OUT, "sitemap.xml"), "w", encoding="utf-8", newline="\n").write(idx)
    open(os.path.join(OUT, "robots.txt"), "w", newline="\n").write(f"User-agent: *\nAllow: /\nDisallow: /login\nDisallow: /admin\n\nSitemap: {SITE}/sitemap.xml\n")
    index = [{"url": url_for(p["path"]), "title": p["title"], "desc": p["desc"], "text": p["text"]} for p in PAGES if p["path"] not in ("privacy", "terms")]
    os.makedirs(os.path.join(OUT, "assets"), exist_ok=True)
    with open(os.path.join(OUT, "assets", "search-index.json"), "w", encoding="utf-8", newline="\n") as f:
        json.dump(index, f, ensure_ascii=False)


def check_links():
    pages = {url_for(p["path"]) for p in PAGES} | {"/404", "/login", "/admin"}
    bad = []
    for root, _, files in os.walk(OUT):
        for fn in files:
            if not fn.endswith(".html") or fn in ("login.html", "admin.html"):
                continue
            text = open(os.path.join(root, fn), encoding="utf-8").read()
            for href in re.findall(r'href="(/[^"#?]*)', text):
                if href.startswith("/assets/") or href.startswith("/insights/") or href.rstrip("/") in pages or href == "/":
                    continue
                bad.append((fn, href))
    return bad


def version_assets():
    """Stamp every local .js/.css link with a content hash so browsers never keep stale copies."""
    import hashlib
    pat = re.compile(r'(/assets/[\w.-]+\.(?:js|css))(?:\?v=\w+)?"')
    targets = [os.path.join(r, f) for r, _, fs in os.walk(OUT) for f in fs if f.endswith(".html")]
    targets.append(os.path.join(ROOT, "backend", "resources", "cpi", "article-shell.html"))

    def stamp(m):
        try:
            digest = hashlib.md5(open(os.path.join(OUT, m.group(1).lstrip("/")), "rb").read()).hexdigest()[:8]
        except OSError:
            return m.group(0)
        return f'{m.group(1)}?v={digest}"'

    for path in targets:
        text = open(path, encoding="utf-8").read()
        new = pat.sub(stamp, text)
        if new != text:
            with open(path, "w", encoding="utf-8", newline="\n") as f:
                f.write(new)


def main():
    build_home()
    build_about()
    build_services_overview()
    for s in SERVICES:
        detail_page("service", s, SERVICES)
    for a in AREAS:
        detail_page("area", a, AREAS)
    build_faq()
    build_insights()
    build_showcase()
    build_quiz()
    build_book()
    build_legal()
    build_404()
    build_article_shell()
    build_meta_files()
    version_assets()
    bad = check_links()
    print(f"{len(PAGES)} pages built into {OUT}")
    if bad:
        print("BROKEN LINKS:", bad)
        sys.exit(1)
    print("link check ok")


if __name__ == "__main__":
    main()
