/**
 * Lage eines Modells an der Stange.
 *
 * Wird vom Konfigurator im Shop und von der Vorschau im ERP gleichermaßen
 * benutzt – beide müssen dasselbe Bild zeigen, sonst stimmt die Vorschau
 * beim Einrichten nicht mit dem überein, was der Kunde sieht.
 *
 * Maße: Die Ansicht rechnet in Metern, die Lage in Millimetern. Null liegt
 * an der Unterkante des Teils; X geht nach rechts, Y nach oben, Z von der
 * Wand weg. Die Stangenachse liegt bei X 0, Z 45 mm (siehe
 * ModelPlacement.php).
 *
 * Der Ablauf, in dieser Reihenfolge:
 *   1. Drehen (Grad, Reihenfolge X, Y, Z) – damit oben im Modell oben ist.
 *   2. Auf die gepflegte Höhe skalieren. Das erspart die Frage nach der
 *      Einheit der Datei und stellt sicher, dass das Bild dieselbe Höhe
 *      zeigt, mit der die Positionen gerechnet werden.
 *   3. Ausrichten: `auto` stellt das Teil auf die Unterkante, mittig auf
 *      die Stange, Rückseite an die Wand. `asis` lässt die Lage der
 *      Konstruktion und stellt es nur auf die Unterkante.
 *   4. Verschieben um die eingetragenen Millimeter.
 */

const MM = 1000;

/**
 * Ein geladenes Modell an seinen Platz bringen.
 *
 * @param {object} THREE    Die Bibliothek.
 * @param {object} objekt   Szene aus der Datei.
 * @param {number} hoeheMm  Gepflegte Bauhöhe in Millimetern (0 = nicht skalieren).
 * @param {object} lage     {align, rot: [x,y,z], off: [x,y,z]}; leer = Voreinstellung.
 * @returns {object} Gruppe mit dem Modell, Ursprung an der Unterkante.
 */
export function platziere( THREE, objekt, hoeheMm, lage ) {
	const l = lage || {};
	const rot = Array.isArray( l.rot ) ? l.rot : [ 0, 0, 0 ];
	const off = Array.isArray( l.off ) ? l.off : [ 0, 0, 0 ];
	const grad = Math.PI / 180;

	const dreher = new THREE.Group();

	dreher.add( objekt );
	dreher.rotation.set( ( rot[ 0 ] || 0 ) * grad, ( rot[ 1 ] || 0 ) * grad, ( rot[ 2 ] || 0 ) * grad, 'XYZ' );
	dreher.updateMatrixWorld( true );

	let kasten = new THREE.Box3().setFromObject( dreher );
	const hoehe = kasten.max.y - kasten.min.y;

	if ( hoehe > 0 && hoeheMm > 0 ) {
		dreher.scale.setScalar( ( hoeheMm / MM ) / hoehe );
		dreher.updateMatrixWorld( true );
		kasten = new THREE.Box3().setFromObject( dreher );
	}

	if ( 'asis' === l.align ) {
		dreher.position.y = -kasten.min.y;
	} else {
		dreher.position.set( -( kasten.min.x + kasten.max.x ) / 2, -kasten.min.y, -kasten.min.z );
	}

	dreher.position.x += ( off[ 0 ] || 0 ) / MM;
	dreher.position.y += ( off[ 1 ] || 0 ) / MM;
	dreher.position.z += ( off[ 2 ] || 0 ) / MM;

	dreher.traverse( ( knoten ) => {
		if ( knoten.isMesh ) {
			knoten.castShadow = true;
			knoten.receiveShadow = true;
		}
	} );

	const huelle = new THREE.Group();

	huelle.add( dreher );

	return huelle;
}
