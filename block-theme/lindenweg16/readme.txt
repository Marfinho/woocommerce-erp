=== Lindenweg16 ===
Contributors: lindenweg16
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 10.0
WC tested up to: 11.2

Freundliches, schlankes Block-Theme (Full Site Editing) für den WooCommerce-Shop von Lindenweg16 – Heimat des Haster Haken.

== Beschreibung ==

Lindenweg16 ist ein Block-Theme für Fahrradorganisation aus Haste: große
Rundungen, weiche Schatten, schwebende Glas-Elemente und leicht federnde
Animationen. Die Texte duzen, die Preise stehen in Euro inkl. MwSt.

* Reines Block-Theme mit theme.json (Version 3), ohne Page-Builder und ohne jQuery
* Nur WooCommerce-Blöcke: Product Collection, Produktfilter, Add to Cart mit Optionen, Mini-Cart, Warenkorb, Kasse, Bestellbestätigung. Keine überschriebenen PHP-Templates aus woocommerce/.
* Keine Pflicht-Plugins außer WooCommerce selbst
* Farben nur als Rollen – du trägst deine Farben selbst ein
* Schriften lokal (Bricolage Grotesque, Inter, DM Mono), keine Anfragen an Google
* Barrierearm: sichtbarer Fokusring, Kontraste nach WCAG AA, reduzierte Bewegung wird respektiert
* Responsiv ab 360 px ohne seitliches Scrollen, Kasse mobil mit großen Feldern
* Kompatibel mit Germanized und German Market
* Newsletter-Anmeldung mit Double-Opt-in ohne Plugin

== Installation ==

1. Im WordPress-Backend: Design → Themes → Theme hinzufügen → Theme hochladen.
2. lindenweg16.zip auswählen, „Jetzt installieren“, dann „Aktivieren“.
3. WooCommerce muss installiert und aktiv sein (ab Version 10.0).
4. Unter Einstellungen → Permalinks einmal „Änderungen speichern“ klicken.

Die Startseite (front-page) wird automatisch verwendet. Du brauchst dafür
keine eigene Seite anzulegen.

== Farben ==

Design → Lindenweg16 → Farben. Ohne Website-Editor; das Theme warnt dort,
wenn eine Kombination schlecht lesbar wird, und „Standardschema
wiederherstellen“ setzt alles zurück. (Im Website-Editor unter Stile →
Farben → Palette gesetzte Farben gehen vor.)

Das Standardschema passt zum Logo: Schiefergrau #3a424c als Hauptfarbe,
warmes Papierweiß, Akzente in Eichenhonig, Nussbaum, hellem Schiefer und
Ton. Das ganze Theme verwendet ausschließlich diese Rollen, nirgends feste
Farbwerte – auch die E-Mails.

* background – Seitenhintergrund
* surface – Karten, Felder
* surface-muted – gefüllte Felder, Chips
* text – Text
* text-muted – gedämpfter Text
* line – Ränder und Trennlinien
* primary – Buttons, Links, Fokusring
* on-primary – Schrift auf primary (und auf dem Angebots-Badge)
* accent-1 bis accent-4 – Sticker, Kacheln, Badges (accent-1 auch: Textmarker und Mengen-Badge im Warenkorb, accent-2 auch: Bewertungssterne)
* on-accent – Schrift auf den Akzentfarben
* sale – Angebots-Badge und Angebotspreis
* success, warning, error – Hinweise, Fehlermeldungen, Häkchen
* ink – dunkle Fläche für den CTA-Block
* on-ink – Schrift auf ink

Die großen, weichen Farbflecken im Hintergrund mischt das Theme per
color-mix() aus primary, accent-1 und accent-3 mit 12–15 % Deckkraft.
Sie passen sich automatisch an.

