#!/usr/bin/env python3
"""Abschlussprüfung des Block-Themes Lindenweg16.

Aufruf: python3 block-theme/check-theme.py [pfad/zum/theme.json-schema]
Ohne Schema-Pfad wird nur die JSON-Syntax geprüft.
"""
import json, pathlib, re, subprocess, sys

root = pathlib.Path(__file__).resolve().parent / "lindenweg16"
fails = []

def fail(msg):
    fails.append(msg)
    print("FEHLER:", msg)

# 1. theme.json
data = json.loads((root / "theme.json").read_text())
assert data["version"] == 3
if len(sys.argv) > 1:
    import jsonschema
    schema = json.loads(pathlib.Path(sys.argv[1]).read_text())
    errs = list(jsonschema.Draft7Validator(schema).iter_errors(data))
    for e in errs:
        fail(f"theme.json {list(e.path)}: {e.message[:200]}")
    print(f"theme.json gegen Schema: {len(errs)} Fehler")

slugs = [c["slug"] for c in data["settings"]["color"]["palette"]]
expected = ["background", "surface", "surface-muted", "text", "text-muted", "line", "primary", "on-primary",
            "accent-1", "accent-2", "accent-3", "accent-4", "on-accent", "sale", "success", "warning", "error", "ink", "on-ink"]
if slugs != expected:
    fail(f"Palette weicht ab: {slugs}")

# 2. PHP-Syntax
for f in sorted(root.rglob("*.php")):
    r = subprocess.run(["php", "-l", str(f)], capture_output=True, text=True)
    if r.returncode != 0:
        fail(f"PHP-Syntax {f.relative_to(root)}: {r.stdout.strip()}")

# 3. Pattern-Header
for f in sorted((root / "patterns").glob("*.php")):
    head = f.read_text().split("*/", 1)[0]
    title = re.search(r"^\s*\*\s*Title:\s*(.+)$", head, re.M)
    slug = re.search(r"^\s*\*\s*Slug:\s*(\S+)$", head, re.M)
    if not title or not slug:
        fail(f"Pattern-Header unvollständig: {f.name}")
        continue
    if not slug.group(1).startswith("lindenweg16/"):
        fail(f"Pattern-Slug ohne Präfix: {f.name}")
    hidden = re.search(r"^\s*\*\s*Inserter:\s*(no|false)\s*$", head, re.M)
    cats = re.search(r"^\s*\*\s*Categories:\s*(.+)$", head, re.M)
    if not hidden and (not cats or "lindenweg16" not in cats.group(1)):
        fail(f"Sichtbares Pattern ohne Kategorie lindenweg16: {f.name}")

# 4. Templates und ihre WooCommerce-Blöcke
need = {
    "archive-product": ["woocommerce/product-collection", '"inherit":true', "woocommerce/product-template", "woocommerce/catalog-sorting", "woocommerce/store-notices", "lindenweg16/hidden-shop-filters"],
    "taxonomy-product_cat": ["woocommerce/product-collection", '"inherit":true', "woocommerce/product-template"],
    "taxonomy-product_tag": ["woocommerce/product-collection", '"inherit":true', "woocommerce/product-template"],
    "product-search-results": ["woocommerce/product-collection", '"inherit":true', "woocommerce/product-template"],
    "single-product": ["woocommerce/product-image-gallery", "woocommerce/product-price", "woocommerce/add-to-cart-with-options", "woocommerce/product-details", "woocommerce/breadcrumbs"],
    "page-cart": ["woocommerce/cart", "woocommerce/filled-cart-block", "woocommerce/empty-cart-block", "woocommerce/proceed-to-checkout-block"],
    "page-checkout": ["woocommerce/checkout", "woocommerce/checkout-terms-block", "woocommerce/checkout-actions-block", "Zahlungspflichtig bestellen", "woocommerce/checkout-order-summary-block"],
    "order-confirmation": ["woocommerce/order-confirmation-status", "woocommerce/order-confirmation-summary", "woocommerce/order-confirmation-totals"],
}
required = ["index", "front-page", "home", "single", "page", "archive", "search", "404", *need.keys()]
for name in required:
    f = root / "templates" / f"{name}.html"
    if not f.exists():
        fail(f"Template fehlt: {name}")
        continue
    t = f.read_text()
    for token in need.get(name, []):
        if token not in t:
            fail(f"{name}.html: {token} fehlt")
    if 'wp:template-part {"slug":"header"' not in t or 'wp:template-part {"slug":"footer"' not in t:
        fail(f"{name}.html: Header oder Footer fehlt")
    # Block-Kommentare ausgeglichen?
    opens = re.findall(r"<!-- wp:([a-z0-9/-]+)(?: [^>]*?)?(?<!/)-->", t)
    closes = re.findall(r"<!-- /wp:([a-z0-9/-]+) -->", t)
    if sorted(opens) != sorted(closes):
        fail(f"{name}.html: Block-Kommentare nicht ausgeglichen")
for part in ["header", "footer", "mini-cart"]:
    if not (root / "parts" / f"{part}.html").exists():
        fail(f"Part fehlt: {part}")

# 5. Keine festen Farbwerte im CSS (außer im auskommentierten Dark-Mode-Block)
css = (root / "assets/css/theme.css").read_text()
css_no_comments = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
bad = re.findall(r"#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(", css_no_comments)
named = re.findall(r":\s*(?:white|black|red|green|blue|gray|grey)\b", css_no_comments)
if bad or named:
    fail(f"Feste Farbwerte im CSS: {bad[:5]} {named[:5]}")
for f in list((root / "templates").glob("*.html")) + list((root / "patterns").glob("*.php")) + list((root / "parts").glob("*.html")):
    t = f.read_text()
    t = t.replace("rgba(0, 0, 0, 0)", "")  # Markierungsformat „Hervorheben“ (transparent, vom Editor erzeugt)
    if re.search(r"#[0-9a-fA-F]{6}\b|\brgba?\(", t):
        fail(f"Feste Farbe in {f.name}")

# 6. Kein jQuery
for f in list(root.rglob("*.js")) + list(root.rglob("*.php")):
    if re.search(r"jQuery\s*[(.]|\$\(|['\"]jquery['\"]", f.read_text()):
        fail(f"jQuery-Verweis in {f.relative_to(root)}")

# 7. Schriften lokal, keine externen Anfragen
if "fonts.googleapis" in css or "fonts.gstatic" in css or any("googleapis" in f.read_text() for f in root.rglob("*.php")):
    fail("Externe Schriftquelle gefunden")
for ff in data["settings"]["typography"]["fontFamilies"]:
    for face in ff["fontFace"]:
        for src in face["src"]:
            if not (root / src.replace("file:./", "")).exists():
                fail(f"Schriftdatei fehlt: {src}")

print("OK – keine Fehler." if not fails else f"{len(fails)} Fehler.")
sys.exit(1 if fails else 0)
