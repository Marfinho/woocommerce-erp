#!/usr/bin/env python3
"""Erzeugt die Demo-Modelle des Konfigurators.

Diese Datei gehoert **nicht** zum Plugin-Betrieb. Sie ist die Herkunft der
drei .glb-Dateien daneben: Ohne sie waeren das drei Binaerdateien, die
niemand mehr aendern kann. Aufruf:

    python3 erzeugen.py

Die Modelle sind bewusst grob - ein Basismodul als Schiene, ein Haken und
eine Ablage. Sie zeigen, dass Massstab, Anordnung und Sperrbereiche
zusammenpassen; sie zeigen nicht den Haster Haken.

Einheit ist Meter (glTF-Konvention), Y zeigt nach oben, der Ursprung liegt
an der Unterkante. Die Ansicht skaliert ohnehin auf die gepflegte Hoehe,
aber ein Modell, das schon von sich aus stimmt, laesst Fehler auffallen.
"""

import json
import math
import struct
from pathlib import Path

MM = 0.001


def box(x0, x1, y0, y1, z0, z1):
    """Ein Quader mit harten Kanten: je Flaeche eigene Eckpunkte."""
    ecken = {
        # (Normale, vier Punkte gegen den Uhrzeigersinn von aussen)
        (0, 0, 1): [(x0, y0, z1), (x1, y0, z1), (x1, y1, z1), (x0, y1, z1)],
        (0, 0, -1): [(x1, y0, z0), (x0, y0, z0), (x0, y1, z0), (x1, y1, z0)],
        (1, 0, 0): [(x1, y0, z1), (x1, y0, z0), (x1, y1, z0), (x1, y1, z1)],
        (-1, 0, 0): [(x0, y0, z0), (x0, y0, z1), (x0, y1, z1), (x0, y1, z0)],
        (0, 1, 0): [(x0, y1, z1), (x1, y1, z1), (x1, y1, z0), (x0, y1, z0)],
        (0, -1, 0): [(x0, y0, z0), (x1, y0, z0), (x1, y0, z1), (x0, y0, z1)],
    }

    punkte, normalen, indizes = [], [], []

    for normale, viereck in ecken.items():
        basis = len(punkte)
        punkte.extend(viereck)
        normalen.extend([normale] * 4)
        indizes.extend([basis, basis + 1, basis + 2, basis, basis + 2, basis + 3])

    return punkte, normalen, indizes


def rohr(pfad, radius, segmente=14):
    """Ein Rohr entlang eines Streckenzugs - daraus wird der Haken."""
    punkte, normalen, indizes = [], [], []
    ringe = []

    for i, mitte in enumerate(pfad):
        # Richtung aus Vorgaenger und Nachfolger: an den Enden einseitig.
        vorher = pfad[max(i - 1, 0)]
        nachher = pfad[min(i + 1, len(pfad) - 1)]
        richtung = [nachher[k] - vorher[k] for k in range(3)]
        laenge = math.sqrt(sum(k * k for k in richtung)) or 1.0
        richtung = [k / laenge for k in richtung]

        # Ein Quervektor, der nie parallel zur Richtung liegt.
        hilf = (1.0, 0.0, 0.0) if abs(richtung[0]) < 0.9 else (0.0, 1.0, 0.0)
        quer = [
            richtung[1] * hilf[2] - richtung[2] * hilf[1],
            richtung[2] * hilf[0] - richtung[0] * hilf[2],
            richtung[0] * hilf[1] - richtung[1] * hilf[0],
        ]
        laenge = math.sqrt(sum(k * k for k in quer)) or 1.0
        quer = [k / laenge for k in quer]

        hoch = [
            richtung[1] * quer[2] - richtung[2] * quer[1],
            richtung[2] * quer[0] - richtung[0] * quer[2],
            richtung[0] * quer[1] - richtung[1] * quer[0],
        ]

        ring = []
        for s in range(segmente):
            winkel = 2 * math.pi * s / segmente
            normale = [quer[k] * math.cos(winkel) + hoch[k] * math.sin(winkel) for k in range(3)]
            ring.append(
                (
                    tuple(mitte[k] + normale[k] * radius for k in range(3)),
                    tuple(normale),
                )
            )
        ringe.append(ring)

    for ring in ringe:
        for stelle, normale in ring:
            punkte.append(stelle)
            normalen.append(normale)

    for i in range(len(ringe) - 1):
        for s in range(segmente):
            a = i * segmente + s
            b = i * segmente + (s + 1) % segmente
            c = a + segmente
            d = b + segmente
            indizes.extend([a, c, b, b, c, d])

    return punkte, normalen, indizes


