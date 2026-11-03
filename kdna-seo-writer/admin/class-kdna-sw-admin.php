<?php
/**
 * Admin screens: the settings page and debug mode.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds Settings > KDNA SEO Writer and the debug panel on edit screens.
 *
 * Scripts and styles are only loaded on the plugin's own settings screen,
 * never on other admin screens and never on the front end.
 */
final class KDNA_SeoWriter_Admin {

	/**
	 * Slug of the settings page.
	 */
	public const PAGE = 'kdna-seo-writer';

	/**
	 * Screen ID WordPress gives the settings page, set when the menu is added.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * The single shared copy of this class.
	 *
	 * @var KDNA_SeoWriter_Admin|null
	 */
	private static ?KDNA_SeoWriter_Admin $instance = null;

	/**
	 * Returns the shared admin object.
	 *
	 * @return KDNA_SeoWriter_Admin
	 */
	public static function instance(): KDNA_SeoWriter_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KDNA_SW_FILE ), array( $this, 'action_links' ) );

		add_action( 'admin_init', array( $this, 'maybe_add_debug' ) );
	}

	/**
	 * Switches on the debug panels when an administrator adds ?kdna_sw_debug=1.
	 *
	 * This runs on admin_init, once WordPress knows who is logged in.
	 *
	 * @return void
	 */
	public function maybe_add_debug(): void {
		if ( ! self::debug_requested() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( $this, 'add_debug_meta_box' ), 10, 2 );
		$this->add_term_debug_hooks();
	}

	/**
	 * Adds Settings > KDNA SEO Writer.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_options_page(
			__( 'KDNA SEO Writer', 'kdna-seo-writer' ),
			__( 'KDNA SEO Writer', 'kdna-seo-writer' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link beside the plugin on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'kdna-seo-writer' ) . '</a>' );
		return $links;
	}

	/**
	 * Loads Alpine.js, the settings app and its styles, on the settings page only.
	 *
	 * @param string $hook_suffix The current admin screen.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'kdna-sw-admin', KDNA_SW_URL . 'admin/css/admin.css', array(), KDNA_SW_VERSION );

		// The settings app registers itself with Alpine, so it loads first and
		// Alpine (bundled inside the plugin, version 3.17.5) loads after it.
		wp_enqueue_script(
			'kdna-sw-settings',
			KDNA_SW_URL . 'admin/js/settings.js',
			array( 'wp-api-fetch' ),
			KDNA_SW_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_enqueue_script(
			'kdna-sw-alpine',
			KDNA_SW_URL . 'admin/js/vendor/alpine.min.js',
			array( 'kdna-sw-settings' ),
			'3.17.5',
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script( 'kdna-sw-settings', 'window.kdnaSwData = ' . wp_json_encode( $this->app_data() ) . ';', 'before' );
	}

	/**
	 * Builds the data the settings app starts with. Never includes the API key.
	 *
	 * @return array<string, mixed>
	 */
	private function app_data(): array {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$post_types[] = array(
				'name'  => $type->name,
				'label' => $type->labels->name,
				'mode'  => KDNA_SeoWriter_Settings::mode_for( 'post_type', $type->name ),
			);
		}

		$taxonomies = array();
		foreach ( get_taxonomies(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		) as $taxonomy ) {
			if ( 'post_format' === $taxonomy->name ) {
				continue;
			}
			$taxonomies[] = array(
				'name'  => $taxonomy->name,
				'label' => $taxonomy->labels->name,
				'mode'  => KDNA_SeoWriter_Settings::mode_for( 'taxonomy', $taxonomy->name ),
			);
		}

		$models = array();
		foreach ( KDNA_SeoWriter_Claude::MODELS as $id => $info ) {
			$models[] = array(
				'id'    => $id,
				'label' => $info['label'],
			);
		}

		return array(
			'restPath'     => '/' . KDNA_SW_REST_NAMESPACE,
			'state'        => KDNA_SeoWriter_Rest_Settings::payload(),
			'postTypes'    => $post_types,
			'taxonomies'   => $taxonomies,
			'models'       => $models,
			'integrations' => KDNA_SeoWriter_Plugin::instance()->integrations(),
			'starterRules' => self::starter_rules(),
			'i18n'         => array(
				'genericError'     => __( 'Something went wrong. Please try again.', 'kdna-seo-writer' ),
				'testError'        => __( 'The test could not run. Please try again.', 'kdna-seo-writer' ),
				'confirmRemoveKey' => __( 'Remove the saved API key? The plugin will stop writing until a new key is added.', 'kdna-seo-writer' ),
			),
		);
	}

	/**
	 * Starter claim rules for common client types.
	 *
	 * These are a starting point to edit, written as a guide only. They are not
	 * legal advice, and each client's rules should be checked by someone who
	 * knows their industry.
	 *
	 * @return array<int, array{id: string, label: string, text: string}>
	 */
	private static function starter_rules(): array {
		return array(
			array(
				'id'    => 'cosmetics',
				'label' => __( 'Cosmetics (Australia, TGA)', 'kdna-seo-writer' ),
				'text'  => __( "Cosmetic claims only. Never say a product treats, cures, heals, prevents or repairs a disease, condition or skin damage, because the TGA treats those as therapeutic claims.\nDo not name medical conditions such as acne, eczema, rosacea, psoriasis or dermatitis as something the product fixes.\nUse cosmetic wording such as \"improves the look of\", \"helps skin feel\", \"hydrates\" and \"smooths the appearance of\".\nNo claims that the product changes how the skin works or its structure.\nDo not say \"clinically proven\" unless the page itself describes the study.", 'kdna-seo-writer' ),
			),
			array(
				'id'    => 'health',
				'label' => __( 'Health and wellbeing', 'kdna-seo-writer' ),
				'text'  => __( "Never promise a cure or a guaranteed result.\nDo not diagnose, and do not suggest the service replaces medical advice.\nAvoid \"miracle\", \"instant\", \"guaranteed\" and \"100%\".\nDo not name prescription-only medicines in promotional wording.", 'kdna-seo-writer' ),
			),
			array(
				'id'    => 'finance',
				'label' => __( 'Finance', 'kdna-seo-writer' ),
				'text'  => __( "Never promise returns or guaranteed results.\nDo not describe any product as \"risk-free\" or \"safe\".\nWrite as general information, not personal advice.\nAvoid \"best\" or \"cheapest\" unless the page itself backs it up.", 'kdna-seo-writer' ),
			),
		);
	}

	/**
	 * Prints the settings page. The tabs are driven by the Alpine.js app.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require KDNA_SW_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Tells you whether an administrator has asked for debug mode (?kdna_sw_debug=1).
	 *
	 * @return bool
	 */
	public static function debug_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display switch, checked against capability below.
		if ( ! isset( $_GET['kdna_sw_debug'] ) || '1' !== $_GET['kdna_sw_debug'] ) {
			return false;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Adds the debug panel to the post edit screen when debug mode is on.
	 *
	 * @param string $post_type Post type being edited.
	 * @param mixed  $post      The post being edited.
	 * @return void
	 */
	public function add_debug_meta_box( string $post_type, $post ): void {
		if ( ! $post instanceof WP_Post || 'attachment' === $post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		add_meta_box(
			'kdna-sw-debug',
			__( 'KDNA SEO Writer: debug', 'kdna-seo-writer' ),
			function ( WP_Post $current ) {
				$this->render_debug( KDNA_SeoWriter_Reader::read_post( (int) $current->ID ) );
			},
			null,
			'normal',
			'high'
		);
	}

	/**
	 * Adds the debug panel to category and tag edit screens when debug mode is on.
	 *
	 * @return void
	 */
	private function add_term_debug_hooks(): void {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			add_action(
				"{$taxonomy}_edit_form",
				function ( $term ) {
					if ( $term instanceof WP_Term ) {
						echo '<div class="postbox" style="margin-top:20px;padding:0 12px 12px;"><h2>' . esc_html__( 'KDNA SEO Writer: debug', 'kdna-seo-writer' ) . '</h2>';
						$this->render_debug( KDNA_SeoWriter_Reader::read_term( (int) $term->term_id, $term->taxonomy ) );
						echo '</div>';
					}
				}
			);
		}
	}

	/**
	 * Prints what the page reader produced for an item, for administrators.
	 *
	 * This shows the text that would be sent to Claude. It never shows the API
	 * key or any other setting.
	 *
	 * @param array<string, mixed> $read Reader result.
	 * @return void
	 */
	private function render_debug( array $read ): void {
		$row = static function ( string $label, string $value ): void {
			echo '<tr><th scope="row" style="text-align:left;vertical-align:top;padding:4px 12px 4px 0;width:170px;">' . esc_html( $label ) . '</th><td style="padding:4px 0;">' . esc_html( $value ) . '</td></tr>';
		};

		echo '<p>' . esc_html__( 'This is the text the page reader builds for this item, which is what Claude will be given. Only administrators can see this. The API key is never shown here.', 'kdna-seo-writer' ) . '</p>';
		echo '<table style="border-collapse:collapse;width:100%;"><tbody>';

		$row( __( 'Status', 'kdna-seo-writer' ), $read['ok'] ? __( 'Ready to send', 'kdna-seo-writer' ) : (string) $read['message'] );
		$row( __( 'Read from', 'kdna-seo-writer' ), implode( ', ', (array) $read['sources'] ) );
		$row( __( 'Words', 'kdna-seo-writer' ), (string) $read['word_count'] . ( $read['trimmed'] ? ' ' . __( '(trimmed before sending)', 'kdna-seo-writer' ) : '' ) );
		$row(
			__( 'H1', 'kdna-seo-writer' ),
			(string) $read['h1'] . ( 'title' === $read['h1_from'] ? ' ' . __( '(no H1 in the content, so the title is used)', 'kdna-seo-writer' ) : '' )
		);
		$row( __( 'First paragraph', 'kdna-seo-writer' ), (string) $read['first_paragraph'] );
		$row( __( 'H2 headings', 'kdna-seo-writer' ), implode( ' | ', (array) $read['h2'] ) );
		$row( __( 'H3 headings', 'kdna-seo-writer' ), implode( ' | ', (array) $read['h3'] ) );

		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Full text', 'kdna-seo-writer' ) . '</h3>';
		echo '<pre style="white-space:pre-wrap;max-height:420px;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #dcdcde;">' . esc_html( (string) $read['text'] ) . '</pre>';
	}
}
