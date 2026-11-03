<?php
/**
 * REST routes: keyphrase report (kdna-sw/v1/report/{id}) and keyphrase register (kdna-sw/v1/keyphrases).
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * The keyphrase report for one item (anyone who can edit it), applying a
 * suggested slug to a never-published item, and the site-wide list of
 * keyphrases for the Keyphrases tab (administrators only).
 */
final class KDNA_SeoWriter_Rest_Report {

	/**
	 * Most keyphrases listed in the Keyphrases tab.
	 */
	private const LIST_LIMIT = 2000;

	/**
	 * Registers the routes.
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
			'/report/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $args,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply_slug' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $args,
				),
			)
		);

		register_rest_route(
			KDNA_SW_REST_NAMESPACE,
			'/keyphrases',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'keyphrases' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
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
	 * Returns the report.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( KDNA_SeoWriter_Report::build( (string) $request['type'], (int) $request['id'], sanitize_key( (string) $request['taxonomy'] ) ) );
	}

	/**
	 * Applies the suggested slug to a never-published post.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_slug( WP_REST_Request $request ) {
		if ( 'post' !== $request['type'] ) {
			return new WP_Error( 'kdna_sw_slug_term', __( 'Category and tag addresses are not changed automatically.', 'kdna-seo-writer' ), array( 'status' => 400 ) );
		}

		$result = KDNA_SeoWriter_Report::apply_slug( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		$report            = KDNA_SeoWriter_Report::build( 'post', (int) $request['id'] );
		$report['message'] = __( 'Slug applied. Reload the editor before saving, so it does not put the old slug back.', 'kdna-seo-writer' );
		return rest_ensure_response( $report );
	}

	/**
	 * Lists every focus keyphrase on the site with the item it belongs to.
	 *
	 * @return WP_REST_Response
	 */
	public function keyphrases(): WP_REST_Response {
		$rows   = KDNA_SeoWriter_Keyphrases::all( true );
		$counts = array();
		foreach ( $rows as $row ) {
			$key            = KDNA_SeoWriter_Rules::normalise( $row['keyphrase'] );
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		$out = array();
		foreach ( array_slice( $rows, 0, self::LIST_LIMIT ) as $row ) {
			$type = 'term' === $row['object_type'] ? get_taxonomy( $row['subtype'] ) : get_post_type_object( $row['subtype'] );
			if ( 'term' === $row['object_type'] ) {
				$term  = get_term( (int) $row['id'], $row['subtype'] );
				$title = $term instanceof WP_Term ? $term->name : '';
				$edit  = (string) get_edit_term_link( (int) $row['id'], $row['subtype'] );
			} else {
				$title = get_the_title( (int) $row['id'] );
				$edit  = (string) get_edit_post_link( (int) $row['id'], 'url' );
			}

			$out[] = array(
				'key'        => $row['object_type'] . ':' . (int) $row['id'],
				'type'       => $row['object_type'],
				'id'         => (int) $row['id'],
				'taxonomy'   => 'term' === $row['object_type'] ? $row['subtype'] : '',
				'keyphrase'  => $row['keyphrase'],
				'title'      => '' !== $title ? $title : __( '(no title)', 'kdna-seo-writer' ),
				'type_label' => $type && isset( $type->labels->singular_name ) ? (string) $type->labels->singular_name : $row['subtype'],
				'edit_url'   => $edit,
				'duplicate'  => $counts[ KDNA_SeoWriter_Rules::normalise( $row['keyphrase'] ) ] > 1,
			);
		}

		usort( $out, static fn( $a, $b ) => strcasecmp( $a['keyphrase'], $b['keyphrase'] ) );

		return rest_ensure_response(
			array(
				'items' => $out,
				'total' => count( $rows ),
			)
		);
	}
}