Kontraste (WCAG AA): Achte darauf, dass diese Paare mindestens 4,5:1
erreichen – text, text-muted und primary auf background, surface und
surface-muted; on-primary auf primary; on-accent auf accent-1 bis
accent-4; sale auf surface; on-primary auf sale; error auf surface;
on-ink auf ink. accent-2 (Sterne) auf surface braucht mindestens 3:1.
Das Standardschema erfüllt das alle.

Dark Mode (optional): In assets/css/theme.css steht unter
„0. Kurznamen und Dark Mode“ ein auskommentierter Block mit
@media (prefers-color-scheme: dark). Kommentar entfernen und die
Platzhalter durch deine dunklen Farben ersetzen.

== Schriften ==

* Bricolage Grotesque 200–800 (Überschriften)
* Inter 100–900 (Fließtext und Bedienelemente)
* DM Mono 400/500 (Preise, Mengen, Artikelnummern, kleine Labels)

Quelle: Google Fonts, als woff2 im Theme unter assets/fonts/ (Liste und
Herkunft in assets/fonts/README.md). Lizenz: SIL Open Font License 1.1,
die Lizenztexte liegen bei. Es werden keine Anfragen an Google gestellt.

== Block-Styles ==

Im Editor unter „Stile“ am jeweiligen Block:

* Button: Primär (Standard), Soft (Fläche mit Rand), Akzent – gelten auch für alle WooCommerce-Buttons
* Gruppe: Karte (Fläche, Radius 28 px, Schatten, Hover-Anhebung), Glas (halbtransparent mit Weichzeichner), Sticker
* Absatz: Eyebrow (kleine Pille, DM Mono, Großbuchstaben), Sticker (leicht gedrehte Pille in Akzentfarbe), Chip (neutrale Pille)
* Beitrags-Begriffe: Eyebrow
* Bild: Schwebend (große Rundung, Schatten, sanftes Schweben)
* Liste: Häkchen

Textmarker: Markiere in einer Überschrift ein Wort und wähle in der
Werkzeugleiste „Hervorheben“. Es wird ein gedrehter Textmarker-Balken in
accent-1.

Symbole für Chips: Die Zusatzklasse „lw16-icon lw16-icon--truck“ (oder
--return, --lock, --chat, --clock, --heart, --check, --cart) unter
Block → Erweitert → Zusätzliche CSS-Klasse setzt ein Symbol davor.

== Patterns ==

Kategorie „Lindenweg16“ im Block-Inserter:

1. Hero mit Stickern – Eyebrow-Sticker, große Headline mit Marker, zwei Buttons, großes Bild mit zwei schwebenden Stickern
2. Kategorien: Sets und Module – zwei große Kacheln in Akzentfarben mit Bild
3. Produkt-Highlights (Neuheiten) – Product Collection als Karten-Raster mit „Alle ansehen“
4. Vertrauensleiste – vier Chips mit Symbol
5. So einfach geht’s – drei nummerierte Schritte mit gestrichelter Verbindungslinie
6. Kundenstimmen – Karten aus echten Produktbewertungen
7. Newsletter – Karte mit Eingabefeld und Button in einer Pille
8. Dunkler CTA – Fläche ink mit zwei unscharfen Farbflecken
9. Stichwort-Laufband – Pillen in Endlos-Animation, an den Rändern ausgeblendet
10. Häufige Fragen (FAQ) – aufklappbare Fragen und Antworten

Die Startseite nutzt Hero, Vertrauensleiste, Kategorien,
Produkt-Highlights, So einfach geht’s, Kundenstimmen, FAQ, Newsletter und CTA.
Ändern: Website-Editor → Templates → Startseite.

Die Bilder in Hero und Kacheln sind Strichzeichnungen, bis du unter
Design → Lindenweg16 → Fotos der Startseite eigene Fotos wählst (Hero am
besten quadratisch oder hochkant).

== Templates ==

index, front-page, home, single, page, page-landing (Landingpage ohne
Seitentitel), archive, search, 404, archive-product,
taxonomy-product_cat, taxonomy-product_tag, product-search-results,
single-product, page-cart, page-checkout, order-confirmation.

