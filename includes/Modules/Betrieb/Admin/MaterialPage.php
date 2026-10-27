<?php
/**
 * Material: Filament, Verbrauchsmaterial, Beschaffungsalarm.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\FilamentRepository;
use Lindenweg16\Modules\Betrieb\ProcurementAlert;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\SupplyRepository;

/**
 * Rendert die Materialseite.
 */
class MaterialPage {

	const NONCE = 'lw16_betrieb_material';
	const SLUG  = 'lw16-betrieb-material';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_material', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_save_lot', array( $this, 'handle_lot' ) );
		add_action( 'admin_post_lw16_betrieb_dry_lot', array( $this, 'handle_dry' ) );
		add_action( 'admin_post_lw16_betrieb_save_supply', array( $this, 'handle_supply' ) );
		add_action( 'admin_post_lw16_betrieb_delete_supply', array( $this, 'handle_delete_supply' ) );
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Filamentcharge erfassen.
	 */
	public function handle_lot() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$input  = array_map( 'wp_unslash', array_filter( (array) $_POST, 'is_scalar' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = FilamentRepository::validate( $input );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error' );
		}

		FilamentRepository::insert( $result['data'] );
		$this->redirect( __( 'Filament erfasst.', 'lindenweg16' ), 'success' );
	}

	/**
	 * Trocknung vermerken.
	 */
	public function handle_dry() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$id = isset( $_POST['lot_id'] ) ? (int) $_POST['lot_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		FilamentRepository::mark_dried( $id );
		$this->redirect( __( 'Trocknung vermerkt.', 'lindenweg16' ), 'success' );
	}

	/**
	 * Verbrauchsmaterial löschen.
	 */
	public function handle_delete_supply() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$id = isset( $_POST['supply_id'] ) ? (int) $_POST['supply_id'] : 0;

		$this->redirect(
			SupplyRepository::delete( $id )
				? __( 'Verbrauchsmaterial gelöscht.', 'lindenweg16' )
				: __( 'Dieses Verbrauchsmaterial gibt es nicht mehr.', 'lindenweg16' ),
			'success'
		);
	}

	/**
	 * Verbrauchsmaterial speichern.
	 */
	public function handle_supply() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$input = array_map( 'wp_unslash', array_filter( (array) $_POST, 'is_scalar' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = SupplyRepository::save( $input );
		$this->redirect(
			$result['errors'] ? implode( ' ', $result['errors'] ) : __( 'Verbrauchsmaterial gespeichert.', 'lindenweg16' ),
			$result['errors'] ? 'error' : 'success'
		);
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type Typ.
	 */
	protected function redirect( $msg, $type ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::SLUG,
					'lw16_msg' => rawurlencode( $msg ),
					'lw16_typ' => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Material', 'lindenweg16' ) . '</h1>';

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_msg'] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success',
				esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification

		$this->render_alerts();
		$this->render_lots();
		$this->render_supplies();

		echo '</div>';
	}

	/**
	 * Beschaffungsalarm.
	 */
	protected function render_alerts() {
		$alerts = ProcurementAlert::all();

		echo '<h2>' . esc_html__( 'Beschaffungsalarm', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Der Engpass im Weihnachtsgeschäft ist nicht der Drucker, sondern die Stange oder der Karton. Module lassen sich über Nacht nachlegen, alles mit Lieferzeit nicht.', 'lindenweg16' ) . '</p>';

		if ( ! $alerts ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Keine Position unter der Meldegrenze.', 'lindenweg16' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Position', 'lindenweg16' ),
			__( 'Art', 'lindenweg16' ),
			__( 'Bestand', 'lindenweg16' ),
			__( 'Tagesverbrauch', 'lindenweg16' ),
			__( 'Reichweite', 'lindenweg16' ),
			__( 'Lieferzeit + Puffer', 'lindenweg16' ),
			__( 'Bestellen', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $alerts as $alert ) {
			echo '<tr>';
			printf( '<td><strong>%s</strong></td>', esc_html( $alert['label'] ) );
			printf( '<td>%s</td>', esc_html( $alert['kind_label'] ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $alert['stock'], 1 ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $alert['daily'], 2 ) ) );
			printf(
				'<td><span class="lw16-ampel lw16-ampel--rot">%s</span></td>',
				esc_html(
					null === $alert['coverage_days']
						? __( 'unbekannt', 'lindenweg16' )
						: sprintf( /* translators: %s: Tage. */ __( '%s Tage', 'lindenweg16' ), number_format_i18n( $alert['coverage_days'], 0 ) )
				)
			);
			printf(
				'<td>%s</td>',
				esc_html( sprintf( /* translators: %d: Tage. */ __( '%d Tage', 'lindenweg16' ), (int) $alert['threshold_days'] ) )
			);
			printf( '<td>%s</td>', esc_html( number_format_i18n( $alert['order_qty'], 0 ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Filamentchargen.
	 */
	protected function render_lots() {
		$lots = FilamentRepository::all();

		echo '<h2>' . esc_html__( 'Filament', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Der Verbrauch wird aus den Druckjobs gerechnet: geplante Teile mal Bauteilgewicht der Revision. Rollen mit Etikett, Leer-Scan und Wiegen gibt es im ERP unter Lager → Material.', 'lindenweg16' ) . '</p>';

		if ( $lots ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Zugang', 'lindenweg16' ),
				__( 'Material', 'lindenweg16' ),
				__( 'Farbe', 'lindenweg16' ),
				__( 'kg Zugang', 'lindenweg16' ),
				__( '€/kg', 'lindenweg16' ),
				__( 'verbraucht', 'lindenweg16' ),
				__( 'Rest', 'lindenweg16' ),
				__( 'getrocknet', 'lindenweg16' ),
				__( 'Standort', 'lindenweg16' ),
				'',
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $lots as $lot ) {
				$used = FilamentRepository::consumption_kg( (int) $lot['id'] );
				$rest = FilamentRepository::remaining_kg( $lot );

				echo '<tr>';
				printf( '<td>%s</td>', esc_html( $lot['received_on'] ? mysql2date( 'd.m.Y', $lot['received_on'] ) : '—' ) );
				printf( '<td>%s</td>', esc_html( (string) $lot['material'] ) );
				printf( '<td>%s</td>', esc_html( ColorRegistry::label( $lot['color'] ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $lot['kg_received'], 2 ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $lot['price_per_kg'], 2 ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( $used, 3 ) ) );
				printf(
					'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
					esc_attr( $rest < 0.5 ? 'rot' : ( $rest < 1 ? 'gelb' : 'gruen' ) ),
					esc_html( number_format_i18n( $rest, 3 ) )
				);

				$needs = FilamentRepository::needs_drying( $lot );
				printf(
					'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
					esc_attr( $needs ? 'gelb' : 'gruen' ),
					esc_html( $lot['dried_on'] ? mysql2date( 'd.m.Y', $lot['dried_on'] ) : __( 'nie', 'lindenweg16' ) )
				);
				printf( '<td>%s</td>', esc_html( (string) $lot['location'] ) );

				echo '<td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="action" value="lw16_betrieb_dry_lot" />';
				printf( '<input type="hidden" name="lot_id" value="%d" />', (int) $lot['id'] );
				printf( '<button class="button button-small">%s</button>', esc_html__( 'Getrocknet', 'lindenweg16' ) );
				echo '</form></td></tr>';
			}

			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Noch kein Filament erfasst.', 'lindenweg16' ) . '</p>';
		}

		echo '<h3>' . esc_html__( 'Filament erfassen', 'lindenweg16' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_save_lot" />';
		echo '<p>';
		printf( '<input type="date" name="received_on" value="%s" /> ', esc_attr( gmdate( 'Y-m-d' ) ) );

		echo '<select name="material">';
		foreach ( RevisionRepository::MATERIALS as $material ) {
			printf( '<option value="%s">%s</option>', esc_attr( $material ), esc_html( $material ) );
		}
		echo '</select> ';

		echo '<select name="color">';
		foreach ( ColorRegistry::all() as $slug => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $label ) );
		}
		echo '</select> ';

		printf( '<input type="text" name="manufacturer" placeholder="%s" size="12" /> ', esc_attr__( 'Hersteller', 'lindenweg16' ) );
		printf( '<input type="text" name="kg_received" placeholder="%s" size="6" /> ', esc_attr__( 'kg', 'lindenweg16' ) );
		printf( '<input type="text" name="price_per_kg" placeholder="%s" size="6" /> ', esc_attr__( '€/kg', 'lindenweg16' ) );
		printf( '<input type="text" name="location" placeholder="%s" size="12" /> ', esc_attr__( 'Standort', 'lindenweg16' ) );
		printf( '<button class="button button-primary">%s</button>', esc_html__( 'Erfassen', 'lindenweg16' ) );
		echo '</p></form>';
	}

	/**
	 * Verbrauchsmaterial ohne Stücklistenbezug.
	 */
	protected function render_supplies() {
		$supplies = SupplyRepository::all();

		echo '<h2>' . esc_html__( 'Verbrauchsmaterial', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Kartons, Holzwolle, Seidenpapier, Klebeband – wird je Bestellung pauschal abgebucht.', 'lindenweg16' ) . '</p>';

		if ( $supplies ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Bezeichnung', 'lindenweg16' ),
				__( 'Einheit', 'lindenweg16' ),
				__( 'Bestand', 'lindenweg16' ),
				__( 'je Bestellung', 'lindenweg16' ),
				__( 'Lieferzeit', 'lindenweg16' ),
				__( 'Puffer', 'lindenweg16' ),
				__( 'Lieferant', 'lindenweg16' ),
				__( 'Preis', 'lindenweg16' ),
				'',
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $supplies as $supply ) {
				echo '<tr>';
				printf( '<td><strong>%s</strong></td>', esc_html( $supply['name'] ) );
				printf( '<td>%s</td>', esc_html( $supply['unit'] ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $supply['stock_qty'], 1 ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $supply['consumption_per_order'], 2 ) ) );
				printf( '<td>%s</td>', esc_html( (int) $supply['lead_time_days'] ) );
				printf( '<td>%s</td>', esc_html( (int) $supply['safety_days'] ) );
				printf( '<td>%s</td>', esc_html( (string) $supply['supplier'] ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $supply['price_per_unit'], 2 ) ) );

				echo '<td>';
				printf(
					'<a class="button button-small" href="%s">%s</a> ',
					esc_url( admin_url( 'admin.php?page=lw16-betrieb-material&supply=' . (int) $supply['id'] ) ),
					esc_html__( 'Bearbeiten', 'lindenweg16' )
				);

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="action" value="lw16_betrieb_delete_supply" />';
				printf( '<input type="hidden" name="supply_id" value="%d" />', (int) $supply['id'] );
				printf(
					'<button class="button button-small" onclick="return confirm(%s)">%s</button>',
					esc_attr( wp_json_encode( __( 'Wirklich löschen? Es wird danach nicht mehr je Bestellung abgebucht.', 'lindenweg16' ) ) ),
					esc_html__( 'Löschen', 'lindenweg16' )
				);
				echo '</form>';
				echo '</td>';

				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$edit = isset( $_GET['supply'] ) ? SupplyRepository::find( (int) $_GET['supply'] ) : null;

		echo '<h3>' . esc_html( $edit ? __( 'Verbrauchsmaterial bearbeiten', 'lindenweg16' ) : __( 'Verbrauchsmaterial anlegen', 'lindenweg16' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_save_supply" />';

		if ( $edit ) {
			printf( '<input type="hidden" name="supply_id" value="%d" />', (int) $edit['id'] );
		}

		$wert = static function ( $feld, $vorgabe = '' ) use ( $edit ) {
			return $edit && isset( $edit[ $feld ] ) && '' !== (string) $edit[ $feld ]
				? (string) $edit[ $feld ]
				: $vorgabe;
		};

		echo '<p>';
		printf( '<input type="text" name="name" placeholder="%s" size="18" value="%s" /> ', esc_attr__( 'Bezeichnung', 'lindenweg16' ), esc_attr( $wert( 'name' ) ) );
		printf( '<input type="text" name="unit" placeholder="%s" size="8" value="%s" /> ', esc_attr__( 'Einheit', 'lindenweg16' ), esc_attr( $wert( 'unit', 'Stück' ) ) );
		printf( '<input type="text" name="stock_qty" placeholder="%s" size="6" value="%s" /> ', esc_attr__( 'Bestand', 'lindenweg16' ), esc_attr( $wert( 'stock_qty' ) ) );
		printf( '<input type="text" name="consumption_per_order" placeholder="%s" size="8" value="%s" /> ', esc_attr__( 'je Bestellung', 'lindenweg16' ), esc_attr( $wert( 'consumption_per_order' ) ) );
		printf( '<input type="text" name="lead_time_days" placeholder="%s" size="6" value="%s" /> ', esc_attr__( 'Lieferzeit', 'lindenweg16' ), esc_attr( $wert( 'lead_time_days' ) ) );
		printf( '<input type="text" name="safety_days" placeholder="%s" size="6" value="%s" /> ', esc_attr__( 'Puffer', 'lindenweg16' ), esc_attr( $wert( 'safety_days', '7' ) ) );
		printf( '<input type="text" name="min_order_qty" placeholder="%s" size="8" value="%s" /> ', esc_attr__( 'Mindestmenge', 'lindenweg16' ), esc_attr( $wert( 'min_order_qty' ) ) );
		printf( '<input type="text" name="supplier" placeholder="%s" size="12" value="%s" /> ', esc_attr__( 'Lieferant', 'lindenweg16' ), esc_attr( $wert( 'supplier' ) ) );
		printf( '<input type="text" name="price_per_unit" placeholder="%s" size="6" value="%s" /> ', esc_attr__( 'Preis', 'lindenweg16' ), esc_attr( $wert( 'price_per_unit' ) ) );
		printf( '<button class="button button-primary">%s</button>', esc_html( $edit ? __( 'Speichern', 'lindenweg16' ) : __( 'Anlegen', 'lindenweg16' ) ) );

		if ( $edit ) {
			printf(
				' <a class="button" href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=lw16-betrieb-material' ) ),
				esc_html__( 'Abbrechen', 'lindenweg16' )
			);
		}
		echo '</p></form>';
	}
}
