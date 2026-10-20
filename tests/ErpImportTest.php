<?php
/**
 * Regeln des CSV-Imports: Datei lesen, Zuordnung, Zahlen und Daten.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Import;
use Lindenweg16\Modules\Betrieb\Admin\ImportPage;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Import.
 */
class ErpImportTest extends TestCase {

	public function test_semicolon_file_is_split() {
		$rows = Import::parse_csv( "Art.-Nr;Bezeichnung;VK brutto\nA-1;Hocker;12,50\nA-2;Tisch;99\n" );

		$this->assertSame( array( 'Art.-Nr', 'Bezeichnung', 'VK brutto' ), $rows[0] );
		$this->assertSame( array( 'A-1', 'Hocker', '12,50' ), $rows[1] );
		$this->assertCount( 3, $rows );
	}

	public function test_comma_file_is_recognised() {
		$rows = Import::parse_csv( "sku,name\nA-1,Hocker\n" );

		$this->assertSame( array( 'sku', 'name' ), $rows[0] );
		$this->assertSame( array( 'A-1', 'Hocker' ), $rows[1] );
	}

	public function test_decimal_comma_in_a_semicolon_file_does_not_switch_delimiter() {
		$this->assertSame( ';', Import::detect_delimiter( "a;b;c\n1,5;2,5;3,5\n" ) );
		$this->assertSame( ',', Import::detect_delimiter( "a,b,c\n1,2,3\n" ) );
		$this->assertSame( "\t", Import::detect_delimiter( "a\tb\tc\n" ) );
		$this->assertSame( ';', Import::detect_delimiter( 'einspaltig' ) );
	}

	public function test_quotes_keep_delimiters_newlines_and_doubled_quotes() {
		$rows = Import::parse_csv( "a;b\n\"x;y\";\"Zeile 1\nZeile 2\"\n\"sagt \"\"hi\"\"\";z\r\n" );

		$this->assertSame( array( 'x;y', "Zeile 1\nZeile 2" ), $rows[1] );
		$this->assertSame( array( 'sagt "hi"', 'z' ), $rows[2] );
	}

	public function test_blank_lines_keep_the_line_numbers() {
		$rows = Import::parse_csv( "a;b\n\n1;2\n" );

		$this->assertCount( 3, $rows );
		$this->assertSame( array( '' ), $rows[1] );
		$this->assertSame( array( '1', '2' ), $rows[2] );
	}

	public function test_old_mac_line_endings_and_missing_final_newline() {
		$rows = Import::parse_csv( "a;b\r1;2" );

		$this->assertSame( array( array( 'a', 'b' ), array( '1', '2' ) ), $rows );
	}

	public function test_cells_are_trimmed_and_trailing_empty_cell_is_kept() {
		$rows = Import::parse_csv( " a ; b ;\n" );

		$this->assertSame( array( 'a', 'b', '' ), $rows[0] );
	}

	public function test_byte_order_mark_is_removed() {
		$this->assertSame( 'Art', Import::decode_text( "\xEF\xBB\xBFArt" ) );
	}

	public function test_windows_1252_is_converted_to_utf8() {
		// ö = F6, ß = DF, € = 80 in Windows-1252.
		$this->assertSame( 'Größe €', Import::decode_text( "Gr\xF6\xDFe \x80" ) );
	}

	public function test_valid_utf8_is_left_alone() {
		$this->assertSame( 'Größe €', Import::decode_text( 'Größe €' ) );
	}

	public function test_utf16_with_bom_is_converted() {
		$utf16 = "\xFF\xFE" . "A\x00\xF6\x00;\x00";

		$this->assertSame( 'Aö;', Import::decode_text( $utf16 ) );
	}

	public function test_numbers_in_german_and_english_notation() {
		$this->assertSame( 1.5, Import::parse_number( '1,5' ) );
		$this->assertSame( 1.5, Import::parse_number( '1.5' ) );
		$this->assertSame( 1234.5, Import::parse_number( '1.234,50' ) );
		$this->assertSame( 1234.5, Import::parse_number( '1,234.50' ) );
		$this->assertSame( 1000.0, Import::parse_number( '1.000' ) );
		$this->assertSame( 1.234, Import::parse_number( '1,234' ) );
		$this->assertSame( 12.5, Import::parse_number( '12,50 €' ) );
		$this->assertSame( -3.0, Import::parse_number( '-3' ) );
		$this->assertSame( 0.0, Import::parse_number( '0' ) );
	}

	public function test_text_is_not_a_number() {
		$this->assertNull( Import::parse_number( 'abc' ) );
		$this->assertNull( Import::parse_number( '' ) );
		$this->assertNull( Import::parse_number( '12 kg' ) );
		$this->assertNull( Import::parse_number( '1,2,3' ) );
	}

	public function test_dates_in_german_and_iso_notation() {
		$this->assertSame( '2025-12-31', Import::parse_date( '31.12.2025' ) );
		$this->assertSame( '2025-03-04', Import::parse_date( '4.3.2025' ) );
		$this->assertSame( '2025-12-31', Import::parse_date( '31.12.25' ) );
		$this->assertSame( '2025-12-31', Import::parse_date( '31/12/2025' ) );
		$this->assertSame( '2025-12-31', Import::parse_date( '2025-12-31' ) );
		$this->assertSame( '2025-12-31', Import::parse_date( '31.12.2025 14:30' ) );
		$this->assertSame( '2024-02-29', Import::parse_date( '29.02.2024' ) );
	}

