<?php
/**
 * Unit-Tests für die Anmelderegeln eines Events.
 *
 * Die Kapazitätsrechnung entscheidet, wer mitfahren darf – und die
 * Komoot-Einbettung entscheidet, welche Adresse in einem iframe landet.
 * Beides gehört einzeln geprüft.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Events\RsvpRechnung;
use Lindenweg16\Modules\Events\EventFields;

/**
 * @covers \Lindenweg16\Modules\Events\RsvpRechnung
 * @covers \Lindenweg16\Modules\Events\EventFields
 */
class EventRsvpTest extends TestCase {

	/* ----------------------------------------------------------------
	 * Plätze und Warteliste
	 * -------------------------------------------------------------- */

	public function test_ohne_grenze_ist_die_teilnehmerzahl_unbegrenzt() {
		$this->assertFalse( RsvpRechnung::ist_begrenzt( 0 ) );
		$this->assertNull( RsvpRechnung::freie_plaetze( 0, 42 ) );
		$this->assertFalse( RsvpRechnung::ist_ausgebucht( 0, 9999 ) );
	}

	public function test_freie_plaetze_bei_gesetzter_grenze() {
		$this->assertSame( 8, RsvpRechnung::freie_plaetze( 20, 12 ) );
	}

	public function test_freie_plaetze_werden_nie_negativ() {
		// Von Hand nachgetragene Zusagen können die Grenze überschreiten.
		$this->assertSame( 0, RsvpRechnung::freie_plaetze( 10, 13 ) );
		$this->assertTrue( RsvpRechnung::ist_ausgebucht( 10, 13 ) );
	}

	public function test_letzter_platz_ist_noch_frei() {
		$this->assertSame( 1, RsvpRechnung::freie_plaetze( 10, 9 ) );
		$this->assertFalse( RsvpRechnung::ist_ausgebucht( 10, 9 ) );
		$this->assertSame( RsvpRechnung::ZUGESAGT, RsvpRechnung::status_nach_bestaetigung( 10, 9 ) );
	}

	/* ----------------------------------------------------------------
	 * Begleitpersonen
	 * -------------------------------------------------------------- */

	public function test_eine_anmeldung_ohne_begleitung_belegt_einen_platz() {
		$this->assertSame( 1, RsvpRechnung::plaetze_fuer( 0 ) );
	}

	public function test_begleitpersonen_belegen_eigene_plaetze() {
		$this->assertSame( 3, RsvpRechnung::plaetze_fuer( 2 ) );
	}

	public function test_begleitung_ist_gedeckelt() {
		$this->assertSame(
			RsvpRechnung::MAX_BEGLEITER + 1,
			RsvpRechnung::plaetze_fuer( 500 )
		);
	}

	public function test_leere_namenszeilen_fallen_heraus() {
		// Das Formular fuegt Zeilen auf Klick hinzu; eine ungenutzte bleibt
		// oft stehen und darf keinen Platz belegen.
		$this->assertSame(
			array( 'Anna', 'Bo' ),
			RsvpRechnung::begleiter_liste( array( 'Anna', '', '   ', 'Bo' ) )
		);
	}

