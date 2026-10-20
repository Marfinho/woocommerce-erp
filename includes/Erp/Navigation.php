<?php
/**
 * Navigation der ERP-Oberfläche.
 *
 * Eine Liste für alles: Die Seitenleiste, die Bereichsübersichten, die
 * Suche und die Rechteprüfung beim Aufruf lesen alle dieselbe Tabelle.
 * Dadurch kann das Menü nichts anbieten, was die Rechteprüfung dann
 * verweigert – und umgekehrt.
 *
 * Zwei Arten von Seiten:
 *
 * - `native` – gehört der neuen Oberfläche (Start, Rechte).
 * - `legacy` – die bestehende wp-admin-Seite, eingebettet. So steht von
 *   Anfang an der volle Funktionsumfang bereit; native Seiten ersetzen
 *   die eingebetteten nach und nach.
 *
 * Frei von Datenbank und WordPress, damit die Zuordnung prüfbar ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Seitenverzeichnis.
 */
class Navigation {

	/**
	 * Bereiche.
	 *
	 * @return array Kennung => [Titel, Symbol, Beschreibung].
	 */
	public static function areas() {
		return array(
			'verkauf'    => array( __( 'Verkauf', 'lindenweg16' ), 'cart', __( 'Bestellungen, Aufträge, Versand und Kunden', 'lindenweg16' ) ),
			'fertigung'  => array( __( 'Fertigung', 'lindenweg16' ), 'hammer', __( 'Artikel, Drucker, Qualität und Druckfarm', 'lindenweg16' ) ),
			'lager'      => array( __( 'Lager', 'lindenweg16' ), 'box', __( 'Bestand, Lagerplätze, Material und Bedarf', 'lindenweg16' ) ),
			'finanzen'   => array( __( 'Finanzen', 'lindenweg16' ), 'euro', __( 'Berichte, Buchhaltung und Kosten', 'lindenweg16' ) ),
			'verwaltung' => array( __( 'Verwaltung', 'lindenweg16' ), 'cog', __( 'Zeit, Einstellungen, Rechte und Support', 'lindenweg16' ) ),
		);
	}

	/**
	 * Die Plätze der Menüführung.
	 *
	 * Die Bereiche (areas) gehören den Rechten: Sie gruppieren den Katalog der
	 * Berechtigungen. Die Plätze hier gehören der Oberfläche: sechs links in
	 * der Leiste, dazu Mailbox und Profilmenü. Beides ist bewusst getrennt,
	 * damit sich die Menüführung ändern lässt, ohne Rechte anzufassen.
	 *
	 * @return array Kennung => [Titel, Symbol, Hinweis, in der Leiste].
	 */
	public static function sections() {
		return array(
			'produktion' => array( __( 'Produktion', 'lindenweg16' ), 'hammer', __( 'Planen, rüsten, drucken, melden', 'lindenweg16' ), true ),
			'verkauf'    => array( __( 'Verkauf', 'lindenweg16' ), 'cart', __( 'Von der Bestellung bis zum Paket', 'lindenweg16' ), true ),
			'lager'      => array( __( 'Lager', 'lindenweg16' ), 'box', __( 'Was da ist, was kommt, was fehlt', 'lindenweg16' ), true ),
			'katalog'    => array( __( 'Katalog', 'lindenweg16' ), 'book', __( 'Alles, was verkauft wird, an einem Ort', 'lindenweg16' ), true ),
			'zahlen'     => array( __( 'Zahlen', 'lindenweg16' ), 'euro', __( 'Was kam rein, was blieb übrig', 'lindenweg16' ), true ),
			'mail'       => array( __( 'Mailbox', 'lindenweg16' ), 'mail', __( 'Postfächer und Nachrichten', 'lindenweg16' ), false ),
			'profil'     => array( __( 'Profil und Einstellungen', 'lindenweg16' ), 'cog', __( 'Selten gebraucht', 'lindenweg16' ), false ),
		);
	}