	public function test_impossible_dates_are_refused() {
		$this->assertNull( Import::parse_date( '31.02.2025' ) );
		$this->assertNull( Import::parse_date( '2025-13-01' ) );
		$this->assertNull( Import::parse_date( 'gestern' ) );
		$this->assertNull( Import::parse_date( '' ) );
		$this->assertNull( Import::parse_date( '12.2025' ) );
	}

	public function test_record_is_normalised_for_the_repositories() {
		$out = Import::normalize_record(
			array(
				'sku'         => 'A-1',
				'weight_g'    => '12,5',
				'price_gross' => '1.299,00 €',
				'job_date'    => '05.01.2026',
				'note'        => '1,5',
				'hours'       => '',
			),
			array( 'weight_g' => 'Gewicht g' )
		);

		$this->assertSame( array(), $out['errors'] );
		$this->assertSame( '12.5', $out['record']['weight_g'] );
		$this->assertSame( '1299', $out['record']['price_gross'] );
		$this->assertSame( '2026-01-05', $out['record']['job_date'] );
		$this->assertSame( '1,5', $out['record']['note'] );
		$this->assertSame( 'A-1', $out['record']['sku'] );
		$this->assertSame( '', $out['record']['hours'] );
	}

	public function test_unreadable_values_become_errors_naming_the_field() {
		$out = Import::normalize_record(
			array( 'weight_g' => 'viel', 'entry_date' => 'morgen', 'hours' => '2' ),
			array( 'weight_g' => 'Gewicht g', 'entry_date' => 'Datum (Pflicht)' )
		);

		$this->assertCount( 2, $out['errors'] );
		$this->assertStringContainsString( 'Gewicht g', $out['errors'][0] );
		$this->assertStringContainsString( '„viel“', $out['errors'][0] );
		$this->assertStringContainsString( 'Datum:', $out['errors'][1] );
		$this->assertStringNotContainsString( 'Pflicht', $out['errors'][1] );
	}

	public function test_mapping_is_suggested_from_labels_and_field_names() {
		$fields = array(
			'sku'      => 'Art.-Nr (Pflicht)',
			'name'     => 'Bezeichnung (Pflicht)',
			'weight_g' => 'Gewicht g',
			'material' => 'Material',
		);
		$header = array( 'Art.-Nr', 'bezeichnung', 'Gewicht g', 'Sonstiges' );

		$map = Import::suggest_mapping( $fields, $header );

		$this->assertSame( 0, $map['sku'] );
		$this->assertSame( 1, $map['name'] );
		$this->assertSame( 2, $map['weight_g'] );
		$this->assertSame( '', $map['material'] );
	}

	public function test_mapping_by_field_name_and_umlaut_free_match() {
		$map = Import::suggest_mapping(
			array( 'sku' => 'Art.-Nr (Pflicht)', 'dealer_price' => 'Händlerpreis' ),
			array( 'SKU', 'Haendlerpreis' )
		);

		$this->assertSame( 0, $map['sku'] );
		$this->assertSame( 1, $map['dealer_price'] );
	}

	public function test_a_column_is_suggested_only_once() {
		$map = Import::suggest_mapping(
			array( 'a' => 'Datum', 'b' => 'Datum' ),
			array( 'Datum' )
		);

		$this->assertSame( 0, $map['a'] );
		$this->assertSame( '', $map['b'] );
	}

	public function test_request_mapping_is_cleaned() {
		$fields = array( 'sku' => 'Art.-Nr (Pflicht)', 'name' => 'Bezeichnung (Pflicht)' );

		$map = Import::clean_mapping( array( 'sku' => '0', 'name' => '', 'evil' => 1, 'x' => 'a' ), $fields, 3 );
		$this->assertSame( array( 'sku' => 0 ), $map );

		$this->assertSame( array(), Import::clean_mapping( array( 'sku' => 5 ), $fields, 3 ), 'Spalte außerhalb der Datei' );
		$this->assertSame( array(), Import::clean_mapping( array( 'sku' => -1 ), $fields, 3 ) );
		$this->assertSame( array(), Import::clean_mapping( 'kaputt', $fields, 3 ) );
	}

	public function test_missing_required_fields_are_named() {
		$fields = array( 'sku' => 'Art.-Nr (Pflicht)', 'name' => 'Bezeichnung (Pflicht)', 'weight_g' => 'Gewicht g' );

		$this->assertSame( array( 'Bezeichnung' ), Import::missing_required( $fields, array( 'sku' => 0 ) ) );
		$this->assertSame( array(), Import::missing_required( $fields, array( 'sku' => 0, 'name' => 1 ) ) );
	}

	public function test_row_is_translated_through_the_mapping_with_short_rows() {
		$record = Import::record_from_row( array( 'A-1', 'Hocker' ), array( 'sku' => 0, 'name' => 1, 'weight_g' => 4 ) );

		$this->assertSame( array( 'sku' => 'A-1', 'name' => 'Hocker', 'weight_g' => '' ), $record );
	}

	public function test_number_and_date_fields_exist_on_some_sheet() {
		$known = array();
		foreach ( ImportPage::sheets() as $sheet ) {
			$known = array_merge( $known, array_keys( $sheet['fields'] ) );
		}

		foreach ( array_merge( Import::number_fields(), Import::date_fields() ) as $field ) {
			$this->assertContains( $field, $known, $field );
		}
	}
}
