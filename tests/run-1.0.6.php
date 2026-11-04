<?php
// Version 1.0.6 tests: alt text jobs that failed in Action Scheduler.
// - a too-large image gives a clear message instead of crashing the job,
// - an image that crashes the job twice is recorded and left alone.
// Run: php tests/run-1.0.6.php
require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-wp.php';
$P = dirname( __DIR__ ) . '/kdna-seo-writer/';
define( 'DAY_IN_SECONDS', 86400 ); define( 'OBJECT', 'OBJECT' );
$GLOBALS['can'] = true; $GLOBALS['as'] = []; $GLOBALS['tlist'] = [];
function current_user_can( $cap, ...$a ) { return $GLOBALS['can']; }
function taxonomy_exists( $t ) { return in_array( $t, [ 'category', 'product_cat' ], true ); }
function wp_date( $f, $t = null ) { return gmdate( 'j F Y g:i a', $t ?? time() ); }
function human_time_diff( $t ) { return '2 hours'; }
function get_edit_post_link( $id, $c = '' ) { return 'https://example.com/wp-admin/post.php?post=' . $id; }
function get_edit_term_link( $id, $t = '' ) { return 'https://example.com/wp-admin/term.php?tag_ID=' . $id; }
function get_terms( $a ) { $out = array_values( array_filter( $GLOBALS['tlist'], fn( $t ) => $t->taxonomy === $a['taxonomy'] && ( empty( $a['hide_empty'] ) || $t->count > 0 ) ) ); return ( $a['fields'] ?? '' ) === 'ids' ? array_map( fn( $t ) => $t->term_id, $out ) : $out; }
function as_has_scheduled_action( $hook, $args = null, $group = '' ) { foreach ( $GLOBALS['as'] as $a ) { if ( $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) && ( '' === $group || $a['group'] === $group ) && in_array( $a['status'], [ 'pending', 'in-progress' ], true ) ) return true; } return false; }
function as_schedule_single_action( $ts, $hook, $args = [], $group = '' ) { $status = 'pending'; $GLOBALS['as'][] = compact( 'ts', 'hook', 'args', 'group', 'status' ); return count( $GLOBALS['as'] ); }
function as_unschedule_all_actions( $hook, $args = [], $group = '' ) { $GLOBALS['as'] = array_values( array_filter( $GLOBALS['as'], fn( $a ) => ! ( ( '' === $hook || $a['hook'] === $hook ) && ( '' === $group || $a['group'] === $group ) && 'pending' === $a['status'] ) ) ); }
// Dates in the query are honoured, like the real Action Scheduler.
function as_get_scheduled_actions( $q, $f = 'ids' ) { return array_keys( array_filter( $GLOBALS['as'], fn( $a ) => $a['group'] === $q['group'] && ( empty( $q['hook'] ) || $a['hook'] === $q['hook'] ) && ( empty( $q['status'] ) || $a['status'] === $q['status'] ) && ( ! isset( $q['date'] ) || $a['ts'] <= $q['date'] ) && ( ! isset( $q['modified'] ) || ( $a['modified'] ?? 0 ) >= $q['modified'] ) ) ); }
require __DIR__ . '/sqlite-helpers.php';
foreach ( [ 'plugin', 'settings', 'crypto', 'claude', 'reader', 'install', 'yoast', 'rules', 'keyphrases', 'state', 'log', 'cache', 'writer', 'usage', 'queue', 'scheduler', 'alt-text', 'fill' ] as $f ) require $P . "includes/class-kdna-sw-$f.php";
if ( ! function_exists( 'trailingslashit' ) ) { function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; } }
function wp_convert_hr_to_bytes( $v ) { $v = strtolower( trim( $v ) ); $n = (int) $v; if ( str_contains( $v, 'g' ) ) $n *= 1073741824; elseif ( str_contains( $v, 'm' ) ) $n *= 1048576; elseif ( str_contains( $v, 'k' ) ) $n *= 1024; return $n; }
function wp_get_image_editor( $f ) { $GLOBALS['editor_opened'] = $f; return new WP_Error( 'no', 'no editor in tests' ); }
$fail = 0;
function check( $name, $cond ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; if ( ! $cond ) $fail++; }
KDNA_SeoWriter_Settings::install_defaults();
KDNA_SeoWriter_Crypto::save_key( 'sk-ant-api03-TESTKEY000000000000000' );
$dir = sys_get_temp_dir();
function png( $path, $w, $h ) { $gd = imagecreatetruecolor( $w, $h ); imagepng( $gd, $path ); }
png( "$dir/kdna-big.png", 4000, 3000 );       // a huge original camera photo
png( "$dir/kdna-big-1024x768.png", 1024, 768 ); // WordPress's Large copy
png( "$dir/kdna-big-768x576.png", 768, 576 );
$reply = fn( $alt ) => [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn', 'content' => [ [ 'type' => 'text', 'text' => json_encode( [ 'alt_text' => $alt ] ) ] ], 'usage' => [ 'input_tokens' => 900, 'output_tokens' => 20 ] ] ) ];
page( 543, '<p>Serum</p>', 'publish', 'product', 'Bio Retinol Serum' );
image( 544, 'image/png', 543, "$dir/kdna-big.png" );

echo "-- using the copy WordPress already made\n";
$GLOBALS['sizes'][544] = [ 'large' => [ 'path' => 'kdna-big-1024x768.png', 'width' => 1024, 'height' => 768 ], 'medium_large' => [ 'path' => 'kdna-big-768x576.png', 'width' => 768, 'height' => 576 ] ];
$GLOBALS['http'] = []; $GLOBALS['responses'] = [ $reply( 'Amber dropper bottle of serum on a marble bench' ) ];
unset( $GLOBALS['editor_opened'] );
$res = KDNA_SeoWriter_Alt_Text::generate( 544, 543 );
$sent = base64_decode( json_decode( $GLOBALS['http'][0]['args']['body'], true )['messages'][0]['content'][0]['source']['data'] );
check( 'sends the Large copy, and never opens the 4000 pixel original', $sent === file_get_contents( "$dir/kdna-big-1024x768.png" ) && ! isset( $GLOBALS['editor_opened'] ) && 'ready' === $res['status'] );
$GLOBALS['sizes'][544] = [ 'large' => [ 'path' => 'missing-file.png', 'width' => 1024, 'height' => 768 ], 'medium_large' => [ 'path' => 'kdna-big-768x576.png', 'width' => 768, 'height' => 576 ] ];
$GLOBALS['http'] = []; $GLOBALS['responses'] = [ $reply( 'Amber dropper bottle of serum on a marble bench' ) ];
KDNA_SeoWriter_Alt_Text::generate( 544, 543 );
$sent = base64_decode( json_decode( $GLOBALS['http'][0]['args']['body'], true )['messages'][0]['content'][0]['source']['data'] );
check( 'if the Large file is missing, the next size down is used', $sent === file_get_contents( "$dir/kdna-big-768x576.png" ) );

echo "-- too large to prepare\n";
$GLOBALS['sizes'][544] = [];
check( 'memory check: a 4000 by 3000 photo needs about 72 MB', ! KDNA_SeoWriter_Alt_Text::memory_allows( 4000, 3000 ) || wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) <= 0 || wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) > 80000000 );
$old = ini_get( 'memory_limit' ); ini_set( 'memory_limit', '64M' );
$GLOBALS['http'] = []; $GLOBALS['as'] = [];
KDNA_SeoWriter_Alt_Text::run_job( 544, 543, 'auto' );
ini_set( 'memory_limit', $old );
$last = KDNA_SeoWriter_State::get( 'post', 544 )['last'];
check( 'no smaller copy and not enough memory: a clear message instead of a crash, and no cost', ! $GLOBALS['http'] && ! isset( $GLOBALS['editor_opened'] ) && 'error' === $last['status'] && str_contains( $last['message'], '4000 by 3000 pixels' ) );
check( 'counted as tried, so it is not retried every night', get_post_meta( 544, 'kdna_sw_attempted' ) > 0 && '' === get_post_meta( 544, 'kdna_sw_alt_running' ) );

