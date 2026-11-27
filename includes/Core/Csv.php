<?php
/**
 * Schutz vor Formel-Einschleusung in CSV-Exporten.
 *
 * Tabellenprogramme (Excel, LibreOffice Calc, Google Sheets) führen eine
 * Zelle als Formel aus, wenn sie mit `=`, `+`, `-`, `@`, Tab oder
 * Wagenrücklauf beginnt. Ein Lieferant, dessen Firmenname in einer
 * importierten E-Rechnung `=HYPERLINK(...)` oder `=cmd|...` lautet, würde
 * sonst beim Öffnen des Buchhaltungsexports auf dem Rechner der Buchhaltung
 * ausgeführt. Dieselbe Regel wird im ganzen Plugin verwendet
 * (BuchBuchungen::csv_safe, BuchMarketing::csv_cell, Payroll::csv_line).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gemeinsame CSV-Helfer.
 */
class Csv {

	/**
	 * Eine Zelle gegen Formel-Einschleusung absichern.
	 *
	 * Ein echter negativer Betrag (`-5`, `-5,00`, `-5.00 €`) bleibt
	 * unverändert; nur Text, der wie eine Formel aussieht, bekommt ein
	 * führendes Hochkomma und bleibt damit Text.
	 *
	 * @param mixed $value Zellwert.
	 * @return string
	 */
	public static function guard( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return $value;
		}

		$first = $value[0];

		// Zahlen (auch negative mit Komma/Punkt und optionalem €) sind keine Formeln.
		if ( ( '-' === $first || '+' === $first ) && preg_match( '/^[-+][\d.,]+( ?€)?$/', $value ) ) {
			return $value;
		}

		if ( false !== strpos( "=+-@\t\r", $first ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
