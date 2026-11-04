<?php
/**
 * Plugin loader: wires every class to WordPress on backend requests only.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * The main plugin object. Loads classes on demand and registers hooks.
 */
final class KDNA_SeoWriter_Plugin {

	/**
	 * The single shared copy of this class.
	 *
	 * @var KDNA_SeoWriter_Plugin|null
	 */
	private static ?KDNA_SeoWriter_Plugin $instance = null;

	/**
	 * Which optional plugins are active, worked out once on plugins_loaded.
	 *
	 * @var array<string, bool>
	 */
	private array $integrations = array(
		'yoast'       => false,
		'woocommerce' => false,
		'elementor'   => false,
		'jetengine'   => false,
	);

	/**
	 * Returns the shared plugin object, creating it the first time.
	 *
	 * @return KDNA_SeoWriter_Plugin
	 */
	public static function instance(): KDNA_SeoWriter_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers the class loader and the hooks the plugin needs on backend requests.
	 *
	 * @return void
	 */
	public function boot(): void {
		spl_autoload_register( array( $this, 'autoload' ) );

		register_activation_hook( KDNA_SW_FILE, array( 'KDNA_SeoWriter_Install', 'activate' ) );

		// Tell WooCommerce the plugin is fine with High-Performance Order Storage.
		// The plugin never reads or writes orders. This is only registered on
		// backend requests, which is where WooCommerce checks compatibility.
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		add_action( 'plugins_loaded', array( $this, 'detect_integrations' ), 20 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( 'KDNA_SeoWriter_Install', 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Keep the keyphrase register up to date when any keyphrase changes.
		KDNA_SeoWriter_Keyphrases::hooks();

		// Background jobs, and the guard that stops open editors wiping their results.
		KDNA_SeoWriter_Scheduler::hooks();
		KDNA_SeoWriter_Alt_Text::hooks();
		KDNA_SeoWriter_Guard::hooks();
		KDNA_SeoWriter_Fill::hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'kdna-sw', 'KDNA_SeoWriter_Cli' );
		}

		if ( is_admin() ) {
			KDNA_SeoWriter_Admin::instance()->hooks();
		}
	}

	/**
	 * Loads a plugin class file the first time the class is used.
	 *
	 * Class names map to file names, for example KDNA_SeoWriter_Rest_Settings
	 * lives in includes/rest/class-kdna-sw-rest-settings.php.
	 *
	 * @param string $class_name The class PHP is looking for.
	 * @return void
	 */
	public function autoload( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'KDNA_SeoWriter_' ) ) {
			return;
		}

		$slug = strtolower( str_replace( '_', '-', substr( $class_name, strlen( 'KDNA_SeoWriter_' ) ) ) );
		$file = 'class-kdna-sw-' . $slug . '.php';

		$folders = array( 'includes/', 'admin/' );
		if ( str_starts_with( $slug, 'rest-' ) ) {
			array_unshift( $folders, 'includes/rest/' );
		}

		foreach ( $folders as $folder ) {
			$path = KDNA_SW_DIR . $folder . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}

	/**
	 * Declares compatibility with WooCommerce's High-Performance Order Storage.
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', KDNA_SW_FILE, true );
		}
	}

	/**
	 * Works out which optional plugins are active, and warns if Yoast is missing.
	 *
	 * Yoast SEO is required because the plugin writes into Yoast's fields.
	 * WooCommerce, Elementor and JetEngine are optional: their features switch
	 * on when they are active.
	 *
	 * @return void
	 */
	public function detect_integrations(): void {
		$this->integrations = array(
			'yoast'       => defined( 'WPSEO_VERSION' ),
			'woocommerce' => defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.0', '>=' ),
			'elementor'   => defined( 'ELEMENTOR_VERSION' ) && class_exists( '\Elementor\Plugin' ),
			'jetengine'   => function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' ),
		);

		if ( ! $this->integrations['yoast'] ) {
			add_action( 'admin_notices', array( $this, 'notice_yoast_missing' ) );
		}
	}

	/**
	 * Shows a friendly notice when Yoast SEO is not active.
	 *
	 * @return void
	 */
	public function notice_yoast_missing(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'KDNA SEO Writer saves its work into Yoast SEO, so it needs Yoast SEO to be installed and active. The free version is enough. Until then, nothing will be written.', 'kdna-seo-writer' );
		echo '</p></div>';
	}

	/**
	 * Tells you whether an optional plugin is active.
	 *
	 * @param string $name One of yoast, woocommerce, elementor or jetengine.
	 * @return bool True when that plugin is active.
	 */
	public function has( string $name ): bool {
		return ! empty( $this->integrations[ $name ] );
	}

	/**
	 * Returns the full list of which optional plugins are active.
	 *
	 * @return array<string, bool>
	 */
	public function integrations(): array {
		return $this->integrations;
	}

	/**
	 * Loads the plugin's translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'kdna-seo-writer', false, dirname( plugin_basename( KDNA_SW_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the plugin's REST routes (kdna-sw/v1).
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new KDNA_SeoWriter_Rest_Settings() )->register();
		( new KDNA_SeoWriter_Rest_Connection() )->register();
		( new KDNA_SeoWriter_Rest_Generate() )->register();
		( new KDNA_SeoWriter_Rest_Queue() )->register();
		( new KDNA_SeoWriter_Rest_Overview() )->register();
		( new KDNA_SeoWriter_Rest_Report() )->register();
		( new KDNA_SeoWriter_Rest_Fill() )->register();
	}
}
