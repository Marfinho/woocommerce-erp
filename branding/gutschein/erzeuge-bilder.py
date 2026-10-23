#!/usr/bin/env python3
"""Zeichnet die festen Markenteile der Gutschein-PDFs (Wortmarke, Titel) mit
Bricolage Grotesque und legt sie als JPEG unter assets/gutschein/ ab.

Der PDF-Generator des Plugins kennt nur Helvetica; Wortmarke und Titel sind
deshalb Bilder, alles Veränderliche (Namen, Text, Betrag, Code) ist Text.
Aufruf aus der Repo-Wurzel:  python3 branding/gutschein/erzeuge-bilder.py
Braucht Node mit Playwright, Chromium und Pillow (Pfade oben anpassen).
"""
import os, subprocess, tempfile
from PIL import Image

WURZEL = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
FONT = os.path.join(WURZEL, 'block-theme/lindenweg16/assets/fonts/bricolage-grotesque-latin-wght-normal.woff2')
AUS = os.path.join(WURZEL, 'assets/gutschein')
CHROMIUM = '/opt/pw-browsers/chromium'
PLAYWRIGHT = '/opt/node22/lib/node_modules/playwright'

# Kennung: Hintergrund, Schrift, Akzent der „16“, Tagline
VARIANTEN = {
    'hell':   ('#EDEBE9', '#161616', '#EE1C4F', '#6b6b6b'),
    'pink':   ('#EE1C4F', '#FFFFFF', '#161616', '#FFFFFF'),
    'dunkel': ('#161616', '#FFFFFF', '#EE1C4F', '#a8a8a8'),
}

def bild(name, html, breite, hoehe, bg):
    with tempfile.TemporaryDirectory() as t:
        h = os.path.join(t, 'a.html')
        png = os.path.join(t, 'a.png')
        open(h, 'w', encoding='utf-8').write(
            '<meta charset=utf-8><style>@font-face{font-family:B;src:url("file://%s");font-weight:200 800}'
            'html,body{margin:0;background:%s}body{width:%dpx;height:%dpx;font-family:B,sans-serif;overflow:hidden}</style>%s'
            % (FONT, bg, breite, hoehe, html))
        js = os.path.join(t, 's.js')
        open(js, 'w').write(
            "const {chromium}=require(process.env.PW);(async()=>{const b=await chromium.launch({executablePath:process.env.CH,args:['--no-sandbox']});"
            "const p=await b.newPage({viewport:{width:%d,height:%d},deviceScaleFactor:3});await p.goto('file://%s');"
            "await p.evaluate(()=>document.fonts.ready);await p.screenshot({path:'%s'});await b.close()})()" % (breite, hoehe, h, png))
        subprocess.run(['node', js], check=True, env=dict(os.environ, PW=PLAYWRIGHT, CH=CHROMIUM))
        # Flächen nahe dem Hintergrund auf genau dessen Wert setzen, damit im PDF
        # kein Rechteck hinter dem Bild durchschimmert.
        ziel = tuple(int(bg[i:i + 2], 16) for i in (1, 3, 5))
        im = Image.open(png).convert('RGB')
        px = im.load()
        for y in range(im.height):
            for x in range(im.width):
                if max(abs(px[x, y][c] - ziel[c]) for c in range(3)) <= 14:
                    px[x, y] = ziel
        im.save(os.path.join(AUS, name + '.jpg'), quality=100, subsampling=0)

os.makedirs(AUS, exist_ok=True)
for k, (bg, fg, acc, tag) in VARIANTEN.items():
    bild('marke-' + k,
         '<div style="font-weight:800;font-size:34px;letter-spacing:-.045em;line-height:1;color:%s">Lindenweg<span style="color:%s">16</span></div>'
         '<div style="margin-top:12px;font-weight:600;font-size:9.5px;letter-spacing:.3em;color:%s">HANDGEMACHT IN HASTE</div>' % (fg, acc, tag),
         260, 66, bg)
    bild('titel-' + k,
         '<div style="font-weight:800;font-size:64px;letter-spacing:-.05em;line-height:1;color:%s;padding-top:6px">Gutschein.</div>' % fg,
         330, 80, bg)
print('fertig')
