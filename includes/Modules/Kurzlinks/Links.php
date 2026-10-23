<?php
/**
 * Kurzlinks: Speicher und Regeln.
 *
 * Die Links liegen als eine Option (Slug => Eintrag); es sind wenige, und die
 * Weiterleitung braucht sie bei jedem Seitenaufruf für die Rewrite-Regel. Die
 * Regeln (Slug prüfen, Eingaben bereinigen) sind ohne Datenbank testbar.
 *
 * Ein Eintrag: slug, beschreibung, seite_id (0 = keine), url ('' = keine), aktiv.
 * Sind seite_id und url leer, führt der Link auf die Startseite (Platzhalter).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Kurzlinks;

defined( 'ABSPATH' ) || exit;

/**
 * Verwaltung und Prüfung der Kurzlinks.
 */
class Links {

	const OPTION   = 'lw16_kurzlinks';
	const MAX_SLUG = 64;

	/**
	 * Die drei Links der ersten Paketbeilage.
	 *
	 * @return array Slug => Beschreibung.
	 */
	public static function default_links() {
		return array(
			'montage'  => __( 'Paketbeilage: Montageanleitung / Video', 'lindenweg16' ),
			'bewerten' => __( 'Paketbeilage: Bewertungsseite', 'lindenweg16' ),
			'freunde'  => __( 'Paketbeilage: Freunde werben (Text auf der Karte, ohne QR)', 'lindenweg16' ),
		);
	}

