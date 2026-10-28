<?php
/**
 * Modul: Marktkasse (POS) – Mobile Kassenfunktion für Märkte.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\POS;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Accounting\KassenbuchRepository;
use Lindenweg16\Modules\Inventory\Repository as LagerRepository;
use Lindenweg16\Modules\Inventory\StockManager;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Core\Lock;
use Lindenweg16\Core\Log;
use Lindenweg16\Modules\Betrieb\StockOwnership;
use Lindenweg16\Modules\Betrieb\StockRepository;

/**
 * Kassenfunktion für den Marktverkauf: Tagesbetrieb mit Anfangs-/Endbestand,
 * Verkäufe aus ERP-Produkten oder manueller Eingabe, Tagesabschluss mit
 * Buchhaltungs-Integration.
 *
 * Die Oberfläche liegt in der ERP-Anwendung (`Erp\Pages\Kasse`, assets/erp/pages/kasse.js);
 * hier steht nur die Fachlogik, damit es keine zweite Kasse im wp-admin gibt.
 */
class POSModule {

	/**
	 * Keine Hooks: Die Kasse hat keine wp-admin-Seite und keine
	 * admin-post-Handler mehr, sie läuft über die ERP-Schnittstelle.
	 */
	public function register() {
	}

	/* ------------------------------------------------------------------
	 * Fachlogik (von der ERP-Schnittstelle aufgerufen)
	 * ---------------------------------------------------------------- */

	/**
	 * Umsatz eines Tages ohne stornierte Positionen.
	 *
	 * @param array $positionen Zeilen aus POSRepository::get_positionen().
	 * @return float
	 */
	public static function sum_positions( array $positionen ) {
		$summe = 0.0;
		foreach ( $positionen as $pos ) {
			if ( ! $pos->storniert ) {
				$summe += (float) $pos->betrag;
			}
		}

		return $summe;
	}

	/**
	 * Neuen Kassatag starten.
	 *
	 * @param array $data markt_name, kampagne_id, datum, kassenbestand_anfang (roh).
	 * @return array {ok, message, id}
	 */
	public static function start_tag( array $data ) {
		if ( POSRepository::get_offener_tag() ) {
			return array( 'ok' => false, 'message' => __( 'Es gibt bereits einen offenen Kassatag. Bitte zuerst abschließen.', 'lindenweg16' ) );
		}

		$name = sanitize_text_field( (string) ( $data['markt_name'] ?? '' ) );
		if ( '' === $name ) {
			return array( 'ok' => false, 'message' => __( 'Bitte Markt oder Anlass angeben.', 'lindenweg16' ) );
		}

		$datum = sanitize_text_field( (string) ( $data['datum'] ?? '' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) ) {
			$datum = gmdate( 'Y-m-d' );
		}

		$id = POSRepository::create_tag( array(
			'markt_name'           => $name,
			'kampagne_id'          => (int) ( $data['kampagne_id'] ?? 0 ),
			'datum'                => $datum,
			'kassenbestand_anfang' => self::parse_amount( $data['kassenbestand_anfang'] ?? '' ),
		) );

		AuditLog::record( 'pos_tag_gestartet', 'pos_tag', $id );

		return array( 'ok' => true, 'message' => __( 'Kassatag gestartet.', 'lindenweg16' ), 'id' => (int) $id );
	}

