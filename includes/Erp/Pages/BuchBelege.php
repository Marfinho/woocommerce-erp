<?php
/**
 * ERP-Buchhaltung: Belege erfassen, prüfen, festschreiben, stornieren.
 *
 * Alles, was bisher nur in der klassischen wp-admin-Ansicht ging
 * (`AccountingModule`: handle_save_beleg, handle_festschreiben,
 * handle_beleg_storno, handle_ocr_scan, handle_erechnung_upload,
 * handle_erechnung_beleg, serve_beleg_file), läuft hier als REST-Route.
 *
 * Die GoBD-Regeln liegen weiter an einer Stelle: `BelegRepository` (Nummernkreis,
 * Hash-Kette, Festschreiben mit Journalbuchung, Storno statt Löschen) und
 * `AuditLog` (Protokoll). Diese Klasse ruft sie nur auf und ergänzt reine
 * Prüfregeln (Doppelbeleg, Feld-Normalisierung, Statusübergänge,
 * Dateityp), die sich ohne WordPress testen lassen.
 *
 * Bewusste Grenze: Betrag, Datum, Händler, Kategorie-Text und Datei gehen in die
 * Hash-Kette des Belegs ein. Sie lassen sich daher auch bei einem offenen Beleg
 * nicht mehr ändern, ohne die Kette zu brechen. Wer sich vertippt hat,
 * storniert den Beleg und erfasst ihn neu. Änderbar bleiben Konto, Zahlungsart,
 * Notiz, MwSt-Satz und Kostenstelle (nicht im Hash).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Lock;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Accounting\BelegOcr;
use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Accounting\BuchungRepository;
use Lindenweg16\Modules\Accounting\ERechnungImporter;
use Lindenweg16\Modules\Accounting\FeeExtractor;
use Lindenweg16\Modules\Accounting\Kontenrahmen;
use Lindenweg16\Modules\Marketing\KampagnenRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen und Regeln für die Belege im ERP.
 */
class BuchBelege {

	/**
	 * Einträge je Seite.
	 */
	const PER_PAGE = 30;

	/**
	 * Größte erlaubte Belegdatei in Byte.
	 */
	const MAX_BYTES = 15728640;

	/**
	 * Längste Kante eines Fotos nach dem Verkleinern.
	 */
	const MAX_EDGE = 2000;

	/**
	 * Zahlungsarten (Schlüssel => Beschriftung).
	 *
	 * @return array
	 */
	public static function zahlungsarten() {
		return array(
			'bar'         => __( 'Bar', 'lindenweg16' ),
			'karte'       => __( 'Karte (EC/Kredit)', 'lindenweg16' ),
			'ueberweisung' => __( 'Überweisung', 'lindenweg16' ),
			'lastschrift' => __( 'Lastschrift', 'lindenweg16' ),
			'paypal'      => __( 'PayPal', 'lindenweg16' ),
			'privat'      => __( 'Privat ausgelegt', 'lindenweg16' ),
		);
	}

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns   = RestApi::NAMESPACE_V1;
		$perm = $api->needs( array( 'finanzen.buchhaltung' ) );
		$base = '/erp/buch/belege';

