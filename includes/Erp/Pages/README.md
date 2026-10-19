# Eigene Seiten im ERP (`/erp`)

Eine Seite der Navigation, die nativ statt als eingebettete wp-admin-Seite läuft, besteht aus drei Teilen.
Jeder Teil liegt in einer **eigenen Datei**, damit mehrere Seiten unabhängig entstehen können.

## 1. Server: `includes/Erp/Pages/<Name>.php`

```php
namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\RestApi;
use WP_REST_Request;
use WP_REST_Response;

class Lager {
	public static function register( RestApi $api ) {
		register_rest_route( RestApi::NAMESPACE_V1, '/erp/bestand', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list' ),
			'permission_callback' => $api->needs( array( 'lager.bestand' ) ), // feines Recht aus Permissions::catalog()
		) );
	}
}
```

* Die Klasse wird automatisch gefunden (`glob`), `register()` ist die einzige Pflicht.
* Rechte immer über `$api->needs( [ … ] )` (alle genannten Rechte nötig) bzw. `Access::can( 'x.y' )`.
* Schreibende Routen: `POST`, Eingaben mit `sanitize_*` bereinigen, Fehler als
  `new WP_REST_Response( array( 'ok' => false, 'message' => '…' ), 422 )` mit **sprechendem deutschem Text**
  (die Oberfläche zeigt `message` bzw. `error` an; nie nur eine Nummer).
* Vorhandene Repositories und Regeln **wiederverwenden** (`includes/Modules/*`), nicht neu schreiben. Die
  alten Seiten (`includes/Modules/*/Admin/*Page.php`) zeigen, welche Funktionen aufgerufen werden und welche
  Prüfungen gelten (Validierung, Protokoll `BetriebAudit`, Rechte). Dieselben Prüfungen, nichts weglassen.
* Reine Rechenregeln als statische Methoden ohne Datenbank, damit sie sich testen lassen.
* PHP ≥ 7.4, WordPress-Coding-Standards wie im Rest des Plugins, deutsche Texte mit `__( '…', 'lindenweg16' )`.

## 2. Oberfläche: `assets/erp/pages/<name>.js`

```js
(function () {
	'use strict';
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	// Seite der Navigation (Kennung aus Navigation::items()): wird mit (item, ui) aufgerufen.
	UI.pages['lager'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Bestand', 'Was im Lager liegt.', ui.classicBtn(item));
		ui.view().appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		ui.api('bestand').then(function (d) { /* … */ });
	};

	// Eigene Adresse #/<name>/<id>/<unter> (Akten, Detailseiten): wird mit (route, ui) aufgerufen.
	UI.routes['lagerplatz'] = function (r, ui) { /* r.id, r.sub */ ui.section('lagerplaetze'); };
})();
```

Geladen werden alle Dateien aus `assets/erp/pages/` automatisch vor `erp.js`; sie laufen erst, wenn ihre Seite
gebraucht wird. Kein Build-Schritt, kein Modulsystem, klassisches ES5-JavaScript (`var`, `function`).

**Hilfen in `ui`:** `h(tag, attrs, …kinder)` (baut DOM-Elemente; **nie `innerHTML` mit Daten**), `api(pfad, {method, body})`
(Pfad relativ zu `lw16/v1/erp/`; wirft bei Fehler einen `Error` mit lesbarer `message`), `toast(text, istFehler)`,
`ico(name)`, `money(zahl, 'EUR')`, `num(zahl)`, `qty(zahl)`, `when(unixzeit)`, `ago(unixzeit, jetzt)`, `minTxt(minuten)`,
`dlg(titel, inhalt, breit)` (Dialog), `pageHead(titel, untertitel, rechts)`, `classicBtn(item)` („Klassische Ansicht“,
wenn die Seite eine alte wp-admin-Seite als Vorgänger hat), `setHead(titel, krümel)`, `can('recht.schluessel')`,
`findItem(id)`, `view()` (das Inhaltselement), `section(seitenId)` (zeigt die Reiter des Bereichs für Seiten mit
eigener Adresse), `every(fn, ms)` (Aktualisierung, endet beim Seitenwechsel), `reload()`, `boot()`, `config`.

**Vorhandene Bausteine (CSS in `assets/erp/erp.css`):** `.card`, `.grid.tiles` + `.tile` (Kennzahl: `.lab`, `.val`),
`.rows` + `.row-card` (`.row-main`, `.row-num`, `.row-tags`), `.tag` (`.now`, `.later`), `.chip` (Filter, `aria-pressed`),
`.presets` (Chip-Reihe), `.btn` (`.primary`), `.field` (Eingabe), `.pills` + `.pill` (Unterauswahl), `.notice` (`.warn`),
`.board` + `.col` (Spalten), `table.list`, `.kv` (Beschriftung/Wert), `.timeline`, `.two` (zweispaltig), `.bar` (Balken),
`.label-area` (Formularzeile mit Umbruch), `.mat`-Karten als Beispiel. Mobil zuerst: alles muss bei 390 px Breite
bedienbar sein, Tippflächen ≥ 44 px. Texte deutsch, sprechend, kein Fachjargon ohne Not. Fehlermeldungen
sagen, was zu tun ist.

Vorbilder im Bestand: `viewShipping`, `viewProcurement`, `viewMaterial`, `viewProducts`, `viewProduct`
(Akte mit Reitern), `viewPrinterFile` in `erp.js`; serverseitig `Screens.php`, `Procurement.php`, `Catalog.php`.

## 3. Navigation: `includes/Erp/Navigation.php`

In `items()` die Seite von `legacy` auf `native` umstellen und die alte wp-admin-Seite als Rückfall nennen:

```php
self::item( 'lager', 'lager', __( 'Lager', 'lindenweg16' ), 'lager.bestand', '', 'native', '', 'lw16-betrieb-lager' ),
```

(`alt` = Seitenkennung der alten Seite; sie bleibt erreichbar und gesperrt wie bisher.) Der Test
`ErpNavigationTest` prüft, dass jede native Seite in `assets/erp/pages/*.js` mit `UI.pages['<id>']` angemeldet ist.

## Tests

* `vendor/bin/phpunit` muss grün bleiben. Neue reine Regeln bekommen Tests in `tests/Erp<Name>Test.php`; die
  Klasse in `tests/bootstrap.php` mit `require` eintragen (dort stehen die Erp-Klassen untereinander).
* JavaScript prüfen mit `node --check assets/erp/pages/<datei>.js`.
