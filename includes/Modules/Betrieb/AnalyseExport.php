<?php
/**
 * Ein Auszug des Betriebszustands als Text, gedacht zur Auswertung durch
 * einen Menschen oder ein Sprachmodell.
 *
 * Bewusst kein CSV: eine Auswertung braucht nicht nur Zahlen, sondern auch
 * die Bedeutung der Spalten, die Einheiten und die Lücken. Deshalb steht am
 * Anfang, was die Datei ist, und am Ende, was nicht darin steht – eine
 * Analyse, die eine fehlende Angabe für eine Null hält, ist schlimmer als
 * gar keine.
 *
 * Zahlen stehen mit Punkt als Dezimaltrennzeichen und Daten nach ISO. Das
 * ist unhandlich für das Auge, aber eindeutig für die Auswertung – und
 * genau dafür ist die Datei da.
 *
 * Die Klasse ist frei von Datenbank und WordPress-Umgebung, damit sie
 * prüfbar bleibt; die Beschaffung der Daten steckt in collect().
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Baut den Auszug.
 */
class AnalyseExport {

	/**
	 * Zeilen je Abschnitt. Was darüber liegt, wird gekürzt und die Kürzung
	 * genannt – eine stillschweigend abgeschnittene Liste führt jede
	 * Auswertung in die Irre.
	 */
	const MAX_ROWS = 400;

	/**
	 * Eine Zahl für die Auswertung: Punkt als Trennzeichen, feste Stellen.
	 *
	 * @param mixed $value    Wert.
	 * @param int   $decimals Nachkommastellen.
	 * @return string
	 */
	public static function num( $value, $decimals = 2 ) {
		if ( null === $value || '' === $value ) {
			return '';
		}

		return number_format( (float) $value, (int) $decimals, '.', '' );
	}

	/**
	 * Eine Zelle für eine Markdown-Tabelle absichern.
	 *
	 * Senkrechte Striche und Zeilenumbrüche würden die Tabelle zerreissen.
	 *
	 * @param mixed $value Wert.
	 * @return string
	 */
	public static function cell( $value ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );

