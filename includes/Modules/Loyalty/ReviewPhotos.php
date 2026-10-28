<?php
/**
 * Fotos in Produktbewertungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Angemeldete Kunden können ihrer Bewertung Fotos anhängen. Die Fotos
 * landen in der Mediathek (am Produkt), erscheinen unter dem Bewertungstext
 * und verschwinden mit der Bewertung. Für Fotos gibt es Zusatzpunkte
 * (LoyaltyTriggers::award_review).
 */
class ReviewPhotos {

	/** Kommentar-Meta mit den Anhang-IDs. */
	const META = 'lw16_fotos';

	/** Feldname im Formular. */
	const FIELD = 'lw16_review_fotos';

	/** Größte Datei in Byte. */
	const MAX_BYTES = 8388608;

	/** Erlaubte Bildtypen. */
	const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'woocommerce_product_review_comment_form_args', array( $this, 'form_field' ) );
		add_action( 'wp_footer', array( $this, 'form_enctype' ) );
		add_filter( 'preprocess_comment', array( $this, 'validate' ) );
		add_action( 'comment_post', array( $this, 'save' ), 5, 1 );
		add_filter( 'comment_text', array( $this, 'show' ), 20, 2 );
		add_action( 'delete_comment', array( $this, 'cleanup' ), 10, 1 );
	}

	/**
	 * Wie viele Fotos sind erlaubt? 0 = Hochladen aus.
	 *
	 * @return int
	 */
	protected static function max_photos() {
		return max( 0, min( 10, (int) Settings::get( 'review_foto_max', 3 ) ) );
	}

	/**
	 * Upload-Feld unter dem Bewertungstext (nur angemeldet).
	 *
	 * @param array $args Formular-Argumente von WooCommerce.
	 * @return array
	 */
	public function form_field( $args ) {
		$max = self::max_photos();
		if ( ! $max || ! is_user_logged_in() ) {
			return $args;
		}
		$bonus = (int) Settings::get( 'punkte_bewertung_foto', 0 );
		$hint  = $bonus > 0
			/* translators: 1: Anzahl Fotos, 2: Punkte */
			? sprintf( __( 'Bis zu %1$d Fotos (JPG, PNG, WebP, je max. 8 MB) – dafür gibt es %2$d Extrapunkte.', 'lindenweg16' ), $max, $bonus )
			/* translators: %d: Anzahl Fotos */
			: sprintf( __( 'Bis zu %d Fotos (JPG, PNG, WebP, je max. 8 MB).', 'lindenweg16' ), $max );

		$args['comment_field'] = ( isset( $args['comment_field'] ) ? $args['comment_field'] : '' )
			. '<p class="comment-form-lw16-fotos"><label for="' . esc_attr( self::FIELD ) . '">' . esc_html__( 'Fotos hinzufügen (freiwillig)', 'lindenweg16' ) . '</label>'
			. '<input type="file" id="' . esc_attr( self::FIELD ) . '" name="' . esc_attr( self::FIELD ) . '[]" accept="image/jpeg,image/png,image/webp" multiple data-max="' . (int) $max . '">'
			. '<small>' . esc_html( $hint ) . '</small>'
			. wp_nonce_field( 'lw16_review_fotos', 'lw16_review_fotos_nonce', true, false )
			. '</p>';
		return $args;
	}

	/**
	 * Das Kommentarformular kennt kein enctype – ohne multipart kommen keine
	 * Dateien an. Nur auf Produktseiten mit Upload-Feld.
	 */
	public function form_enctype() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! self::max_photos() || ! is_user_logged_in() ) {
			return;
		}
		echo "<script>(function(){var f=document.getElementById('commentform');if(f&&f.querySelector('input[name=\"" . esc_js( self::FIELD ) . "[]\"]')){f.enctype='multipart/form-data';f.encoding='multipart/form-data';}})();</script>\n";
	}

	/**
	 * Hochgeladene Dateien in eine Liste je Datei umbauen.
	 *
	 * @param array $files Eintrag aus $_FILES (Mehrfachfeld).
	 * @return array Liste mit name, type, tmp_name, error, size.
	 */
	public static function normalize( $files ) {
		$out = array();
		if ( ! is_array( $files ) || ! isset( $files['name'] ) ) {
			return $out;
		}
		$names = (array) $files['name'];
		foreach ( $names as $i => $name ) {
			$error = (int) ( ( (array) $files['error'] )[ $i ] ?? UPLOAD_ERR_NO_FILE );
			if ( UPLOAD_ERR_NO_FILE === $error || '' === (string) $name ) {
				continue;
			}
			$out[] = array(
				'name'     => (string) $name,
				'type'     => (string) ( ( (array) $files['type'] )[ $i ] ?? '' ),
				'tmp_name' => (string) ( ( (array) $files['tmp_name'] )[ $i ] ?? '' ),
				'error'    => $error,
				'size'     => (int) ( ( (array) $files['size'] )[ $i ] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Was spricht gegen die Dateien? Leer = alles gut.
	 *
	 * @param array $files     Normalisierte Liste.
	 * @param int   $max       Höchstzahl.
	 * @param int   $max_bytes Größte Datei.
	 * @return string Fehlermeldung oder ''.
	 */
	public static function problem( array $files, $max, $max_bytes ) {
		if ( count( $files ) > $max ) {
			/* translators: %d: Anzahl */
			return sprintf( _n( 'Bitte höchstens %d Foto anhängen.', 'Bitte höchstens %d Fotos anhängen.', $max, 'lindenweg16' ), $max );
		}
		foreach ( $files as $f ) {
			if ( UPLOAD_ERR_INI_SIZE === $f['error'] || UPLOAD_ERR_FORM_SIZE === $f['error'] || $f['size'] > $max_bytes ) {
				/* translators: %s: Dateiname */
				return sprintf( __( '„%s“ ist zu groß – höchstens 8 MB je Foto.', 'lindenweg16' ), $f['name'] );
			}
			if ( UPLOAD_ERR_OK !== $f['error'] ) {
				/* translators: %s: Dateiname */
				return sprintf( __( '„%s“ kam nicht vollständig an. Bitte noch einmal versuchen.', 'lindenweg16' ), $f['name'] );
			}
			if ( ! preg_match( '/\.(jpe?g|jpe|png|webp)$/i', $f['name'] ) ) {
				/* translators: %s: Dateiname */
				return sprintf( __( '„%s“ ist kein Foto im Format JPG, PNG oder WebP.', 'lindenweg16' ), $f['name'] );
			}
		}
		return '';
	}

	/**
	 * Vor dem Speichern prüfen – ein Fehler bricht ab, bevor die Bewertung
	 * ohne die gewünschten Fotos online geht.
	 *
	 * @param array $data Kommentardaten.
	 * @return array
	 */
	public function validate( $data ) {
		if ( empty( $_FILES[ self::FIELD ] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $data;
		}
		$files = self::normalize( $_FILES[ self::FIELD ] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( ! $files ) {
			return $data;
		}
		if ( ! isset( $_POST['lw16_review_fotos_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lw16_review_fotos_nonce'] ) ), 'lw16_review_fotos' ) ) {
			wp_die( esc_html__( 'Die Seite war zu lange offen. Bitte lade sie neu und schick die Bewertung noch einmal.', 'lindenweg16' ), esc_html__( 'Fotos nicht angenommen', 'lindenweg16' ), array( 'response' => 403, 'back_link' => true ) );
		}
		if ( 'product' !== get_post_type( (int) ( $data['comment_post_ID'] ?? 0 ) ) ) {
			return $data;
		}
		$problem = self::problem( $files, self::max_photos(), self::MAX_BYTES );
		if ( $problem ) {
			wp_die( esc_html( $problem ), esc_html__( 'Fotos nicht angenommen', 'lindenweg16' ), array( 'response' => 400, 'back_link' => true ) );
		}
		foreach ( $files as $f ) {
			$check = wp_check_filetype_and_ext( $f['tmp_name'], $f['name'], self::MIMES );
			if ( empty( $check['type'] ) || ! in_array( $check['type'], self::MIMES, true ) || ! @getimagesize( $f['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				/* translators: %s: Dateiname */
				wp_die( esc_html( sprintf( __( '„%s“ ist kein Foto im Format JPG, PNG oder WebP.', 'lindenweg16' ), $f['name'] ) ), esc_html__( 'Fotos nicht angenommen', 'lindenweg16' ), array( 'response' => 400, 'back_link' => true ) );
			}
		}
		return $data;
	}

	/**
	 * Fotos nach dem Speichern der Bewertung in die Mediathek übernehmen.
	 *
	 * @param int $comment_id Kommentar.
	 */
	public function save( $comment_id ) {
		if ( empty( $_FILES[ self::FIELD ] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment || 'product' !== get_post_type( (int) $comment->comment_post_ID ) || (int) $comment->user_id !== get_current_user_id() ) {
			return;
		}
		// validate() hat Nonce, Anzahl, Größe und Typ schon geprüft.
		$files = array_slice( self::normalize( $_FILES[ self::FIELD ] ), 0, self::max_photos() ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( ! $files ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ids = array();
		foreach ( $files as $f ) {
			$id = media_handle_sideload(
				$f,
				(int) $comment->comment_post_ID,
				/* translators: %s: Produktname */
				sprintf( __( 'Kundenfoto: %s', 'lindenweg16' ), get_the_title( (int) $comment->comment_post_ID ) ),
				array( 'post_author' => (int) $comment->user_id )
			);
			if ( ! is_wp_error( $id ) ) {
				update_post_meta( $id, '_lw16_review_comment', (int) $comment_id );
				$ids[] = (int) $id;
			}
		}
		if ( $ids ) {
			update_comment_meta( $comment_id, self::META, $ids );
		}
	}

	/**
	 * Fotos unter dem Bewertungstext zeigen.
	 *
	 * @param string           $text    Text.
	 * @param \WP_Comment|null $comment Kommentar.
	 * @return string
	 */
	public function show( $text, $comment = null ) {
		if ( ! $comment instanceof \WP_Comment ) {
			return $text;
		}
		$ids = array_filter( array_map( 'intval', (array) get_comment_meta( $comment->comment_ID, self::META, true ) ) );
		if ( ! $ids ) {
			return $text;
		}
		$html = '<div class="lw16-review-fotos">';
		foreach ( $ids as $id ) {
			$full  = wp_get_attachment_image_url( $id, 'large' );
			$thumb = wp_get_attachment_image( $id, 'thumbnail', false, array( 'loading' => 'lazy', 'alt' => esc_attr__( 'Kundenfoto zur Bewertung', 'lindenweg16' ) ) );
			if ( $full && $thumb ) {
				$html .= '<a href="' . esc_url( $full ) . '" target="_blank" rel="noopener">' . $thumb . '</a>';
			}
		}
		return $text . $html . '</div>';
	}

	/**
	 * Mit der Bewertung verschwinden auch ihre Fotos.
	 *
	 * @param int $comment_id Kommentar.
	 */
	public function cleanup( $comment_id ) {
		foreach ( array_filter( array_map( 'intval', (array) get_comment_meta( $comment_id, self::META, true ) ) ) as $id ) {
			if ( (int) get_post_meta( $id, '_lw16_review_comment', true ) === (int) $comment_id ) {
				wp_delete_attachment( $id, true );
			}
		}
	}
}
