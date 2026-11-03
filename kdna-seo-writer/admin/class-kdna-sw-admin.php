<?php
/**
 * Admin screens: the settings page.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds Settings > KDNA SEO Writer. Debug mode lives in KDNA_SeoWriter_Debug.
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

		KDNA_SeoWriter_Debug::instance()->hooks();
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
}