Template-Parts: header, footer, mini-cart.

== WooCommerce ==

* Produktkarte: Bild mit Sale-Sticker oben links und rundem „In den Warenkorb“-Knopf unten rechts (auf Touch-Geräten immer sichtbar)
* Produktseite: Galerie mit Zoom, Lightbox und Slider, Thumbnails als Pillen-Reihe; Varianten als Pillen; Mengenwähler mit − / +; Lieferzeit und drei Vertrauens-Chips; Tabs als Pillen-Umschalter; mobil ein fixierter „In den Warenkorb“-Balken
* Kategorie-Seiten: Filter (WooCommerce-Produktfilter) auf dem Desktop in der Seitenleiste, mobil als ausklappbares Panel
* Mini-Cart: Drawer von rechts mit Glas-Hintergrund und Fortschrittsbalken „Noch X € bis zum kostenlosen Versand“
* Kasse: Bestell-Button „Zahlungspflichtig bestellen“, Checkbox für AGB und Datenschutz, Bestellübersicht auf dem Desktop sticky

Fortschrittsbalken kostenloser Versand: Der Betrag kommt automatisch aus
deiner Versandart „Kostenloser Versand“ (Mindestbestellwert). Gibt es
keine, steht dort der Platzhalter 50 €. Ändern oder ausblenden:
add_filter( 'lindenweg16_free_shipping_threshold', fn() => 0 );

Lieferzeit: Ohne Germanized/German Market zeigt die Produktseite
„Lieferzeit: 2–4 Werktage“. Ändern:
add_filter( 'lindenweg16_delivery_time', fn() => '1–3 Werktage' );

Preis-Hinweis: Ohne Germanized/German Market steht unter jedem Preis
„inkl. MwSt., zzgl. Versand“ mit Link auf die Seite
„versand-und-zahlung“. Andere Seite:
add_filter( 'lindenweg16_shipping_page_url', fn() => home_url( '/versand/' ) );

== Bearbeiten ohne Website-Editor ==

Unter Design → Lindenweg16 findest du alle Wege an einem Ort.

* Startseite: „Startseite als Seite anlegen“ legt eine normale Seite mit allen Abschnitten an und stellt sie als Startseite ein. Danach bearbeitest du Texte, Bilder, FAQ und die Reihenfolge unter Seiten → Startseite. Ohne diese Seite zeigt das Theme seine Standard-Startseite.
* Menüs: Design → Menüs. Menü-Positionen: Hauptmenü (Header), Footer Spalte 1–3. Der Name des Menüs wird zur Spaltenüberschrift im Footer. Ohne zugeordnetes Menü erscheinen die Standard-Links.
* Kundenstimmen: kommen automatisch aus freigegebenen Produktbewertungen (Produkte → Bewertungen) mit mindestens 4 Sternen. Ohne Bewertungen bleibt der Abschnitt unsichtbar. Im Editor gibt es dafür den Block „Kundenstimmen“ (Anzahl, Mindest-Sterne), außerdem den Shortcode [lindenweg16_kundenstimmen anzahl="3" min_sterne="4"].
* FAQ: Pattern „Häufige Fragen (FAQ)“ mit aufklappbaren Fragen (Details-Block). Auf der Startseite unter #faq verlinkbar.

== Germanized und German Market ==

Ist eines der beiden Plugins aktiv, blendet das Theme seine eigenen
Hinweise (Preis-Hinweis, Lieferzeit) aus und überlässt das dem Plugin.

* Germanized: Das Theme setzt dessen Blöcke für Grundpreis, Steuer- und Versandhinweis (und auf der Produktseite die Lieferzeit) automatisch unter jeden Preis. Die Inhalte stellst du in Germanized ein.
* German Market: gibt seine Hinweise selbst am Preis aus.

