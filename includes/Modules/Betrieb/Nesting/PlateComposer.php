<?php
/**
 * Zusammensetzen mehrerer Bauteile zu einer gemeinsamen (ungeslicten)
 * Projekt-3MF, nach den Platzierungen aus BinPacker::arrange().
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ThreeMf;

/**
 * Reine ZIP-/XML-Arbeit, kein Slicing-Wissen: Aus mehreren Quell-3MF wird
 * eine einzige 3MF mit mehreren <object>-Ressourcen und einem <build> voller
 * <item>-Platzierungen (Transformationsmatrix je Kopie).
 *
 * Grenzen (bewusst, siehe MeshOutline für dieselbe Einschränkung): Es wird
 * je Quelldatei nur das ERSTE <object> übernommen. Verweist es per
 * <components> auf andere Objekte statt einen eigenen <mesh>-Block zu
 * haben (Bambu-Studio-Production-Extension, Geometrie in einer eigenen
 * Datei innerhalb der 3MF ausgelagert – siehe MeshOutline), wird daraus
 * ein flaches Objekt mit eingebetteter, transformierter Geometrie gebaut
 * (flatten_components(), eine Ebene tief). Bleibt danach nichts übrig
 * (Verweis fehlt oder ist seinerseits wieder nur eine Verschachtelung),
 * bricht compose() mit einer verständlichen Fehlermeldung ab, statt eine
 * unvollständige 3MF zu erzeugen.
 *
 * Nullpunkt-Versatz (X/Y UND Z): Der Nullpunkt eines Objekts liegt nicht
 * zwangsläufig an seiner Unterseite bzw. der Ecke seiner Bounding-Box -
 * manche Exporte legen ihn ins geometrische Zentrum oder sonst wohin.
 * BinPacker::arrange() normiert jede Hülle intern auf die eigene
 * Bounding-Box-Ecke (0,0), bevor es X/Y-Positionen berechnet - eine
 * zurückgegebene Platzierung {x,y} gilt also für diese normierte Hülle,
 * nicht fürs rohe Mesh. Würde man {x,y} unverändert aufs rohe Mesh
 * anwenden, läge jedes Bauteil um genau den Versatz seines eigenen
 * Nullpunkts daneben - bei genug Versatz sogar ausserhalb der Platte.
 * Für Z gilt dieselbe Überlegung, nur ohne Gegenstück aus BinPacker (das
 * nur in X/Y anordnet): der tiefste Punkt jedes Objekts muss auf Z=0
 * gelegt werden, sonst schwebt das Teil über der Druckplatte oder steckt
 * darunter. compose() berechnet all das selbst aus der Geometrie (siehe
 * mesh_bounds_min()) und rechnet es in transform_matrix() heraus, bevor
 * die BinPacker-Platzierung angewandt wird.
 */
class PlateComposer {

