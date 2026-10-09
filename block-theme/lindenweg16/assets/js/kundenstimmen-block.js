/**
 * Editor-Teil des Blocks „Kundenstimmen“ – ohne Build-Schritt.
 * Die Ausgabe kommt vom Server (inc/kundenstimmen.php).
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var ServerSideRender = wp.serverSideRender;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;

	wp.blocks.registerBlockType( 'lindenweg16/kundenstimmen', {
		apiVersion: 3,
		title: __( 'Kundenstimmen', 'lindenweg16' ),
		icon: 'format-quote',
		category: 'widgets',
		edit: function ( props ) {
			var attrs = props.attributes;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Einstellungen', 'lindenweg16' ) },
						el( RangeControl, {
							label: __( 'Anzahl', 'lindenweg16' ),
							min: 1,
							max: 9,
							value: attrs.anzahl,
							onChange: function ( v ) {
								props.setAttributes( { anzahl: v } );
							},
						} ),
						el( RangeControl, {
							label: __( 'Mindestens Sterne', 'lindenweg16' ),
							min: 1,
							max: 5,
							value: attrs.minSterne,
							onChange: function ( v ) {
								props.setAttributes( { minSterne: v } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'lindenweg16/kundenstimmen',
					attributes: attrs,
					EmptyResponsePlaceholder: function () {
						return el(
							'p',
							{ className: 'lw16-kundenstimmen__empty' },
							__( 'Kundenstimmen: Noch keine freigegebenen Bewertungen mit genug Sternen – der Abschnitt bleibt auf der Website unsichtbar, bis es welche gibt (Produkte → Bewertungen).', 'lindenweg16' )
						);
					},
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
