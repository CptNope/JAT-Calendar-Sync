/*
 * Appointment Booking block (no build step).
 *
 * The editor shows the real widget with sample times, styled exactly as it will be on
 * the page. Appearance controls here override Appointments → Settings → Appearance for
 * this block only; "Default" keeps the site-wide setting.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var useRef = element.useRef;
	var useEffect = element.useEffect;
	var __ = i18n.__;
	var data = window.jatcsEditor || {};

	function luminance( hex ) {
		var m = /^#?([0-9a-f]{6})/i.exec( hex || '' );
		if ( ! m ) {
			return 0;
		}
		var c = [ 0, 2, 4 ].map( function ( i ) {
			var v = parseInt( m[ 1 ].substr( i, 2 ), 16 ) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
	}

	function readableOn( bg ) {
		var l = luminance( bg );
		return ( 1.05 / ( l + 0.05 ) ) >= ( ( l + 0.05 ) / ( luminance( '#111111' ) + 0.05 ) ) ? '#ffffff' : '#111111';
	}

	// Site-wide styles plus this block's overrides, as CSS variables and classes.
	function styleFor( a ) {
		var vars = Object.assign( {}, data.vars || {} );
		var classes = ( data.classes || [] ).slice();
		var theme = data.theme || {};

		if ( a.accent ) {
			vars[ '--jatcs-accent' ] = a.accent;
			vars[ '--jatcs-on-accent' ] = readableOn( a.accent );
		}
		if ( a.surface ) {
			vars[ '--jatcs-surface' ] = a.surface;
		}
		if ( a.radius ) {
			vars[ '--jatcs-radius' ] = a.radius === 'auto' ? theme.radius || '10px' : ( data.radii || {} )[ a.radius ] || vars[ '--jatcs-radius' ];
		}
		if ( a.density ) {
			vars[ '--jatcs-space' ] = ( data.spaces || {} )[ a.density ] || '1';
			classes = classes.filter( function ( c ) {
				return c.indexOf( 'jatcs--density-' ) !== 0;
			} ).concat( 'jatcs--density-' + a.density );
		}
		if ( a.headings === 'inherit' ) {
			delete vars[ '--jatcs-heading-font' ];
		} else if ( a.headings === 'theme' && theme.headingFont ) {
			vars[ '--jatcs-heading-font' ] = theme.headingFont;
		}
		if ( a.buttons ) {
			classes = classes.filter( function ( c ) {
				return c.indexOf( 'jatcs--buttons-' ) !== 0;
			} ).concat( 'jatcs--buttons-' + a.buttons );
		}
		return { vars: vars, classes: classes };
	}

	function Preview( props ) {
		var ref = useRef( null );
		var a = props.attributes;

		// Mount (and remount when the heading changes, since the widget draws it).
		useEffect( function () {
			var node = ref.current;
			if ( ! node || ! window.JATCSBooking || ! data.config ) {
				return;
			}
			var cfg = Object.assign( {}, data.config, { heading: a.heading || '', demo: true } );
			if ( a.type ) {
				var only = ( data.config.types || [] ).filter( function ( t ) {
					return t.id === a.type;
				} );
				cfg.types = only.length ? only : cfg.types;
			}
			node.className = 'jatcs';
			node.id = node.id || 'jatcs-editor-' + Math.random().toString( 36 ).slice( 2, 8 );
			window.JATCSBooking.mount( node, cfg );
		}, [ a.heading, a.type ] );

		// Restyle live.
		useEffect( function () {
			var node = ref.current;
			if ( ! node ) {
				return;
			}
			var s = styleFor( a );
			node.removeAttribute( 'style' );
			Object.keys( s.vars ).forEach( function ( k ) {
				node.style.setProperty( k, s.vars[ k ] );
			} );
			Array.prototype.slice.call( node.classList ).forEach( function ( c ) {
				if ( c.indexOf( 'jatcs--buttons-' ) === 0 || c.indexOf( 'jatcs--density-' ) === 0 ) {
					node.classList.remove( c );
				}
			} );
			s.classes.forEach( function ( c ) {
				node.classList.add( c );
			} );
			if ( ! a.accent && window.JATCSBooking ) {
				window.JATCSBooking.probe( node );
			}
			var submit = node.querySelector( '.jatcs__submit' );
			if ( submit ) {
				submit.classList.toggle( 'wp-element-button', node.classList.contains( 'jatcs--buttons-theme' ) && node.classList.contains( 'jatcs--wp-buttons' ) );
			}
		} );

		return el( 'div', { ref: ref, className: 'jatcs' } );
	}

	var opt = function ( value, label ) {
		return { value: value, label: label };
	};
	var DEFAULT = __( 'Default (site setting)', 'jat-calendar-sync' );

	blocks.registerBlockType( 'jat-calendar-sync/booking', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = function ( key ) {
				return function ( value ) {
					var next = {};
					next[ key ] = value || '';
					props.setAttributes( next );
				};
			};
			var blockProps = blockEditor.useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Booking', 'jat-calendar-sync' ) },
						el( components.TextControl, {
							label: __( 'Heading above the calendar', 'jat-calendar-sync' ),
							help: __( 'Optional, e.g. Book a 30-minute call.', 'jat-calendar-sync' ),
							value: a.heading,
							onChange: set( 'heading' ),
						} ),
						( data.types || [] ).length > 1 && el( components.SelectControl, {
							label: __( 'Appointment types', 'jat-calendar-sync' ),
							value: a.type,
							options: [ opt( '', __( 'All visible types (visitor picks)', 'jat-calendar-sync' ) ) ].concat( ( data.types || [] ).map( function ( t ) {
								return opt( t.id, t.name );
							} ) ),
							onChange: set( 'type' ),
						} ),
						el( 'p', { className: 'components-base-control__help' }, __( 'Types, hours and calendars are set under Appointments. The preview uses sample times.', 'jat-calendar-sync' ) )
					),
					el(
						components.PanelBody,
						{ title: __( 'Calendar style', 'jat-calendar-sync' ), initialOpen: false },
						el( 'p', { className: 'components-base-control__help' }, __( 'Overrides for this block only. Text color, background, font and spacing are under the Styles tab.', 'jat-calendar-sync' ) ),
						el( components.SelectControl, {
							label: __( 'Booking button and fields', 'jat-calendar-sync' ),
							value: a.buttons,
							options: [ opt( '', DEFAULT ), opt( 'theme', __( 'Use my theme’s style', 'jat-calendar-sync' ) ), opt( 'plugin', __( 'Use the calendar’s style', 'jat-calendar-sync' ) ) ],
							onChange: set( 'buttons' ),
						} ),
						el( components.SelectControl, {
							label: __( 'Corners', 'jat-calendar-sync' ),
							value: a.radius,
							options: [
								opt( '', DEFAULT ),
								opt( 'auto', __( 'Match my theme', 'jat-calendar-sync' ) ),
								opt( 'square', __( 'Square', 'jat-calendar-sync' ) ),
								opt( 'soft', __( 'Slightly rounded', 'jat-calendar-sync' ) ),
								opt( 'rounded', __( 'Rounded', 'jat-calendar-sync' ) ),
								opt( 'round', __( 'Very rounded', 'jat-calendar-sync' ) ),
							],
							onChange: set( 'radius' ),
						} ),
						el( components.SelectControl, {
							label: __( 'Spacing', 'jat-calendar-sync' ),
							value: a.density,
							options: [ opt( '', DEFAULT ), opt( 'compact', __( 'Compact', 'jat-calendar-sync' ) ), opt( 'comfortable', __( 'Comfortable', 'jat-calendar-sync' ) ), opt( 'roomy', __( 'Roomy', 'jat-calendar-sync' ) ) ],
							onChange: set( 'density' ),
						} ),
						el( components.SelectControl, {
							label: __( 'Headings', 'jat-calendar-sync' ),
							value: a.headings,
							options: [ opt( '', DEFAULT ), opt( 'theme', __( 'My theme’s heading font', 'jat-calendar-sync' ) ), opt( 'inherit', __( 'Same as the text around it', 'jat-calendar-sync' ) ) ],
							onChange: set( 'headings' ),
						} )
					)
				),
				blockEditor.PanelColorSettings && el(
					blockEditor.InspectorControls,
					null,
					el( blockEditor.PanelColorSettings, {
						title: __( 'Calendar colors', 'jat-calendar-sync' ),
						colorSettings: [
							{
								label: __( 'Accent', 'jat-calendar-sync' ),
								value: a.accent,
								onChange: set( 'accent' ),
							},
							{
								label: __( 'Calendar box', 'jat-calendar-sync' ),
								value: a.surface,
								onChange: set( 'surface' ),
							},
						],
					} )
				),
				el( Preview, { attributes: a } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
