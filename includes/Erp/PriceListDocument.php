<?php
/**
 * Händlerpreisliste als Dokument im Erscheinungsbild von Lindenweg16.
 *
 * Aus den Daten der Kundenakte entsteht eine eigenständige HTML-Seite (A4,
 * Schriften eingebettet), die der Browser über „Drucken → Als PDF speichern“
 * zum PDF macht. HTML statt eines selbst gezeichneten PDF, weil sich Layout,
 * Farben und Texte so frei ändern lassen.
 *
 * Persönlich wird das Dokument durch die Akte: Es zeigt, ob der Händler schon
 * bestellt hat, in welcher Stufe er zuletzt eingekauft hat und was er spart,
 * wenn er mehr bestellt. Die Rechenregeln sind reine Funktionen, die Ausgabe
 * ebenso – nur `serve()` und `load_fonts()` greifen auf WordPress zu.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\Pages\Verkauf;
use Lindenweg16\Modules\Betrieb\ContactRepository;
use Lindenweg16\Modules\Betrieb\DiscountResolver;

/**
 * Baut und liefert die Preisliste.
 */
class PriceListDocument {

	const ACTION = 'lw16_erp_pricelist';

	/**
	 * Wie viele frühere Bestellungen das Dokument zeigt.
	 */
	const HISTORY_LIMIT = 5;

