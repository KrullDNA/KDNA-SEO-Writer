<?php
// Version 1.0.4 tests: why Fill empty fields now missed items, and the fixes.
// - a job held back because the server was busy was never booked again,
// - temporary Anthropic errors (busy, rate limited) counted as a try, so the item was left for a week,
// - jobs the server stopped part-way were counted as done, and nobody went back for them.
// Run: php tests/run-1.0.4.php
require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-wp.php';
$P = dirname( __DIR__ ) . '/kdna-seo-writer/';
define( 'DAY_IN_SECONDS', 86400 ); define( 'OBJECT', 'OBJECT' );
$GLOBALS['can'] = true; $GLOBALS['as'] = [];
function current_user_can( $cap, ...$a ) { return $GLOBALS['can']; }
function taxonomy_exists( $t ) { return in_array( $t, [ 'category', 'product_cat' ], true ); }
function wp_date( $f, $t = null ) { return gmdate( 'j F Y g:i a', $t ?? time() ); }
function get_terms( $a ) { return []; }
// Action Scheduler stand-ins with groups and statuses, like the real one:
// as_has_scheduled_action also sees the job that is running right now.
function as_has_scheduled_action( $hook, $args = null, $group = '' ) { foreach ( $GLOBALS['as'] as $a ) { if ( $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) && ( '' === $group || $a['group'] === $group ) && in_array( $a['status'], [ 'pending', 'in-progress' ], true ) ) return true; } return false; }
function as_schedule_single_action( $ts, $hook, $args = [], $group = '' ) { $status = 'pending'; $GLOBALS['as'][] = compact( 'ts', 'hook', 'args', 'group', 'status' ); return count( $GLOBALS['as'] ); }
function as_unschedule_all_actions( $hook, $args = [], $group = '' ) { $GLOBALS['as'] = array_values( array_filter( $GLOBALS['as'], fn( $a ) => ! ( ( '' === $hook || $a['hook'] === $hook ) && ( '' === $group || $a['group'] === $group ) && 'pending' === $a['status'] ) ) ); }
function as_get_scheduled_actions( $q, $f = 'ids' ) { return array_keys( array_filter( $GLOBALS['as'], fn( $a ) => $a['group'] === $q['group'] && ( empty( $q['hook'] ) || $a['hook'] === $q['hook'] ) && ( empty( $q['status'] ) || $a['status'] === $q['status'] ) ) ); }
require __DIR__ . '/sqlite-helpers.php';
foreach ( [ 'plugin', 'settings', 'crypto', 'claude', 'reader', 'install', 'yoast', 'rules', 'keyphrases', 'state', 'log', 'cache', 'writer', 'usage', 'queue', 'scheduler', 'alt-text', 'fill' ] as $f ) require $P . "includes/class-kdna-sw-$f.php";

$fail = 0;
function check( $name, $cond ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; if ( ! $cond ) $fail++; }
function jobs( $hook, $group = null, $status = 'pending' ) { return array_values( array_filter( $GLOBALS['as'], fn( $a ) => $a['hook'] === $hook && ( null === $group || $a['group'] === $group ) && $a['status'] === $status ) ); }
KDNA_SeoWriter_Settings::install_defaults();
KDNA_SeoWriter_Crypto::save_key( 'sk-ant-api03-TESTKEY000000000000000' );
$words = '<p>' . str_repeat( 'Our bio retinol serum smooths the look of fine lines with gentle plant actives and squalane. ', 8 ) . '</p>';
page( 1, $words, 'publish', 'post' );
page( 2, $words, 'publish', 'post' );
page( 3, $words, 'publish', 'post' );