	/**
	 * Verkauf auf einen offenen Tag buchen und Lager abbuchen.
	 *
	 * @param int   $tag_id Kassatag.
	 * @param array $data   produkt_id (Auswahlwert a:/p:), artikel_name, menge, einzelpreis (roh).
	 * @return array {ok, message}
	 */
	public static function book_sale( $tag_id, array $data ) {
		$tag_id = (int) $tag_id;
		$tag    = POSRepository::get_tag( $tag_id );
		if ( ! $tag || 'offen' !== $tag->status ) {
			return array( 'ok' => false, 'message' => __( 'Kein offener Kassatag.', 'lindenweg16' ) );
		}

		$auswahl     = self::parse_choice( is_string( $data['produkt_id'] ?? '' ) ? ( $data['produkt_id'] ?? '' ) : '' );
		$produkt_id  = $auswahl['produkt_id'];
		$article_id  = $auswahl['article_id'];
		$artikel     = sanitize_text_field( (string) ( $data['artikel_name'] ?? '' ) );
		$menge       = max( 1, (int) ( $data['menge'] ?? 1 ) );
		$einzelpreis = self::parse_amount( $data['einzelpreis'] ?? '' );

		if ( ! $artikel && $article_id ) {
			$a       = ArticleRepository::find( $article_id );
			$artikel = $a ? $a['name'] : __( 'Unbekannt', 'lindenweg16' );
		}
		if ( ! $artikel && $produkt_id ) {
			$p       = LagerRepository::get_product( $produkt_id );
			$artikel = $p ? $p->name : __( 'Unbekannt', 'lindenweg16' );
		}
		if ( ! $artikel ) {
			$artikel = __( 'Freier Betrag', 'lindenweg16' );
		}

		$betrag = $menge * $einzelpreis;

		POSRepository::create_position( array(
			'tag_id'       => $tag_id,
			'produkt_id'   => $produkt_id,
			'article_id'   => $article_id,
			'artikel_name' => $artikel,
			'menge'        => $menge,
			'einzelpreis'  => $einzelpreis,
			'betrag'       => $betrag,
		) );

		// Lagerbestand abbuchen. Die Weiche entscheidet, welche
		// Lagerverwaltung zustaendig ist - seit der Zusammenlegung ist das
		// bei bekannten Artikeln das Modul Betrieb. Ohne sie blieb der
		// Marktverkauf dort unsichtbar und die Bedarfsplanung rechnete mit
		// Ware, die laengst im Karton lag.
		if ( $produkt_id || $article_id ) {
			$referenz = sprintf( /* translators: %s: Marktname. */ __( 'Marktverkauf %s', 'lindenweg16' ), $tag->markt_name );

			$booked = StockOwnership::book(
				array( 'article_id' => $article_id, 'legacy_produkt_id' => $produkt_id ),
				-1 * abs( $menge ),
				'verkauf',
				array(
					'ref_type' => 'kasse',
					'ref_id'   => $tag_id,
					'note'     => $referenz,
				)
			);

			if ( 'betrieb' !== $booked && $produkt_id ) {
				StockManager::record_movement( $produkt_id, -1 * abs( $menge ), 'verkauf', array( 'referenz' => $referenz ) );
			}
		}

		Log::normal( 'kasse', sprintf( 'Kassenverkauf: %d × %s (%s €)', $menge, $artikel, number_format( $betrag, 2, ',', '.' ) ), array( 'tag_id' => $tag_id ) );

		return array( 'ok' => true, 'message' => __( 'Verkauf gebucht.', 'lindenweg16' ) );
	}

