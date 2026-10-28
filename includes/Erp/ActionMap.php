<?php
/**
 * Welche feinen Rechte ein Formular- oder Ajax-Vorgang verlangt.
 *
 * Die Seiten des Plugins prüfen beim Speichern nur die sechs groben
 * Betrieb-Rechte (oder manage_lw16). Dadurch reichte „Stammdaten ändern“,
 * um Artikel, Drucker, Material und Kontakte zu ändern – und wer „Artikel“
 * sehen durfte, konnte per selbst gebauter Anfrage auch speichern.
 *
 * Diese Tabelle ordnet jedem Vorgang (`admin-post.php?action=…`, Ajax) die
 * feinen Rechte zu, die gleichzeitig vorhanden sein müssen. Sie schränkt nur
 * ein: Was nicht verzeichnet ist, prüft weiter das jeweilige Modul selbst.
 * Wer Vollzugriff hat, ist ausgenommen.
 *
 * Frei von Datenbank und WordPress; ein Test stellt sicher, dass jeder im
 * Quelltext fest benannte Vorgang entweder hier steht oder bewusst
 * ausgenommen ist – ein neuer Vorgang bleibt so nicht unbemerkt ungeprüft.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Zuordnung Vorgang → feine Rechte.
 */
class ActionMap {

	/**
	 * Vorgang => Rechte, die alle vorhanden sein müssen.
	 *
	 * @return array
	 */
	public static function rules() {
		$artikel   = array( 'fertigung.artikel', 'fertigung.stammdaten.edit' );
		$drucker   = array( 'fertigung.drucker', 'fertigung.stammdaten.edit' );
		$bambu     = array( 'fertigung.bambu', 'fertigung.stammdaten.edit' );
		$lagerplatz = array( 'lager.lagerplaetze' );
		$werk      = array( 'lager.lagerplaetze', 'fertigung.auftraege.start' );
		$auftrag   = array( 'verkauf.orders', 'verkauf.orders.status' );
		$export    = array( 'finanzen.berichte', 'finanzen.export' );
		$konten    = array( 'finanzen.buchhaltung' );
		$konten_ex = array( 'finanzen.buchhaltung', 'finanzen.export' );

		$rules = array(
			// Artikel & Revisionen.
			'lw16_betrieb_model_get'      => array( 'fertigung.artikel' ),
			'lw16_betrieb_article_state'  => $artikel,
			'lw16_betrieb_price_adopt'    => $artikel,
			'lw16_betrieb_price_push_all' => $artikel,
			'lw16_betrieb_purchase'       => $artikel,
			'lw16_betrieb_purchase_state' => $artikel,
			'lw16_betrieb_gpsr'           => array( 'fertigung.artikel' ),

			// Drucker, Bambu, Slicing.
			'lw16_betrieb_save_printer'   => $drucker,
			'lw16_betrieb_flush_routes'   => $drucker,
			'lw16_betrieb_maintenance'    => array( 'fertigung.drucker' ),
			'lw16_bambu_add_printer'      => $bambu,
			'lw16_bambu_delete_printer'   => $bambu,
			'lw16_bambu_save_printer'     => $bambu,
			'lw16_bambu_regen_token'      => $bambu,
			'lw16_bambu_enqueue_job'      => array( 'fertigung.bambu' ),
			'lw16_bambu_enqueue_model'    => array( 'fertigung.bambu' ),
			'lw16_bambu_cards'            => array( 'fertigung.bambu' ),
			'lw16_slicing_delete_profile' => $bambu,
			'lw16_slicing_regen_token'    => $bambu,
			'lw16_slicing_rename_filament' => $bambu,
			'lw16_slicing_save_profile'   => $bambu,

			// Konfigurator.
			'lw16_haken_add_block'        => array( 'fertigung.konfigurator', 'fertigung.stammdaten.edit' ),
			'lw16_haken_delete_block'     => array( 'fertigung.konfigurator', 'fertigung.stammdaten.edit' ),
			'lw16_haken_delete_part'      => array( 'fertigung.konfigurator', 'fertigung.stammdaten.edit' ),
			'lw16_haken_save_part'        => array( 'fertigung.konfigurator', 'fertigung.stammdaten.edit' ),

			// Workflow: Plattendatei herunterladen.
			'lw16_erp_run_file'           => array( 'fertigung.workflow' ),
			'lw16_erp_payslip_pdf'        => array( 'verwaltung.loehne' ),
			'lw16_erp_payslip_csv'        => array( 'verwaltung.loehne' ),

			// Druckjournal, Produktion.
			'lw16_betrieb_save_printjob'  => array( 'fertigung.druckjournal' ),
			'lw16_betrieb_void_printjob'  => array( 'fertigung.druckjournal' ),
			'lw16_job_complete'           => array( 'fertigung.produktion' ),
			'lw16_job_status'             => array( 'fertigung.produktion' ),
			'lw16_save_excluded_weeks'    => array( 'fertigung.produktion' ),
			'lw16_refresh_tracking'       => array( 'fertigung.produktion' ),
			'lw16_save_tracking'          => array( 'fertigung.produktion' ),

			// Lager.
			'lw16_betrieb_save_bin'       => $lagerplatz,
			'lw16_betrieb_make_picklist'  => $lagerplatz,
			'lw16_betrieb_work_order'     => $werk,
			'lw16_betrieb_work_done'      => $werk,
			'lw16_betrieb_work_timer'     => $werk,
			'lw16_betrieb_stock_move'     => array( 'lager.bestand', 'lager.buchen' ),
			'lw16_betrieb_stock_void'     => array( 'lager.bestand', 'lager.buchen' ),
			'lw16_betrieb_stock_target'   => array( 'lager.bestand', 'fertigung.stammdaten.edit' ),
			'lw16_betrieb_stock_part'     => array( 'lager.bestand', 'lager.buchen' ),
			'lw16_betrieb_demand_order'   => array( 'lager.bedarf' ),
			'lw16_betrieb_save_lot'       => array( 'lager.material' ),
			'lw16_betrieb_dry_lot'        => array( 'lager.material' ),
			'lw16_betrieb_save_supply'    => array( 'lager.material' ),
			'lw16_betrieb_delete_supply'  => array( 'lager.material' ),

			// Verkauf.
			'lw16_betrieb_confirm_order'  => $auftrag,
			'lw16_betrieb_orphan_order'   => $auftrag,
			'lw16_betrieb_manual_order'   => $auftrag,
			'lw16_betrieb_save_order'     => $auftrag,
			'lw16_betrieb_delivery_note'  => array( 'verkauf.orders' ),
			'lw16_betrieb_dhl_label'      => array( 'verkauf.versand', 'verkauf.versand.etikett' ),
			'lw16_betrieb_dhl_void'       => array( 'verkauf.versand' ),
			'lw16_betrieb_dhl_pdf'        => array( 'verkauf.versand' ),
			'lw16_betrieb_dhl_test'       => array( 'verkauf.versand' ),
			'lw16_betrieb_contact'        => array( 'verkauf.kontakte' ),
			'lw16_betrieb_activity'       => array( 'verkauf.kontakte' ),
			'lw16_betrieb_term'           => array( 'verkauf.kontakte' ),
			'lw16_betrieb_token'          => array( 'verkauf.kontakte' ),
			'lw16_betrieb_commission_batch' => array( 'verkauf.kontakte' ),
			'lw16_referral_decision'      => array( 'verkauf.loyalty' ),
			'lw16_kampagne_km'            => array( 'verkauf.marketing' ),
			'lw16_kampagne_loesen'        => array( 'verkauf.marketing' ),
			'lw16_kampagne_zuordnen'      => array( 'verkauf.marketing' ),
			'lw16_save_kampagne'          => array( 'verkauf.marketing' ),

			// Finanzen.
			'lw16_betrieb_export'         => $export,
			'lw16_betrieb_analyse'        => $export,
			'lw16_betrieb_freeze'         => array( 'finanzen.berichte' ),
			'lw16_bp_export'              => $konten_ex,
			'lw16_euer_pdf'               => $konten_ex,
			'lw16_verfahrensdoku'         => $konten_ex,

			// Verwaltung.
			'lw16_betrieb_time'           => array( 'verwaltung.zeit' ),
			'lw16_timer_start'            => array( 'verwaltung.zeit' ),
			'lw16_timer_discard'          => array( 'verwaltung.zeit' ),
			'lw16_betrieb_labels'         => array( 'verwaltung.etiketten' ),
			'lw16_betrieb_labels_queue'   => array( 'verwaltung.etiketten' ),
			'lw16_print_queue_add_printer' => array( 'verwaltung.etiketten' ),
			'lw16_print_queue_regen_token' => array( 'verwaltung.etiketten' ),
			'lw16_print_queue_test_label' => array( 'verwaltung.etiketten' ),
			'lw16_betrieb_save_lager'     => array( 'verwaltung.einstellungen' ),
			'lw16_betrieb_save_setting'   => array( 'verwaltung.einstellungen' ),
			'lw16_betrieb_save_tiers'     => array( 'verwaltung.einstellungen' ),
			'lw16_betrieb_goals'          => array( 'verwaltung.einstellungen' ),
			'lw16_save_settings'          => array( 'verwaltung.einstellungen' ),
			'lw16_betrieb_import'         => array( 'verwaltung.import' ),
			'lw16_betrieb_migrate_legacy' => array( 'verwaltung.import' ),
			'lw16_betrieb_verfahren'      => array( 'verwaltung.import' ),
			'lw16_betrieb_user_profile'   => array( 'verwaltung.loehne' ),
			'lw16_betrieb_user_wage'      => array( 'verwaltung.loehne' ),
			'lw16_export_now'             => array( 'verwaltung.support' ),
			'lw16_ticket_action'          => array( 'verwaltung.support' ),
			'lw16_ticket_create'          => array( 'verwaltung.support' ),
			'lw16_mail_account_delete'    => array( 'verwaltung.mailbox_konten' ),
			'lw16_mail_account_save'      => array( 'verwaltung.mailbox_konten' ),
			'lw16_mail_settings_save'     => array( 'verwaltung.mailbox_konten' ),
			'lw16_mail_account_test'      => array( 'verwaltung.mailbox_konten' ),
		);

		// Seite „Artikel & Revisionen“: Speichern der Stammdaten.
		foreach ( array( 'article', 'component', 'revision', 'bom', 'sourcing', 'activate', 'bom_delete', 'bundle', 'model_remove' ) as $what ) {
			$rules[ 'lw16_betrieb_save_' . $what ] = $artikel;
		}

		// Buchhaltung: alles außer den Exporten (oben) braucht nur das Modul.
		foreach ( array( 'lw16_ausscheiden_anlage', 'lw16_beleg_datei', 'lw16_beleg_storno', 'lw16_book_afa', 'lw16_deactivate_dauerbuchung', 'lw16_erechnung_beleg', 'lw16_erechnung_upload', 'lw16_execute_dauerbuchungen', 'lw16_fee_rescan', 'lw16_festschreiben', 'lw16_gobd_check', 'lw16_rechnung_storno', 'lw16_save_anlage', 'lw16_save_beleg', 'lw16_save_dauerbuchung', 'lw16_save_kassenbuch', 'lw16_storno_kassenbuch', 'lw16_ocr_scan' ) as $action ) {
			$rules[ $action ] = $konten;
		}

		return $rules;
	}

