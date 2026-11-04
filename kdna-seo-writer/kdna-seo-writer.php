<?php
/**
 * Plugin Name:       KDNA SEO Writer
 * Description:       Writes SEO titles, meta descriptions, focus keyphrases, social text and image alt text with Claude, checked against Yoast's rules and saved into Yoast. Backend only, nothing loads on public pages.
 * Version:           1.0.2
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            KDNA
 * Author URI:        https://krulldna.com
 * Text Domain:       kdna-seo-writer
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * WC requires at least: 9.0
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

define( 'KDNA_SW_VERSION', '1.0.2' );
define( 'KDNA_SW_FILE', __FILE__ );
define( 'KDNA_SW_DIR', plugin_dir_path( __FILE__ ) );
define( 'KDNA_SW_URL', plugin_dir_url( __FILE__ ) );
define( 'KDNA_SW_MIN_PHP', '8.1' );
define( 'KDNA_SW_MIN_WP', '6.5' );
define( 'KDNA_SW_REST_NAMESPACE', 'kdna-sw/v1' );

/**
 * Works out whether this request is one where the plugin has any work to do.
 *
 * The plugin is a backend tool. It only wakes up in wp-admin (which also covers
 * the Elementor editor and admin-ajax, where Action Scheduler's async runner
 * lives), in WP-Cron and WP-CLI (where Action Scheduler jobs run), and in its
 * own REST routes. On a normal public page view this returns false and the
 * plugin registers nothing at all, so visitors' page loads are untouched.
 * The block editor's save requests are included too (see
 * kdna_sw_is_editor_save_request()), so publishing from the block editor is
 * noticed.
 *
 * @return bool True when the plugin should load its code.
 */
function kdna_sw_is_backend_request(): bool {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}

	if ( is_admin() || wp_doing_cron() ) {
		return true;
	}

	return kdna_sw_is_own_rest_request() || kdna_sw_is_editor_save_request();
}

/**
 * Checks whether the request is the block editor saving a post or page.
 *
 * The block editor saves through the core REST API (/wp/v2/...) rather than
 * wp-admin, so the plugin has to wake up for those requests to notice a
 * publish or update. Only requests that change something (POST, PUT, PATCH)
 * to the core /wp/v2/ routes count, and those need a logged-in editor.
 * Visitors' requests, including REST reads and shop basket requests to other
 * routes, are never matched.
 *
 * @return bool True for a write request to the core /wp/v2/ REST routes.
 */
function kdna_sw_is_editor_save_request(): bool {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against fixed values.
	if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the route name.
	if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return str_starts_with( '/' . ltrim( wp_unslash( $_GET['rest_route'] ), '/' ), '/wp/v2/' );
	}

	if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared as a plain string only.
	$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
	return str_contains( $path, '/' . trim( rest_get_url_prefix(), '/' ) . '/wp/v2/' );
}

/**
 * Checks whether the current request is aimed at one of this plugin's REST routes.
 *
 * WordPress has not parsed the request yet when plugins load, so this looks at
 * the raw address. It covers both pretty permalinks (/wp-json/kdna-sw/v1/...)
 * and plain permalinks (?rest_route=/kdna-sw/v1/...).
 *
 * @return bool True for a request to the kdna-sw/v1 namespace.
 */
function kdna_sw_is_own_rest_request(): bool {
	$namespace = '/' . KDNA_SW_REST_NAMESPACE;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the route name, nothing is processed here.
	if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$route = '/' . ltrim( wp_unslash( $_GET['rest_route'] ), '/' );
		if ( str_starts_with( $route, $namespace ) ) {
			return true;
		}
	}

	if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared as a plain string only.
	$path   = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
	$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . $namespace;

	return str_contains( $path, $prefix );
}

/**
 * Checks the server meets the plugin's minimum PHP and WordPress versions.
 *
 * @return string Empty when everything is fine, otherwise a plain-English problem.
 */
function kdna_sw_requirements_problem(): string {
	global $wp_version;

	if ( version_compare( PHP_VERSION, KDNA_SW_MIN_PHP, '<' ) ) {
		/* translators: 1: required PHP version, 2: current PHP version. */
		return sprintf( __( 'KDNA SEO Writer needs PHP %1$s or newer. This server is running PHP %2$s, so the plugin has paused itself. Your host can update PHP for you.', 'kdna-seo-writer' ), KDNA_SW_MIN_PHP, PHP_VERSION );
	}

	if ( version_compare( (string) $wp_version, KDNA_SW_MIN_WP, '<' ) ) {
		/* translators: 1: required WordPress version, 2: current WordPress version. */
		return sprintf( __( 'KDNA SEO Writer needs WordPress %1$s or newer. This site is running WordPress %2$s, so the plugin has paused itself. Please update WordPress first.', 'kdna-seo-writer' ), KDNA_SW_MIN_WP, (string) $wp_version );
	}

	return '';
}

/**
 * Checks whether WooCommerce is switched on, before plugins have loaded.
 *
 * Reads the list of active plugins, which WordPress has already loaded, so
 * this adds no database query.
 *
 * @return bool
 */
function kdna_sw_woocommerce_active(): bool {
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	return in_array( 'woocommerce/woocommerce.php', $active, true );
}

/**
 * Starts the plugin, but only on backend requests.
 *
 * On public page views this function returns straight away without adding a
 * single hook, so the plugin costs visitors nothing.
 *
 * @return void
 */
function kdna_sw_boot(): void {
	if ( ! kdna_sw_is_backend_request() ) {
		return;
	}

	$problem = kdna_sw_requirements_problem();
	if ( '' !== $problem ) {
		add_action(
			'admin_notices',
			static function () use ( $problem ) {
				if ( current_user_can( 'activate_plugins' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $problem ) . '</p></div>';
				}
			}
		);
		return;
	}

	// Background jobs run through Action Scheduler. WooCommerce ships its own
	// copy; without WooCommerce, the copy bundled in lib/ is loaded (4.2.0).
	// Action Scheduler itself makes sure only the newest copy runs.
	if ( ! kdna_sw_woocommerce_active() && is_readable( KDNA_SW_DIR . 'lib/action-scheduler/action-scheduler.php' ) ) {
		require_once KDNA_SW_DIR . 'lib/action-scheduler/action-scheduler.php';
	}

	require_once KDNA_SW_DIR . 'includes/class-kdna-sw-plugin.php';
	KDNA_SeoWriter_Plugin::instance()->boot();
}

kdna_sw_boot();