	/**
	 * Einen ganzen Bon buchen: mehrere Positionen auf einmal.
	 *
	 * Jede Zeile läuft durch book_sale(), also mit eigener Lagerbuchung. Vorab
	 * wird alles geprüft, damit kein halber Bon entsteht, nur weil eine Zeile
	 * unbrauchbar ist; ein Fehler beim Buchen selbst bricht ab und sagt, wie
	 * viele Zeilen schon durch sind.
	 *
	 * @param int   $tag_id Kassatag.
	 * @param array $zeilen Je Zeile produkt_id, artikel_name, menge, einzelpreis (roh).
	 * @return array {ok, message}
	 */
	public static function book_bon( $tag_id, array $zeilen ) {
		$tag = POSRepository::get_tag( (int) $tag_id );
		if ( ! $tag || 'offen' !== $tag->status ) {
			return array( 'ok' => false, 'message' => __( 'Kein offener Kassatag.', 'lindenweg16' ) );
		}

		$zeilen = array_values( array_filter( $zeilen, 'is_array' ) );

		if ( ! $zeilen || count( $zeilen ) > 100 ) {
			return array( 'ok' => false, 'message' => __( 'Der Bon ist leer oder zu lang.', 'lindenweg16' ) );
		}

		foreach ( $zeilen as $zeile ) {
			if ( self::parse_amount( $zeile['einzelpreis'] ?? '' ) <= 0 ) {
				return array( 'ok' => false, 'message' => __( 'Jede Zeile braucht einen Preis über 0 €.', 'lindenweg16' ) );
			}
		}

		$summe = 0.0;
		foreach ( $zeilen as $i => $zeile ) {
			$result = self::book_sale( $tag_id, $zeile );

			if ( ! $result['ok'] ) {
				return array(
					'ok'      => false,
					'message' => sprintf(
						/* translators: 1: Anzahl gebuchter Zeilen, 2: Fehlertext. */
						__( 'Nach %1$d Zeile(n) abgebrochen: %2$s', 'lindenweg16' ),
						$i,
						$result['message']
					),
				);
			}

			$summe += max( 1, (int) ( $zeile['menge'] ?? 1 ) ) * self::parse_amount( $zeile['einzelpreis'] ?? '' );
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %s: Betrag. */
				__( 'Kassiert: %s €', 'lindenweg16' ),
				number_format( $summe, 2, ',', '.' )
			),
		);
	}

	/**
	 * Verkauf stornieren und Lager zurückbuchen.
	 *
	 * @param int $id Position.
	 * @return array {ok, message}
	 */
	public static function storno_position( $id ) {
		$id = (int) $id;

		// Ein Doppelklick oder zwei Geräte zugleich dürfen nicht zweimal zurückbuchen.
		if ( ! Lock::acquire( 'pos_storno_' . $id, 5 ) ) {
			return array( 'ok' => false, 'message' => __( 'Der Storno läuft gerade.', 'lindenweg16' ) );
		}

		try {
			$pos = POSRepository::get_position( $id );
			if ( $pos && ! $pos->storniert ) {
				POSRepository::update_position( $id, array( 'storniert' => 1 ) );
				Log::attention( 'kasse', 'Kassenverkauf storniert: ' . (int) $pos->menge . ' × ' . $pos->artikel_name, array( 'position' => $id ) );

				// Lagerbestand zurückbuchen – über dieselbe Weiche wie der
				// Verkauf, sonst landet der Storno im anderen Lager als der
				// Verkauf und beide stehen dauerhaft falsch.
				if ( $pos->produkt_id || ! empty( $pos->article_id ) ) {
					$tag      = POSRepository::get_tag( $pos->tag_id );
					$referenz = sprintf( /* translators: %s: Marktname. */ __( 'Storno Marktverkauf %s', 'lindenweg16' ), $tag ? $tag->markt_name : '' );

					$booked = StockOwnership::book(
						array(
							'article_id'        => isset( $pos->article_id ) ? (int) $pos->article_id : 0,
							'legacy_produkt_id' => (int) $pos->produkt_id,
						),
						abs( (int) $pos->menge ),
						'retoure',
						array(
							'ref_type' => 'kasse',
							'ref_id'   => (int) $pos->tag_id,
							'note'     => $referenz,
						)
					);

					if ( 'betrieb' !== $booked && $pos->produkt_id ) {
						StockManager::record_movement( (int) $pos->produkt_id, abs( (int) $pos->menge ), 'retoure', array( 'referenz' => $referenz ) );
					}
				}
			}
		} finally {
			Lock::release( 'pos_storno_' . $id );
		}

		return array( 'ok' => true, 'message' => __( 'Verkauf storniert.', 'lindenweg16' ) );
	}

	/**
	 * Tag abschließen: Beleg und Kassenbucheintrag über den Tagesumsatz.
	 *
	 * @param int   $tag_id Kassatag.
	 * @param array $data   kassenbestand_ende (roh), notiz.
	 * @return array {ok, message}
	 */
	public static function close_tag( $tag_id, array $data ) {
		$tag_id = (int) $tag_id;

		// Zwei Geräte oder ein Doppelklick dürfen den Tag nicht zweimal buchen.
		if ( ! Lock::acquire( 'pos_abschluss_' . $tag_id, 10 ) ) {
			return array( 'ok' => false, 'message' => __( 'Der Abschluss läuft gerade.', 'lindenweg16' ) );
		}

		try {
			$tag = POSRepository::get_tag( $tag_id );
			if ( ! $tag || 'offen' !== $tag->status ) {
				return array( 'ok' => false, 'message' => __( 'Kein offener Kassatag.', 'lindenweg16' ) );
			}

			$kassenbestand_ende = self::parse_amount( $data['kassenbestand_ende'] ?? '' );
			$notiz              = sanitize_textarea_field( (string) ( $data['notiz'] ?? '' ) );
			$umsatz             = self::sum_positions( POSRepository::get_positionen( $tag_id ) );

			// Beleg in der Buchhaltung anlegen (Einnahme).
			$beleg_id = null;
			if ( $umsatz > 0 ) {
				$beleg_id = BelegRepository::create( array(
					'betrag'        => $umsatz,
					'datum'         => $tag->datum,
					'haendler'      => $tag->markt_name,
					'konto'         => \Lindenweg16\Modules\Accounting\Kontenrahmen::erloes_konto(
						1 === (int) \Lindenweg16\Core\Settings::get( 'kleinunternehmer', 1 )
					),
					'eur_kategorie' => 'Marktverkauf',
					'notiz'         => $notiz ?: sprintf( __( 'Tagesumsatz Markt: %s', 'lindenweg16' ), $tag->markt_name ),
					'quelle'        => 'marktkasse',
				) );
			}

			// Kassenbuch-Eintrag: Tagesumsatz als Bareinnahme buchen.
			$kassenbuch_id = null;
			if ( $umsatz > 0 ) {
				$kassenbuch_id = KassenbuchRepository::create( array(
					'datum'        => $tag->datum,
					'beschreibung' => sprintf( __( 'Marktkasse %s – Tagesumsatz', 'lindenweg16' ), $tag->markt_name ),
					'einnahme'     => $umsatz,
					'ausgabe'      => 0,
					'beleg_id'     => $beleg_id,
				) );
			}

			POSRepository::update_tag( $tag_id, array(
				'status'             => 'abgeschlossen',
				'kassenbestand_ende' => $kassenbestand_ende,
				'umsatz_gesamt'      => $umsatz,
				'notiz'              => $notiz,
				'beleg_id'           => $beleg_id,
				'abgeschlossen_am'   => current_time( 'mysql' ),
			) );

			AuditLog::record( 'pos_tag_abgeschlossen', 'pos_tag', $tag_id, array(
				'umsatz'        => $umsatz,
				'kasse_ende'    => $kassenbestand_ende,
				'beleg_id'      => $beleg_id,
				'kassenbuch_id' => $kassenbuch_id,
			) );
		} finally {
			Lock::release( 'pos_abschluss_' . $tag_id );
		}

		return array(
			'ok'      => true,
			'message' => sprintf( __( 'Kassatag abgeschlossen. Umsatz: %s € – Beleg #%d in Buchhaltung, Kassenbuch-Eintrag #%d angelegt.', 'lindenweg16' ), number_format( $umsatz, 2, ',', '.' ), $beleg_id ?: 0, $kassenbuch_id ?: 0 ),
		);
	}

	/**
	 * Foto aus der Kamera als Mediendatei speichern.
	 *
	 * Das Foto kommt als Data-URL, vom Browser schon verkleinert. Geprüft
	 * wird am Inhalt, nicht am Namen: nur JPEG, PNG und WebP, höchstens 3 MB.
	 *
	 * @param string $data_url Data-URL.
	 * @param string $title    Titel der Mediendatei.
	 * @return int|string Mediendatei-ID oder Fehlertext.
	 */
	public static function save_photo( $data_url, $title ) {
		if ( ! preg_match( '#^data:image/(?:jpeg|png|webp);base64,([A-Za-z0-9+/=\r\n]+)$#', (string) $data_url, $m ) ) {
			return __( 'Das Foto ist kein gültiges Bild.', 'lindenweg16' );
		}

		$bytes = base64_decode( $m[1], true );

		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > 3 * 1024 * 1024 ) {
			return __( 'Das Foto ist leer oder zu groß (höchstens 3 MB).', 'lindenweg16' );
		}

		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ext  = array(
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_PNG  => 'png',
			IMAGETYPE_WEBP => 'webp',
		);

		if ( ! $info || ! isset( $ext[ $info[2] ] ) ) {
			return __( 'Das Foto ist kein gültiges Bild.', 'lindenweg16' );
		}

		$upload = wp_upload_bits( 'kasse-' . gmdate( 'Ymd-His' ) . '-' . wp_rand( 100, 999 ) . '.' . $ext[ $info[2] ], null, $bytes );

		if ( ! empty( $upload['error'] ) ) {
			return __( 'Das Foto konnte nicht gespeichert werden.', 'lindenweg16' );
		}

		$attachment = wp_insert_attachment(
			array(
				'post_mime_type' => $info['mime'],
				'post_title'     => sanitize_text_field( $title ),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( ! $attachment || is_wp_error( $attachment ) ) {
			return __( 'Das Foto konnte nicht gespeichert werden.', 'lindenweg16' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $upload['file'] ) );

		return (int) $attachment;
	}

	/**
	 * Produkt nur für die Kasse anlegen: Name, Preis, Foto, Anfangsbestand.
	 *
	 * @param array $data name, preis (roh), bestand (roh, optional), foto (Data-URL, optional).
	 * @return array {ok, message, value} – value ist der Auswahlwert der neuen Kachel.
	 */
	public static function create_product( array $data ) {
		$name  = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$preis = self::parse_amount( $data['preis'] ?? '' );

		if ( '' === $name ) {
			return array( 'ok' => false, 'message' => __( 'Bitte einen Namen eintragen.', 'lindenweg16' ) );
		}
		if ( $preis <= 0 ) {
			return array( 'ok' => false, 'message' => __( 'Bitte einen Preis eintragen.', 'lindenweg16' ) );
		}

		// Erst das Foto: schlägt es fehl, ist noch nichts angelegt.
		$image_id = 0;
		if ( ! empty( $data['foto'] ) ) {
			$saved = self::save_photo( (string) $data['foto'], $name );

			if ( is_string( $saved ) ) {
				return array( 'ok' => false, 'message' => $saved );
			}
			$image_id = $saved;
		}

		$result = ArticleRepository::create_pos_article( $name, $preis, $image_id );

		if ( ! $result['success'] ) {
			if ( $image_id ) {
				wp_delete_attachment( $image_id, true );
			}

			return array( 'ok' => false, 'message' => $result['message'] );
		}

		$bestand = (int) round( self::parse_amount( $data['bestand'] ?? '' ) );
		if ( $bestand > 0 ) {
			StockRepository::book(
				array(
					'article_id'    => $result['id'],
					'qty'           => $bestand,
					'movement_type' => 'korrektur',
					'location'      => 'lager',
					'note'          => __( 'Anfangsbestand beim Anlegen in der Kasse', 'lindenweg16' ),
				)
			);
		}

		Log::normal( 'kasse', sprintf( 'Kassenprodukt angelegt: %s (%s €)', $name, number_format( $preis, 2, ',', '.' ) ), array( 'article_id' => $result['id'] ) );

		return array( 'ok' => true, 'message' => __( 'Produkt angelegt.', 'lindenweg16' ), 'value' => 'a:' . $result['id'] );
	}

	/**
	 * Kassenprodukt ändern: Name, Preis, neues Foto oder ausblenden.
	 *
	 * Nur Produkte mit pos_only; normale Artikel pflegt man im Artikelstamm.
	 * Ausgeblendet heißt „ausgelaufen“: Es verschwindet aus den Kacheln,
	 * Bestand und Buchungen bleiben lesbar.
	 *
	 * @param int   $id   Artikel.
	 * @param array $data name, preis (roh), foto (Data-URL, optional), ausblenden (bool).
	 * @return array {ok, message}
	 */
	public static function update_product( $id, array $data ) {
		$id      = (int) $id;
		$article = ArticleRepository::find( $id );

		if ( ! $article || empty( $article['pos_only'] ) ) {
			return array( 'ok' => false, 'message' => __( 'Das ist kein Kassenprodukt.', 'lindenweg16' ) );
		}

		if ( ! empty( $data['ausblenden'] ) ) {
			ArticleRepository::set_status( $id, 'ausgelaufen' );
			Log::normal( 'kasse', 'Kassenprodukt ausgeblendet: ' . $article['name'], array( 'article_id' => $id ) );

			return array( 'ok' => true, 'message' => __( 'Produkt ausgeblendet.', 'lindenweg16' ) );
		}

		$name  = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$preis = self::parse_amount( $data['preis'] ?? '' );

		if ( '' === $name ) {
			return array( 'ok' => false, 'message' => __( 'Bitte einen Namen eintragen.', 'lindenweg16' ) );
		}
		if ( $preis <= 0 ) {
			return array( 'ok' => false, 'message' => __( 'Bitte einen Preis eintragen.', 'lindenweg16' ) );
		}

		$fields = array(
			'name'        => $name,
			'price_gross' => $preis,
		);

		$old_image = (int) ( $article['image_id'] ?? 0 );

		if ( ! empty( $data['foto'] ) ) {
			$saved = self::save_photo( (string) $data['foto'], $name );

			if ( is_string( $saved ) ) {
				return array( 'ok' => false, 'message' => $saved );
			}
			$fields['image_id'] = $saved;
		}

		if ( ! ArticleRepository::update( $id, $fields, __( 'Änderung in der Kasse', 'lindenweg16' ) ) ) {
			if ( isset( $fields['image_id'] ) ) {
				wp_delete_attachment( $fields['image_id'], true );
			}

			return array( 'ok' => false, 'message' => __( 'Das Produkt konnte nicht gespeichert werden.', 'lindenweg16' ) );
		}

		// Das alte Foto gehörte nur diesem Produkt.
		if ( isset( $fields['image_id'] ) && $old_image ) {
			wp_delete_attachment( $old_image, true );
		}

		Log::normal( 'kasse', 'Kassenprodukt geändert: ' . $name, array( 'article_id' => $id ) );

		return array( 'ok' => true, 'message' => __( 'Produkt gespeichert.', 'lindenweg16' ), 'value' => 'a:' . $id );
	}

	/**
	 * Offenen Kassatag abbrechen – nur für Tests.
	 *
	 * Alle Verkäufe des Tages werden storniert (Lager zurückgebucht), danach
	 * steht der Tag auf „abgebrochen“. Es entsteht kein Beleg und kein
	 * Kassenbucheintrag; der Tag bleibt mit seinen Positionen sichtbar im
	 * Protokoll, gelöscht wird nichts. Abgeschlossene Tage lassen sich nicht
	 * abbrechen – sie sind gebucht.
	 *
	 * @param int $tag_id Kassatag.
	 * @return array {ok, message}
	 */
	public static function cancel_tag( $tag_id ) {
		$tag_id = (int) $tag_id;

		if ( ! Lock::acquire( 'pos_abschluss_' . $tag_id, 10 ) ) {
			return array( 'ok' => false, 'message' => __( 'Der Tag wird gerade bearbeitet.', 'lindenweg16' ) );
		}

		try {
			$tag = POSRepository::get_tag( $tag_id );
			if ( ! $tag || 'offen' !== $tag->status ) {
				return array( 'ok' => false, 'message' => __( 'Nur ein offener Kassatag lässt sich abbrechen.', 'lindenweg16' ) );
			}

			$zurueck = 0;
			foreach ( POSRepository::get_positionen( $tag_id ) as $pos ) {
				if ( ! $pos->storniert ) {
					$result = self::storno_position( (int) $pos->id );

					if ( ! $result['ok'] ) {
						return array( 'ok' => false, 'message' => $result['message'] );
					}
					++$zurueck;
				}
			}

			POSRepository::update_tag( $tag_id, array(
				'status'           => 'abgebrochen',
				'umsatz_gesamt'    => 0,
				'notiz'            => trim( (string) $tag->notiz . ' ' . __( '[Tag abgebrochen]', 'lindenweg16' ) ),
				'abgeschlossen_am' => current_time( 'mysql' ),
			) );

			AuditLog::record( 'pos_tag_abgebrochen', 'pos_tag', $tag_id, array( 'positionen_storniert' => $zurueck ) );
			Log::attention( 'kasse', 'Kassatag abgebrochen: ' . $tag->markt_name, array( 'tag_id' => $tag_id, 'positionen' => $zurueck ) );
		} finally {
			Lock::release( 'pos_abschluss_' . $tag_id );
		}

		return array(
			'ok'      => true,
			/* translators: %d: Anzahl stornierter Positionen. */
			'message' => sprintf( __( 'Kassatag abgebrochen. %d Verkauf/Verkäufe storniert und der Bestand zurückgebucht.', 'lindenweg16' ), $zurueck ),
		);
	}

	/* ------------------------------------------------------------------
	 * Auswahl und Helfer
	 * ---------------------------------------------------------------- */

	/**
	 * Verkäufliche Positionen für die Kassenauswahl.
	 *
	 * Nur Kassenprodukte (pos_only): Was in der Kasse steht, hat man dort
	 * selbst angelegt. Shop-Artikel und Altprodukte der abgelösten
	 * Verwaltung erscheinen nicht – sie kämen aus WooCommerce und nicht aus
	 * dem ERP. Frühere Verkäufe darauf bleiben unberührt.
	 *
	 * @return array Einträge mit value, label, name, preis, bild, id und pos.
	 */
	public static function sale_options() {
		$options = array();

		foreach ( ArticleRepository::selectable( true ) as $article ) {
			if ( empty( $article['pos_only'] ) ) {
				continue;
			}

			$preis = null === $article['price_gross'] ? '' : number_format( (float) $article['price_gross'], 2, '.', '' );

			$options[] = array(
				'value' => 'a:' . (int) $article['id'],
				'label' => $article['sku'] . ' – ' . $article['name'],
				'name'  => (string) $article['name'],
				'sku'   => (string) $article['sku'],
				'preis' => $preis,
				'bild'  => self::article_image_url( $article, 0 ),
				'id'    => (int) $article['id'],
				'pos'   => true,
			);
		}

		return $options;
	}

	/**
	 * Bild eines Artikels für die Kachel: erst das eigene Foto, dann das des Shop-Produkts.
	 *
	 * @param array $article Artikelzeile.
	 * @param int   $wc_id   Verknüpftes Shop-Produkt oder 0.
	 * @return string Bild-URL oder leer.
	 */
	protected static function article_image_url( array $article, $wc_id ) {
		$image_id = isset( $article['image_id'] ) ? (int) $article['image_id'] : 0;

		if ( $image_id && function_exists( 'wp_get_attachment_image_url' ) ) {
			$url = (string) wp_get_attachment_image_url( $image_id, 'thumbnail' );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return self::product_image_url( $wc_id );
	}

	/**
	 * Bild eines Shop-Produkts für die Kachel.
	 *
	 * Der Artikelstamm führt selbst kein Bild – das Produktfoto liegt im
	 * Shop. Fehlt die Verknüpfung oder das Beitragsbild, gibt es eben
	 * keins; die Kachel zeigt dann die Initialen.
	 *
	 * @param int $wc_product_id WooCommerce-Produkt.
	 * @return string Bild-URL oder leer.
	 */
	protected static function product_image_url( $wc_product_id ) {
		$wc_product_id = (int) $wc_product_id;

		if ( ! $wc_product_id || ! function_exists( 'get_post_thumbnail_id' ) ) {
			return '';
		}

		$thumb_id = (int) get_post_thumbnail_id( $wc_product_id );

		if ( ! $thumb_id ) {
			return '';
		}

		return (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' );
	}

	/**
	 * Verkaufspreis eines Shop-Produkts.
	 *
	 * @param int $wc_product_id WooCommerce-Produkt.
	 * @return string Preis mit Punkt als Dezimaltrenner oder leer.
	 */
	protected static function product_price( $wc_product_id ) {
		$wc_product_id = (int) $wc_product_id;

		if ( ! $wc_product_id || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $wc_product_id );

		if ( ! $product ) {
			return '';
		}

		$preis = $product->get_price();

		return '' === $preis || null === $preis ? '' : number_format( (float) $preis, 2, '.', '' );
	}

	/**
	 * Kürzel für eine Kachel ohne Produktbild.
	 *
	 * Reine Zeichenkettenlogik: die Anfangsbuchstaben der ersten beiden
	 * Wörter. Ohne sie wäre eine bildlose Kachel eine leere Fläche und am
	 * Marktstand nicht vom Nachbarn zu unterscheiden.
	 *
	 * @param string $name Artikelbezeichnung.
	 * @return string Ein bis zwei Großbuchstaben.
	 */
	public static function initials( $name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );

		if ( '' === $name ) {
			return '?';
		}

		$woerter  = explode( ' ', $name );
		$kuerzel  = '';

		foreach ( $woerter as $wort ) {
			if ( '' === $wort ) {
				continue;
			}

			$kuerzel .= function_exists( 'mb_substr' ) ? mb_substr( $wort, 0, 1 ) : substr( $wort, 0, 1 );

			if ( strlen( $kuerzel ) >= 2 ) {
				break;
			}
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $kuerzel ) : strtoupper( $kuerzel );
	}

	/**
	 * Suchtext einer Kachel – reine Zeichenkettenlogik.
	 *
	 * Der Filter im Browser vergleicht denselben normalisierten Text.
	 * Weicht die Aufbereitung hier davon ab, findet die Suche Kacheln
	 * nicht, die sichtbar passen.
	 *
	 * @param string $text Bezeichnung und Artikelnummer.
	 * @return string
	 */
	public static function search_key( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/**
	 * Handgetippten Geldbetrag in eine Zahl verwandeln.
	 *
	 * Reine Zeichenkettenlogik. Am Marktstand wird „12,50" getippt, nicht
	 * „12.50"; ein nacktes (float) daraus wäre 12,00 € – der Fehler fällt
	 * erst beim Kassensturz auf. Erkannt werden deshalb beide
	 * Dezimaltrenner samt Tausenderpunkt, Währungszeichen und Leerraum.
	 *
	 * @param mixed $raw Eingabe.
	 * @return float Betrag; nie negativ.
	 */
	public static function parse_amount( $raw ) {
		if ( is_float( $raw ) || is_int( $raw ) ) {
			return max( 0.0, (float) $raw );
		}

		// Ein Array käme nur aus einem manipulierten Formular; (string)
		// darauf wäre in PHP 8 ein Fehler statt einer Buchung.
		if ( ! is_string( $raw ) ) {
			return 0.0;
		}

		$text = trim( (string) $raw );

		if ( '' === $text ) {
			return 0.0;
		}

		// Alles, was kein Zifferntrenner ist, fliegt raus – Euro-Zeichen,
		// Leerzeichen, Buchstaben.
		$text = preg_replace( '/[^0-9,.\-]/', '', $text );

		if ( '' === $text || '-' === $text ) {
			return 0.0;
		}

		$letztes_komma = strrpos( $text, ',' );
		$letzter_punkt = strrpos( $text, '.' );
		$trenner       = max( false === $letztes_komma ? -1 : $letztes_komma, false === $letzter_punkt ? -1 : $letzter_punkt );

		if ( $trenner < 0 ) {
			return max( 0.0, (float) $text );
		}

		// Was hinter dem letzten Trenner steht, sind die Nachkommastellen –
		// aber nur, wenn es höchstens zwei Ziffern sind. „1.250" ist ein
		// Tausenderpunkt, kein Viertelcent.
		$nachkomma = substr( $text, $trenner + 1 );
		$vorkomma  = substr( $text, 0, $trenner );

		if ( strlen( $nachkomma ) > 2 || '' === $nachkomma ) {
			$zahl = preg_replace( '/[,.]/', '', $text );

			return max( 0.0, (float) $zahl );
		}

		$vorkomma = preg_replace( '/[,.]/', '', $vorkomma );

		return max( 0.0, (float) ( $vorkomma . '.' . $nachkomma ) );
	}

	/**
	 * Auswahlwert der Kasse zerlegen – reine Zeichenkettenlogik.
	 *
	 * Alte Kassentage schickten die nackte Produkt-ID. Die wird weiterhin
	 * verstanden, damit ein offener Tab von vor dem Update nichts kaputt
	 * macht.
	 *
	 * @param string $raw Wert aus dem Formular.
	 * @return array {article_id, produkt_id} – jeweils null, wenn nicht gesetzt.
	 */
	public static function parse_choice( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return array( 'article_id' => null, 'produkt_id' => null );
		}

		if ( 0 === strpos( $raw, 'a:' ) ) {
			$id = (int) substr( $raw, 2 );
			return array( 'article_id' => $id ? $id : null, 'produkt_id' => null );
		}

		if ( 0 === strpos( $raw, 'p:' ) ) {
			$id = (int) substr( $raw, 2 );
			return array( 'article_id' => null, 'produkt_id' => $id ? $id : null );
		}

		$id = (int) $raw;

		return array( 'article_id' => null, 'produkt_id' => $id ? $id : null );
	}
}