def vereine(teile):
    """Mehrere Koerper zu einem Netz zusammenfassen."""
    punkte, normalen, indizes = [], [], []

    for p, n, i in teile:
        versatz = len(punkte)
        punkte.extend(p)
        normalen.extend(n)
        indizes.extend([k + versatz for k in i])

    return punkte, normalen, indizes


def schreibe_glb(pfad, netz, name):
    """Netz als binaeres glTF ablegen."""
    punkte, normalen, indizes = netz

    puffer = bytearray()

    def lege_ab(daten):
        while len(puffer) % 4:
            puffer.append(0)
        start = len(puffer)
        puffer.extend(daten)
        return start, len(daten)

    punkt_bytes = b"".join(struct.pack("<3f", *p) for p in punkte)
    normal_bytes = b"".join(struct.pack("<3f", *n) for n in normalen)
    index_bytes = b"".join(struct.pack("<I", i) for i in indizes)

    punkt_offset, punkt_laenge = lege_ab(punkt_bytes)
    normal_offset, normal_laenge = lege_ab(normal_bytes)
    index_offset, index_laenge = lege_ab(index_bytes)

    minimum = [min(p[k] for p in punkte) for k in range(3)]
    maximum = [max(p[k] for p in punkte) for k in range(3)]

    gltf = {
        "asset": {
            "version": "2.0",
            "generator": "Lindenweg16 Konfigurator - Demo-Modell",
        },
        "scene": 0,
        "scenes": [{"nodes": [0]}],
        "nodes": [{"mesh": 0, "name": name}],
        "meshes": [
            {
                "name": name,
                "primitives": [
                    {
                        "attributes": {"POSITION": 0, "NORMAL": 1},
                        "indices": 2,
                        "material": 0,
                    }
                ],
            }
        ],
        "materials": [
            {
                "name": "Kunststoff",
                "pbrMetallicRoughness": {
                    "baseColorFactor": [0.92, 0.92, 0.90, 1.0],
                    "metallicFactor": 0.0,
                    "roughnessFactor": 0.55,
                },
            }
        ],
        "buffers": [{"byteLength": len(puffer)}],
        "bufferViews": [
            {"buffer": 0, "byteOffset": punkt_offset, "byteLength": punkt_laenge, "target": 34962},
            {"buffer": 0, "byteOffset": normal_offset, "byteLength": normal_laenge, "target": 34962},
            {"buffer": 0, "byteOffset": index_offset, "byteLength": index_laenge, "target": 34963},
        ],
        "accessors": [
            {
                "bufferView": 0,
                "componentType": 5126,
                "count": len(punkte),
                "type": "VEC3",
                "min": minimum,
                "max": maximum,
            },
            {"bufferView": 1, "componentType": 5126, "count": len(normalen), "type": "VEC3"},
            {"bufferView": 2, "componentType": 5125, "count": len(indizes), "type": "SCALAR"},
        ],
    }

    json_bytes = json.dumps(gltf, separators=(",", ":")).encode("utf-8")
    json_bytes += b" " * ((4 - len(json_bytes) % 4) % 4)
    puffer.extend(b"\0" * ((4 - len(puffer) % 4) % 4))

    kopf = struct.pack("<III", 0x46546C67, 2, 12 + 8 + len(json_bytes) + 8 + len(puffer))
    inhalt = (
        kopf
        + struct.pack("<II", len(json_bytes), 0x4E4F534A)
        + json_bytes
        + struct.pack("<II", len(puffer), 0x004E4942)
        + bytes(puffer)
    )

    Path(pfad).write_bytes(inhalt)
    print(f"{pfad}: {len(inhalt)} Bytes, {len(indizes) // 3} Dreiecke")


