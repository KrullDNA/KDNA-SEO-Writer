<?php
/**
 * List screens: SEO status column, Generate SEO row action and bulk action.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds an "SEO Writer" status column, a "Generate SEO" row action and a
 * "Generate SEO metadata" bulk action to the list screens of every content
 * type that is not Off (posts, pages, products, custom post types and terms).
 *
 * Hooks are only added once WordPress knows which list screen is open, so no
 * other screen pays for them. The status column reads Yoast's values from the
 * list's own (already loaded) data, plus one query per page for suggestions
 * waiting for review.
 */
final class KDNA_SeoWriter_Lists {

	/**
	 * admin-post.php action for the row action.
	 */
	private const ACTION = 'kdna_sw_generate_now';

	/**
	 * Most items one bulk action queues.
	 */
	private const BULK_LIMIT = 200;

	/**
	 * Suggestions waiting for the posts on this page, loaded once.
	 *
	 * @var array<int, array<int, string>>|null
	 */
	private ?array $waiting = null;

	/**
	 * The single shared copy of this class.
	 *
	 * @var KDNA_SeoWriter_Lists|null
	 */
	private static ?KDNA_SeoWriter_Lists $instance = null;

	/**
	 * Returns the shared list screens object.
	 *
	 * @return KDNA_SeoWriter_Lists
	 */
	public static function instance(): KDNA_SeoWriter_Lists {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'current_screen', array( $this, 'setup_screen' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_row_action' ) );
	}

	/**
	 * Adds the column and actions on list screens of enabled content types.
	 *
	 * @param WP_Screen $screen Current screen.
	 * @return void
	 */
	public function setup_screen( $screen ): void {
		if ( ! $screen instanceof WP_Screen ) {
			return;
		}

		if ( 'edit' === $screen->base && KDNA_SeoWriter_Editor::post_type_enabled( (string) $screen->post_type ) ) {
			$type = (string) $screen->post_type;
			add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_post_column' ), 10, 2 );
			add_filter( 'post_row_actions', array( $this, 'post_row_action' ), 10, 2 );
			add_filter( 'page_row_actions', array( $this, 'post_row_action' ), 10, 2 );
			add_filter( "bulk_actions-edit-{$type}", array( $this, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handle_post_bulk' ), 10, 3 );
		} elseif ( 'edit-tags' === $screen->base && KDNA_SeoWriter_Scheduler::enabled( 'term', (string) $screen->taxonomy ) ) {
			$taxonomy = (string) $screen->taxonomy;
			add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'add_column' ) );
			add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'render_term_column' ), 10, 3 );
			add_filter( "{$taxonomy}_row_actions", array( $this, 'term_row_action' ), 10, 2 );
			add_filter( "bulk_actions-edit-{$taxonomy}", array( $this, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$taxonomy}", array( $this, 'handle_term_bulk' ), 10, 3 );
		} else {
			return;
		}

		add_action( 'admin_head', array( $this, 'column_styles' ) );
		add_action( 'admin_notices', array( $this, 'queued_notice' ) );
	}

