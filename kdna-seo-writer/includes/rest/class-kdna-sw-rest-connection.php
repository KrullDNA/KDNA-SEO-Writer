<?php
/**
 * REST route: test the Anthropic connection (kdna-sw/v1/test).
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the Test connection check for the Connection tab.
 */
final class KDNA_SeoWriter_Rest_Connection {

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			KDNA_SW_REST_NAMESPACE,
			'/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * Checks the saved key and chosen model, and reports the result in plain English.
	 *
	 * @return WP_REST_Response
	 */
	public function test(): WP_REST_Response {
		return rest_ensure_response( KDNA_SeoWriter_Claude::test_connection() );
	}
}
