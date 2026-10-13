"""One-off: swap the original oklch palette for the Metayage brand palette (from metayage.com's app.css)."""
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ASSETS = os.path.join(ROOT, "marketing-canpatentit", "assets")

# Metayage: primary #1481c4, cyan #39c5f0, green #30b67d, yellow #ecb439, dark #212529 / #343a40, light #f8f9fa
MAP = [
    ("oklch(0.17 0.025 259)", "#212529"),      # ink / dark bands
    ("oklch(0.985 0.008 92)", "#f8f9fa"),      # paper
    ("oklch(0.55 0.19 253)", "#1481c4"),       # brand blue
    ("oklch(0.88 0.17 119)", "#39c5f0"),       # accent (was lime) -> brand cyan
    ("oklch(0.94 0.02 207)", "#e3f5fb"),       # mist
    ("oklch(0.26 0.06 262)", "#343a40"),       # hero gradient end
    ("oklch(0.42 0.12 262)", "#1b6a9c"),       # hero glow
    ("oklch(0.72 0.15 253)", "#5ab4e6"),       # blue text on dark theme
    ("oklch(0.94 0.012 92)", "#f1f3f5"),       # dark theme ink
    ("oklch(0.2 0.02 259)", "#1b1f23"),        # dark theme paper
    ("oklch(0.25 0.03 230)", "#1f2d35"),       # dark theme mist
    ("oklch(0.97 0.006 92)", "#f1f3f5"),       # admin background
    ("oklch(0.85 0.14 70)", "#ecb439"),        # warn tag -> brand yellow
]

for name in ("site.css", "pages.css", "admin.css"):
    p = os.path.join(ASSETS, name)
    s = open(p, encoding="utf-8").read()
    for a, b in MAP:
        s = s.replace(a, b)
    open(p, "w", encoding="utf-8", newline="\n").write(s)
    print(name, "remaining oklch:", sorted(set(re.findall(r"oklch\([^)]*\)", s))))
