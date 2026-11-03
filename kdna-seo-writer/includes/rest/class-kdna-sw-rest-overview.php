<?php
/**
 * REST route: the Overview tab (kdna-sw/v1/overview).
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns coverage, problems and usage, and queues Suggest improvements.
 * Administrators only.
 */
final class KDNA_SeoWriter_Rest_Overview {

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			KDNA_SW_REST_NAMESPACE,
			'/overview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( KDNA_SeoWriter_Overview::data() ),
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'suggest' ),
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
					'args'                => array(
						'items' => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
					),
				),
			)
		);
	}

	/**
	 * Queues Suggest improvements for the chosen items, or for every item with a problem.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggest( WP_REST_Request $request ) {
		if ( ! KDNA_SeoWriter_Crypto::has_key() || ! KDNA_SeoWriter_Scheduler::available() ) {
			return new WP_Error( 'kdna_sw_cannot_suggest', __( 'Add an API key in the Connection tab first.', 'kdna-seo-writer' ), array( 'status' => 400 ) );
		}

		$count = KDNA_SeoWriter_Overview::suggest( array_map( 'strval', (array) $request['items'] ) );

		return rest_ensure_response(
			array(
				'queued'  => $count,
				/* translators: %d: number of items. */
				'message' => sprintf( _n( '%d item queued. Its suggestions will appear in the Review tab shortly.', '%d items queued. Their suggestions will appear in the Review tab over the next few minutes.', $count, 'kdna-seo-writer' ), $count ),
			)
		);
	}
}
