<?php
// Version 1.0.5 tests: why "Check what needs doing" finds fewer items than the
// Coverage table, the option to try recently tried items again, a clear
// "nothing started" message, and Write anyway for short pages.
// Run: php tests/run-1.0.5.php
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

$fail = 0;
function check( $name, $cond ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; if ( ! $cond ) $fail++; }
function term( $id, $name, $count ) { $t = new WP_Term(); $t->term_id = $id; $t->taxonomy = 'category'; $t->name = $name; $t->count = $count; $GLOBALS['tlist'][] = $t; $GLOBALS['terms'][ $id ] = $t; }
function tried( $id, $status, $message, $skipped = '' ) { meta( $id, 'kdna_sw_attempted', time() - 7200 ); KDNA_SeoWriter_State::set_last( 'post', $id, $status, $message ); if ( $skipped ) KDNA_SeoWriter_State::skipped( 'post', $id, $skipped ); }
KDNA_SeoWriter_Settings::install_defaults();
KDNA_SeoWriter_Crypto::save_key( 'sk-ant-api03-TESTKEY000000000000000' );
$all = [ 'title', 'metadesc', 'focuskw', 'opengraph-title', 'opengraph-description', 'twitter-title', 'twitter-description' ];
$words = '<p>' . str_repeat( 'Our bio retinol serum smooths the look of fine lines with gentle plant actives and squalane. ', 8 ) . '</p>';

