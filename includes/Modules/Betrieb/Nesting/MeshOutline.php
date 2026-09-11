<?php
/**
 * Grundriss (XY-Hülle) eines Bauteils aus einer 3MF-Projektdatei.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ThreeMf;

/**
 * Liest die Draufsicht (Z ignoriert) eines Bauteils aus dem 3MF-Modell.
 *
 * Bewusst einfach gehalten: Es wird das ERSTE <object> mit auswertbarer
 * Geometrie genommen (keine Mehrkörper-Baugruppen aus mehreren
 * gleichrangigen Objekten). Für die "Modul"-Bauteile dieses Betriebs – je
 * Revision eine Geometrie – ist das der Regelfall.
 *
 * Ein <object> hat seine Geometrie entweder direkt (<mesh><vertices>) oder
 * verweist per Production-Extension-<component> auf ein anderes Objekt –
 * Bambu Studio lagert die Geometrie mancher Objekte in eigene Dateien
 * innerhalb der 3MF aus (z. B. "3D/Objects/object_8.model", referenziert
 * über p:path) statt sie im Wurzel-3dmodel.model einzubetten. Beides wird
 * aufgelöst (siehe vertices_via_components()); nur eine weitere Ebene tief
 * – ein referenziertes Objekt, das seinerseits nur wieder auf ein drittes
 * verweist, bleibt aussen vor.
 */
class MeshOutline {

	/**
	 * Namensraum der 3MF-Production-Extension (liefert u. a. das
	 * "path"-Attribut, mit dem ein <component> auf eine andere Datei
	 * innerhalb der 3MF verweist).
	 */
	const PRODUCTION_NS = 'http://schemas.microsoft.com/3dmanufacturing/production/2015/06';

	/**
	 * XY-Hülle (konvex) eines Bauteils aus dem Inhalt von
	 * 3D/3dmodel.model. Löst KEINE über <components p:path="..."> in
	 * andere Dateien ausgelagerte Geometrie auf – dafür xy_hull_from_file()
	 * mit Zugriff auf die ganze 3MF (ein ZIP) verwenden.
	 *
	 * @param string $xml Inhalt von 3D/3dmodel.model.
	 * @return array|null Konvexe Hülle [[x,y], ...] in mm, oder null, wenn
	 *                      keine Geometrie gefunden wurde.
	 */
	public static function xy_hull_from_xml( $xml ) {
		$doc = ThreeMf::document( $xml );

		if ( ! $doc ) {
			return null;
		}

		foreach ( $doc->getElementsByTagName( 'object' ) as $object ) {
			$points = self::vertices_of( $object );

			if ( $points ) {
				return Polygon::convex_hull( $points );
			}
		}

		return null;
	}

	/**
	 * Wie xy_hull_from_xml(), löst dabei aber zusätzlich in andere Dateien
	 * innerhalb der 3MF ausgelagerte Geometrie auf (siehe Klassendoku).
	 *
	 * @param string $zip_path Absoluter Pfad zur 3MF-Datei.
	 * @return array|null Konvexe Hülle [[x,y], ...] in mm, oder null.
	 */
	public static function xy_hull_from_file( $zip_path ) {
		if ( ! class_exists( '\ZipArchive' ) || ! is_file( $zip_path ) ) {
			return null;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return null;
		}

		$xml = $zip->getFromName( ThreeMf::MODEL_PATH );
		$doc = is_string( $xml ) ? ThreeMf::document( $xml ) : null;

		if ( ! $doc ) {
			$zip->close();
			return null;
		}

		foreach ( $doc->getElementsByTagName( 'object' ) as $object ) {
			$points = self::vertices_xyz_of( $object );

			if ( ! $points ) {
				$points = self::vertices_via_components( $object, $doc, $zip );
			}

			if ( $points ) {
				$zip->close();
				return Polygon::convex_hull( self::project_xy( $points ) );
			}
		}

		$zip->close();
		return null;
	}

	/**
	 * Geometrie eines <object> ohne eigenen <mesh>-Block über seine
	 * <component>-Verweise auflösen (Production Extension). Nur eine Ebene
	 * tief – siehe Klassendoku.
	 *
	 * @param \DOMElement  $object Das <object>, dessen Komponenten geprüft werden.
	 * @param \DOMDocument $doc    Wurzeldokument (3D/3dmodel.model), falls ein Verweis ohne p:path auf ein Objekt im selben Dokument zeigt.
	 * @param \ZipArchive  $zip    Die geöffnete 3MF, um per p:path referenzierte Dateien zu lesen.
	 * @return array Liste [x, y, z], leer wenn nichts auflösbar war.
	 */
	protected static function vertices_via_components( \DOMElement $object, \DOMDocument $doc, \ZipArchive $zip ) {
		foreach ( $object->getElementsByTagName( 'component' ) as $component ) {
			$object_id = $component->getAttribute( 'objectid' );

			if ( '' === $object_id ) {
				continue;
			}

			$path    = $component->getAttributeNS( self::PRODUCTION_NS, 'path' );
			$sub_doc = $doc;

			if ( '' !== $path ) {
				$sub_xml = $zip->getFromName( ltrim( $path, '/' ) );
				$sub_doc = is_string( $sub_xml ) ? ThreeMf::document( $sub_xml ) : null;
			}

			if ( ! $sub_doc ) {
				continue;
			}

			$referenced = self::find_object_by_id( $sub_doc, $object_id );
			$points     = $referenced ? self::vertices_xyz_of( $referenced ) : array();

			if ( $points ) {
				return self::apply_transform( $points, $component->getAttribute( 'transform' ) );
			}
		}

		return array();
	}

