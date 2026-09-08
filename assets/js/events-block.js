/**
 * Block „Nächste Events" für den Seiten-Editor.
 *
 * Bewusst ohne JSX und ohne Build-Schritt geschrieben: Das Plugin kommt
 * ohne Build-Werkzeug aus, und ein einzelner Block wäre kein guter Grund,
 * damit anzufangen. wp.element.createElement leistet dasselbe wie JSX,
 * es liest sich nur etwas sperriger.
 *
 * Die Vorschau im Editor rendert der Server über denselben Weg wie die
 * fertige Seite - so zeigt der Editor, was später dasteht, statt einer
 * nachgebauten Ähnlichkeit.
 */
(function (blocks, element, blockEditor, components, i18n, serverSideRender) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;

	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var Notice = components.Notice;

	var ServerSideRender = serverSideRender;

	// Vom Server mitgegebene Werte: die vorhandenen Eventtypen und die
	// Grenzen der Wartezeit. So steht dieselbe Regel nicht zweimal da.
	var daten = window.LW16EventsBlock || {};
	var typen = daten.typen || [ { label: __( 'Alle Typen', 'lindenweg16' ), value: '' } ];
	var grenzen = daten.intervall || { min: 4, max: 60, standard: 7 };

	blocks.registerBlockType('lindenweg16/events', {
		edit: function (props) {
			var attr = props.attributes;
			var setzen = props.setAttributes;

			var einstellungen = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Auswahl', 'lindenweg16' ), initialOpen: true },
					el(TextControl, {
						label: __( 'Überschrift', 'lindenweg16' ),
						help: __( 'Leer lassen, wenn die Überschrift schon woanders steht.', 'lindenweg16' ),
						value: attr.titel,
						onChange: function (wert) { setzen({ titel: wert }); }
					}),
					el(RangeControl, {
						label: __( 'Wie viele Events höchstens', 'lindenweg16' ),
						value: attr.anzahl,
						min: 1,
						max: 20,
						onChange: function (wert) { setzen({ anzahl: wert || 1 }); }
					}),
					el(SelectControl, {
						label: __( 'Eventtyp', 'lindenweg16' ),
						value: attr.typ,
						options: typen,
						onChange: function (wert) { setzen({ typ: wert }); }
					}),
					el(ToggleControl, {
						label: __( 'Link auf alle Termine zeigen', 'lindenweg16' ),
						checked: attr.alleLink,
						onChange: function (wert) { setzen({ alleLink: wert }); }
					})
				),
				el(
					PanelBody,
					{ title: __( 'Weiterblättern', 'lindenweg16' ), initialOpen: false },
					el(ToggleControl, {
						label: __( 'Von allein weiterblättern', 'lindenweg16' ),
						help: __( 'Besucher können jederzeit pausieren. Wer im Betriebssystem weniger Bewegung eingestellt hat, bekommt die Reihe still.', 'lindenweg16' ),
						checked: attr.autoplay,
						onChange: function (wert) { setzen({ autoplay: wert }); }
					}),
					attr.autoplay
						? el(RangeControl, {
							label: __( 'Wartezeit je Event (Sekunden)', 'lindenweg16' ),
							value: attr.intervall,
							min: grenzen.min,
							max: grenzen.max,
							onChange: function (wert) { setzen({ intervall: wert || grenzen.standard }); }
						})
						: null
				)
			);

			var vorschau = ServerSideRender
				? el(ServerSideRender, {
					block: 'lindenweg16/events',
					attributes: attr
				})
				: el(
					Notice,
					{ status: 'info', isDismissible: false },
					__( 'Die Vorschau steht in dieser WordPress-Fassung nicht zur Verfügung. Auf der Seite selbst erscheint die Reihe trotzdem.', 'lindenweg16' )
				);

			// Der Editor soll die Reihe zeigen, aber nicht bedienbar machen -
			// ein Klick soll den Block auswaehlen, nicht einem Eventlink folgen.
			return el(
				'div',
				useBlockProps({ className: 'lw16-events-block-editor' }),
				einstellungen,
				el('div', { className: 'lw16-events-block-editor__schirm' }, vorschau)
			);
		},

		// Dynamischer Block: Die Ausgabe kommt bei jedem Seitenaufruf frisch
		// vom Server. Gespeichertes HTML trüge sonst die Teilnehmerzahlen von
		// gestern im Beitrag mit sich herum.
		save: function () {
			return null;
		}
	});
})(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n,
	window.wp.serverSideRender
);
