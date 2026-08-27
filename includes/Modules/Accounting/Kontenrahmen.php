<?php
/**
 * Kontenrahmen SKR03: die Kostenart-Achse der Buchhaltung.
 *
 * Trennt bewusst von der Kostenstelle (Kampagne): Das Konto sagt, WAS
 * gekauft wurde und in welche EÜR-Zeile es gehört, die Kostenstelle sagt,
 * WOFÜR. Ein Marktbesuch verteilt sich über mehrere Konten (Standgebühr,
 * Fahrtkosten, Verbrauchsmaterial), bleibt aber eine Kostenstelle.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

/**
 * Kuratierter Auszug aus SKR03 – nicht der volle Kontenrahmen, sondern die
 * Konten, die im Betrieb tatsächlich vorkommen. Über den Filter
 * `lw16_kontenrahmen` erweiterbar, ohne diese Datei anzufassen.
 */
class Kontenrahmen {

	/**
	 * Erlöskonto für den Regelfall (Kleinunternehmer).
	 */
	const KONTO_ERLOES_KU = '8195';

	/**
	 * Erlöskonto bei Regelbesteuerung, 19 % USt.
	 */
	const KONTO_ERLOES_19 = '8400';

	/**
	 * Werbekosten – Standgebühr, Anzeigenschaltung, Flyerdruck.
	 */
	const KONTO_WERBUNG = '4600';

	/**
	 * Reisekosten Unternehmer, Fahrtkosten – hier landet das Kilometergeld.
	 */
	const KONTO_FAHRTKOSTEN = '4672';

	/**
	 * Nebenkosten des Geldverkehrs – Zahlungsgebühren der Bestellungen.
	 */
	const KONTO_GELDVERKEHR = '4970';

	/**
	 * Sammelkonto, wenn nichts Genaueres feststeht.
	 */
	const KONTO_SONSTIGES = '4900';

