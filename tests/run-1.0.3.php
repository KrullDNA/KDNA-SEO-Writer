<?php
// Version 1.0.3 tests: Fill empty fields now (preview, cost estimate, start,
// progress, stop). Posts and meta run in SQLite so the real sweep query is used.
// Run: php tests/run-1.0.3.php
require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-wp.php';
$P = dirname( __DIR__ ) . '/kdna-seo-writer/';
define( 'DAY_IN_SECONDS', 86400 ); define( 'OBJECT', 'OBJECT' );
$GLOBALS['can'] = true; $GLOBALS['as'] = [];
function current_user_can( $cap, ...$a ) { return $GLOBALS['can']; }
function taxonomy_exists( $t ) { return in_array( $t, [ 'category', 'product_cat' ], true ); }
function wp_date( $f, $t = null ) { return gmdate( 'j F Y g:i a', $t ?? time() ); }
function get_terms( $a ) { return []; }
// Action Scheduler stand-ins that keep jobs per group.
function as_has_scheduled_action( $hook, $args = null, $group = '' ) { foreach ( $GLOBALS['as'] as $a ) { if ( $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) && ( '' === $group || $a['group'] === $group ) ) return true; } return false; }
function as_schedule_single_action( $ts, $hook, $args = [], $group = '' ) { $GLOBALS['as'][] = compact( 'ts', 'hook', 'args', 'group' ); return count( $GLOBALS['as'] ); }
function as_unschedule_all_actions( $hook, $args = [], $group = '' ) { $GLOBALS['as'] = array_values( array_filter( $GLOBALS['as'], fn( $a ) => ! ( ( '' === $hook || $a['hook'] === $hook ) && ( '' === $group || $a['group'] === $group ) ) ) ); }
function as_get_scheduled_actions( $q, $f = 'ids' ) { return 'pending' !== ( $q['status'] ?? 'pending' ) ? [] : array_keys( array_filter( $GLOBALS['as'], fn( $a ) => $a['group'] === $q['group'] && ( empty( $q['hook'] ) || $a['hook'] === $q['hook'] ) ) ); }
require __DIR__ . '/sqlite-helpers.php';
foreach ( [ 'plugin', 'settings', 'crypto', 'claude', 'reader', 'install', 'yoast', 'rules', 'keyphrases', 'state', 'log', 'cache', 'writer', 'usage', 'queue', 'scheduler', 'alt-text', 'fill' ] as $f ) require $P . "includes/class-kdna-sw-$f.php";

$fail = 0;
function check( $name, $cond ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; if ( ! $cond ) $fail++; }
KDNA_SeoWriter_Settings::install_defaults();

echo "-- before a key is saved\n";
check( 'cannot start without a key', '' !== KDNA_SeoWriter_Fill::cannot_start() && is_wp_error( KDNA_SeoWriter_Fill::start() ) );
KDNA_SeoWriter_Crypto::save_key( 'sk-ant-api03-TESTKEY000000000000000' );
check( 'ready once a key is saved', '' === KDNA_SeoWriter_Fill::cannot_start() );

echo "-- what needs doing\n";
page( 1, '<p>One</p>', 'publish', 'post' );
page( 2, '<p>Two</p>', 'publish', 'post' );
page( 3, '<p>Three</p>', 'publish', 'page' );
page( 4, '<p>Done</p>', 'publish', 'page' );
page( 5, '<p>Draft</p>', 'draft', 'post' );
foreach ( [ 'title', 'metadesc', 'focuskw', 'opengraph-title', 'opengraph-description', 'twitter-title', 'twitter-description' ] as $k ) meta( 4, '_yoast_wpseo_' . $k, 'Filled' );
image( 20 ); image( 21 ); meta( 21, '_wp_attachment_image_alt', 'Has alt' );
$pre = KDNA_SeoWriter_Fill::preview();
$types = array_column( $pre['by_type'], 'count', 'label' );
check( 'counts published items with empty fields, not filled ones or drafts: ' . $pre['items'], 3 === $pre['items'] && 2 === $types['Posts'] && 1 === $types['Pages'] );
check( 'counts images with no alt text', 1 === $pre['images'] );
$expected = 3 * KDNA_SeoWriter_Usage::cost( 'claude-haiku-5-5', 3900, 1200, 0, 0 ) + KDNA_SeoWriter_Usage::cost( 'claude-haiku-5-5', 2000, 180, 0, 0 );
check( 'estimate uses the chosen model\'s prices (US$' . $pre['estimate'] . ')', abs( $pre['estimate'] - ceil( $expected * 100 ) / 100 ) < 0.0001 && $pre['estimate'] >= 0.01 && ! $pre['over_limit'] );
KDNA_SeoWriter_Settings::save( [ 'connection' => [ 'model' => 'claude-sonnet-5-5' ] ] );
check( 'a dearer model gives a higher estimate', KDNA_SeoWriter_Fill::preview()['estimate'] > $pre['estimate'] );

