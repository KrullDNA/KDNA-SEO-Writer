<?php
/**
 * Edit screen panels: classic meta box, block editor side panel, term edit
 * screen box and the SEO Writer section in Elementor's Page Settings.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows each field's status (filled, waiting for review, locked, missing) and a
 * Generate now button wherever an item is edited.
 *
 * Generate now only queues a background job. The panel then checks back every
 * few seconds and updates itself when the job is done.
 *
 * Every editor screen also carries the save guard's "screen opened at" field,
 * so saving a page that was open while the plugin worked never wipes the
 * plugin's new values (see KDNA_SeoWriter_Guard).
 */
final class KDNA_SeoWriter_Editor {

	/**
	 * Script handle shared by the classic, block editor and term panels.
	 */
	private const SCRIPT = 'kdna-sw-editor';

	/**
	 * The single shared copy of this class.
	 *
	 * @var KDNA_SeoWriter_Editor|null
	 */
	private static ?KDNA_SeoWriter_Editor $instance = null;

	/**
	 * Returns the shared editor object.
	 *
	 * @return KDNA_SeoWriter_Editor
	 */
	public static function instance(): KDNA_SeoWriter_Editor {
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
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_classic' ) );
		add_action( 'admin_init', array( $this, 'add_term_boxes' ) );

		add_action( 'elementor/documents/register_controls', array( $this, 'add_elementor_section' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_elementor' ) );
	}

	/**
	 * Adds the SEO Writer box to the classic editor's sidebar.
	 *
	 * In the block editor this box is replaced by the side panel, so it is
	 * flagged as a classic-only box.
	 *
	 * @param string $post_type Post type being edited.
	 * @param mixed  $post      The post.
	 * @return void
	 */
	public function add_meta_box( string $post_type, $post ): void {
		if ( ! $post instanceof WP_Post || ! self::post_type_enabled( $post_type ) ) {
			return;
		}

		add_meta_box(
			'kdna-sw-panel',
			__( 'SEO Writer', 'kdna-seo-writer' ),
			function ( WP_Post $current ) {
				KDNA_SeoWriter_Guard::hidden_field();
				$this->render_box( 'post', (int) $current->ID, $current->post_type );
			},
			$post_type,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Adds the SEO Writer box to the edit screen of every enabled taxonomy.
	 *
	 * @return void
	 */
	public function add_term_boxes(): void {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			if ( KDNA_SeoWriter_Scheduler::enabled( 'term', $taxonomy ) ) {
				add_action( "{$taxonomy}_edit_form", array( $this, 'render_term_box' ) );
			}
		}
	}

	/**
	 * Prints the term edit screen box (inside the term form).
	 *
	 * @param mixed $term The term.
	 * @return void
	 */
	public function render_term_box( $term ): void {
		if ( ! $term instanceof WP_Term || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return;
		}
		KDNA_SeoWriter_Guard::hidden_field();
		echo '<div class="postbox kdna-sw-term-box"><div class="postbox-header"><h2>' . esc_html__( 'SEO Writer', 'kdna-seo-writer' ) . '</h2></div><div class="inside">';
		$this->render_box( 'term', (int) $term->term_id, $term->taxonomy );
		echo '</div></div>';
	}

	/**
	 * Prints the status list and Generate now button. The editor script takes
	 * it over to keep it up to date.
	 *
	 * @param string $type    Either post or term.
	 * @param int    $id      Post or term ID.
	 * @param string $subtype Post type or taxonomy.
	 * @return void
	 */
	public function render_box( string $type, int $id, string $subtype ): void {
		echo self::box_html( $type, $id, $subtype ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with escaping in box_html().
	}

	/**
	 * Builds the status box markup, escaped.
	 *
	 * @param string $type    Either post or term.
	 * @param int    $id      Post or term ID.
	 * @param string $subtype Post type or taxonomy.
	 * @return string
	 */
	public static function box_html( string $type, int $id, string $subtype ): string {
		$status = KDNA_SeoWriter_Rest_Generate::status( $type, $id, $subtype );

		$html  = '<div class="kdna-sw-box" data-kdna-sw-type="' . esc_attr( $type ) . '" data-kdna-sw-id="' . esc_attr( (string) $id ) . '" data-kdna-sw-taxonomy="' . esc_attr( 'term' === $type ? $subtype : '' ) . '">';
		$html .= '<ul class="kdna-sw-fields">';
		foreach ( $status['fields'] as $field ) {
			$html .= '<li class="kdna-sw-field is-' . esc_attr( $field['status'] ) . '"><span class="kdna-sw-field-label">' . esc_html( $field['label'] ) . '</span> <span class="kdna-sw-field-status">' . esc_html( $field['text'] ) . '</span></li>';
		}
		$html .= '</ul>';

		if ( '' !== $status['problem'] ) {
			$html .= '<p class="kdna-sw-msg">' . esc_html( $status['problem'] ) . '</p>';
		} else {
			$html .= '<p><button type="button" class="button kdna-sw-generate" data-kdna-sw-generate>' . esc_html__( 'Generate now', 'kdna-seo-writer' ) . '</button></p>';
			$html .= '<p class="kdna-sw-msg" role="status" aria-live="polite">' . esc_html( $status['job'] ? __( 'Queued. This updates by itself when Claude has finished.', 'kdna-seo-writer' ) : ( $status['last']['message'] ?? '' ) ) . '</p>';
		}

		$html .= '<p class="description">' . esc_html( self::mode_note( $status['mode'] ) ) . '</p>';
		$html .= '<div class="kdna-sw-report"><h4>' . esc_html__( 'Keyphrase report', 'kdna-seo-writer' ) . '</h4><div class="kdna-sw-report-body" aria-live="polite"><p class="description">' . esc_html__( 'Loading…', 'kdna-seo-writer' ) . '</p></div></div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Loads the side panel script in the block editor for enabled post types.
	 *
	 * @return void
	 */
	public function enqueue_block_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! self::post_type_enabled( (string) $screen->post_type ) ) {
			return;
		}

		$this->enqueue_script( array( 'wp-api-fetch', 'wp-element', 'wp-components', 'wp-data', 'wp-plugins', 'wp-editor' ), array( 'mode' => 'block' ) );
	}

	/**
	 * Loads the panel script on the classic editor and term edit screens.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue_classic( string $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			if ( ! self::post_type_enabled( (string) $screen->post_type ) || $screen->is_block_editor() ) {
				return;
			}
		} elseif ( 'term.php' === $hook_suffix ) {
			if ( ! KDNA_SeoWriter_Scheduler::enabled( 'term', (string) $screen->taxonomy ) ) {
				return;
			}
		} else {
			return;
		}

		$this->enqueue_script( array( 'wp-api-fetch' ), array( 'mode' => 'classic' ) );
	}

	/**
	 * Adds the SEO Writer section to Elementor's Page Settings.
	 *
	 * Checked against Elementor 4.5.0: the elementor/documents/register_controls
	 * action passes the document, and sections added to TAB_SETTINGS appear
	 * under Page Settings. The section is only built while the Elementor
	 * editor is open, so it costs nothing anywhere else.
	 *
	 * @param mixed $document Elementor document.
	 * @return void
	 */
	public function add_elementor_section( $document ): void {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) || ! self::in_elementor_editor() ) {
			return;
		}

		$post_id = (int) $document->get_main_id();
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! self::post_type_enabled( $post->post_type ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$document->start_controls_section(
			'kdna_sw_section',
			array(
				'label' => __( 'SEO Writer', 'kdna-seo-writer' ),
				'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
			)
		);

		$document->add_control(
			'kdna_sw_status',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw'  => self::box_html( 'post', $post_id, $post->post_type ),
			)
		);

		$document->end_controls_section();
	}

	/**
	 * Loads the panel script in the Elementor editor.
	 *
	 * @return void
	 */
	public function enqueue_elementor(): void {
		$this->enqueue_script( array( 'wp-api-fetch' ), array( 'mode' => 'elementor' ) );
		wp_add_inline_style( 'kdna-sw-editor', self::elementor_css() );
	}

	/**
	 * Registers and loads the panel script with its settings.
	 *
	 * @param array<int, string>   $deps  Script dependencies.
	 * @param array<string, mixed> $extra Extra settings for the script.
	 * @return void
	 */
	private function enqueue_script( array $deps, array $extra ): void {
		wp_enqueue_script( self::SCRIPT, KDNA_SW_URL . 'admin/js/editor-panel.js', $deps, KDNA_SW_VERSION, true );
		wp_enqueue_style( 'kdna-sw-editor', KDNA_SW_URL . 'admin/css/editor.css', array(), KDNA_SW_VERSION );

		$data = array_merge(
			array(
				'restPath'  => '/' . KDNA_SW_REST_NAMESPACE,
				'loadedAt'  => time(),
				'guardName' => KDNA_SeoWriter_Guard::FIELD,
				'i18n'      => array(
					'title'      => __( 'SEO Writer', 'kdna-seo-writer' ),
					'generate'   => __( 'Generate now', 'kdna-seo-writer' ),
					'working'    => __( 'Queuing…', 'kdna-seo-writer' ),
					'queued'     => __( 'Queued. This updates by itself when Claude has finished.', 'kdna-seo-writer' ),
					'unlock'     => __( 'Unlock', 'kdna-seo-writer' ),
					'review'     => __( 'Open the Review tab', 'kdna-seo-writer' ),
					'error'      => __( 'Something went wrong. Please try again.', 'kdna-seo-writer' ),
					'savesDraft' => __( 'Write the content first. Generate now saves it as a draft, then writes the SEO.', 'kdna-seo-writer' ),
					'savingFirst' => __( 'Saving your changes first…', 'kdna-seo-writer' ),
					'saveFailed' => __( 'The page could not be saved, so nothing was queued. Save it yourself, then try again.', 'kdna-seo-writer' ),
					'updateFirst' => __( 'You have unsaved changes on this published page. Click Update first, so Claude reads them, then click Generate now.', 'kdna-seo-writer' ),
					'reloadNote' => __( 'Reload the page to see the new text in Yoast\'s box. Saving first is safe: the new values are kept.', 'kdna-seo-writer' ),
					'report'     => __( 'Keyphrase report', 'kdna-seo-writer' ),
					'copy'       => __( 'Copy', 'kdna-seo-writer' ),
					'copied'     => __( 'Copied', 'kdna-seo-writer' ),
					'applySlug'  => __( 'Apply this slug', 'kdna-seo-writer' ),
					'statuses'   => array(
						'good' => __( 'Good', 'kdna-seo-writer' ),
						'okay' => __( 'Could be better', 'kdna-seo-writer' ),
						'bad'  => __( 'Needs work', 'kdna-seo-writer' ),
					),
					'noSuggest'  => __( 'Click Generate now to get suggested wording for anything that needs work.', 'kdna-seo-writer' ),
					'modes'      => array(
						'auto'   => self::mode_note( 'auto' ),
						'review' => self::mode_note( 'review' ),
						'off'    => self::mode_note( 'off' ),
					),
				),
			),
			$extra
		);

		wp_add_inline_script( self::SCRIPT, 'window.kdnaSwEditor = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Explains what Generate now will do for this content type.
	 *
	 * @param string $mode auto, review or off.
	 * @return string
	 */
	private static function mode_note( string $mode ): string {
		if ( 'auto' === $mode ) {
			return __( 'Empty fields are filled straight away. Replacements for existing text wait in the Review tab.', 'kdna-seo-writer' );
		}
		if ( 'review' === $mode ) {
			return __( 'Suggestions wait in the Review tab until they are approved.', 'kdna-seo-writer' );
		}
		return __( 'This content type is set to Off.', 'kdna-seo-writer' );
	}

	/**
	 * Tells you whether the Elementor editor is open.
	 *
	 * @return bool
	 */
	private static function in_elementor_editor(): bool {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && method_exists( \Elementor\Plugin::$instance->editor, 'is_edit_mode' ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of which screen is open.
		return isset( $_GET['action'] ) && 'elementor' === $_GET['action'];
	}

	/**
	 * Tells you whether a post type is public and not set to Off.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function post_type_enabled( string $post_type ): bool {
		$object = get_post_type_object( $post_type );
		return $object && $object->public && 'attachment' !== $post_type && KDNA_SeoWriter_Scheduler::enabled( 'post', $post_type );
	}

	/**
	 * Small styles for the box inside Elementor's dark or light panel.
	 *
	 * @return string CSS.
	 */
	private static function elementor_css(): string {
		return '.elementor-panel .kdna-sw-box .kdna-sw-fields{margin:0 0 10px;padding:0;list-style:none}.elementor-panel .kdna-sw-box .kdna-sw-field{display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-bottom:1px solid var(--e-a-border-color,#e6e8ea)}.elementor-panel .kdna-sw-box .kdna-sw-msg{margin:8px 0}.elementor-panel .kdna-sw-box .description{font-style:italic;opacity:.8}';
	}
}