	/**
	 * Kontenstamm: Nummer => Bezeichnung, Obergruppe, Typ, Abzugsfähigkeit.
	 *
	 * `abzugsfaehig` ist der Prozentsatz, der als Betriebsausgabe zählt.
	 * Er weicht nur bei Bewirtung von 100 ab – aber genau dort wäre ein
	 * stiller Vollabzug ein Fehler in der EÜR.
	 *
	 * @return array
	 */
	public static function konten() {
		$konten = array(
			// --- Erlöse ---------------------------------------------------
			'8195' => array( 'Erlöse als Kleinunternehmer §19 UStG', 'Erlöse', 'einnahme', 100, '' ),
			'8400' => array( 'Erlöse 19 % USt', 'Erlöse', 'einnahme', 100, '' ),
			'8300' => array( 'Erlöse 7 % USt', 'Erlöse', 'einnahme', 100, '' ),

			// --- Wareneinsatz ---------------------------------------------
			'3200' => array( 'Wareneingang', 'Wareneinsatz', 'ausgabe', 100, '' ),
			'3800' => array( 'Bezugsnebenkosten', 'Wareneinsatz', 'ausgabe', 100, '' ),

			// --- Raum und Grundlasten -------------------------------------
			'4210' => array( 'Miete (unbewegliche Wirtschaftsgüter)', 'Raumkosten', 'ausgabe', 100, '' ),
			'4240' => array( 'Gas, Strom, Wasser', 'Raumkosten', 'ausgabe', 100, '' ),
			'4360' => array( 'Versicherungen', 'Versicherungen und Beiträge', 'ausgabe', 100, '' ),
			'4380' => array( 'Beiträge (IHK, Berufsgenossenschaft)', 'Versicherungen und Beiträge', 'ausgabe', 100, '' ),

			// --- Werbung und Reisen ---------------------------------------
			'4600' => array( 'Werbekosten', 'Werbe- und Reisekosten', 'ausgabe', 100, 'Standgebühr, Anzeigen, Flyerdruck, Google Ads.' ),
			'4610' => array( 'Streuartikel (Werbegeschenke bis 10 €)', 'Werbe- und Reisekosten', 'ausgabe', 100, '' ),
			'4630' => array( 'Geschenke abzugsfähig', 'Werbe- und Reisekosten', 'ausgabe', 100, 'Nur bis zur Freigrenze je Empfänger und Jahr.' ),
			'4650' => array( 'Bewirtungskosten', 'Werbe- und Reisekosten', 'ausgabe', 70, 'Geschäftliche Bewirtung ist nur zu 70 % abziehbar; Anlass und Teilnehmer gehören auf den Beleg.' ),
			'4653' => array( 'Aufmerksamkeiten', 'Werbe- und Reisekosten', 'ausgabe', 100, 'Kaffee und Kekse am Stand – im Gegensatz zur Bewirtung voll abziehbar.' ),
			'4672' => array( 'Reisekosten Unternehmer: Fahrtkosten', 'Werbe- und Reisekosten', 'ausgabe', 100, 'Kilometerpauschale für Fahrten mit dem privaten Fahrzeug.' ),
			'4674' => array( 'Reisekosten Unternehmer: Verpflegungsmehraufwand', 'Werbe- und Reisekosten', 'ausgabe', 100, 'Nur die gesetzlichen Pauschalen, keine Einzelbelege.' ),
			'4676' => array( 'Reisekosten Unternehmer: Übernachtung', 'Werbe- und Reisekosten', 'ausgabe', 100, '' ),

			// --- Kfz -------------------------------------------------------
			'4530' => array( 'Laufende Kfz-Betriebskosten', 'Fahrzeugkosten', 'ausgabe', 100, 'Nur für ein Fahrzeug im Betriebsvermögen – sonst 4672.' ),

			// --- Büro und Verwaltung ---------------------------------------
			'4910' => array( 'Porto', 'Verwaltungskosten', 'ausgabe', 100, '' ),
			'4920' => array( 'Telefon und Internet', 'Verwaltungskosten', 'ausgabe', 100, '' ),
			'4930' => array( 'Bürobedarf', 'Verwaltungskosten', 'ausgabe', 100, '' ),
			'4940' => array( 'Zeitschriften und Bücher', 'Verwaltungskosten', 'ausgabe', 100, '' ),
			'4945' => array( 'Fortbildungskosten', 'Verwaltungskosten', 'ausgabe', 100, '' ),
			'4950' => array( 'Rechts- und Beratungskosten', 'Verwaltungskosten', 'ausgabe', 100, '' ),
			'4955' => array( 'Buchführungskosten', 'Verwaltungskosten', 'ausgabe', 100, '' ),
			'4965' => array( 'Wartungskosten Hard- und Software', 'Verwaltungskosten', 'ausgabe', 100, 'Hosting, Software-Abos, Lizenzen.' ),
			'4970' => array( 'Nebenkosten des Geldverkehrs', 'Verwaltungskosten', 'ausgabe', 100, 'Zahlungsgebühren der Bestellungen laufen automatisch hierher.' ),

			// --- Material und Abschreibung ---------------------------------
			'4980' => array( 'Betriebsbedarf (Verbrauchsmaterial)', 'Betriebsbedarf', 'ausgabe', 100, 'Filament, Verpackung, Kleinmaterial.' ),
			'4985' => array( 'Werkzeuge und Kleingeräte', 'Betriebsbedarf', 'ausgabe', 100, '' ),
			'4830' => array( 'Abschreibungen auf Sachanlagen (AfA)', 'Abschreibungen', 'ausgabe', 100, '' ),
			'4855' => array( 'Sofortabschreibung geringwertiger Wirtschaftsgüter', 'Abschreibungen', 'ausgabe', 100, '' ),

			// --- Rest ------------------------------------------------------
			'4900' => array( 'Sonstige betriebliche Aufwendungen', 'Sonstiges', 'ausgabe', 100, '' ),
		);

		$out = array();
		foreach ( $konten as $nr => $row ) {
			$out[ (string) $nr ] = array(
				'konto'        => (string) $nr,
				'label'        => $row[0],
				'gruppe'       => $row[1],
				'typ'          => $row[2],
				'abzugsfaehig' => (float) $row[3],
				'hinweis'      => $row[4],
			);
		}

		/**
		 * Kontenrahmen erweitern oder anpassen.
		 *
		 * @param array $out Konten nach Nummer.
		 */
		return function_exists( 'apply_filters' ) ? apply_filters( 'lw16_kontenrahmen', $out ) : $out;
	}

