/**
 * KDNA SEO Writer settings screen (Alpine.js component).
 *
 * Loads before Alpine and registers the kdnaSwSettings component. Talks to the
 * plugin's REST routes with WordPress's apiFetch, which sends the REST nonce.
 * The API key is only ever sent to the server, never received back.
 */
( function () {
	'use strict';

	var data = window.kdnaSwData || {};
	var i18n = data.i18n || {};
	var TABS = [ 'overview', 'review', 'keyphrases', 'connection', 'brand', 'content', 'automation', 'advanced' ];

	/**
	 * Turns an empty list from PHP into an empty object, so named keys can be set.
	 *
	 * @param {*} value Value from the server.
	 * @return {Object} An object.
	 */
	function asObject( value ) {
		return value && typeof value === 'object' && ! Array.isArray( value ) ? value : {};
	}

	/**
	 * Makes sure every map the screen edits is an object, and fills in the
	 * current mode for every post type and taxonomy.
	 *
	 * @param {Object} settings Settings from the server.
	 * @return {Object} Settings ready for the screen.
	 */
	function prepare( settings ) {
		var copy = JSON.parse( JSON.stringify( settings ) );

		copy.content.post_types = asObject( copy.content.post_types );
		copy.content.taxonomies = asObject( copy.content.taxonomies );
		copy.content.jet_fields = asObject( copy.content.jet_fields );
		copy.connection.rates = asObject( copy.connection.rates );

		( data.postTypes || [] ).forEach( function ( type ) {
			if ( ! copy.content.post_types[ type.name ] ) {
				copy.content.post_types[ type.name ] = type.mode;
			}
		} );
		( data.taxonomies || [] ).forEach( function ( taxonomy ) {
			if ( ! copy.content.taxonomies[ taxonomy.name ] ) {
				copy.content.taxonomies[ taxonomy.name ] = taxonomy.mode;
			}
		} );

		return copy;
	}

	/**
	 * Picks the tab to open from the address, for example #brand.
	 *
	 * @return {string} Tab ID.
	 */
	function tabFromHash() {
		var hash = ( window.location.hash || '' ).replace( '#', '' );
		return TABS.indexOf( hash ) !== -1 ? hash : ( data.defaultTab || 'connection' );
	}

	/**
	 * Measures text in Google's title font (20px Arial), the same way Yoast's
	 * snippet preview does, using an off-screen canvas.
	 *
	 * @param {string} text Title text.
	 * @return {number} Width in pixels.
	 */
	var canvas = null;
	function measure( text ) {
		canvas = canvas || document.createElement( 'canvas' );
		var context = canvas.getContext( '2d' );
		if ( ! context ) {
			return 0;
		}
		context.font = '20px Arial';
		return Math.ceil( context.measureText( text ).width );
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaSwSettings', function () {
			return {
				tab: tabFromHash(),
				settings: prepare( data.state.settings ),
				key: data.state.key,
				apiKey: '',
				models: data.models || [],
				postTypes: data.postTypes || [],
				taxonomies: data.taxonomies || [],
				hiddenTypes: data.hiddenTypes || [],
				integrations: data.integrations || {},
				starterRules: data.starterRules || [],
				busy: false,
				testing: false,
				notice: { text: '', ok: true },
				test: { message: '', ok: true },
				contentTypes: ( data.postTypes || [] ).concat( data.taxonomies || [], [ { name: 'attachment', label: i18n.images } ] ),
				review: {
					items: [],
					total: 0,
					page: 1,
					perPage: 20,
					loading: false,
					loaded: false,
					selected: [],
					progress: '',
					filters: { subtype: '', field: '', flag: '' },
					polls: 0,
				},
				overview: { data: null, loading: false, message: '' },
				fill: { status: {}, preview: null, problem: '', loading: false, polling: false },
				keyphrases: { items: [], loading: false, loaded: false, search: '', duplicatesOnly: false, open: '', reports: {} },

				/**
				 * Keeps the open tab in step with the address when the screen starts,
				 * and loads the Review or Overview data when those tabs open.
				 * WordPress's apiFetch already sends the REST nonce with every request.
				 */
				init: function () {
					window.addEventListener( 'hashchange', () => {
						this.tab = tabFromHash();
					} );
					this.$watch( 'tab', ( tab ) => this.loadTab( tab ) );
					this.loadTab( this.tab );
				},

				/**
				 * Loads the data a tab needs the first time it opens.
				 *
				 * @param {string} tab Tab ID.
				 */
				loadTab: function ( tab ) {
					if ( 'review' === tab ) {
						this.loadReview( this.review.page );
					} else if ( 'overview' === tab ) {
						this.loadOverview();
					} else if ( 'keyphrases' === tab && ! this.keyphrases.loaded ) {
						this.loadKeyphrases();
					}
				},

				/**
				 * Opens a tab and remembers it in the address.
				 *
				 * @param {string} tab Tab ID.
				 */
				go: function ( tab ) {
					this.tab = tab;
					this.notice.text = '';
					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, '', '#' + tab );
					}
				},

				/**
				 * Moves between tabs with the arrow, Home and End keys, as screen
				 * reader and keyboard users expect from a row of tabs.
				 *
				 * @param {KeyboardEvent} event Key press on the tab row.
				 */
				tabKeys: function ( event ) {
					var keys = [ 'ArrowRight', 'ArrowLeft', 'Home', 'End' ];
					if ( keys.indexOf( event.key ) === -1 ) {
						return;
					}
					event.preventDefault();
					var index = TABS.indexOf( this.tab );
					if ( 'ArrowRight' === event.key ) {
						index = ( index + 1 ) % TABS.length;
					} else if ( 'ArrowLeft' === event.key ) {
						index = ( index - 1 + TABS.length ) % TABS.length;
					} else {
						index = 'Home' === event.key ? 0 : TABS.length - 1;
					}
					this.go( TABS[ index ] );
					this.$nextTick( () => {
						var button = document.querySelector( '.kdna-sw-tabs [data-tab="' + TABS[ index ] + '"]' );
						if ( button ) {
							button.focus();
						}
					} );
				},

				/* ---------------- Review tab ---------------- */

				/**
				 * Loads one page of the review queue.
				 *
				 * @param {number} page Page number.
				 * @return {Promise} Done.
				 */
				loadReview: function ( page ) {
					var f = this.review.filters;
					var query = '?page=' + Math.max( 1, page || 1 ) + '&subtype=' + encodeURIComponent( f.subtype ) + '&field=' + encodeURIComponent( f.field ) + '&flag=' + encodeURIComponent( f.flag );

					this.review.loading = ! this.review.loaded;
					return window.wp.apiFetch( { path: data.restPath + '/queue' + query } )
						.then( ( response ) => {
							this.review.items = ( response.items || [] ).map( function ( item ) {
								item.fields.forEach( function ( row ) {
									row.edit = row.suggested;
								} );
								return item;
							} );
							this.review.total = response.total || 0;
							this.review.page = response.page || 1;
							this.review.loaded = true;
							this.review.selected = this.review.selected.filter( ( key ) => this.review.items.some( ( item ) => item.key === key ) );
							this.updateBadge( response.count );
							this.pollRegenerating();
						} )
						.catch( ( error ) => {
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
						} )
						.finally( () => {
							this.review.loading = false;
						} );
				},

				/**
				 * Checks back every ten seconds while any item is regenerating.
				 */
				pollRegenerating: function () {
					var waiting = this.review.items.some( function ( item ) {
						return item.regenerating;
					} );
					if ( ! waiting || this.review.polls > 30 || 'review' !== this.tab ) {
						this.review.polls = 0;
						return;
					}
					this.review.polls++;
					window.setTimeout( () => this.loadReview( this.review.page ), 10000 );
				},

				/**
				 * Sends a queue action.
				 *
				 * @param {Object} body Action and its details.
				 * @return {Promise<Object>} Response.
				 */
				queueAction: function ( body ) {
					return window.wp.apiFetch( { path: data.restPath + '/queue', method: 'POST', data: body } );
				},

				/**
				 * Approves one field, with the edited text if it was changed.
				 *
				 * @param {Object} item Item.
				 * @param {Object} row  Field row.
				 */
				approveRow: function ( item, row ) {
					var edits = {};
					if ( row.edit !== row.suggested ) {
						edits[ row.id ] = row.edit;
					}
					this.run( this.queueAction( { action: 'approve', rows: [ row.id ], edits: edits } ), i18n.approved );
				},

				/**
				 * Rejects one field.
				 *
				 * @param {Object} item Item.
				 * @param {Object} row  Field row.
				 */
				rejectRow: function ( item, row ) {
					this.run( this.queueAction( { action: 'reject', rows: [ row.id ] } ), i18n.rejected );
				},

				/**
				 * Approves every field of one item, including any edits.
				 *
				 * @param {Object} item Item.
				 */
				approveItem: function ( item ) {
					var edits = {};
					item.fields.forEach( function ( row ) {
						if ( row.edit !== row.suggested ) {
							edits[ row.id ] = row.edit;
						}
					} );
					var rows = item.fields.map( function ( row ) {
						return row.id;
					} );
					this.run( this.queueAction( { action: 'approve', rows: rows, edits: edits } ), i18n.approved );
				},

				/**
				 * Approves whole items, in batches of ten so large selections
				 * never hit the server's time limit.
				 *
				 * @param {Array<string>} keys Item keys.
				 * @return {Promise} Done.
				 */
				approveItems: function ( keys ) {
					var batches = [];
					for ( var i = 0; i < keys.length; i += 10 ) {
						batches.push( keys.slice( i, i + 10 ) );
					}
					var done = 0;
					var errors = [];
					this.busy = true;

					var next = () => {
						if ( ! batches.length ) {
							return Promise.resolve();
						}
						var batch = batches.shift();
						return this.queueAction( { action: 'approve_items', items: batch } ).then( ( response ) => {
							done += batch.length;
							errors = errors.concat( response.errors || [] );
							this.review.progress = i18n.approving.replace( '%1$d', done ).replace( '%2$d', keys.length );
							this.updateBadge( response.count );
							return next();
						} );
					};

					return next()
						.then( () => {
							this.review.progress = '';
							this.review.selected = [];
							this.notice = errors.length ? { text: errors.join( ' ' ), ok: false } : { text: i18n.approvedMany.replace( '%d', done ), ok: true };
							return this.loadReview( 1 );
						} )
						.catch( ( error ) => {
							this.review.progress = '';
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
						} )
						.finally( () => {
							this.busy = false;
						} );
				},

				/**
				 * Approves every item that matches the current filters.
				 */
				approveAllMatching: function () {
					// eslint-disable-next-line no-alert
					if ( ! window.confirm( i18n.confirmApproveAll.replace( '%d', this.review.total ) ) ) {
						return;
					}
					this.busy = true;
					this.queueAction( { action: 'matching', filters: this.review.filters } )
						.then( ( response ) => this.approveItems( response.items || [] ) )
						.catch( ( error ) => {
							this.busy = false;
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
						} );
				},

				/**
				 * Rejects whole items.
				 *
				 * @param {Array<string>} keys Item keys.
				 */
				rejectItems: function ( keys ) {
					this.run( this.queueAction( { action: 'reject_items', items: keys } ), i18n.rejected ).then( () => {
						this.review.selected = [];
					} );
				},

				/**
				 * Clears an item's suggestions and asks Claude for new ones.
				 *
				 * @param {Object} item Item.
				 */
				regenerate: function ( item ) {
					this.run( this.queueAction( { action: 'regenerate', items: [ item.key ] } ), i18n.regenerating, true );
				},

				/**
				 * Runs a queue request, shows the result and reloads the page of the queue.
				 *
				 * @param {Promise} request  The request.
				 * @param {string}  message  Message on success.
				 * @param {boolean} [keepPage] True to keep showing the item (regenerate).
				 * @return {Promise} Done.
				 */
				run: function ( request, message, keepPage ) {
					this.busy = true;
					return request
						.then( ( response ) => {
							var errors = response.errors || [];
							this.notice = errors.length ? { text: errors.join( ' ' ), ok: false } : { text: message, ok: true };
							this.updateBadge( response.count );
							if ( keepPage ) {
								this.review.polls = 0;
							}
							return this.loadReview( this.review.page );
						} )
						.catch( ( error ) => {
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
						} )
						.finally( () => {
							this.busy = false;
						} );
				},

				/**
				 * Builds the length bar for a field: characters for descriptions,
				 * pixel width in Google's font for SEO titles.
				 *
				 * @param {Object} row  Field row.
				 * @param {Object} item Item.
				 * @return {Object|null} percent, ok and text, or null for no bar.
				 */
				meter: function ( row, item ) {
					var adv = this.settings.advanced;
					var text = ( row.edit || '' ).trim();

					if ( 'meta_description' === row.field || 'og_description' === row.field || 'x_description' === row.field ) {
						var max = 'meta_description' === row.field ? adv.desc_max : 200;
						var min = 'meta_description' === row.field ? adv.desc_min + 1 : 1;
						var chars = Array.from( text ).length;
						return {
							percent: Math.min( 100, Math.round( ( chars / max ) * 100 ) ),
							ok: chars >= min && chars <= max,
							text: i18n.chars.replace( '%1$d', chars ).replace( '%2$d', min ).replace( '%3$d', max ),
						};
					}

					if ( 'alt_text' === row.field ) {
						var altMax = adv.alt_max || 125;
						var altChars = Array.from( text ).length;
						return {
							percent: Math.min( 100, Math.round( ( altChars / altMax ) * 100 ) ),
							ok: altChars >= 1 && altChars <= altMax,
							text: i18n.chars.replace( '%1$d', altChars ).replace( '%2$d', 1 ).replace( '%3$d', altMax ),
						};
					}

					if ( 'seo_title' === row.field ) {
						var full = text + ( item.title_suffix ? ' ' + item.title_suffix : '' );
						var width = measure( full );
						return {
							percent: Math.min( 100, Math.round( ( width / adv.title_max_px ) * 100 ) ),
							ok: width >= adv.title_min_px && width < adv.title_max_px,
							text: i18n.pixels.replace( '%1$d', width ).replace( '%2$d', adv.title_min_px ).replace( '%3$d', adv.title_max_px - 1 ),
						};
					}

					return null;
				},

				/**
				 * Words for a review flag.
				 *
				 * @param {string} flag new, attention or failed.
				 * @return {string} Label.
				 */
				flagLabel: function ( flag ) {
					return ( i18n.flags || {} )[ flag ] || flag;
				},

				/**
				 * Pill style for a review flag.
				 *
				 * @param {string} flag new, attention or failed.
				 * @return {string} Class.
				 */
				flagClass: function ( flag ) {
					return 'failed' === flag ? 'is-bad' : ( 'attention' === flag ? 'is-wait' : 'is-good' );
				},

				allSelected: function () {
					return this.review.items.length > 0 && this.review.items.every( ( item ) => this.review.selected.indexOf( item.key ) !== -1 );
				},

				toggleAll: function ( on ) {
					this.review.selected = on ? this.review.items.map( function ( item ) {
						return item.key;
					} ) : [];
				},

				approveAllLabel: function () {
					return i18n.approveAll.replace( '%d', this.review.total );
				},

				pages: function () {
					return Math.max( 1, Math.ceil( this.review.total / this.review.perPage ) );
				},

				pageLabel: function () {
					return i18n.pageOf.replace( '%1$d', this.review.page ).replace( '%2$d', this.pages() );
				},

				/**
				 * Updates the number on the menu item.
				 *
				 * @param {number} count Items waiting.
				 */
				updateBadge: function ( count ) {
					if ( 'number' !== typeof count ) {
						return;
					}
					var link = document.querySelector( '#adminmenu a[href$="page=' + data.page + '"]' );
					if ( ! link ) {
						return;
					}
					var badge = link.querySelector( '.awaiting-mod' );
					if ( count > 0 ) {
						if ( ! badge ) {
							badge = document.createElement( 'span' );
							badge.className = 'awaiting-mod';
							link.appendChild( document.createTextNode( ' ' ) );
							link.appendChild( badge );
						}
						badge.textContent = String( count );
					} else if ( badge ) {
						badge.remove();
					}
				},

				/* ---------------- Overview tab ---------------- */

				/**
				 * Loads the Overview data.
				 *
				 * @return {Promise} Done.
				 */
				loadOverview: function () {
					this.overview.loading = ! this.overview.data;
					return window.wp.apiFetch( { path: data.restPath + '/overview' } )
						.then( ( response ) => {
							this.overview.data = response;
							this.updateBadge( response.queue );
							this.loadFill();
						} )
						.catch( ( error ) => {
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
						} )
						.finally( () => {
							this.overview.loading = false;
						} );
				},

				/* ---------------- Fill empty fields now ---------------- */

				/**
				 * Loads the run's progress, and keeps checking while it runs.
				 *
				 * @return {Promise} Done.
				 */
				loadFill: function () {
					return window.wp.apiFetch( { path: data.restPath + '/fill' } ).then( ( response ) => {
						var wasRunning = this.fill.status.running;
						this.fill.status = response.status || {};
						this.fill.problem = response.problem || '';
						if ( wasRunning && ! this.fill.status.running ) {
							this.loadOverview();
						}
						this.pollFill();
					} ).catch( () => {} );
				},

				/**
				 * Checks progress every 15 seconds while a run is going and the
				 * Overview is open.
				 */
				pollFill: function () {
					if ( ! this.fill.status.running || 'overview' !== this.tab || this.fill.polling ) {
						return;
					}
					this.fill.polling = true;
					window.setTimeout( () => {
						this.fill.polling = false;
						this.loadFill();
					}, 15000 );
				},

				/**
				 * Counts what a run would cover and estimates the cost.
				 */
				loadFillPreview: function () {
					this.fill.loading = true;
					window.wp.apiFetch( { path: data.restPath + '/fill?preview=1' } )
						.then( ( response ) => {
							this.fill.preview = response.preview || null;
							this.fill.status = response.status || {};
							this.fill.problem = response.problem || '';
						} )
						.catch( ( error ) => {
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
						} )
						.finally( () => {
							this.fill.loading = false;
						} );
				},

				/**
				 * Starts the run, after checking with the user.
				 */
				startFill: function () {
					var p = this.fill.preview;
					// eslint-disable-next-line no-alert
					if ( ! p || ! window.confirm( i18n.fillConfirm.replace( '%1$d', p.items ).replace( '%2$d', p.images ).replace( '%3$s', p.estimate.toFixed( 2 ) ) ) ) {
						return;
					}
					this.busy = true;
					window.wp.apiFetch( { path: data.restPath + '/fill', method: 'POST', data: { action: 'start' } } )
						.then( ( response ) => {
							this.fill.status = response.status || {};
							this.fill.preview = null;
							this.notice = { text: i18n.fillStarted, ok: true };
							this.pollFill();
						} )
						.catch( ( error ) => {
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
						} )
						.finally( () => {
							this.busy = false;
						} );
				},

				/**
				 * Stops the run, after checking with the user.
				 */
				stopFill: function () {
					// eslint-disable-next-line no-alert
					if ( ! window.confirm( i18n.fillStopConfirm ) ) {
						return;
					}
					this.busy = true;
					window.wp.apiFetch( { path: data.restPath + '/fill', method: 'POST', data: { action: 'stop' } } )
						.then( ( response ) => {
							this.fill.status = response.status || {};
							this.notice = { text: i18n.fillStopped, ok: true };
							this.loadOverview();
						} )
						.catch( ( error ) => {
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
						} )
						.finally( () => {
							this.busy = false;
						} );
				},

				fillPercent: function () {
					var s = this.fill.status;
					return s.total ? Math.round( ( s.done / s.total ) * 100 ) : 0;
				},

				fillProgress: function () {
					return i18n.fillProgress.replace( '%1$d', this.fill.status.done || 0 ).replace( '%2$d', this.fill.status.total || 0 );
				},

				fillFinished: function () {
					return i18n.fillFinished.replace( '%1$d', this.fill.status.total || 0 ).replace( '%2$s', this.fill.status.started || '' );
				},

				fillSummary: function () {
					var p = this.fill.preview;
					if ( ! p || ! ( p.items + p.images ) ) {
						return i18n.fillNothing;
					}
					return i18n.fillSummary.replace( '%1$d', p.items ).replace( '%2$d', p.images );
				},

				fillEstimate: function () {
					return i18n.fillEstimate.replace( '%s', this.fill.preview.estimate.toFixed( 2 ) );
				},

				/**
				 * Queues Suggest improvements for items with problems.
				 */
				suggestImprovements: function () {
					this.busy = true;
					this.overview.message = '';
					window.wp.apiFetch( { path: data.restPath + '/overview', method: 'POST', data: { items: [] } } )
						.then( ( response ) => {
							this.overview.message = response.message || '';
						} )
						.catch( ( error ) => {
							this.overview.message = ( error && error.message ) || i18n.genericError;
						} )
						.finally( () => {
							this.busy = false;
						} );
				},

				problemCount: function () {
					var problems = ( this.overview.data && this.overview.data.problems ) || {};
					return Object.keys( problems ).reduce( function ( sum, key ) {
						return sum + problems[ key ].length;
					}, 0 );
				},

				percent: function ( filled, total ) {
					if ( ! total ) {
						return '0';
					}
					return filled + ' (' + Math.round( ( filled / total ) * 100 ) + '%)';
				},

				tokensLabel: function () {
					var usage = this.overview.data ? this.overview.data.usage : null;
					if ( ! usage ) {
						return '';
					}
					return i18n.tokens.replace( '%1$s', usage.input.toLocaleString() ).replace( '%2$s', usage.output.toLocaleString() ).replace( '%3$s', usage.cached.toLocaleString() );
				},

				/* ---------------- Keyphrases tab ---------------- */

				/**
				 * Loads the keyphrase register.
				 *
				 * @return {Promise} Done.
				 */
				loadKeyphrases: function () {
					this.keyphrases.loading = true;
					return window.wp.apiFetch( { path: data.restPath + '/keyphrases' } )
						.then( ( response ) => {
							this.keyphrases.items = response.items || [];
							this.keyphrases.loaded = true;
						} )
						.catch( ( error ) => {
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
						} )
						.finally( () => {
							this.keyphrases.loading = false;
						} );
				},

				/**
				 * Returns the keyphrases matching the search and duplicates filter.
				 *
				 * @return {Array<Object>} Rows.
				 */
				filteredKeyphrases: function () {
					var search = ( this.keyphrases.search || '' ).toLowerCase().trim();
					var dupes = this.keyphrases.duplicatesOnly;
					return this.keyphrases.items.filter( function ( row ) {
						if ( dupes && ! row.duplicate ) {
							return false;
						}
						return ! search || row.keyphrase.toLowerCase().indexOf( search ) !== -1 || row.title.toLowerCase().indexOf( search ) !== -1;
					} );
				},

				/**
				 * Opens or closes an item's keyphrase report.
				 *
				 * @param {Object} row Keyphrase row.
				 */
				toggleReport: function ( row ) {
					if ( this.keyphrases.open === row.key ) {
						this.keyphrases.open = '';
						return;
					}
					this.keyphrases.open = row.key;
					this.loadReport( row );
				},

				/**
				 * Loads one item's keyphrase report.
				 *
				 * @param {Object} row Keyphrase row.
				 * @return {Promise} Done.
				 */
				loadReport: function ( row ) {
					var path = data.restPath + '/report/' + row.id + '?type=' + row.type + ( row.taxonomy ? '&taxonomy=' + encodeURIComponent( row.taxonomy ) : '' );
					return window.wp.apiFetch( { path: path } ).then( ( report ) => {
						this.keyphrases.reports = Object.assign( {}, this.keyphrases.reports, { [ row.key ]: report } );
					} );
				},

				/**
				 * Applies the suggested slug to a never-published item.
				 *
				 * @param {Object} row Keyphrase row.
				 */
				applySlug: function ( row ) {
					this.busy = true;
					window.wp.apiFetch( { path: data.restPath + '/report/' + row.id, method: 'POST', data: { type: row.type } } )
						.then( ( report ) => {
							this.keyphrases.reports = Object.assign( {}, this.keyphrases.reports, { [ row.key ]: report } );
							this.notice = { text: report.message || '', ok: true };
						} )
						.catch( ( error ) => {
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
						} )
						.finally( () => {
							this.busy = false;
						} );
				},

				/**
				 * Copies suggested wording to the clipboard.
				 *
				 * @param {string} text  Text.
				 * @param {Event}  event Click event.
				 */
				copy: function ( text, event ) {
					var button = event && event.currentTarget;
					if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
						window.navigator.clipboard.writeText( text ).then( function () {
							if ( button ) {
								var label = button.textContent;
								button.textContent = i18n.copied;
								window.setTimeout( function () {
									button.textContent = label;
								}, 1500 );
							}
						} );
					}
				},

				statusLabel: function ( status ) {
					return ( i18n.statuses || {} )[ status ] || status;
				},

				/* ---------------- Advanced tab ---------------- */

				/**
				 * Clears every field lock on the site, after checking with the user.
				 */
				clearLocks: function () {
					// eslint-disable-next-line no-alert
					if ( window.confirm( i18n.confirmClearLocks ) ) {
						this.save( { clear_locks: true } );
					}
				},

				/**
				 * Sends settings (and a new API key, if typed) to the server.
				 *
				 * @param {Object} extra Extra fields, such as remove_key.
				 * @return {Promise<boolean>} True when saved.
				 */
				save: function ( extra ) {
					var body = Object.assign( { settings: this.settings }, extra || {} );
					var typedKey = this.apiKey.trim();

					// Only the Connection tab sends a key, and only something that
					// looks like one. Browsers and password managers sometimes fill
					// the key box with a saved login password, even while another
					// tab is open, and that must never replace the real key.
					if ( 'connection' === this.tab && typedKey.indexOf( 'sk-ant-' ) === 0 ) {
						body.api_key = typedKey;
					} else if ( 'connection' === this.tab && typedKey !== '' ) {
						this.notice = { text: i18n.badKey, ok: false };
						return Promise.resolve( false );
					}

					this.busy = true;
					return window.wp.apiFetch( {
						path: data.restPath + '/settings',
						method: 'POST',
						data: body,
					} )
						.then( ( response ) => {
							this.settings = prepare( response.settings );
							this.key = response.key;
							this.apiKey = '';
							this.notice = { text: response.message || '', ok: true };
							return true;
						} )
						.catch( ( error ) => {
							this.notice = { text: ( error && error.message ) || i18n.genericError, ok: false };
							return false;
						} )
						.finally( () => {
							this.busy = false;
						} );
				},

				/**
				 * Removes the saved API key after checking with the user.
				 */
				removeKey: function () {
					// eslint-disable-next-line no-alert
					if ( window.confirm( i18n.confirmRemoveKey ) ) {
						this.apiKey = '';
						this.test.message = '';
						this.save( { remove_key: true } );
					}
				},

				/**
				 * Saves the tab, then checks the key and model with Anthropic.
				 */
				testConnection: function () {
					this.testing = true;
					this.test.message = '';

					this.save()
						.then( ( saved ) => {
							if ( ! saved ) {
								return null;
							}
							this.notice.text = '';
							return window.wp.apiFetch( { path: data.restPath + '/test', method: 'POST' } );
						} )
						.then( ( result ) => {
							if ( result ) {
								this.test = { message: result.message, ok: !! result.ok };
							}
						} )
						.catch( ( error ) => {
							this.test = { message: ( error && error.message ) || i18n.testError, ok: false };
						} )
						.finally( () => {
							this.testing = false;
						} );
				},

				/**
				 * Adds a set of starter claim rules to the end of the claim rules box.
				 *
				 * @param {Object} rule Starter rule set.
				 */
				addStarter: function ( rule ) {
					var current = ( this.settings.brand.claims || '' ).trim();
					if ( current.indexOf( rule.text ) !== -1 ) {
						return;
					}
					this.settings.brand.claims = current ? current + '\n\n' + rule.text : rule.text;
				},
			};
		} );
	} );
}() );