def schiene():
    """Basismodul: 600 mm lang, 60 breit, 20 tief, mit zwei Schraubaugen."""
    teile = [
        # Ruecken an der Wand.
        box(-30 * MM, 30 * MM, 0, 600 * MM, 0, 8 * MM),
        # Vorderer Steg, an dem die Module sitzen.
        box(-12 * MM, 12 * MM, 0, 600 * MM, 8 * MM, 20 * MM),
    ]

    # Die Schraubaugen liegen in den Sperrbereichen - so ist im Bild zu
    # sehen, warum dort kein Modul sitzen darf.
    for mitte in (65 * MM, 525 * MM):
        teile.append(box(-22 * MM, 22 * MM, mitte - 22 * MM, mitte + 22 * MM, 4 * MM, 10 * MM))

    return vereine(teile)


def haken():
    """Modul: Platte mit ausschwingendem Arm, 80 mm hoch."""
    pfad = [
        (0, 20 * MM, 20 * MM),
        (0, 18 * MM, 40 * MM),
        (0, 14 * MM, 58 * MM),
        (0, 16 * MM, 72 * MM),
        (0, 26 * MM, 80 * MM),
        (0, 38 * MM, 78 * MM),
    ]

    return vereine(
        [
            box(-14 * MM, 14 * MM, 0, 80 * MM, 14 * MM, 22 * MM),
            rohr(pfad, 5 * MM),
        ]
    )


def keil(x0, x1, punkte_yz):
    """Ein Dreiecksprisma: drei Punkte in der YZ-Ebene, in X ausgezogen.

    Aus gestapelten Quadern wurde eine Treppe - von der Seite gesehen war
    das keine Stuetze, sondern eine Sitzreihe.
    """
    (ay, az), (by, bz), (cy, cz) = punkte_yz

    links = [(x0, ay, az), (x0, by, bz), (x0, cy, cz)]
    rechts = [(x1, ay, az), (x1, by, bz), (x1, cy, cz)]

    punkte, normalen, indizes = [], [], []

    def flaeche(ecken, normale):
        basis = len(punkte)
        punkte.extend(ecken)
        normalen.extend([normale] * len(ecken))
        if len(ecken) == 3:
            indizes.extend([basis, basis + 1, basis + 2])
        else:
            indizes.extend([basis, basis + 1, basis + 2, basis, basis + 2, basis + 3])

    flaeche(list(reversed(links)), (-1, 0, 0))
    flaeche(rechts, (1, 0, 0))

    # Die drei Seitenflaechen mit einer aus dem Kantenverlauf gerechneten
    # Normalen - so bleibt die Schraege glatt beleuchtet.
    for i in range(3):
        j = (i + 1) % 3
        kante = (punkte_yz[j][0] - punkte_yz[i][0], punkte_yz[j][1] - punkte_yz[i][1])
        laenge = math.sqrt(kante[0] ** 2 + kante[1] ** 2) or 1.0
        normale = (0.0, kante[1] / laenge, -kante[0] / laenge)
        flaeche([links[i], rechts[i], rechts[j], links[j]], normale)

    return punkte, normalen, indizes


def ablage():
    """Modul: Platte mit Boden, Aufkantung und zwei Stuetzen, 150 mm hoch."""
    teile = [
        box(-20 * MM, 20 * MM, 0, 150 * MM, 14 * MM, 22 * MM),
        # Boden.
        box(-45 * MM, 45 * MM, 138 * MM, 144 * MM, 22 * MM, 92 * MM),
        # Aufkantung vorn. Sie endet auf 150 mm, damit die Bauhoehe des
        # Modells genau der gepflegten Hoehe entspricht.
        box(-45 * MM, 45 * MM, 144 * MM, 150 * MM, 86 * MM, 92 * MM),
    ]

    # Zwei Stuetzen: von der Wand auf halber Hoehe schraeg zur Bodenkante.
    for x in (-42 * MM, 36 * MM):
        teile.append(
            keil(
                x,
                x + 6 * MM,
                (
                    (138 * MM, 22 * MM),
                    (78 * MM, 22 * MM),
                    (138 * MM, 86 * MM),
                ),
            )
        )

    return vereine(teile)


if __name__ == "__main__":
    hier = Path(__file__).parent

    schreibe_glb(hier / "schiene.glb", schiene(), "Basismodul (Demo)")
    schreibe_glb(hier / "haken.glb", haken(), "Haken (Demo)")
    schreibe_glb(hier / "ablage.glb", ablage(), "Ablage (Demo)")
