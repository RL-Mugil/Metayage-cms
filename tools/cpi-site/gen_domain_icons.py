"""Writes the per-domain icon CSS (one outline icon per technology domain) and prints it to stdout.

Run:  python tools/cpi-site/gen_domain_icons.py >> marketing-canpatentit/assets/pages.css   (one-off)
Icons are 24x24 outline paths used as CSS masks, so they take the colour of the element.
"""
ICONS = {
    "default": "M3 8h18v12H3zM9 8V5h6v3M3 13h18",
    "ai": "M7 7h10v10H7zM10 3v4M14 3v4M10 17v4M14 17v4M3 10h4M3 14h4M17 10h4M17 14h4",
    "telecom": "M12 12v9M8 21h8M12 10a2 2 0 1 0 0 .01M7.8 7.8a5.9 5.9 0 0 0 0 8.4M16.2 7.8a5.9 5.9 0 0 1 0 8.4M4.9 4.9a10 10 0 0 0 0 14.2M19.1 4.9a10 10 0 0 1 0 14.2",
    "ev": "M13 2 4 14h7l-1 8 9-12h-7z",
    "health": "M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 8v8M8 12h8",
    "crypto": "M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4M12 15v2",
    "xr": "M12 2l9 5v10l-9 5-9-5V7zM12 12l9-5M12 12v10M12 12 3 7",
    "chain": "M3 4h7v7H3zM14 13h7v7h-7zM10 8h4a3 3 0 0 1 3 3v2M14 16h-4a3 3 0 0 1-3-3v-2",
    "factory": "M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2",
    "bi": "M4 20V10M10 20V4M16 20v-7M22 20H2",
    "network": "M12 3a2 2 0 1 0 0 .01M5 19a2 2 0 1 0 0 .01M19 19a2 2 0 1 0 0 .01M12 5v6M12 11l-6 6M12 11l6 6",
    "food": "M5 8h12v6a6 6 0 0 1-12 0zM17 9h2a2 2 0 0 1 0 5h-2M8 3v2M12 3v2M3 21h16",
    "aero": "M2 14l9-3V4a1 1 0 0 1 2 0v7l9 3v2l-9-1-1 5 3 1v1l-4-1-4 1v-1l3-1-1-5-9 1z",
    "bio": "M9 3h6M10 3v6L4 19a2 2 0 0 0 2 3h12a2 2 0 0 0 2-3l-6-10V3M7 15h10",
    "polymer": "M12 2l8 5v10l-8 5-8-5V7zM12 7l4 2.5v5L12 17l-4-2.5v-5z",
}


def uri(path):
    return ("url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='none' stroke='%23000' "
            "stroke-width='2' stroke-linecap='round' stroke-linejoin='round' d='" + path + "'/%3E%3C/svg%3E\")")


out = ["", "/* Per-domain icons for patent cards (grid and Recommended Patents) */",
       ":root { --dom-icon: " + uri(ICONS["default"]) + "; }"]
for key, path in ICONS.items():
    if key != "default":
        out.append(".dom-%s { --dom-icon: %s; }" % (key, uri(path)))
out += [
    ".cpi-pcard-domain::before, .cpi-rcard-domain::before { width: 20px; height: 20px; border-radius: 0; -webkit-mask: var(--dom-icon) center / contain no-repeat; mask: var(--dom-icon) center / contain no-repeat; }",
    ".cpi-pcard-domain::before { background: var(--cpi-blue); }",
    ".cpi-rcard-domain::before { background: var(--cpi-green); width: 24px; height: 24px; }",
]
print("\n".join(out))
