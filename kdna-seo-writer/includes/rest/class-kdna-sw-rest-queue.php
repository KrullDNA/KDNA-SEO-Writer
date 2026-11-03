<?php
/**
 * REST route: the review queue (kdna-sw/v1/queue).
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the review queue and runs approve, edit then approve, reject,
 * regenerate and bulk approve. Administrators only.
 */
final class KDNA_SeoWriter_Rest_Queue {

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			KDNA_SW_REST_NAMESPACE,
			'/queue',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'page'    => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'subtype' => array(
							'type'    => 'string',
							'default' => '',
						),
						'field'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'flag'    => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'act' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'approve', 'reject', 'approve_items', 'reject_items', 'regenerate', 'matching' ),
							'required' => true,
						),
						'rows'   => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'integer' ),
							'default' => array(),
						),
						'items'  => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
						'edits'  => array(
							'type'    => 'object',
							'default' => array(),
						),
						'filters' => array(
							'type'    => 'object',
							'default' => array(),
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
	 * Returns one page of the queue.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response {
		$page = KDNA_SeoWriter_Queue::items( self::filters( $request->get_params() ), (int) $request['page'] );
		return rest_ensure_response(
			array_merge(
				$page,
				array(
					'page'  => (int) $request['page'],
					'count' => KDNA_SeoWriter_Queue::count_items(),
				)
			)
		);
	}

	/**
	 * Runs a queue action.
	 *
	 * Bulk approval is sent by the screen in small batches, so even hundreds
	 * of items never run into the server's time limit.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function act( WP_REST_Request $request ): WP_REST_Response {
		$rows  = array_map( 'absint', (array) $request['rows'] );
		$items = array_values( array_filter( array_map( 'strval', (array) $request['items'] ), static fn( $key ) => null !== KDNA_SeoWriter_Queue::parse_key( $key ) ) );
		$edits = array();
		foreach ( (array) $request['edits'] as $row_id => $text ) {
			$edits[ absint( $row_id ) ] = (string) $text;
		}

		switch ( $request['action'] ) {
			case 'approve':
				$result = KDNA_SeoWriter_Queue::approve( $rows, $edits );
				break;
			case 'approve_items':
				$result = KDNA_SeoWriter_Queue::approve_items( $items );
				break;
			case 'reject':
				$result = array( 'rejected' => KDNA_SeoWriter_Queue::reject( $rows ) );
				break;
			case 'reject_items':
				$result = array( 'rejected' => KDNA_SeoWriter_Queue::reject_items( $items ) );
				break;
			case 'regenerate':
				$result = array( 'queued' => KDNA_SeoWriter_Queue::regenerate( $items ) );
				break;
			default:
				$result = array( 'items' => KDNA_SeoWriter_Queue::matching_keys( self::filters( (array) $request['filters'] ) ) );
		}

		$result['count'] = KDNA_SeoWriter_Queue::count_items();
		return rest_ensure_response( $result );
	}

	/**
	 * Cleans the filter values.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @return array<string, string>
	 */
	private static function filters( array $params ): array {
		return array(
			'subtype' => sanitize_key( (string) ( $params['subtype'] ?? '' ) ),
			'field'   => sanitize_key( (string) ( $params['field'] ?? '' ) ),
			'flag'    => sanitize_key( (string) ( $params['flag'] ?? '' ) ),
		);
	}
}