	/**
	 * Wohin eine Seite in der Menüführung gehört: Platz und Reiter.
	 *
	 * Seiten mit demselben Reiter liegen unter diesem Reiter als Auswahl
	 * nebeneinander; die Reihenfolge hier ist die Reihenfolge dort. Eine Seite
	 * ohne Eintrag landet im Profilmenü, damit nichts verschwindet.
	 *
	 * @return array Seitenkennung => [Platz, Reiter].
	 */
	public static function layout() {
		return array(
			'workflow'         => array( 'produktion', __( 'Plan und Board', 'lindenweg16' ) ),
			'produktion'       => array( 'produktion', __( 'Plan und Board', 'lindenweg16' ) ),
			'druckfarm'        => array( 'produktion', __( 'Drucker', 'lindenweg16' ) ),
			'drucker'          => array( 'produktion', __( 'Drucker', 'lindenweg16' ) ),
			'bambu'            => array( 'produktion', __( 'Drucker', 'lindenweg16' ) ),
			'sollist'          => array( 'produktion', __( 'Soll gegen Ist', 'lindenweg16' ) ),
			'kapazitaet'       => array( 'produktion', __( 'Soll gegen Ist', 'lindenweg16' ) ),
			'druckjournal'     => array( 'produktion', __( 'Journal', 'lindenweg16' ) ),
			'qualitaet'        => array( 'produktion', __( 'Journal', 'lindenweg16' ) ),

			'versand'          => array( 'verkauf', __( 'Bestellungen', 'lindenweg16' ) ),
			'auftraege'        => array( 'verkauf', __( 'Bestellungen', 'lindenweg16' ) ),
			'picken'           => array( 'verkauf', __( 'Bestellungen', 'lindenweg16' ) ),
			'shop-bestellungen' => array( 'verkauf', __( 'Bestellungen', 'lindenweg16' ) ),
			'kontakte'         => array( 'verkauf', __( 'Kunden', 'lindenweg16' ) ),
			'loyalty'          => array( 'verkauf', __( 'Kunden', 'lindenweg16' ) ),
			'kasse'            => array( 'verkauf', __( 'Kasse', 'lindenweg16' ) ),
			'marketing'        => array( 'verkauf', __( 'Marketing', 'lindenweg16' ) ),

			'lager'            => array( 'lager', __( 'Bestand', 'lindenweg16' ) ),
			'lagerplaetze'     => array( 'lager', __( 'Bestand', 'lindenweg16' ) ),
			'scannen'          => array( 'lager', __( 'Bestand', 'lindenweg16' ) ),
			'material'         => array( 'lager', __( 'Material', 'lindenweg16' ) ),
			'einkauf'          => array( 'lager', __( 'Einkauf', 'lindenweg16' ) ),
			'bedarf'           => array( 'lager', __( 'Einkauf', 'lindenweg16' ) ),

			'artikel'          => array( 'katalog', __( 'Produkte', 'lindenweg16' ) ),
			'shop-produkte'    => array( 'katalog', __( 'Produkte', 'lindenweg16' ) ),
			'konfigurator'     => array( 'katalog', __( 'Konfigurator', 'lindenweg16' ) ),
			'arview'           => array( 'katalog', __( 'AR-Ansicht', 'lindenweg16' ) ),

			'finanz-dashboard' => array( 'zahlen', __( 'Übersicht', 'lindenweg16' ) ),
			'berichte'         => array( 'zahlen', __( 'Berichte', 'lindenweg16' ) ),
			'reporting'        => array( 'zahlen', __( 'Berichte', 'lindenweg16' ) ),
			'buchhaltung'      => array( 'zahlen', __( 'Buchhaltung', 'lindenweg16' ) ),

			'mailbox'          => array( 'mail', __( 'Mailbox', 'lindenweg16' ) ),
			'mailbox-konten'   => array( 'mail', __( 'Mailbox', 'lindenweg16' ) ),
		);
	}

	/**
	 * Platz und Reiter einer Seite.
	 *
	 * @param string $id Seitenkennung.
	 * @return array [Platz, Reiter]
	 */
	public static function place( $id ) {
		$layout = self::layout();

		return isset( $layout[ $id ] ) ? $layout[ $id ] : array( 'profil', '' );
	}