Die Ausgaben beider Plugins werden nicht versteckt, sondern im Stil des
Themes dargestellt. Beide Plugins setzen auch einen eigenen Text für den
Bestell-Button (bei Germanized z. B. „Jetzt kaufen“), einstellbar im Plugin.

== Newsletter ==

Das Pattern „Newsletter“ funktioniert ohne Plugin, mit Double-Opt-in:
Nach der Anmeldung verschickt WordPress eine Bestätigungsmail; erst der
Klick auf den Link trägt die Adresse ein. Die Liste findest du unter
Werkzeuge → Newsletter, mit CSV-Export (inklusive Abmeldelink je Adresse)
für dein Versand-Tool. Auskunft und Löschung laufen über
Werkzeuge → Personenbezogene Daten exportieren/löschen.

Damit Mails ankommen, sollte deine Website Mails zuverlässig versenden
können (beim Hoster nachfragen).

Abschalten: add_filter( 'lindenweg16_newsletter_enabled', '__return_false' );

== E-Mails ==

Alle E-Mails sehen aus wie die Website – ohne Plugin:

* WooCommerce- und Germanized-Mails (Bestellbestätigung, Rechnung, Konto …): Farben kommen aus deiner Palette (Design → Lindenweg16 → Farben), Rundungen, Pillen-Buttons, Bestellübersicht und Adressen als Karten, Preise in DM Mono. Die Farbeinstellungen unter WooCommerce → Einstellungen → E-Mails werden dabei vom Theme überschrieben. Im Kopf steht dein Website-Logo, solange dort kein eigenes Kopfbild gesetzt ist.
* Reine Text-Mails (WordPress, z. B. Passwort zurücksetzen, die Newsletter-Bestätigung, andere Plugins) werden ins selbe Layout verpackt. Links werden klickbar, ein Link allein in einer Zeile wird zum Button.
* Mails des Lindenweg16-ERP-Plugins übernehmen das Layout ebenfalls.
* HTML-Mails anderer Plugins (Complianz, Formular-Plugins …): Das Theme nimmt nur den Inhalt und setzt ihn in den Lindenweg16-Rahmen – fremde Farben, Breiten und Hintergründe fallen weg. WPForms wird dafür auf seine Klartext-Vorlage gestellt.
* Unverändert bleiben nur multipart-Mails (z. B. Newsletter-Dienste mit Text- und HTML-Teil). Belege, die PayPal oder Stripe selbst verschicken, kommen von deren Servern und lassen sich nicht gestalten.

Schriften: Mailprogramme zeigen Webfonts nur teilweise (z. B. Apple Mail). Sonst greift eine Systemschrift; Farben und Formen bleiben gleich. In Outlook für Windows erscheinen Rundungen eckig.

Den Fußtext (z. B. Adresse) stellst du unter WooCommerce → Einstellungen → E-Mails → „Fußzeilentext“ ein.

Abschalten: add_filter( 'lindenweg16_email_design', '__return_false' );

== Caching (LiteSpeed Cache) ==

Warenkorb, Kasse und Mein Konto nimmt das Theme automatisch von der Seitenoptimierung von LiteSpeed Cache aus (JS zusammenfassen/verzögern, Unique CSS). Die Cart- und Checkout-Blöcke von WooCommerce brauchen ihre Skripte in der richtigen Reihenfolge und bleiben sonst im Ladezustand hängen. Alle anderen Seiten werden weiter optimiert.

Einige LiteSpeed-Einstellungen gibt das Theme über LiteSpeeds eigene Filter vor; LiteSpeed zeigt sie in seinen Einstellungen als „per Filter festgelegt“ an:

