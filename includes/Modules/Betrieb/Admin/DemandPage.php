<?php
/**
 * Bedarf und Fertigungsvorschläge.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\BinRepository;
use Lindenweg16\Modules\Betrieb\DemandPlanner;
use Lindenweg16\Modules\Betrieb\PicklistRepository;
use Lindenweg16\Modules\Betrieb\StockRepository;
use Lindenweg16\Modules\Betrieb\WorkOrderRepository;

/**
 * Zeigt, was gedruckt, montiert und bestellt werden muss.
 */
class DemandPage {

	const NONCE = 'lw16_betrieb_demand';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_bedarf', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_demand_order', array( $this, 'handle_order' ) );
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
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type success oder error.
	 */
	protected function redirect( $msg, $type = 'success' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'lw16-betrieb-bedarf',
					'lw16_msg' => rawurlencode( $msg ),
					'lw16_typ' => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Aus einem Vorschlag einen Fertigungsauftrag machen.
	 */
	public function handle_order() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$article_id = isset( $_POST['article_id'] ) ? (int) $_POST['article_id'] : 0;
		$qty        = isset( $_POST['qty'] ) ? (float) str_replace( ',', '.', wp_unslash( $_POST['qty'] ) ) : 0;
		$color      = isset( $_POST['color'] ) ? sanitize_key( wp_unslash( $_POST['color'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = WorkOrderRepository::validate(
			array(
				'article_id'  => $article_id,
				'qty_planned' => $qty,
				'color'       => $color,
			)
		);

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error' );
		}

		$id = WorkOrderRepository::insert( $result['data'] );

		if ( ! $id ) {
			$this->redirect( __( 'Auftrag konnte nicht angelegt werden.', 'lindenweg16' ), 'error' );
		}

		$order       = WorkOrderRepository::find( $id );
		$picklist_id = PicklistRepository::from_work_order( $order, BinRepository::article_hints(), BinRepository::component_hints() );

		if ( $picklist_id ) {
			WorkOrderRepository::attach_picklist( $id, $picklist_id );
		}

		$this->redirect(
			sprintf(
				/* translators: %s: Auftragscode. */
				__( 'Fertigungsauftrag %s angelegt, Materialliste steht bereit.', 'lindenweg16' ),
				$order['code']
			)
		);
	}

	/* ====================================================================
	 * Ausgabe
	 * ================================================================== */

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		// phpcs:disable WordPress.Security.NonceVerification
		$with_soft = ! isset( $_GET['nur_fest'] );
		// phpcs:enable WordPress.Security.NonceVerification

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Bedarf', 'lindenweg16' ) . '</h1>';
		$this->notice();

		echo '<p>' . esc_html__( 'Was muss heute entstehen? Gerechnet wird von oben nach unten: Aus dem Bedarf an fertigen Artikeln wird der Bedarf an Halbzeugen, daraus der an Rohlingen und Kleinteilen. Auf jeder Stufe zählt, was schon im Regal liegt und was bereits in Arbeit ist.', 'lindenweg16' ) . '</p>';

		$base = admin_url( 'admin.php?page=lw16-betrieb-bedarf' );
		echo '<nav class="lw16-tabs">';
		printf(
			'<a href="%s" class="%s">%s</a> ',
			esc_url( $base ),
			$with_soft ? 'active' : '',
			esc_html__( 'Mit Wunschliste', 'lindenweg16' )
		);
		printf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( add_query_arg( 'nur_fest', '1', $base ) ),
			$with_soft ? '' : 'active',
			esc_html__( 'Nur Bestellungen und Sollbestand', 'lindenweg16' )
		);
		echo '</nav>';

		$plan = DemandPlanner::plan( DemandPlanner::collect( $with_soft ), DemandPlanner::context() );

		foreach ( $plan['warnings'] as $warning ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $warning ) );
		}

		$todo = DemandPlanner::actionable( $plan['articles'] );

		if ( ! $todo ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Nichts zu tun – alle Bedarfe sind durch Bestand und laufende Aufträge gedeckt.', 'lindenweg16' ) . '</p></div>';
		} else {
			$this->render_articles( $todo );
		}

		$this->render_components( $plan['components'] );
		$this->render_covered( $plan['articles'] );

		echo '</div>';
	}

	/**
	 * Meldung aus dem Umleitungsparameter.
	 */
	protected function notice() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['lw16_msg'] ) ) {
			return;
		}
		$type = isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success';
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Vorschläge je Artikel.
	 *
	 * @param array $rows Zeilen mit offenem Bedarf.
	 */
	protected function render_articles( array $rows ) {
		$actions = DemandPlanner::action_labels();
		$sources = DemandPlanner::source_labels();

		echo '<h2>' . esc_html__( 'Zu tun', 'lindenweg16' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Von unten nach oben abarbeiten: Was am tiefsten in der Stückliste steckt, steht oben – es muss zuerst da sein.', 'lindenweg16' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Was', 'lindenweg16' ),
			__( 'Artikel', 'lindenweg16' ),
			__( 'Bedarf', 'lindenweg16' ),
			__( 'Bestand', 'lindenweg16' ),
			__( 'In Arbeit', 'lindenweg16' ),
			__( 'Fehlt', 'lindenweg16' ),
			__( 'Wofür', 'lindenweg16' ),
			'',
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$why = array();
			foreach ( $row['sources'] as $key => $source ) {
				$why[] = sprintf(
					'%s %s%s',
					number_format_i18n( $source['qty'], 0 ),
					isset( $sources[ $key ] ) ? $sources[ $key ] : $key,
					$source['labels'] ? ' (' . implode( ', ', array_slice( $source['labels'], 0, 4 ) ) . ')' : ''
				);
			}

			echo '<tr>';
			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
				'drucken' === $row['action'] ? 'gelb' : ( 'montieren' === $row['action'] ? 'gruen' : 'rot' ),
				esc_html( isset( $actions[ $row['action'] ] ) ? $actions[ $row['action'] ] : $row['action'] )
			);
			printf( '<td><strong>%s</strong><br /><small>%s</small></td>', esc_html( $row['sku'] ), esc_html( $row['name'] ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $row['gross'], 0 ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $row['stock'], 0 ) ) );
			printf( '<td>%s</td>', $row['in_work'] > 0 ? esc_html( number_format_i18n( $row['in_work'], 0 ) ) : '—' );
			printf( '<td><strong>%s</strong></td>', esc_html( number_format_i18n( $row['net'], 0 ) ) );
			printf( '<td><small>%s</small></td>', esc_html( implode( ' · ', $why ) ) );

			echo '<td>';
			if ( 'montieren' === $row['action'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="action" value="lw16_betrieb_demand_order" />';
				printf( '<input type="hidden" name="article_id" value="%d" />', (int) $row['article_id'] );
				printf( '<input type="hidden" name="qty" value="%s" />', esc_attr( (string) $row['net'] ) );
				printf( '<button class="button button-small button-primary">%s</button>', esc_html__( 'Auftrag anlegen', 'lindenweg16' ) );
				echo '</form>';
			} elseif ( 'drucken' === $row['action'] ) {
				printf(
					'<a class="button button-small" href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=lw16-betrieb-druckjournal' ) ),
					esc_html__( 'Ins Druckjournal', 'lindenweg16' )
				);
			} else {
				printf(
					'<a class="button button-small" href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=lw16-betrieb-material' ) ),
					esc_html__( 'Beschaffung', 'lindenweg16' )
				);
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Kleinteilebedarf.
	 *
	 * @param array $rows Komponentenzeilen.
	 */
	protected function render_components( array $rows ) {
		$missing = array_filter(
			$rows,
			static function ( $row ) {
				return $row['net'] > 0.0005;
			}
		);

		echo '<h2>' . esc_html__( 'Kleinteile für diese Bedarfe', 'lindenweg16' ) . '</h2>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Keine Kleinteile betroffen.', 'lindenweg16' ) . '</p>';
			return;
		}

		if ( ! $missing ) {
			echo '<p>' . esc_html__( 'Alle benötigten Kleinteile sind vorrätig.', 'lindenweg16' ) . '</p>';
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Kleinteil', 'lindenweg16' ),
			__( 'Benötigt', 'lindenweg16' ),
			__( 'Bestand', 'lindenweg16' ),
			__( 'Fehlt', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr%s>',
				$row['net'] > 0.0005 ? ' class="lw16-fehlend"' : ''
			);
			printf( '<td>%s – %s</td>', esc_html( $row['code'] ), esc_html( $row['name'] ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $row['gross'], 0 ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $row['stock'], 0 ) ) );
			printf( '<td><strong>%s</strong></td>', esc_html( number_format_i18n( $row['net'], 0 ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Bedarfe, die bereits gedeckt sind – zur Kontrolle.
	 *
	 * @param array $rows Alle Zeilen.
	 */
	protected function render_covered( array $rows ) {
		$covered = array_filter(
			$rows,
			static function ( $row ) {
				return $row['net'] <= 0.0005 && $row['gross'] > 0;
			}
		);

		if ( ! $covered ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Gedeckt', 'lindenweg16' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Diese Bedarfe bestehen, sind aber durch Bestand oder laufende Aufträge abgedeckt. Sie stehen hier, damit sichtbar bleibt, dass sie berücksichtigt wurden.', 'lindenweg16' ) . '</p>';

		echo '<table class="widefat striped"><tbody>';
		foreach ( $covered as $row ) {
			printf(
				'<tr><td>%s – %s</td><td>%s</td></tr>',
				esc_html( $row['sku'] ),
				esc_html( $row['name'] ),
				esc_html(
					sprintf(
						/* translators: 1: Bedarf, 2: Bestand, 3: in Arbeit. */
						__( 'Bedarf %1$s · Bestand %2$s · in Arbeit %3$s', 'lindenweg16' ),
						number_format_i18n( $row['gross'], 0 ),
						number_format_i18n( $row['stock'], 0 ),
						number_format_i18n( $row['in_work'], 0 )
					)
				)
			);
		}
		echo '</tbody></table>';
	}
}
