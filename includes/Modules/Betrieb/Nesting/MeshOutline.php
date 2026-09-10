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
 * Bewusst einfach gehalten: Es wird das ERSTE <object> mit einem
 * <mesh><vertices>-Block genommen (kein Zusammensetzen mehrerer über
 * <components> verknüpfter Objekte, keine Mehrkörper-Baugruppen). Für die
 * "Modul"-Bauteile dieses Betriebs – je Revision eine Geometrie – ist das
 * der Regelfall; eine als Unterbaugruppe verschachtelte Projektdatei liegt
 * ausserhalb dieses ersten Wurfs und müsste vor dem Anordnen erst als
 * einzelnes Bauteil exportiert werden.
 */
class MeshOutline {

	/**
	 * XY-Hülle (konvex) eines Bauteils aus dem Inhalt von
	 * 3D/3dmodel.model.
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
	 * Alle Vertex-XY-Koordinaten eines <object>-Elements (Z ignoriert –
	 * die Anordnung ist eine reine Draufsicht auf das Druckbett).
	 *
	 * @param \DOMElement $object <object>-Knoten.
	 * @return array Liste [x, y].
	 */
	protected static function vertices_of( \DOMElement $object ) {
		$points = array();

		foreach ( $object->getElementsByTagName( 'vertex' ) as $vertex ) {
			$x = $vertex->getAttribute( 'x' );
			$y = $vertex->getAttribute( 'y' );

			if ( '' === $x || '' === $y || ! is_numeric( $x ) || ! is_numeric( $y ) ) {
				continue;
			}

			$points[] = array( (float) $x, (float) $y );
		}

		return $points;
	}
}
