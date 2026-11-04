<?php
// Version 1.0.2 tests: saving settings never replaces the API key with
// something that is not an Anthropic key, and a rejected key saves nothing.
// Run: php tests/run-1.0.2.php
require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-wp.php';
define( 'DAY_IN_SECONDS', 86400 );
$P = dirname( __DIR__ ) . '/kdna-seo-writer/';
foreach ( [ 'plugin', 'settings', 'crypto', 'claude', 'install', 'yoast', 'state', 'usage', 'scheduler', 'queue', 'rules', 'keyphrases', 'log', 'cache', 'writer', 'reader' ] as $f ) require $P . "includes/class-kdna-sw-$f.php";
class WP_REST_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return $this->p[ $k ] ?? null; } }
class WP_REST_Server { const READABLE = 'GET'; const EDITABLE = 'POST'; const CREATABLE = 'POST'; }
function rest_ensure_response( $r ) { return $r; }
require $P . 'includes/rest/class-kdna-sw-rest-settings.php';

$fail = 0;
function check( $name, $cond ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; if ( ! $cond ) $fail++; }
KDNA_SeoWriter_Settings::install_defaults();
$rest = new KDNA_SeoWriter_Rest_Settings();

$r = $rest->save( new WP_REST_Request( [ 'api_key' => 'sk-ant-api03-REALKEY1234567890abcdef' ] ) );
check( 'a real key is saved', ! is_wp_error( $r ) && KDNA_SeoWriter_Crypto::get_key() === 'sk-ant-api03-REALKEY1234567890abcdef' );

$r = $rest->save( new WP_REST_Request( [ 'api_key' => 'MyLongWordPressPassword2026abcdef', 'settings' => [ 'brand' => [ 'name' => 'Changed' ] ] ] ) );
check( 'a long letters-and-numbers password is refused', is_wp_error( $r ) && $r->get_error_code() === 'kdna_sw_bad_key' );
check( 'the saved key is untouched', KDNA_SeoWriter_Crypto::get_key() === 'sk-ant-api03-REALKEY1234567890abcdef' );
check( 'nothing else was saved either', KDNA_SeoWriter_Settings::get( 'brand', 'name' ) === '' );

$r = $rest->save( new WP_REST_Request( [ 'settings' => [ 'brand' => [ 'name' => 'Apotheca®' ] ] ] ) );
check( 'saving another tab without a key keeps the key', ! is_wp_error( $r ) && KDNA_SeoWriter_Crypto::get_key() === 'sk-ant-api03-REALKEY1234567890abcdef' && KDNA_SeoWriter_Settings::get( 'brand', 'name' ) === 'Apotheca®' );
check( 'the response never contains the key', ! str_contains( json_encode( $r ), 'REALKEY' ) && $r['key']['saved'] === true );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
