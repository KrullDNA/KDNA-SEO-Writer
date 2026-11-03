<?php
/**
 * REST route: read and save settings (kdna-sw/v1/settings).
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets the settings screen load and save settings, and save or remove the API key.
 *
 * Only administrators can use it, and WordPress's REST nonce must be sent with
 * every request. The API key can be sent in, but is never sent back out: the
 * response only says whether a key is saved.
 */
final class KDNA_SeoWriter_Rest_Settings {

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			KDNA_SW_REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'settings'   => array(
							'type'     => 'object',
							'required' => false,
						),
						'api_key'    => array(
							'type'     => 'string',
							'required' => false,
						),
						'remove_key' => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'clear_locks' => array(
							'type'     => 'boolean',
							'required' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Allows only administrators.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns the current settings and whether an API key is saved.
	 *
	 * @return WP_REST_Response
	 */
	public function read(): WP_REST_Response {
		return rest_ensure_response( self::payload() );
	}

	/**
	 * Saves settings, and saves or removes the API key if asked.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$settings = $request->get_param( 'settings' );
		if ( is_array( $settings ) ) {
			KDNA_SeoWriter_Settings::save( $settings );

			// The sweep time or the nightly switch may have changed.
			KDNA_SeoWriter_Scheduler::schedule_sweep( true );
		}

		if ( $request->get_param( 'clear_locks' ) ) {
			$cleared            = KDNA_SeoWriter_State::clear_all_locks();
			$payload            = self::payload();
			/* translators: %d: number of items. */
			$payload['message'] = sprintf( _n( 'Locks cleared on %d item.', 'Locks cleared on %d items.', $cleared, 'kdna-seo-writer' ), $cleared );
			return rest_ensure_response( $payload );
		}

		if ( $request->get_param( 'remove_key' ) ) {
			KDNA_SeoWriter_Crypto::delete_key();
		}

		$api_key = $request->get_param( 'api_key' );
		if ( is_string( $api_key ) && '' !== trim( $api_key ) ) {
			$api_key = trim( sanitize_text_field( $api_key ) );
			if ( ! preg_match( '/^[A-Za-z0-9_\-]{20,300}$/', $api_key ) ) {
				return new WP_Error(
					'kdna_sw_bad_key',
					__( 'That does not look like an Anthropic API key. Keys start with sk-ant- and contain only letters, numbers, dashes and underscores.', 'kdna-seo-writer' ),
					array( 'status' => 400 )
				);
			}
			if ( ! KDNA_SeoWriter_Crypto::save_key( $api_key ) ) {
				return new WP_Error(
					'kdna_sw_key_not_saved',
					__( 'The API key could not be saved securely on this server. Please ask KDNA to check the server\'s PHP sodium extension.', 'kdna-seo-writer' ),
					array( 'status' => 500 )
				);
			}
		}

		$payload            = self::payload();
		$payload['message'] = __( 'Settings saved.', 'kdna-seo-writer' );

		return rest_ensure_response( $payload );
	}

	/**
	 * Builds the data the settings screen needs. Never includes the API key.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		return array(
			'settings' => KDNA_SeoWriter_Settings::all(),
			'key'      => array(
				'saved'    => KDNA_SeoWriter_Crypto::has_key(),
				'readable' => KDNA_SeoWriter_Crypto::key_is_readable(),
			),
		);
	}
}