	public function test_namen_werden_normalisiert() {
		$this->assertSame(
			array( 'Anna Beispiel' ),
			RsvpRechnung::begleiter_liste( array( "  Anna	
  Beispiel " ) )
		);
	}

	public function test_zu_viele_namen_werden_abgeschnitten() {
		$viele = array_fill( 0, 50, 'Person' );
		$this->assertCount( RsvpRechnung::MAX_BEGLEITER, RsvpRechnung::begleiter_liste( $viele ) );
	}

	public function test_gruppe_passt_nur_vollstaendig_hinein() {
		// Drei freie Plaetze, eine Gruppe zu viert: Die passt nicht.
		$this->assertTrue( RsvpRechnung::passt( 10, 7, 3 ) );
		$this->assertFalse( RsvpRechnung::passt( 10, 7, 4 ) );
	}

	public function test_gruppe_die_nicht_passt_kommt_auf_die_warteliste() {
		$this->assertSame(
			RsvpRechnung::WARTELISTE,
			RsvpRechnung::status_nach_bestaetigung( 10, 8, 3 )
		);
		$this->assertSame(
			RsvpRechnung::ZUGESAGT,
			RsvpRechnung::status_nach_bestaetigung( 10, 8, 2 )
		);
	}

	public function test_ohne_grenze_passt_jede_gruppe() {
		$this->assertTrue( RsvpRechnung::passt( 0, 999, 8 ) );
	}

	public function test_erste_anmeldung_nach_dem_letzten_platz_wartet() {
		$this->assertSame( RsvpRechnung::WARTELISTE, RsvpRechnung::status_nach_bestaetigung( 10, 10 ) );
	}

	public function test_ohne_grenze_bekommt_jeder_einen_platz() {
		$this->assertSame( RsvpRechnung::ZUGESAGT, RsvpRechnung::status_nach_bestaetigung( 0, 500 ) );
	}

	/* ----------------------------------------------------------------
	 * Formularzustand
	 * -------------------------------------------------------------- */

	public function test_abgeschaltete_anmeldung_zeigt_kein_formular() {
		$this->assertSame( 'aus', RsvpRechnung::formular_status( false, false, 10, 0 ) );
	}

	public function test_abgeschaltet_schlaegt_ausgebucht() {
		// Erst gar kein Formular – ein „ausgebucht" wäre hier irreführend.
		$this->assertSame( 'aus', RsvpRechnung::formular_status( false, false, 10, 10 ) );
	}

	public function test_vergangenes_event_nimmt_keine_anmeldung_mehr() {
		$this->assertSame( 'vorbei', RsvpRechnung::formular_status( true, true, 10, 0 ) );
	}

	public function test_ausgebuchtes_event_bietet_die_warteliste_an() {
		$this->assertSame( 'warteliste', RsvpRechnung::formular_status( true, false, 10, 10 ) );
	}

	public function test_offenes_event() {
		$this->assertSame( 'offen', RsvpRechnung::formular_status( true, false, 10, 3 ) );
		$this->assertSame( 'offen', RsvpRechnung::formular_status( true, false, 0, 300 ) );
	}

	/* ----------------------------------------------------------------
	 * Nachrücken
	 * -------------------------------------------------------------- */

	public function test_eine_absage_laesst_genau_einen_nachruecken() {
		$this->assertSame( 1, RsvpRechnung::nachruecker_anzahl( array( 1, 1, 1, 1 ), 10, 9 ) );
	}

	public function test_bei_vollem_event_rueckt_niemand_nach() {
		$this->assertSame( 0, RsvpRechnung::nachruecker_anzahl( array( 1, 1 ), 10, 10 ) );
	}

	public function test_es_ruecken_nie_mehr_nach_als_warten() {
		$this->assertSame( 2, RsvpRechnung::nachruecker_anzahl( array( 1, 1 ), 10, 3 ) );
	}

	public function test_aufgehobene_grenze_holt_die_ganze_warteliste_herein() {
		// Setzt man die Maximalzahl auf 0, ist niemand mehr zu Recht auf
		// der Warteliste.
		$this->assertSame( 7, RsvpRechnung::nachruecker_anzahl( array_fill( 0, 7, 2 ), 0, 10 ) );
	}

	public function test_eine_gruppe_rueckt_nur_geschlossen_nach() {
		// Zwei Plaetze frei, vorne wartet eine Dreiergruppe: Die wird nicht
		// auseinandergerissen, also rueckt niemand nach.
		$this->assertSame( 0, RsvpRechnung::nachruecker_anzahl( array( 3, 1 ), 10, 8 ) );
	}

	public function test_niemand_ueberholt_eine_wartende_gruppe() {
		// Der Einzelne hinter der Dreiergruppe passt zwar - darf aber nicht
		// vorbei, sonst kaeme die Gruppe nie an die Reihe.
		$this->assertSame( 0, RsvpRechnung::nachruecker_anzahl( array( 3, 1, 1 ), 10, 9 ) );
	}

	public function test_mehrere_gruppen_ruecken_nach_solange_der_platz_reicht() {
		// Fuenf Plaetze frei: 2 + 2 passen, die naechste Dreiergruppe nicht.
		$this->assertSame( 2, RsvpRechnung::nachruecker_anzahl( array( 2, 2, 3 ), 10, 5 ) );
	}

	/* ----------------------------------------------------------------
	 * Belegungstext
	 * -------------------------------------------------------------- */

	public function test_belegungstext_mit_grenze() {
		$this->assertSame( '7 von 20 Plätzen belegt', RsvpRechnung::belegung_text( 20, 7 ) );
	}

	public function test_belegungstext_ohne_grenze_zaehlt_teilnehmer() {
		$this->assertSame( '1 Teilnehmer', RsvpRechnung::belegung_text( 0, 1 ) );
		$this->assertSame( '12 Teilnehmer', RsvpRechnung::belegung_text( 0, 12 ) );
	}

	/* ----------------------------------------------------------------
	 * Komoot-Einbettung
	 * -------------------------------------------------------------- */

	public function test_komoot_tour_wird_zur_einbettungsadresse() {
		$this->assertSame(
			'https://www.komoot.de/tour/123456/embed?profile=1',
			EventFields::komoot_embed_url( 'https://www.komoot.de/tour/123456' )
		);
	}

	public function test_freigabe_token_wird_uebernommen() {
		$url = EventFields::komoot_embed_url( 'https://www.komoot.de/tour/123456?share_token=abcDEF123' );
		$this->assertStringContainsString( 'share_token=abcDEF123', $url );
		$this->assertStringContainsString( '/tour/123456/embed', $url );
	}

	public function test_komoot_com_und_sprachpfad_werden_erkannt() {
		$this->assertStringContainsString(
			'/tour/987/embed',
			EventFields::komoot_embed_url( 'https://www.komoot.com/de-de/tour/987' )
		);
	}

	public function test_fremde_adresse_wird_nicht_eingebettet() {
		// Der entscheidende Fall: Was hier durchkäme, landete in einem
		// iframe auf der eigenen Seite.
		$this->assertSame( '', EventFields::komoot_embed_url( 'https://boese.example/tour/123' ) );
		$this->assertSame( '', EventFields::komoot_embed_url( 'https://komoot.boese.example/tour/123' ) );
	}

	public function test_adresse_ohne_tour_wird_verworfen() {
		$this->assertSame( '', EventFields::komoot_embed_url( 'https://www.komoot.de/discover' ) );
	}

	public function test_leere_eingabe_bleibt_leer() {
		$this->assertSame( '', EventFields::komoot_embed_url( '' ) );
		$this->assertSame( '', EventFields::komoot_embed_url( 'kein-link' ) );
	}

	public function test_darstellung_mit_hoehenprofil_ist_der_standard() {
		$this->assertStringContainsString(
			'profile=1',
			EventFields::komoot_embed_url( 'https://www.komoot.de/tour/123456' )
		);
	}

	public function test_darstellung_nur_karte_schaltet_das_hoehenprofil_ab() {
		$url = EventFields::komoot_embed_url( 'https://www.komoot.de/tour/123456', 'karte' );
		$this->assertStringContainsString( 'profile=0', $url );
		$this->assertStringNotContainsString( 'profile=1', $url );
	}

	public function test_unbekannte_darstellung_faellt_auf_den_standard_zurueck() {
		// Komoot kennt nur diesen einen Schalter. Ein erfundener Wert darf
		// nicht als Parameter in der Adresse landen.
		$url = EventFields::komoot_embed_url( 'https://www.komoot.de/tour/123456', 'satellit' );
		$this->assertStringContainsString( 'profile=1', $url );
		$this->assertStringNotContainsString( 'satellit', $url );
	}

	public function test_darstellung_wird_gegen_eine_positivliste_geprueft() {
		$this->assertSame( 'karte', EventFields::sanitize_option( 'komoot', 'karte' ) );
		$this->assertSame( 'profil', EventFields::sanitize_option( 'komoot', 'profil' ) );
		$this->assertSame( 'profil', EventFields::sanitize_option( 'komoot', 'gelaende' ) );
		$this->assertSame( 'profil', EventFields::sanitize_option( 'komoot', array( 'unfug' ) ) );
	}

	public function test_andere_feldtypen_haben_keine_darstellung() {
		$this->assertSame( '', EventFields::sanitize_option( 'text', 'karte' ) );
		$this->assertSame( '', EventFields::sanitize_option( 'url', 'profil' ) );
	}

	public function test_ohne_hoehenprofil_wird_der_rahmen_niedriger() {
		// Sonst stuende unter der Karte ein leerer Streifen.
		$this->assertSame( 420, EventFields::komoot_hoehe( 'profil' ) );
		$this->assertSame( 340, EventFields::komoot_hoehe( 'karte' ) );
	}

	public function test_unbrauchbares_freigabe_token_wird_weggelassen() {
		// Statt das Token zu uebernehmen und damit fremde Parameter in die
		// Adresse zu lassen, faellt es einfach weg - die Tour bleibt.
		$url = EventFields::komoot_embed_url( 'https://www.komoot.de/tour/55?share_token=" onload="alert(1)' );
		$this->assertStringContainsString( '/tour/55/embed', $url );
		$this->assertStringNotContainsString( 'onload', $url );
	}
}
