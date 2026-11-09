<?php
/**
 * Varianten: ein Shop-Produkt, mehrere Artikel dahinter.
 *
 * Im Shop gibt es den Schuhhalter einmal, wählbar nach Farbe und Typ (SPD,
 * SPD-SL, …), das Basismodul einmal, wählbar nach Farbe und Holz. Im ERP ist
 * jeder Typ und jedes Holz ein eigener Artikel – mit eigener Stückliste
 * (eigenes Druckteil, eigenes Brett), eigenem Bestand und eigenem Modell im
 * Konfigurator. Die Farbe bleibt, was sie war: die Achse des Bestands
 * innerhalb eines Artikels.
 *
 * Die Brücke ist das Feld `wc_variant` am Artikel: Es nennt die Merkmale der
 * Shop-Varianten, für die der Artikel steht, etwa `pa_schuhsystem=spd`.
 * Mehrere Merkmale werden mit `&` verbunden. Leer heißt: der Artikel steht
 * für das ganze Produkt (wie bisher).
 *
 * Ein Set enthält Module, die selbst Varianten haben. Wählt der Kunde am Set
 * den Typ des Schuhhalters oder das Holz, tauscht die Stückliste das Modul
 * gegen den passenden Geschwisterartikel (gleiches Shop-Produkt, passendes
 * Merkmal). Ein solches Set – eines mit wählbaren Modulen – lässt sich nicht
 * auf Vorrat montieren, weil erst die Bestellung sagt, was hineinkommt. Es
 * wird deshalb beim Verkauf aus seinen Modulen gepackt: Gebucht, reserviert
 * und gepickt werden die Module, nicht das Set.
 *
 * Bewusst ohne Datenbank und ohne WooCommerce: Der Aufrufer lädt einmal und
 * übergibt den Kontext. Das macht die Regeln testbar.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Zuordnung Shop-Variante zu Artikel und Variantentausch in der Stückliste.
 */
class Variants {

	/**
	 * Ein Merkmal oder eine Ausprägung als Kürzel.
	 *
	 * Klein, ohne Umlaute, Trennzeichen als Bindestrich – so wie WooCommerce
	 * seine Kürzel bildet. Beide Seiten eines Vergleichs gehen hier durch,
	 * deshalb passt „SPD-SL“ zu `spd-sl` und „Weiß“ zu `weiss`.
	 *
	 * @param string $value Rohwert.
	 * @return string
	 */
	public static function slug( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = strtr(
			$value,
			array(
				'ä' => 'ae',
				'ö' => 'oe',
				'ü' => 'ue',
				'ß' => 'ss',
				'é' => 'e',
				'è' => 'e',
			)
		);
		$value = preg_replace( '/[^a-z0-9_]+/', '-', $value );

		return trim( (string) $value, '-' );
	}

	/**
	 * Den Namen eines Merkmals vereinheitlichen.
	 *
	 * Varianten speichern `attribute_pa_holz`, Bestellpositionen `pa_holz`,
	 * eigene Attribute heißen nach ihrem Namen („Holz“).
	 *
	 * @param string $key Rohname.
	 * @return string
	 */
	public static function key( $key ) {
		$key = trim( (string) $key );

		if ( 0 === strpos( $key, 'attribute_' ) ) {
			$key = substr( $key, 10 );
		}

		return self::slug( $key );
	}

	/**
	 * Merkmale aus dem Feld am Artikel lesen.
	 *
	 * Erlaubt `pa_schuhsystem=spd`, mehrere mit `&`, Komma, Semikolon oder
	 * Zeilenumbruch getrennt. Was kein `=` hat, fällt heraus.
	 *
	 * @param string $text Feldinhalt.
	 * @return array Merkmal => Ausprägung, nach Merkmal sortiert.
	 */
	public static function parse( $text ) {
		$pairs = array();

		foreach ( preg_split( '/[&,;\r\n]+/', (string) $text ) as $chunk ) {
			if ( false === strpos( $chunk, '=' ) ) {
				continue;
			}

			list( $key, $value ) = explode( '=', $chunk, 2 );
			$key                 = self::key( $key );
			$value               = self::slug( $value );

			if ( '' !== $key && '' !== $value ) {
				$pairs[ $key ] = $value;
			}
		}

		ksort( $pairs );

		return $pairs;
	}

