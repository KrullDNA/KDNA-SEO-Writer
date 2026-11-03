/**
 * KDNA SEO Writer edit screen panels.
 *
 * One script for four places:
 * - block editor: a "SEO Writer" panel in the document sidebar,
 * - classic editor and term edit screens: the server-printed box,
 * - Elementor editor: the box in Page Settings > SEO Writer.
 *
 * Generate now only queues a background job, then this script checks back
 * every five seconds until the job has run. It also adds the save guard's
 * "screen opened at" field to Yoast's form in the block editor and Elementor,
 * so saving the page never wipes values the plugin saved meanwhile.
 */
( function () {
	'use strict';

	var cfg = window.kdnaSwEditor || {};
	var i18n = cfg.i18n || {};
	var POLL_MS = 5000;
	var POLL_LIMIT = 72; // Six minutes.

	/**
	 * Calls one of the plugin's REST routes.
	 *
	 * @param {string} path   Path after the namespace.
	 * @param {Object} [opts] apiFetch options.
	 * @return {Promise<Object>} Response.
	 */
	function api( path, opts ) {
		return window.wp.apiFetch( Object.assign( { path: cfg.restPath + path }, opts || {} ) );
	}

	/**
	 * Builds the status route for an item.
	 *
	 * @param {Object} item type, id and taxonomy.
	 * @return {string} Path.
	 */
	function statusPath( item ) {
		var path = '/generate/' + item.id + '?type=' + encodeURIComponent( item.type );
		if ( item.taxonomy ) {
			path += '&taxonomy=' + encodeURIComponent( item.taxonomy );
		}
		return path;
	}

	/**
	 * Sends an action (generate or unlock) for an item.
	 *
	 * @param {Object} item   type, id and taxonomy.
	 * @param {string} action generate or unlock.
	 * @param {string} [field] Field group, for unlock.
	 * @return {Promise<Object>} New status.
	 */
	function act( item, action, field ) {
		return api( '/generate/' + item.id, {
			method: 'POST',
			data: { type: item.type, taxonomy: item.taxonomy || '', action: action, field: field || '' },
		} );
	}

	/**
	 * Adds the save guard's hidden field to a form once it appears.
	 *
	 * @param {string} selector Form selector.
	 */
	function addGuardField( selector ) {
		var tries = 0;
		var timer = window.setInterval( function () {
			var form = document.querySelector( selector );
			tries++;
			if ( form && ! form.querySelector( 'input[name="' + cfg.guardName + '"]' ) ) {
				var input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = cfg.guardName;
				input.value = String( cfg.loadedAt );
				form.appendChild( input );
			}
			if ( form || tries > 120 ) {
				window.clearInterval( timer );
			}
		}, 500 );
	}

	/* ------------------------------------------------------------------ */
	/* Classic editor, term screen and Elementor: the server-printed box. */
	/* ------------------------------------------------------------------ */

	/**
	 * Redraws a box from a status response.
	 *
	 * @param {Element} box     The box.
	 * @param {Object}  data    Status from the server.
	 * @param {string}  message Message to show.
	 */
	function renderBox( box, data, message ) {
		var list = box.querySelector( '.kdna-sw-fields' );
		var msg = box.querySelector( '.kdna-sw-msg' );
		var button = box.querySelector( '[data-kdna-sw-generate]' );

		if ( list ) {
			list.textContent = '';
			( data.fields || [] ).forEach( function ( field ) {
				var li = document.createElement( 'li' );
				li.className = 'kdna-sw-field is-' + field.status;

				var label = document.createElement( 'span' );
				label.className = 'kdna-sw-field-label';
				label.textContent = field.label;

				var status = document.createElement( 'span' );
				status.className = 'kdna-sw-field-status';
				status.textContent = field.text;

				li.appendChild( label );
				li.appendChild( document.createTextNode( ' ' ) );
				li.appendChild( status );

				if ( 'locked' === field.status ) {
					var unlock = document.createElement( 'button' );
					unlock.type = 'button';
					unlock.className = 'button-link kdna-sw-unlock';
					unlock.setAttribute( 'data-kdna-sw-unlock', field.group );
					unlock.textContent = i18n.unlock;
					li.appendChild( document.createTextNode( ' ' ) );
					li.appendChild( unlock );
				}
				list.appendChild( li );
			} );
		}

		if ( button ) {
			button.disabled = !! data.job;
		}
		if ( msg ) {
			msg.textContent = message || ( data.job ? i18n.queued : ( data.last && data.last.message ) || '' );
		}
	}

	/**
	 * Reads the item a box belongs to.
	 *
	 * @param {Element} box The box.
	 * @return {Object} type, id and taxonomy.
	 */
	function boxItem( box ) {
		return {
			type: box.getAttribute( 'data-kdna-sw-type' ) || 'post',
			id: parseInt( box.getAttribute( 'data-kdna-sw-id' ), 10 ),
			taxonomy: box.getAttribute( 'data-kdna-sw-taxonomy' ) || '',
		};
	}

	/**
	 * Checks back until the box's job has run.
	 *
	 * @param {Element} box   The box.
	 * @param {number}  [count] Checks so far.
	 */
	function pollBox( box, count ) {
		count = count || 0;
		if ( count >= POLL_LIMIT ) {
			return;
		}
		window.setTimeout( function () {
			api( statusPath( boxItem( box ) ) ).then( function ( data ) {
				if ( data.job ) {
					renderBox( box, data );
					pollBox( box, count + 1 );
				} else {
					var done = ( data.last && data.last.message ) || '';
					if ( 'saved' === ( data.last && data.last.status ) && 'classic' !== cfg.mode ) {
						done += ' ' + i18n.reloadNote;
					}
					renderBox( box, data, done );
				}
			} ).catch( function () {
				pollBox( box, count + 1 );
			} );
		}, POLL_MS );
	}

	// One click handler for every box, including boxes Elementor adds later.
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}

		var generate = target.closest( '[data-kdna-sw-generate]' );
		var unlock = target.closest( '[data-kdna-sw-unlock]' );
		var box = target.closest( '.kdna-sw-box' );
		if ( ! box || ( ! generate && ! unlock ) ) {
			return;
		}

		event.preventDefault();
		var item = boxItem( box );

		if ( generate ) {
			generate.disabled = true;
			setMessage( box, i18n.working );
			act( item, 'generate' ).then( function ( data ) {
				renderBox( box, data, data.message );
				pollBox( box );
			} ).catch( function ( error ) {
				generate.disabled = false;
				api( statusPath( item ) ).then( function ( data ) {
					renderBox( box, data, ( error && error.message ) || i18n.error );
				} );
			} );
		} else {
			act( item, 'unlock', unlock.getAttribute( 'data-kdna-sw-unlock' ) ).then( function ( data ) {
				renderBox( box, data, data.message );
			} ).catch( function ( error ) {
				setMessage( box, ( error && error.message ) || i18n.error );
			} );
		}
	} );

	/**
	 * Shows a message in a box without redrawing the field list.
	 *
	 * @param {Element} box     The box.
	 * @param {string}  message Message.
	 */
	function setMessage( box, message ) {
		var msg = box.querySelector( '.kdna-sw-msg' );
		if ( msg ) {
			msg.textContent = message || '';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Elementor: guard field on Yoast's panel form.                      */
	/* ------------------------------------------------------------------ */

	if ( 'elementor' === cfg.mode ) {
		addGuardField( '#yoast-form' );

		// Boxes already queued when the editor opened keep checking back.
		window.setTimeout( function () {
			document.querySelectorAll( '.kdna-sw-box' ).forEach( function ( box ) {
				var button = box.querySelector( '[data-kdna-sw-generate]' );
				if ( button && button.disabled ) {
					pollBox( box );
				}
			} );
		}, 1000 );
	}

	if ( 'classic' === cfg.mode ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			document.querySelectorAll( '.kdna-sw-box' ).forEach( function ( box ) {
				api( statusPath( boxItem( box ) ) ).then( function ( data ) {
					renderBox( box, data );
					if ( data.job ) {
						pollBox( box );
					}
				} );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Block editor: document sidebar panel.                              */
	/* ------------------------------------------------------------------ */

	if ( 'block' !== cfg.mode ) {
		return;
	}

	addGuardField( 'form.metabox-base-form' );

	var wp = window.wp;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel || ! wp.plugins ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var Button = wp.components.Button;
	var useSelect = wp.data.useSelect;

	/**
	 * The SEO Writer panel.
	 *
	 * @return {Object} Element.
	 */
	function SeoWriterPanel() {
		var post = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				id: editor.getCurrentPostId(),
				status: editor.getEditedPostAttribute( 'status' ),
				isNew: editor.isEditedPostNew(),
			};
		}, [] );

		var dataState = useState( null );
		var data = dataState[ 0 ];
		var setData = dataState[ 1 ];
		var msgState = useState( '' );
		var message = msgState[ 0 ];
		var setMessage = msgState[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var polls = useRef( 0 );
		var item = { type: 'post', id: post.id, taxonomy: '' };

		function load( afterJob ) {
			return api( statusPath( item ) ).then( function ( next ) {
				setData( next );
				if ( next.job && polls.current < POLL_LIMIT ) {
					polls.current++;
					window.setTimeout( function () {
						load( true );
					}, POLL_MS );
				} else if ( afterJob ) {
					var done = ( next.last && next.last.message ) || '';
					if ( next.last && 'saved' === next.last.status ) {
						done += ' ' + i18n.reloadNote;
					}
					setMessage( done );
				}
				return next;
			} );
		}

		useEffect( function () {
			if ( post.id ) {
				load( false );
			}
		}, [ post.id ] );

		function generate() {
			setBusy( true );
			setMessage( i18n.working );
			act( item, 'generate' ).then( function ( next ) {
				setData( next );
				setMessage( next.message || i18n.queued );
				polls.current = 0;
				window.setTimeout( function () {
					load( true );
				}, POLL_MS );
			} ).catch( function ( error ) {
				setMessage( ( error && error.message ) || i18n.error );
			} ).finally( function () {
				setBusy( false );
			} );
		}

		function unlock( group ) {
			act( item, 'unlock', group ).then( function ( next ) {
				setData( next );
				setMessage( next.message || '' );
			} ).catch( function ( error ) {
				setMessage( ( error && error.message ) || i18n.error );
			} );
		}

		var children = [];

		if ( data ) {
			children.push(
				el( 'ul', { className: 'kdna-sw-fields', key: 'fields' }, ( data.fields || [] ).map( function ( field ) {
					return el( 'li', { className: 'kdna-sw-field is-' + field.status, key: field.group },
						el( 'span', { className: 'kdna-sw-field-label' }, field.label ),
						' ',
						el( 'span', { className: 'kdna-sw-field-status' }, field.text ),
						'locked' === field.status ? el( Button, { variant: 'link', className: 'kdna-sw-unlock', onClick: function () {
							unlock( field.group );
						} }, i18n.unlock ) : null
					);
				} ) )
			);

			if ( data.problem ) {
				children.push( el( 'p', { className: 'kdna-sw-msg', key: 'problem' }, data.problem ) );
			} else {
				children.push( el( 'p', { key: 'button' },
					el( Button, { variant: 'secondary', onClick: generate, disabled: busy || data.job || post.isNew, isBusy: busy || data.job }, i18n.generate )
				) );
				var text = message || ( data.job ? i18n.queued : ( data.last && data.last.message ) || '' );
				if ( post.isNew ) {
					text = i18n.saveFirst;
				}
				children.push( el( 'p', { className: 'kdna-sw-msg', role: 'status', 'aria-live': 'polite', key: 'msg' }, text ) );
				if ( data.fields && data.fields.some( function ( field ) {
					return 'waiting' === field.status;
				} ) ) {
					children.push( el( 'p', { key: 'review' }, el( 'a', { href: data.review_url }, i18n.review ) ) );
				}
			}
			children.push( el( 'p', { className: 'description', key: 'mode' }, ( i18n.modes || {} )[ data.mode ] || '' ) );
		}

		return el( Panel, { name: 'kdna-seo-writer', title: i18n.title, className: 'kdna-sw-panel' }, el( 'div', { className: 'kdna-sw-box-block' }, children ) );
	}

	wp.plugins.registerPlugin( 'kdna-seo-writer', { render: SeoWriterPanel, icon: null } );
}() );