	/**
	 * 3MF-Transformationsmatrix (12 Zahlen, Zeilen zuerst): erst das rohe
	 * Mesh um seinen eigenen Bounding-Box-Nullpunkt ($ox, $oy) verschieben
	 * (siehe Klassendoku "Nullpunkt-Versatz"), dann um die Z-Achse drehen,
	 * dann auf die BinPacker-Position ($tx, $ty) plus Z-Anhebung ($tz)
	 * bringen. Mit $ox = $oy = 0 (Standard) entspricht das genau der
	 * bisherigen reinen Rotation+Verschiebung.
	 *
	 * Reine Rechnung, keine Datei-/Zip-Arbeit – deshalb separat und ohne
	 * WordPress-Testsuite prüfbar. Die Drehrichtung entspricht
	 * Polygon::rotate() (Gegenuhrzeigersinn), damit eine BinPacker-
	 * Platzierung unverändert hier eingesetzt werden kann. Da nur um die
	 * Z-Achse gedreht wird, ändert die Rotation den Z-Bereich der Punkte
	 * nicht – $tz gilt deshalb unabhängig vom Rotationswinkel, unabhängig
	 * von $ox/$oy.
	 *
	 * @param float $rotation_deg Winkel um die Z-Achse, Gegenuhrzeigersinn.
	 * @param float $tx           Zielposition X (mm), von BinPacker.
	 * @param float $ty           Zielposition Y (mm), von BinPacker.
	 * @param float $tz           Verschiebung Z (mm), Standard 0.
	 * @param float $ox           Eigener Bounding-Box-Nullpunkt X des rohen Mesh, Standard 0.
	 * @param float $oy           Eigener Bounding-Box-Nullpunkt Y des rohen Mesh, Standard 0.
	 * @return string Die 12 Matrixzahlen, leerzeichengetrennt.
	 */
	public static function transform_matrix( $rotation_deg, $tx, $ty, $tz = 0, $ox = 0, $oy = 0 ) {
		$rad = deg2rad( (float) $rotation_deg );
		$cos = cos( $rad );
		$sin = sin( $rad );

		$ox = (float) $ox;
		$oy = (float) $oy;

		// Erst ($ox,$oy) herausrechnen (Punkt minus eigener Nullpunkt),
		// dann drehen: rotierter Punkt = R·(p - o) = R·p - R·o. Die
		// Translation der Matrix trägt deshalb -R·o zusätzlich zum
		// Zielpunkt.
		$rotated_ox = $ox * $cos - $oy * $sin;
		$rotated_oy = $ox * $sin + $oy * $cos;

		$numbers = array( $cos, $sin, 0, -$sin, $cos, 0, 0, 0, 1, (float) $tx - $rotated_ox, (float) $ty - $rotated_oy, (float) $tz );

		return implode(
			' ',
			array_map(
				// Bis zu 6 Nachkommastellen, ohne unnötige Nullen - genug
				// Präzision für den Slicer, ohne die Datei mit
				// Fliesskomma-Rauschen aufzublähen (siehe format_number()).
				array( self::class, 'format_number' ),
				$numbers
			)
		);
	}

