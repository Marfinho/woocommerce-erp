<?php
/**
 * AR-Ansicht: Upload-Typen GLB und USDZ – nur Admins, nur passender Inhalt.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\ArView\UploadTypes;
use PHPUnit\Framework\TestCase;

/**
 * Typen, Rechte und Inhaltsprüfung.
 */
class ArViewUploadTypesTest extends TestCase {

	/**
	 * Temporäre Dateien.
	 *
	 * @var array
	 */
	protected $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $f ) {
			@unlink( $f );
		}
		unset( $GLOBALS['lw16_test_caps'] );
	}

	/**
	 * Legt eine Datei mit Inhalt an.
	 *
	 * @param string $content Inhalt.
	 * @return string Pfad.
	 */
	protected function file( $content ) {
		$path          = tempnam( sys_get_temp_dir(), 'lw16ar' );
		$this->files[] = $path;
		file_put_contents( $path, $content );
		return $path;
	}

	public function test_typen() {
		$this->assertSame(
			array(
				'glb'  => 'model/gltf-binary',
				'usdz' => 'model/vnd.usdz+zip',
			),
			UploadTypes::types()
		);
	}

	public function test_nur_admins_bekommen_die_typen() {
		$up = new UploadTypes();

		$GLOBALS['lw16_test_caps'] = array( 'manage_options' );
		$this->assertArrayHasKey( 'glb', $up->add_mimes( array( 'jpg' => 'image/jpeg' ) ) );
		$this->assertArrayHasKey( 'usdz', $up->add_mimes( array() ) );
		$this->assertArrayHasKey( 'jpg', $up->add_mimes( array( 'jpg' => 'image/jpeg' ) ), 'Bestehende Typen bleiben.' );

		$GLOBALS['lw16_test_caps'] = array( 'upload_files', 'manage_lw16' );
		$this->assertSame( array( 'jpg' => 'image/jpeg' ), $up->add_mimes( array( 'jpg' => 'image/jpeg' ) ) );
	}

	public function test_inhalt_muss_zur_endung_passen() {
		$glb  = $this->file( "glTF\x02\x00\x00\x00rest" );
		$zip  = $this->file( "PK\x03\x04rest" );
		$text = $this->file( 'hallo' );

		$this->assertTrue( UploadTypes::content_matches( 'glb', $glb ) );
		$this->assertFalse( UploadTypes::content_matches( 'glb', $zip ) );
		$this->assertFalse( UploadTypes::content_matches( 'glb', $text ) );

		$this->assertTrue( UploadTypes::content_matches( 'usdz', $zip ) );
		$this->assertFalse( UploadTypes::content_matches( 'usdz', $glb ) );

		$this->assertFalse( UploadTypes::content_matches( 'glb', '/nicht/vorhanden.glb' ) );
		$this->assertFalse( UploadTypes::content_matches( 'exe', $glb ) );
	}

	public function test_check_filetype_erlaubt_nur_echte_dateien_fuer_admins() {
		$up  = new UploadTypes();
		$glb = $this->file( "glTF\x02\x00\x00\x00" );
		$bad = $this->file( 'MZ not a model' );
		$old = array( 'ext' => false, 'type' => false, 'proper_filename' => false );

		$GLOBALS['lw16_test_caps'] = array( 'manage_options' );

		$ok = $up->check_filetype( $old, $glb, 'Haken.GLB' );
		$this->assertSame( 'glb', $ok['ext'] );
		$this->assertSame( 'model/gltf-binary', $ok['type'] );

		$this->assertFalse( $up->check_filetype( $old, $bad, 'Haken.glb' )['ext'], 'Umbenannte Datei wird abgewiesen.' );

		$GLOBALS['lw16_test_caps'] = array();
		$this->assertFalse( $up->check_filetype( $old, $glb, 'Haken.glb' )['ext'], 'Ohne Adminrecht keine Datei.' );

		// Andere Endungen bleiben unberührt.
		$other = array( 'ext' => 'jpg', 'type' => 'image/jpeg', 'proper_filename' => false );
		$this->assertSame( $other, $up->check_filetype( $other, $glb, 'foto.jpg' ) );
	}

	public function test_endung() {
		$this->assertSame( 'glb', UploadTypes::extension( '/a/b/Haken.GLB' ) );
		$this->assertSame( '', UploadTypes::extension( 'ohne' ) );
	}
}