	/**
	 * Startwerte anlegen, wenn es noch keine Links gibt (Aktivierung, Migration).
	 *
	 * Ziel ist jeweils die Startseite als Platzhalter. Vorhandene Links werden nie angefasst.
	 */
	public static function seed_defaults() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}

		$links = array();

		foreach ( self::default_links() as $slug => $beschreibung ) {
			$links[ $slug ] = array(
				'slug'         => $slug,
				'beschreibung' => $beschreibung,
				'seite_id'     => 0,
				'url'          => '',
				'aktiv'        => true,
			);
		}

		update_option( self::OPTION, $links, true );
	}

	/**
	 * Alle Links.
	 *
	 * @return array Slug => Eintrag.
	 */
	public static function all() {
		$raw = get_option( self::OPTION, array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $slug => $link ) {
			if ( ! is_array( $link ) || ! is_string( $slug ) || '' === $slug ) {
				continue;
			}

			$out[ $slug ] = array(
				'slug'         => $slug,
				'beschreibung' => isset( $link['beschreibung'] ) ? (string) $link['beschreibung'] : '',
				'seite_id'     => isset( $link['seite_id'] ) ? (int) $link['seite_id'] : 0,
				'url'          => isset( $link['url'] ) ? (string) $link['url'] : '',
				'aktiv'        => ! empty( $link['aktiv'] ),
			);
		}

		return $out;
	}

	/**
	 * Nur die aktiven Links.
	 *
	 * @return array
	 */
	public static function active() {
		return array_filter(
			self::all(),
			function ( $link ) {
				return $link['aktiv'];
			}
		);
	}

	/**
	 * Einen Link holen.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public static function find( $slug ) {
		$all = self::all();

		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Links speichern.
	 *
	 * @param array $links Slug => Eintrag.
	 */
	public static function save( array $links ) {
		update_option( self::OPTION, $links, true );
	}

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank, getestet)
	 * ================================================================== */

	/**
	 * Pfadsegmente, die WordPress, WooCommerce und das Plugin selbst belegen.
	 *
	 * @return array
	 */
	public static function reserved_slugs() {
		return array(
			// WordPress.
			'wp-admin', 'wp-login', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-json', 'wp-cron',
			'wp-signup', 'wp-activate', 'wp-trackback', 'wp-comments-post', 'wp-register', 'wp-mail',
			'xmlrpc', 'xmlrpc.php', 'feed', 'feeds', 'rss', 'rss2', 'rdf', 'atom', 'comments', 'comment-page',
			'embed', 'trackback', 'page', 'category', 'tag', 'author', 'search', 'attachment', 'type', 'date',
			'admin', 'login', 'logout', 'register', 'dashboard', 'index', 'sitemap', 'sitemap-index',
			'wp-sitemap', 'robots', 'favicon', 'ads', 'sw', 'manifest',
			// WooCommerce.
			'wc-api', 'wc-auth', 'product', 'product-category', 'product-tag', 'shop', 'cart', 'checkout',
			'my-account', 'order-pay', 'order-received', 'wc-ajax',
			// Plugin.
			'erp', 'betrieb-erfassen', 'nachbestellen', 'praemien', 'wunschliste', 'support', 'ar-ansicht',
		);
	}

	/**
	 * Wie `reserved_slugs()`, dazu die Basen, die diese Installation für Beiträge,
	 * Produkte, Kategorien und Schlagwörter eingestellt hat.
	 *
	 * @return array
	 */
	public static function reserved_slugs_for_site() {
		global $wp_rewrite;

		$bases = self::reserved_slugs();

		if ( is_object( $wp_rewrite ) ) {
			foreach ( array( 'author_base', 'search_base', 'comments_base', 'pagination_base', 'feed_base' ) as $prop ) {
				if ( ! empty( $wp_rewrite->$prop ) && is_string( $wp_rewrite->$prop ) ) {
					$bases[] = $wp_rewrite->$prop;
				}
			}
		}

		if ( function_exists( 'get_post_types' ) ) {
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
				if ( is_array( $type->rewrite ) && ! empty( $type->rewrite['slug'] ) ) {
					$bases[] = $type->rewrite['slug'];
				}
			}
		}

		if ( function_exists( 'get_taxonomies' ) ) {
			foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
				if ( is_array( $tax->rewrite ) && ! empty( $tax->rewrite['slug'] ) ) {
					$bases[] = $tax->rewrite['slug'];
				}
			}
		}

		if ( function_exists( 'wc_get_permalink_structure' ) ) {
			$wc = wc_get_permalink_structure();
			foreach ( array( 'product_base', 'category_base', 'tag_base', 'attribute_base' ) as $key ) {
				if ( ! empty( $wc[ $key ] ) ) {
					$bases[] = $wc[ $key ];
				}
			}
		}

		$bases[] = (string) get_option( 'category_base', '' );
		$bases[] = (string) get_option( 'tag_base', '' );

		$out = array();

		foreach ( $bases as $base ) {
			// Erstes Pfadsegment: aus „/produkt/%product_cat%“ wird „produkt“.
			$segment = strtolower( strtok( trim( (string) $base, '/' ), '/' ) );

			if ( '' !== $segment && false === strpos( $segment, '%' ) ) {
				$out[ $segment ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * Gibt es schon Inhalt (Seite, Beitrag, Produkt …) unter diesem Pfad?
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function content_exists( $slug ) {
		if ( ! function_exists( 'get_page_by_path' ) ) {
			return false;
		}

		$types = get_post_types( array( 'public' => true ) );

		return (bool) get_page_by_path( $slug, OBJECT, array_values( $types ) );
	}

	/**
	 * Fehlermeldung zu einem neuen Slug, leer wenn er in Ordnung ist.
	 *
	 * @param string $raw      Eingabe vor dem Bereinigen.
	 * @param string $slug     Bereinigter Slug (`sanitize_title`).
	 * @param array  $existing Bereits vergebene Slugs.
	 * @param array  $reserved Reservierte Pfadsegmente.
	 * @param bool   $content  Ob dieser Pfad schon eine Seite, einen Beitrag oder ein Produkt hat.
	 * @return string
	 */
	public static function slug_error( $raw, $slug, array $existing, array $reserved, $content ) {
		if ( '' === $slug ) {
			return __( 'Bitte einen Slug eingeben.', 'lindenweg16' );
		}

		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			return __( 'Der Slug darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.', 'lindenweg16' );
		}

		if ( strlen( $slug ) > self::MAX_SLUG ) {
			/* translators: %d: Höchstzahl Zeichen. */
			return sprintf( __( 'Der Slug darf höchstens %d Zeichen lang sein.', 'lindenweg16' ), self::MAX_SLUG );
		}

		if ( in_array( $slug, $existing, true ) ) {
			return __( 'Diesen Slug gibt es schon als Kurzlink.', 'lindenweg16' );
		}

		if ( in_array( $slug, $reserved, true ) ) {
			return __( 'Dieser Slug ist von WordPress, WooCommerce oder dem Plugin belegt.', 'lindenweg16' );
		}

		if ( $content ) {
			return __( 'Unter diesem Pfad gibt es schon eine Seite, einen Beitrag oder ein Produkt. Der Kurzlink würde sie verdecken – bitte einen anderen Slug wählen oder dem Inhalt einen anderen Pfad geben.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * Host ohne „www.“, in Kleinbuchstaben.
	 *
	 * @param string $url URL oder Host.
	 * @return string
	 */
	public static function bare_host( $url ) {
		$host = parse_url( (string) $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		if ( ! is_string( $host ) ) {
			$host = (string) $url;
		}

		return preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/**
	 * Liegt das Ziel auf der eigenen Seite? Ein Pfad ohne Host zählt als intern.
	 *
	 * @param string $url       Ziel.
	 * @param string $home_host Host der Website (mit oder ohne www).
	 * @return bool
	 */
	public static function is_internal( $url, $home_host ) {
		$host = parse_url( (string) $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		if ( ! is_string( $host ) || '' === $host ) {
			return true;
		}

		return self::bare_host( $host ) === self::bare_host( $home_host );
	}

	/**
	 * Fehlermeldung zu einer freien Ziel-URL, leer wenn sie in Ordnung ist.
	 *
	 * Erlaubt sind http(s)-Adressen und Pfade auf der eigenen Seite. Ein Ziel, das
	 * wieder auf einen Kurzlink führt, wäre eine Schleife.
	 *
	 * @param string $url       Bereinigte URL (`esc_url_raw`) oder leer.
	 * @param string $raw       Eingabe vor dem Bereinigen.
	 * @param string $home_host Host der Website.
	 * @param array  $slugs     Slugs aller Kurzlinks.
	 * @return string
	 */
	public static function url_error( $url, $raw, $home_host, array $slugs ) {
		if ( '' === trim( (string) $raw ) ) {
			return '';
		}

		if ( '' === $url ) {
			return __( 'Die Ziel-URL ist ungültig. Erlaubt sind http(s)-Adressen und Pfade wie /montage-video.', 'lindenweg16' );
		}

		$scheme = parse_url( $url, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		if ( is_string( $scheme ) && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return __( 'Als Ziel sind nur http- und https-Adressen erlaubt.', 'lindenweg16' );
		}

		if ( 0 === strpos( trim( (string) $raw ), '//' ) ) {
			return __( 'Bitte die vollständige Adresse mit https:// angeben.', 'lindenweg16' );
		}

		if ( self::is_internal( $url, $home_host ) ) {
			$path = trim( (string) parse_url( $url, PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

			if ( in_array( strtolower( $path ), $slugs, true ) ) {
				return __( 'Das Ziel führt wieder auf einen Kurzlink – das wäre eine Schleife.', 'lindenweg16' );
			}
		}

		return '';
	}

	/**
	 * Eine abgeschickte Zeile bereinigen.
	 *
	 * @param array $row Rohdaten aus dem Formular (ohne Slashes).
	 * @return array slug (bereinigt), slug_raw, beschreibung, seite_id, url, url_raw, aktiv, loeschen.
	 */
	public static function sanitize_row( array $row ) {
		$slug_raw = isset( $row['slug'] ) && is_string( $row['slug'] ) ? $row['slug'] : '';
		$url_raw  = isset( $row['url'] ) && is_string( $row['url'] ) ? trim( $row['url'] ) : '';

		return array(
			'slug'         => sanitize_title( $slug_raw ),
			'slug_raw'     => $slug_raw,
			'beschreibung' => isset( $row['beschreibung'] ) && is_string( $row['beschreibung'] ) ? sanitize_text_field( $row['beschreibung'] ) : '',
			'seite_id'     => isset( $row['seite_id'] ) ? absint( $row['seite_id'] ) : 0,
			'url'          => '' === $url_raw ? '' : esc_url_raw( $url_raw, array( 'http', 'https' ) ),
			'url_raw'      => $url_raw,
			'aktiv'        => ! empty( $row['aktiv'] ),
			'loeschen'     => ! empty( $row['loeschen'] ),
		);
	}

	/**
	 * Abgeschickte Zeilen prüfen und mit den gespeicherten Links zusammenführen.
	 *
	 * Geprüft wird nur, was sich ändert: ein neuer Slug, ein neu aktivierter Link
	 * und jede geänderte Ziel-URL. Bestehende Slugs lassen sich nicht umbenennen
	 * (sie stehen schon gedruckt) – sie werden über die versteckte Kennung zugeordnet.
	 * Gibt es einen Fehler, wird nichts gespeichert.
	 *
	 * @param array    $rows      Rohzeilen aus dem Formular.
	 * @param array    $current   Gespeicherte Links (Slug => Eintrag).
	 * @param array    $reserved  Reservierte Pfadsegmente.
	 * @param callable $exists    Prüft, ob ein Pfad schon Inhalt hat: function ( string $slug ): bool.
	 * @param string   $home_host Host der Website.
	 * @return array [ 'links' => Slug => Eintrag, 'errors' => Zeilennummer => Meldungen ]
	 */
	public static function process( array $rows, array $current, array $reserved, callable $exists, $home_host ) {
		$links  = $current;
		$errors = array();
		$todo   = array();

		// Erst alle Zeilen bereinigen: Schleifenprüfung und Doppelte brauchen die Gesamtliste.
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$clean = self::sanitize_row( $row );
			$orig  = isset( $row['orig'] ) && is_string( $row['orig'] ) ? $row['orig'] : '';

			if ( '' !== $orig ) {
				if ( ! isset( $current[ $orig ] ) ) {
					continue; // Zwischenzeitlich gelöscht – ignorieren.
				}
				$clean['slug'] = $orig;
				$clean['neu']  = false;
			} else {
				// Ganz leere Zeile für einen neuen Link: nichts zu tun.
				if ( '' === $clean['slug_raw'] && '' === $clean['beschreibung'] && '' === $clean['url_raw'] && 0 === $clean['seite_id'] ) {
					continue;
				}
				$clean['neu'] = true;
			}

			$todo[ $i ] = $clean;
		}

		foreach ( $todo as $i => $c ) {
			if ( $c['loeschen'] && ! $c['neu'] ) {
				unset( $links[ $c['slug'] ] );
			}
		}

		$slugs_after = array_keys( $links );
		foreach ( $todo as $c ) {
			if ( $c['neu'] && ! $c['loeschen'] && '' !== $c['slug'] ) {
				$slugs_after[] = $c['slug'];
			}
		}

		foreach ( $todo as $i => $c ) {
			if ( $c['loeschen'] ) {
				continue;
			}

			$msgs = array();
			$slug = $c['slug'];
			$prev = $c['neu'] ? null : $current[ $slug ];

			if ( $c['neu'] ) {
				$msg = self::slug_error( $c['slug_raw'], $slug, array_keys( $links ), $reserved, '' !== $slug && $exists( $slug ) );
				if ( '' !== $msg ) {
					$msgs[] = $msg;
				}
			} elseif ( $c['aktiv'] && ! $prev['aktiv'] && $exists( $slug ) ) {
				$msgs[] = self::slug_error( $slug, $slug, array(), $reserved, true );
			}

			if ( 0 === $c['seite_id'] && ( ! $prev || $prev['url'] !== $c['url'] || ( '' !== $c['url_raw'] && '' === $c['url'] ) ) ) {
				$msg = self::url_error( $c['url'], $c['url_raw'], $home_host, $slugs_after );
				if ( '' !== $msg ) {
					$msgs[] = $msg;
				}
			}

			if ( $msgs ) {
				$errors[ $i ] = $msgs;
				continue;
			}

			$links[ $slug ] = array(
				'slug'         => $slug,
				'beschreibung' => $c['beschreibung'],
				'seite_id'     => $c['seite_id'],
				'url'          => $c['seite_id'] > 0 ? '' : $c['url'],
				'aktiv'        => $c['aktiv'],
			);
		}

		return array(
			'links'  => $links,
			'errors' => $errors,
		);
	}
}
