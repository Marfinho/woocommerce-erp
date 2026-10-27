<?php
/**
 * Lesbarmachen des Protokolls: wer hat was gemacht.
 *
 * Das Protokoll wird ohnehin geschrieben – hashverkettet, für jede Änderung,
 * mit Nutzer und Zeit. Was fehlte, war die Sicht darauf: `article_revisions`
 * und `betrieb_update` sind Tabellennamen, keine Auskunft. Diese Klasse
 * übersetzt sie in Sätze, die jemand ohne Kenntnis der Datenbank versteht.
 *
 * Sie wertet nicht: Das Protokoll sagt, was geschah, nicht ob es richtig
 * war. Eine Oberfläche, die Nutzer nach Anzahl ihrer Buchungen sortiert und
 * einfärbt, wäre eine Leistungsmessung – dafür ist ein Protokoll, das man
 * zum Wiederfinden von Fehlern braucht, das falsche Werkzeug.
 *
 * Frei von Datenbank und WordPress.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Übersetzt Protokollzeilen.
 */
class UserActivity {

	/**
	 * Was die Aktion war.
	 *
	 * @return array
	 */
	public static function action_labels() {
		return array(
			'create' => __( 'angelegt', 'lindenweg16' ),
			'update' => __( 'geändert', 'lindenweg16' ),
			'void'   => __( 'storniert', 'lindenweg16' ),
		);
	}

	/**
	 * Woran gearbeitet wurde. Die Schlüssel sind die Tabellennamen ohne
	 * Präfix, wie sie im Protokoll stehen.
	 *
	 * @return array
	 */
	public static function object_labels() {
		return array(
			'articles'           => __( 'Artikel', 'lindenweg16' ),
			'article_revisions'  => __( 'Revision', 'lindenweg16' ),
			'article_sourcing'   => __( 'Beschaffungsdaten', 'lindenweg16' ),
			'bom'                => __( 'Stückliste', 'lindenweg16' ),
			'components'         => __( 'Komponente', 'lindenweg16' ),
			'bundles'            => __( 'Bestellart', 'lindenweg16' ),
			'stock_movements'    => __( 'Lagerbuchung', 'lindenweg16' ),
			'filament_rolls'     => __( 'Filamentrolle', 'lindenweg16' ),
			'stock_targets'      => __( 'Sollbestand', 'lindenweg16' ),
			'storage_bins'       => __( 'Lagerplatz', 'lindenweg16' ),
			'picklists'          => __( 'Pickliste', 'lindenweg16' ),
			'work_orders'        => __( 'Fertigungsauftrag', 'lindenweg16' ),
			'print_jobs'         => __( 'Druckauftrag', 'lindenweg16' ),
			'printers'           => __( 'Drucker', 'lindenweg16' ),
			'maintenance_log'    => __( 'Wartung', 'lindenweg16' ),
			'filament_lots'      => __( 'Filamentcharge', 'lindenweg16' ),
			'batches'            => __( 'Charge', 'lindenweg16' ),
			'supplies'           => __( 'Verbrauchsmaterial', 'lindenweg16' ),
			'time_entries'       => __( 'Zeiteintrag', 'lindenweg16' ),
			'user_rates'         => __( 'Stundenlohn', 'lindenweg16' ),
			'user_access'        => __( 'Rechte', 'lindenweg16' ),
			'contacts'           => __( 'Kontakt', 'lindenweg16' ),
			'contact_activities' => __( 'Kontaktnotiz', 'lindenweg16' ),
			'contact_profiles'   => __( 'Kontaktprofil', 'lindenweg16' ),
			'contact_terms'      => __( 'Konditionen', 'lindenweg16' ),
			'order_ops'          => __( 'Bestellung', 'lindenweg16' ),
			'purchases'          => __( 'Nachbestellung', 'lindenweg16' ),
			'config_parts'       => __( 'Konfigurator-Teil', 'lindenweg16' ),
			'config_blocks'      => __( 'Sperrbereich', 'lindenweg16' ),
			'reorder_tokens'     => __( 'Nachbestell-Link', 'lindenweg16' ),
			'calc_settings'      => __( 'Kalkulationsgrundlage', 'lindenweg16' ),
			'price_tiers'        => __( 'Preisstaffel', 'lindenweg16' ),
			'kpi_snapshots'      => __( 'Monatsabschluss', 'lindenweg16' ),
			'kpi_goals'          => __( 'Quartalsziel', 'lindenweg16' ),
		);
	}

