<?php
/**
 * Updates für Plugin und Theme direkt aus GitHub – über die normale
 * WordPress-Update-Funktion.
 *
 * WordPress fragt zweimal täglich (und unter Dashboard → Aktualisierungen →
 * „Erneut prüfen“) nach neuen Versionen. Hier hängen wir uns ein: Die
 * Versionsnummer im Branch `main` wird mit der installierten verglichen; ist
 * sie höher, erscheint wie bei jedem anderen Plugin „Jetzt aktualisieren“.
 * Herunterladen, Entpacken, Ersetzen und Reaktivieren erledigt WordPress
 * selbst.
 *
 * - Plugin: Version aus `lindenweg16.php`, Paket = ZIP des Branches.
 * - Theme „lindenweg16“: Version aus `block-theme/lindenweg16/style.css`,
 *   Paket = `block-theme/dist/lindenweg16.zip`.
 *
 * Ein Update erscheint nur, wenn die Versionsnummer erhöht wurde.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

class Updater {

	/** GitHub-Repository (Besitzer/Name). */
	const REPO = 'Marfinho/woocommerce-erp';

	/** Branch, aus dem aktualisiert wird. */
	const BRANCH = 'main';

	/** Ordnername des Themes. */
	const THEME = 'lindenweg16';

	/** Zwischenspeicher der zuletzt gelesenen Versionen. */
	const CACHE = 'lw16_updater_remote';

	/** Wie lange die gelesenen Versionen gelten (Sekunden). */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Hooks registrieren. Läuft auch im Cron – dort prüft WordPress auf Updates.
	 */
	public function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_plugin' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'inject_theme' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 0 );

		if ( is_admin() ) {
			add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
			add_action( 'admin_post_lw16_check_updates', array( $this, 'check_now' ) );
			add_action( 'admin_notices', array( $this, 'notice' ) );
			// „Erneut prüfen“ unter Dashboard → Aktualisierungen liest GitHub neu.
			add_action( 'load-update-core.php', array( $this, 'maybe_flush_on_force_check' ) );
		}
	}

	/**
	 * Adresse einer Datei im Branch (raw).
	 *
	 * @param string $path Pfad im Repository.
	 * @return string
	 */
	public static function raw_url( $path ) {
		return 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/' . ltrim( $path, '/' );
	}

	/**
	 * Paket des Plugins: ZIP des Branches. GitHub legt darin den Ordner
	 * `woocommerce-erp-main` an.
	 *
	 * @return string
	 */
	public static function plugin_package() {
		return 'https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip';
	}

	/**
	 * Paket des Themes.
	 *
	 * @return string
	 */
	public static function theme_package() {
		return self::raw_url( 'block-theme/dist/' . self::THEME . '.zip' );
	}

	/**
	 * Versionsnummer aus einem Dateikopf lesen (Plugin-Header oder style.css).
	 *
	 * @param string $content Dateiinhalt.
	 * @return string Version oder '' wenn keine gefunden.
	 */
	public static function parse_version( $content ) {
		if ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9A-Za-z.\-+]*)\s*$/mi', substr( (string) $content, 0, 8192 ), $m ) ) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Ist die Version auf GitHub neuer als die installierte?
	 *
	 * @param string $installed Installierte Version.
	 * @param string $remote    Version auf GitHub.
	 * @return bool
	 */
	public static function is_newer( $installed, $remote ) {
		return '' !== (string) $remote && version_compare( (string) $remote, (string) $installed, '>' );
	}

	/**
	 * Versionen auf GitHub lesen (zwischengespeichert).
	 *
	 * @param bool $fresh Zwischenspeicher übergehen.
	 * @return array{plugin:string,theme:string,checked:int}
	 */
	public static function remote( $fresh = false ) {
		$cached = $fresh ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['plugin'], $cached['theme'] ) ) {
			return $cached;
		}

		$data = array(
			'plugin'  => self::parse_version( self::fetch( self::raw_url( 'lindenweg16.php' ) ) ),
			'theme'   => self::parse_version( self::fetch( self::raw_url( 'block-theme/' . self::THEME . '/style.css' ) ) ),
			'checked' => time(),
		);

		// Ist GitHub nicht erreichbar, nur kurz merken – sonst fehlt das Update stundenlang.
		set_site_transient( self::CACHE, $data, ( '' === $data['plugin'] && '' === $data['theme'] ) ? 15 * MINUTE_IN_SECONDS : self::CACHE_TTL );

		return $data;
	}

	/**
	 * Datei von GitHub laden.
	 *
	 * @param string $url Adresse.
	 * @return string Inhalt oder '' bei Fehler.
	 */
	protected static function fetch( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Slug des Plugins (Ordnername, z. B. `woocommerce-erp-main`).
	 *
	 * @return string
	 */
	protected static function plugin_slug() {
		return dirname( LW16_PLUGIN_BASENAME );
	}

	/**
	 * Update-Eintrag des Plugins in die WordPress-Liste schreiben.
	 *
	 * @param mixed $transient Inhalt von update_plugins.
	 * @return mixed
	 */
	public function inject_plugin( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$remote = self::remote();
		$item   = (object) array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::plugin_slug(),
			'plugin'       => LW16_PLUGIN_BASENAME,
			'new_version'  => '' !== $remote['plugin'] ? $remote['plugin'] : LW16_VERSION,
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => self::plugin_package(),
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);

		if ( self::is_newer( LW16_VERSION, $remote['plugin'] ) ) {
			$transient->response[ LW16_PLUGIN_BASENAME ] = $item;
			unset( $transient->no_update[ LW16_PLUGIN_BASENAME ] );
		} else {
			// Auch ohne Update eintragen: dann bietet WordPress „Automatische Updates aktivieren“ an.
			$transient->no_update[ LW16_PLUGIN_BASENAME ] = $item;
			unset( $transient->response[ LW16_PLUGIN_BASENAME ] );
		}

		return $transient;
	}

	/**
	 * Update-Eintrag des Themes in die WordPress-Liste schreiben.
	 *
	 * @param mixed $transient Inhalt von update_themes.
	 * @return mixed
	 */
	public function inject_theme( $transient ) {
		if ( ! is_object( $transient ) || ! function_exists( 'wp_get_theme' ) ) {
			return $transient;
		}

		$theme = wp_get_theme( self::THEME );
		if ( ! $theme->exists() ) {
			return $transient;
		}

		$remote = self::remote();
		$item   = array(
			'theme'        => self::THEME,
			'new_version'  => '' !== $remote['theme'] ? $remote['theme'] : (string) $theme->get( 'Version' ),
			'url'          => 'https://github.com/' . self::REPO . '/tree/' . self::BRANCH . '/block-theme',
			'package'      => self::theme_package(),
			'requires_php' => '7.4',
		);

		if ( self::is_newer( (string) $theme->get( 'Version' ), $remote['theme'] ) ) {
			$transient->response[ self::THEME ] = $item;
			unset( $transient->no_update[ self::THEME ] );
		} else {
			$transient->no_update[ self::THEME ] = $item;
			unset( $transient->response[ self::THEME ] );
		}

		return $transient;
	}

	/**
	 * „Details ansehen“ im Plugin-Update: Version und Änderungen aus CHANGELOG.md.
	 *
	 * @param false|object|array $result Bisheriges Ergebnis.
	 * @param string             $action Art der Anfrage.
	 * @param object             $args   Argumente (slug).
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::plugin_slug() ) {
			return $result;
		}

		$remote = self::remote();

		return (object) array(
			'name'          => 'Lindenweg16 ERP',
			'slug'          => self::plugin_slug(),
			'version'       => '' !== $remote['plugin'] ? $remote['plugin'] : LW16_VERSION,
			'author'        => 'Lindenweg16',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires_php'  => '7.4',
			'download_link' => self::plugin_package(),
			'last_updated'  => gmdate( 'Y-m-d H:i:s', (int) $remote['checked'] ),
			'sections'      => array(
				'changelog' => self::changelog_html( self::fetch( self::raw_url( 'CHANGELOG.md' ) ) ),
			),
		);
	}

	/**
	 * Die neuesten Einträge aus CHANGELOG.md als einfaches HTML.
	 *
	 * @param string $markdown Inhalt von CHANGELOG.md.
	 * @param int    $versions Wie viele Versionen.
	 * @return string
	 */
	public static function changelog_html( $markdown, $versions = 3 ) {
		$markdown = str_replace( array( "\r\n", "\r" ), "\n", (string) $markdown );
		$start    = strpos( $markdown, "\n## " );
		if ( false === $start ) {
			return '<p>' . esc_html__( 'Die Änderungen stehen in CHANGELOG.md auf GitHub.', 'lindenweg16' ) . '</p>';
		}

		$out       = array();
		$in_list   = false;
		$seen      = 0;
		$was_blank = true;

		foreach ( explode( "\n", substr( $markdown, $start + 1 ) ) as $line ) {
			if ( 0 === strpos( $line, '## ' ) && ++$seen > $versions ) {
				break;
			}

			$blank     = $was_blank;
			$was_blank = '' === trim( $line );
			$is_item   = (bool) preg_match( '/^\s*[-*]\s+/', $line );
			if ( $in_list && ! $is_item && ! preg_match( '/^\s{2,}\S/', $line ) ) {
				$out[]   = '</ul>';
				$in_list = false;
			}

			$text = esc_html( trim( (string) preg_replace( '/^\s*(#{2,4}|[-*])\s+/', '', $line ) ) );
			$text = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
			$text = (string) preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );

			if ( 0 === strpos( $line, '## ' ) ) {
				$out[] = '<h3>' . $text . '</h3>';
			} elseif ( preg_match( '/^#{3,4} /', $line ) ) {
				$out[] = '<h4>' . $text . '</h4>';
			} elseif ( $is_item ) {
				if ( ! $in_list ) {
					$out[]   = '<ul>';
					$in_list = true;
				}
				$out[] = '<li>' . $text;
			} elseif ( '' !== $text ) {
				// Fortsetzungszeile eines Listenpunkts oder normaler Absatz.
				$last = count( $out ) - 1;
				if ( $in_list ) {
					$out[ $last ] .= ' ' . $text;
				} elseif ( ! $blank && $last >= 0 && '</p>' === substr( $out[ $last ], -4 ) ) {
					// Umbrochener Absatz: an den vorigen anhängen.
					$out[ $last ] = substr( $out[ $last ], 0, -4 ) . ' ' . $text . '</p>';
				} else {
					$out[] = '<p>' . $text . '</p>';
				}
			}
		}

		if ( $in_list ) {
			$out[] = '</ul>';
		}

		return implode( "\n", $out );
	}

	/**
	 * Nach dem Entpacken den Ordner so benennen wie den installierten – sonst
	 * legt WordPress ein zweites Plugin bzw. Theme an.
	 *
	 * @param string|\WP_Error $source        Entpackter Ordner.
	 * @param string           $remote_source Übergeordneter Ordner.
	 * @param object           $upgrader      Upgrader.
	 * @param array            $hook_extra    plugin / theme des Updates.
	 * @return string|\WP_Error
	 */
	public function fix_folder( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) ) {
			return $source;
		}

		$target = '';
		if ( ( $hook_extra['plugin'] ?? '' ) === LW16_PLUGIN_BASENAME ) {
			$target = self::plugin_slug();
		} elseif ( ( $hook_extra['theme'] ?? '' ) === self::THEME ) {
			$target = self::THEME;
		}

		$wanted = self::target_path( $source, $target );
		if ( '' === $wanted || ! $wp_filesystem ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $wanted ) ) {
			$wp_filesystem->delete( $wanted, true );
		}

		if ( ! $wp_filesystem->move( $source, $wanted, true ) ) {
			return new \WP_Error( 'lw16_update_folder', __( 'Der Update-Ordner konnte nicht umbenannt werden.', 'lindenweg16' ) );
		}

		return $wanted;
	}

	/**
	 * Neuer Pfad für den entpackten Ordner – '' wenn er schon richtig heißt.
	 *
	 * @param string $source Entpackter Ordner (mit oder ohne Schrägstrich am Ende).
	 * @param string $target Gewünschter Ordnername.
	 * @return string
	 */
	public static function target_path( $source, $target ) {
		$source = rtrim( (string) $source, '/\\' );

		if ( '' === $target || '.' === $target || basename( $source ) === $target ) {
			return '';
		}

		return dirname( $source ) . '/' . $target . '/';
	}

	/**
	 * Gelesene Versionen verwerfen (nach einem Update oder auf Wunsch).
	 */
	public function flush() {
		delete_site_transient( self::CACHE );
	}

	/**
	 * „Erneut prüfen“ unter Dashboard → Aktualisierungen.
	 */
	public function maybe_flush_on_force_check() {
		if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WordPress prüft selbst.
			$this->flush();
		}
	}

	/**
	 * Link „Jetzt nach Updates suchen“ in der Plugin-Liste.
	 *
	 * @param array  $meta Links unter der Beschreibung.
	 * @param string $file Plugin-Datei.
	 * @return array
	 */
	public function row_meta( $meta, $file ) {
		if ( LW16_PLUGIN_BASENAME !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $meta;
		}

		$url    = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_check_updates' ), 'lw16_check_updates' );
		$meta[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Jetzt nach Updates suchen', 'lindenweg16' ) . '</a>';

		return $meta;
	}

	/**
	 * Sofort bei GitHub nachsehen und die WordPress-Update-Listen neu aufbauen.
	 */
	public function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), 403 );
		}
		check_admin_referer( 'lw16_check_updates' );

		$this->flush();
		$remote = self::remote( true );

		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		wp_update_plugins();
		wp_update_themes();

		$found = (int) self::is_newer( LW16_VERSION, $remote['plugin'] );
		$theme = wp_get_theme( self::THEME );
		if ( $theme->exists() && self::is_newer( (string) $theme->get( 'Version' ), $remote['theme'] ) ) {
			$found += 2;
		}

		$state = ( '' === $remote['plugin'] && '' === $remote['theme'] ) ? 'error' : (string) $found;

		wp_safe_redirect( add_query_arg( 'lw16_updates', $state, admin_url( 'plugins.php' ) ) );
		exit;
	}

	/**
	 * Ergebnis von „Jetzt nach Updates suchen“ anzeigen.
	 */
	public function notice() {
		if ( ! isset( $_GET['lw16_updates'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$state = sanitize_key( wp_unslash( $_GET['lw16_updates'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'error' === $state ) {
			$class = 'notice-error';
			$text  = __( 'GitHub war nicht erreichbar. Bitte später noch einmal versuchen.', 'lindenweg16' );
		} elseif ( '0' === $state ) {
			$class = 'notice-success';
			$text  = __( 'Lindenweg16 ERP und Theme sind auf dem neuesten Stand.', 'lindenweg16' );
		} else {
			$parts = array();
			if ( (int) $state & 1 ) {
				$parts[] = __( 'Plugin Lindenweg16 ERP', 'lindenweg16' );
			}
			if ( (int) $state & 2 ) {
				$parts[] = __( 'Theme Lindenweg16', 'lindenweg16' );
			}
			$class = 'notice-warning';
			/* translators: %s: Plugin und/oder Theme. */
			$text = sprintf( __( 'Update verfügbar: %s. Unter Dashboard → Aktualisierungen oder direkt in der Liste auf „Jetzt aktualisieren“ klicken.', 'lindenweg16' ), implode( ', ', $parts ) );
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			esc_attr( $class ),
			esc_html( $text ),
			esc_url( admin_url( 'update-core.php' ) ),
			esc_html__( 'Zu den Aktualisierungen', 'lindenweg16' )
		);
	}
}