	/**
	 * Konten eines Typs, nach Nummer sortiert.
	 *
	 * @param string $typ ausgabe|einnahme, leer = alle.
	 * @return array
	 */
	public static function all( $typ = '' ) {
		$konten = self::konten();
		if ( '' === $typ ) {
			ksort( $konten );
			return $konten;
		}
		$out = array();
		foreach ( $konten as $nr => $k ) {
			if ( $k['typ'] === $typ ) {
				$out[ $nr ] = $k;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Konten eines Typs nach Obergruppe gebündelt – für Auswahlfelder.
	 *
	 * @param string $typ ausgabe|einnahme.
	 * @return array Gruppe => [ Konto => Datensatz ].
	 */
	public static function grouped( $typ = 'ausgabe' ) {
		$out = array();
		foreach ( self::all( $typ ) as $nr => $k ) {
			$out[ $k['gruppe'] ][ $nr ] = $k;
		}
		return $out;
	}

	/**
	 * Einzelnes Konto holen.
	 *
	 * @param string $konto Kontonummer.
	 * @return array|null
	 */
	public static function get( $konto ) {
		$konten = self::konten();
		$konto  = (string) $konto;
		return isset( $konten[ $konto ] ) ? $konten[ $konto ] : null;
	}

	/**
	 * Gibt es das Konto?
	 *
	 * @param string $konto Kontonummer.
	 * @return bool
	 */
	public static function exists( $konto ) {
		return null !== self::get( $konto );
	}

	/**
	 * Bezeichnung eines Kontos, mit Nummer davor.
	 *
	 * @param string $konto Kontonummer.
	 * @return string
	 */
	public static function label( $konto ) {
		$k = self::get( $konto );
		if ( ! $k ) {
			return (string) $konto;
		}
		return $k['konto'] . ' ' . $k['label'];
	}

	/**
	 * Obergruppe eines Kontos – die Gliederungsebene der EÜR-Auswertung.
	 *
	 * @param string $konto Kontonummer.
	 * @return string
	 */
	public static function gruppe( $konto ) {
		$k = self::get( $konto );
		return $k ? $k['gruppe'] : __( 'ohne Zuordnung', 'lindenweg16' );
	}

	/**
	 * Abzugsfähiger Anteil eines Kontos in Prozent.
	 *
	 * @param string $konto Kontonummer.
	 * @return float
	 */
	public static function abzugsfaehig( $konto ) {
		$k = self::get( $konto );
		return $k ? (float) $k['abzugsfaehig'] : 100.0;
	}

	/**
	 * Betriebsausgabe eines Belegs: Betrag mal abzugsfähigem Anteil.
	 *
	 * Für die EÜR maßgeblich – bei Bewirtung stehen 100 € auf dem Beleg,
	 * abziehbar sind aber nur 70 €.
	 *
	 * @param string $konto  Kontonummer.
	 * @param float  $betrag Belegbetrag.
	 * @return float
	 */
	public static function abziehbarer_betrag( $konto, $betrag ) {
		return round( (float) $betrag * self::abzugsfaehig( $konto ) / 100, 2 );
	}

	/**
	 * Erlöskonto passend zur Umsatzsteuer-Einstellung.
	 *
	 * @param bool $kleinunternehmer Kleinunternehmer nach §19 UStG.
	 * @return string
	 */
	public static function erloes_konto( $kleinunternehmer = true ) {
		return $kleinunternehmer ? self::KONTO_ERLOES_KU : self::KONTO_ERLOES_19;
	}

	/**
	 * Übersetzung der alten Freitext-Kategorien auf Konten.
	 *
	 * Die Belege vor Einführung des Kontenrahmens tragen nur einen Text in
	 * `eur_kategorie`. Der bleibt unangetastet stehen – er steckt in der
	 * Hash-Kette –, aber die Auswertung braucht für ihn ein Konto.
	 *
	 * @return array Text => Kontonummer.
	 */
	public static function legacy_map() {
		return array(
			'Wareneinkauf'                 => '3200',
			'Büromaterial'                 => '4930',
			'Fahrtkosten'                  => self::KONTO_FAHRTKOSTEN,
			'Telefon/Internet'             => '4920',
			'Fortbildung'                  => '4945',
			'Porto/Versand'                => '4910',
			'Hosting/Software'             => '4965',
			'AfA'                          => '4830',
			'Sonstiges'                    => self::KONTO_SONSTIGES,
			'Nebenkosten des Geldverkehrs' => self::KONTO_GELDVERKEHR,
			'Marktverkauf'                 => self::KONTO_ERLOES_KU,
		);
	}

	/**
	 * Konto zu einer alten Freitext-Kategorie finden.
	 *
	 * @param string $kategorie Kategorietext.
	 * @return string Kontonummer, leer wenn unbekannt.
	 */
	public static function konto_fuer_kategorie( $kategorie ) {
		$map       = self::legacy_map();
		$kategorie = trim( (string) $kategorie );
		return isset( $map[ $kategorie ] ) ? $map[ $kategorie ] : '';
	}
}
