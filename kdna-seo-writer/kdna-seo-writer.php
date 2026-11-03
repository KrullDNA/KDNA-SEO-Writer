<?php
/**
 * Plugin Name:       KDNA SEO Writer
 * Description:       Writes SEO titles, meta descriptions, focus keyphrases, social text and image alt text with Claude, checked against Yoast's rules and saved into Yoast. Backend only, nothing loads on public pages.
 * Version:           1.0.0
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

define( 'KDNA_SW_VERSION', '1.0.0' );
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

	return kdna_sw_is_own_rest_request();
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

	require_once KDNA_SW_DIR . 'includes/class-kdna-sw-plugin.php';
	KDNA_SeoWriter_Plugin::instance()->boot();
}

kdna_sw_boot();