	/**
	 * Vorgänge, die bewusst keine feine Zuordnung haben.
	 *
	 * Grund steht jeweils dabei; ein neuer Eintrag verlangt eine Begründung.
	 *
	 * @return array Vorgang => Grund.
	 */
	public static function exempt() {
		$own     = 'prüft das Modul selbst (nur Vollzugriff, Altbestand)';
		$public  = 'öffentlich oder für Kunden im Shop';
		$mailbox = 'Rechte je Postfach im Mailbox-Modul';

		$list = array(
			'lw16_global_search'       => 'Suche prüft Vollzugriff selbst',
			'lw16_packliste'          => 'Lieferschein: Produktion oder Versand, das Modul prüft beides',
			'lw16_bambu_snapshot'     => 'Live-Bild: Druckfarm oder Bambu, das Modul prüft die Betriebsrechte',
			'lw16_reset_run'          => 'nur manage_options (Livegang)',
			'lw16_check_updates'      => 'WordPress-Updater',
			'lw16_rechnung'           => $public,
			'lw16_waitlist_signup'    => $public,
			'lw16_set_redeem'         => $public,
			'lw16_wishlist_toggle'    => $public,
			'lw16_event_qr'           => 'Events-Verwaltung über den Beitragstyp',
			'lw16_event_rsvp_export'  => 'Events-Verwaltung über den Beitragstyp',
			'lw16_event_rsvp_status'  => 'Events-Verwaltung über den Beitragstyp',
			'lw16_komoot_vorschau'    => 'Events-Verwaltung über den Beitragstyp',
			'lw16_gutschein_vorschau' => 'prüft das Modul selbst (Recht „edit_products“)',
			'lw16_mail_action'        => $mailbox,
			'lw16_mail_attachment'    => $mailbox,
			'lw16_mail_note'          => $mailbox,
			'lw16_mail_send'          => $mailbox,
			'lw16_mail_preview'       => $mailbox,
			'lw16_mail_sync'          => $mailbox,
		);

		foreach ( array( 'lw16_complete_pickliste', 'lw16_create_pickliste', 'lw16_delete_lagerort', 'lw16_delete_product', 'lw16_download_pickliste', 'lw16_inventur', 'lw16_produktion', 'lw16_retoure', 'lw16_save_lagerort', 'lw16_save_product', 'lw16_update_lagerort', 'lw16_update_product', 'lw16_wareneingang', 'lw16_wc_import', 'lw16_zuordnung_lagerort' ) as $action ) {
			$list[ $action ] = $own;
		}

		return $list;
	}

	/**
	 * Rechte, die ein Vorgang verlangt.
	 *
	 * @param string $action Vorgangsname.
	 * @return string[] Leer, wenn nichts verzeichnet ist.
	 */
	public static function perms_for( $action ) {
		$rules = self::rules();

		return isset( $rules[ $action ] ) ? $rules[ $action ] : array();
	}
}