echo "-- server busy\n";
$GLOBALS['filters']['kdna_sw_max_load'] = fn( $v ) => -1; // always busy
$GLOBALS['as'] = [ [ 'ts' => time(), 'hook' => 'kdna_sw_generate', 'args' => [ 'post', 1, 'post', 'auto' ], 'group' => 'kdna-seo-writer', 'status' => 'in-progress' ] ];
KDNA_SeoWriter_Scheduler::run_job( 'post', 1, 'post', 'auto' );
$again = jobs( 'kdna_sw_generate' );
check( 'a job held back because the server is busy is booked again (it used to be dropped)', 1 === count( $again ) && $again[0]['args'] === [ 'post', 1, 'post', 'auto' ] && $again[0]['ts'] >= time() + 899 );
update_option( 'kdna_sw_fill', [ 'started' => time(), 'total' => 3, 'pass' => 1, 'stopped' => 0, 'done' => 0 ] );
$GLOBALS['as'] = [ [ 'ts' => time(), 'hook' => 'kdna_sw_generate', 'args' => [ 'post', 1, 'post', 'auto' ], 'group' => 'kdna-seo-writer-fill', 'status' => 'in-progress' ] ];
KDNA_SeoWriter_Scheduler::run_job( 'post', 1, 'post', 'auto' );
check( 'during a Fill run it stays in the run, so the run waits for it', 1 === count( jobs( 'kdna_sw_generate', 'kdna-seo-writer-fill' ) ) );
$GLOBALS['as'] = [ [ 'ts' => time(), 'hook' => 'kdna_sw_alt', 'args' => [ 20, 0, 'auto' ], 'group' => 'kdna-seo-writer-fill', 'status' => 'in-progress' ] ];
KDNA_SeoWriter_Alt_Text::run_job( 20, 0, 'auto' );
check( 'same for alt text jobs', 1 === count( jobs( 'kdna_sw_alt', 'kdna-seo-writer-fill' ) ) );
delete_option( 'kdna_sw_fill' );
unset( $GLOBALS['filters']['kdna_sw_max_load'] );
check( 'busy is judged per processor core (default limit at least 4)', ! KDNA_SeoWriter_Scheduler::server_busy() || sys_getloadavg()[0] > 4 );

echo "-- temporary and permanent errors\n";
$err = fn( $code, $type ) => [ 'response' => [ 'code' => $code ], 'headers' => [], 'body' => json_encode( [ 'type' => 'error', 'error' => [ 'type' => $type, 'message' => 'x' ] ] ) ];
$GLOBALS['as'] = []; $GLOBALS['http'] = []; $GLOBALS['responses'] = [ $err( 400, 'overloaded_error' ) ];
KDNA_SeoWriter_Scheduler::run_job( 'post', 2, 'post', 'auto' );
$last = KDNA_SeoWriter_State::get( 'post', 2 )['last'];
check( 'Anthropic busy: recorded, but not counted as a try, so it is picked up again', 1 === count( $GLOBALS['http'] ) && 'error' === $last['status'] && '' === get_post_meta( 2, 'kdna_sw_attempted' ) );
$GLOBALS['http'] = []; $GLOBALS['responses'] = [ $err( 400, 'rate_limit_error' ) ];
KDNA_SeoWriter_Scheduler::run_job( 'post', 2, 'post', 'auto' );
check( 'rate limited: not counted as a try', '' === get_post_meta( 2, 'kdna_sw_attempted' ) );
$GLOBALS['http'] = []; $GLOBALS['responses'] = [ $err( 401, 'authentication_error' ) ];
KDNA_SeoWriter_Scheduler::run_job( 'post', 3, 'post', 'auto' );
check( 'a permanent error (bad key) still counts as a try', get_post_meta( 3, 'kdna_sw_attempted' ) > 0 );
check( 'temporary check: unreachable yes, bad key no', KDNA_SeoWriter_Claude::is_temporary( new WP_Error( 'kdna_sw_unreachable', 'x' ) ) && ! KDNA_SeoWriter_Claude::is_temporary( new WP_Error( 'kdna_sw_api_authentication_error', 'x', [ 'status' => 401 ] ) ) && KDNA_SeoWriter_Claude::is_temporary( new WP_Error( 'kdna_sw_api_529', 'x', [ 'status' => 529 ] ) ) );

