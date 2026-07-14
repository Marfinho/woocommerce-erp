<?php
/**
 * Test-Bootstrap: lädt die reinen Rechenregeln ohne WordPress-Umgebung.
 *
 * Die getesteten Klassen enthalten `defined('ABSPATH') || exit;` als
 * Direktaufruf-Schutz. Für die Unit-Tests definieren wir ABSPATH und laden
 * die seiteneffektfreie Calc-Klasse direkt – bewusst ohne die volle
 * WordPress-Testsuite, da nur reine Logik geprüft wird.
 *
 * @package Lindenweg16
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/includes/Core/Calc.php';