page( 1, $words, 'publish', 'page', 'About' );            // never tried: in the run
page( 2, '<p>Call us today.</p>', 'publish', 'page', 'Contact' ); tried( 2, 'skipped', 'Not enough content: 3 words found, 50 needed to describe it well.', 'not_enough_content' );
page( 3, $words, 'publish', 'page', 'Story' );             tried( 3, 'error', 'Anthropic is very busy right now. The plugin will try again later.' );
page( 4, $words, 'publish', 'page', 'Locked' );
foreach ( $all as $k ) if ( 'title' !== $k ) meta( 4, '_yoast_wpseo_' . $k, 'Filled' );
KDNA_SeoWriter_State::remember( 'post', 4, [ 'seo_title' => 'Old title' ] ); // a person emptied the plugin's title
page( 5, $words, 'publish', 'page', 'Waiting' );
$db->insert( 'wp_kdna_sw_queue', [ 'object_type' => 'post', 'object_id' => 5, 'object_subtype' => 'page', 'field' => 'seo_title', 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );
page( 6, $words, 'publish', 'page', 'Queued' );            KDNA_SeoWriter_State::set_job( 'post', 6, true ); meta( 6, 'kdna_sw_attempted', time() - 60 );
page( 7, $words, 'publish', 'page', 'Done' );               foreach ( $all as $k ) meta( 7, '_yoast_wpseo_' . $k, 'Filled' );
term( 30, 'Serums', 4 ); term( 31, 'Coming soon', 0 );

echo "-- what is left out, and why\n";
$pre = KDNA_SeoWriter_Fill::preview();
$why = array_column( $pre['left_out']['rows'], 'code', 'title' );
$txt = array_column( $pre['left_out']['rows'], 'reason', 'title' );
check( 'the run covers the page never tried and the category with posts: ' . $pre['items'], 2 === $pre['items'] );
check( 'left out list explains every other item with empty fields (' . $pre['left_out']['count'] . ')', 6 === $pre['left_out']['count'] && ! isset( $why['About'], $why['Done'], $why['Serums'] ) );
check( 'short page: shows the result "Not enough content"', 'short' === $why['Contact'] && str_contains( $txt['Contact'], 'Tried 2 hours ago: Not enough content: 3 words' ) );
check( 'tried with an error: shows the error', 'tried' === $why['Story'] && str_contains( $txt['Story'], 'very busy' ) );
check( 'locked by a person', 'locked' === $why['Locked'] );
check( 'waiting in Review', 'review' === $why['Waiting'] );
check( 'already queued', 'queued' === $why['Queued'] );
check( 'empty category: explained', 'empty' === $why['Coming soon'] && str_contains( $txt['Coming soon'], 'no posts' ) );
check( 'rows link to the edit screen and name the type', 'https://example.com/wp-admin/post.php?post=2' === $pre['left_out']['rows'][0]['link'] || in_array( 'https://example.com/wp-admin/post.php?post=2', array_column( $pre['left_out']['rows'], 'link' ), true ) );

echo "-- try again items tried in the last week\n";
$re = KDNA_SeoWriter_Fill::preview( true );
check( 'with the box ticked, tried items are included (About, Contact, Story, Queued, category): ' . $re['items'], 5 === $re['items'] && $re['retry'] );
check( 'locked and waiting items are still left out', isset( array_column( $re['left_out']['rows'], 'code', 'title' )['Locked'], array_column( $re['left_out']['rows'], 'code', 'title' )['Waiting'] ) && ! isset( array_column( $re['left_out']['rows'], 'code', 'title' )['Contact'] ) );
$st = KDNA_SeoWriter_Fill::start( true );
$ids = array_map( fn( $a ) => $a['args'][1], array_filter( $GLOBALS['as'], fn( $a ) => 'kdna_sw_generate' === $a['hook'] ) );
sort( $ids );
check( 'a retry run queues them', ! is_wp_error( $st ) && [ 1, 2, 3, 6, 30 ] === array_values( $ids ) );
// Pass 1 ran: 1, 2 and 30 were tried during the run; 3's job was stopped by the server.
$GLOBALS['as'] = [];
foreach ( [ 1, 2, 6 ] as $id ) meta( $id, 'kdna_sw_attempted', time() + 1 ); update_term_meta( 30, 'kdna_sw_attempted', time() + 1 );
KDNA_SeoWriter_Fill::check();
$again = array_values( array_map( fn( $a ) => $a['args'][1], array_filter( $GLOBALS['as'], fn( $a ) => 'kdna_sw_generate' === $a['hook'] ) ) );
check( 'pass 2 of a retry run goes back only for what was not tried during the run', [ 3 ] === $again );

echo "-- nothing to start\n";
$GLOBALS['as'] = []; unset( $GLOBALS['opts']['kdna_sw_fill'] );
foreach ( [ 1, 3, 6 ] as $id ) meta( $id, 'kdna_sw_attempted', time() ); update_term_meta( 30, 'kdna_sw_attempted', time() );
$st = KDNA_SeoWriter_Fill::start();
check( 'says nothing was started, instead of "Started" and then vanishing', is_wp_error( $st ) && 'kdna_sw_fill_nothing' === $st->get_error_code() && ! KDNA_SeoWriter_Fill::status()['running'] );

echo "-- background job health\n";
$GLOBALS['as'] = [ [ 'ts' => time() - 3600, 'hook' => 'kdna_sw_generate', 'args' => [ 'post', 9, 'page', 'manual' ], 'group' => 'kdna-seo-writer', 'status' => 'pending' ] ];
check( 'jobs overdue and nothing finished for 20 minutes: stalled', KDNA_SeoWriter_Fill::job_health()['stalled'] );
$GLOBALS['as'][] = [ 'ts' => time() - 300, 'hook' => 'kdna_sw_generate', 'args' => [ 'post', 8, 'page', 'auto' ], 'group' => 'kdna-seo-writer', 'status' => 'complete', 'modified' => time() - 120 ];
check( 'a job finished recently: not stalled (just busy)', ! KDNA_SeoWriter_Fill::job_health()['stalled'] );
$GLOBALS['as'][] = [ 'ts' => time() - 300, 'hook' => 'kdna_sw_alt', 'args' => [ 20, 0, 'auto' ], 'group' => 'kdna-seo-writer-fill', 'status' => 'failed', 'modified' => time() - 600 ];
check( 'failed jobs in the last day are counted', 1 === KDNA_SeoWriter_Fill::job_health()['failed'] );

echo "-- Write anyway\n";
$reply = fn( array $a ) => [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn', 'content' => [ [ 'type' => 'text', 'text' => json_encode( $a ) ] ], 'usage' => [ 'input_tokens' => 100, 'output_tokens' => 10 ] ] ) ];
$good = [ 'focus_keyphrase' => 'contact apotheca', 'seo_title' => 'Contact Apotheca for Skincare Advice and Orders', 'meta_description' => 'Contact apotheca for help choosing corrective skincare, questions about an order, or advice on refills. Our team replies within one business day.', 'social_title' => 'Talk to the Apotheca team', 'social_description' => 'We are here to help.', 'slug' => 'contact', 'opening_line' => '', 'headings' => [] ];
$GLOBALS['as'] = []; $GLOBALS['http'] = [];
KDNA_SeoWriter_Scheduler::run_job( 'post', 2, 'page', 'manual' );
check( 'Generate now on a short page: skipped with the reason, without calling Claude', ! $GLOBALS['http'] && str_contains( KDNA_SeoWriter_State::get( 'post', 2 )['last']['message'], 'Not enough content' ) );
$GLOBALS['http'] = []; $GLOBALS['responses'] = [ $reply( $good ), $reply( $good ) ];
KDNA_SeoWriter_Scheduler::run_job( 'post', 2, 'page', 'short' );
$last = KDNA_SeoWriter_State::get( 'post', 2 )['last'];
check( 'Write anyway: Claude writes from what is there (' . $last['status'] . ')', count( $GLOBALS['http'] ) >= 1 && in_array( $last['status'], [ 'saved', 'queued' ], true ) );
check( 'Write anyway only affects that one job', -1 === KDNA_SeoWriter_Reader::$min_words_override );
$GLOBALS['http'] = [];
page( 8, '<p>Too short again.</p>', 'publish', 'page', 'Short two' );
KDNA_SeoWriter_Scheduler::run_job( 'post', 8, 'page', 'manual' );
check( 'the next Generate now uses the Minimum words setting again', ! $GLOBALS['http'] );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