	/**
	 * Adds the SEO Writer column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$columns['kdna_sw'] = __( 'SEO Writer', 'kdna-seo-writer' );
		return $columns;
	}

	/**
	 * Prints the status for a post row.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_post_column( $column, $post_id ): void {
		if ( 'kdna_sw' !== $column ) {
			return;
		}

		if ( null === $this->waiting ) {
			global $wp_query;
			$ids           = isset( $wp_query->posts ) ? wp_list_pluck( (array) $wp_query->posts, 'ID' ) : array( $post_id );
			$this->waiting = KDNA_SeoWriter_Queue::waiting_fields( 'post', array_map( 'intval', $ids ) );
		}

		$post_id = (int) $post_id;
		echo self::status_html( 'post', $post_id, (string) get_post_type( $post_id ), $this->waiting[ $post_id ] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_html().
	}

	/**
	 * Returns the status for a term row.
	 *
	 * @param string $content Existing content.
	 * @param string $column  Column name.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public function render_term_column( $content, $column, $term_id ): string {
		if ( 'kdna_sw' !== $column ) {
			return (string) $content;
		}
		$term = get_term( (int) $term_id );
		if ( ! $term instanceof WP_Term ) {
			return (string) $content;
		}
		$waiting = KDNA_SeoWriter_Queue::waiting_fields( 'term', array( (int) $term_id ) );
		return self::status_html( 'term', (int) $term_id, $term->taxonomy, $waiting[ (int) $term_id ] ?? array() );
	}

	/**
	 * Builds the short status shown in the column.
	 *
	 * @param string             $type    Either post or term.
	 * @param int                $id      Post or term ID.
	 * @param string             $subtype Post type or taxonomy.
	 * @param array<int, string> $waiting Fields waiting for review.
	 * @return string Escaped HTML.
	 */
	private static function status_html( string $type, int $id, string $subtype, array $waiting ): string {
		$current  = KDNA_SeoWriter_Yoast::get_all( $type, $id, $subtype );
		$statuses = KDNA_SeoWriter_State::field_statuses( $type, $id, $current, $waiting );
		$counts   = array_count_values( $statuses );
		$names    = array(
			'seo_title'        => __( 'title', 'kdna-seo-writer' ),
			'meta_description' => __( 'description', 'kdna-seo-writer' ),
			'focus_keyphrase'  => __( 'keyphrase', 'kdna-seo-writer' ),
			'social'           => __( 'social', 'kdna-seo-writer' ),
		);

		$list = static function ( string $status ) use ( $statuses, $names ): string {
			return implode( ', ', array_map( static fn( $group ) => $names[ $group ], array_keys( $statuses, $status, true ) ) );
		};

		if ( KDNA_SeoWriter_State::job_waiting( $type, $id ) ) {
			$label = __( 'Queued', 'kdna-seo-writer' );
			$class = 'waiting';
		} elseif ( ! empty( $counts['waiting'] ) ) {
			$label = __( 'Waiting for review', 'kdna-seo-writer' );
			$class = 'waiting';
		} elseif ( ! empty( $counts['missing'] ) ) {
			/* translators: %s: list of missing fields. */
			$label = sprintf( __( 'Missing: %s', 'kdna-seo-writer' ), $list( 'missing' ) );
			$class = 'missing';
		} else {
			$label = __( 'Filled', 'kdna-seo-writer' );
			$class = 'filled';
		}

		$html = '<span class="kdna-sw-col is-' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
		if ( ! empty( $counts['locked'] ) ) {
			/* translators: %s: list of locked fields. */
			$html .= '<br><span class="kdna-sw-col-note">' . esc_html( sprintf( __( 'Locked: %s', 'kdna-seo-writer' ), $list( 'locked' ) ) ) . '</span>';
		}
		return $html;
	}