* CSS zusammenfassen an: eine Datei statt rund 30 blockiert das erste Bild kürzer.
* Gast-Optimierung aus (der Gastmodus selbst bleibt an). Sie erzwang beim ersten Besuch JS verzögern und UCSS – mit JavaScript-Fehlern und ungestylter Seite.
* Kein „Unique CSS“ (UCSS) und kein asynchrones CSS: UCSS behält nur das CSS, das beim Erzeugen sichtbar war; Handy-Menü, Mini-Cart und Filter fehlen dann.
* JS nicht zusammenfassen, höchstens aufschieben (defer) statt verzögern.
* Kein Lazy Load per JavaScript (auch nicht von Smush): WordPress lädt Bilder nativ nach und gibt dem Produktfoto Vorrang.
* Emoji-Skript entfernt; Jetpacks WooCommerce-Analytics wird nicht minimiert (sonst „Loading chunk failed“).

Alles andere – Cache, CSS/JS minimieren, Bildoptimierung, CDN – bleibt, wie es in LiteSpeed eingestellt ist. Anpassen oder abschalten über den Filter lindenweg16_litespeed_settings (siehe inc/litespeed.php).

Nach einem Theme-Update in LiteSpeed Cache einmal „Alles leeren“ (Toolbox → Leeren → Alles leeren), sonst liefert der Cache noch die alten Seiten aus.

== Barrierefreiheit ==

* Sichtbarer Fokusring in primary an allen bedienbaren Elementen
* Skip-Link zum Inhalt (von WordPress)
* Bei „Bewegung reduzieren“ im Betriebssystem sind alle Animationen aus, das Laufband steht still
* Inhalte blenden beim Laden ein, der Endzustand ist immer voll sichtbar
* Bedienflächen mindestens 44 × 44 px

== Übersetzung ==

Alle Texte sind übersetzbar (Textdomain lindenweg16). Die Vorlage liegt
in languages/lindenweg16.pot.

== Für Entwickler ==

Filter: lindenweg16_free_shipping_threshold, lindenweg16_delivery_time,
lindenweg16_shipping_page_url, lindenweg16_has_legal_plugin,
lindenweg16_germanized_price_blocks, lindenweg16_newsletter_enabled.
Aktion: lindenweg16_newsletter_confirmed (bei jeder bestätigten Adresse).

== Changelog ==

= 1.8.0 =
* Gestaltung der Prämien-Teile (Konto-Übersicht, Prämienkarte, Punkte in Warenkorb und Kasse, Einlösen, Bewertungsfotos, Geburtstagsfeld) liegt jetzt im ERP-Plugin 0.57.0 – sie funktioniert damit auch, wenn das Theme noch nicht aktualisiert ist; das Theme behält Menü-Seitenleiste und WooCommerce-Korrekturen

= 1.7.0 =
* Prämienprogramm (ERP-Plugin 0.56.0): Stufen-Leiste in der Prämienkarte, Hinweis „Mit dieser Bestellung sammelst du …“ in Warenkorb und Kasse

= 1.6.0 =
* Prämienprogramm (ERP-Plugin 0.55.0): Hinweis auf laufende Aktionen in der Konto-Übersicht, „Prämienpunkte sammeln“ auf der Produktseite, Fotos unter Bewertungen und Upload-Feld im Bewertungsformular, Geburtstagsfeld in den Kontodetails

= 1.5.0 =
* Mein Konto neu: ab Tablet-Breite Menü als Seitenleiste mit Symbolen, aktiver Punkt dunkel; Inhalte als ruhige Karten mit Rand
* Übersicht (kommt vom ERP-Plugin) gestaltet: dunkle Prämienkarte mit Punktestand, Gegenwert, Fortschritt, „So sammelst du“ und Empfehlungslink; Kennzahlen, letzte Bestellung, Adressen
* Hinweise: Text steht wieder neben dem Symbol statt darunter; Info-Hinweise in Akzentfarbe
* Adressen-Seite: Titel und „Bearbeiten“ in einer Zeile, Adresse aufrecht statt kursiv
* Wunschliste: Karten im Theme-Stil, Knöpfe nebeneinander; Buttons im Konto nicht mehr unterstrichen

