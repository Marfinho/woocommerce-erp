<?php
/**
 * Title: Haster-Haken-Logo (animiert)
 * Slug: lindenweg16/haster-haken-logo
 * Categories: lindenweg16, banner
 * Keywords: logo, haster haken, marke, animation
 * Viewport Width: 800
 * Description: Das Haster-Haken-Logo mit Schriftzug, mittig. Baut sich beim Laden auf: Achse, Module, Schriftzug. Farben aus der Palette.
 *
 * @package Lindenweg16
 */
?>
<!-- wp:group {"tagName":"section","className":"lw16-section","layout":{"type":"constrained"}} -->
<section class="wp-block-group lw16-section">
	<!-- wp:html -->
	<?php echo lindenweg16_haster_logo_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG, Attribute escaped. ?>
	<!-- /wp:html -->
</section>
<!-- /wp:group -->
