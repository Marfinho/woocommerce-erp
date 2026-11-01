<?php
/**
 * ERP-Seite „Inventur“: Zählliste zum Stichtag, Differenzen buchen, Bestandswert.
 *
 * Ablauf: Inventur anlegen → Soll-Bestand (Lager, je Artikel und Farbe) wird
 * festgehalten → zählen, am Handy Zeile für Zeile, auch Artikel nachtragen,
 * die gar nicht auf der Liste standen → abschließen. Beim Abschluss wird je
 * Zeile die Differenz zwischen gezählter Menge und dem Bestand **zum Zeitpunkt
 * des Zählens** als Korrektur gebucht (ref_type „inventur“). Verkäufe zwischen
 * Zählen und Abschluss gehen dadurch nicht verloren. Nicht gezählte Zeilen
 * bleiben unberührt und stehen im Protokoll.
 *
 * Bestandswert: Bestand (Lager, beim Händler, unterwegs) zu einem Stichtag aus
 * dem Bewegungskonto, bewertet mit den Herstellkosten der Kalkulation. Sehen
 * darf ihn nur, wer „Preise, Kosten und Löhne sehen“ hat.
 *
 * Reine Regeln sind getestet (tests/ErpInventurTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\StockRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inventur und Bestandswert.
 */
class Inventur {

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Bestand eines Artikels in einer Farbe an einem Ort bis zu einem Zeitpunkt.
	 *
	 * @param array  $movements Bewegungen (article_id, color, location, qty, moved_at).
	 * @param int    $article   Artikel.
	 * @param string $color     Farbe.
	 * @param string $location  Ort.
	 * @param string $until     Zeitpunkt UTC 'Y-m-d H:i:s' (einschließlich).
	 * @return float
	 */
	public static function balance_at( array $movements, $article, $color, $location, $until ) {
		$sum = 0.0;

		foreach ( $movements as $m ) {
			$c = '' !== (string) $m['color'] ? (string) $m['color'] : ColorRegistry::UNRESOLVED;
			if ( (int) $m['article_id'] === (int) $article && $c === (string) $color && (string) $m['location'] === (string) $location && (string) $m['moved_at'] <= (string) $until ) {
				$sum += (float) $m['qty'];
			}
		}

		return round( $sum, 3 );
	}

	/**
	 * Bestände aller Artikel, Farben und Orte bis zu einem Zeitpunkt.
	 *
	 * @param array  $movements Bewegungen.
	 * @param string $until     Zeitpunkt UTC.
	 * @return array article_id => color => location => Menge (ohne Nullen).
	 */
	public static function balances_at( array $movements, $until ) {
		$out = array();

		foreach ( $movements as $m ) {
			if ( (string) $m['moved_at'] > (string) $until ) {
				continue;
			}
			$a = (int) $m['article_id'];
			$c = '' !== (string) $m['color'] ? (string) $m['color'] : ColorRegistry::UNRESOLVED;
			$l = (string) $m['location'];
			if ( ! isset( $out[ $a ][ $c ][ $l ] ) ) {
				$out[ $a ][ $c ][ $l ] = 0.0;
			}
			$out[ $a ][ $c ][ $l ] += (float) $m['qty'];
		}

		foreach ( $out as $a => $colors ) {
			foreach ( $colors as $c => $places ) {
				foreach ( $places as $l => $q ) {
					if ( abs( $q ) < 0.0005 ) {
						unset( $out[ $a ][ $c ][ $l ] );
					} else {
						$out[ $a ][ $c ][ $l ] = round( $q, 3 );
					}
				}
				if ( ! $out[ $a ][ $c ] ) {
					unset( $out[ $a ][ $c ] );
				}
			}
			if ( ! $out[ $a ] ) {
				unset( $out[ $a ] );
			}
		}

		return $out;
	}

	/**
	 * Zeilen der Zählliste auswerten.
	 *
	 * @param array $lines Zeilen (expected_qty, counted_qty|null, unit_value|null).
	 * @return array {counted, open, with_diff, diff_qty, diff_value, expected_value, counted_value}
	 */
	public static function summary( array $lines ) {
		$out = array(
			'lines'          => count( $lines ),
			'counted'        => 0,
			'open'           => 0,
			'with_diff'      => 0,
			'diff_qty'       => 0.0,
			'diff_value'     => 0.0,
			'expected_value' => 0.0,
			'counted_value'  => 0.0,
		);

		foreach ( $lines as $l ) {
			$unit                   = null === $l['unit_value'] ? 0.0 : (float) $l['unit_value'];
			$out['expected_value'] += (float) $l['expected_qty'] * $unit;

			if ( null === $l['counted_qty'] || '' === $l['counted_qty'] ) {
				++$out['open'];
				$out['counted_value'] += (float) $l['expected_qty'] * $unit;
				continue;
			}

			++$out['counted'];
			$diff                  = (float) $l['counted_qty'] - (float) $l['expected_qty'];
			$out['counted_value'] += (float) $l['counted_qty'] * $unit;

			if ( abs( $diff ) >= 0.0005 ) {
				++$out['with_diff'];
				$out['diff_qty']   += $diff;
				$out['diff_value'] += $diff * $unit;
			}
		}

		foreach ( array( 'diff_qty', 'diff_value', 'expected_value', 'counted_value' ) as $key ) {
			$out[ $key ] = round( $out[ $key ], 2 );
		}

		return $out;
	}

