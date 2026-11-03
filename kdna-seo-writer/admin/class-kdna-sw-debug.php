<?php
/**
 * Debug mode (?kdna_sw_debug=1): reader output, test generate, change log with undo.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds an administrators-only debug panel to post and term edit screens.
 *
 * Add &kdna_sw_debug=1 to the address of an edit screen to see:
 * - the text the page reader builds (what Claude is given),
 * - Yoast's title template and current values for the item,
 * - Test generate (shows Claude's answer and every rule check, saves nothing),
 * - Generate and save into empty fields,
 * - the item's recent changes, each with an Undo link.
 *
 * Test generate runs while you wait because it is a testing tool for
 * administrators. Normal generation runs in the background (Stage 3).
 * The API key is never shown here.
 */
final class KDNA_SeoWriter_Debug {

	/**
	 * admin-post.php action name.
	 */
	private const ACTION = 'kdna_sw_debug';

	/**
	 * The single shared copy of this class.
	 *
	 * @var KDNA_SeoWriter_Debug|null
	 */
	private static ?KDNA_SeoWriter_Debug $instance = null;

	/**
	 * Returns the shared debug object.
	 *
	 * @return KDNA_SeoWriter_Debug
	 */
	public static function instance(): KDNA_SeoWriter_Debug {
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
		add_action( 'admin_init', array( $this, 'maybe_add_panels' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
	}

	/**
	 * Tells you whether an administrator has asked for debug mode.
	 *
	 * @return bool
	 */
	public static function requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display switch, checked against capability below.
		if ( ! isset( $_GET['kdna_sw_debug'] ) || '1' !== $_GET['kdna_sw_debug'] ) {
			return false;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Adds the debug panels when debug mode is on. Runs once the user is known.
	 *
	 * @return void
	 */
	public function maybe_add_panels(): void {
		if ( ! self::requested() ) {
			return;
		}

		add_action( 'add_meta_boxes', array( $this, 'add_post_panel' ), 10, 2 );

		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form", array( $this, 'render_term_panel' ) );
		}
	}

	/**
	 * Adds the panel to the post edit screen.
	 *
	 * @param string $post_type Post type being edited.
	 * @param mixed  $post      The post being edited.
	 * @return void
	 */
	public function add_post_panel( string $post_type, $post ): void {
		if ( ! $post instanceof WP_Post || 'attachment' === $post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		add_meta_box(
			'kdna-sw-debug',
			__( 'KDNA SEO Writer: debug', 'kdna-seo-writer' ),
			function ( WP_Post $current ) {
				$this->render_panel( 'post', (int) $current->ID, $current->post_type );
			},
			null,
			'normal',
			'high'
		);
	}

	/**
	 * Prints the panel below the category or tag edit form.
	 *
	 * @param mixed $term The term being edited.
	 * @return void
	 */
	public function render_term_panel( $term ): void {
		if ( ! $term instanceof WP_Term || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return;
		}
		echo '<div class="postbox" style="margin-top:20px;padding:0 12px 12px;"><h2>' . esc_html__( 'KDNA SEO Writer: debug', 'kdna-seo-writer' ) . '</h2>';
		$this->render_panel( 'term', (int) $term->term_id, $term->taxonomy );
		echo '</div>';
	}

	/**
	 * Runs a debug action (test generate, generate and save, undo), then returns
	 * to the edit screen with the result.
	 *
	 * @return void
	 */
	public function handle_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce checked just below.
		$type    = isset( $_GET['type'] ) && 'term' === $_GET['type'] ? 'term' : 'post';
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$subtype = isset( $_GET['subtype'] ) ? sanitize_key( wp_unslash( $_GET['subtype'] ) ) : '';
		$do      = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$log_id  = isset( $_GET['log'] ) ? absint( $_GET['log'] ) : 0;
		// phpcs:enable

		check_admin_referer( self::nonce_action( $type, $id ) );

		$can_edit = 'term' === $type ? current_user_can( 'edit_term', $id ) : current_user_can( 'edit_post', $id );
		if ( ! current_user_can( 'manage_options' ) || ! $can_edit ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'kdna-seo-writer' ), 403 );
		}

		// Generating can take two requests to Claude, so allow more time than
		// the usual 30 seconds. This only applies to this test request.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 150 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Test tool, see comment above.
		}

		$payload = array(
			'do'   => $do,
			'time' => time(),
		);