	/**
	 * Die sichtbaren Seiten, geordnet nach Platz und Reiter.
	 *
	 * @param callable $can Prüft ein Recht.
	 * @return array Platz => [ [label, items[]], … ]
	 */
	public static function sections_visible( callable $can ) {
		$order  = array_keys( self::layout() );
		$out    = array();
		$items  = self::items();

		foreach ( array_keys( self::sections() ) as $section ) {
			$out[ $section ] = array();
		}

		// Reihenfolge der Reiter und Seiten: wie im Layout, Rest hinten.
		$ids = array_merge( $order, array_diff( array_keys( $items ), $order ) );

		foreach ( $ids as $id ) {
			if ( ! isset( $items[ $id ] ) || ! $can( $items[ $id ]['perm'] ) ) {
				continue;
			}

			list( $section, $tab ) = self::place( $id );

			$found = false;
			foreach ( $out[ $section ] as $i => $existing ) {
				if ( $existing['label'] === $tab ) {
					$out[ $section ][ $i ]['items'][] = $items[ $id ];
					$found                              = true;
					break;
				}
			}

			if ( ! $found ) {
				$out[ $section ][] = array( 'label' => $tab, 'items' => array( $items[ $id ] ) );
			}
		}

		return array_filter( $out );
	}

	/**
	 * Ein Seiteneintrag.
	 *
	 * @param string $id     Kennung (Teil der Adresse).
	 * @param string $area   Bereich.
	 * @param string $title  Titel.
	 * @param string $perm   Recht aus Permissions::catalog().
	 * @param string $target Bei legacy: Seitenkennung oder Pfad im wp-admin; bei native: leer.
	 * @param string $kind   legacy oder native.
	 * @param string $wp_cap Zusätzlich verlangtes WordPress-Recht (Shop).
	 * @param string $alt    Bei native: Kennung der klassischen wp-admin-Seite, die weiter besteht.
	 * @return array
	 */
	protected static function item( $id, $area, $title, $perm, $target = '', $kind = 'legacy', $wp_cap = '', $alt = '' ) {
		return array(
			'alt'    => $alt,
			'id'     => $id,
			'area'   => $area,
			'title'  => $title,
			'perm'   => $perm,
			'kind'   => $kind,
			'target' => $target,
			'wp_cap' => $wp_cap,
		);
	}