	/**
	 * Adds "Generate SEO" to a post row's actions.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    The post.
	 * @return array<string, string>
	 */
	public function post_row_action( array $actions, $post ): array {
		if ( $post instanceof WP_Post && current_user_can( 'edit_post', $post->ID ) && KDNA_SeoWriter_Editor::post_type_enabled( $post->post_type ) ) {
			$actions['kdna_sw'] = '<a href="' . esc_url( self::row_url( 'post', (int) $post->ID, $post->post_type ) ) . '">' . esc_html__( 'Generate SEO', 'kdna-seo-writer' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Adds "Generate SEO" to a term row's actions.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Term               $term    The term.
	 * @return array<string, string>
	 */
	public function term_row_action( array $actions, $term ): array {
		if ( $term instanceof WP_Term && current_user_can( 'edit_term', $term->term_id ) ) {
			$actions['kdna_sw'] = '<a href="' . esc_url( self::row_url( 'term', (int) $term->term_id, $term->taxonomy ) ) . '">' . esc_html__( 'Generate SEO', 'kdna-seo-writer' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Adds the bulk action.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public function add_bulk_action( array $actions ): array {
		$actions['kdna_sw_generate'] = __( 'Generate SEO metadata', 'kdna-seo-writer' );
		return $actions;
	}

	/**
	 * Queues Generate now for the selected posts.
	 *
	 * @param string          $redirect Where to go afterwards.
	 * @param string          $action   Chosen bulk action.
	 * @param array<int, int> $ids      Selected post IDs.
	 * @return string
	 */
	public function handle_post_bulk( $redirect, $action, $ids ): string {
		if ( 'kdna_sw_generate' !== $action ) {
			return (string) $redirect;
		}
		$count = 0;
		foreach ( array_slice( array_map( 'intval', (array) $ids ), 0, self::BULK_LIMIT ) as $id ) {
			$type = (string) get_post_type( $id );
			if ( current_user_can( 'edit_post', $id ) && '' === KDNA_SeoWriter_Rest_Generate::cannot_generate( 'post', $type ) && KDNA_SeoWriter_Scheduler::queue( 'post', $id, $type, 'manual', $count * 5 ) ) {
				++$count;
			}
		}
		return add_query_arg( 'kdna_sw_queued', $count, (string) $redirect );
	}

	/**
	 * Queues Generate now for the selected terms.
	 *
	 * @param string          $redirect Where to go afterwards.
	 * @param string          $action   Chosen bulk action.
	 * @param array<int, int> $ids      Selected term IDs.
	 * @return string
	 */
	public function handle_term_bulk( $redirect, $action, $ids ): string {
		if ( 'kdna_sw_generate' !== $action ) {
			return (string) $redirect;
		}
		$screen   = get_current_screen();
		$taxonomy = $screen ? (string) $screen->taxonomy : '';
		$count    = 0;
		foreach ( array_slice( array_map( 'intval', (array) $ids ), 0, self::BULK_LIMIT ) as $id ) {
			if ( current_user_can( 'edit_term', $id ) && '' === KDNA_SeoWriter_Rest_Generate::cannot_generate( 'term', $taxonomy ) && KDNA_SeoWriter_Scheduler::queue( 'term', $id, $taxonomy, 'manual', $count * 5 ) ) {
				++$count;
			}
		}
		return add_query_arg( 'kdna_sw_queued', $count, (string) $redirect );
	}

	/**
	 * Runs the "Generate SEO" row action, then returns to the list.
	 *
	 * @return void
	 */
	public function handle_row_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce checked just below.
		$type    = isset( $_GET['type'] ) && 'term' === $_GET['type'] ? 'term' : 'post';
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$subtype = isset( $_GET['subtype'] ) ? sanitize_key( wp_unslash( $_GET['subtype'] ) ) : '';
		// phpcs:enable

		check_admin_referer( self::ACTION . '_' . $type . '_' . $id );

		$allowed = 'term' === $type ? current_user_can( 'edit_term', $id ) : current_user_can( 'edit_post', $id );
		if ( ! $allowed ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'kdna-seo-writer' ), 403 );
		}

		$problem = KDNA_SeoWriter_Rest_Generate::cannot_generate( $type, $subtype );
		$queued  = '' === $problem && KDNA_SeoWriter_Scheduler::queue( $type, $id, $subtype, 'manual' );

		$back = wp_get_referer();
		$back = $back ? $back : admin_url();
		wp_safe_redirect( add_query_arg( 'kdna_sw_queued', $queued ? 1 : 0, remove_query_arg( 'kdna_sw_queued', $back ) ) );
		exit;
	}

	/**
	 * Shows how many items were queued after a row or bulk action.
	 *
	 * @return void
	 */
	public function queued_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( ! isset( $_GET['kdna_sw_queued'] ) ) {
			return;
		}
		$count = absint( $_GET['kdna_sw_queued'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $count > 0 ) {
			/* translators: %d: number of items. */
			$text  = sprintf( _n( '%d item queued for SEO Writer. Results usually arrive within a few minutes.', '%d items queued for SEO Writer. Results arrive over the next few minutes.', $count, 'kdna-seo-writer' ), $count );
			$class = 'notice-success';
		} else {
			$text  = __( 'Nothing was queued. Check that an API key is saved and that this content type is not set to Off.', 'kdna-seo-writer' );
			$class = 'notice-warning';
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Prints the few styles the column needs, on list screens only.
	 *
	 * @return void
	 */
	public function column_styles(): void {
		echo '<style>.column-kdna_sw{width:11em}.kdna-sw-col::before{content:"";display:inline-block;width:8px;height:8px;margin-right:6px;border-radius:50%;background:#646970}.kdna-sw-col.is-filled::before{background:#00733b}.kdna-sw-col.is-waiting::before{background:#996800}.kdna-sw-col.is-missing::before{background:#b32d2e}.kdna-sw-col-note{color:#646970;font-size:12px}</style>';
	}

	/**
	 * Builds the signed link for the row action.
	 *
	 * @param string $type    Either post or term.
	 * @param int    $id      Post or term ID.
	 * @param string $subtype Post type or taxonomy.
	 * @return string
	 */
	private static function row_url( string $type, int $id, string $subtype ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::ACTION,
					'type'    => $type,
					'id'      => $id,
					'subtype' => $subtype,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $type . '_' . $id
		);
	}
}