	/**
	 * Ein <object id="..."> in einem Dokument finden.
	 *
	 * @param \DOMDocument $doc Dokument.
	 * @param string       $id  Gesuchte id.
	 * @return \DOMElement|null
	 */
	protected static function find_object_by_id( \DOMDocument $doc, $id ) {
		foreach ( $doc->getElementsByTagName( 'object' ) as $object ) {
			if ( $object->getAttribute( 'id' ) === (string) $id ) {
				return $object;
			}
		}

		return null;
	}

	/**
	 * Das "transform"-Attribut eines <component> anwenden: 12 Zahlen einer
	 * zeilenweisen 3×4-Matrix (siehe 3MF-Spezifikation), angewandt als
	 * Zeilenvektor mal Matrix: x' = x·m00 + y·m10 + z·m20 + m30 usw. Ohne
	 * gültiges Attribut bleibt die Einheitsmatrix (Punkte unverändert).
	 *
	 * @param array  $points_xyz Liste [x, y, z].
	 * @param string $transform  Wert des transform-Attributs, oder leer.
	 * @return array Liste [x, y, z].
	 */
	public static function apply_transform( array $points_xyz, $transform ) {
		$zahlen = preg_split( '/\s+/', trim( (string) $transform ) );

		if ( ! is_array( $zahlen ) || 12 !== count( $zahlen ) || array_filter( $zahlen, static fn( $n ) => ! is_numeric( $n ) ) ) {
			return $points_xyz;
		}

		list( $m00, $m01, $m02, $m10, $m11, $m12, $m20, $m21, $m22, $m30, $m31, $m32 ) = array_map( 'floatval', $zahlen );

		$out = array();
		foreach ( $points_xyz as $p ) {
			list( $x, $y, $z ) = $p;
			$out[] = array(
				$x * $m00 + $y * $m10 + $z * $m20 + $m30,
				$x * $m01 + $y * $m11 + $z * $m21 + $m31,
				$x * $m02 + $y * $m12 + $z * $m22 + $m32,
			);
		}

		return $out;
	}

	/**
	 * [x, y, z]-Liste auf [x, y] reduzieren (Z ignoriert – die Anordnung
	 * ist eine reine Draufsicht auf das Druckbett).
	 *
	 * @param array $points_xyz Liste [x, y, z].
	 * @return array Liste [x, y].
	 */
	protected static function project_xy( array $points_xyz ) {
		return array_map(
			static fn( $p ) => array( $p[0], $p[1] ),
			$points_xyz
		);
	}

	/**
	 * Alle Vertex-XY-Koordinaten eines <object>-Elements (Z ignoriert –
	 * die Anordnung ist eine reine Draufsicht auf das Druckbett).
	 *
	 * @param \DOMElement $object <object>-Knoten.
	 * @return array Liste [x, y].
	 */
	protected static function vertices_of( \DOMElement $object ) {
		return self::project_xy( self::vertices_xyz_of( $object ) );
	}

	/**
	 * Alle Vertex-XYZ-Koordinaten eines <object>-Elements, direkt aus
	 * dessen eigenem <mesh><vertices>-Block (keine Komponenten-Auflösung –
	 * dafür vertices_via_components()).
	 *
	 * @param \DOMElement $object <object>-Knoten.
	 * @return array Liste [x, y, z].
	 */
	protected static function vertices_xyz_of( \DOMElement $object ) {
		$points = array();

		foreach ( $object->getElementsByTagName( 'vertex' ) as $vertex ) {
			$x = $vertex->getAttribute( 'x' );
			$y = $vertex->getAttribute( 'y' );
			$z = $vertex->getAttribute( 'z' );

			if ( '' === $x || '' === $y || ! is_numeric( $x ) || ! is_numeric( $y ) ) {
				continue;
			}

			$points[] = array( (float) $x, (float) $y, is_numeric( $z ) ? (float) $z : 0.0 );
		}

		return $points;
	}
}