	/**
	 * Alle Seiten in Anzeigereihenfolge.
	 *
	 * @return array Kennung => Eintrag.
	 */
	public static function items() {
		$list = array(
			self::item( 'shop-bestellungen', 'verkauf', __( 'Shop-Bestellungen', 'lindenweg16' ), 'verkauf.shop_orders', '', 'native', 'edit_shop_orders', 'admin.php?page=wc-orders' ),
			self::item( 'auftraege', 'verkauf', __( 'Aufträge', 'lindenweg16' ), 'verkauf.orders', '', 'native', '', 'lw16-betrieb-bestellungen' ),
			self::item( 'picken', 'verkauf', __( 'Picklisten', 'lindenweg16' ), 'verkauf.picken', '', 'native' ),
			self::item( 'versand', 'verkauf', __( 'Versand', 'lindenweg16' ), 'verkauf.versand', '', 'native', '', 'lw16-betrieb-versand' ),
			self::item( 'kontakte', 'verkauf', __( 'Kontakte / B2B', 'lindenweg16' ), 'verkauf.kontakte', '', 'native', '', 'lw16-betrieb-kontakte' ),
			self::item( 'shop-produkte', 'verkauf', __( 'Shop-Produkte', 'lindenweg16' ), 'verkauf.shop_products', 'edit.php?post_type=product', 'legacy', 'edit_products' ),
			self::item( 'loyalty', 'verkauf', __( 'Loyalty', 'lindenweg16' ), 'verkauf.loyalty', '', 'native', '', 'lw16-loyalty' ),
			self::item( 'kasse', 'verkauf', __( 'Kasse', 'lindenweg16' ), 'verkauf.kasse', 'lw16-kasse' ),
			self::item( 'marketing', 'verkauf', __( 'Marketing', 'lindenweg16' ), 'verkauf.marketing', '', 'native', '', 'lw16-marketing' ),

			self::item( 'workflow', 'fertigung', __( 'Workflow', 'lindenweg16' ), 'fertigung.workflow', '', 'native' ),
			self::item( 'druckfarm', 'fertigung', __( 'Druckfarm', 'lindenweg16' ), 'fertigung.druckfarm', '', 'native', '', 'lw16-betrieb-druckfarm' ),
			self::item( 'sollist', 'fertigung', __( 'Soll gegen Ist', 'lindenweg16' ), 'fertigung.sollist', '', 'native' ),
			self::item( 'druckjournal', 'fertigung', __( 'Druckjournal', 'lindenweg16' ), 'fertigung.druckjournal', '', 'native', '', 'lw16-betrieb-druckjournal' ),
			self::item( 'artikel', 'fertigung', __( 'Alle Produkte', 'lindenweg16' ), 'fertigung.artikel', '', 'native', '', 'lw16-betrieb-artikel' ),
			self::item( 'konfigurator', 'fertigung', __( 'Konfigurator', 'lindenweg16' ), 'fertigung.konfigurator', '', 'native', '', 'lw16-betrieb-konfigurator' ),
			self::item( 'kapazitaet', 'fertigung', __( 'Kapazität', 'lindenweg16' ), 'fertigung.kapazitaet', '', 'native', '', 'lw16-betrieb-kapazitaet' ),
			self::item( 'qualitaet', 'fertigung', __( 'Qualität', 'lindenweg16' ), 'fertigung.qualitaet', '', 'native', '', 'lw16-betrieb-qualitaet' ),
			self::item( 'drucker', 'fertigung', __( '3D-Drucker & Wartung', 'lindenweg16' ), 'fertigung.drucker', '', 'native', '', 'lw16-betrieb-drucker' ),
			self::item( 'bambu', 'fertigung', __( 'Bambu-Lab-Drucker', 'lindenweg16' ), 'fertigung.bambu', '', 'native', '', 'lw16-betrieb-bambu' ),
			self::item( 'produktion', 'fertigung', __( 'Produktion', 'lindenweg16' ), 'fertigung.produktion', '', 'native', '', 'lw16-produktion' ),

			self::item( 'scannen', 'lager', __( 'Scannen', 'lindenweg16' ), 'lager.scannen', '', 'native' ),
			self::item( 'lager', 'lager', __( 'Lager', 'lindenweg16' ), 'lager.bestand', '', 'native', '', 'lw16-betrieb-lager' ),
			self::item( 'lagerplaetze', 'lager', __( 'Lagerplätze & Picklisten', 'lindenweg16' ), 'lager.lagerplaetze', '', 'native', '', 'lw16-betrieb-lagerplaetze' ),
			self::item( 'material', 'lager', __( 'Material', 'lindenweg16' ), 'lager.material', '', 'native', '', 'lw16-betrieb-material' ),
			self::item( 'einkauf', 'lager', __( 'Einkauf', 'lindenweg16' ), 'lager.einkauf', '', 'native' ),
			self::item( 'bedarf', 'lager', __( 'Bedarf', 'lindenweg16' ), 'lager.bedarf', '', 'native', '', 'lw16-betrieb-bedarf' ),

			self::item( 'berichte', 'finanzen', __( 'Berichte', 'lindenweg16' ), 'finanzen.berichte', '', 'native', '', 'lw16-betrieb-berichte' ),
			self::item( 'buchhaltung', 'finanzen', __( 'Buchhaltung', 'lindenweg16' ), 'finanzen.buchhaltung', '', 'native', '', 'lw16-buchhaltung' ),
			self::item( 'reporting', 'finanzen', __( 'Reporting', 'lindenweg16' ), 'finanzen.reporting', '', 'native', '', 'lw16-reporting' ),
			self::item( 'finanz-dashboard', 'finanzen', __( 'Dashboard Finanzen', 'lindenweg16' ), 'finanzen.dashboard', '', 'native', '', 'lindenweg16' ),

			self::item( 'zeit', 'verwaltung', __( 'Zeiterfassung', 'lindenweg16' ), 'verwaltung.zeit', '', 'native', '', 'lw16-betrieb-zeit' ),
			self::item( 'mailbox', 'verwaltung', __( 'Mailbox', 'lindenweg16' ), 'verwaltung.mailbox', '', 'native', '', 'lw16-mailbox' ),
			self::item( 'mailbox-konten', 'verwaltung', __( 'Postfächer', 'lindenweg16' ), 'verwaltung.mailbox_konten', '', 'native', '', 'lw16-mailbox-konten' ),
			self::item( 'etiketten', 'verwaltung', __( 'Etikettendrucker', 'lindenweg16' ), 'verwaltung.etiketten', '', 'native', '', 'lw16-betrieb-druckwarteschlange-drucker' ),
			self::item( 'rechte', 'verwaltung', __( 'Benutzer & Rechte', 'lindenweg16' ), 'verwaltung.rechte', '', 'native' ),
			self::item( 'loehne', 'verwaltung', __( 'Löhne & Protokoll', 'lindenweg16' ), 'verwaltung.loehne', '', 'native', '', 'lw16-betrieb-benutzer' ),
			self::item( 'einstellungen', 'verwaltung', __( 'Einstellungen', 'lindenweg16' ), 'verwaltung.einstellungen', '', 'native', '', 'lw16-betrieb-einstellungen' ),
			self::item( 'import', 'verwaltung', __( 'Import & Doku', 'lindenweg16' ), 'verwaltung.import', '', 'native', '', 'lw16-betrieb-import' ),
			self::item( 'arview', 'verwaltung', __( 'AR-Ansicht', 'lindenweg16' ), 'verwaltung.arview', '', 'native', '', 'lw16-arview' ),
			self::item( 'support', 'verwaltung', __( 'Support', 'lindenweg16' ), 'verwaltung.support', '', 'native', '', 'lw16-support' ),
			self::item( 'anleitungen', 'verwaltung', __( 'Anleitungen', 'lindenweg16' ), 'verwaltung.anleitungen', '', 'native', '', 'lw16-anleitungen' ),
		);

		$items = array();
		foreach ( $list as $item ) {
			$items[ $item['id'] ] = $item;
		}

		return $items;
	}

