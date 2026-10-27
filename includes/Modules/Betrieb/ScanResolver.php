<?php
/**
 * Auflösung eines gescannten Codes.
 *
 * Am Handy weiß niemand vorher, was gleich vor der Kamera liegt: ein
 * Lagerplatz-Etikett, ein Artikeletikett, eine Charge oder der aufgedruckte
 * EAN eines zugekauften Beutels. Deshalb wird nicht der Nutzer gefragt, was
 * er gescannt hat, sondern der Code selbst befragt.
 *
 * Die Erkennung läuft in zwei Stufen: Erst ein eindeutiges Präfix aus
 * eigenen Etiketten, dann die freie Suche über alle bekannten Codes. Die
 * freie Suche ist nötig, weil zugekaufte Ware fremde Barcodes trägt, die
 * niemand neu bekleben will.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Erkennt, worauf ein gescannter Code zeigt.
 */
class ScanResolver {

	/**
	 * Präfix eigener Lagerplatz-Etiketten.
	 */
	const PREFIX_BIN = 'LW16B:';

	/**
	 * Präfix eigener Artikeletiketten.
	 */
	const PREFIX_ARTICLE = 'LW16A:';

	/**
	 * Präfix eigener Chargenetiketten.
	 */
	const PREFIX_BATCH = 'LW16C:';

	/**
	 * Präfix eigener Filamentrollen-Etiketten.
	 */
	const PREFIX_ROLL = 'LW16R:';

	/**
	 * Rohen Scan säubern.
	 *
	 * Scanner hängen gern ein Zeilenende an, und manche Kameras liefern
	 * führende Leerzeichen. Beides würde jeden Vergleich scheitern lassen.
	 *
	 * @param string $raw Rohwert.
	 * @return string
	 */
	public static function clean( $raw ) {
		$raw = (string) $raw;
		$raw = str_replace( array( "\r", "\n", "\t" ), '', $raw );

		return trim( $raw );
	}

	/**
	 * Präfix abtrennen, wenn es eines der eigenen ist.
	 *
	 * Reine Zeichenkettenlogik ohne Datenbank.
	 *
	 * @param string $code Gesäuberter Code.
	 * @return array {kind, value} – kind ist bin, article, batch oder unknown.
	 */
	public static function split( $code ) {
		$map = array(
			self::PREFIX_BIN     => 'bin',
			self::PREFIX_ARTICLE => 'article',
			self::PREFIX_BATCH   => 'batch',
			self::PREFIX_ROLL    => 'roll',
		);

		foreach ( $map as $prefix => $kind ) {
			if ( 0 === stripos( $code, $prefix ) ) {
				return array(
					'kind'  => $kind,
					'value' => trim( substr( $code, strlen( $prefix ) ) ),
				);
			}
		}

		// Eigene URLs dürfen ebenfalls gescannt werden – der Nutzer soll nicht
		// unterscheiden müssen, ob auf dem Zettel ein Code oder ein Link steht.
		$query = wp_parse_url( $code, PHP_URL_QUERY );
		if ( $query ) {
			parse_str( $query, $args );
			if ( ! empty( $args['lw16_code'] ) ) {
				return self::split( self::clean( $args['lw16_code'] ) );
			}
		}

		return array(
			'kind'  => 'unknown',
			'value' => $code,
		);
	}