		self::route( $ns, $base, 'GET', 'liste', $perm );
		self::route( $ns, $base, 'POST', 'anlegen', $perm );
		self::route( $ns, $base . '/optionen', 'GET', 'optionen', $perm );
		self::route( $ns, $base . '/doppelt', 'GET', 'doppelt', $perm );
		self::route( $ns, $base . '/ocr', 'POST', 'ocr', $perm );
		self::route( $ns, $base . '/ocr-text', 'POST', 'ocr_text', $perm );
		self::route( $ns, $base . '/erechnung', 'POST', 'erechnung_hochladen', $perm );
		self::route( $ns, $base . '/erechnung/(?P<id>\d+)/uebernehmen', 'POST', 'erechnung_uebernehmen', $perm );
		self::route( $ns, $base . '/festschreiben-alle', 'POST', 'festschreiben_alle', $perm );
		self::route( $ns, $base . '/(?P<id>\d+)', 'GET', 'einzeln', $perm );
		self::route( $ns, $base . '/(?P<id>\d+)', 'POST', 'aendern', $perm );
		self::route( $ns, $base . '/(?P<id>\d+)/datei', 'GET', 'datei', $perm );
		self::route( $ns, $base . '/(?P<id>\d+)/festschreiben', 'POST', 'festschreiben', $perm );
		self::route( $ns, $base . '/(?P<id>\d+)/stornieren', 'POST', 'stornieren', $perm );
	}

	/**
	 * Eine Route anmelden. Jede Route hängt am Recht aus `$perm`.
	 *
	 * @param string   $ns      Namensraum.
	 * @param string   $path    Pfad.
	 * @param string   $methods Methode.
	 * @param string   $method  Methode dieser Klasse.
	 * @param callable $perm    Rechteprüfung.
	 */
	protected static function route( $ns, $path, $methods, $method, $perm ) {
		register_rest_route(
			$ns,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( __CLASS__, $method ),
				'permission_callback' => $perm,
			)
		);
	}

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank und WordPress, getestet in tests/ErpBuchBelegeTest.php)
	 * ================================================================== */

	/**
	 * Status eines Belegs.
	 *
	 * @param bool $storniert       Storniert?
	 * @param bool $festgeschrieben Festgeschrieben?
	 * @return string offen, festgeschrieben oder storniert.
	 */
	public static function status( $storniert, $festgeschrieben ) {
		if ( $storniert ) {
			return 'storniert';
		}

		return $festgeschrieben ? 'festgeschrieben' : 'offen';
	}

	/**
	 * Darf mit einem Beleg in diesem Zustand etwas geschehen?
	 *
	 * @param string $status Status (offen, festgeschrieben, storniert).
	 * @param string $quelle Quelle des Belegs (storno = Gegenbeleg).
	 * @param string $aktion aendern, festschreiben, stornieren oder notiz.
	 * @return string Leer, wenn erlaubt, sonst der Grund auf Deutsch.
	 */
	public static function uebergang_fehler( $status, $quelle, $aktion ) {
		$storno_beleg = 'storno' === (string) $quelle;

		switch ( $aktion ) {
			case 'aendern':
				if ( 'festgeschrieben' === $status ) {
					return __( 'Dieser Beleg ist festgeschrieben und kann nicht mehr geändert werden. Nur die Notiz lässt sich noch ergänzen. Bei einem Fehler bitte stornieren und neu erfassen.', 'lindenweg16' );
				}
				if ( 'storniert' === $status ) {
					return __( 'Dieser Beleg ist storniert und kann nicht mehr geändert werden.', 'lindenweg16' );
				}
				return '';

			case 'festschreiben':
				if ( 'festgeschrieben' === $status ) {
					return __( 'Dieser Beleg ist schon festgeschrieben.', 'lindenweg16' );
				}
				if ( 'storniert' === $status ) {
					return __( 'Ein stornierter Beleg kann nicht mehr festgeschrieben werden.', 'lindenweg16' );
				}
				return '';

			case 'stornieren':
				if ( 'storniert' === $status ) {
					return __( 'Dieser Beleg ist schon storniert.', 'lindenweg16' );
				}
				if ( $storno_beleg ) {
					return __( 'Ein Storno-Beleg lässt sich nicht noch einmal stornieren.', 'lindenweg16' );
				}
				return '';

			case 'notiz':
				if ( 'storniert' === $status ) {
					return __( 'Bei einem stornierten Beleg lässt sich nichts mehr ergänzen.', 'lindenweg16' );
				}
				return '';
		}

		return __( 'Das ist bei diesem Beleg nicht möglich.', 'lindenweg16' );
	}

	/**
	 * Welche Felder ein Beleg in diesem Zustand noch annimmt.
	 *
	 * Betrag, Datum, Händler und Datei fehlen mit Absicht: Sie stehen in der
	 * Hash-Kette.
	 *
	 * @param string $status Status.
	 * @return string[]
	 */
	public static function aenderbare_felder( $status ) {
		if ( 'offen' === $status ) {
			return array( 'konto', 'zahlungsart', 'externe_nr', 'mwst_satz', 'notiz', 'kampagne' );
		}
		if ( 'festgeschrieben' === $status ) {
			return array( 'notiz' );
		}

		return array();
	}

	/**
	 * Fehlertext für einen fehlenden Stornogrund.
	 *
	 * @param mixed $grund Eingabe.
	 * @return string Leer, wenn der Grund reicht.
	 */
	public static function storno_grund_error( $grund ) {
		$grund = trim( preg_replace( '/\s+/u', ' ', (string) $grund ) );

		if ( '' === $grund ) {
			return __( 'Bitte einen Grund für die Stornierung angeben. Er wird im Protokoll festgehalten.', 'lindenweg16' );
		}
		if ( mb_strlen( $grund ) < 5 ) {
			return __( 'Der Grund ist zu kurz. Bitte in einem Satz sagen, warum der Beleg storniert wird.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * Stornogrund bereinigen.
	 *
	 * @param mixed $grund Eingabe.
	 * @return string
	 */
	public static function clean_grund( $grund ) {
		$grund = trim( preg_replace( '/\s+/u', ' ', (string) $grund ) );

		return mb_substr( $grund, 0, 500 );
	}

	/**
	 * Betrag aus einer Eingabe lesen: „12,50“, „1.234,56“, „12.5“, „€ 7“.
	 *
	 * @param mixed $wert Eingabe.
	 * @return string Betrag mit zwei Nachkommastellen, leer wenn nicht lesbar.
	 */
	public static function normalize_amount( $wert ) {
		if ( is_int( $wert ) || is_float( $wert ) ) {
			return number_format( (float) $wert, 2, '.', '' );
		}

		$wert = trim( (string) $wert );
		$wert = preg_replace( '/[^\d.,\-]/u', '', $wert );

		if ( '' === $wert || ! preg_match( '/\d/', $wert ) ) {
			return '';
		}

		$negativ = 0 === strpos( $wert, '-' );
		$wert    = ltrim( $wert, '-' );
		$komma   = strrpos( $wert, ',' );
		$punkt   = strrpos( $wert, '.' );

		if ( false !== $komma && ( false === $punkt || $komma > $punkt ) ) {
			$wert = str_replace( '.', '', $wert );
			$wert = str_replace( ',', '.', $wert );
		} elseif ( false !== $punkt && false !== $komma ) {
			$wert = str_replace( ',', '', $wert );
		} elseif ( false !== $punkt && 1 === preg_match( '/^\d{1,3}(\.\d{3})+$/', $wert ) ) {
			// 1.234 ist tausendzweihundertvierunddreißig, nicht 1,234.
			$wert = str_replace( '.', '', $wert );
		}

		if ( ! is_numeric( $wert ) ) {
			return '';
		}

		return ( $negativ ? '-' : '' ) . number_format( (float) $wert, 2, '.', '' );
	}

	/**
	 * Datum Y-m-d prüfen.
	 *
	 * @param mixed $wert Eingabe.
	 * @return bool
	 */
	public static function valid_date( $wert ) {
		if ( ! is_string( $wert ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Ein Schalter aus Formular oder JSON: 1, "1", true, "true", "ja", "on".
	 *
	 * @param mixed $wert Eingabe.
	 * @return bool
	 */
	public static function flag( $wert ) {
		if ( is_bool( $wert ) ) {
			return $wert;
		}

		return in_array( strtolower( trim( (string) $wert ) ), array( '1', 'true', 'ja', 'on', 'yes' ), true );
	}

	/**
	 * Text ohne Tags, auch ohne WordPress.
	 *
	 * @param mixed $text Eingabe.
	 * @return string
	 */
	protected static function ohne_tags( $text ) {
		return function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $text ) : strip_tags( (string) $text );
	}

	/**
	 * Eingaben für einen neuen Beleg prüfen und bereinigen.
	 *
	 * @param array  $in    Rohe Eingaben.
	 * @param string $heute Heutiges Datum Y-m-d.
	 * @return array {ok, clean, errors (Feld => Text)}
	 */
	public static function validate_input( array $in, $heute ) {
		$errors = array();
		$clean  = array();

		$betrag = self::normalize_amount( isset( $in['betrag'] ) ? $in['betrag'] : '' );
		if ( '' === $betrag ) {
			$errors['betrag'] = __( 'Bitte den Betrag eintragen, zum Beispiel 12,50.', 'lindenweg16' );
		} elseif ( (float) $betrag <= 0 ) {
			$errors['betrag'] = __( 'Der Betrag muss größer als null sein. Eine Rückzahlung wird als Storno erfasst, nicht als Minusbetrag.', 'lindenweg16' );
		} elseif ( (float) $betrag >= 1000000 ) {
			$errors['betrag'] = __( 'Dieser Betrag ist unplausibel hoch. Bitte noch einmal prüfen.', 'lindenweg16' );
		}
		$clean['betrag'] = $betrag;

		$datum = isset( $in['datum'] ) ? trim( (string) $in['datum'] ) : '';
		if ( ! self::valid_date( $datum ) ) {
			$errors['datum'] = __( 'Bitte ein gültiges Datum angeben.', 'lindenweg16' );
		} elseif ( $datum > $heute ) {
			$errors['datum'] = __( 'Das Datum liegt in der Zukunft. Bitte das Belegdatum prüfen.', 'lindenweg16' );
		} elseif ( $datum < '2000-01-01' ) {
			$errors['datum'] = __( 'Das Datum ist unplausibel alt. Bitte das Belegdatum prüfen.', 'lindenweg16' );
		}
		$clean['datum'] = $datum;

		$haendler = trim( preg_replace( '/\s+/u', ' ', self::ohne_tags( isset( $in['haendler'] ) ? $in['haendler'] : '' ) ) );
		if ( mb_strlen( $haendler ) > 255 ) {
			$haendler = mb_substr( $haendler, 0, 255 );
		}
		$clean['haendler'] = $haendler;

		$konto = isset( $in['konto'] ) ? trim( (string) $in['konto'] ) : '';
		if ( '' === $konto || ! Kontenrahmen::exists( $konto ) ) {
			$konto = Kontenrahmen::KONTO_SONSTIGES;
		}
		$clean['konto'] = $konto;

		$zahlart = isset( $in['zahlungsart'] ) ? (string) $in['zahlungsart'] : '';
		if ( '' !== $zahlart && ! isset( self::zahlungsarten()[ $zahlart ] ) ) {
			$errors['zahlungsart'] = __( 'Diese Zahlungsart gibt es nicht.', 'lindenweg16' );
			$zahlart               = '';
		}
		$clean['zahlungsart'] = $zahlart;

		$mwst = isset( $in['mwst_satz'] ) ? trim( (string) $in['mwst_satz'] ) : '';
		if ( '' !== $mwst && ! in_array( $mwst, array( '0', '7', '19' ), true ) ) {
			$errors['mwst_satz'] = __( 'Als MwSt-Satz sind 0, 7 und 19 Prozent möglich.', 'lindenweg16' );
			$mwst                = '';
		}
		$clean['mwst_satz'] = $mwst;

		$externe = isset( $in['externe_nr'] ) ? trim( preg_replace( '/\s+/u', ' ', (string) $in['externe_nr'] ) ) : '';
		$clean['externe_nr'] = mb_substr( $externe, 0, 60 );

		$notiz = isset( $in['notiz'] ) ? trim( str_replace( "\r", '', (string) $in['notiz'] ) ) : '';
		$clean['notiz'] = mb_substr( $notiz, 0, 2000 );

		$anteil = isset( $in['kampagne_anteil'] ) && '' !== (string) $in['kampagne_anteil']
			? (float) self::normalize_amount( $in['kampagne_anteil'] )
			: 100.0;
		$clean['kampagne_id']     = isset( $in['kampagne_id'] ) ? max( 0, (int) $in['kampagne_id'] ) : 0;
		$clean['kampagne_anteil'] = min( 100.0, max( 0.0, $anteil ) );

		return array(
			'ok'     => ! $errors,
			'clean'  => $clean,
			'errors' => $errors,
		);
	}

	/**
	 * Notiztext aus freiem Text und den Zusatzangaben bauen.
	 *
	 * Für Zahlungsart, externe Belegnummer und MwSt-Satz gibt es keine
	 * eigenen Spalten. Sie stehen als Kopfzeilen in der Notiz (die nicht in der
	 * Hash-Kette steht) und lassen sich wieder herauslesen.
	 *
	 * @param string $frei Freier Text.
	 * @param array  $meta zahlungsart, externe_nr, mwst_satz.
	 * @return string
	 */
	public static function notiz_bauen( $frei, array $meta ) {
		$zeilen = array();
		$arten  = self::zahlungsarten();

		if ( ! empty( $meta['zahlungsart'] ) && isset( $arten[ $meta['zahlungsart'] ] ) ) {
			$zeilen[] = 'Zahlungsart: ' . $arten[ $meta['zahlungsart'] ];
		}
		if ( ! empty( $meta['externe_nr'] ) ) {
			$zeilen[] = 'Beleg-Nr. (extern): ' . $meta['externe_nr'];
		}
		if ( isset( $meta['mwst_satz'] ) && '' !== (string) $meta['mwst_satz'] ) {
			$zeilen[] = 'MwSt: ' . (int) $meta['mwst_satz'] . ' %';
		}

		$frei = trim( (string) $frei );

		if ( ! $zeilen ) {
			return $frei;
		}

		return implode( "\n", $zeilen ) . ( '' !== $frei ? "\n\n" . $frei : '' );
	}

	/**
	 * Notiztext in freien Text und Zusatzangaben zerlegen (Gegenstück zu notiz_bauen).
	 *
	 * @param string $notiz Gespeicherte Notiz.
	 * @return array {frei, zahlungsart, externe_nr, mwst_satz}
	 */
	public static function notiz_zerlegen( $notiz ) {
		$out    = array(
			'frei'        => '',
			'zahlungsart' => '',
			'externe_nr'  => '',
			'mwst_satz'   => '',
		);
		$notiz  = str_replace( "\r", '', (string) $notiz );
		$zeilen = explode( "\n", $notiz );
		$arten  = array_flip( self::zahlungsarten() );
		$n      = 0;

		foreach ( $zeilen as $zeile ) {
			if ( preg_match( '/^Zahlungsart: (.+)$/u', $zeile, $m ) && isset( $arten[ $m[1] ] ) && 0 === $n ) {
				$out['zahlungsart'] = $arten[ $m[1] ];
			} elseif ( preg_match( '/^Beleg-Nr\. \(extern\): (.+)$/u', $zeile, $m ) && $n < 2 ) {
				$out['externe_nr'] = $m[1];
			} elseif ( preg_match( '/^MwSt: (\d{1,2}) %$/u', $zeile, $m ) && $n < 3 ) {
				$out['mwst_satz'] = $m[1];
			} else {
				break;
			}
			++$n;
		}

		$rest = array_slice( $zeilen, $n );
		if ( $n && isset( $rest[0] ) && '' === trim( $rest[0] ) ) {
			array_shift( $rest );
		}
		$out['frei'] = trim( implode( "\n", $rest ) );

		return $out;
	}

	/**
	 * MwSt-Anteil im Bruttobetrag.
	 *
	 * @param float|string $brutto Bruttobetrag.
	 * @param mixed        $satz   Satz in Prozent.
	 * @return float|null Null, wenn kein Satz bekannt ist.
	 */
	public static function mwst_anteil( $brutto, $satz ) {
		$satz = (float) $satz;

		if ( $satz <= 0 || (float) $brutto <= 0 ) {
			return null;
		}

		return round( (float) $brutto - (float) $brutto / ( 1 + $satz / 100 ), 2 );
	}

	/**
	 * Händlername zum Vergleichen vereinfachen.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function haendler_key( $name ) {
		$name = mb_strtolower( (string) $name );
		$name = strtr(
			$name,
			array(
				'ä' => 'ae',
				'ö' => 'oe',
				'ü' => 'ue',
				'ß' => 'ss',
				'é' => 'e',
				'è' => 'e',
			)
		);
		$name = preg_replace( '/[^a-z0-9]+/', ' ', $name );
		$name = preg_replace( '/\b(gmbh|mbh|ag|kg|ohg|gbr|ug|haftungsbeschraenkt|ek|e k|co|und|sarl|ltd|inc|llc)\b/', ' ', $name );

		return trim( preg_replace( '/\s+/', ' ', $name ) );
	}

	/**
	 * Sind zwei Händlernamen wohl derselbe Händler?
	 *
	 * Fehlt auf einer Seite der Name, gilt das als „möglich“: Gleiche Summe am
	 * gleichen Tag ohne Namen ist verdächtig genug für eine Rückfrage.
	 *
	 * @param string $a Name.
	 * @param string $b Name.
	 * @return bool
	 */
	public static function haendler_aehnlich( $a, $b ) {
		$a = self::haendler_key( $a );
		$b = self::haendler_key( $b );

		if ( '' === $a || '' === $b ) {
			return true;
		}
		if ( $a === $b || false !== strpos( $a, $b ) || false !== strpos( $b, $a ) ) {
			return true;
		}

		similar_text( $a, $b, $prozent );

		return $prozent >= 75;
	}

	/**
	 * Mögliche Doppelbelege: gleiche Summe, gleiches Datum, ähnlicher Händler.
	 *
	 * Stornierte Belege und Storno-Gegenbelege zählen nicht.
	 *
	 * @param array $neu   {betrag, datum, haendler}.
	 * @param array $rows  Vorhandene Belege (Zeilen mit id, betrag, datum, haendler, storniert, quelle).
	 * @return array Die ähnlichen Zeilen.
	 */
	public static function doppelte_finden( array $neu, array $rows ) {
		$treffer = array();

		foreach ( $rows as $row ) {
			$row = (array) $row;

			if ( ! empty( $row['storniert'] ) || ( isset( $row['quelle'] ) && 'storno' === $row['quelle'] ) ) {
				continue;
			}
			if ( abs( (float) $row['betrag'] - (float) $neu['betrag'] ) >= 0.005 ) {
				continue;
			}
			if ( (string) $row['datum'] !== (string) $neu['datum'] ) {
				continue;
			}
			if ( ! self::haendler_aehnlich( isset( $neu['haendler'] ) ? $neu['haendler'] : '', isset( $row['haendler'] ) ? $row['haendler'] : '' ) ) {
				continue;
			}

			$treffer[] = $row;
		}

		return $treffer;
	}

	/**
	 * Warntext zu möglichen Doppelbelegen.
	 *
	 * @param int $anzahl Anzahl ähnlicher Belege.
	 * @return string
	 */
	public static function doppelt_text( $anzahl ) {
		return sprintf(
			/* translators: %d: Anzahl. */
			_n(
				'Es gibt schon einen Beleg mit gleichem Betrag, gleichem Datum und ähnlichem Händler. Möglicherweise wurde er doppelt erfasst.',
				'Es gibt schon %d Belege mit gleichem Betrag, gleichem Datum und ähnlichem Händler. Möglicherweise wurde er doppelt erfasst.',
				(int) $anzahl,
				'lindenweg16'
			),
			(int) $anzahl
		);
	}

	/**
	 * Dateityp aus den ersten Bytes bestimmen – nach Inhalt, nicht nach Endung.
	 *
	 * @param string $head Erste Bytes (mindestens 16).
	 * @return array|null {art, ext, mime} oder null, wenn unbekannt.
	 */
	public static function dateityp( $head ) {
		$head = (string) $head;

		if ( 0 === strpos( $head, "\xFF\xD8\xFF" ) ) {
			return array( 'art' => 'jpeg', 'ext' => 'jpg', 'mime' => 'image/jpeg' );
		}
		if ( 0 === strpos( $head, "\x89PNG\r\n\x1A\n" ) ) {
			return array( 'art' => 'png', 'ext' => 'png', 'mime' => 'image/png' );
		}
		if ( 0 === strpos( $head, 'GIF87a' ) || 0 === strpos( $head, 'GIF89a' ) ) {
			return array( 'art' => 'gif', 'ext' => 'gif', 'mime' => 'image/gif' );
		}
		if ( 0 === strpos( $head, 'RIFF' ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return array( 'art' => 'webp', 'ext' => 'webp', 'mime' => 'image/webp' );
		}
		if ( 0 === strpos( $head, '%PDF-' ) ) {
			return array( 'art' => 'pdf', 'ext' => 'pdf', 'mime' => 'application/pdf' );
		}
		if ( 'ftyp' === substr( $head, 4, 4 ) && in_array( substr( $head, 8, 4 ), array( 'heic', 'heix', 'hevc', 'mif1', 'msf1', 'heim', 'heis' ), true ) ) {
			return array( 'art' => 'heic', 'ext' => 'heic', 'mime' => 'image/heic' );
		}

		$ohne_bom = ltrim( 0 === strpos( $head, "\xEF\xBB\xBF" ) ? substr( $head, 3 ) : $head );
		if ( 0 === strpos( $ohne_bom, '<?xml' ) || ( 0 === strpos( $ohne_bom, '<' ) && preg_match( '/^<[A-Za-z][\w:.\-]*[\s>]/', $ohne_bom ) ) ) {
			return array( 'art' => 'xml', 'ext' => 'xml', 'mime' => 'text/plain' );
		}

		return null;
	}

	/**
	 * Ist dieser Dateityp für den Zweck erlaubt?
	 *
	 * @param array|null $typ     Ergebnis von dateityp().
	 * @param string     $zweck   beleg (Foto/PDF), ocr (Foto/PDF) oder erechnung (XML/PDF).
	 * @return string Leer, wenn erlaubt, sonst Fehlertext.
	 */
	public static function dateityp_fehler( $typ, $zweck ) {
		if ( ! $typ ) {
			return 'erechnung' === $zweck
				? __( 'Diese Datei ist weder eine XML-Rechnung noch ein PDF. Bitte eine E-Rechnung (XML oder ZUGFeRD-PDF) wählen.', 'lindenweg16' )
				: __( 'Diese Datei kenne ich nicht. Erlaubt sind Fotos (JPEG, PNG, WebP) und PDF.', 'lindenweg16' );
		}
		if ( 'heic' === $typ['art'] ) {
			return __( 'Fotos im HEIC-Format kann der Server nicht verarbeiten. Bitte das Foto direkt in der App aufnehmen oder in den Kameraeinstellungen auf „Maximal kompatibel“ (JPEG) umstellen.', 'lindenweg16' );
		}

		$erlaubt = 'erechnung' === $zweck
			? array( 'xml', 'pdf' )
			: array( 'jpeg', 'png', 'webp', 'gif', 'pdf' );

		if ( ! in_array( $typ['art'], $erlaubt, true ) ) {
			return 'erechnung' === $zweck
				? __( 'Eine E-Rechnung ist eine XML-Datei oder ein ZUGFeRD-PDF. Fotos bitte über „Beleg erfassen“ hochladen.', 'lindenweg16' )
				: ( 'xml' === $typ['art']
					? __( 'Das ist eine XML-Datei. E-Rechnungen bitte über „E-Rechnung“ hochladen, dann werden die Felder daraus übernommen.', 'lindenweg16' )
					: __( 'Dieser Dateityp ist für Belege nicht erlaubt. Erlaubt sind Fotos (JPEG, PNG, WebP) und PDF.', 'lindenweg16' ) );
		}

		return '';
	}

	/**
	 * Dateiname in der geschützten Ablage – wie in der klassischen Ansicht.
	 *
	 * @param string $datum  Y-m-d (gmdate).
	 * @param string $zufall Zufallsanteil, nur Buchstaben und Ziffern.
	 * @param string $ext    Endung.
	 * @return string
	 */
	public static function dateiname( $datum, $zufall, $ext ) {
		return 'beleg-' . preg_replace( '/\D/', '', $datum ) . '-' . preg_replace( '/[^A-Za-z0-9]/', '', $zufall ) . '.' . strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $ext ) );
	}

	/**
	 * Betrag, Datum, Händler & Co. aus erkanntem Text vorschlagen.
	 *
	 * Es wird nur vorgeschlagen, was sicher gelesen wurde. Was fehlt, bleibt
	 * leer, statt geraten zu werden.
	 *
	 * @param string $text   Erkannter Text.
	 * @param string $heute  Heutiges Datum Y-m-d.
	 * @return array {felder: {betrag, datum, haendler, externe_nr, mwst_satz, konto}, gefunden: string[], hinweise: string[]}
	 */
	public static function ocr_felder( $text, $heute ) {
		$text   = (string) $text;
		$felder = array(
			'betrag'     => '',
			'datum'      => '',
			'haendler'   => '',
			'externe_nr' => '',
			'mwst_satz'  => '',
			'konto'      => '',
		);
		$hinweise = array();

		$betrag = BelegOcr::extract_betrag( $text );
		if ( '' !== $betrag && (float) $betrag > 0 ) {
			$felder['betrag'] = self::normalize_amount( $betrag );
		}

		$datum = BelegOcr::extract_datum( $text );
		if ( '' !== $datum && self::valid_date( $datum ) && $datum <= $heute ) {
			$felder['datum'] = $datum;
		}

		$haendler = BelegOcr::extract_haendler( $text );
		$haendler = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/[^\p{L}\p{N}\s.,&\-\'\/()+]/u', ' ', $haendler ) ) );
		if ( mb_strlen( $haendler ) >= 3 && preg_match( '/\p{L}{2,}/u', $haendler ) ) {
			$felder['haendler'] = mb_substr( $haendler, 0, 80 );
		}

		$felder['externe_nr'] = self::belegnummer_finden( $text );
		$felder['mwst_satz']  = self::mwst_satz_finden( $text );

		$gebuehr = self::gebuehr_hinweis( $text );
		if ( null !== $gebuehr ) {
			$felder['konto'] = '4970';
			$hinweise[]      = sprintf(
				/* translators: %s: Betrag. */
				__( 'Der Text enthält eine Gebührenzeile (%s €). Als Konto ist „Nebenkosten des Geldverkehrs“ vorgeschlagen, bitte prüfen.', 'lindenweg16' ),
				number_format( $gebuehr, 2, ',', '.' )
			);
		}

		$gefunden = array();
		foreach ( $felder as $key => $wert ) {
			if ( '' !== $wert ) {
				$gefunden[] = $key;
			}
		}

		return array(
			'felder'   => $felder,
			'gefunden' => $gefunden,
			'hinweise' => $hinweise,
		);
	}

	/**
	 * Rechnungs- oder Belegnummer des Ausstellers im Text finden.
	 *
	 * @param string $text Text.
	 * @return string Leer, wenn keine eindeutig benannte Nummer da steht.
	 */
	public static function belegnummer_finden( $text ) {
		$muster = '/(?:Rechnungs\s*-?\s*(?:nummer|nr\.?)|Rechnung\s*(?:nummer|nr\.?)|Beleg\s*-?\s*(?:nummer|nr\.?)|Bon\s*-?\s*(?:nummer|nr\.?)|Quittung\s*(?:nummer|nr\.?)|Invoice\s*(?:no\.?|number|nr\.?|#))\s*[:#]?\s*([A-Z0-9][A-Z0-9\/\-_.]{2,29})/iu';

		if ( preg_match( $muster, (string) $text, $m ) ) {
			return rtrim( $m[1], '.-/' );
		}

		return '';
	}

	/**
	 * MwSt-Satz im Text finden (7 oder 19 Prozent).
	 *
	 * Nur wenn genau ein Satz neben einem Steuerwort steht; stehen beide da,
	 * ist es eine Mischrechnung und der Satz bleibt leer.
	 *
	 * @param string $text Text.
	 * @return string 7, 19 oder leer.
	 */
	public static function mwst_satz_finden( $text ) {
		$gefunden = array();

		if ( preg_match_all( '/(?:mwst|ust|mehrwertsteuer|umsatzsteuer|steuer|vat)[^\n\d]{0,12}(19|7)(?:[.,]0+)?\s*%/iu', (string) $text, $m ) ) {
			$gefunden = array_unique( $m[1] );
		}
		if ( preg_match_all( '/(19|7)(?:[.,]0+)?\s*%\s*(?:mwst|ust|mehrwertsteuer|umsatzsteuer|vat)/iu', (string) $text, $m ) ) {
			$gefunden = array_unique( array_merge( $gefunden, $m[1] ) );
		}

		return 1 === count( $gefunden ) ? (string) reset( $gefunden ) : '';
	}

	/**
	 * Gebührenzeile im Text: Betrag der Gebühr, falls vorhanden.
	 *
	 * @param string $text Text.
	 * @return float|null
	 */
	public static function gebuehr_hinweis( $text ) {
		foreach ( preg_split( '/\R/u', (string) $text ) as $zeile ) {
			if ( FeeExtractor::is_fee_line( $zeile ) ) {
				$betrag = FeeExtractor::parse_amount( $zeile );
				if ( null !== $betrag && $betrag > 0 ) {
					return $betrag;
				}
			}
		}

		return null;
	}

	/**
	 * Listenfilter aus den Anfrageparametern.
	 *
	 * @param array $p Parameter.
	 * @return array {suche, status, von, bis, konto, quelle, seite}
	 */
	public static function filter_bereinigen( array $p ) {
		$status = isset( $p['status'] ) ? (string) $p['status'] : '';
		if ( ! in_array( $status, array( 'offen', 'festgeschrieben', 'storniert' ), true ) ) {
			$status = '';
		}

		$von = isset( $p['von'] ) && self::valid_date( (string) $p['von'] ) ? (string) $p['von'] : '';
		$bis = isset( $p['bis'] ) && self::valid_date( (string) $p['bis'] ) ? (string) $p['bis'] : '';
		if ( '' !== $von && '' !== $bis && $von > $bis ) {
			list( $von, $bis ) = array( $bis, $von );
		}

		$konto = isset( $p['konto'] ) ? (string) $p['konto'] : ( isset( $p['kategorie'] ) ? (string) $p['kategorie'] : '' );
		if ( ! preg_match( '/^[0-9]{3,10}$/', $konto ) ) {
			$konto = '';
		}

		$quelle = isset( $p['quelle'] ) ? (string) $p['quelle'] : '';
		if ( ! preg_match( '/^[a-z_]{1,20}$/', $quelle ) ) {
			$quelle = '';
		}

		$suche = isset( $p['suche'] ) ? trim( preg_replace( '/\s+/u', ' ', (string) $p['suche'] ) ) : '';

		return array(
			'suche'  => mb_substr( $suche, 0, 80 ),
			'status' => $status,
			'von'    => $von,
			'bis'    => $bis,
			'konto'  => $konto,
			'quelle' => $quelle,
			'seite'  => isset( $p['seite'] ) ? max( 1, min( 100000, (int) $p['seite'] ) ) : 1,
		);
	}

	/**
	 * SQL-Bedingung zu einem Status (feste Texte, keine Eingabe darin).
	 *
	 * @param string $status offen, festgeschrieben, storniert oder leer.
	 * @return string
	 */
	public static function status_sql( $status ) {
		switch ( $status ) {
			case 'offen':
				return 'festgeschrieben = 0 AND storniert = 0';
			case 'festgeschrieben':
				return 'festgeschrieben = 1 AND storniert = 0';
			case 'storniert':
				return 'storniert = 1';
		}

		return '1=1';
	}

	/**
	 * Beschriftung der Quelle.
	 *
	 * @param string $quelle Quelle.
	 * @return string
	 */
	public static function quelle_label( $quelle ) {
		switch ( (string) $quelle ) {
			case 'wareneingang':
				return __( 'Wareneingang', 'lindenweg16' );
			case 'erechnung':
				return __( 'E-Rechnung', 'lindenweg16' );
			case 'storno':
				return __( 'Storno', 'lindenweg16' );
		}

		return __( 'manuell', 'lindenweg16' );
	}

	/* ====================================================================
	 * Hilfen mit WordPress
	 * ================================================================== */

	/**
	 * Fehlerantwort.
	 *
	 * @param string $message Text auf Deutsch.
	 * @param int    $status  HTTP-Status.
	 * @param array  $extra   Weitere Felder.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422, array $extra = array() ) {
		return new WP_REST_Response( array_merge( array( 'ok' => false, 'message' => $message ), $extra ), $status );
	}

	/**
	 * Eingabeparameter als Array (JSON oder Formular).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array
	 */
	protected static function params( WP_REST_Request $request ) {
		$p = $request->get_params();

		return is_array( $p ) ? $p : array();
	}

	/**
	 * Zeile für die Liste und den Kopf der Einzelansicht.
	 *
	 * @param object $b Beleg.
	 * @return array
	 */
	protected static function row( $b ) {
		$status = self::status( ! empty( $b->storniert ), 1 === (int) $b->festgeschrieben );

		return array(
			'id'           => (int) $b->id,
			'nummer'       => '' !== (string) $b->belegnummer ? (string) $b->belegnummer : '#' . (int) $b->id,
			'datum'        => (string) $b->datum,
			'betrag'       => (float) $b->betrag,
			'haendler'     => (string) $b->haendler,
			'konto'        => (string) $b->konto,
			'kategorie'    => $b->konto && Kontenrahmen::exists( $b->konto ) ? Kontenrahmen::label( $b->konto ) : (string) $b->eur_kategorie,
			'quelle'       => (string) $b->quelle,
			'quelle_label' => self::quelle_label( $b->quelle ),
			'status'       => $status,
			'hat_datei'    => '' !== (string) $b->datei_pfad,
		);
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Optionen für Formulare: Konten, Zahlungsarten, Kostenstellen, Zeichenerkennung.
	 *
	 * @return WP_REST_Response
	 */
	public static function optionen() {
		$gruppen = array();
		foreach ( Kontenrahmen::grouped( 'ausgabe' ) as $gruppe => $konten ) {
			$liste = array();
			foreach ( $konten as $nr => $k ) {
				$liste[] = array(
					'konto'  => (string) $nr,
					'label'  => $k['label'],
					'abzug'  => (float) $k['abzugsfaehig'],
					'hinweis' => (string) $k['hinweis'],
				);
			}
			$gruppen[] = array( 'gruppe' => $gruppe, 'konten' => $liste );
		}

		$kampagnen = array();
		foreach ( KampagnenRepository::options() as $id => $label ) {
			$kampagnen[] = array( 'id' => (int) $id, 'label' => $label );
		}

		$zahlarten = array();
		foreach ( self::zahlungsarten() as $key => $label ) {
			$zahlarten[] = array( 'key' => $key, 'label' => $label );
		}

		$tesseract = BelegOcr::is_available();

		return new WP_REST_Response(
			array(
				'konten'        => $gruppen,
				'standardkonto' => Kontenrahmen::KONTO_SONSTIGES,
				'zahlungsarten' => $zahlarten,
				'kampagnen'     => $kampagnen,
				'tesseract'     => $tesseract,
				'ocr_hinweis'   => $tesseract
					? ''
					: __( 'Auf dem Server ist keine Zeichenerkennung (Tesseract) eingerichtet. PDFs mit Textschicht werden gelesen, Fotos nicht. Bitte die Felder von Hand ausfüllen.', 'lindenweg16' ),
				'heute'         => gmdate( 'Y-m-d' ),
				'max_bytes'     => min( self::MAX_BYTES, (int) wp_max_upload_size() ),
			)
		);
	}

	/**
	 * Belegliste mit Filtern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function liste( WP_REST_Request $request ) {
		global $wpdb;

		$f = self::filter_bereinigen( (array) $request->get_params() );
		$t = BelegRepository::table();

		$where  = array( self::status_sql( $f['status'] ) );
		$params = array();

		if ( '' !== $f['von'] ) {
			$where[]  = 'datum >= %s';
			$params[] = $f['von'];
		}
		if ( '' !== $f['bis'] ) {
			$where[]  = 'datum <= %s';
			$params[] = $f['bis'];
		}
		if ( '' !== $f['konto'] ) {
			$where[]  = 'konto = %s';
			$params[] = $f['konto'];
		}
		if ( '' !== $f['quelle'] ) {
			$where[]  = 'quelle = %s';
			$params[] = $f['quelle'];
		}
		if ( '' !== $f['suche'] ) {
			$like     = '%' . $wpdb->esc_like( $f['suche'] ) . '%';
			$betrag   = self::normalize_amount( $f['suche'] );
			$where[]  = '(belegnummer LIKE %s OR haendler LIKE %s OR notiz LIKE %s OR eur_kategorie LIKE %s OR konto LIKE %s OR CAST(betrag AS CHAR) LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = '' !== $betrag ? '%' . $wpdb->esc_like( $betrag ) . '%' : $like;
		}

		$bedingung = implode( ' AND ', $where );

		$gesamt_sql = "SELECT COUNT(*), COALESCE(SUM(CASE WHEN storniert = 0 AND quelle <> 'storno' THEN betrag ELSE 0 END), 0) FROM {$t} WHERE {$bedingung}"; // phpcs:ignore
		$summe_row  = $params
			? $wpdb->get_row( $wpdb->prepare( $gesamt_sql, $params ), ARRAY_N ) // phpcs:ignore
			: $wpdb->get_row( $gesamt_sql, ARRAY_N ); // phpcs:ignore
		$gesamt     = $summe_row ? (int) $summe_row[0] : 0;
		$summe      = $summe_row ? (float) $summe_row[1] : 0.0;

		$seiten = max( 1, (int) ceil( $gesamt / self::PER_PAGE ) );
		$seite  = min( $f['seite'], $seiten );

		$sql          = "SELECT * FROM {$t} WHERE {$bedingung} ORDER BY datum DESC, id DESC LIMIT %d OFFSET %d"; // phpcs:ignore
		$sql_params   = $params;
		$sql_params[] = self::PER_PAGE;
		$sql_params[] = ( $seite - 1 ) * self::PER_PAGE;

		$rows = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $sql_params ) ) as $b ) { // phpcs:ignore
			$rows[] = self::row( $b );
		}

		$offen = $wpdb->get_row( "SELECT COUNT(*), COALESCE(SUM(betrag), 0) FROM {$t} WHERE festgeschrieben = 0 AND storniert = 0", ARRAY_N ); // phpcs:ignore

		return new WP_REST_Response(
			array(
				'rows'         => $rows,
				'seite'        => $seite,
				'seiten'       => $seiten,
				'gesamt'       => $gesamt,
				'summe'        => $summe,
				'filter'       => $f,
				'offen_anzahl' => $offen ? (int) $offen[0] : 0,
				'offen_summe'  => $offen ? (float) $offen[1] : 0.0,
			)
		);
	}

	/**
	 * Einzelner Beleg mit allem, was dazugehört.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function einzeln( WP_REST_Request $request ) {
		global $wpdb;

		$b = BelegRepository::get( (int) $request['id'] );
		if ( ! $b ) {
			return self::fail( __( 'Diesen Beleg gibt es nicht (mehr). Bitte die Liste neu laden.', 'lindenweg16' ), 404 );
		}

		$id     = (int) $b->id;
		$row    = self::row( $b );
		$status = $row['status'];
		$notiz  = self::notiz_zerlegen( (string) $b->notiz );

		$user = (int) $b->erstellt_von ? get_userdata( (int) $b->erstellt_von ) : null;

		$row = array_merge(
			$row,
			array(
				'eur_kategorie'      => (string) $b->eur_kategorie,
				'notiz'              => (string) $b->notiz,
				'notiz_frei'         => $notiz['frei'],
				'zahlungsart'        => $notiz['zahlungsart'],
				'externe_nr'         => $notiz['externe_nr'],
				'mwst_satz'          => $notiz['mwst_satz'],
				'mwst_betrag'        => self::mwst_anteil( $b->betrag, $notiz['mwst_satz'] ),
				'festgeschrieben_am' => (string) $b->festgeschrieben_am,
				'erstellt_am'        => (string) $b->erstellt_am,
				'erstellt_von'       => $user ? (string) $user->display_name : '',
				'storno_grund'       => (string) $b->storno_grund,
				'hash'               => (string) $b->hash,
				'vorheriger_hash'    => (string) $b->vorheriger_hash,
			)
		);

		$t       = BelegRepository::table();
		$storno  = $wpdb->get_row( $wpdb->prepare( "SELECT id, belegnummer FROM {$t} WHERE storno_von_id = %d LIMIT 1", $id ) ); // phpcs:ignore
		$original = (int) $b->storno_von_id ? BelegRepository::get( (int) $b->storno_von_id ) : null;

		$kampagnen = array();
		foreach ( (array) KampagnenRepository::fuer_beleg( $id ) as $k ) {
			$kampagnen[] = array(
				'id'          => (int) $k->id,
				'name'        => (string) $k->name,
				'kostenstelle' => (string) $k->kostenstelle,
				'anteil'      => (float) $k->anteil_prozent,
			);
		}

		$buchungen = array();
		$haupt     = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . BuchungRepository::table() . " WHERE referenz_typ = 'beleg' AND referenz_id = %d ORDER BY id", $id ) ); // phpcs:ignore
		foreach ( (array) $haupt as $bu ) {
			$buchungen[] = self::buchung_row( $bu );
			$gegen       = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . BuchungRepository::table() . " WHERE referenz_typ = 'storno' AND referenz_id = %d ORDER BY id", (int) $bu->id ) ); // phpcs:ignore
			foreach ( (array) $gegen as $g ) {
				$buchungen[] = self::buchung_row( $g );
			}
		}

		return new WP_REST_Response(
			array(
				'beleg'      => $row,
				'datei'      => self::datei_info( $b ),
				'kampagnen'  => $kampagnen,
				'buchungen'  => $buchungen,
				'storno_beleg' => $storno ? array( 'id' => (int) $storno->id, 'nummer' => (string) $storno->belegnummer ) : null,
				'storno_von'   => $original ? array( 'id' => (int) $original->id, 'nummer' => (string) $original->belegnummer ) : null,
				'erlaubt'    => array(
					'aendern'       => '' === self::uebergang_fehler( $status, $b->quelle, 'aendern' ),
					'festschreiben' => '' === self::uebergang_fehler( $status, $b->quelle, 'festschreiben' ),
					'stornieren'    => '' === self::uebergang_fehler( $status, $b->quelle, 'stornieren' ),
					'notiz'         => '' === self::uebergang_fehler( $status, $b->quelle, 'notiz' ),
				),
				'aenderbar'  => self::aenderbare_felder( $status ),
			)
		);
	}

	/**
	 * Buchungszeile für die Einzelansicht.
	 *
	 * @param object $bu Buchung.
	 * @return array
	 */
	protected static function buchung_row( $bu ) {
		return array(
			'id'        => (int) $bu->id,
			'nummer'    => (string) $bu->buchungsnummer,
			'datum'     => (string) $bu->buchungsdatum,
			'typ'       => (string) $bu->typ,
			'betrag'    => (float) $bu->betrag,
			'kategorie' => (string) $bu->kategorie,
			'storniert' => ! empty( $bu->storniert ),
		);
	}

	/**
	 * Absoluter Pfad der Belegdatei, nur innerhalb der geschützten Ablage.
	 *
	 * Belege aus E-Rechnungen liegen im Dokumentenverzeichnis, alle anderen in
	 * der Belegablage. Der Pfad muss nach realpath() innerhalb liegen; der
	 * Vergleich braucht das Trennzeichen, sonst passt ein Nachbarverzeichnis.
	 *
	 * @param object $beleg Beleg.
	 * @return string Leer, wenn keine Datei da ist.
	 */
	protected static function datei_pfad( $beleg ) {
		if ( empty( $beleg->datei_pfad ) ) {
			return '';
		}

		$name = basename( (string) $beleg->datei_pfad );
		foreach ( array( Installer::belege_dir(), Installer::docs_dir() ) as $dir ) {
			$basis = realpath( $dir );
			$datei = realpath( trailingslashit( $dir ) . $name );

			if ( $basis && $datei && 0 === strpos( $datei, rtrim( $basis, '/\\' ) . DIRECTORY_SEPARATOR ) && is_file( $datei ) ) {
				return $datei;
			}
		}

		return '';
	}

	/**
	 * Angaben zur Belegdatei.
	 *
	 * @param object $beleg Beleg.
	 * @return array|null
	 */
	protected static function datei_info( $beleg ) {
		if ( empty( $beleg->datei_pfad ) ) {
			return null;
		}

		$pfad = self::datei_pfad( $beleg );
		if ( '' === $pfad ) {
			return array(
				'vorhanden' => false,
				'meldung'   => __( 'Die Datei zu diesem Beleg ist auf dem Server nicht mehr zu finden. Bitte beim Hoster nach einer Sicherung fragen.', 'lindenweg16' ),
			);
		}

		$kopf = (string) file_get_contents( $pfad, false, null, 0, 16 ); // phpcs:ignore
		$typ  = self::dateityp( $kopf );

		return array(
			'vorhanden' => true,
			'art'       => $typ ? $typ['art'] : 'unbekannt',
			'mime'      => $typ ? $typ['mime'] : 'application/octet-stream',
			'groesse'   => (int) filesize( $pfad ),
			'sha256'    => (string) hash_file( 'sha256', $pfad ),
			'url'       => add_query_arg(
				'_wpnonce',
				wp_create_nonce( 'wp_rest' ),
				rest_url( RestApi::NAMESPACE_V1 . '/erp/buch/belege/' . (int) $beleg->id . '/datei' )
			),
		);
	}

	/**
	 * Belegdatei ausliefern (Bild oder PDF), nur mit dem Recht für die Buchhaltung.
	 *
	 * Die Rechteprüfung steht an der Route (`needs`); hier kommt noch die
	 * Pfadprüfung dazu. Die Adresse trägt die REST-Nonce, damit ein
	 * `<img>`/`<iframe>` mit dem Anmelde-Cookie zugreifen darf.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|void
	 */
	public static function datei( WP_REST_Request $request ) {
		$beleg = BelegRepository::get( (int) $request['id'] );
		$pfad  = $beleg ? self::datei_pfad( $beleg ) : '';

		if ( '' === $pfad ) {
			return self::fail( __( 'Zu diesem Beleg gibt es keine Datei, oder sie ist auf dem Server nicht mehr vorhanden.', 'lindenweg16' ), 404 );
		}

		$typ = self::dateityp( (string) file_get_contents( $pfad, false, null, 0, 16 ) ); // phpcs:ignore
		if ( ! $typ || 'heic' === $typ['art'] ) {
			return self::fail( __( 'Diese Datei lässt sich nicht anzeigen.', 'lindenweg16' ), 415 );
		}

		nocache_headers();
		header( 'Content-Type: ' . $typ['mime'] );
		header( 'Content-Length: ' . filesize( $pfad ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( ( $beleg->belegnummer ? $beleg->belegnummer : 'beleg-' . (int) $beleg->id ) . '.' . $typ['ext'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=300' );
		readfile( $pfad ); // phpcs:ignore
		exit;
	}

	/**
	 * Mögliche Doppelbelege zu Betrag, Datum und Händler.
	 *
	 * @param array $neu {betrag, datum, haendler}.
	 * @return array Kurzzeilen der ähnlichen Belege.
	 */
	protected static function doppelte_laden( array $neu ) {
		global $wpdb;

		if ( ! self::valid_date( (string) $neu['datum'] ) || (float) $neu['betrag'] <= 0 ) {
			return array();
		}

		$t    = BelegRepository::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, belegnummer, datum, betrag, haendler, storniert, quelle FROM {$t} WHERE datum = %s AND ABS(betrag - %f) < 0.005 ORDER BY id DESC LIMIT 20", $neu['datum'], (float) $neu['betrag'] ), ARRAY_A ); // phpcs:ignore

		$out = array();
		foreach ( self::doppelte_finden( $neu, (array) $rows ) as $r ) {
			$out[] = array(
				'id'       => (int) $r['id'],
				'nummer'   => '' !== (string) $r['belegnummer'] ? (string) $r['belegnummer'] : '#' . (int) $r['id'],
				'datum'    => (string) $r['datum'],
				'betrag'   => (float) $r['betrag'],
				'haendler' => (string) $r['haendler'],
			);
		}

		return $out;
	}

	/**
	 * Doppelbeleg-Prüfung für das Formular.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function doppelt( WP_REST_Request $request ) {
		$neu = array(
			'betrag'   => (float) self::normalize_amount( $request->get_param( 'betrag' ) ),
			'datum'    => (string) $request->get_param( 'datum' ),
			'haendler' => sanitize_text_field( (string) $request->get_param( 'haendler' ) ),
		);

		$liste = self::doppelte_laden( $neu );

		return new WP_REST_Response(
			array(
				'aehnliche' => $liste,
				'message'   => $liste ? self::doppelt_text( count( $liste ) ) : '',
			)
		);
	}

	/**
	 * Hochgeladene Datei prüfen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @param string          $zweck   beleg, ocr oder erechnung.
	 * @param bool            $pflicht Ohne Datei ein Fehler?
	 * @return array {file, typ, error}  file = Eintrag aus $_FILES oder null.
	 */
	protected static function upload_pruefen( WP_REST_Request $request, $zweck, $pflicht ) {
		$files = $request->get_file_params();
		$file  = isset( $files['datei'] ) ? $files['datei'] : null;
		$max   = min( self::MAX_BYTES, (int) wp_max_upload_size() );

		// Übersteigt die Anfrage post_max_size, kommt gar kein Feld an.
		if ( ! $file || ( empty( $file['name'] ) && empty( $file['error'] ) ) || ( isset( $file['error'] ) && 4 === (int) $file['error'] ) ) {
			return array(
				'file'  => null,
				'typ'   => null,
				'error' => $pflicht ? __( 'Es kam keine Datei an. Entweder wurde keine gewählt, oder sie ist größer, als der Server annimmt. Bitte ein kleineres Foto oder PDF wählen.', 'lindenweg16' ) : '',
			);
		}

		$fehler = \Lindenweg16\Erp\ProductFile::upload_error( isset( $file['error'] ) ? (int) $file['error'] : 0, $max );
		if ( '' !== $fehler ) {
			return array( 'file' => null, 'typ' => null, 'error' => $fehler );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array( 'file' => null, 'typ' => null, 'error' => __( 'Die Datei wurde nicht korrekt hochgeladen. Bitte noch einmal versuchen.', 'lindenweg16' ) );
		}

		if ( (int) $file['size'] > $max ) {
			return array(
				'file'  => null,
				'typ'   => null,
				'error' => sprintf(
					/* translators: %s: Megabyte. */
					__( 'Die Datei ist zu groß (höchstens %s MB). Bitte ein kleineres Foto wählen.', 'lindenweg16' ),
					number_format_i18n( $max / 1048576, 0 )
				),
			);
		}

		$kopf = (string) file_get_contents( $file['tmp_name'], false, null, 0, 16 ); // phpcs:ignore
		$typ  = self::dateityp( $kopf );

		$fehler = self::dateityp_fehler( $typ, $zweck );
		if ( '' !== $fehler ) {
			return array( 'file' => null, 'typ' => $typ, 'error' => $fehler );
		}

		// Ein Bild muss auch wirklich eines sein, nicht nur so anfangen.
		if ( in_array( $typ['art'], array( 'jpeg', 'png', 'gif', 'webp' ), true ) && false === @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore
			return array( 'file' => null, 'typ' => $typ, 'error' => __( 'Das Bild ist beschädigt und lässt sich nicht lesen. Bitte neu aufnehmen.', 'lindenweg16' ) );
		}

		return array( 'file' => $file, 'typ' => $typ, 'error' => '' );
	}

	/**
	 * Datei in die geschützte Belegablage legen (gleiche Ablage wie die klassische Ansicht).
	 *
	 * @param array $file Eintrag aus $_FILES.
	 * @param array $typ  Ergebnis von dateityp().
	 * @return array {name, sha256, size} oder leer bei Fehler.
	 */
	protected static function datei_ablegen( array $file, array $typ ) {
		$dir = Installer::belege_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$name = self::dateiname( gmdate( 'Ymd' ), wp_generate_password( 12, false ), $typ['ext'] );
		$ziel = trailingslashit( $dir ) . $name;

		if ( ! @move_uploaded_file( $file['tmp_name'], $ziel ) ) { // phpcs:ignore
			return array();
		}

		// Handyfotos sind groß: wie in der klassischen Ansicht auf 2000 px verkleinern.
		if ( in_array( $typ['art'], array( 'jpeg', 'png', 'webp' ), true ) ) {
			$editor = wp_get_image_editor( $ziel );
			if ( ! is_wp_error( $editor ) ) {
				$size = $editor->get_size();
				if ( ! empty( $size['width'] ) && ( $size['width'] > self::MAX_EDGE || $size['height'] > self::MAX_EDGE ) ) {
					$editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
					$editor->set_quality( 82 );
					$editor->save( $ziel );
				}
			}
		}

		return array(
			'name'   => $name,
			'sha256' => (string) hash_file( 'sha256', $ziel ),
			'size'   => (int) filesize( $ziel ),
		);
	}

	/**
	 * Beleg anlegen (mit Datei), optional gleich festschreiben.
	 *
	 * @param WP_REST_Request $request Anfrage (multipart oder JSON).
	 * @return WP_REST_Response
	 */
	public static function anlegen( WP_REST_Request $request ) {
		$p   = self::params( $request );
		$val = self::validate_input( $p, gmdate( 'Y-m-d' ) );

		if ( ! $val['ok'] ) {
			return self::fail( reset( $val['errors'] ), 422, array( 'fehler' => $val['errors'] ) );
		}
		$c = $val['clean'];

		$kampagne = null;
		if ( $c['kampagne_id'] ) {
			$kampagne = KampagnenRepository::get( $c['kampagne_id'] );
			if ( ! $kampagne ) {
				return self::fail( __( 'Diese Kostenstelle gibt es nicht (mehr). Bitte eine andere wählen.', 'lindenweg16' ), 422, array( 'fehler' => array( 'kampagne_id' => '' ) ) );
			}
		}

		$upload = self::upload_pruefen( $request, 'beleg', false );
		if ( '' !== $upload['error'] ) {
			return self::fail( $upload['error'] );
		}

		$doppelte = self::doppelte_laden( array( 'betrag' => (float) $c['betrag'], 'datum' => $c['datum'], 'haendler' => $c['haendler'] ) );
		$trotzdem = self::flag( isset( $p['trotzdem'] ) ? $p['trotzdem'] : false );
		if ( $doppelte && ! $trotzdem ) {
			return self::fail( self::doppelt_text( count( $doppelte ) ), 409, array( 'doppelt' => true, 'aehnliche' => $doppelte ) );
		}

		$datei = array();
		if ( $upload['file'] ) {
			$datei = self::datei_ablegen( $upload['file'], $upload['typ'] );
			if ( ! $datei ) {
				return self::fail( __( 'Die Datei konnte nicht gespeichert werden. Bitte noch einmal versuchen. Wenn es wieder nicht klappt, ist der Speicherort auf dem Server nicht beschreibbar.', 'lindenweg16' ), 500 );
			}
		}

		$beleg_id = self::beleg_erzeugen( $c, $datei ? $datei['name'] : '' );

		if ( ! $beleg_id ) {
			if ( $datei ) {
				@unlink( BelegRepository::file_path( $datei['name'] ) ); // phpcs:ignore
			}
			return self::fail( __( 'Der Beleg konnte nicht gespeichert werden. Es wurde nichts verbucht. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		$anteil = 0.0;
		if ( $kampagne ) {
			$anteil = KampagnenRepository::zuordnen( $c['kampagne_id'], $beleg_id, $c['kampagne_anteil'] );
		}

		AuditLog::record(
			'beleg_erfasst',
			'beleg',
			$beleg_id,
			array(
				'weg'      => 'erp',
				'betrag'   => $c['betrag'],
				'sha256'   => $datei ? $datei['sha256'] : '',
				'groesse'  => $datei ? $datei['size'] : 0,
				'dateityp' => $upload['typ'] ? $upload['typ']['art'] : '',
				'doppelt_bestaetigt' => (bool) $doppelte,
			)
		);

		return self::nach_anlegen( $beleg_id, self::flag( isset( $p['festschreiben'] ) ? $p['festschreiben'] : false ), $anteil );
	}

	/**
	 * Beleg in der Datenbank anlegen – Nummernvergabe unter Sperre.
	 *
	 * Die Belegnummer wird vor dem Einfügen gelesen. Ohne Sperre könnten zwei
	 * gleichzeitige Erfassungen (zwei Handys) dieselbe Nummer bekommen.
	 *
	 * @param array  $c     Bereinigte Eingaben.
	 * @param string $datei Dateiname in der Ablage oder leer.
	 * @param string $quelle Quelle.
	 * @return int Beleg-ID oder 0.
	 */
	protected static function beleg_erzeugen( array $c, $datei, $quelle = 'manuell' ) {
		$eintrag = Kontenrahmen::get( $c['konto'] );
		$gesperrt = Lock::acquire( 'beleg_nummer' );

		try {
			return (int) BelegRepository::create(
				array(
					'datei_pfad'    => $datei,
					'betrag'        => (float) $c['betrag'],
					'datum'         => $c['datum'],
					'haendler'      => $c['haendler'],
					'konto'         => $c['konto'],
					'eur_kategorie' => $eintrag ? $eintrag['label'] : '',
					'notiz'         => self::notiz_bauen( $c['notiz'], $c ),
					'quelle'        => $quelle,
				)
			);
		} finally {
			if ( $gesperrt ) {
				Lock::release( 'beleg_nummer' );
			}
		}
	}

	/**
	 * Antwort nach dem Anlegen, ggf. nach dem Festschreiben.
	 *
	 * @param int   $beleg_id        Beleg.
	 * @param bool  $festschreiben   Gleich festschreiben?
	 * @param float $kampagne_anteil Zugeordneter Anteil.
	 * @return WP_REST_Response
	 */
	protected static function nach_anlegen( $beleg_id, $festschreiben, $kampagne_anteil ) {
		$frozen  = false;
		$warnung = '';

		if ( $festschreiben ) {
			$frozen = BelegRepository::festschreiben( $beleg_id );
			if ( ! $frozen ) {
				$warnung = __( 'Der Beleg ist gespeichert, ließ sich aber nicht festschreiben. Er bleibt offen und kann in der Liste festgeschrieben werden.', 'lindenweg16' );
			}
		}

		$b = BelegRepository::get( $beleg_id );

		return new WP_REST_Response(
			array(
				'ok'              => true,
				'message'         => $frozen
					? __( 'Beleg gespeichert und festgeschrieben.', 'lindenweg16' )
					: ( '' !== $warnung ? $warnung : __( 'Beleg gespeichert. Er ist noch offen und lässt sich bis zum Festschreiben ergänzen.', 'lindenweg16' ) ),
				'festgeschrieben' => $frozen,
				'kampagne_anteil' => $kampagne_anteil,
				'beleg'           => $b ? self::row( $b ) : array( 'id' => $beleg_id ),
			),
			201
		);
	}

	/**
	 * Beleg ändern (nur erlaubte Felder, nur solange offen; danach nur die Notiz).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function aendern( WP_REST_Request $request ) {
		$b = BelegRepository::get( (int) $request['id'] );
		if ( ! $b ) {
			return self::fail( __( 'Diesen Beleg gibt es nicht (mehr). Bitte die Liste neu laden.', 'lindenweg16' ), 404 );
		}

		$id      = (int) $b->id;
		$status  = self::status( ! empty( $b->storniert ), 1 === (int) $b->festgeschrieben );
		$p       = self::params( $request );
		$aktuell = self::notiz_zerlegen( (string) $b->notiz );

		// Nach dem Festschreiben bleibt nur die Notiz, sonst nichts (wie BelegRepository::update).
		$nur_notiz = 'offen' !== $status;
		$fehler    = self::uebergang_fehler( $status, $b->quelle, $nur_notiz ? 'notiz' : 'aendern' );
		if ( '' !== $fehler ) {
			return self::fail( $fehler, 409 );
		}

		$verlangt = array_diff( array_keys( $p ), array( 'notiz', 'id', 'client_ref' ) );
		if ( $nur_notiz && array_intersect( $verlangt, array( 'konto', 'betrag', 'datum', 'haendler', 'zahlungsart', 'externe_nr', 'mwst_satz', 'kampagne_id' ) ) ) {
			return self::fail( self::uebergang_fehler( $status, $b->quelle, 'aendern' ), 409 );
		}

		$frei = isset( $p['notiz'] ) ? trim( str_replace( "\r", '', (string) $p['notiz'] ) ) : $aktuell['frei'];
		$frei = mb_substr( $frei, 0, 2000 );
		$meta = $aktuell;
		$daten = array();

		if ( ! $nur_notiz ) {
			$val = self::validate_input(
				array(
					'betrag'      => $b->betrag,
					'datum'       => $b->datum,
					'konto'       => isset( $p['konto'] ) ? $p['konto'] : $b->konto,
					'zahlungsart' => isset( $p['zahlungsart'] ) ? $p['zahlungsart'] : $aktuell['zahlungsart'],
					'externe_nr'  => isset( $p['externe_nr'] ) ? $p['externe_nr'] : $aktuell['externe_nr'],
					'mwst_satz'   => isset( $p['mwst_satz'] ) ? $p['mwst_satz'] : $aktuell['mwst_satz'],
					'notiz'       => $frei,
					'kampagne_id' => isset( $p['kampagne_id'] ) ? $p['kampagne_id'] : 0,
					'kampagne_anteil' => isset( $p['kampagne_anteil'] ) ? $p['kampagne_anteil'] : 100,
				),
				gmdate( 'Y-m-d', time() + DAY_IN_SECONDS * 400 )
			);
			if ( ! $val['ok'] ) {
				return self::fail( reset( $val['errors'] ), 422, array( 'fehler' => $val['errors'] ) );
			}
			$c    = $val['clean'];
			$meta = $c;

			$daten['konto'] = $c['konto'];
		}

		$daten['notiz'] = self::notiz_bauen( $frei, $meta );

		if ( ! BelegRepository::update( $id, $daten ) ) {
			return self::fail( __( 'Es gab nichts zu ändern, oder die Änderung wurde vom Beleg nicht angenommen.', 'lindenweg16' ), 409 );
		}

		if ( ! $nur_notiz && isset( $p['kampagne_id'] ) ) {
			self::kampagne_setzen( $id, $c['kampagne_id'], $c['kampagne_anteil'] );
		}

		AuditLog::record(
			$nur_notiz ? 'beleg_notiz_ergaenzt' : 'beleg_geaendert',
			'beleg',
			$id,
			array(
				'felder'  => array_keys( $daten ),
				'konto'   => isset( $daten['konto'] ) ? $daten['konto'] : (string) $b->konto,
				'vorher'  => mb_substr( (string) $b->notiz, 0, 300 ),
				'nachher' => mb_substr( $daten['notiz'], 0, 300 ),
			)
		);

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => $nur_notiz
					? __( 'Notiz gespeichert. Alles andere ist durch das Festschreiben gesichert.', 'lindenweg16' )
					: __( 'Änderungen gespeichert.', 'lindenweg16' ),
			)
		);
	}

	/**
	 * Kostenstelle eines offenen Belegs setzen (ersetzt vorhandene Zuordnungen).
	 *
	 * @param int   $beleg_id    Beleg.
	 * @param int   $kampagne_id Kostenstelle, 0 = keine.
	 * @param float $anteil      Prozent.
	 */
	protected static function kampagne_setzen( $beleg_id, $kampagne_id, $anteil ) {
		foreach ( (array) KampagnenRepository::fuer_beleg( $beleg_id ) as $k ) {
			if ( (int) $k->id !== (int) $kampagne_id ) {
				KampagnenRepository::loesen( (int) $k->id, $beleg_id );
			}
		}

		if ( $kampagne_id && KampagnenRepository::get( $kampagne_id ) ) {
			KampagnenRepository::zuordnen( $kampagne_id, $beleg_id, $anteil );
		}
	}

	/**
	 * Einen Beleg festschreiben.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function festschreiben( WP_REST_Request $request ) {
		$b = BelegRepository::get( (int) $request['id'] );
		if ( ! $b ) {
			return self::fail( __( 'Diesen Beleg gibt es nicht (mehr). Bitte die Liste neu laden.', 'lindenweg16' ), 404 );
		}

		$status = self::status( ! empty( $b->storniert ), 1 === (int) $b->festgeschrieben );
		$fehler = self::uebergang_fehler( $status, $b->quelle, 'festschreiben' );
		if ( '' !== $fehler ) {
			return self::fail( $fehler, 409 );
		}

		if ( ! BelegRepository::festschreiben( (int) $b->id ) ) {
			return self::fail( __( 'Der Beleg ließ sich nicht festschreiben. Bitte die Liste neu laden und noch einmal versuchen.', 'lindenweg16' ), 409 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Beleg festgeschrieben und im Journal gebucht. Er ist jetzt unveränderbar.', 'lindenweg16' ),
			)
		);
	}

	/**
	 * Alle offenen Belege festschreiben.
	 *
	 * Belege mit Betrag null werden übersprungen: Festschreiben lässt sich nicht
	 * zurücknehmen, und ein Nullbeleg ist fast immer ein Erfassungsfehler.
	 *
	 * @return WP_REST_Response
	 */
	public static function festschreiben_alle() {
		global $wpdb;

		$t   = BelegRepository::table();
		$ids = $wpdb->get_col( "SELECT id FROM {$t} WHERE festgeschrieben = 0 AND storniert = 0 ORDER BY datum ASC, id ASC LIMIT 500" ); // phpcs:ignore

		$fertig       = 0;
		$uebersprungen = array();

		foreach ( (array) $ids as $id ) {
			$b = BelegRepository::get( (int) $id );
			if ( ! $b ) {
				continue;
			}
			if ( (float) $b->betrag <= 0 ) {
				$uebersprungen[] = '' !== (string) $b->belegnummer ? (string) $b->belegnummer : '#' . (int) $b->id;
				continue;
			}
			if ( '' !== self::uebergang_fehler( self::status( ! empty( $b->storniert ), 1 === (int) $b->festgeschrieben ), $b->quelle, 'festschreiben' ) ) {
				continue;
			}
			if ( BelegRepository::festschreiben( (int) $b->id ) ) {
				++$fertig;
			}
		}

		AuditLog::record( 'belege_alle_festgeschrieben', 'beleg', 0, array( 'anzahl' => $fertig, 'uebersprungen' => $uebersprungen ) );

		$text = 0 === $fertig
			? __( 'Es gab keine offenen Belege zum Festschreiben.', 'lindenweg16' )
			: sprintf(
				/* translators: %d: Anzahl. */
				_n( '%d Beleg festgeschrieben und im Journal gebucht.', '%d Belege festgeschrieben und im Journal gebucht.', $fertig, 'lindenweg16' ),
				$fertig
			);

		if ( $uebersprungen ) {
			$text .= ' ' . sprintf(
				/* translators: %s: Belegnummern. */
				__( 'Übersprungen wegen Betrag 0: %s. Bitte prüfen oder stornieren.', 'lindenweg16' ),
				implode( ', ', $uebersprungen )
			);
		}

		return new WP_REST_Response(
			array(
				'ok'            => true,
				'message'       => $text,
				'festgeschrieben' => $fertig,
				'uebersprungen' => $uebersprungen,
			)
		);
	}

	/**
	 * Beleg stornieren – nur mit Grund. Die Stornierung legt einen Gegenbeleg an,
	 * nichts wird gelöscht.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function stornieren( WP_REST_Request $request ) {
		$grund_fehler = self::storno_grund_error( $request->get_param( 'grund' ) );
		if ( '' !== $grund_fehler ) {
			return self::fail( $grund_fehler, 422, array( 'fehler' => array( 'grund' => $grund_fehler ) ) );
		}

		$b = BelegRepository::get( (int) $request['id'] );
		if ( ! $b ) {
			return self::fail( __( 'Diesen Beleg gibt es nicht (mehr). Bitte die Liste neu laden.', 'lindenweg16' ), 404 );
		}

		$status = self::status( ! empty( $b->storniert ), 1 === (int) $b->festgeschrieben );
		$fehler = self::uebergang_fehler( $status, $b->quelle, 'stornieren' );
		if ( '' !== $fehler ) {
			return self::fail( $fehler, 409 );
		}

		$storno_id = BelegRepository::stornieren( (int) $b->id, self::clean_grund( $request->get_param( 'grund' ) ) );
		if ( ! $storno_id ) {
			return self::fail( __( 'Der Beleg ließ sich nicht stornieren. Bitte die Liste neu laden und noch einmal versuchen.', 'lindenweg16' ), 409 );
		}

		$storno = BelegRepository::get( (int) $storno_id );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %s: Belegnummer des Storno-Belegs. */
					__( 'Beleg storniert. Der Gegenbeleg %s wurde angelegt; der ursprüngliche Beleg bleibt zur Nachvollziehbarkeit erhalten.', 'lindenweg16' ),
					$storno ? (string) $storno->belegnummer : ''
				),
			)
		);
	}

	/**
	 * Vorschlag für die Felder aus einem Foto oder PDF.
	 *
	 * Ohne Tesseract und ohne Textschicht kommen keine Felder zurück, sondern eine
	 * ehrliche Meldung. Es wird nichts geraten.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function ocr( WP_REST_Request $request ) {
		$upload = self::upload_pruefen( $request, 'ocr', true );
		if ( '' !== $upload['error'] ) {
			return self::fail( $upload['error'] );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore
		}

		$result = BelegOcr::scan( $upload['file']['tmp_name'] );

		if ( empty( $result['success'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'        => true,
					'erkannt'   => false,
					'tesseract' => BelegOcr::is_available(),
					'meldung'   => '' !== (string) $result['error'] ? $result['error'] : __( 'Aus diesem Beleg ließ sich nichts auslesen. Bitte die Felder von Hand ausfüllen.', 'lindenweg16' ),
					'felder'    => new \stdClass(),
					'gefunden'  => array(),
				)
			);
		}

		$vorschlag = self::ocr_felder( (string) $result['raw_text'], gmdate( 'Y-m-d' ) );
		$gefunden  = $vorschlag['gefunden'];

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'erkannt'  => (bool) $gefunden,
				'quelle'   => isset( $result['quelle'] ) ? $result['quelle'] : 'ocr',
				'felder'   => $vorschlag['felder'],
				'gefunden' => $gefunden,
				'meldung'  => $gefunden
					? __( 'Felder vorausgefüllt. Bitte alles prüfen, die Erkennung kann sich irren.', 'lindenweg16' )
					: __( 'Es wurde Text gelesen, aber kein Betrag oder Datum sicher erkannt. Bitte die Felder von Hand ausfüllen.', 'lindenweg16' ),
				'hinweise' => $vorschlag['hinweise'],
			)
		);
	}

	/**
	 * Felder aus Text, den der Browser selbst erkannt hat (Rückfall ohne Tesseract auf dem Server).
	 *
	 * Das Foto wird dafür nicht übertragen, nur der gelesene Text. Die Regeln zum Finden von
	 * Betrag, Datum und Händler sind dieselben wie bei der Erkennung auf dem Server.
	 *
	 * @param WP_REST_Request $request Anfrage mit dem Feld text.
	 * @return WP_REST_Response
	 */
	public static function ocr_text( WP_REST_Request $request ) {
		$text = (string) $request->get_param( 'text' );
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 20000 ) : substr( $text, 0, 20000 );
		if ( '' === trim( $text ) ) {
			return self::fail( __( 'Es wurde kein Text übergeben.', 'lindenweg16' ) );
		}
		$vorschlag = self::ocr_felder( $text, gmdate( 'Y-m-d' ) );
		$gefunden  = $vorschlag['gefunden'];

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'erkannt'  => (bool) $gefunden,
				'quelle'   => 'browser',
				'felder'   => $vorschlag['felder'],
				'gefunden' => $gefunden,
				'meldung'  => $gefunden
					? __( 'Felder vorausgefüllt (Texterkennung im Browser). Bitte alles prüfen, die Erkennung kann sich irren.', 'lindenweg16' )
					: __( 'Es wurde Text gelesen, aber kein Betrag oder Datum sicher erkannt. Bitte die Felder von Hand ausfüllen.', 'lindenweg16' ),
				'hinweise' => $vorschlag['hinweise'],
			)
		);
	}

	/**
	 * E-Rechnung hochladen (XML oder ZUGFeRD-PDF) und lesen.
	 *
	 * Bei einem PDF ohne lesbares XML wird nichts gespeichert; die Oberfläche
	 * fährt dann mit dem normalen Beleg-Ablauf fort.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function erechnung_hochladen( WP_REST_Request $request ) {
		$upload = self::upload_pruefen( $request, 'erechnung', true );
		if ( '' !== $upload['error'] ) {
			return self::fail( $upload['error'] );
		}

		// Der Importer prüft die Endung; der Inhalt ist oben schon geprüft. Die Endung muss dazu passen.
		$file         = $upload['file'];
		$file['name'] = 'erechnung.' . $upload['typ']['ext'];

		$parsed = ERechnungImporter::process_upload( $file );
		if ( ! $parsed ) {
			return self::fail( __( 'Die Datei konnte nicht verarbeitet werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		$ablage = trailingslashit( Installer::docs_dir() ) . basename( (string) $parsed['datei_pfad'] );

		if ( 'pdf_ohne_xml' === $parsed['format'] || (float) $parsed['betrag'] <= 0 ) {
			@unlink( $ablage ); // phpcs:ignore
			return new WP_REST_Response(
				array(
					'ok'          => true,
					'erechnung'   => false,
					'meldung'     => 'pdf_ohne_xml' === $parsed['format']
						? __( 'In diesem PDF steckt keine maschinenlesbare Rechnung (oder sie ist komprimiert). Ich lese es deshalb wie einen normalen Beleg.', 'lindenweg16' )
						: __( 'Aus der E-Rechnung ließ sich kein Betrag lesen. Bitte die Datei prüfen oder den Beleg von Hand erfassen.', 'lindenweg16' ),
				)
			);
		}

		$datum = self::valid_date( (string) $parsed['datum'] ) ? (string) $parsed['datum'] : gmdate( 'Y-m-d' );

		$vorhanden = self::erechnung_vorhanden( (string) $parsed['rechnungsnummer_ext'], (string) $parsed['absender_name'] );
		if ( $vorhanden ) {
			@unlink( $ablage ); // phpcs:ignore
			return self::fail(
				__( 'Diese E-Rechnung (gleiche Rechnungsnummer und gleicher Absender) wurde schon importiert. Sie wird nicht noch einmal angelegt.', 'lindenweg16' ),
				409,
				array( 'doppelt' => true, 'beleg_id' => $vorhanden )
			);
		}

		$er_id = ERechnungImporter::save( $parsed );
		if ( ! $er_id ) {
			@unlink( $ablage ); // phpcs:ignore
			return self::fail( __( 'Die E-Rechnung konnte nicht gespeichert werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		$felder = array(
			'betrag'     => number_format( (float) $parsed['betrag'], 2, '.', '' ),
			'datum'      => $datum,
			'haendler'   => (string) $parsed['absender_name'],
			'externe_nr' => (string) $parsed['rechnungsnummer_ext'],
		);

		$doppelte = self::doppelte_laden( array( 'betrag' => (float) $felder['betrag'], 'datum' => $datum, 'haendler' => $felder['haendler'] ) );

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'erechnung'    => true,
				'erechnung_id' => (int) $er_id,
				'format'       => (string) $parsed['format'],
				'felder'       => $felder,
				'aehnliche'    => $doppelte,
				'meldung'      => __( 'E-Rechnung gelesen. Betrag, Datum und Absender stammen aus der Rechnung und lassen sich nicht ändern.', 'lindenweg16' ),
			)
		);
	}

	/**
	 * Gibt es diese E-Rechnung schon?
	 *
	 * @param string $nummer Rechnungsnummer des Absenders.
	 * @param string $absender Absender.
	 * @return int Beleg-ID oder 0.
	 */
	protected static function erechnung_vorhanden( $nummer, $absender ) {
		global $wpdb;

		if ( '' === trim( $nummer ) ) {
			return 0;
		}

		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ERechnungImporter::table() . ' WHERE rechnungsnummer_ext = %s AND absender_name = %s LIMIT 1', $nummer, $absender ) ); // phpcs:ignore

		return (int) $id;
	}

	/**
	 * Gelesene E-Rechnung als Beleg übernehmen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function erechnung_uebernehmen( WP_REST_Request $request ) {
		$er = ERechnungImporter::get( (int) $request['id'] );
		if ( ! $er ) {
			return self::fail( __( 'Diese E-Rechnung gibt es nicht (mehr). Bitte noch einmal hochladen.', 'lindenweg16' ), 404 );
		}
		if ( 'verarbeitet' === $er->status ) {
			return self::fail( __( 'Diese E-Rechnung wurde schon als Beleg übernommen.', 'lindenweg16' ), 409 );
		}

		$p   = self::params( $request );
		$val = self::validate_input(
			array_merge(
				$p,
				array(
					'betrag'   => $er->betrag,
					'datum'    => $er->datum,
					'haendler' => $er->absender_name,
					'notiz'    => isset( $p['notiz'] ) ? $p['notiz'] : '',
				)
			),
			gmdate( 'Y-m-d' )
		);

		// Das Rechnungsdatum darf in der Zukunft liegen (Datum auf der Rechnung ist maßgeblich), der Rest muss stimmen.
		$errors = $val['errors'];
		if ( isset( $errors['datum'] ) && self::valid_date( (string) $er->datum ) ) {
			unset( $errors['datum'] );
		}
		if ( $errors ) {
			return self::fail( reset( $errors ), 422, array( 'fehler' => $errors ) );
		}
		$c = $val['clean'];

		$doppelte = self::doppelte_laden( array( 'betrag' => (float) $c['betrag'], 'datum' => (string) $er->datum, 'haendler' => (string) $er->absender_name ) );
		$trotzdem = self::flag( isset( $p['trotzdem'] ) ? $p['trotzdem'] : false );
		if ( $doppelte && ! $trotzdem ) {
			return self::fail( self::doppelt_text( count( $doppelte ) ), 409, array( 'doppelt' => true, 'aehnliche' => $doppelte ) );
		}

		$eintrag  = Kontenrahmen::get( $c['konto'] );
		$gesperrt = Lock::acquire( 'beleg_nummer' );

		try {
			$beleg_id = (int) ERechnungImporter::create_beleg( (int) $er->id, $eintrag ? $eintrag['label'] : 'Wareneinkauf' );
		} finally {
			if ( $gesperrt ) {
				Lock::release( 'beleg_nummer' );
			}
		}

		if ( ! $beleg_id ) {
			return self::fail( __( 'Die E-Rechnung ließ sich nicht als Beleg übernehmen. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		// Konto und Zusatzangaben nachtragen (nicht in der Hash-Kette): ERechnungImporter kennt nur die Kategorie.
		$beleg = BelegRepository::get( $beleg_id );
		$frei  = trim( ( $beleg ? (string) $beleg->notiz : '' ) . ( '' !== $c['notiz'] ? "\n" . $c['notiz'] : '' ) );
		$meta  = $c;
		if ( '' === $meta['externe_nr'] ) {
			$meta['externe_nr'] = (string) $er->rechnungsnummer_ext;
		}
		BelegRepository::update( $beleg_id, array( 'konto' => $c['konto'], 'notiz' => self::notiz_bauen( $frei, $meta ) ) );

		$anteil = 0.0;
		if ( $c['kampagne_id'] && KampagnenRepository::get( $c['kampagne_id'] ) ) {
			$anteil = KampagnenRepository::zuordnen( $c['kampagne_id'], $beleg_id, $c['kampagne_anteil'] );
		}

		AuditLog::record(
			'beleg_erfasst',
			'beleg',
			$beleg_id,
			array(
				'weg'          => 'erp_erechnung',
				'erechnung_id' => (int) $er->id,
				'betrag'       => number_format( (float) $er->betrag, 2, '.', '' ),
				'doppelt_bestaetigt' => (bool) $doppelte,
			)
		);

		return self::nach_anlegen( $beleg_id, self::flag( isset( $p['festschreiben'] ) ? $p['festschreiben'] : false ), $anteil );
	}
}