	/**
	 * Korrekturmenge beim Abschluss.
	 *
	 * @param float $counted   Gezählt.
	 * @param float $at_count  Bestand im Konto zum Zeitpunkt des Zählens.
	 * @return float
	 */
	public static function correction( $counted, $at_count ) {
		return round( (float) $counted - (float) $at_count, 3 );
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns    = RestApi::NAMESPACE_V1;
		$needs = $api->needs( array( 'lager.inventur' ) );

		register_rest_route( $ns, '/erp/inventur', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'index' ), 'permission_callback' => $needs ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $needs ),
		) );
		register_rest_route( $ns, '/erp/inventur/bestandswert', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'stock_value' ), 'permission_callback' => $api->needs( array( 'lager.inventur', 'finanzen.kosten' ) ) ) );
		register_rest_route( $ns, '/erp/inventur/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'show' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/inventur/(?P<id>\d+)/zaehlen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'count_line' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/inventur/(?P<id>\d+)/zeile', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add_line' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/inventur/(?P<id>\d+)/abschliessen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'close' ), 'permission_callback' => $api->needs( array( 'lager.inventur', 'lager.inventur.abschliessen' ) ) ) );
		register_rest_route( $ns, '/erp/inventur/(?P<id>\d+)/csv', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'csv' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/inventur/(?P<id>\d+)/pdf', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pdf' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Herstellkosten je Artikel (für die Bewertung).
	 *
	 * @param array $article_ids Artikel.
	 * @return array article_id => Euro
	 */
	public static function unit_costs( array $article_ids ) {
		$out      = array();
		$ctx      = \Lindenweg16\Modules\Betrieb\CalculationContext::build();
		$settings = \Lindenweg16\Modules\Betrieb\CalculationContext::settings();

		foreach ( array_unique( array_map( 'intval', $article_ids ) ) as $id ) {
			$cost       = \Lindenweg16\Modules\Betrieb\CostCalculator::manufacturing_cost( $id, $ctx, $settings );
			$out[ $id ] = round( (float) $cost['herstellkosten'], 4 );
		}

		return $out;
	}

	/**
	 * Liste der Inventuren.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		global $wpdb;

		$t    = Kit::table( 'stocktakes' );
		$l    = Kit::table( 'stocktake_lines' );
		$rows = $wpdb->get_results( "SELECT s.*, (SELECT COUNT(*) FROM {$l} WHERE stocktake_id = s.id) AS n, (SELECT COUNT(*) FROM {$l} WHERE stocktake_id = s.id AND counted_qty IS NOT NULL) AS done FROM {$t} s ORDER BY s.id DESC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'          => (int) $r['id'],
				'code'        => (string) $r['code'],
				'title'       => (string) $r['title'],
				'status'      => (string) $r['status'],
				'snapshot_at' => strtotime( $r['snapshot_at'] . ' UTC' ),
				'closed_at'   => $r['closed_at'] ? strtotime( $r['closed_at'] . ' UTC' ) : 0,
				'lines'       => (int) $r['n'],
				'counted'     => (int) $r['done'],
			);
		}

		return new WP_REST_Response(
			array(
				'rows'      => $out,
				'can_close' => Access::can( 'lager.inventur.abschliessen' ),
				'can_value' => Access::can( 'finanzen.kosten' ),
			)
		);
	}

	/**
	 * Neue Inventur mit Soll-Bestand.
	 *
	 * @param WP_REST_Request $request Anfrage {title, nur_bestand}.
	 * @return WP_REST_Response
	 */
	public static function create( WP_REST_Request $request ) {
		global $wpdb;

		$in    = Kit::input( $request );
		$title = Kit::cut( sanitize_text_field( isset( $in['title'] ) ? (string) $in['title'] : '' ), 250 );
		$only  = ! empty( $in['nur_bestand'] );
		$parts = ! array_key_exists( 'mit_teilen', $in ) || ! empty( $in['mit_teilen'] );
		$t     = Kit::table( 'stocktakes' );

		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'offen'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return Kit::fail( __( 'Es läuft schon eine Inventur. Bitte erst abschließen.', 'lindenweg16' ), 409 );
		}

		$now  = current_time( 'mysql', true );
		$code = Kit::number_from( $t, 'code', 'IV' );

		$wpdb->insert(
			$t,
			array(
				'code'        => $code,
				'title'       => '' !== $title ? $title : sprintf( /* translators: %s: Datum. */ __( 'Inventur %s', 'lindenweg16' ), Kit::dmy( current_time( 'Y-m-d' ) ) ),
				'status'      => 'offen',
				'snapshot_at' => $now,
				'created_at'  => $now,
				'created_by'  => get_current_user_id(),
			)
		);
		$id = (int) $wpdb->insert_id;

		if ( ! $id ) {
			return Kit::fail( __( 'Die Inventur ließ sich nicht anlegen. Ist das Plugin-Update vollständig gelaufen (Datenbank)?', 'lindenweg16' ), 500 );
		}

		$lines = array();
		foreach ( StockRepository::balances() as $article => $colors ) {
			foreach ( $colors as $color => $places ) {
				$qty = isset( $places['lager'] ) ? (float) $places['lager'] : 0.0;
				if ( $only && abs( $qty ) < 0.0005 ) {
					continue;
				}
				$lines[ $article . '|' . $color ] = array( (int) $article, (string) $color, $qty );
			}
		}
		if ( ! $only ) {
			foreach ( StockRepository::targets() as $target ) {
				$key = (int) $target['article_id'] . '|' . (string) $target['color'];
				if ( ! isset( $lines[ $key ] ) ) {
					$lines[ $key ] = array( (int) $target['article_id'], (string) $target['color'], 0.0 );
				}
			}
		}

		$costs = self::unit_costs( array_map( function ( $l ) { return $l[0]; }, $lines ) );
		foreach ( $lines as $line ) {
			$wpdb->insert(
				Kit::table( 'stocktake_lines' ),
				array(
					'stocktake_id' => $id,
					'article_id'   => $line[0],
					'color'        => $line[1],
					'expected_qty' => $line[2],
					'unit_value'   => isset( $costs[ $line[0] ] ) ? $costs[ $line[0] ] : null,
				)
			);
		}

		$n = count( $lines );
		if ( $parts ) {
			foreach ( self::part_rows() as $part ) {
				if ( $only && abs( $part['qty'] ) < 0.0005 ) {
					continue;
				}
				$wpdb->insert(
					Kit::table( 'stocktake_lines' ),
					array(
						'stocktake_id' => $id,
						'item_type'    => $part['type'],
						'article_id'   => $part['id'],
						'color'        => '',
						'expected_qty' => $part['qty'],
						'unit_value'   => $part['unit'],
					)
				);
				++$n;
			}
		}

		AuditLog::record( 'erp_inventur_angelegt', 'stocktake', $id, array( 'code' => $code, 'zeilen' => $n ) );

		/* translators: 1: Nummer, 2: Anzahl. */
		return new WP_REST_Response( array( 'ok' => true, 'id' => $id, 'message' => sprintf( __( 'Inventur %1$s mit %2$d Zeilen angelegt. Jetzt zählen.', 'lindenweg16' ), $code, $n ) ), 201 );
	}

	/**
	 * Kleinteile (Komponenten) und Kartons/Verbrauchsmaterial mit Bestand und Stückwert.
	 *
	 * @return array Liste {type, id, qty, unit, code, name, unit_label, barcode, bin_id}
	 */
	public static function part_rows() {
		$out = array();

		foreach ( \Lindenweg16\Modules\Betrieb\ComponentRepository::all() as $c ) {
			$out[] = array(
				'type'       => 'component',
				'id'         => (int) $c['id'],
				'qty'        => (float) $c['stock_qty'],
				'unit'       => round( \Lindenweg16\Modules\Betrieb\CostCalculator::unit_price( $c ), 4 ),
				'code'       => (string) $c['code'],
				'name'       => (string) $c['name'],
				'unit_label' => __( 'Stück', 'lindenweg16' ),
				'barcode'    => (string) $c['barcode'],
				'bin_id'     => (int) $c['default_bin_id'],
			);
		}
		foreach ( \Lindenweg16\Modules\Betrieb\SupplyRepository::all() as $s ) {
			$out[] = array(
				'type'       => 'supply',
				'id'         => (int) $s['id'],
				'qty'        => (float) $s['stock_qty'],
				'unit'       => round( (float) $s['price_per_unit'], 4 ),
				'code'       => '',
				'name'       => (string) $s['name'],
				'unit_label' => (string) $s['unit'],
				'barcode'    => '',
				'bin_id'     => 0,
			);
		}

		return $out;
	}

	/**
	 * Inventur laden.
	 *
	 * @param int $id Kennung.
	 * @return array|null
	 */
	protected static function find( $id ) {
		global $wpdb;

		$s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Kit::table( 'stocktakes' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( ! $s ) {
			return null;
		}

		$s['lines'] = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Kit::table( 'stocktake_lines' ) . ' WHERE stocktake_id = %d ORDER BY id ASC', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $s;
	}

	/**
	 * Eine Inventur mit Zeilen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function show( WP_REST_Request $request ) {
		$s = self::find( (int) $request['id'] );

		if ( ! $s ) {
			return Kit::fail( __( 'Diese Inventur gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$articles = ArticleRepository::all_by_id();
		$value    = Access::can( 'finanzen.kosten' );
		$bins     = array();
		$lines    = array();

		foreach ( \Lindenweg16\Modules\Betrieb\BinRepository::all() as $bin ) {
			$bins[ (int) $bin['id'] ] = (string) $bin['code'];
		}

		$parts = array();
		foreach ( self::part_rows() as $p ) {
			$parts[ $p['type'] . ':' . $p['id'] ] = $p;
		}
		$kinds = array( 'article' => __( 'Produkt', 'lindenweg16' ), 'component' => __( 'Kleinteil', 'lindenweg16' ), 'supply' => __( 'Karton/Material', 'lindenweg16' ) );

		foreach ( $s['lines'] as $l ) {
			$type = (string) $l['item_type'];
			if ( 'article' === $type ) {
				$a    = isset( $articles[ (int) $l['article_id'] ] ) ? $articles[ (int) $l['article_id'] ] : null;
				$info = array(
					'sku'         => $a ? (string) $a['sku'] : '#' . (int) $l['article_id'],
					'name'        => $a ? (string) $a['name'] : '',
					'barcode'     => $a ? (string) $a['barcode'] : '',
					'bin'         => $a && $a['default_bin_id'] && isset( $bins[ (int) $a['default_bin_id'] ] ) ? $bins[ (int) $a['default_bin_id'] ] : '',
					'color_label' => ColorRegistry::label( $l['color'] ),
					'unit_label'  => __( 'Stück', 'lindenweg16' ),
				);
			} else {
				$p    = isset( $parts[ $type . ':' . (int) $l['article_id'] ] ) ? $parts[ $type . ':' . (int) $l['article_id'] ] : null;
				$info = array(
					'sku'         => $p ? ( '' !== $p['code'] ? $p['code'] : $kinds[ $type ] ) : '#' . (int) $l['article_id'],
					'name'        => $p ? $p['name'] : '',
					'barcode'     => $p ? $p['barcode'] : '',
					'bin'         => $p && $p['bin_id'] && isset( $bins[ $p['bin_id'] ] ) ? $bins[ $p['bin_id'] ] : '',
					'color_label' => $kinds[ $type ],
					'unit_label'  => $p ? $p['unit_label'] : '',
				);
			}
			$lines[] = array_merge( $info, array(
				'id'          => (int) $l['id'],
				'type'        => $type,
				'article_id'  => (int) $l['article_id'],
				'color'       => (string) $l['color'],
				'expected'    => (float) $l['expected_qty'],
				'counted'     => null === $l['counted_qty'] ? null : (float) $l['counted_qty'],
				'unit_value'  => $value && null !== $l['unit_value'] ? (float) $l['unit_value'] : null,
				'note'        => (string) $l['note'],
				'booked'      => (bool) $l['movement_id'] || (bool) $l['booked'],
			) );
		}

		usort(
			$lines,
			function ( $x, $y ) {
				return strcmp( $x['bin'] . $x['sku'] . $x['color'], $y['bin'] . $y['sku'] . $y['color'] );
			}
		);

		$sum = self::summary( $s['lines'] );
		if ( ! $value ) {
			$sum['diff_value']     = null;
			$sum['expected_value'] = null;
			$sum['counted_value']  = null;
		}

		$options = array();
		$popts   = array();
		if ( 'offen' === $s['status'] ) {
			foreach ( $articles as $a ) {
				$options[] = array( (int) $a['id'], $a['sku'] . ' · ' . $a['name'] );
			}
			foreach ( self::part_rows() as $p ) {
				$popts[] = array( $p['type'] . ':' . $p['id'], $kinds[ $p['type'] ] . ' · ' . trim( $p['code'] . ' ' . $p['name'] ) );
			}
		}

		return new WP_REST_Response(
			array(
				'id'          => (int) $s['id'],
				'code'        => (string) $s['code'],
				'title'       => (string) $s['title'],
				'status'      => (string) $s['status'],
				'snapshot_at' => strtotime( $s['snapshot_at'] . ' UTC' ),
				'lines'       => $lines,
				'summary'     => $sum,
				'articles'    => $options,
				'parts'       => $popts,
				'colors'      => ColorRegistry::options( true ),
				'can_close'   => Access::can( 'lager.inventur.abschliessen' ),
			)
		);
	}

	/**
	 * Eine Zeile zählen.
	 *
	 * @param WP_REST_Request $request Anfrage {line_id, qty, note}.
	 * @return WP_REST_Response
	 */
	public static function count_line( WP_REST_Request $request ) {
		global $wpdb;

		$s  = self::find( (int) $request['id'] );
		$in = Kit::input( $request );

		if ( ! $s || 'offen' !== $s['status'] ) {
			return Kit::fail( __( 'Diese Inventur ist nicht (mehr) offen.', 'lindenweg16' ), 409 );
		}

		$line_id = isset( $in['line_id'] ) ? (int) $in['line_id'] : 0;
		$raw     = isset( $in['qty'] ) ? $in['qty'] : '';
		$qty     = '' === trim( (string) $raw ) ? null : Kit::amount( $raw );
		$found   = null;

		foreach ( $s['lines'] as $l ) {
			if ( (int) $l['id'] === $line_id ) {
				$found = $l;
			}
		}

		if ( ! $found ) {
			return Kit::fail( __( 'Diese Zeile gibt es nicht.', 'lindenweg16' ), 404 );
		}
		if ( '' !== trim( (string) $raw ) && ( null === $qty || $qty < 0 || $qty > Lager::MAX_QTY ) ) {
			return Kit::fail( __( 'Bitte eine Menge ab 0 eintragen (leer = nicht gezählt).', 'lindenweg16' ) );
		}

		$wpdb->update(
			Kit::table( 'stocktake_lines' ),
			array(
				'counted_qty' => $qty,
				'counted_at'  => null === $qty ? null : current_time( 'mysql', true ),
				'counted_by'  => null === $qty ? null : get_current_user_id(),
				'note'        => isset( $in['note'] ) ? Kit::cut( sanitize_text_field( (string) $in['note'] ), 250 ) : $found['note'],
			),
			array( 'id' => $line_id )
		);

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Artikel nachtragen, der nicht auf der Liste stand.
	 *
	 * @param WP_REST_Request $request Anfrage {article_id, color, qty}.
	 * @return WP_REST_Response
	 */
	public static function add_line( WP_REST_Request $request ) {
		global $wpdb;

		$s       = self::find( (int) $request['id'] );
		$in      = Kit::input( $request );
		$type    = isset( $in['item_type'] ) && in_array( $in['item_type'], array( 'component', 'supply' ), true ) ? (string) $in['item_type'] : 'article';
		$article = isset( $in['article_id'] ) ? (int) $in['article_id'] : 0;

		if ( 'article' !== $type ) {
			return self::add_part_line( $s, $type, $article, $in );
		}
		$color   = ColorRegistry::normalize( isset( $in['color'] ) ? (string) $in['color'] : '' );
		$qty     = Kit::amount( isset( $in['qty'] ) ? $in['qty'] : '' );

		if ( ! $s || 'offen' !== $s['status'] ) {
			return Kit::fail( __( 'Diese Inventur ist nicht (mehr) offen.', 'lindenweg16' ), 409 );
		}
		if ( ! $article || ! ArticleRepository::find( $article ) ) {
			return Kit::fail( __( 'Bitte einen Artikel wählen.', 'lindenweg16' ) );
		}
		if ( '' === $color ) {
			$color = ColorRegistry::default_color();
		}
		if ( null === $qty || $qty < 0 ) {
			return Kit::fail( __( 'Bitte die gezählte Menge eintragen.', 'lindenweg16' ) );
		}
		foreach ( $s['lines'] as $l ) {
			if ( (int) $l['article_id'] === $article && (string) $l['color'] === $color ) {
				return Kit::fail( __( 'Diesen Artikel in dieser Farbe gibt es schon auf der Liste – bitte dort zählen.', 'lindenweg16' ), 409 );
			}
		}

		$movs     = StockRepository::all( $article );
		$expected = self::balance_at( $movs, $article, $color, 'lager', (string) $s['snapshot_at'] );
		$cost     = self::unit_costs( array( $article ) );

		$wpdb->insert(
			Kit::table( 'stocktake_lines' ),
			array(
				'stocktake_id' => (int) $s['id'],
				'article_id'   => $article,
				'color'        => $color,
				'expected_qty' => $expected,
				'counted_qty'  => $qty,
				'unit_value'   => $cost[ $article ],
				'counted_at'   => current_time( 'mysql', true ),
				'counted_by'   => get_current_user_id(),
				'note'         => __( 'nachgetragen', 'lindenweg16' ),
			)
		);

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Zeile nachgetragen.', 'lindenweg16' ) ), 201 );
	}

	/**
	 * Kleinteil oder Karton nachtragen.
	 *
	 * @param array|null $s    Inventur.
	 * @param string     $type component oder supply.
	 * @param int        $id   Kennung.
	 * @param array      $in   Eingaben.
	 * @return WP_REST_Response
	 */
	protected static function add_part_line( $s, $type, $id, array $in ) {
		global $wpdb;

		$qty  = Kit::amount( isset( $in['qty'] ) ? $in['qty'] : '' );
		$part = null;
		foreach ( self::part_rows() as $p ) {
			if ( $p['type'] === $type && $p['id'] === $id ) {
				$part = $p;
			}
		}

		if ( ! $s || 'offen' !== $s['status'] ) {
			return Kit::fail( __( 'Diese Inventur ist nicht (mehr) offen.', 'lindenweg16' ), 409 );
		}
		if ( ! $part ) {
			return Kit::fail( __( 'Bitte eine Position wählen.', 'lindenweg16' ) );
		}
		if ( null === $qty || $qty < 0 ) {
			return Kit::fail( __( 'Bitte die gezählte Menge eintragen.', 'lindenweg16' ) );
		}
		foreach ( $s['lines'] as $l ) {
			if ( $l['item_type'] === $type && (int) $l['article_id'] === $id ) {
				return Kit::fail( __( 'Diese Position steht schon auf der Liste – bitte dort zählen.', 'lindenweg16' ), 409 );
			}
		}

		$wpdb->insert(
			Kit::table( 'stocktake_lines' ),
			array(
				'stocktake_id' => (int) $s['id'],
				'item_type'    => $type,
				'article_id'   => $id,
				'color'        => '',
				'expected_qty' => $part['qty'],
				'counted_qty'  => $qty,
				'unit_value'   => $part['unit'],
				'counted_at'   => current_time( 'mysql', true ),
				'counted_by'   => get_current_user_id(),
				'note'         => __( 'nachgetragen', 'lindenweg16' ),
			)
		);

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Zeile nachgetragen.', 'lindenweg16' ) ), 201 );
	}

	/**
	 * Zählliste als PDF zum Ausdrucken (nach Lagerplatz sortiert).
	 *
	 * @param WP_REST_Request $request Anfrage (blind = ohne Soll).
	 * @return WP_REST_Response
	 */
	public static function pdf( WP_REST_Request $request ) {
		$res = self::show( $request );

		if ( 200 !== $res->get_status() ) {
			return $res;
		}

		$d      = $res->get_data();
		$blind  = (bool) $request->get_param( 'blind' );
		$pdf    = new \Lindenweg16\Core\Pdf();
		$widths = array( 50, 80, 205, 70, 60, 70 );
		$aligns = array( 'l', 'l', 'l', 'l', 'r', 'r' );

		$pdf->text( Kit::company()['name'], 10 );
		/* translators: 1: Nummer, 2: Titel. */
		$pdf->text( sprintf( __( 'Zählliste %1$s – %2$s', 'lindenweg16' ), $d['code'], $d['title'] ), 16, true );
		/* translators: %s: Datum. */
		$pdf->text( sprintf( __( 'Stichtag %s · Gezählt von: ____________________', 'lindenweg16' ), wp_date( 'd.m.Y H:i', $d['snapshot_at'] ) ), 10 );
		$pdf->space( 8 );
		$pdf->hr();
		$pdf->table_cols( array( __( 'Platz', 'lindenweg16' ), __( 'Nummer', 'lindenweg16' ), __( 'Bezeichnung', 'lindenweg16' ), __( 'Farbe/Art', 'lindenweg16' ), $blind ? '' : __( 'Soll', 'lindenweg16' ), __( 'Gezählt', 'lindenweg16' ) ), $widths, $aligns, 9, true );
		$pdf->hr( 0.3 );

		foreach ( $d['lines'] as $l ) {
			$pdf->table_cols(
				array(
					Kit::cut( $l['bin'], 9 ),
					Kit::cut( $l['sku'], 14 ),
					Kit::cut( $l['name'], 40 ),
					Kit::cut( $l['color_label'], 12 ),
					$blind ? '' : str_replace( '.', ',', (string) ( 0 + $l['expected'] ) ) . ' ' . Kit::cut( $l['unit_label'], 6 ),
					null === $l['counted'] ? '________' : str_replace( '.', ',', (string) ( 0 + $l['counted'] ) ),
				),
				$widths,
				$aligns,
				9
			);
		}

		return Kit::file_response( 'zaehlliste-' . $d['code'] . '.pdf', 'application/pdf', $pdf->output() );
	}

	/**
	 * Abschließen: Differenzen als Korrektur buchen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function close( WP_REST_Request $request ) {
		global $wpdb;

		$s = self::find( (int) $request['id'] );

		if ( ! $s || 'offen' !== $s['status'] ) {
			return Kit::fail( __( 'Diese Inventur ist nicht (mehr) offen.', 'lindenweg16' ), 409 );
		}

		$lock = 'lw16_erp_inventur_' . (int) $s['id'];
		if ( get_transient( $lock ) ) {
			return Kit::fail( __( 'Wird gerade abgeschlossen – bitte kurz warten.', 'lindenweg16' ), 409 );
		}
		set_transient( $lock, 1, 120 );

		$booked = 0;
		$cache  = array();

		$part_stock = array();
		foreach ( self::part_rows() as $p ) {
			$part_stock[ $p['type'] . ':' . $p['id'] ] = $p['qty'];
		}

		foreach ( $s['lines'] as $l ) {
			if ( null === $l['counted_qty'] || $l['movement_id'] || $l['booked'] ) {
				continue;
			}

			// Kleinteile und Kartons haben kein Bewegungskonto: Differenz zum Bestand jetzt.
			if ( 'article' !== $l['item_type'] ) {
				$key  = $l['item_type'] . ':' . (int) $l['article_id'];
				$have = isset( $part_stock[ $key ] ) ? (float) $part_stock[ $key ] : null;
				if ( null === $have ) {
					continue;
				}
				$diff = self::correction( $l['counted_qty'], $have );
				if ( abs( $diff ) >= 0.0005 ) {
					/* translators: 1: Nummer, 2: gezählt. */
					$why = sprintf( __( 'Inventur %1$s: gezählt %2$s', 'lindenweg16' ), $s['code'], str_replace( '.', ',', (string) ( 0 + (float) $l['counted_qty'] ) ) );
					$ok  = 'supply' === $l['item_type']
						? \Lindenweg16\Modules\Betrieb\SupplyRepository::adjust_stock( (int) $l['article_id'], $diff, $why )
						: \Lindenweg16\Modules\Betrieb\ComponentRepository::adjust_stock( (int) $l['article_id'], $diff, $why );
					if ( $ok ) {
						++$booked;
					}
				}
				$wpdb->update( Kit::table( 'stocktake_lines' ), array( 'booked' => 1 ), array( 'id' => (int) $l['id'] ) );
				continue;
			}

			$article = (int) $l['article_id'];
			if ( ! isset( $cache[ $article ] ) ) {
				$cache[ $article ] = StockRepository::all( $article );
			}

			$at   = self::balance_at( $cache[ $article ], $article, (string) $l['color'], 'lager', (string) $l['counted_at'] );
			$diff = self::correction( $l['counted_qty'], $at );

			if ( abs( $diff ) < 0.0005 ) {
				continue;
			}

			$mid = StockRepository::book(
				array(
					'article_id'    => $article,
					'color'         => (string) $l['color'],
					'qty'           => $diff,
					'movement_type' => 'korrektur',
					'location'      => 'lager',
					'ref_type'      => 'inventur',
					'ref_id'        => (int) $s['id'],
					/* translators: 1: Nummer, 2: gezählt, 3: Konto. */
					'note'          => sprintf( __( 'Inventur %1$s: gezählt %2$s, im Konto %3$s', 'lindenweg16' ), $s['code'], str_replace( '.', ',', (string) ( 0 + (float) $l['counted_qty'] ) ), str_replace( '.', ',', (string) ( 0 + $at ) ) ),
				)
			);

			if ( $mid ) {
				$wpdb->update( Kit::table( 'stocktake_lines' ), array( 'movement_id' => $mid ), array( 'id' => (int) $l['id'] ) );
				++$booked;
			}
		}

		$wpdb->update( Kit::table( 'stocktakes' ), array( 'status' => 'abgeschlossen', 'closed_at' => current_time( 'mysql', true ), 'closed_by' => get_current_user_id() ), array( 'id' => (int) $s['id'] ) );
		delete_transient( $lock );

		$sum = self::summary( $s['lines'] );
		AuditLog::record( 'erp_inventur_abgeschlossen', 'stocktake', (int) $s['id'], array( 'gebucht' => $booked, 'nicht_gezaehlt' => $sum['open'], 'differenz_wert' => $sum['diff_value'] ) );

		/* translators: 1: Anzahl, 2: Anzahl. */
		return new WP_REST_Response( array( 'ok' => true, 'message' => sprintf( __( 'Inventur abgeschlossen: %1$d Korrekturen gebucht, %2$d Zeilen nicht gezählt (unverändert).', 'lindenweg16' ), $booked, $sum['open'] ) ) );
	}

	/**
	 * Protokoll als CSV.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function csv( WP_REST_Request $request ) {
		$res = self::show( $request );

		if ( 200 !== $res->get_status() ) {
			return $res;
		}

		$d    = $res->get_data();
		$rows = array( array( 'Inventur', $d['code'], $d['title'], 'Stichtag', wp_date( 'd.m.Y H:i', $d['snapshot_at'] ) ) );
		$rows[] = array( 'SKU', 'Artikel', 'Farbe', 'Lagerplatz', 'Soll', 'Gezählt', 'Differenz', 'Herstellkosten', 'Wert Differenz', 'Notiz' );

		foreach ( $d['lines'] as $l ) {
			$diff   = null === $l['counted'] ? '' : $l['counted'] - $l['expected'];
			$rows[] = array(
				$l['sku'],
				$l['name'],
				$l['color_label'],
				$l['bin'],
				str_replace( '.', ',', (string) ( 0 + $l['expected'] ) ),
				null === $l['counted'] ? 'nicht gezählt' : str_replace( '.', ',', (string) ( 0 + $l['counted'] ) ),
				'' === $diff ? '' : str_replace( '.', ',', (string) round( $diff, 3 ) ),
				null === $l['unit_value'] ? '' : number_format( $l['unit_value'], 2, ',', '' ),
				'' === $diff || null === $l['unit_value'] ? '' : number_format( $diff * $l['unit_value'], 2, ',', '' ),
				$l['note'],
			);
		}

		return new WP_REST_Response(
			array(
				'filename' => 'inventur-' . $d['code'] . '.csv',
				'mime'     => 'text/csv;charset=utf-8',
				'text'     => Zahlen::csv( $rows ),
			)
		);
	}

	/**
	 * Bestandswert zum Stichtag.
	 *
	 * @param WP_REST_Request $request Anfrage (datum, csv).
	 * @return WP_REST_Response
	 */
	public static function stock_value( WP_REST_Request $request ) {
		$date = Kit::date( (string) $request->get_param( 'datum' ) );
		$date = '' !== $date ? $date : current_time( 'Y-m-d' );

		$until    = get_gmt_from_date( $date . ' 23:59:59' );
		$balances = self::balances_at( StockRepository::all(), $until );
		$articles = ArticleRepository::all_by_id();
		$costs    = self::unit_costs( array_keys( $balances ) );
		$places   = \Lindenweg16\Modules\Betrieb\StockLedger::location_labels();
		$rows     = array();
		$total    = 0.0;
		$by_place = array();

		foreach ( $balances as $article => $colors ) {
			foreach ( $colors as $color => $locs ) {
				foreach ( $locs as $loc => $qty ) {
					$unit                = isset( $costs[ $article ] ) ? $costs[ $article ] : 0.0;
					$value               = round( $qty * $unit, 2 );
					$total              += $value;
					$by_place[ $loc ]    = ( isset( $by_place[ $loc ] ) ? $by_place[ $loc ] : 0.0 ) + $value;
					$rows[]              = array(
						'sku'   => isset( $articles[ $article ] ) ? (string) $articles[ $article ]['sku'] : '#' . $article,
						'name'  => isset( $articles[ $article ] ) ? (string) $articles[ $article ]['name'] : '',
						'color' => ColorRegistry::label( $color ),
						'place' => isset( $places[ $loc ] ) ? $places[ $loc ] : $loc,
						'qty'   => $qty,
						'unit'  => $unit,
						'value' => $value,
					);
				}
			}
		}

		// Kleinteile, Kartons und Filament führen keinen Verlauf – sie gibt es nur mit dem heutigen Stand.
		$today = current_time( 'Y-m-d' );
		$extra = $date === $today;
		if ( $extra ) {
			$kinds = array( 'component' => __( 'Kleinteil', 'lindenweg16' ), 'supply' => __( 'Karton/Material', 'lindenweg16' ) );
			foreach ( self::part_rows() as $p ) {
				if ( $p['qty'] <= 0 ) {
					continue;
				}
				$value             = round( $p['qty'] * $p['unit'], 2 );
				$total            += $value;
				$by_place['teile'] = ( isset( $by_place['teile'] ) ? $by_place['teile'] : 0.0 ) + $value;
				$rows[]            = array( 'sku' => $p['code'], 'name' => $p['name'], 'color' => $kinds[ $p['type'] ], 'place' => __( 'Kleinteile & Kartons', 'lindenweg16' ), 'qty' => $p['qty'], 'unit' => $p['unit'], 'value' => $value );
			}
			foreach ( \Lindenweg16\Modules\Betrieb\FilamentRepository::all() as $lot ) {
				$kg = round( (float) \Lindenweg16\Modules\Betrieb\FilamentRepository::remaining_kg( (array) $lot ), 3 );
				if ( $kg <= 0 ) {
					continue;
				}
				$value                = round( $kg * (float) $lot['price_per_kg'], 2 );
				$total               += $value;
				$by_place['filament'] = ( isset( $by_place['filament'] ) ? $by_place['filament'] : 0.0 ) + $value;
				$rows[]               = array( 'sku' => (string) $lot['lot_code'], 'name' => trim( 'Filament ' . $lot['material'] . ' ' . $lot['manufacturer'] ), 'color' => ColorRegistry::label( $lot['color'] ), 'place' => __( 'Filament', 'lindenweg16' ), 'qty' => $kg, 'unit' => (float) $lot['price_per_kg'], 'value' => $value );
			}
			$places['teile']    = __( 'Kleinteile & Kartons', 'lindenweg16' );
			$places['filament'] = __( 'Filament (kg)', 'lindenweg16' );
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $b['value'] <=> $a['value'];
			}
		);

		if ( $request->get_param( 'csv' ) ) {
			$csv = array( array( 'Bestandswert zum', Kit::dmy( $date ), 'Herstellkosten laut Kalkulation (heutiger Stand)' ), array( 'SKU', 'Artikel', 'Farbe', 'Ort', 'Menge', 'Herstellkosten', 'Wert' ) );
			foreach ( $rows as $r ) {
				$csv[] = array( $r['sku'], $r['name'], $r['color'], $r['place'], str_replace( '.', ',', (string) ( 0 + $r['qty'] ) ), number_format( $r['unit'], 2, ',', '' ), number_format( $r['value'], 2, ',', '' ) );
			}
			$csv[] = array( '', '', '', '', '', 'Summe', number_format( $total, 2, ',', '' ) );

			return new WP_REST_Response( array( 'filename' => 'bestandswert-' . $date . '.csv', 'mime' => 'text/csv;charset=utf-8', 'text' => Zahlen::csv( $csv ) ) );
		}

		$named = array();
		foreach ( $by_place as $loc => $v ) {
			$named[] = array( isset( $places[ $loc ] ) ? $places[ $loc ] : $loc, round( $v, 2 ) );
		}

		return new WP_REST_Response(
			array(
				'date'     => $date,
				'total'    => round( $total, 2 ),
				'by_place' => $named,
				'rows'     => array_slice( $rows, 0, 300 ),
				'count'    => count( $rows ),
				'with_parts' => $extra,
			)
		);
	}
}
