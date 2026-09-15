/**
 * BLT Events - Block editor blocks.
 *
 * Thin editor wrappers around the two shortcodes. Rendering happens
 * server-side (ServerSideRender), so what the editor shows is what the
 * site prints. No build step: plain wp.* globals.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var components = wp.components;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var ComboboxControl = components.ComboboxControl;
	var Placeholder = components.Placeholder;
	var Spinner = components.Spinner;
	var ServerSideRender = wp.serverSideRender;
	var useSelect = wp.data.useSelect;
	var data = window.bltEventsBlocks || {};

	/* ------------------------------------------------------------------
	 * Events Calendar
	 * ---------------------------------------------------------------- */
	registerBlockType( 'blt-events/calendar', {
		edit: function ( props ) {
			var atts = props.attributes;
			var blockProps = useBlockProps();

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Layout', 'blt-events' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'View', 'blt-events' ),
							value: atts.view,
							options: [
								{ value: 'list', label: __( 'List', 'blt-events' ) },
								{ value: 'grid', label: __( 'Grid', 'blt-events' ) },
								{ value: 'calendar', label: __( 'Month calendar', 'blt-events' ) }
							],
							onChange: function ( value ) { props.setAttributes( { view: value } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show view switcher', 'blt-events' ),
							help: __( 'Lets visitors flip between list, grid and month.', 'blt-events' ),
							checked: !! atts.switcher,
							onChange: function ( value ) { props.setAttributes( { switcher: value } ); }
						} ),
						atts.view !== 'calendar' && el( RangeControl, {
							label: __( 'Maximum events', 'blt-events' ),
							value: atts.limit,
							min: 1,
							max: 100,
							onChange: function ( value ) { props.setAttributes( { limit: value } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Filter', 'blt-events' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Category', 'blt-events' ),
							value: atts.category,
							options: data.categories || [ { value: '', label: __( 'All categories', 'blt-events' ) } ],
							onChange: function ( value ) { props.setAttributes( { category: value } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Featured events only', 'blt-events' ),
							checked: !! atts.featured,
							onChange: function ( value ) { props.setAttributes( { featured: value } ); }
						} ),
						atts.view !== 'calendar' && el( ToggleControl, {
							label: __( 'Include past events', 'blt-events' ),
							checked: !! atts.past,
							onChange: function ( value ) { props.setAttributes( { past: value } ); }
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'blt-events/calendar',
						attributes: atts
					} )
				)
			);
		},
		save: function () {
			return null;
		}
	} );

	/* ------------------------------------------------------------------
	 * Registration form
	 * ---------------------------------------------------------------- */
	registerBlockType( 'blt-events/registration-form', {
		edit: function ( props ) {
			var atts = props.attributes;
			var blockProps = useBlockProps();

			var events = useSelect( function ( select ) {
				return select( 'core' ).getEntityRecords( 'postType', 'event', {
					per_page: 100,
					status: 'publish',
					orderby: 'title',
					order: 'asc'
				} );
			}, [] );

			var options = ( events || [] ).map( function ( event ) {
				return {
					value: String( event.id ),
					label: ( event.title && event.title.rendered ) ? event.title.rendered.replace( /<[^>]+>/g, '' ) : '#' + event.id
				};
			} );

			var picker = el( ComboboxControl, {
				label: __( 'Event', 'blt-events' ),
				value: atts.eventId ? String( atts.eventId ) : '',
				options: options,
				onChange: function ( value ) { props.setAttributes( { eventId: parseInt( value, 10 ) || 0 } ); }
			} );

			var body;
			if ( events === null ) {
				body = el( Placeholder, { label: __( 'Event Registration Form', 'blt-events' ) }, el( Spinner ) );
			} else if ( ! atts.eventId ) {
				body = el(
					Placeholder,
					{
						icon: 'tickets-alt',
						label: __( 'Event Registration Form', 'blt-events' ),
						instructions: __( 'Pick the event this form registers people for.', 'blt-events' )
					},
					el( 'div', { style: { width: '100%' } }, picker )
				);
			} else {
				body = el( ServerSideRender, {
					block: 'blt-events/registration-form',
					attributes: atts
				} );
			}

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el( PanelBody, { title: __( 'Event', 'blt-events' ), initialOpen: true }, picker )
				),
				el( 'div', blockProps, body )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
