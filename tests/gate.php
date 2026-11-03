<?php
// Checks the plugin registers nothing on public requests, and wakes up only for
// backend ones (wp-admin, its own REST routes, block editor saves).
// Usage: php tests/gate.php <request-uri> [rest_route] [admin] [METHOD]
require __DIR__ . '/stubs.php';
$GLOBALS['wp_version'] = '6.8';
$GLOBALS['hooks'] = 0;
function add_action( ...$a ) { $GLOBALS['hooks']++; }
function add_filter( ...$a ) { $GLOBALS['hooks']++; }
function register_activation_hook( ...$a ) { $GLOBALS['hooks']++; }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function is_multisite() { return false; }
function get_site_option( $k, $d = false ) { return $d; }
function did_action( $h ) { return 0; }
function doing_action( $h = null ) { return false; }
$GLOBALS['opts']['active_plugins'] = [ 'wordpress-seo/wp-seo.php' ];
$_SERVER['REQUEST_URI']    = $argv[1];
$_SERVER['REQUEST_METHOD'] = $argv[4] ?? 'GET';
if ( ! empty( $argv[2] ) ) {
	$_GET['rest_route'] = $argv[2];
}
$GLOBALS['is_admin'] = ( $argv[3] ?? '' ) === 'admin';
// Action Scheduler's loader registers its own hooks; count them separately.
require dirname( __DIR__ ) . '/kdna-seo-writer/kdna-seo-writer.php';
echo $_SERVER['REQUEST_METHOD'], ' ', $_SERVER['REQUEST_URI'], ( ! empty( $argv[2] ) ? ' rest_route=' . $argv[2] : '' ), ' => hooks registered: ', $GLOBALS['hooks'], ', plugin loaded: ', class_exists( 'KDNA_SeoWriter_Plugin', false ) ? 'yes' : 'no', "\n";