= 1.4.0 =
* Neues Template „Über uns“ (greift für die Seite mit dem Slug „ueber-uns“ automatisch, sonst in den Seiten-Einstellungen wählbar): Hero mit Beitragsbild (sonst Produktfoto) und Stickern, Text-Laufband, Geschichte aus dem Seiteninhalt (erste Überschrift links, Text rechts), Werkstatt als Liste mit Linien, Abschlusszeile zu Shop und Anfragen
* Links zur Kontaktseite finden auch den alten Slug „contact-us“

= 1.3.0 =
* Neues Template „Kontakt“ (Seite mit Slug „kontakt“ automatisch, sonst in den Seiten-Einstellungen wählbar): Einleitung mit Kontakt-Chips, vier Themen-Kacheln, Formular aus dem Seiteninhalt (z. B. WPForms-Block), Direktkontakt und kurze Antworten
* E-Mail, Telefon und Adresse kommen aus den Firmendaten des ERP-Plugins (Rückfall: WooCommerce-Shopadresse, Admin-E-Mail); ohne Telefonnummer wird keine angezeigt
* Neue Symbole für Chips: Mail, Telefon, Pfeil nach unten

= 1.2.0 =
* E-Mails: persönliche Mails aus der Mailbox des ERP-Plugins (ab 0.18.0) im Website-Look – Text, Signatur in einer eigenen Zeile unter einer feinen Linie, darunter das Zitat der Ursprungsmail
* E-Mails: Zeilen, die mit „>“ beginnen, werden als Zitatblock mit Randlinie dargestellt

= 1.1.1 =
* E-Mails offensiver im Website-Look: Sticker „Handgemacht in Haste“ über der Überschrift, letztes Wort mit Textmarker, größere Überschrift in Bricolage, Spaltenköpfe in DM Mono, Gesamtsumme markiert, dunkler Footer
* Warenkorb-Erinnerung (ERP-Plugin ab 0.15.1): Artikel als Karte mit Bild, Menge und Preis
* E-Mails: Zeilen wie „Jetzt bestellen: https://…“ werden ein Knopf „Jetzt bestellen“ (Warenkorb-Erinnerung, Warteliste …)
* Warenkorb: „Warenkorb-Summe“ wieder als eine Karte (Überschrift, Gutschein, Summe); das Gutschein-Feld ist keine eigene weiße Pille mehr

= 1.1.0 =
* Alle Mails im CI: auch HTML-Mails anderer Plugins bekommen den Lindenweg16-Rahmen
* Meta-Beschreibung für Startseite, Shop, Produkte, Seiten und Kategorien (ohne SEO-Plugin); Text der Startseite unter Design → Lindenweg16
* Schneller sichtbar: Einblend-Animation ohne Ausblenden (LCP), CSS zusammengefasst
* Zahlungsarten im Footer unter Design → Lindenweg16 pflegbar
* Farbschema passend zum Logo; alle Farben unter Design → Lindenweg16 anpassbar (mit Kontrastprüfung)
* Fotos der Startseite (Hero, Kacheln) unter Design → Lindenweg16 wählbar
* E-Mails im Look der Website
* LiteSpeed: Vorgaben gegen JavaScript-Fehler und ungestylte Seiten, schnellere Bilder
* Optik-Korrekturen in Shop, Warenkorb, Kasse, Konto und Handy-Menü
* CSS und JS bekommen nach jedem Update eine neue Adresse (kein veralteter Browser-Cache)

= 1.0.0 =
* Erste Version.

== Copyright ==

Lindenweg16, (C) 2026 Lindenweg16. Lizenz: GPLv2 oder später.

Schriften: Bricolage Grotesque (C) The Bricolage Grotesque Project
Authors, Inter (C) The Inter Project Authors, DM Mono (C) The DM Mono
Project Authors – jeweils SIL Open Font License 1.1.

Illustrationen und Symbole in assets/images und assets/css: eigene Arbeit,
GPLv2 oder später.