	/**
	 * Mehrere Quell-3MF zu einer gemeinsamen Projekt-3MF zusammensetzen.
	 *
	 * @param array  $placements  Liste {source_path, x, y, rotation_deg}.
	 *                             source_path = absoluter Pfad der Quell-3MF
	 *                             (eine Datei darf mehrfach vorkommen, für
	 *                             mehrere Kopien desselben Bauteils).
	 * @param string $output_path Zielpfad der zusammengesetzten 3MF.
	 * @return array {ok, error}
	 */
	public static function compose( array $placements, $output_path ) {
		if ( ! $placements ) {
			return array( 'ok' => false, 'error' => __( 'Keine Platzierungen übergeben.', 'lindenweg16' ) );
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			return array( 'ok' => false, 'error' => __( 'PHP-Erweiterung ZipArchive fehlt.', 'lindenweg16' ) );
		}

		$base_path = (string) $placements[0]['source_path'];

		$base_zip = new \ZipArchive();
		if ( true !== $base_zip->open( $base_path ) ) {
			return array( 'ok' => false, 'error' => __( 'Basis-3MF konnte nicht geöffnet werden.', 'lindenweg16' ) );
		}

		$base_model_xml = $base_zip->getFromName( ThreeMf::MODEL_PATH );
		if ( ! is_string( $base_model_xml ) ) {
			$base_zip->close();
			return array( 'ok' => false, 'error' => __( 'Basis-3MF enthält kein 3dmodel.model.', 'lindenweg16' ) );
		}

		$doc = new \DOMDocument();
		$doc->loadXML( $base_model_xml, LIBXML_NONET );

		$resources = self::first_child( $doc->documentElement, 'resources' );
		$build     = self::first_child( $doc->documentElement, 'build' );

		if ( ! $resources || ! $build ) {
			$base_zip->close();
			return array( 'ok' => false, 'error' => __( 'Basis-3MF hat keine gültige 3MF-Struktur (resources/build fehlt).', 'lindenweg16' ) );
		}

		// Von den vorhandenen Objekten nur das erste behalten (siehe
		// Klassendoku) – alle anderen (z. B. schon vorhandene <item>s einer
		// bereits geslicten Datei) werden entfernt, bevor neue dazukommen.
		$objects = array();
		foreach ( iterator_to_array( $resources->childNodes ) as $child ) {
			if ( $child instanceof \DOMElement && 'object' === $child->localName ) {
				$objects[] = $child;
			}
		}

		if ( ! $objects ) {
			$base_zip->close();
			return array( 'ok' => false, 'error' => __( 'Basis-3MF enthält kein Bauteil (keine <object>-Ressource).', 'lindenweg16' ) );
		}

		$base_object = $objects[0];

		if ( self::has_components( $base_object ) ) {
			$flattened = self::flatten_components( $doc, $base_object, $base_zip );

			if ( ! $flattened ) {
				$base_zip->close();
				return array( 'ok' => false, 'error' => __( 'Diese Projektdatei ist eine Unterbaugruppe ohne auflösbare Geometrie (verweist per <components> auf ein Objekt, das nicht gefunden wurde oder selbst wieder nur verschachtelt ist) – das unterstützt die Plattenanordnung nicht.', 'lindenweg16' ) );
			}

			$base_object = $flattened;
		}

		foreach ( $objects as $obj ) {
			$resources->removeChild( $obj );
		}
		foreach ( iterator_to_array( $build->childNodes ) as $item ) {
			$build->removeChild( $item );
		}

		$resources->appendChild( $base_object );

		$next_id = self::max_resource_id( $doc ) + 1;

		// path => neu vergebene Objekt-ID bzw. eigener Nullpunkt-Versatz
		// (siehe Klassendoku) im zusammengesetzten Dokument.
		$object_id_by_path               = array();
		$bounds_by_path                  = array();
		$object_id_by_path[ $base_path ] = $base_object->getAttribute( 'id' );
		$bounds_by_path[ $base_path ]    = self::mesh_bounds_min( $base_object );
		$rotated_min                     = array();

		foreach ( $placements as $placement ) {
			$path = (string) $placement['source_path'];

			if ( ! isset( $object_id_by_path[ $path ] ) ) {
				$imported = self::import_object( $doc, $resources, $path, $next_id );

				if ( ! $imported['ok'] ) {
					$base_zip->close();
					return array( 'ok' => false, 'error' => $imported['error'] );
				}

				$object_id_by_path[ $path ] = $imported['object_id'];
				$bounds_by_path[ $path ]    = $imported['bounds'];
				$next_id                    = $imported['object_id'] + 1;
			}

			$bounds   = $bounds_by_path[ $path ];
			$rotation = isset( $placement['rotation_deg'] ) ? $placement['rotation_deg'] : 0;
			$rot_key  = $path . '|' . $rotation;

			if ( ! isset( $rotated_min[ $rot_key ] ) ) {
				$rotated_min[ $rot_key ] = self::rotated_min( isset( $bounds['points'] ) ? $bounds['points'] : array(), $bounds['min_x'], $bounds['min_y'], $rotation );
			}

			$item = $doc->createElementNS( $doc->documentElement->namespaceURI ?: null, 'item' );
			$item->setAttribute( 'objectid', $object_id_by_path[ $path ] );
			$item->setAttribute(
				'transform',
				self::transform_matrix(
					$rotation,
					$placement['x'] - $rotated_min[ $rot_key ][0],
					$placement['y'] - $rotated_min[ $rot_key ][1],
					-$bounds['min_z'],
					$bounds['min_x'],
					$bounds['min_y']
				)
			);
			$build->appendChild( $item );
		}

		$new_model_xml = $doc->saveXML();

		$out = new \ZipArchive();
		if ( true !== $out->open( $output_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			$base_zip->close();
			return array( 'ok' => false, 'error' => __( 'Zieldatei konnte nicht angelegt werden.', 'lindenweg16' ) );
		}

		// Alle Nicht-Modell-Einträge des Basis-Containers unverändert
		// übernehmen ([Content_Types].xml, _rels, Bambu-Projektmetadaten,
		// ggf. Vorschaubild) – dieselbe grundsätzliche Struktur, wie Bambu
		// Studio sie erzeugt, muss nicht neu erfunden werden. Ausgenommen
		// sind die Slicing-ERGEBNISSE der Quelldatei (siehe
		// is_stale_slicing_entry()): Die gehören zum einzelnen Bauteil,
		// nicht zur neuen, noch ungeslicten Platte.
		for ( $i = 0; $i < $base_zip->numFiles; $i++ ) {
			$name = $base_zip->getNameIndex( $i );
			if ( ThreeMf::MODEL_PATH === $name || self::is_stale_slicing_entry( $name ) ) {
				continue;
			}
			$out->addFromString( $name, $base_zip->getFromIndex( $i ) );
		}

		$out->addFromString( ThreeMf::MODEL_PATH, $new_model_xml );

		$base_zip->close();
		$out->close();

		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * Ist dieser ZIP-Eintrag ein Slicing-Ergebnis der Quelldatei, das
	 * nicht in die zusammengesetzte Platte gehört?
	 *
	 * Eine als Artikeldatei hinterlegte Bambu-Studio-Projektdatei war
	 * oft schon einmal geslict und trägt dann den fertigen G-Code des
	 * EINZELNEN Bauteils samt Auswertung mit sich (Metadata/plate_1.gcode,
	 * .gcode.md5, plate_1.json, slice_info.config). Würden diese Einträge
	 * in die Platte übernommen, gälte sie für Bambu Studio beim manuellen
	 * Slicen als "bereits geslict" - ein Klick auf "Drucken" schickte dann
	 * den alten Ein-Teil-G-Code statt der ganzen Platte an den Drucker.
	 * Auch die Slicer-Zahlen (Druckzeit, Gewicht) in slice_info.config
	 * beschrieben ein anderes Objekt. Vorschaubilder bleiben erhalten,
	 * die schreibt der Slicer beim Export ohnehin neu.
	 *
	 * @param string $name Pfad des ZIP-Eintrags.
	 * @return bool
	 */
	public static function is_stale_slicing_entry( $name ) {
		$name = ltrim( (string) $name, '/' );

		if ( ThreeMf::SLICE_INFO === $name ) {
			return true;
		}

		if ( 0 !== strpos( $name, 'Metadata/' ) ) {
			return false;
		}

		return (bool) preg_match( '~^Metadata/plate_\d+\.(gcode|gcode\.md5|json)$~', $name );
	}

	/**
	 * Erstes Kindelement mit diesem lokalen Namen.
	 *
	 * @param \DOMElement|null $parent Elternelement.
	 * @param string           $name   Gesuchter Elementname.
	 * @return \DOMElement|null
	 */
	protected static function first_child( $parent, $name ) {
		if ( ! $parent ) {
			return null;
		}
		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof \DOMElement && $name === $child->localName ) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Enthält ein <object> einen <components>-Verweis auf andere Objekte
	 * (Unterbaugruppe statt reinem Mesh-Körper)?
	 *
	 * @param \DOMElement $object <object>-Knoten.
	 * @return bool
	 */
	protected static function has_components( \DOMElement $object ) {
		foreach ( $object->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'components' === $child->localName ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Höchste bislang vergebene Ressourcen-ID im Dokument (0, wenn keine).
	 *
	 * @param \DOMDocument $doc Dokument.
	 * @return int
	 */
	protected static function max_resource_id( \DOMDocument $doc ) {
		$max = 0;
		foreach ( $doc->getElementsByTagName( 'object' ) as $object ) {
			$id = (int) $object->getAttribute( 'id' );
			if ( $id > $max ) {
				$max = $id;
			}
		}
		return $max;
	}

	/**
	 * Das erste <object> einer weiteren Quell-3MF ins Zieldokument
	 * importieren, mit einer neuen, kollisionsfreien ID.
	 *
	 * @param \DOMDocument $doc       Zieldokument.
	 * @param \DOMElement  $resources Ziel-<resources>.
	 * @param string       $path      Quelldatei.
	 * @param int          $new_id    Zu vergebende ID.
	 * @return array {ok, object_id, bounds, error}
	 */
	protected static function import_object( \DOMDocument $doc, \DOMElement $resources, $path, $new_id ) {
		$nichts = array( 'min_x' => 0.0, 'min_y' => 0.0, 'min_z' => 0.0 );

		if ( ! class_exists( '\ZipArchive' ) ) {
			return array( 'ok' => false, 'object_id' => 0, 'bounds' => $nichts, 'error' => __( 'PHP-Erweiterung ZipArchive fehlt.', 'lindenweg16' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array( 'ok' => false, 'object_id' => 0, 'bounds' => $nichts, 'error' => __( 'Quell-3MF konnte nicht geöffnet werden.', 'lindenweg16' ) );
		}

		$xml = $zip->getFromName( ThreeMf::MODEL_PATH );

		if ( ! is_string( $xml ) ) {
			$zip->close();
			return array( 'ok' => false, 'object_id' => 0, 'bounds' => $nichts, 'error' => __( 'Quell-3MF enthält kein 3dmodel.model.', 'lindenweg16' ) );
		}

		$source_doc = new \DOMDocument();
		$source_doc->loadXML( $xml, LIBXML_NONET );

		$object = null;
		foreach ( $source_doc->getElementsByTagName( 'object' ) as $candidate ) {
			$object = $candidate;
			break;
		}

		if ( ! $object ) {
			$zip->close();
			return array( 'ok' => false, 'object_id' => 0, 'bounds' => $nichts, 'error' => __( 'Quell-3MF enthält kein Bauteil.', 'lindenweg16' ) );
		}

		if ( self::has_components( $object ) ) {
			// $zip bleibt bis hierhin offen - flatten_components() braucht
			// sie noch, um eine per p:path referenzierte Datei zu lesen
			// (vorher wurde $zip schon vor diesem Aufruf geschlossen, was
			// eine solche Datei bei jeder Nicht-Basis-Platzierung silent
			// unauflösbar gemacht hätte).
			$flattened = self::flatten_components( $doc, $object, $zip );

			if ( ! $flattened ) {
				$zip->close();
				return array(
					'ok'        => false,
					'object_id' => 0,
					'bounds'    => $nichts,
					'error'     => __( 'Eine der Projektdateien ist eine Unterbaugruppe ohne auflösbare Geometrie (verweist per <components> auf ein Objekt, das nicht gefunden wurde oder selbst wieder nur verschachtelt ist) – das unterstützt die Plattenanordnung nicht.', 'lindenweg16' ),
				);
			}

			$imported = $flattened;
		} else {
			$imported = $doc->importNode( $object, true );
		}

		$zip->close();

		$bounds = self::mesh_bounds_min( $imported );

		$imported->setAttribute( 'id', (string) $new_id );
		$resources->appendChild( $imported );

		return array( 'ok' => true, 'object_id' => $new_id, 'bounds' => $bounds, 'error' => '' );
	}

	/**
	 * Kleinste X-, Y- und Z-Koordinate im eigenen Mesh eines Objekts –
	 * der Nullpunkt-Versatz, der vor dem Anwenden einer BinPacker-
	 * Platzierung herausgerechnet werden muss (siehe Klassendoku
	 * "Nullpunkt-Versatz" und transform_matrix()). Ohne Geometrie (sollte
	 * durch die vorherigen Prüfungen nicht vorkommen) bleibt es bei 0.
	 *
	 * @param \DOMElement $object <object> mit eigenem <mesh>.
	 * @return array {min_x, min_y, min_z}
	 */
	protected static function mesh_bounds_min( \DOMElement $object ) {
		$nullpunkt = array(
			'min_x' => 0.0,
			'min_y' => 0.0,
			'min_z' => 0.0,
		);

		$mesh = self::first_child( $object, 'mesh' );

		if ( ! $mesh ) {
			return $nullpunkt;
		}

		list( $vertices, ) = self::mesh_vertices_and_triangles( $mesh );

		if ( ! $vertices ) {
			return $nullpunkt;
		}

		$min    = $vertices[0];
		$points = array();
		foreach ( $vertices as $v ) {
			$min      = array(
				min( $min[0], $v[0] ),
				min( $min[1], $v[1] ),
				min( $min[2], $v[2] ),
			);
			$points[] = array( $v[0], $v[1] );
		}

		return array(
			'min_x'  => $min[0],
			'min_y'  => $min[1],
			'min_z'  => $min[2],
			'points' => $points,
		);
	}

	/**
	 * Kleinste X- und Y-Koordinate des Mesh nach der Drehung um den eigenen
	 * Nullpunkt (Bounding-Box-Ecke), so wie transform_matrix() sie anwendet.
	 *
	 * Eine Platzierung {x, y} meint die Ecke des GEDREHTEN Begrenzungs-
	 * rechtecks (so rechnet BinPacker). transform_matrix() dreht aber um die
	 * Ecke des ungedrehten: Bei 90° liegt das Teil dann um seine Höhe links
	 * der Zielposition, bei 180° um Breite und Höhe. Diesen Versatz rechnet
	 * compose() mit heraus.
	 *
	 * @param array $points  Liste [x, y] des rohen Mesh.
	 * @param float $ox      Eigener Nullpunkt X.
	 * @param float $oy      Eigener Nullpunkt Y.
	 * @param float $degrees Winkel, Gegenuhrzeigersinn.
	 * @return array [min_x, min_y]
	 */
	public static function rotated_min( array $points, $ox, $oy, $degrees ) {
		if ( ! $points ) {
			return array( 0.0, 0.0 );
		}

		$rad = deg2rad( (float) $degrees );
		$cos = cos( $rad );
		$sin = sin( $rad );
		$minx = INF;
		$miny = INF;

		foreach ( $points as $p ) {
			$x = $p[0] - $ox;
			$y = $p[1] - $oy;
			$minx = min( $minx, $x * $cos - $y * $sin );
			$miny = min( $miny, $x * $sin + $y * $cos );
		}

		return array( $minx, $miny );
	}

	/**
	 * Ein <object> mit <components>-Verweis(en) statt eigenem <mesh>
	 * (Bambu-Studio-Production-Extension, siehe MeshOutline) durch ein
	 * flaches Objekt mit eingebetteter, transformierter Geometrie ersetzen
	 * – eine Ebene tief: ein referenziertes Objekt, das seinerseits wieder
	 * nur verschachtelt ist, wird übersprungen. Mehrere <component>-Kinder
	 * werden zu einem gemeinsamen Mesh zusammengefasst (Dreiecksindizes
	 * entsprechend verschoben), damit z. B. eine aus mehreren Körpern
	 * bestehende Baugruppe vollständig mitkommt.
	 *
	 * @param \DOMDocument $doc    Zieldokument – die neuen Elemente gehören dorthin, unabhängig davon, aus welchem Dokument $object stammt.
	 * @param \DOMElement  $object Das <object> mit <components>.
	 * @param \ZipArchive  $zip    Die 3MF, in der per p:path referenzierte Dateien liegen.
	 * @return \DOMElement|null Neues <object> mit eingebettetem <mesh>, oder null, wenn nichts auflösbar war.
	 */
	protected static function flatten_components( \DOMDocument $doc, \DOMElement $object, \ZipArchive $zip ) {
		$components = self::first_child( $object, 'components' );

		if ( ! $components ) {
			return null;
		}

		$vertices  = array();
		$triangles = array();

		foreach ( $components->childNodes as $component ) {
			if ( ! ( $component instanceof \DOMElement ) || 'component' !== $component->localName ) {
				continue;
			}

			$object_id = $component->getAttribute( 'objectid' );
			if ( '' === $object_id ) {
				continue;
			}

			$path    = $component->getAttributeNS( MeshOutline::PRODUCTION_NS, 'path' );
			$sub_doc = $object->ownerDocument;

			if ( '' !== $path ) {
				$sub_xml = $zip->getFromName( ltrim( $path, '/' ) );
				$sub_doc = is_string( $sub_xml ) ? ThreeMf::document( $sub_xml ) : null;
			}

			if ( ! $sub_doc ) {
				continue;
			}

			$referenced = null;
			foreach ( $sub_doc->getElementsByTagName( 'object' ) as $candidate ) {
				if ( $candidate->getAttribute( 'id' ) === (string) $object_id ) {
					$referenced = $candidate;
					break;
				}
			}

			// Selbst wieder nur eine Verschachtelung, oder fehlt ganz -
			// eine Ebene tiefer wird bewusst nicht aufgelöst (siehe
			// Klassendoku von MeshOutline).
			if ( ! $referenced || self::has_components( $referenced ) ) {
				continue;
			}

			$mesh = self::first_child( $referenced, 'mesh' );
			if ( ! $mesh ) {
				continue;
			}

			list( $sub_vertices, $sub_triangles ) = self::mesh_vertices_and_triangles( $mesh );
			$sub_vertices = MeshOutline::apply_transform( $sub_vertices, $component->getAttribute( 'transform' ) );

			$offset = count( $vertices );
			foreach ( $sub_vertices as $v ) {
				$vertices[] = $v;
			}
			foreach ( $sub_triangles as $t ) {
				$triangles[] = array( $t[0] + $offset, $t[1] + $offset, $t[2] + $offset );
			}
		}

		if ( ! $vertices || ! $triangles ) {
			return null;
		}

		$ns         = $doc->documentElement->namespaceURI ?: null;
		$new_object = $doc->createElementNS( $ns, 'object' );
		$new_object->setAttribute( 'id', $object->getAttribute( 'id' ) );
		if ( $object->hasAttribute( 'type' ) ) {
			$new_object->setAttribute( 'type', $object->getAttribute( 'type' ) );
		}

		$mesh_el      = $doc->createElementNS( $ns, 'mesh' );
		$vertices_el  = $doc->createElementNS( $ns, 'vertices' );
		foreach ( $vertices as $v ) {
			$vertex_el = $doc->createElementNS( $ns, 'vertex' );
			$vertex_el->setAttribute( 'x', self::format_number( $v[0] ) );
			$vertex_el->setAttribute( 'y', self::format_number( $v[1] ) );
			$vertex_el->setAttribute( 'z', self::format_number( $v[2] ) );
			$vertices_el->appendChild( $vertex_el );
		}
		$triangles_el = $doc->createElementNS( $ns, 'triangles' );
		foreach ( $triangles as $t ) {
			$triangle_el = $doc->createElementNS( $ns, 'triangle' );
			$triangle_el->setAttribute( 'v1', (string) $t[0] );
			$triangle_el->setAttribute( 'v2', (string) $t[1] );
			$triangle_el->setAttribute( 'v3', (string) $t[2] );
			$triangles_el->appendChild( $triangle_el );
		}
		$mesh_el->appendChild( $vertices_el );
		$mesh_el->appendChild( $triangles_el );
		$new_object->appendChild( $mesh_el );

		return $new_object;
	}

	/**
	 * Vertex- und Dreieckslisten eines <mesh>-Elements.
	 *
	 * @param \DOMElement $mesh <mesh>-Knoten.
	 * @return array [ [[x,y,z], ...], [[v1,v2,v3], ...] ]
	 */
	protected static function mesh_vertices_and_triangles( \DOMElement $mesh ) {
		$vertices    = array();
		$vertices_el = self::first_child( $mesh, 'vertices' );

		if ( $vertices_el ) {
			foreach ( $vertices_el->childNodes as $vertex ) {
				if ( $vertex instanceof \DOMElement && 'vertex' === $vertex->localName ) {
					$vertices[] = array(
						(float) $vertex->getAttribute( 'x' ),
						(float) $vertex->getAttribute( 'y' ),
						(float) $vertex->getAttribute( 'z' ),
					);
				}
			}
		}

		$triangles    = array();
		$triangles_el = self::first_child( $mesh, 'triangles' );

		if ( $triangles_el ) {
			foreach ( $triangles_el->childNodes as $triangle ) {
				if ( $triangle instanceof \DOMElement && 'triangle' === $triangle->localName ) {
					$triangles[] = array(
						(int) $triangle->getAttribute( 'v1' ),
						(int) $triangle->getAttribute( 'v2' ),
						(int) $triangle->getAttribute( 'v3' ),
					);
				}
			}
		}

		return array( $vertices, $triangles );
	}

	/**
	 * Zahl fürs 3MF-XML formatieren – bis zu 6 Nachkommastellen, ohne
	 * unnötige Nullen (siehe transform_matrix()).
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	protected static function format_number( $n ) {
		return rtrim( rtrim( sprintf( '%.6F', $n ), '0' ), '.' );
	}
}
