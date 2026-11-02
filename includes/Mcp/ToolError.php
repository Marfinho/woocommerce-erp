<?php
/**
 * Fachlicher Fehler eines MCP-Werkzeugs.
 *
 * Geht als lesbares Ergebnis mit isError an das Modell zurück, nicht als
 * Protokollfehler – so kann es die Eingabe korrigieren.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Werkzeugfehler.
 */
class ToolError extends \RuntimeException {
}