echo "-- jobs that crash part-way\n";
image( 540, 'image/png', 539, "$dir/kdna-big-768x576.png" );
update_post_meta( 540, 'kdna_sw_alt_running', time() - 3600 ); // last job died part-way
$GLOBALS['http'] = []; $GLOBALS['responses'] = [ $reply( 'Amber dropper bottle of serum on a marble bench' ) ];
KDNA_SeoWriter_Alt_Text::run_job( 540, 539, 'auto' );
check( 'after one crash it is simply tried again', 1 === count( $GLOBALS['http'] ) && 'queued' === KDNA_SeoWriter_State::get( 'post', 540 )['last']['status'] && '' === get_post_meta( 540, 'kdna_sw_alt_crashes' ) );
image( 536, 'image/png', 535, "$dir/kdna-big-768x576.png" );
update_post_meta( 536, 'kdna_sw_alt_running', time() - 3600 ); update_post_meta( 536, 'kdna_sw_alt_crashes', 1 );
$GLOBALS['http'] = [];
KDNA_SeoWriter_Alt_Text::run_job( 536, 535, 'auto' );
$last = KDNA_SeoWriter_State::get( 'post', 536 )['last'];
check( 'after a second crash it is recorded and left alone, so it never fails over and over', ! $GLOBALS['http'] && 'error' === $last['status'] && str_contains( $last['message'], 'stopped twice' ) && get_post_meta( 536, 'kdna_sw_attempted' ) > 0 );
check( 'the crash markers are cleared', '' === get_post_meta( 536, 'kdna_sw_alt_running' ) && '' === get_post_meta( 536, 'kdna_sw_alt_crashes' ) );
image( 537, 'image/png', 535, sys_get_temp_dir() . '/kdna-big-768x576.png' );
update_post_meta( 537, 'kdna_sw_alt_running', time() - 60 );
$GLOBALS['http'] = []; $GLOBALS['responses'] = [ $reply( 'Amber dropper bottle of serum on a marble bench' ) ];
KDNA_SeoWriter_Alt_Text::run_job( 537, 535, 'auto' );
check( 'a marker under 10 minutes old is not counted as a crash', '' === get_post_meta( 537, 'kdna_sw_alt_crashes' ) && 1 === count( $GLOBALS['http'] ) );

foreach ( [ 'kdna-big.png', 'kdna-big-1024x768.png', 'kdna-big-768x576.png' ] as $f ) @unlink( "$dir/$f" );
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
