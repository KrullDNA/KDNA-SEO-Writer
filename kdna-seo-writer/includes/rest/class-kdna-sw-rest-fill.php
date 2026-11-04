<?php
/**
 * REST route: Fill empty fields now (kdna-sw/v1/fill).
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows what a full run would cover and cost, starts it, reports progress and
 * stops it. Administrators only.
 */
final class KDNA_SeoWriter_Rest_Fill {

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			KDNA_SW_REST_NAMESPACE,
			'/fill',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
					'args'                => array(
						'preview' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'retry'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'act' ),
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'start', 'stop' ),
							'required' => true,
						),
						'retry'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Returns the run's progress, and with preview=1 what a new run would cover.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response {
		$out = array(
			'status'  => KDNA_SeoWriter_Fill::status(),
			'problem' => KDNA_SeoWriter_Fill::cannot_start(),
		);
		if ( $request['preview'] ) {
			$out['preview'] = KDNA_SeoWriter_Fill::preview( (bool) $request['retry'] );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Starts or stops the run.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function act( WP_REST_Request $request ) {
		$status = 'start' === $request['action'] ? KDNA_SeoWriter_Fill::start( (bool) $request['retry'] ) : KDNA_SeoWriter_Fill::stop();
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		return rest_ensure_response(
			array(
				'status'  => $status,
				'problem' => KDNA_SeoWriter_Fill::cannot_start(),
			)
		);
	}
}
