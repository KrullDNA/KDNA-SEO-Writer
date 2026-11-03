<?php
/**
 * REST route: Generate now and item status (kdna-sw/v1/generate/{id}).
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets the edit screen panel, the Elementor section and the list screens ask
 * for an item's status, queue Generate now, and clear a field's lock.
 *
 * Anyone who can edit the item may use it. Generate now only queues a
 * background job, so the request returns straight away.
 */
final class KDNA_SeoWriter_Rest_Generate {

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register(): void {
		$args = array(
			'id'       => array(
				'type'     => 'integer',
				'required' => true,
			),
			'type'     => array(
				'type'    => 'string',
				'enum'    => array( 'post', 'term' ),
				'default' => 'post',
			),
			'taxonomy' => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		register_rest_route(
			KDNA_SW_REST_NAMESPACE,
			'/generate/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $args,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'act' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array_merge(
						$args,
						array(
							'action' => array(
								'type'    => 'string',
								'enum'    => array( 'generate', 'unlock' ),
								'default' => 'generate',
							),
							'field'  => array(
								'type'    => 'string',
								'default' => '',
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Allows anyone who can edit this item.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool
	 */
	public function can_edit( WP_REST_Request $request ): bool {
		$id = (int) $request['id'];
		return 'term' === $request['type'] ? current_user_can( 'edit_term', $id ) : current_user_can( 'edit_post', $id );
	}

	/**
	 * Returns the item's status.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( self::status( (string) $request['type'], (int) $request['id'], sanitize_key( (string) $request['taxonomy'] ) ) );
	}

	/**
	 * Queues Generate now, or clears a lock.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function act( WP_REST_Request $request ) {
		$type    = (string) $request['type'];
		$id      = (int) $request['id'];
		$subtype = sanitize_key( (string) $request['taxonomy'] );
		$subtype = '' !== $subtype ? $subtype : KDNA_SeoWriter_Overview::subtype_of( $type, $id );

		if ( 'unlock' === $request['action'] ) {
			$field  = sanitize_key( (string) $request['field'] );
			$fields = isset( KDNA_SeoWriter_State::GROUPS[ $field ] ) ? KDNA_SeoWriter_State::GROUPS[ $field ] : array();
			KDNA_SeoWriter_State::unlock( $type, $id, $fields, KDNA_SeoWriter_Yoast::get_all( $type, $id, $subtype ) );

			$payload            = self::status( $type, $id, $subtype );
			$payload['message'] = __( 'Unlocked. The plugin may now work on this field again.', 'kdna-seo-writer' );
			return rest_ensure_response( $payload );
		}

		$problem = self::cannot_generate( $type, $subtype );
		if ( '' !== $problem ) {
			return new WP_Error( 'kdna_sw_cannot_generate', $problem, array( 'status' => 400 ) );
		}

		KDNA_SeoWriter_Scheduler::queue( $type, $id, $subtype, 'manual' );

		$payload            = self::status( $type, $id, $subtype );
		$payload['message'] = __( 'Queued. Claude usually finishes within a minute or two, and this panel updates by itself.', 'kdna-seo-writer' );
		return rest_ensure_response( $payload );
	}

	/**
	 * Explains why Generate now cannot run, if it cannot.
	 *
	 * @param string $type    Either post or term.
	 * @param string $subtype Post type or taxonomy.
	 * @return string Empty when it can run.
	 */
	public static function cannot_generate( string $type, string $subtype ): string {
		if ( ! KDNA_SeoWriter_Yoast::is_active() ) {
			return __( 'Yoast SEO is not active, so there is nowhere to save the metadata.', 'kdna-seo-writer' );
		}
		if ( ! KDNA_SeoWriter_Crypto::has_key() ) {
			return __( 'No Anthropic API key has been saved yet. Add one in Settings > KDNA SEO Writer > Connection.', 'kdna-seo-writer' );
		}
		if ( ! KDNA_SeoWriter_Scheduler::available() ) {
			return __( 'The background job runner (Action Scheduler) is not available, so nothing can be queued.', 'kdna-seo-writer' );
		}
		if ( ! KDNA_SeoWriter_Scheduler::enabled( $type, $subtype ) ) {
			return __( 'This content type is set to Off in Settings > KDNA SEO Writer > Content.', 'kdna-seo-writer' );
		}
		return '';
	}

	/**
	 * Builds an item's status for the panels.
	 *
	 * @param string $type    Either post or term.
	 * @param int    $id      Post or term ID.
	 * @param string $subtype Post type or taxonomy.
	 * @return array<string, mixed>
	 */
	public static function status( string $type, int $id, string $subtype ): array {
		$subtype = '' !== $subtype ? $subtype : KDNA_SeoWriter_Overview::subtype_of( $type, $id );
		$current = KDNA_SeoWriter_Yoast::get_all( $type, $id, $subtype );
		KDNA_SeoWriter_State::refresh_locks( $type, $id, $current );

		$waiting  = KDNA_SeoWriter_Queue::waiting_fields( $type, array( $id ) );
		$statuses = KDNA_SeoWriter_State::field_statuses( $type, $id, $current, $waiting[ $id ] ?? array() );
		$state    = KDNA_SeoWriter_State::get( $type, $id );
		$labels   = array(
			'seo_title'        => __( 'SEO title', 'kdna-seo-writer' ),
			'meta_description' => __( 'Meta description', 'kdna-seo-writer' ),
			'focus_keyphrase'  => __( 'Focus keyphrase', 'kdna-seo-writer' ),
			'social'           => __( 'Social title and description', 'kdna-seo-writer' ),
		);

		$fields = array();
		foreach ( $statuses as $group => $status ) {
			$fields[] = array(
				'group'  => $group,
				'label'  => $labels[ $group ],
				'status' => $status,
				'text'   => self::status_label( $status ),
			);
		}

		$last = $state['last'];

		return array(
			'fields'     => $fields,
			'job'        => KDNA_SeoWriter_State::job_waiting( $type, $id ),
			'last'       => $last ? array(
				'status'  => (string) $last['status'],
				'message' => (string) $last['message'],
				'when'    => human_time_diff( (int) $last['time'] ),
			) : null,
			'mode'       => KDNA_SeoWriter_Settings::mode_for( 'term' === $type ? 'taxonomy' : 'post_type', $subtype ),
			'problem'    => self::cannot_generate( $type, $subtype ),
			'review_url' => admin_url( 'options-general.php?page=' . KDNA_SeoWriter_Admin::PAGE . '#review' ),
		);
	}

	/**
	 * Returns the words shown for a field status.
	 *
	 * @param string $status filled, waiting, locked or missing.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'filled'  => __( 'Filled', 'kdna-seo-writer' ),
			'waiting' => __( 'Waiting for review', 'kdna-seo-writer' ),
			'locked'  => __( 'Locked (edited by a person)', 'kdna-seo-writer' ),
			'missing' => __( 'Missing', 'kdna-seo-writer' ),
		);
		return $labels[ $status ] ?? $status;
	}
}