	/**
	 * Merkmale als Feldinhalt.
	 *
	 * @param array $pairs Merkmal => Ausprägung.
	 * @return string
	 */
	public static function format( array $pairs ) {
		$pairs = self::normalize_attributes( $pairs );
		$out   = array();

		foreach ( $pairs as $key => $value ) {
			$out[] = $key . '=' . $value;
		}

		return implode( '&', $out );
	}

	/**
	 * Merkmale einer Variante oder Bestellposition vereinheitlichen.
	 *
	 * @param array $raw Name => Wert (Namen wie `attribute_pa_holz` oder `Holz`).
	 * @return array Merkmal => Ausprägung, leere Werte entfallen.
	 */
	public static function normalize_attributes( array $raw ) {
		$out = array();

		foreach ( $raw as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$key   = self::key( (string) $key );
			$value = self::slug( (string) $value );

			if ( '' !== $key && '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Erfüllt eine Auswahl alle Merkmale eines Artikels?
	 *
	 * @param array $pairs Merkmale des Artikels.
	 * @param array $attrs Merkmale der Auswahl (vereinheitlicht).
	 * @return bool
	 */
	public static function matches( array $pairs, array $attrs ) {
		foreach ( $pairs as $key => $value ) {
			if ( ! isset( $attrs[ $key ] ) || (string) $attrs[ $key ] !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Passt eine Shop-Variante zu den Merkmalen eines Artikels?
	 *
	 * Anders als matches() gilt hier eine Variante mit „beliebig“ (leerer
	 * Wert) als passend – WooCommerce lässt den Kunden dann selbst wählen.
	 * Ein Merkmal, das die Variante gar nicht kennt, passt nicht.
	 *
	 * @param array $pairs Merkmale des Artikels.
	 * @param array $raw   Attribute der Variante (`attribute_pa_holz` => Wert).
	 * @return bool
	 */
	public static function fits( array $pairs, array $raw ) {
		$attrs = array();

		foreach ( $raw as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$attrs[ self::key( (string) $key ) ] = self::slug( (string) $value );
			}
		}

		foreach ( $pairs as $key => $value ) {
			if ( ! array_key_exists( $key, $attrs ) ) {
				return false;
			}

			if ( '' !== $attrs[ $key ] && $attrs[ $key ] !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Artikel je Shop-Produkt.
	 *
	 * Ausgelaufene Artikel zählen nur, solange ihr Produkt keinen aktiven
	 * hat – sonst würde ein alter Artikel einem neuen die Variante streitig
	 * machen.
	 *
	 * @param array $articles Artikel (id => Zeile mit wc_product_id, wc_variant, status).
	 * @return array wc_product_id => article_id => Merkmale.
	 */
	public static function groups( array $articles ) {
		$all  = array();
		$live = array();

		foreach ( $articles as $id => $article ) {
			$article = (array) $article;
			$product = isset( $article['wc_product_id'] ) ? (int) $article['wc_product_id'] : 0;

			if ( $product < 1 ) {
				continue;
			}

			$id    = isset( $article['id'] ) ? (int) $article['id'] : (int) $id;
			$pairs = self::parse( isset( $article['wc_variant'] ) ? (string) $article['wc_variant'] : '' );

			$all[ $product ][ $id ] = $pairs;

			if ( 'ausgelaufen' !== ( isset( $article['status'] ) ? (string) $article['status'] : '' ) ) {
				$live[ $product ][ $id ] = $pairs;
			}
		}

		$out = array();

		foreach ( $all as $product => $members ) {
			$members = isset( $live[ $product ] ) ? $live[ $product ] : $members;
			ksort( $members );
			$out[ $product ] = $members;
		}

		return $out;
	}

	/**
	 * Den Artikel zu einer Auswahl wählen.
	 *
	 * Gewinnt der Artikel, dessen Merkmale alle passen und der die meisten
	 * hat. Passt keiner, gilt ein Artikel ohne Merkmale (er steht für das
	 * ganze Produkt). Gibt es auch den nicht, wird der erste genommen und als
	 * ungeklärt gemeldet – eine Bestellung wird nie verworfen.
	 *
	 * @param array $group Artikel-ID => Merkmale (aus groups()).
	 * @param array $attrs Merkmale der Auswahl.
	 * @return array {article_id, exact}
	 */
	public static function pick( array $group, array $attrs ) {
		$best    = 0;
		$score   = -1;
		$generic = 0;

		foreach ( $group as $id => $pairs ) {
			if ( ! $pairs ) {
				if ( ! $generic ) {
					$generic = (int) $id;
				}
				continue;
			}

			if ( self::matches( $pairs, $attrs ) && count( $pairs ) > $score ) {
				$best  = (int) $id;
				$score = count( $pairs );
			}
		}

		if ( $best ) {
			return array( 'article_id' => $best, 'exact' => true );
		}

		if ( $generic ) {
			return array( 'article_id' => $generic, 'exact' => true );
		}

		$ids = array_keys( $group );

		return array(
			'article_id' => $ids ? (int) reset( $ids ) : 0,
			'exact'      => false,
		);
	}

	/**
	 * Kontext aus Artikeln und Stückliste.
	 *
	 * @param array $articles Artikel (id => Zeile).
	 * @param array $bom      Stückliste (parent_id => Zeilen).
	 * @return array {articles, bom, groups}
	 */
	public static function context( array $articles, array $bom ) {
		return array(
			'articles' => $articles,
			'bom'      => $bom,
			'groups'   => self::groups( $articles ),
		);
	}

	/**
	 * Die Gruppe, zu der ein Artikel gehört.
	 *
	 * @param int   $article_id Artikel.
	 * @param array $ctx        Kontext aus context().
	 * @return array Artikel-ID => Merkmale (leer, wenn ohne Shop-Produkt).
	 */
	public static function group_of( $article_id, array $ctx ) {
		$article = isset( $ctx['articles'][ (int) $article_id ] ) ? (array) $ctx['articles'][ (int) $article_id ] : array();
		$product = isset( $article['wc_product_id'] ) ? (int) $article['wc_product_id'] : 0;

		return $product && isset( $ctx['groups'][ $product ] ) ? $ctx['groups'][ $product ] : array();
	}

	/**
	 * Ist ein Artikel eine wählbare Variante?
	 *
	 * Wählbar ist er, wenn er Merkmale trägt oder sein Shop-Produkt weitere
	 * Artikel hat.
	 *
	 * @param int   $article_id Artikel.
	 * @param array $ctx        Kontext.
	 * @return bool
	 */
	public static function choosable( $article_id, array $ctx ) {
		$group = self::group_of( $article_id, $ctx );

		if ( count( $group ) > 1 ) {
			return true;
		}

		return ! empty( $group[ (int) $article_id ] );
	}

	/**
	 * Ein Modul der Stückliste gegen das gewählte Geschwister tauschen.
	 *
	 * Ohne passende Auswahl bleibt es beim Modul der Stückliste.
	 *
	 * @param int   $child_id Modul laut Stückliste.
	 * @param array $attrs    Merkmale der Auswahl.
	 * @param array $ctx      Kontext.
	 * @return int Artikel, der tatsächlich hineinkommt.
	 */
	public static function swap( $child_id, array $attrs, array $ctx ) {
		$child_id = (int) $child_id;

		if ( ! self::choosable( $child_id, $ctx ) ) {
			return $child_id;
		}

		$group = self::group_of( $child_id, $ctx );
		$best  = 0;
		$score = 0;

		foreach ( $group as $id => $pairs ) {
			if ( $pairs && self::matches( $pairs, $attrs ) && count( $pairs ) > $score ) {
				$best  = (int) $id;
				$score = count( $pairs );
			}
		}

		return $best ? $best : $child_id;
	}

	/**
	 * Wird dieser Artikel beim Verkauf aus seinen Modulen gepackt?
	 *
	 * Das gilt für jeden Artikel, in dessen Stückliste direkt ein wählbares
	 * Modul steht – also für Sets mit Schuhhalter oder Basismodul, sobald es
	 * diese in mehreren Varianten gibt.
	 *
	 * @param int   $article_id Artikel.
	 * @param array $ctx        Kontext.
	 * @return bool
	 */
	public static function packs_at_sale( $article_id, array $ctx ) {
		$rows = isset( $ctx['bom'][ (int) $article_id ] ) ? (array) $ctx['bom'][ (int) $article_id ] : array();

		foreach ( $rows as $row ) {
			$row = (array) $row;

			if ( 'artikel' === ( isset( $row['child_type'] ) ? $row['child_type'] : '' )
				&& (int) $row['child_id'] !== (int) $article_id
				&& self::choosable( (int) $row['child_id'], $ctx ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Was in ein gepacktes Set hineinkommt.
	 *
	 * Nur die direkte Stückliste: Die Module sind fertige Artikel mit
	 * eigenem Bestand, ihre Kleinteile sind beim Montieren schon verbaut.
	 *
	 * @param int   $article_id Set.
	 * @param float $qty        Menge.
	 * @param array $attrs      Merkmale der Auswahl.
	 * @param array $ctx        Kontext.
	 * @return array {articles: id => Menge, components: id => Menge}
	 */
	public static function pack( $article_id, $qty, array $attrs, array $ctx ) {
		$rows = isset( $ctx['bom'][ (int) $article_id ] ) ? (array) $ctx['bom'][ (int) $article_id ] : array();
		$out  = array(
			'articles'   => array(),
			'components' => array(),
		);

		foreach ( $rows as $row ) {
			$row   = (array) $row;
			$type  = isset( $row['child_type'] ) ? (string) $row['child_type'] : 'komponente';
			$child = isset( $row['child_id'] ) ? (int) $row['child_id'] : 0;
			$need  = ( isset( $row['qty'] ) ? (float) $row['qty'] : 0.0 ) * (float) $qty;

			if ( ! $child || $need <= 0 ) {
				continue;
			}

			if ( 'artikel' === $type ) {
				$child                        = self::swap( $child, $attrs, $ctx );
				$out['articles'][ $child ]    = ( isset( $out['articles'][ $child ] ) ? $out['articles'][ $child ] : 0.0 ) + $need;
			} else {
				$out['components'][ $child ] = ( isset( $out['components'][ $child ] ) ? $out['components'][ $child ] : 0.0 ) + $need;
			}
		}

		return $out;
	}

	/**
	 * Lagerzeilen einer Bestellposition – reine Logik.
	 *
	 * @param int    $product_id Shop-Produkt (Elternprodukt).
	 * @param array  $attrs      Merkmale der Position (vereinheitlicht).
	 * @param string $color      Farbe.
	 * @param string $resolved   Quelle der Farbe.
	 * @param float  $qty        Menge.
	 * @param array  $ctx        Kontext.
	 * @return array Zeilen {article_id, color, resolved, qty, exact, set_id, components}.
	 *               Leer, wenn das Produkt keinen Artikel hat.
	 */
	public static function lines( $product_id, array $attrs, $color, $resolved, $qty, array $ctx ) {
		$group = isset( $ctx['groups'][ (int) $product_id ] ) ? $ctx['groups'][ (int) $product_id ] : array();

		if ( ! $group ) {
			return array();
		}

		$pick = self::pick( $group, $attrs );
		$id   = (int) $pick['article_id'];

		if ( ! $id ) {
			return array();
		}

		if ( ! self::packs_at_sale( $id, $ctx ) ) {
			return array(
				array(
					'article_id' => $id,
					'color'      => (string) $color,
					'resolved'   => (string) $resolved,
					'qty'        => (float) $qty,
					'exact'      => (bool) $pick['exact'],
					'set_id'     => 0,
					'components' => array(),
				),
			);
		}

		$packed = self::pack( $id, (float) $qty, $attrs, $ctx );
		$out    = array();
		$first  = true;

		foreach ( $packed['articles'] as $child => $need ) {
			$out[] = array(
				'article_id' => (int) $child,
				'color'      => (string) $color,
				'resolved'   => (string) $resolved,
				'qty'        => (float) $need,
				'exact'      => (bool) $pick['exact'],
				'set_id'     => $id,
				// Die Kleinteile des Sets selbst hängen an der ersten Zeile,
				// damit sie genau einmal gebucht werden.
				'components' => $first ? $packed['components'] : array(),
			);
			$first = false;
		}

		return $out;
	}
}