	/**
	 * Adresse des Dokuments für einen Kontakt (öffnet in neuem Tab).
	 *
	 * @param int $contact_id Kontakt.
	 * @return string
	 */
	public static function url( $contact_id ) {
		return Shell::nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION . '&id=' . (int) $contact_id ),
			self::ACTION . '_' . (int) $contact_id
		);
	}

	/* ====================================================================
	 * Rechenregeln (rein)
	 * ================================================================== */

	/**
	 * Stufen mit dem, was sie dem Händler bringen.
	 *
	 * Je Stufe: Rabatt in € beim Mindestbestellwert, der Betrag, den der
	 * Händler dann zahlt, und was er bei diesem Bestellwert mehr spart als in
	 * der Stufe davor – das ist der Anreiz, die Schwelle zu erreichen.
	 *
	 * @param array $tiers Stufen mit min, pct und optional name.
	 * @return array Liste aus name, min, pct, discount, pay, extra.
	 */
	public static function tier_steps( array $tiers ) {
		usort(
			$tiers,
			static function ( $a, $b ) {
				return (float) $a['min'] <=> (float) $b['min'];
			}
		);

		$out      = array();
		$prev_pct = 0.0;

		foreach ( $tiers as $i => $t ) {
			$min      = (float) $t['min'];
			$pct      = (float) $t['pct'];
			$discount = round( $min * $pct / 100, 2 );

			$out[] = array(
				'name'     => isset( $t['name'] ) && '' !== trim( (string) $t['name'] ) ? (string) $t['name'] : 'Stufe ' . ( $i + 1 ),
				'min'      => $min,
				'pct'      => $pct,
				'discount' => $discount,
				'pay'      => round( $min - $discount, 2 ),
				'extra'    => round( $min * max( 0.0, $pct - $prev_pct ) / 100, 2 ),
			);

			$prev_pct = $pct;
		}

		return $out;
	}

	/**
	 * Wo der Händler bei einem Bestellwert steht und was die nächste Stufe bringt.
	 *
	 * @param array      $tiers     Stufen mit min, pct und optional name.
	 * @param float|null $reference Bestellwert (zum UVP, vor Rabatt) oder null, wenn es noch keinen gibt.
	 * @return array {current, next, missing, gain, reference}
	 */
	public static function standing( array $tiers, $reference ) {
		$steps = self::tier_steps( $tiers );
		$rows  = array();

		foreach ( $steps as $s ) {
			$rows[] = array(
				'name'            => $s['name'],
				'min_order_value' => $s['min'],
				'discount_pct'    => $s['pct'],
			);
		}

		if ( null === $reference || $reference <= 0 ) {
			return array(
				'current'   => null,
				'next'      => $steps ? $steps[0] : null,
				'missing'   => $steps ? $steps[0]['min'] : 0.0,
				'gain'      => $steps ? $steps[0]['discount'] : 0.0,
				'reference' => null,
			);
		}

		$resolved = DiscountResolver::resolve( $rows, $reference );
		$current  = null;
		$next     = null;

		foreach ( $steps as $s ) {
			if ( $resolved['tier'] && $s['name'] === $resolved['tier']['name'] && abs( $s['min'] - (float) $resolved['tier']['min_order_value'] ) < 0.001 ) {
				$current = $s;
			}

			if ( $resolved['next'] && $s['name'] === $resolved['next']['name'] && abs( $s['min'] - (float) $resolved['next']['min_order_value'] ) < 0.001 ) {
				$next = $s;
			}
		}

		$gain = 0.0;

		if ( $next ) {
			$cur_pct = $current ? $current['pct'] : 0.0;
			$gain    = round( $next['min'] * ( $next['pct'] - $cur_pct ) / 100, 2 );
		}

		return array(
			'current'   => $current,
			'next'      => $next,
			'missing'   => $next ? round( $next['min'] - $reference, 2 ) : 0.0,
			'gain'      => $gain,
			'reference' => $reference,
		);
	}

	/**
	 * Bestellwert der letzten Bestellung zum UVP, vor Rabatt.
	 *
	 * Gespeichert ist, was der Händler gezahlt hat; die Stufen aber gelten für
	 * den Wert vor Rabatt. Zurückgerechnet ist das eine Näherung (Versand und
	 * nicht rabattfähige Posten sind nicht herausgerechnet) – für den Hinweis
	 * „so weit fehlt zur nächsten Stufe“ reicht sie.
	 *
	 * @param array $orders Bestellungen, neueste zuerst, mit value und pct.
	 * @return float|null
	 */
	public static function reference_value( array $orders ) {
		if ( ! $orders ) {
			return null;
		}

		$last = reset( $orders );
		$pct  = max( 0.0, min( 99.0, (float) $last['pct'] ) );
		$base = (float) $last['value'] / ( 1 - $pct / 100 );

		return $base > 0 ? round( $base, 2 ) : null;
	}

	/**
	 * Name der Stufe zu einem Rabattsatz, soweit er eindeutig zuzuordnen ist.
	 *
	 * @param float $pct   Rabattsatz.
	 * @param array $steps Stufen aus tier_steps().
	 * @return string Leer, wenn keine Stufe passt.
	 */
	public static function tier_label( $pct, array $steps ) {
		if ( (float) $pct <= 0 ) {
			return '';
		}

		foreach ( $steps as $s ) {
			if ( abs( $s['pct'] - (float) $pct ) < 0.001 ) {
				return $s['name'];
			}
		}

		return '';
	}

	/* ====================================================================
	 * Formatierung (rein)
	 * ================================================================== */

	/**
	 * Text für HTML maskieren.
	 *
	 * @param mixed $s Text.
	 * @return string
	 */
	protected static function e( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Betrag in Euro; ganze Beträge ohne Cent.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	public static function eur( $n ) {
		$n        = round( (float) $n, 2 );
		$decimals = abs( $n - round( $n ) ) < 0.005 ? 0 : 2;

		return number_format( $n, $decimals, ',', '.' ) . ' €';
	}

	/**
	 * Prozentsatz mit Komma, ohne überflüssige Nullen.
	 *
	 * @param float $p Prozent.
	 * @return string
	 */
	public static function pct( $p ) {
		return str_replace( '.', ',', rtrim( rtrim( number_format( (float) $p, 2, '.', '' ), '0' ), '.' ) ) . ' %';
	}

	/**
	 * Datum als TT.MM.JJJJ.
	 *
	 * @param string $ymd JJJJ-MM-TT.
	 * @return string
	 */
	public static function dmy( $ymd ) {
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', (string) $ymd, $m ) ? $m[3] . '.' . $m[2] . '.' . $m[1] : (string) $ymd;
	}

	/**
	 * Anrede: mit Namen nur bei einer einzelnen Person.
	 *
	 * @param string $person Ansprechpartner (leer erlaubt, auch mehrere).
	 * @return string
	 */
	public static function greeting( $person ) {
		$person = trim( (string) $person );

		if ( '' === $person || false !== strpos( $person, ',' ) || false !== stripos( $person, ' und ' ) ) {
			return 'Guten Tag,';
		}

		return 'Guten Tag ' . $person . ',';
	}

	/* ====================================================================
	 * Dokument (rein)
	 * ================================================================== */

	/**
	 * Das fertige HTML-Dokument.
	 *
	 * @param array $d     Daten aus Verkauf::price_list_data().
	 * @param array $fonts Schriftname => Base64-Inhalt (WOFF2); leer = Systemschrift.
	 * @return string
	 */
	public static function render( array $d, array $fonts = array() ) {
		$c       = $d['contact'];
		$me      = isset( $d['sender'] ) ? $d['sender'] : array();
		$steps   = self::tier_steps( $d['tiers'] );
		$orders  = isset( $d['orders'] ) ? $d['orders'] : array();
		$ref     = self::reference_value( $orders );
		$stand   = self::standing( $d['tiers'], $ref );
		$last    = $orders ? reset( $orders ) : null;
		$last_tn = $last ? self::tier_label( $last['pct'], $steps ) : '';
		$revenue = 0.0;

		foreach ( $orders as $o ) {
			$revenue += (float) $o['value'];
		}

		$title = 'Preisliste ' . $c['company'] . ' – ' . self::dmy( $d['date'] );
		$html  = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . self::e( $title ) . '</title>'
			. '<style>' . self::css( $fonts ) . '</style></head><body>';

		$html .= '<div class="toolbar no-print"><button type="button" onclick="window.print()">Als PDF speichern oder drucken</button>'
			. '<span>Im Druckdialog „Als PDF speichern“ wählen und „Kopf- und Fußzeilen“ ausschalten.</span></div>';

		/* ---- Seite 1 ---- */
		$html .= '<section class="page">';
		$html .= self::band( $d['date'] );

		$html .= '<div class="addr"><div class="from">' . self::e( implode( ' · ', array_filter( array( isset( $me['name'] ) ? $me['name'] : '', isset( $me['strasse'] ) ? $me['strasse'] : '', isset( $me['ort'] ) ? $me['ort'] : '' ) ) ) ) . '</div>'
			. '<div class="to"><b>' . self::e( $c['company'] ) . '</b>'
			. ( '' !== $c['person'] ? '<div>' . self::e( $c['person'] ) . '</div>' : '' )
			. ( '' !== $c['address'] ? '<div>' . self::e( $c['address'] ) . '</div>' : '' )
			. ( '' !== $c['vat_id'] ? '<div class="mono">USt-IdNr. ' . self::e( $c['vat_id'] ) . '</div>' : '' )
			. '</div></div>';

		$html .= '<h1>Ihre Händlerpreise</h1>';
		$html .= '<p class="lead">' . self::e( self::greeting( $c['person'] ) ) . '<br>' . self::e( self::intro( count( $orders ), $last_tn, $last ) ) . '</p>';

		if ( $orders ) {
			$html .= self::history_panel( $orders, $revenue, $last, $last_tn, $steps );
		}

		$html .= self::articles_table( $d['rows'], (float) $d['vat'] );
		$html .= self::footer( $me );
		$html .= '</section>';

		/* ---- Seite 2 ---- */
		$html .= '<section class="page">';
		$html .= self::band( $d['date'] );
		$html .= '<h1>Je mehr Sie bestellen, desto mehr bleibt bei Ihnen</h1>';
		$html .= '<p class="lead">Der Rabatt richtet sich nach dem Wert Ihrer Bestellung. Mit jeder Stufe steigt nicht nur der Satz – er gilt auch für den ganzen Bestellwert, nicht nur für den Teil darüber.</p>';
		$html .= self::steps_table( $steps, $stand, $last_tn );
		$html .= self::callout( $stand, $steps, $ref );
		$html .= self::terms( $d );
		$html .= self::footer( $me );
		$html .= '</section>';

		return $html . '</body></html>';
	}

	/**
	 * Einleitungssatz – je nachdem, ob der Händler schon bestellt hat.
	 *
	 * @param int        $count   Anzahl bisheriger Bestellungen.
	 * @param string     $tier    Name der zuletzt genutzten Stufe.
	 * @param array|null $last    Letzte Bestellung.
	 * @return string
	 */
	protected static function intro( $count, $tier, $last ) {
		if ( $count < 1 ) {
			return 'schön, dass Sie sich den Haster Haken ansehen. Hier finden Sie, was ein Händler bei uns zahlt, und wie sich der Einkauf mit der Menge für Sie lohnt.';
		}

		$text = 1 === $count ? 'vielen Dank für Ihre Bestellung.' : 'vielen Dank für Ihre bisherigen ' . $count . ' Bestellungen.';

		if ( $last && (float) $last['pct'] > 0 ) {
			$text .= ' Zuletzt haben Sie mit ' . self::pct( $last['pct'] ) . ' Rabatt eingekauft' . ( '' !== $tier ? ' (Stufe „' . $tier . '“)' : '' ) . '. Hier sehen Sie Ihre aktuellen Preise und was die nächste Stufe für Sie bedeutet.';
		} else {
			$text .= ' Hier sehen Sie Ihre aktuellen Preise und was Sie mit einer größeren Bestellung sparen.';
		}

		return $text;
	}

	/**
	 * Kasten „Ihr Stand bei uns“ mit den letzten Bestellungen.
	 *
	 * @param array      $orders  Bestellungen, neueste zuerst.
	 * @param float      $revenue Umsatz insgesamt.
	 * @param array|null $last    Letzte Bestellung.
	 * @param string     $last_tn Name der zuletzt genutzten Stufe.
	 * @param array      $steps   Stufen.
	 * @return string
	 */
	protected static function history_panel( array $orders, $revenue, $last, $last_tn, array $steps ) {
		$out  = '<div class="panel"><h2>Ihr Stand bei uns</h2><div class="tiles">';
		$out .= '<div class="tile"><span>Bestellungen</span><b class="mono">' . count( $orders ) . '</b></div>';
		$out .= '<div class="tile"><span>Bestellwert gesamt</span><b class="mono">' . self::e( self::eur( $revenue ) ) . '</b></div>';
		$out .= '<div class="tile"><span>Zuletzt eingekauft mit</span><b class="mono">' . self::e( (float) $last['pct'] > 0 ? self::pct( $last['pct'] ) : 'ohne Rabatt' ) . '</b>' . ( '' !== $last_tn ? '<em>Stufe „' . self::e( $last_tn ) . '“</em>' : '' ) . '</div>';
		$out .= '</div>';

		$out .= '<table class="hist"><thead><tr><th>Bestellung</th><th>Datum</th><th class="num">Wert</th><th class="num">Rabatt</th></tr></thead><tbody>';

		foreach ( array_slice( $orders, 0, self::HISTORY_LIMIT ) as $o ) {
			$tn   = self::tier_label( $o['pct'], $steps );
			$out .= '<tr><td class="mono">' . self::e( $o['number'] ) . '</td><td>' . self::e( self::dmy( $o['date'] ) ) . '</td>'
				. '<td class="num mono">' . self::e( self::eur( $o['value'] ) ) . '</td>'
				. '<td class="num mono">' . self::e( (float) $o['pct'] > 0 ? self::pct( $o['pct'] ) . ( '' !== $tn ? ' · ' . $tn : '' ) : '–' ) . '</td></tr>';
		}

		return $out . '</tbody></table></div>';
	}

	/**
	 * Artikeltabelle.
	 *
	 * @param array $rows Zeilen aus Verkauf::price_list_rows().
	 * @return string
	 */
	protected static function articles_table( array $rows, $vat = 0.0 ) {
		$out = '<h2>Artikel und Händlerpreise</h2>';

		if ( ! $rows ) {
			return $out . '<p class="muted">Zurzeit sind keine Artikel mit Händlerpreis hinterlegt.</p>';
		}

		$out .= '<table class="art"><thead><tr><th>Nr.</th><th>Artikel</th><th class="num">Empfohlener VK'
			. ( $vat > 0 ? '<br><small>brutto</small>' : '' ) . '</th><th class="num">Ihr Preis'
			. ( $vat > 0 ? '<br><small>netto</small>' : '' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$out .= '<tr><td class="mono">' . self::e( $r['sku'] ) . '</td><td>' . self::e( $r['name'] ) . '</td>'
				. '<td class="num mono">' . self::e( null === $r['uvp'] ? '–' : self::eur( $r['uvp'] ) ) . '</td>'
				. '<td class="num mono strong">' . self::e( self::eur( $r['dealer_net'] ) ) . '</td></tr>';
		}

		return $out . '</tbody></table>';
	}

	/**
	 * Tabelle der Stufen mit Ersparnis; die zuletzt genutzte Stufe ist markiert.
	 *
	 * @param array  $steps   Stufen aus tier_steps().
	 * @param array  $stand   Ergebnis von standing().
	 * @param string $last_tn Name der zuletzt genutzten Stufe.
	 * @return string
	 */
	protected static function steps_table( array $steps, array $stand, $last_tn ) {
		if ( ! $steps ) {
			return '<p class="muted">Die Mengenstaffel wird individuell vereinbart.</p>';
		}

		$out = '<table class="steps"><thead><tr><th>Stufe</th><th class="num">ab Bestellwert</th><th class="num">Rabatt</th><th class="num">Sie sparen</th><th class="num">Sie zahlen</th><th class="num">Mehr gespart<br><small>als in der Stufe davor</small></th></tr></thead><tbody>';

		foreach ( $steps as $i => $s ) {
			$mine = '' !== $last_tn && $s['name'] === $last_tn;
			$out .= '<tr' . ( $mine ? ' class="mine"' : '' ) . '><td><b>' . self::e( $s['name'] ) . '</b>' . ( $mine ? ' <span class="tag">Ihre letzte Stufe</span>' : '' ) . '</td>'
				. '<td class="num mono">' . self::e( self::eur( $s['min'] ) ) . '</td>'
				. '<td class="num mono strong">' . self::e( self::pct( $s['pct'] ) ) . '</td>'
				. '<td class="num mono">' . self::e( self::eur( $s['discount'] ) ) . '</td>'
				. '<td class="num mono">' . self::e( self::eur( $s['pay'] ) ) . '</td>'
				. '<td class="num mono">' . self::e( 0 === $i ? '–' : '+ ' . self::eur( $s['extra'] ) ) . '</td></tr>';
		}

		return $out . '</tbody></table><p class="note">„Sie sparen“ und „Sie zahlen“ gelten jeweils genau beim Mindestbestellwert der Stufe.</p>';
	}

	/**
	 * Hervorgehobener Hinweis auf die nächste Stufe.
	 *
	 * @param array      $stand Ergebnis von standing().
	 * @param array      $steps Stufen.
	 * @param float|null $ref   Bestellwert der letzten Bestellung.
	 * @return string
	 */
	protected static function callout( array $stand, array $steps, $ref ) {
		if ( ! $steps ) {
			return '';
		}

		if ( null === $ref ) {
			$s = $steps[0];

			return '<div class="callout"><b>Schon ab ' . self::e( self::eur( $s['min'] ) ) . ' Bestellwert</b> sparen Sie ' . self::e( self::eur( $s['discount'] ) ) . ' (' . self::e( self::pct( $s['pct'] ) ) . '). Wer mehr bestellt, kommt in die höheren Stufen – bis zu ' . self::e( self::pct( end( $steps )['pct'] ) ) . ' Rabatt.</div>';
		}

		if ( ! $stand['next'] ) {
			return '<div class="callout"><b>Sie sind in unserer höchsten Stufe.</b> Mit Ihrer letzten Bestellung (Bestellwert rund ' . self::e( self::eur( $ref ) ) . ') haben Sie bereits den besten Satz von ' . self::e( self::pct( $stand['current']['pct'] ) ) . ' erreicht.</div>';
		}

		$n = $stand['next'];

		return '<div class="callout"><b>Nur ' . self::e( self::eur( $stand['missing'] ) ) . ' mehr Bestellwert – und Sie sind in Stufe „' . self::e( $n['name'] ) . '“.</b> '
			. 'Ihre letzte Bestellung lag bei rund ' . self::e( self::eur( $ref ) ) . '. Ab ' . self::e( self::eur( $n['min'] ) ) . ' gelten ' . self::e( self::pct( $n['pct'] ) ) . ' Rabatt'
			. ( $stand['current'] ? ' statt ' . self::e( self::pct( $stand['current']['pct'] ) ) : '' )
			. ' – bei diesem Bestellwert sparen Sie damit ' . self::e( self::eur( $stand['gain'] ) ) . ' mehr.</div>';
	}

	/**
	 * Konditionen unter der Staffel.
	 *
	 * @param array $d Daten.
	 * @return string
	 */
	protected static function terms( array $d ) {
		$items   = array();
		$items[] = 'Zahlungsziel: ' . ( $d['days'] ? $d['days'] . ' Tage netto' : 'nach Vereinbarung' ) . '.';
		$items[] = (float) $d['vat'] > 0
			? 'Alle Preise netto zuzüglich ' . self::pct( $d['vat'] * 100 ) . ' Umsatzsteuer.'
			: 'Alle Preise ohne Umsatzsteuer (Kleinunternehmer nach § 19 UStG).';
		$items[] = 'Der Bestellwert für die Stufe wird zum empfohlenen Verkaufspreis gerechnet, vor Rabatt. Nicht rabattfähige Positionen zählen nicht mit.';
		$items[] = 'Preise freibleibend, Stand ' . self::dmy( $d['date'] ) . '.';

		$out = '<h2>Konditionen</h2><ul class="terms">';

		foreach ( $items as $i ) {
			$out .= '<li>' . self::e( $i ) . '</li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Kopfband mit Wortmarke und Stand.
	 *
	 * @param string $date Stand.
	 * @return string
	 */
	protected static function band( $date ) {
		return '<header class="band"><div class="mark"><span class="dot"></span><b>Lindenweg 16</b><em>Haster Haken</em></div>'
			. '<div class="stand mono">Händlerpreisliste · Stand ' . self::e( self::dmy( $date ) ) . '</div></header>';
	}

	/**
	 * Fußzeile.
	 *
	 * @param array $me Absender.
	 * @return string
	 */
	protected static function footer( array $me ) {
		$parts = array( 'Lindenweg 16 · Handgemacht in Haste' );

		foreach ( array( 'email', 'telefon' ) as $k ) {
			if ( ! empty( $me[ $k ] ) ) {
				$parts[] = $me[ $k ];
			}
		}

		return '<footer class="foot">' . self::e( implode( ' · ', $parts ) ) . '</footer>';
	}

	/**
	 * Gestaltung nach CI: Schwarz als Hauptfarbe, Rot nur als Akzent.
	 *
	 * @param array $fonts Schriftname => Base64 (WOFF2).
	 * @return string
	 */
	protected static function css( array $fonts ) {
		$faces = array(
			'bricolage' => array( 'Bricolage Grotesque', '200 800', 'normal' ),
			'inter'     => array( 'Inter', '100 900', 'normal' ),
			'mono'      => array( 'DM Mono', '400', 'normal' ),
			'mono500'   => array( 'DM Mono', '500', 'normal' ),
		);
		$css   = '';

		foreach ( $faces as $key => $f ) {
			if ( ! empty( $fonts[ $key ] ) ) {
				$css .= '@font-face{font-family:"' . $f[0] . '";font-weight:' . $f[1] . ';font-style:' . $f[2] . ';font-display:block;src:url(data:font/woff2;base64,' . $fonts[ $key ] . ') format("woff2");}';
			}
		}

		return $css . <<<'CSS'
:root{--ink:#151515;--on-ink:#F1EFEE;--red:#EC1443;--tint:#FBD9E0;--paper:#E8E6E3;--wood:#D9C3A5}
*{box-sizing:border-box}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{margin:0;background:#cfcdca;color:var(--ink);font:9.6pt/1.45 "Inter",system-ui,sans-serif}
.mono{font-family:"DM Mono",ui-monospace,monospace;font-variant-numeric:tabular-nums}
.toolbar{position:sticky;top:0;z-index:5;display:flex;gap:14px;align-items:center;padding:10px 16px;background:var(--ink);color:var(--on-ink);font-size:10pt}
.toolbar button{background:var(--red);color:#fff;border:0;border-radius:6px;padding:8px 14px;font:600 10pt "Inter",sans-serif;cursor:pointer}
.toolbar span{opacity:.75}
.page{position:relative;width:210mm;min-height:297mm;margin:12px auto;background:#fff;padding:0 16mm 22mm;overflow:hidden;page-break-after:always;break-after:page}
.page:last-of-type{page-break-after:auto;break-after:auto}
.band{display:flex;justify-content:space-between;align-items:center;margin:0 -16mm 9mm;padding:7mm 16mm;background:var(--ink);color:var(--on-ink);border-bottom:2.2mm solid var(--red)}
.mark{display:flex;align-items:baseline;gap:3mm}
.mark .dot{display:inline-block;width:3mm;height:3mm;border-radius:50%;background:var(--red);align-self:center}
.mark b{font:700 17pt "Bricolage Grotesque",sans-serif;letter-spacing:-.01em}
.mark em{font:500 10pt "Bricolage Grotesque",sans-serif;font-style:normal;color:var(--wood)}
.stand{font-size:8.4pt;color:var(--on-ink);opacity:.85}
h1{font:700 21pt/1.12 "Bricolage Grotesque",sans-serif;letter-spacing:-.015em;margin:0 0 3mm}
h2{font:700 12pt "Bricolage Grotesque",sans-serif;margin:6mm 0 2.5mm}
.lead{margin:0 0 5mm;max-width:150mm}
.muted{color:#5b5855}
.addr{display:flex;justify-content:space-between;gap:10mm;margin-bottom:8mm}
.addr .from{font-size:7.2pt;color:#6a6764;border-bottom:.2mm solid #bbb;align-self:flex-start;padding-bottom:1mm;max-width:80mm}
.addr .to{min-width:70mm;line-height:1.4}
.panel{background:var(--paper);border-left:1.6mm solid var(--red);border-radius:1mm;padding:4mm 5mm;margin:0 0 4mm}
.panel h2{margin:0 0 2.5mm}
.tiles{display:flex;gap:4mm;margin-bottom:3mm}
.tile{flex:1;background:#fff;border-radius:1.5mm;padding:2.5mm 3mm}
.tile span{display:block;font-size:7.4pt;color:#6a6764;text-transform:uppercase;letter-spacing:.04em}
.tile b{display:block;font-size:13pt;font-weight:500;margin-top:.8mm}
.tile em{display:block;font-style:normal;font-size:8pt;color:#6a6764}
table{width:100%;border-collapse:collapse}
th{font:600 7.6pt "Inter",sans-serif;text-transform:uppercase;letter-spacing:.04em;text-align:left;color:#5b5855;padding:1.6mm 2mm;border-bottom:.35mm solid var(--ink);vertical-align:bottom}
th small{font-weight:400;text-transform:none;letter-spacing:0}
td{padding:1.9mm 2mm;border-bottom:.2mm solid #d8d5d1;vertical-align:top}
.num{text-align:right;white-space:nowrap}
.strong{font-weight:500}
.hist td,.hist th{padding:1.2mm 2mm;font-size:8.6pt}
.hist th{border-bottom-color:#9a9794}
.art tbody tr:nth-child(even){background:#faf9f8}
.steps td{padding:2.6mm 2mm}
.steps tr.mine{background:var(--tint)}
.tag{display:inline-block;margin-left:1.5mm;padding:.3mm 1.6mm;border-radius:2mm;background:var(--red);color:#fff;font-size:6.6pt;font-weight:600;letter-spacing:.03em;text-transform:uppercase;vertical-align:1px}
.note{font-size:8pt;color:#6a6764;margin:1.5mm 0 0}
.callout{margin:6mm 0 0;padding:4.5mm 5.5mm;background:var(--ink);color:var(--on-ink);border-left:2mm solid var(--red);border-radius:1mm;font-size:10.4pt;line-height:1.5}
.callout b{color:#fff}
.terms{margin:0;padding-left:4.5mm}
.terms li{margin:0 0 1.2mm}
.foot{position:absolute;left:16mm;right:16mm;bottom:9mm;padding-top:2.5mm;border-top:.25mm solid #c9c6c2;font-size:7.8pt;color:#6a6764;text-align:center}
@page{size:A4;margin:0}
@media print{body{background:#fff}.no-print{display:none!important}.page{margin:0;box-shadow:none}}
CSS;
	}

	/* ====================================================================
	 * Auslieferung (WordPress)
	 * ================================================================== */

	/**
	 * Schriften als Base64 aus dem Plugin laden, damit das Dokument allein steht.
	 *
	 * @return array Schlüssel bricolage, inter, mono, mono500.
	 */
	public static function load_fonts() {
		$dir   = LW16_PLUGIN_DIR . 'assets/vendor/lindenweg16-fonts/';
		$files = array(
			'bricolage' => 'bricolage-grotesque-latin-wght-normal.woff2',
			'inter'     => 'inter-latin-wght-normal.woff2',
			'mono'      => 'dm-mono-latin-400-normal.woff2',
			'mono500'   => 'dm-mono-latin-500-normal.woff2',
		);
		$out   = array();

		foreach ( $files as $key => $file ) {
			if ( is_readable( $dir . $file ) ) {
				$out[ $key ] = base64_encode( (string) file_get_contents( $dir . $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}

		return $out;
	}

	/**
	 * Auslieferung der Preisliste (admin-post).
	 */
	public static function serve() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		check_admin_referer( self::ACTION . '_' . $id );

		if ( ! Access::can( 'verkauf.kontakte' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}

		if ( ! ContactRepository::find( $id ) ) {
			wp_die( esc_html__( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo self::render( Verkauf::price_list_data( $id ), self::load_fonts() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