KDNA_SeoWriter_Settings::save( [ 'connection' => [ 'model' => 'claude-haiku-5-5', 'spend_limit' => 0.01 ] ] );
$db->insert( 'wp_kdna_sw_log', [ 'kind' => 'usage', 'model' => 'claude-haiku-5-5', 'input_tokens' => 90000, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] ); KDNA_SeoWriter_Usage::reset(); // US$0.009 spent
check( 'warns when the estimate would pass the spend limit', KDNA_SeoWriter_Fill::preview()['over_limit'] );
KDNA_SeoWriter_Settings::save( [ 'connection' => [ 'spend_limit' => 0 ] ] ); KDNA_SeoWriter_Usage::reset();
check( 'no warning when there is no limit', ! KDNA_SeoWriter_Fill::preview()['over_limit'] );

echo "-- running\n";
$GLOBALS['as'] = [ [ 'ts' => time(), 'hook' => 'kdna_sw_generate', 'args' => [ 'post', 99, 'post', 'auto' ], 'group' => 'kdna-seo-writer' ] ];
$st = KDNA_SeoWriter_Fill::start();
$fill = array_values( array_filter( $GLOBALS['as'], fn( $a ) => 'kdna-seo-writer-fill' === $a['group'] && 'kdna_sw_fill_check' !== $a['hook'] ) );
check( 'queues every item and image in its own group', ! is_wp_error( $st ) && 4 === count( $fill ) && $st['total'] === 4 && $st['items'] === 3 && $st['images'] === 1 );
check( 'items fill empty fields only (auto mode)', 'auto' === $fill[0]['args'][3] && 'kdna_sw_alt' === $fill[3]['hook'] );
check( 'jobs are spaced out', $fill[1]['ts'] - $fill[0]['ts'] === 10 && $fill[3]['ts'] - $fill[0]['ts'] === 30 );
check( 'progress shows running, 0 of 4', $st['running'] && 0 === $st['done'] );
check( 'a second run cannot start while one is going', is_wp_error( KDNA_SeoWriter_Fill::start() ) );
array_splice( $GLOBALS['as'], 1, 2 ); // two jobs have run
$st = KDNA_SeoWriter_Fill::status();
check( 'progress counts jobs as they run: ' . $st['done'] . ' of ' . $st['total'], 2 === $st['done'] && $st['running'] );

echo "-- stopping\n";
$st = KDNA_SeoWriter_Fill::stop();
check( 'stop cancels the rest of this run only', ! array_filter( $GLOBALS['as'], fn( $a ) => 'kdna-seo-writer-fill' === $a['group'] ) && 1 === count( $GLOBALS['as'] ) && 'kdna-seo-writer' === $GLOBALS['as'][0]['group'] );
check( 'status shows stopped', ! $st['running'] && $st['stopped'] && ! $st['finished'] );

echo "-- finishing\n";
$GLOBALS['as'] = [];
KDNA_SeoWriter_Fill::start();
$GLOBALS['as'] = []; // every job has run, and each item was tried
foreach ( [ 1, 2, 3, 20 ] as $id ) meta( $id, 'kdna_sw_attempted', time() );
KDNA_SeoWriter_Fill::check();
$st = KDNA_SeoWriter_Fill::status();
check( 'status shows finished when every job has run', ! $st['running'] && $st['finished'] && 4 === $st['done'] && '' !== $st['started'] );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
