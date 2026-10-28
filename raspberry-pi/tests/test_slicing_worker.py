"""Tests der Plattenprüfung vor dem Slicen (check_plate_layout).

Aufruf im Ordner raspberry-pi:
    python3 -m pytest tests
"""

import json
import zipfile

import pytest

import slicing_worker as sw


def box(x0, y0, x1, y1, z=10.0, offset=0):
    """Quader als Punkte und Dreiecke (Dreiecksindizes ab offset)."""
    punkte = [(x0, y0, 0), (x1, y0, 0), (x1, y1, 0), (x0, y1, 0), (x0, y0, z), (x1, y0, z), (x1, y1, z), (x0, y1, z)]
    flaechen = [(0, 2, 1), (0, 3, 2), (4, 5, 6), (4, 6, 7), (0, 1, 5), (0, 5, 4), (1, 2, 6), (1, 6, 5), (2, 3, 7), (2, 7, 6), (3, 0, 4), (3, 4, 7)]
    return punkte, [(a + offset, b + offset, c + offset) for a, b, c in flaechen]


def t_shape():
    """Liegendes T wie der Brillenhalter: Steg 5 x 200, Arm 145 x 5 in der Mitte
    (Nullpunkt in der Mitte, wie in Bambu-Studio-Projekten)."""
    p1, d1 = box(-77.5, -100, -72.5, 100)
    p2, d2 = box(-72.5, -2.5, 77.5, 2.5, offset=len(p1))
    return p1 + p2, d1 + d2


def write_plate(path, items):
    """Platte im Aufbau von PlateComposer: Objekt mit Komponente, Netz in
    einer eigenen Datei (p:path), eine <item>-Platzierung je Kopie."""
    punkte, dreiecke = t_shape()
    verts = "".join(f'<vertex x="{x}" y="{y}" z="{z}"/>' for x, y, z in punkte)
    tris = "".join(f'<triangle v1="{a}" v2="{b}" v3="{c}"/>' for a, b, c in dreiecke)
    objekt = (
        '<?xml version="1.0" encoding="UTF-8"?><model unit="millimeter" '
        'xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02"><resources>'
        f'<object id="1" type="model"><mesh><vertices>{verts}</vertices><triangles>{tris}</triangles></mesh></object>'
        "</resources><build/></model>"
    )
    build = "".join(f'<item objectid="2" transform="{t}"/>' for t in items)
    haupt = (
        '<?xml version="1.0" encoding="UTF-8"?><model unit="millimeter" '
        'xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" '
        'xmlns:p="http://schemas.microsoft.com/3dmanufacturing/production/2015/06"><resources>'
        '<object id="2" type="model"><components><component p:path="/3D/Objects/object_1.model" objectid="1" transform="1 0 0 0 1 0 0 0 1 0 0 0"/></components></object>'
        f"</resources><build>{build}</build></model>"
    )
    with zipfile.ZipFile(path, "w") as zf:
        zf.writestr("3D/3dmodel.model", haupt)
        zf.writestr("3D/Objects/object_1.model", objekt)
    return path


@pytest.fixture
def printer(tmp_path):
    pfad = tmp_path / "printer.json"
    pfad.write_text(json.dumps({
        "printable_area": ["0x0", "256x0", "256x256", "0x256"],
        "bed_exclude_area": ["0x0", "256x0", "256x15", "18x15", "18x28", "0x28"],
    }))
    return pfad


# Zwei T ineinandergeschoben: das zweite um 180° gedreht, Arm 10 mm versetzt.
T1 = "1 0 0 0 1 0 0 0 1 100 130 10"
T2 = "-1 0 0 0 -1 0 0 0 1 120 140 10"


def test_ineinandergeschobene_t_formen_sind_gueltig(tmp_path, printer):
    # Genau der Fall, den OrcaSlicer mit --arrange 1 auseinanderriss: Die
    # konvexen Hüllen überschneiden sich, die echten Grundrisse nicht.
    assert sw.check_plate_layout(write_plate(tmp_path / "p.3mf", [T1, T2]), printer) == ""


def test_ueberschneidung_wird_erkannt(tmp_path, printer):
    meldung = sw.check_plate_layout(write_plate(tmp_path / "p.3mf", [T1, "1 0 0 0 1 0 0 0 1 102 130 10"]), printer)
    assert "überschneiden sich" in meldung


def test_teil_auf_der_kalibrierlinie_wird_erkannt(tmp_path, printer):
    # Steg reicht bis y = 110 - 100 = 10, also in den vorderen Streifen.
    meldung = sw.check_plate_layout(write_plate(tmp_path / "p.3mf", ["1 0 0 0 1 0 0 0 1 100 110 10"]), printer)
    assert "Sperrfläche" in meldung


def test_teil_neben_dem_bett_wird_erkannt(tmp_path, printer):
    meldung = sw.check_plate_layout(write_plate(tmp_path / "p.3mf", ["1 0 0 0 1 0 0 0 1 200 130 10"]), printer)
    assert "über das Druckbett" in meldung


def test_nicht_auswertbare_datei_blockiert_nicht(tmp_path, printer):
    kaputt = tmp_path / "k.3mf"
    kaputt.write_bytes(b"kein zip")
    assert sw.check_plate_layout(kaputt, printer) == ""


def test_transformationen_werden_verkettet():
    # Erst 90° drehen, dann verschieben.
    dreh = [0, 1, 0, -1, 0, 0, 0, 0, 1, 0, 0, 0]
    schieb = [1, 0, 0, 0, 1, 0, 0, 0, 1, 10, 20, 0]
    t = sw.combine_transforms(dreh, schieb)
    x, y = 5.0, 0.0
    assert (x * t[0] + y * t[3] + t[9], x * t[1] + y * t[4] + t[10]) == (10.0, 25.0)


def test_worker_ordnet_nicht_mehr_selbst_an():
    assert "--arrange 0" in sw.DEFAULT_SLICER_ARGS_TEMPLATE
    assert "--arrange 1" not in sw.DEFAULT_SLICER_ARGS_TEMPLATE