	/**
	 * Einen Eintrag über seine Kennung finden.
	 *
	 * @param string $id Kennung.
	 * @return array|null
	 */
	public static function find( $id ) {
		$items = self::items();

		return isset( $items[ $id ] ) ? $items[ $id ] : null;
	}

	/**
	 * Gehört ein wp-admin-Seitenname zu einer Plugin-Seite der Navigation?
	 *
	 * Nur Plugin-Seiten, die über eine Seitenkennung laufen: Shop-Seiten
	 * stehen als Pfad im Eintrag und passen nie auf einen ?page=-Wert, die
	 * prüft WooCommerce selbst, daran soll nichts rütteln.
	 *
	 * @param string $page Wert von ?page=.
	 * @return array|null
	 */
	public static function find_by_page( $page ) {
		$page = (string) $page;

		if ( '' === $page ) {
			return null;
		}

		foreach ( self::items() as $item ) {
			// Native Seiten behalten ihre klassische Seite im wp-admin; sie
			// bleibt erreichbar und deshalb auch gesperrt.
			if ( ( 'legacy' === $item['kind'] && $item['target'] === $page ) || ( '' !== $item['alt'] && $item['alt'] === $page ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Adresse einer eingebetteten Seite im wp-admin, relativ zu wp-admin/.
	 *
	 * @param array $item Eintrag.
	 * @return string
	 */
	public static function legacy_path( array $item ) {
		$target = 'legacy' === $item['kind'] ? $item['target'] : $item['alt'];

		if ( '' === $target ) {
			return '';
		}

		// Ein Pfad (mit Punkt) bleibt, wie er ist; eine reine Kennung
		// ist eine Plugin-Seite unter admin.php.
		return false !== strpos( $target, '.php' ) ? $target : 'admin.php?page=' . $target;
	}

	/**
	 * Die Navigation für jemanden, gefiltert nach seinen Rechten.
	 *
	 * @param callable $can Prüft ein Recht: function ( string $perm ): bool.
	 * @return array Bereich => [Einträge].
	 */
	public static function visible( callable $can ) {
		$tree = array();

		foreach ( self::areas() as $area => $meta ) {
			$tree[ $area ] = array();
		}

		foreach ( self::items() as $item ) {
			if ( $can( $item['perm'] ) ) {
				$tree[ $item['area'] ][] = $item;
			}
		}

		return array_filter( $tree );
	}
}