		return str_replace( '|', '/', $text );
	}

	/**
	 * Eine Markdown-Tabelle. Ohne Zeilen bleibt der Abschnitt leer, sagt
	 * das aber ausdrücklich.
	 *
	 * @param array $head Spaltenköpfe.
	 * @param array $rows Zeilen.
	 * @return string
	 */
	public static function table( array $head, array $rows ) {
		if ( ! $rows ) {
			return __( '_Keine Daten._', 'lindenweg16' ) . "\n";
		}

		$out       = array();
		$out[]     = '| ' . implode( ' | ', array_map( array( __CLASS__, 'cell' ), $head ) ) . ' |';
		$out[]     = '|' . str_repeat( ' --- |', count( $head ) );
		$truncated = count( $rows ) - self::MAX_ROWS;

		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
			$row = array_pad( array_slice( (array) $row, 0, count( $head ) ), count( $head ), '' );
			$out[] = '| ' . implode( ' | ', array_map( array( __CLASS__, 'cell' ), $row ) ) . ' |';
		}

		$text = implode( "\n", $out ) . "\n";

		if ( $truncated > 0 ) {
			$text .= "\n" . sprintf(
				/* translators: %d: Anzahl der weggelassenen Zeilen. */
				__( '_Gekürzt: %d weitere Zeilen sind hier nicht enthalten._', 'lindenweg16' ),
				$truncated
			) . "\n";
		}

		return $text;
	}

	/**
	 * Der Vorspann: was die Datei ist und wie sie zu lesen ist.
	 *
	 * @param array $data Daten.
	 * @return string
	 */
	protected static function preamble( array $data ) {
		$stand   = isset( $data['stand'] ) ? $data['stand'] : '';
		$von     = isset( $data['von'] ) ? $data['von'] : '';
		$bis     = isset( $data['bis'] ) ? $data['bis'] : '';
		$version = isset( $data['version'] ) ? $data['version'] : '';

		$out  = '# ' . __( 'Betriebsdaten Lindenweg16 – Auszug zur Analyse', 'lindenweg16' ) . "\n\n";
		$out .= sprintf(
			/* translators: 1: Zeitpunkt, 2: Beginn, 3: Ende, 4: Version. */
			__( 'Stand: %1$s (UTC) · Auswertungszeitraum: %2$s bis %3$s · Erzeugt von Lindenweg16 %4$s', 'lindenweg16' ),
			$stand,
			$von,
			$bis,
			$version
		) . "\n\n";

		$out .= '## ' . __( 'Wie diese Datei zu lesen ist', 'lindenweg16' ) . "\n\n";

		foreach ( array(
			__( 'Es ist ein Auszug aus einem Warenwirtschafts- und Fertigungssystem eines Kleinbetriebs, der Bauteile im 3D-Druck fertigt und über einen eigenen Shop sowie an Händler verkauft.', 'lindenweg16' ),
			__( 'Zahlen benutzen den Punkt als Dezimaltrennzeichen, Daten stehen nach ISO (JJJJ-MM-TT), Wochen als ISO-Woche. Geldbeträge sind Euro, Zeiten Stunden, Gewichte Gramm, sofern die Spalte nichts anderes sagt.', 'lindenweg16' ),
			__( 'Ein leeres Feld heisst „nicht erfasst" und nicht „null". Der Unterschied ist für jede Auswertung wesentlich.', 'lindenweg16' ),
			__( 'Maschinenstunden und Personenstunden sind zwei verschiedene Grössen und dürfen nicht addiert werden: der Drucker läuft ohne Bedienung, die Nachbearbeitung nicht.', 'lindenweg16' ),
			__( 'Der Abschnitt „Was in diesen Daten fehlt" am Ende nennt die bekannten Lücken. Bitte vor jeder Schlussfolgerung lesen.', 'lindenweg16' ),
		) as $line ) {
			$out .= '- ' . $line . "\n";
		}

		return $out . "\n";
	}

	/**
	 * Den ganzen Auszug bauen.
	 *
	 * @param array $data Eingesammelter Zustand, siehe collect().
	 * @return string
	 */
	public static function render( array $data ) {
		$get = static function ( $key ) use ( $data ) {
			return isset( $data[ $key ] ) ? (array) $data[ $key ] : array();
		};

		$out = self::preamble( $data );

		$out .= '## 1. ' . __( 'Kalkulationsgrundlagen', 'lindenweg16' ) . "\n\n";
		$out .= __( 'Die Annahmen, mit denen das System rechnet. Sie gelten ab einem Stichtag und werden nicht rückwirkend geändert.', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array( __( 'Grösse', 'lindenweg16' ), __( 'Wert', 'lindenweg16' ), __( 'gültig ab', 'lindenweg16' ) ),
			$get( 'settings' )
		) . "\n";

		$out .= '## 2. ' . __( 'Artikel', 'lindenweg16' ) . "\n\n";
		$out .= __( 'Beschaffungsweg: druck = selbst gedruckt, montage = aus Stückliste zusammengebaut, zukauf = eingekauft, hybrid = gedruckt und zugekauft. Bestand ist der Bestand im eigenen Lager ohne Kommissionsware. Reichweite ist der Bestand geteilt durch den Wochenverbrauch der letzten vier Wochen.', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array(
				__( 'Art.-Nr', 'lindenweg16' ),
				__( 'Bezeichnung', 'lindenweg16' ),
				__( 'Beschaffung', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
				__( 'Revision', 'lindenweg16' ),
				__( 'Material', 'lindenweg16' ),
				__( 'Gewicht g', 'lindenweg16' ),
				__( 'Druckzeit h/Teil', 'lindenweg16' ),
				__( 'Nacharbeit min', 'lindenweg16' ),
				__( 'Herstellkosten', 'lindenweg16' ),
				__( 'VK brutto', 'lindenweg16' ),
				__( 'Händlerpreis', 'lindenweg16' ),
				__( 'Bestand', 'lindenweg16' ),
				__( 'Soll', 'lindenweg16' ),
				__( 'Reichweite Wo', 'lindenweg16' ),
			),
			$get( 'articles' )
		) . "\n";

		$out .= '## 3. ' . __( 'Stücklisten', 'lindenweg16' ) . "\n\n";
		$out .= __( 'Eine Zeile je Artikel mit Stückliste, Mengen je Stück. Die Auflösung ist mehrstufig: ein Artikel kann einen anderen enthalten.', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array( __( 'Art.-Nr', 'lindenweg16' ), __( 'Besteht aus', 'lindenweg16' ) ),
			$get( 'boms' )
		) . "\n";

		$out .= '## 4. ' . __( 'Kennzahlen im Zeitraum', 'lindenweg16' ) . "\n\n";
		$out .= __( 'Ziel und Alarmgrenze sind selbst gesetzt, nicht gemessen. Lage: gruen = im Ziel, gelb = dazwischen, rot = jenseits der Alarmgrenze, neutral = kein Ziel hinterlegt.', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array(
				__( 'Kennzahl', 'lindenweg16' ),
				__( 'Wert', 'lindenweg16' ),
				__( 'Ziel', 'lindenweg16' ),
				__( 'Alarm', 'lindenweg16' ),
				__( 'Lage', 'lindenweg16' ),
			),
			$get( 'kpis' )
		) . "\n";

		$out .= '## 5. ' . __( 'Druckjournal je Woche', 'lindenweg16' ) . "\n\n";
		$out .= __( 'Jeder Druckauftrag wird beim Fertigmelden erfasst. Ausschuss ist die Differenz zwischen geplanten und guten Teilen. Druckstunden sind Maschinenzeit.', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array(
				__( 'ISO-Woche', 'lindenweg16' ),
				__( 'Aufträge', 'lindenweg16' ),
				__( 'Teile geplant', 'lindenweg16' ),
				__( 'Teile i.O.', 'lindenweg16' ),
				__( 'Ausschuss', 'lindenweg16' ),
				__( 'Ausschussquote', 'lindenweg16' ),
				__( 'Druckstunden', 'lindenweg16' ),
			),
			$get( 'print_weeks' )
		) . "\n";

		$out .= '## 6. ' . __( 'Ausschussursachen', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array( __( 'Ursache', 'lindenweg16' ), __( 'Aufträge', 'lindenweg16' ), __( 'Teile Ausschuss', 'lindenweg16' ) ),
			$get( 'failures' )
		) . "\n";

		$out .= '## 7. ' . __( 'Arbeitszeit', 'lindenweg16' ) . "\n\n";
		$out .= __( 'Personenstunden aus der Zeiterfassung. Sie enthalten die Bedienzeit am Drucker, nicht die Druckdauer.', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array( __( 'Kategorie', 'lindenweg16' ), __( 'Stunden im Zeitraum', 'lindenweg16' ) ),
			$get( 'time_categories' )
		) . "\n";
		$out .= self::table(
			array( __( 'ISO-Woche', 'lindenweg16' ), __( 'Stunden', 'lindenweg16' ) ),
			$get( 'time_weeks' )
		) . "\n";

		$out .= '## 8. ' . __( 'Offene Aufträge', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array(
				__( 'Auftrag', 'lindenweg16' ),
				__( 'Art', 'lindenweg16' ),
				__( 'Artikel', 'lindenweg16' ),
				__( 'Farbe', 'lindenweg16' ),
				__( 'Menge', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
				__( 'erfasste Stunden', 'lindenweg16' ),
			),
			$get( 'orders' )
		) . "\n";

		$out .= '## 9. ' . __( 'Lagerbewegungen im Zeitraum', 'lindenweg16' ) . "\n\n";
		$out .= __( 'Der Bestand ist ein Journal, kein Feld: jede Änderung ist eine Buchung. Stornos stehen als Gegenbuchung darin.', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array( __( 'Bewegungsart', 'lindenweg16' ), __( 'Buchungen', 'lindenweg16' ), __( 'Menge', 'lindenweg16' ) ),
			$get( 'movements' )
		) . "\n";

		$out .= '## 10. ' . __( 'Verkauf', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array( __( 'Grösse', 'lindenweg16' ), __( 'Wert', 'lindenweg16' ) ),
			$get( 'sales' )
		) . "\n";

		$out .= '## 11. ' . __( 'Geschäftskunden', 'lindenweg16' ) . "\n\n";
		if ( empty( $data['contacts_named'] ) ) {
			$out .= __( 'Namen sind bewusst nicht enthalten. Die Kunden sind durchnummeriert; dieselbe Nummer meint in dieser Datei denselben Kunden.', 'lindenweg16' ) . "\n\n";
		}
		$out .= self::table(
			array(
				__( 'Kunde', 'lindenweg16' ),
				__( 'Art', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
				__( 'Umsatz gesamt', 'lindenweg16' ),
				__( 'Kommissionsware Stück', 'lindenweg16' ),
			),
			$get( 'contacts' )
		) . "\n";

		$out .= '## 12. ' . __( 'Systemprüfung', 'lindenweg16' ) . "\n\n";
		$out .= __( 'Widersprüche, die das System in seinem eigenen Datenbestand findet. Sie sagen etwas über die Belastbarkeit der Zahlen oben.', 'lindenweg16' ) . "\n\n";
		$out .= self::table(
			array( __( 'Schwere', 'lindenweg16' ), __( 'Bereich', 'lindenweg16' ), __( 'Befund', 'lindenweg16' ) ),
			$get( 'findings' )
		) . "\n";

		$out .= '## ' . __( 'Was in diesen Daten fehlt', 'lindenweg16' ) . "\n\n";
		foreach ( $get( 'gaps' ) as $gap ) {
			$out .= '- ' . $gap . "\n";
		}

		return $out;
	}

	/* --------------------------------------------------------------------
	 * Beschaffung der Daten
	 * ------------------------------------------------------------------ */

	/**
	 * Den Zustand aus der Datenbank einsammeln.
	 *
	 * Getrennt von render(), damit die Darstellung ohne Datenbank prüfbar
	 * bleibt.
	 *
	 * @param string $von    Beginn des Zeitraums (Y-m-d).
	 * @param string $bis    Ende des Zeitraums (Y-m-d).
	 * @param bool   $named  Namen der Geschäftskunden mitgeben.
	 * @return array
	 */
	public static function collect( $von, $bis, $named = false ) {
		$articles  = ArticleRepository::all_by_id();
		$revisions = RevisionRepository::active_by_article();
		$ctx       = CalculationContext::build( $bis );
		$settings  = CalcSettings::for_date( $bis );
		$balances  = StockLedger::balances( StockRepository::all() );

		$targets = array();
		foreach ( StockRepository::targets() as $row ) {
			$key             = (int) $row['article_id'];
			$targets[ $key ] = isset( $targets[ $key ] ) ? $targets[ $key ] + (float) $row['target_qty'] : (float) $row['target_qty'];
		}

		return array(
			'stand'           => gmdate( 'Y-m-d H:i' ),
			'von'             => $von,
			'bis'             => $bis,
			'version'         => defined( 'LW16_VERSION' ) ? LW16_VERSION : '',
			'contacts_named'  => (bool) $named,
			'settings'        => self::rows_settings( $settings ),
			'articles'        => self::rows_articles( $articles, $revisions, $ctx, $settings, $balances, $targets ),
			'boms'            => self::rows_boms( $articles ),
			'kpis'            => self::rows_kpis( $von, $bis ),
			'print_weeks'     => self::rows_print_weeks(),
			'failures'        => self::rows_failures( $von, $bis ),
			'time_categories' => self::rows_time_categories( $von, $bis ),
			'time_weeks'      => self::rows_time_weeks(),
			'orders'          => self::rows_orders( $articles ),
			'movements'       => self::rows_movements( $von, $bis ),
			'sales'           => self::rows_sales( $von, $bis ),
			'contacts'        => self::rows_contacts( $named ),
			'findings'        => self::rows_findings(),
			'gaps'            => self::gaps(),
		);
	}

	/**
	 * Kalkulationsgrundlagen.
	 *
	 * @param array $settings Werte zum Stichtag.
	 * @return array
	 */
	protected static function rows_settings( array $settings ) {
		$rows = array();

		foreach ( CalcSettings::labels() as $key => $label ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}

			$history = CalcSettings::history( $key );
			$from    = '';
			foreach ( (array) $history as $row ) {
				if ( $row['valid_from'] <= gmdate( 'Y-m-d' ) ) {
					$from = $row['valid_from'];
				}
			}

			$rows[] = array( $label, self::num( $settings[ $key ], 4 ), $from );
		}

		return $rows;
	}

	/**
	 * Artikel mit Kalkulation und Bestand.
	 *
	 * @param array $articles  Artikel.
	 * @param array $revisions Aktive Revisionen.
	 * @param array $ctx       Kalkulationskontext.
	 * @param array $settings  Annahmen.
	 * @param array $balances  Bestände.
	 * @param array $targets   Sollbestände.
	 * @return array
	 */
	protected static function rows_articles( array $articles, array $revisions, array $ctx, array $settings, array $balances, array $targets ) {
		$rows = array();

		foreach ( $articles as $id => $article ) {
			$revision = isset( $revisions[ $id ] ) ? $revisions[ $id ] : null;
			$cost     = CostCalculator::manufacturing_cost( $id, $ctx, $settings );

			$stock = 0.0;
			foreach ( array_keys( (array) ( isset( $balances[ $id ] ) ? $balances[ $id ] : array() ) ) as $color ) {
				$stock += StockLedger::pick( $balances, $id, $color )['lager'];
			}

			$weekly = StockRepository::consumption( $id, 4 ) / 4;

			$rows[] = array(
				$article['sku'],
				$article['name'],
				$article['sourcing_type'],
				isset( $article['status'] ) ? $article['status'] : '',
				$revision ? $revision['version'] : '',
				$revision ? (string) $revision['material'] : '',
				$revision ? self::num( $revision['weight_g'], 1 ) : '',
				$revision ? self::num( CostCalculator::print_time_per_part( $revision ), 3 ) : '',
				self::num( isset( $article['postprocessing_min'] ) ? $article['postprocessing_min'] : null, 1 ),
				self::num( $cost['herstellkosten'], 2 ),
				self::num( isset( $article['price_gross'] ) ? $article['price_gross'] : null, 2 ),
				self::num( isset( $article['dealer_price'] ) ? $article['dealer_price'] : null, 2 ),
				self::num( $stock, 0 ),
				isset( $targets[ $id ] ) ? self::num( $targets[ $id ], 0 ) : '',
				$weekly > 0 ? self::num( $stock / $weekly, 1 ) : '',
			);
		}

		return $rows;
	}

	/**
	 * Stücklisten in einer Zeile je Artikel.
	 *
	 * @param array $articles Artikel.
	 * @return array
	 */
	protected static function rows_boms( array $articles ) {
		$components = ComponentRepository::all_by_id();
		$rows       = array();

		foreach ( BomRepository::all_by_parent() as $parent_id => $lines ) {
			if ( ! isset( $articles[ (int) $parent_id ] ) ) {
				continue;
			}

			$parts = array();
			foreach ( (array) $lines as $line ) {
				$child = (int) $line['child_id'];

				if ( 'komponente' === $line['child_type'] ) {
					$label = isset( $components[ $child ] ) ? $components[ $child ]['code'] : '?';
				} else {
					$label = isset( $articles[ $child ] ) ? $articles[ $child ]['sku'] : '?';
				}

				$parts[] = self::num( $line['qty'], 0 ) . 'x ' . $label;
			}

			$rows[] = array( $articles[ (int) $parent_id ]['sku'], implode( ', ', $parts ) );
		}

		return $rows;
	}

	/**
	 * Kennzahlen mit Ziel und Alarmgrenze.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	protected static function rows_kpis( $von, $bis ) {
		$metrics = Reporting::metrics( $von, $bis );
		$rows    = array();

		foreach ( Kpi::definitions() as $key => $definition ) {
			$value = isset( $metrics[ $key ] ) ? $metrics[ $key ] : null;

			$rows[] = array(
				$definition['label'] . ( $definition['unit'] ? ' (' . $definition['unit'] . ')' : '' ),
				null === $value ? '' : self::num( $value, 2 ),
				null === $definition['target'] ? '' : self::num( $definition['target'], 2 ),
				null === $definition['alarm'] ? '' : self::num( $definition['alarm'], 2 ),
				Kpi::evaluate( $key, $value )['level'],
			);
		}

		return $rows;
	}

	/**
	 * Druckjournal je ISO-Woche.
	 *
	 * @return array
	 */
	protected static function rows_print_weeks() {
		$weeks = array();

		foreach ( PrintJobRepository::all( array( 'limit' => 5000 ) ) as $job ) {
			if ( ! empty( $job['voided_at'] ) ) {
				continue;
			}

			$key = sprintf( '%04d-W%02d', (int) $job['iso_year'], (int) $job['iso_week'] );

			if ( ! isset( $weeks[ $key ] ) ) {
				$weeks[ $key ] = array(
					'jobs'    => 0,
					'planned' => 0.0,
					'ok'      => 0.0,
					'hours'   => 0.0,
				);
			}

			$weeks[ $key ]['jobs']++;
			$weeks[ $key ]['planned'] += (float) $job['parts_planned'];
			$weeks[ $key ]['ok']      += (float) $job['parts_ok'];
			$weeks[ $key ]['hours']   += (float) $job['print_time_h'];
		}

		krsort( $weeks );
		$rows = array();

		foreach ( $weeks as $key => $week ) {
			$scrap = $week['planned'] - $week['ok'];

			$rows[] = array(
				$key,
				$week['jobs'],
				self::num( $week['planned'], 0 ),
				self::num( $week['ok'], 0 ),
				self::num( $scrap, 0 ),
				$week['planned'] > 0 ? self::num( $scrap / $week['planned'], 4 ) : '',
				self::num( $week['hours'], 2 ),
			);
		}

		return $rows;
	}

	/**
	 * Ausschuss nach Ursache.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	protected static function rows_failures( $von, $bis ) {
		$labels = PrintJobRepository::failure_labels();
		$counts = array();

		foreach ( PrintJobRepository::all( array( 'limit' => 5000 ) ) as $job ) {
			if ( ! empty( $job['voided_at'] ) || $job['job_date'] < $von || $job['job_date'] > $bis ) {
				continue;
			}

			$scrap = PrintJobRepository::metrics( $job )['parts_scrap'];
			if ( $scrap <= 0 ) {
				continue;
			}

			$key = (string) $job['failure_reason'];
			$key = '' === $key ? 'ohne Angabe' : $key;

			if ( ! isset( $counts[ $key ] ) ) {
				$counts[ $key ] = array( 0, 0.0 );
			}

			$counts[ $key ][0]++;
			$counts[ $key ][1] += $scrap;
		}

		arsort( $counts );
		$rows = array();

		foreach ( $counts as $key => $count ) {
			$rows[] = array(
				isset( $labels[ $key ] ) ? $labels[ $key ] : $key,
				$count[0],
				self::num( $count[1], 0 ),
			);
		}

		return $rows;
	}

	/**
	 * Arbeitsstunden je Kategorie.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	protected static function rows_time_categories( $von, $bis ) {
		$labels = TimeRepository::category_labels();
		$rows   = array();

		foreach ( TimeRepository::hours_by_category( $von, $bis ) as $row ) {
			$key    = (string) $row['category'];
			$rows[] = array( isset( $labels[ $key ] ) ? $labels[ $key ] : $key, self::num( $row['hours'], 2 ) );
		}

		return $rows;
	}

	/**
	 * Arbeitsstunden je Woche.
	 *
	 * @return array
	 */
	protected static function rows_time_weeks() {
		$rows = array();

		foreach ( TimeRepository::hours_by_week( 26 ) as $row ) {
			$rows[] = array(
				sprintf( '%04d-W%02d', (int) $row['iso_year'], (int) $row['iso_week'] ),
				self::num( $row['hours'], 2 ),
			);
		}

		return $rows;
	}

	/**
	 * Offene Fertigungs- und Druckaufträge.
	 *
	 * @param array $articles Artikel.
	 * @return array
	 */
	protected static function rows_orders( array $articles ) {
		$rows = array();

		foreach ( WorkOrderRepository::unfinished() as $order ) {
			$article = isset( $articles[ (int) $order['article_id'] ] ) ? $articles[ (int) $order['article_id'] ] : null;

			$rows[] = array(
				$order['code'],
				$order['kind'],
				$article ? $article['sku'] : '',
				(string) $order['color'],
				self::num( $order['qty_planned'], 0 ),
				$order['status'],
				self::num( (float) $order['seconds_worked'] / 3600, 2 ),
			);
		}

		return $rows;
	}

	/**
	 * Lagerbewegungen des Zeitraums nach Art.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	protected static function rows_movements( $von, $bis ) {
		$labels = StockLedger::movement_labels();
		$counts = array();

		foreach ( StockRepository::all() as $movement ) {
			$date = substr( (string) $movement['moved_at'], 0, 10 );

			if ( $date < $von || $date > $bis ) {
				continue;
			}

			$key = (string) $movement['movement_type'];

			if ( ! isset( $counts[ $key ] ) ) {
				$counts[ $key ] = array( 0, 0.0 );
			}

			$counts[ $key ][0]++;
			$counts[ $key ][1] += (float) $movement['qty'];
		}

		ksort( $counts );
		$rows = array();

		foreach ( $counts as $key => $count ) {
			$rows[] = array(
				isset( $labels[ $key ] ) ? $labels[ $key ] . ' (' . $key . ')' : $key,
				$count[0],
				self::num( $count[1], 0 ),
			);
		}

		return $rows;
	}

	/**
	 * Verkaufszahlen des Zeitraums.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	protected static function rows_sales( $von, $bis ) {
		$metrics = Reporting::metrics( $von, $bis );

		return array(
			array( __( 'Umsatz brutto im Zeitraum', 'lindenweg16' ), self::num( $metrics['umsatz'], 2 ) ),
			array( __( 'Bestätigte Bestellungen', 'lindenweg16' ), self::num( $metrics['bestellungen'], 0 ) ),
			array( __( 'Durchschnittlicher Warenkorb', 'lindenweg16' ), self::num( $metrics['warenkorb'], 2 ) ),
			array( __( 'B2B-Anteil am Umsatz (%)', 'lindenweg16' ), self::num( $metrics['b2b_anteil'], 2 ) ),
			array( __( 'Wiederkaufsrate (%)', 'lindenweg16' ), self::num( $metrics['wiederkaufsrate'], 2 ) ),
			array( __( 'Offene Rechnungen', 'lindenweg16' ), (string) count( OrderOpsRepository::open_invoices() ) ),
		);
	}

	/**
	 * Geschäftskunden, auf Wunsch ohne Namen.
	 *
	 * @param bool $named Namen mitgeben.
	 * @return array
	 */
	protected static function rows_contacts( $named ) {
		$types  = ContactRepository::type_labels();
		$states = ContactRepository::status_labels();
		$rows   = array();
		$nr     = 0;

		foreach ( ContactRepository::all() as $contact ) {
			$nr++;
			$id = (int) $contact['id'];

			$label = $named
				? trim( (string) $contact['company'] . ' ' . (string) $contact['contact_person'] )
				: sprintf( /* translators: %d: laufende Nummer. */ __( 'Kunde %d', 'lindenweg16' ), $nr );

			$commission = 0.0;
			foreach ( (array) ContactRepository::commission_stock( $id ) as $row ) {
				$commission += (float) $row['qty'];
			}

			$rows[] = array(
				$label,
				isset( $types[ $contact['type'] ] ) ? $types[ $contact['type'] ] : (string) $contact['type'],
				isset( $states[ $contact['status'] ] ) ? $states[ $contact['status'] ] : (string) $contact['status'],
				self::num( ContactRepository::revenue( $id ), 2 ),
				self::num( $commission, 0 ),
			);
		}

		return $rows;
	}

	/**
	 * Befunde der Systemprüfung.
	 *
	 * @return array
	 */
	protected static function rows_findings() {
		$rows = array();

		foreach ( Consistency::check( Consistency::collect() ) as $finding ) {
			$rows[] = array( $finding['level'], $finding['area'], $finding['text'] );
		}

		return $rows;
	}

	/**
	 * Die bekannten Lücken. Sie stehen fest im Programm, weil sie sich aus
	 * dem Aufbau ergeben und nicht aus den Daten.
	 *
	 * @return array
	 */
	public static function gaps() {
		return array(
			__( 'Die Buchhaltung ist nicht enthalten: Belege, Einnahmen-Überschuss-Rechnung und tatsächliche Ausgaben stehen in einem anderen Teil des Systems. Die Herstellkosten hier sind gerechnet, nicht gebucht.', 'lindenweg16' ),
			__( 'Herstellkosten beruhen auf den Kalkulationsgrundlagen aus Abschnitt 1. Ändert sich eine Annahme, ändert sich jede Zahl in der Spalte – sie sind kein gemessener Wert.', 'lindenweg16' ),
			__( 'Die Reichweite rechnet mit dem Verbrauch der letzten vier Wochen. Bei einem jungen Sortiment oder nach einer Aktion ist dieser Wert wenig aussagekräftig.', 'lindenweg16' ),
			__( 'Arbeitszeit wird von Hand erfasst. Fehlende Stunden sehen in den Zahlen wie Leerlauf aus.', 'lindenweg16' ),
			__( 'Kommissionsware ist aus dem Lager herausgebucht und liegt beim Kunden. Sie steckt in der Spalte Bestand also nicht mehr drin.', 'lindenweg16' ),
			__( 'Bestellungen zählen nur, wenn sie bestätigt sind. Ein schwacher Zeitraum kann auch ein unbestätigter Zeitraum sein.', 'lindenweg16' ),
			__( 'Solange die Systemprüfung Befunde zur alten Lagerverwaltung meldet, buchen zwei Stellen Bestand. Bestandszahlen sind dann mit Vorsicht zu lesen.', 'lindenweg16' ),
		);
	}
}
