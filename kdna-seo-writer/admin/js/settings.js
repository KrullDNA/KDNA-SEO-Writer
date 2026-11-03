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
		return TABS.indexOf( hash ) !== -1 ? hash : 'connection';
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
				integrations: data.integrations || {},
				starterRules: data.starterRules || [],
				busy: false,
				testing: false,
				notice: { text: '', ok: true },
				test: { message: '', ok: true },

				/**
				 * Keeps the open tab in step with the address when the screen starts.
				 * WordPress's apiFetch already sends the REST nonce with every request.
				 */
				init: function () {
					window.addEventListener( 'hashchange', () => {
						this.tab = tabFromHash();
					} );
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
				 * Sends settings (and a new API key, if typed) to the server.
				 *
				 * @param {Object} extra Extra fields, such as remove_key.
				 * @return {Promise<boolean>} True when saved.
				 */
				save: function ( extra ) {
					var body = Object.assign( { settings: this.settings }, extra || {} );
					if ( this.apiKey.trim() !== '' ) {
						body.api_key = this.apiKey.trim();
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
