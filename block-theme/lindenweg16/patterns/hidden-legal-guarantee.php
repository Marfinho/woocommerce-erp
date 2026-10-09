<?php
/**
 * Title: Produktseite – Gesetzliche Gewährleistung (EU)
 * Slug: lindenweg16/hidden-legal-guarantee
 * Inserter: no
 * Description: Harmonisierte Mitteilung zur gesetzlichen Gewährleistung nach der Durchführungsverordnung (EU) 2025/1960 – auf jeder Produktseite unter der Beschreibung.
 *
 * Die Mitteilung ist eine feste Vorlage der EU-Kommission: Sie wird
 * unverändert gezeigt, ohne Rundung und ohne Zuschnitt.
 *
 * @package Lindenweg16
 */

$lindenweg16_notice_png = get_theme_file_uri( 'assets/legal/gewaehrleistung-eu-de.png' );
$lindenweg16_notice_pdf = get_theme_file_uri( 'assets/legal/gewaehrleistung-eu-de.pdf' );
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-legal-guarantee","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-legal-guarantee">
	<!-- wp:group {"className":"lw16-bubble","layout":{"type":"flex","orientation":"vertical"}} -->
	<div class="wp-block-group lw16-bubble">
		<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
		<p class="is-style-eyebrow"><?php esc_html_e( 'Durchführungsverordnung (EU) 2025/1960', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:heading {"level":2,"fontSize":"x-large"} -->
		<h2 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Gesetzliche Gewährleistung', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:image {"sizeSlug":"full","linkDestination":"custom","className":"lw16-legal-guarantee__image"} -->
		<figure class="wp-block-image size-full lw16-legal-guarantee__image"><a href="<?php echo esc_url( $lindenweg16_notice_pdf ); ?>"><img src="<?php echo esc_url( $lindenweg16_notice_png ); ?>" alt="<?php esc_attr_e( 'Harmonisierte EU-Mitteilung „Gesetzliche Gewährleistung“: Mindestens zwei Jahre gesetzliche Gewährleistung der Vertragsmäßigkeit für Waren, die in der Europäischen Union verkauft werden. Entsprechen Waren nicht der Beschreibung oder funktionieren nicht bestimmungsgemäß, haften Verkäufer für jede Vertragswidrigkeit, die bei Lieferung bestand und innerhalb der Gewährleistungsfrist erkennbar wird; sie müssen kostenlose Nachbesserung oder Ersatzlieferung und in bestimmten Fällen eine Preisminderung oder die Erstattung des Kaufpreises anbieten. Was zu tun ist: das Problem so bald wie möglich dem Verkäufer melden und einen Kaufnachweis vorlegen. Mehr unter europa.eu/youreurope/garantien.', 'lindenweg16' ); ?>"/></a></figure>
		<!-- /wp:image -->

		<!-- wp:paragraph {"className":"lw16-legal-guarantee__links","fontSize":"small"} -->
		<p class="lw16-legal-guarantee__links has-small-font-size"><a href="<?php echo esc_url( $lindenweg16_notice_pdf ); ?>"><?php esc_html_e( 'Mitteilung als PDF öffnen', 'lindenweg16' ); ?></a> · <a href="https://europa.eu/youreurope/garantien" rel="noopener"><?php esc_html_e( 'Mehr zu deinen Rechten auf europa.eu', 'lindenweg16' ); ?></a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->