		if ( 'preview' === $do || 'save' === $do ) {
			$result            = KDNA_SeoWriter_Writer::generate( $type, $id, $subtype );
			$payload['result'] = $result;

			if ( 'save' === $do && $result['values'] ) {
				$saved            = KDNA_SeoWriter_Writer::save( $type, $id, $subtype, $result['values'], 'test', true );
				$payload['saved'] = is_wp_error( $saved ) ? $saved->get_error_message() : $saved;
			}
		} elseif ( 'undo' === $do && $log_id ) {
			$undone           = KDNA_SeoWriter_Log::undo( $log_id );
			$payload['undo'] = is_wp_error( $undone ) ? $undone->get_error_message() : true;
		}

		set_transient( self::result_key( $type, $id ), $payload, 10 * MINUTE_IN_SECONDS );

		$back = 'term' === $type ? get_edit_term_link( $id, $subtype ) : get_edit_post_link( $id, 'url' );
		wp_safe_redirect( add_query_arg( 'kdna_sw_debug', '1', (string) $back ) );
		exit;
	}

	/**
	 * Prints the whole panel for one item.
	 *
	 * @param string $type    Either post or term.
	 * @param int    $id      Post or term ID.
	 * @param string $subtype Post type or taxonomy.
	 * @return void
	 */
	private function render_panel( string $type, int $id, string $subtype ): void {
		$read = KDNA_SeoWriter_Reader::read( $type, $id, $subtype );

		echo '<p>' . esc_html__( 'Only administrators can see this panel. The API key is never shown here.', 'kdna-seo-writer' ) . '</p>';

		$this->render_last_result( $type, $id );
		$this->render_actions( $type, $id, $subtype );
		$this->render_yoast( $type, $id, $subtype );
		$this->render_changes( $type, $id, $subtype );
		$this->render_reader( $read );
	}

	/**
	 * Prints the Test generate and Generate and save links.
	 *
	 * @param string $type    Either post or term.
	 * @param int    $id      Post or term ID.
	 * @param string $subtype Post type or taxonomy.
	 * @return void
	 */
	private function render_actions( string $type, int $id, string $subtype ): void {
		echo '<h3>' . esc_html__( 'Try it', 'kdna-seo-writer' ) . '</h3><p>';
		echo '<a class="button" href="' . esc_url( self::action_url( $type, $id, $subtype, 'preview' ) ) . '">' . esc_html__( 'Test generate (saves nothing)', 'kdna-seo-writer' ) . '</a> ';
		echo '<a class="button button-primary" href="' . esc_url( self::action_url( $type, $id, $subtype, 'save' ) ) . '">' . esc_html__( 'Generate and save into empty fields', 'kdna-seo-writer' ) . '</a>';
		echo '</p><p class="description">' . esc_html__( 'Each click asks Claude now, which takes a few seconds and costs a fraction of a cent. Saving only fills Yoast fields that are empty, and every change can be undone below. Save the page first if you have unsaved edits.', 'kdna-seo-writer' ) . '</p>';
	}

	/**
	 * Prints the result of the last debug action, if there is one.
	 *
	 * @param string $type Either post or term.
	 * @param int    $id   Post or term ID.
	 * @return void
	 */
	private function render_last_result( string $type, int $id ): void {
		$payload = get_transient( self::result_key( $type, $id ) );
		if ( ! is_array( $payload ) ) {
			return;
		}
		delete_transient( self::result_key( $type, $id ) );

		echo '<div style="border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#fff;padding:4px 12px;margin:12px 0;">';

		if ( isset( $payload['undo'] ) ) {
			echo '<p>' . esc_html( true === $payload['undo'] ? __( 'Undone. The previous value is back in Yoast.', 'kdna-seo-writer' ) : (string) $payload['undo'] ) . '</p></div>';
			return;
		}

		$result = (array) ( $payload['result'] ?? array() );
		$status = array(
			'ready'        => __( 'Ready: every check passed', 'kdna-seo-writer' ),
			'needs_review' => __( 'Needs review: some checks failed', 'kdna-seo-writer' ),
			'skipped'      => __( 'Skipped', 'kdna-seo-writer' ),
			'error'        => __( 'Error', 'kdna-seo-writer' ),
		);

		echo '<h3>' . esc_html( $status[ $result['status'] ?? 'error' ] ?? '' ) . '</h3>';
		echo '<p>' . esc_html( (string) ( $result['message'] ?? '' ) ) . ' ';
		/* translators: %d: number of requests sent to Claude. */
		echo esc_html( sprintf( _n( '(%d request to Claude)', '(%d requests to Claude)', (int) ( $result['attempts'] ?? 0 ), 'kdna-seo-writer' ), (int) ( $result['attempts'] ?? 0 ) ) ) . '</p>';

		if ( ! empty( $result['values'] ) ) {
			$this->table_start();
			foreach ( (array) $result['values'] as $field => $value ) {
				$this->row( KDNA_SeoWriter_Rules::field_label( (string) $field ), (string) $value );
			}
			$this->row( __( 'Shown in Google as', 'kdna-seo-writer' ), (string) $result['rendered_title'] );
			$this->row( __( 'Suggested slug', 'kdna-seo-writer' ), (string) $result['suggestions']['slug'] );
			$this->row( __( 'Suggested opening line', 'kdna-seo-writer' ), (string) $result['suggestions']['opening_line'] );
			$this->row( __( 'Suggested headings', 'kdna-seo-writer' ), implode( ' | ', (array) $result['suggestions']['headings'] ) );
			echo '</tbody></table>';

			echo '<ul style="margin:8px 0 8px 4px;">';
			foreach ( (array) $result['checks'] as $check ) {
				$mark = $check['ok'] ? '<span style="color:#00733b;" aria-hidden="true">&#10003;</span>' : '<span style="color:#b32d2e;" aria-hidden="true">&#10007;</span>';
				$word = $check['ok'] ? __( 'Pass:', 'kdna-seo-writer' ) : __( 'Fail:', 'kdna-seo-writer' );
				echo '<li>' . $mark . ' <span class="screen-reader-text">' . esc_html( $word ) . '</span>' . esc_html( (string) $check['message'] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $mark is fixed markup.
			}
			echo '</ul>';
		}

		if ( array_key_exists( 'saved', $payload ) ) {
			if ( is_array( $payload['saved'] ) ) {
				$labels = array_map( array( 'KDNA_SeoWriter_Rules', 'field_label' ), $payload['saved'] );
				echo '<p><strong>' . esc_html( $labels ? __( 'Saved into Yoast:', 'kdna-seo-writer' ) . ' ' . implode( ', ', $labels ) : __( 'Nothing saved: every field already had text.', 'kdna-seo-writer' ) ) . '</strong></p>';
			} else {
				echo '<p><strong>' . esc_html( (string) $payload['saved'] ) . '</strong></p>';
			}
		}

		echo '</div>';
	}

	/**
	 * Prints Yoast's title template and the item's current Yoast values.
	 *
	 * @param string $type    Either post or term.
	 * @param int    $id      Post or term ID.
	 * @param string $subtype Post type or taxonomy.
	 * @return void
	 */
	private function render_yoast( string $type, int $id, string $subtype ): void {
		echo '<h3>' . esc_html__( 'Yoast', 'kdna-seo-writer' ) . '</h3>';

		if ( ! KDNA_SeoWriter_Yoast::is_active() ) {
			echo '<p>' . esc_html__( 'Yoast SEO is not active.', 'kdna-seo-writer' ) . '</p>';
			return;
		}

		$template = KDNA_SeoWriter_Yoast::title_template( $type, $subtype );

		$this->table_start();
		$this->row( __( 'Yoast version', 'kdna-seo-writer' ), KDNA_SeoWriter_Yoast::version() );
		$this->row( __( 'Title template', 'kdna-seo-writer' ), $template );
		$this->row( __( 'Added after the title', 'kdna-seo-writer' ), KDNA_SeoWriter_Yoast::title_suffix( $template ) );
		foreach ( KDNA_SeoWriter_Yoast::get_all( $type, $id, $subtype ) as $field => $value ) {
			$this->row( KDNA_SeoWriter_Rules::field_label( $field ), '' !== $value ? $value : __( '(empty)', 'kdna-seo-writer' ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Prints the item's recent changes with Undo links.
	 *
	 * @param string $type    Either post or term.
	 * @param int    $id      Post or term ID.
	 * @param string $subtype Post type or taxonomy.
	 * @return void
	 */
	private function render_changes( string $type, int $id, string $subtype ): void {
		$rows = KDNA_SeoWriter_Log::for_item( $type, $id, 20 );

		echo '<h3>' . esc_html__( 'Recent changes', 'kdna-seo-writer' ) . '</h3>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'The plugin has not changed anything on this item yet.', 'kdna-seo-writer' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="margin-bottom:12px;"><thead><tr>';
		foreach ( array( __( 'Log ID', 'kdna-seo-writer' ), __( 'Field', 'kdna-seo-writer' ), __( 'Before', 'kdna-seo-writer' ), __( 'After', 'kdna-seo-writer' ), __( 'Source', 'kdna-seo-writer' ), __( 'Date (UTC)', 'kdna-seo-writer' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $log ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $log['id'] ) . '</td>';
			echo '<td>' . esc_html( KDNA_SeoWriter_Rules::field_label( (string) $log['field'] ) ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $log['old_value'] ? (string) $log['old_value'] : __( '(empty)', 'kdna-seo-writer' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $log['new_value'] ) . '</td>';
			echo '<td>' . esc_html( (string) $log['source'] ) . '</td>';
			echo '<td>' . esc_html( (string) $log['created_at'] ) . '</td>';
			echo '<td>';
			if ( ! empty( $log['undone_at'] ) ) {
				esc_html_e( 'Undone', 'kdna-seo-writer' );
			} elseif ( 'undo' !== $log['source'] ) {
				echo '<a href="' . esc_url( self::action_url( $type, $id, $subtype, 'undo', (int) $log['id'] ) ) . '">' . esc_html__( 'Undo', 'kdna-seo-writer' ) . '</a>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Prints what the page reader produced.
	 *
	 * @param array<string, mixed> $read Reader result.
	 * @return void
	 */
	private function render_reader( array $read ): void {
		echo '<h3>' . esc_html__( 'Page reader', 'kdna-seo-writer' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'This is the text Claude is given for this item.', 'kdna-seo-writer' ) . '</p>';

		$this->table_start();
		$this->row( __( 'Status', 'kdna-seo-writer' ), $read['ok'] ? __( 'Ready to send', 'kdna-seo-writer' ) : (string) $read['message'] );
		$this->row( __( 'Read from', 'kdna-seo-writer' ), implode( ', ', (array) $read['sources'] ) );
		$this->row( __( 'Words', 'kdna-seo-writer' ), (string) $read['word_count'] . ( $read['trimmed'] ? ' ' . __( '(trimmed before sending)', 'kdna-seo-writer' ) : '' ) );
		$this->row( __( 'H1', 'kdna-seo-writer' ), (string) $read['h1'] . ( 'title' === $read['h1_from'] ? ' ' . __( '(no H1 in the content, so the title is used)', 'kdna-seo-writer' ) : '' ) );
		$this->row( __( 'First paragraph', 'kdna-seo-writer' ), (string) $read['first_paragraph'] );
		$this->row( __( 'H2 headings', 'kdna-seo-writer' ), implode( ' | ', (array) $read['h2'] ) );
		$this->row( __( 'H3 headings', 'kdna-seo-writer' ), implode( ' | ', (array) $read['h3'] ) );
		echo '</tbody></table>';

		echo '<h4>' . esc_html__( 'Full text', 'kdna-seo-writer' ) . '</h4>';
		echo '<pre style="white-space:pre-wrap;max-height:420px;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #dcdcde;">' . esc_html( (string) $read['text'] ) . '</pre>';
	}

	/**
	 * Opens a two-column table.
	 *
	 * @return void
	 */
	private function table_start(): void {
		echo '<table style="border-collapse:collapse;width:100%;margin-bottom:12px;"><tbody>';
	}

	/**
	 * Prints one label and value row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private function row( string $label, string $value ): void {
		echo '<tr><th scope="row" style="text-align:left;vertical-align:top;padding:4px 12px 4px 0;width:190px;">' . esc_html( $label ) . '</th><td style="padding:4px 0;word-break:break-word;">' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * Builds a signed link to a debug action.
	 *
	 * @param string $type    Either post or term.
	 * @param int    $id      Post or term ID.
	 * @param string $subtype Post type or taxonomy.
	 * @param string $do      preview, save or undo.
	 * @param int    $log_id  For undo, the log row ID.
	 * @return string
	 */
	private static function action_url( string $type, int $id, string $subtype, string $do, int $log_id = 0 ): string {
		$args = array(
			'action'  => self::ACTION,
			'type'    => $type,
			'id'      => $id,
			'subtype' => $subtype,
			'do'      => $do,
		);
		if ( $log_id ) {
			$args['log'] = $log_id;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::nonce_action( $type, $id ) );
	}

	/**
	 * Returns the nonce action for an item's debug links.
	 *
	 * @param string $type Either post or term.
	 * @param int    $id   Post or term ID.
	 * @return string
	 */
	private static function nonce_action( string $type, int $id ): string {
		return 'kdna_sw_debug_' . $type . '_' . $id;
	}

	/**
	 * Returns the name of the short-lived store for an item's last debug result.
	 *
	 * @param string $type Either post or term.
	 * @param int    $id   Post or term ID.
	 * @return string
	 */
	private static function result_key( string $type, int $id ): string {
		return 'kdna_sw_dbg_' . get_current_user_id() . '_' . $type . '_' . $id;
	}
}