	/**
	 * Gescannten Code auflösen.
	 *
	 * @param string $raw Rohwert vom Scanner oder aus dem Eingabefeld.
	 * @return array {type, id, code, label, extra} oder type = 'none'.
	 */
	public static function resolve( $raw ) {
		$code = self::clean( $raw );

		if ( '' === $code ) {
			return self::nothing();
		}

		$split = self::split( $code );

		if ( 'bin' === $split['kind'] ) {
			return self::as_bin( BinRepository::find_by_code( $split['value'] ) );
		}

		if ( 'article' === $split['kind'] ) {
			return self::as_article( ArticleRepository::find_by_sku( $split['value'] ) );
		}

		if ( 'batch' === $split['kind'] ) {
			return self::as_batch( BatchRepository::find_by_code( $split['value'] ) );
		}

		if ( 'roll' === $split['kind'] ) {
			return self::as_roll( FilamentRolls::find_by_code( $split['value'] ) );
		}

		// Freie Suche: eigene Codes zuerst, dann fremde Barcodes.
		if ( preg_match( '/^R-\d{4,}$/i', $code ) ) {
			$roll = FilamentRolls::find_by_code( $code );
			if ( $roll ) {
				return self::as_roll( $roll );
			}
		}

		$bin = BinRepository::find_by_code( $code );
		if ( $bin ) {
			return self::as_bin( $bin );
		}

		$article = ArticleRepository::find_by_sku( $code );
		if ( $article ) {
			return self::as_article( $article );
		}

		$article = ArticleRepository::find_by_barcode( $code );
		if ( $article ) {
			return self::as_article( $article );
		}

		$batch = BatchRepository::find_by_code( $code );
		if ( $batch ) {
			return self::as_batch( $batch );
		}

		$component = ComponentRepository::find_by_code_or_barcode( $code );
		if ( $component ) {
			return array(
				'type'  => 'component',
				'id'    => (int) $component['id'],
				'code'  => (string) $component['code'],
				'label' => (string) $component['name'],
				'extra' => array(),
			);
		}

		return self::nothing( $code );
	}

	/**
	 * Nichts gefunden.
	 *
	 * @param string $code Gescannter Code.
	 * @return array
	 */
	protected static function nothing( $code = '' ) {
		return array(
			'type'  => 'none',
			'id'    => 0,
			'code'  => (string) $code,
			'label' => '',
			'extra' => array(),
		);
	}

	/**
	 * Lagerplatz als Ergebnis.
	 *
	 * @param array|null $bin Platz.
	 * @return array
	 */
	protected static function as_bin( $bin ) {
		if ( ! $bin ) {
			return self::nothing();
		}

		return array(
			'type'  => 'bin',
			'id'    => (int) $bin['id'],
			'code'  => (string) $bin['code'],
			'label' => (string) $bin['name'],
			'extra' => array( 'kind' => (string) $bin['kind'] ),
		);
	}

	/**
	 * Artikel als Ergebnis.
	 *
	 * @param array|null $article Artikel.
	 * @return array
	 */
	protected static function as_article( $article ) {
		if ( ! $article ) {
			return self::nothing();
		}

		return array(
			'type'  => 'article',
			'id'    => (int) $article['id'],
			'code'  => (string) $article['sku'],
			'label' => (string) $article['name'],
			'extra' => array(),
		);
	}

	/**
	 * Filamentrolle als Ergebnis.
	 *
	 * @param array|null $roll Rolle.
	 * @return array
	 */
	protected static function as_roll( $roll ) {
		if ( ! $roll ) {
			return self::nothing();
		}

		$d = FilamentRolls::describe( $roll );

		return array(
			'type'  => 'roll',
			'id'    => (int) $roll['id'],
			'code'  => (string) $roll['roll_code'],
			'label' => trim( $d['color_label'] . ' ' . $d['material'] ),
			'extra' => $d,
		);
	}

	/**
	 * Charge als Ergebnis – sie zeigt auf ihren Artikel.
	 *
	 * @param array|null $batch Charge.
	 * @return array
	 */
	protected static function as_batch( $batch ) {
		if ( ! $batch ) {
			return self::nothing();
		}

		return array(
			'type'  => 'batch',
			'id'    => (int) $batch['id'],
			'code'  => (string) $batch['batch_code'],
			'label' => (string) $batch['batch_code'],
			'extra' => array(
				'article_id' => (int) $batch['article_id'],
				'color'      => isset( $batch['color'] ) ? (string) $batch['color'] : '',
			),
		);
	}
}
