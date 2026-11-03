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

	/**
	 * Builds the keyphrase report route for an item.
	 *
	 * @param {Object} item type, id and taxonomy.
	 * @return {string} Path.
	 */
	function reportPath( item ) {
		return statusPath( item ).replace( '/generate/', '/report/' );
	}

	/**
	 * Copies text to the clipboard.
	 *
	 * @param {string}  text   Text.
	 * @param {Element} [button] Button to show "Copied" on.
	 */
	function copyText( text, button ) {
		var done = function () {
			if ( button ) {
				var label = button.textContent;
				button.textContent = i18n.copied;
				window.setTimeout( function () {
					button.textContent = label;
				}, 1500 );
			}
		};
		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( text ).then( done );
		}
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
					unlock.setAttribute( 'aria-label', i18n.unlock + ' ' + field.label );
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
	 * Draws the keyphrase report inside a box.
	 *
	 * @param {Element} box    The box.
	 * @param {Object}  report Report from the server.
	 */
	function renderReport( box, report ) {
		var body = box.querySelector( '.kdna-sw-report-body' );
		if ( ! body ) {
			return;
		}
		body.textContent = '';
		body.setAttribute( 'data-loaded', '1' );

		var add = function ( parent, tag, className, text ) {
			var node = document.createElement( tag );
			if ( className ) {
				node.className = className;
			}
			if ( text ) {
				node.textContent = text;
			}
			parent.appendChild( node );
			return node;
		};

		if ( report.message ) {
			add( body, 'p', 'kdna-sw-report-msg', report.message );
		}

		var list = add( body, 'ul', 'kdna-sw-report-list' );
		var needsWork = false;
		( report.checks || [] ).forEach( function ( check ) {
			var li = add( list, 'li', 'kdna-sw-report-check is-' + check.status );
			var head = add( li, 'div', 'kdna-sw-report-head' );
			add( head, 'strong', '', check.label );
			add( head, 'span', 'kdna-sw-report-status', ( i18n.statuses || {} )[ check.status ] || check.status );
			add( li, 'p', '', check.message );
			if ( ! check.ok ) {
				needsWork = true;
			}
			if ( check.suggestion ) {
				var tip = add( li, 'div', 'kdna-sw-suggestion' );
				add( tip, 'span', 'kdna-sw-suggestion-label', check.suggestion_label + ':' );
				add( tip, 'code', '', check.suggestion );
				var copy = add( tip, 'button', 'button-link', i18n.copy );
				copy.type = 'button';
				copy.setAttribute( 'aria-label', i18n.copy + ' ' + check.suggestion_label );
				copy.setAttribute( 'data-kdna-sw-copy', check.suggestion );
			}
			if ( check.can_apply ) {
				var apply = add( li, 'button', 'button', i18n.applySlug );
				apply.type = 'button';
				apply.setAttribute( 'data-kdna-sw-apply-slug', '1' );
			}
			if ( check.warning ) {
				add( li, 'p', 'kdna-sw-warning', check.warning );
			}
		} );

		if ( needsWork && ! report.has_suggestions && report.keyphrase ) {
			add( body, 'p', 'description', i18n.noSuggest );
		}
		if ( report.content_note && report.keyphrase ) {
			add( body, 'p', 'description', report.content_note );
		}
	}

	/**
	 * Loads and draws a box's keyphrase report.
	 *
	 * @param {Element} box The box.
	 */
	function loadReport( box ) {
		var body = box.querySelector( '.kdna-sw-report-body' );
		if ( ! body ) {
			return;
		}
		body.setAttribute( 'data-loaded', '1' );
		api( reportPath( boxItem( box ) ) ).then( function ( report ) {
			renderReport( box, report );
		} ).catch( function () {
			body.textContent = i18n.error;
		} );
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
					loadReport( box );
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
		var copy = target.closest( '[data-kdna-sw-copy]' );
		var slug = target.closest( '[data-kdna-sw-apply-slug]' );
		var box = target.closest( '.kdna-sw-box' );
		if ( ! box || ( ! generate && ! unlock && ! copy && ! slug ) ) {
			return;
		}

		event.preventDefault();
		var item = boxItem( box );

		if ( copy ) {
			copyText( copy.getAttribute( 'data-kdna-sw-copy' ), copy );
			return;
		}

		if ( slug ) {
			slug.disabled = true;
			api( '/report/' + item.id, { method: 'POST', data: { type: item.type, taxonomy: item.taxonomy || '' } } ).then( function ( report ) {
				renderReport( box, report );
			} ).catch( function ( error ) {
				slug.disabled = false;
				setMessage( box, ( error && error.message ) || i18n.error );
			} );
			return;
		}

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

		// Elementor draws Page Settings when it is opened, so look for the box
		// every two seconds and load its report the first time it appears.
		window.setInterval( function () {
			document.querySelectorAll( '.kdna-sw-box .kdna-sw-report-body:not([data-loaded])' ).forEach( function ( body ) {
				loadReport( body.closest( '.kdna-sw-box' ) );
			} );
		}, 2000 );

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
				loadReport( box );
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
		var reportState = useState( null );
		var report = reportState[ 0 ];
		var setReport = reportState[ 1 ];
		var item = { type: 'post', id: post.id, taxonomy: '' };

		function loadReportData() {
			return api( reportPath( item ) ).then( setReport ).catch( function () {} );
		}

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
					loadReportData();
				}
				return next;
			} );
		}

		useEffect( function () {
			if ( post.id ) {
				load( false );
				loadReportData();
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

		function applySlug() {
			api( '/report/' + item.id, { method: 'POST', data: { type: 'post' } } ).then( function ( next ) {
				setReport( next );
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
						'locked' === field.status ? el( Button, { variant: 'link', className: 'kdna-sw-unlock', 'aria-label': i18n.unlock + ' ' + field.label, onClick: function () {
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

		if ( report ) {
			children.push( el( 'h4', { key: 'report-title', className: 'kdna-sw-report-title' }, i18n.report ) );
			if ( report.message ) {
				children.push( el( 'p', { key: 'report-msg', className: 'kdna-sw-report-msg' }, report.message ) );
			}
			var needsWork = false;
			children.push( el( 'ul', { key: 'report', className: 'kdna-sw-report-list' }, ( report.checks || [] ).map( function ( check ) {
				if ( ! check.ok ) {
					needsWork = true;
				}
				return el( 'li', { key: check.id, className: 'kdna-sw-report-check is-' + check.status },
					el( 'div', { className: 'kdna-sw-report-head' },
						el( 'strong', null, check.label ),
						el( 'span', { className: 'kdna-sw-report-status' }, ( i18n.statuses || {} )[ check.status ] || check.status )
					),
					el( 'p', null, check.message ),
					check.suggestion ? el( 'div', { className: 'kdna-sw-suggestion' },
						el( 'span', { className: 'kdna-sw-suggestion-label' }, check.suggestion_label + ':' ),
						el( 'code', null, check.suggestion ),
						el( Button, { variant: 'link', 'aria-label': i18n.copy + ' ' + check.suggestion_label, onClick: function ( event ) {
							copyText( check.suggestion, event.currentTarget );
						} }, i18n.copy )
					) : null,
					check.can_apply ? el( Button, { variant: 'secondary', onClick: applySlug }, i18n.applySlug ) : null,
					check.warning ? el( 'p', { className: 'kdna-sw-warning' }, check.warning ) : null
				);
			} ) ) );
			if ( needsWork && ! report.has_suggestions && report.keyphrase ) {
				children.push( el( 'p', { key: 'nosuggest', className: 'description' }, i18n.noSuggest ) );
			}
			if ( report.content_note && report.keyphrase ) {
				children.push( el( 'p', { key: 'note', className: 'description' }, report.content_note ) );
			}
		}

		return el( Panel, { name: 'kdna-seo-writer', title: i18n.title, className: 'kdna-sw-panel' }, el( 'div', { className: 'kdna-sw-box-block' }, children ) );
	}

	wp.plugins.registerPlugin( 'kdna-seo-writer', { render: SeoWriterPanel, icon: null } );
}() );