echo "-- no double booking\n";
$GLOBALS['as'] = [ [ 'ts' => time(), 'hook' => 'kdna_sw_generate', 'args' => [ 'post', 1, 'post', 'auto' ], 'group' => 'kdna-seo-writer', 'status' => 'pending' ] ];
KDNA_SeoWriter_Scheduler::queue( 'post', 1, 'post', 'auto', 0, 'kdna-seo-writer-fill' );
check( 'an item already booked by the nightly sweep is not booked again by a Fill run', 1 === count( $GLOBALS['as'] ) );

echo "-- passes\n";
$GLOBALS['as'] = []; $GLOBALS['opts']['kdna_sw_fill'] = null; unset( $GLOBALS['opts']['kdna_sw_fill'] );
$st = KDNA_SeoWriter_Fill::start();
check( 'pass 1 books every item, plus a check after the last job', 3 === $st['total'] && 3 === count( jobs( 'kdna_sw_generate', 'kdna-seo-writer-fill' ) ) && 1 === count( jobs( 'kdna_sw_fill_check' ) ) && $st['running'] && 1 === $st['pass'] );
check( 'the check is not counted as a job in the progress', 0 === $st['done'] && 3 === $st['pending'] );
// The check runs while a job is still waiting: it looks again later.
array_shift( $GLOBALS['as'] ); $GLOBALS['as'] = array_values( array_filter( $GLOBALS['as'], fn( $a ) => 'kdna_sw_fill_check' !== $a['hook'] ) );
KDNA_SeoWriter_Fill::check();
check( 'while jobs are still waiting, the check looks again later', 1 === count( jobs( 'kdna_sw_fill_check' ) ) && 1 === KDNA_SeoWriter_Fill::status()['pass'] );
// Every job has now run. Items 1 and 2 were done (tried), item 3 was stopped by the server part-way (never tried).
$GLOBALS['as'] = [];
meta( 1, 'kdna_sw_attempted', time() ); meta( 2, 'kdna_sw_attempted', time() );
KDNA_SeoWriter_Fill::check();
$st = KDNA_SeoWriter_Fill::status();
check( 'pass 2 goes back for the item the server stopped, and only that one', 2 === $st['pass'] && 4 === $st['total'] && 1 === count( jobs( 'kdna_sw_generate', 'kdna-seo-writer-fill' ) ) && 3 === jobs( 'kdna_sw_generate', 'kdna-seo-writer-fill' )[0]['args'][1] && $st['running'] );
check( 'the progress bar carries on: 3 of 4 done', 3 === $st['done'] );
$GLOBALS['as'] = []; meta( 3, 'kdna_sw_attempted', time() );
KDNA_SeoWriter_Fill::check();
$st = KDNA_SeoWriter_Fill::status();
check( 'when nothing is left the run finishes by itself', ! $st['running'] && $st['finished'] && 0 === $st['left'] && ! jobs( 'kdna_sw_fill_check' ) );

echo "-- the last pass\n";
$db->pdo->exec( "DELETE FROM wp_postmeta WHERE meta_key = 'kdna_sw_attempted'" ); unset( $GLOBALS['opts']['kdna_sw_fill'] );
$GLOBALS['as'] = [];
KDNA_SeoWriter_Fill::start();
for ( $i = 0; $i < 6; $i++ ) { $GLOBALS['as'] = []; KDNA_SeoWriter_Fill::check(); } // jobs keep failing every pass
$st = KDNA_SeoWriter_Fill::status();
check( 'stops after ' . $st['passes'] . ' passes and reports what is left: ' . $st['left'], 5 === $st['pass'] && $st['finished'] && 3 === $st['left'] );
$GLOBALS['as'] = []; unset( $GLOBALS['opts']['kdna_sw_fill'] );
KDNA_SeoWriter_Fill::start(); KDNA_SeoWriter_Fill::stop();
check( 'stop also cancels the check', ! jobs( 'kdna_sw_fill_check' ) && ! KDNA_SeoWriter_Fill::status()['running'] && ! KDNA_SeoWriter_Fill::is_running() );
$GLOBALS['as'] = [];
KDNA_SeoWriter_Fill::check();
check( 'a check after a stop does nothing', ! $GLOBALS['as'] );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