	/**
	 * Gehört diese Protokollzeile zum Modul Betrieb?
	 *
	 * @param array $row Protokollzeile.
	 * @return bool
	 */
	public static function is_betrieb( array $row ) {
		$aktion = isset( $row['aktion'] ) ? (string) $row['aktion'] : '';

		return 0 === strpos( $aktion, 'betrieb_' );
	}

	/**
	 * Die reine Aktion ohne Modulpräfix.
	 *
	 * @param array $row Protokollzeile.
	 * @return string
	 */
	public static function action( array $row ) {
		$aktion = isset( $row['aktion'] ) ? (string) $row['aktion'] : '';

		return self::is_betrieb( $row ) ? substr( $aktion, strlen( 'betrieb_' ) ) : $aktion;
	}

	/**
	 * Eine Protokollzeile als Satz.
	 *
	 * @param array $row Protokollzeile.
	 * @return string
	 */
	public static function describe( array $row ) {
		$actions = self::action_labels();
		$objects = self::object_labels();

		$action = self::action( $row );
		$typ    = isset( $row['objekt_typ'] ) ? (string) $row['objekt_typ'] : '';
		$id     = isset( $row['objekt_id'] ) ? (int) $row['objekt_id'] : 0;

		$objekt = isset( $objects[ $typ ] ) ? $objects[ $typ ] : $typ;
		$verb   = isset( $actions[ $action ] ) ? $actions[ $action ] : $action;

		if ( '' === $objekt ) {
			return $verb;
		}

		if ( ! $id ) {
			return sprintf( /* translators: 1: Gegenstand, 2: Vorgang. */ __( '%1$s %2$s', 'lindenweg16' ), $objekt, $verb );
		}

		return sprintf(
			/* translators: 1: Gegenstand, 2: Datensatznummer, 3: Vorgang. */
			__( '%1$s #%2$d %3$s', 'lindenweg16' ),
			$objekt,
			$id,
			$verb
		);
	}

	/**
	 * Zusammenfassung über viele Protokollzeilen.
	 *
	 * @param array $rows Protokollzeilen.
	 * @return array {total, create, update, void, first, last, areas}
	 */
	public static function summarize( array $rows ) {
		$out = array(
			'total'  => 0,
			'create' => 0,
			'update' => 0,
			'void'   => 0,
			'first'  => '',
			'last'   => '',
			'areas'  => array(),
		);

		foreach ( $rows as $row ) {
			$row = (array) $row;

			if ( ! self::is_betrieb( $row ) ) {
				continue;
			}

			$out['total']++;

			$action = self::action( $row );
			if ( isset( $out[ $action ] ) ) {
				$out[ $action ]++;
			}

			$typ = isset( $row['objekt_typ'] ) ? (string) $row['objekt_typ'] : '';
			if ( '' !== $typ ) {
				$out['areas'][ $typ ] = isset( $out['areas'][ $typ ] ) ? $out['areas'][ $typ ] + 1 : 1;
			}

			$zeit = isset( $row['erstellt_am'] ) ? (string) $row['erstellt_am'] : '';
			if ( '' === $zeit ) {
				continue;
			}
			if ( '' === $out['first'] || $zeit < $out['first'] ) {
				$out['first'] = $zeit;
			}
			if ( $zeit > $out['last'] ) {
				$out['last'] = $zeit;
			}
		}

		arsort( $out['areas'] );

		return $out;
	}

	/**
	 * Die Bereiche, in denen jemand am meisten gearbeitet hat, als Text.
	 *
	 * @param array $areas Bereich => Anzahl.
	 * @param int   $limit Wie viele.
	 * @return string
	 */
	public static function areas_label( array $areas, $limit = 3 ) {
		$objects = self::object_labels();
		$parts   = array();

		foreach ( array_slice( $areas, 0, (int) $limit, true ) as $typ => $count ) {
			$label   = isset( $objects[ $typ ] ) ? $objects[ $typ ] : $typ;
			$parts[] = $label . ' (' . (int) $count . ')';
		}

		return implode( ', ', $parts );
	}
}
