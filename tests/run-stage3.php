<?php
// Stage 3 tests: routing (save or review), the review queue, locks, the save
// guard, the scheduler's decisions, and usage and the spend limit.
// The queue and log tables run in an in-memory SQLite database, so the real
// SQL in the plugin is exercised. Run: php tests/run-stage3.php
require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-wp.php';
$P = dirname( __DIR__ ) . '/kdna-seo-writer/';

define( 'DAY_IN_SECONDS', 86400 ); define( 'OBJECT', 'OBJECT' );
$GLOBALS['can'] = true; $GLOBALS['as'] = []; $GLOBALS['revision'] = false;
function current_user_can( $cap, ...$a ) { return $GLOBALS['can']; }
function wp_is_post_revision( $p ) { return $GLOBALS['revision']; }
function wp_is_post_autosave( $p ) { return false; }
function wp_doing_ajax() { return false; }
function apply_filters( $h, $v, ...$a ) { return $v; }
function human_time_diff( $t ) { return 'a moment'; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function get_edit_post_link( $id, $c = '' ) { return 'https://example.com/wp-admin/post.php?post=' . $id; }
function get_edit_term_link( $id, $t = '' ) { return 'https://example.com/wp-admin/term.php?tag_ID=' . $id; }
function wp_timezone() { return new DateTimeZone( 'Australia/Sydney' ); }
function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); }
function as_has_scheduled_action( $hook, $args = null, $group = '' ) { foreach ( $GLOBALS['as'] as $a ) { if ( $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) ) return true; } return false; }
function as_schedule_single_action( $ts, $hook, $args = [], $group = '' ) { $GLOBALS['as'][] = compact( 'ts', 'hook', 'args', 'group' ); return count( $GLOBALS['as'] ); }
function as_unschedule_all_actions( $hook, $args = [], $group = '' ) { $GLOBALS['as'] = array_values( array_filter( $GLOBALS['as'], fn( $a ) => $a['hook'] !== $hook ) ); }
function as_next_scheduled_action( $hook, $args = null, $group = '' ) { foreach ( $GLOBALS['as'] as $a ) if ( $a['hook'] === $hook ) return $a['ts']; return false; }

// A small $wpdb on SQLite, so the plugin's own SQL really runs.
class WPDB_SQLite {
	public $prefix = 'wp_'; public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts'; public $termmeta = 'wp_termmeta'; public $insert_id = 0; public $pdo;
	function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->sqliteCreateFunction( 'CHAR_LENGTH', fn( $s ) => mb_strlen( (string) $s ), 1 );
		$this->pdo->exec( "CREATE TABLE wp_kdna_sw_queue ( id INTEGER PRIMARY KEY AUTOINCREMENT, object_type TEXT, object_id INTEGER, object_subtype TEXT, field TEXT, current_value TEXT, suggested_value TEXT, checks TEXT, status TEXT DEFAULT 'pending', flag TEXT DEFAULT 'new', fingerprint TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT )" );
		$this->pdo->exec( "CREATE TABLE wp_kdna_sw_log ( id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT DEFAULT 'change', object_type TEXT DEFAULT '', object_id INTEGER DEFAULT 0, object_subtype TEXT DEFAULT '', field TEXT DEFAULT '', old_value TEXT, new_value TEXT, source TEXT DEFAULT '', model TEXT DEFAULT '', input_tokens INTEGER DEFAULT 0, output_tokens INTEGER DEFAULT 0, cache_read_tokens INTEGER DEFAULT 0, cache_write_tokens INTEGER DEFAULT 0, user_id INTEGER DEFAULT 0, created_at TEXT, undone_at TEXT )" );
	}
	function prepare( $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%%|%[sdf]/', function ( $m ) use ( &$i, $args ) {
			if ( '%%' === $m[0] ) return '%';
			$v = $args[ $i++ ];
			return '%s' === $m[0] ? $this->pdo->quote( (string) $v ) : ( '%d' === $m[0] ? (string) (int) $v : (string) (float) $v );
		}, $q );
	}
	function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	private function fake_meta( $q ) { // Yoast keyphrase read, served from the post meta stand-in.
		$out = [];
		foreach ( $GLOBALS['meta'] as $pid => $m ) if ( ! empty( $m['_yoast_wpseo_focuskw'] ) ) $out[] = (object) [ 'post_id' => $pid, 'meta_value' => $m['_yoast_wpseo_focuskw'], 'post_type' => $GLOBALS['posts'][ $pid ]->post_type ?? 'page' ];
		return $out;
	}
	function get_results( $q, $o = OBJECT ) {
		if ( str_contains( $q, 'wp_postmeta' ) ) return $this->fake_meta( $q );
		$rows = $this->pdo->query( $q )->fetchAll( PDO::FETCH_ASSOC );
		return ARRAY_A === $o ? $rows : array_map( fn( $r ) => (object) $r, $rows );
	}
	function get_row( $q, $o = OBJECT ) { $r = $this->get_results( $q, $o ); return $r[0] ?? null; }
	function get_var( $q ) { $r = $this->pdo->query( $q )->fetch( PDO::FETCH_NUM ); return $r ? $r[0] : null; }
	function get_col( $q ) { return []; }
	function query( $q ) { return $this->pdo->exec( $q ); }
	function insert( $t, $d, $f = null ) { $cols = array_keys( $d ); $st = $this->pdo->prepare( "INSERT INTO $t (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')' ); $st->execute( array_values( $d ) ); $this->insert_id = (int) $this->pdo->lastInsertId(); return 1; }
	function update( $t, $d, $w, $f = null, $wf = null ) { $set = implode( ',', array_map( fn( $k ) => "$k = ?", array_keys( $d ) ) ); $where = implode( ' AND ', array_map( fn( $k ) => "$k = ?", array_keys( $w ) ) ); $st = $this->pdo->prepare( "UPDATE $t SET $set WHERE $where" ); $st->execute( array_merge( array_values( $d ), array_values( $w ) ) ); return $st->rowCount(); }
	function delete( $t, $w, $f = null ) { $where = implode( ' AND ', array_map( fn( $k ) => "$k = ?", array_keys( $w ) ) ); $st = $this->pdo->prepare( "DELETE FROM $t WHERE $where" ); $st->execute( array_values( $w ) ); return $st->rowCount(); }
}
$GLOBALS['wpdb'] = new WPDB_SQLite();

foreach ( [ 'plugin', 'settings', 'crypto', 'claude', 'reader', 'install', 'yoast', 'rules', 'keyphrases', 'state', 'log', 'cache', 'writer', 'usage', 'queue', 'scheduler', 'guard' ] as $f ) require $P . "includes/class-kdna-sw-$f.php";

$fail = 0;
function check( $name, $cond ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; if ( ! $cond ) $fail++; }
function q( $sql ) { return $GLOBALS['wpdb']->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
function reset_item( $id ) { unset( $GLOBALS['meta'][ $id ] ); $GLOBALS['wpdb']->pdo->exec( "DELETE FROM wp_kdna_sw_queue WHERE object_id = $id" ); }
function make_post( $id, $type, $title = 'Bio Retinol Serum' ) {
	$p = new WP_Post(); $p->ID = $id; $p->post_type = $type; $p->post_title = $title;
	$p->post_content = '<h1>Bio Retinol Serum</h1><p>Our bio retinol serum smooths the look of fine lines overnight using bakuchiol, a gentle plant alternative.</p><h2>How to use</h2><p>Apply three drops at night after cleansing.</p>';
	$GLOBALS['posts'][ $id ] = $p; return $p;
}
$checks_ok = [ [ 'id' => 'title_width', 'field' => 'seo_title', 'ok' => true, 'message' => 'ok' ], [ 'id' => 'description_length', 'field' => 'meta_description', 'ok' => true, 'message' => 'ok' ] ];
$values = [ 'focus_keyphrase' => 'bio retinol serum', 'seo_title' => 'Bio Retinol Serum for Smoother Looking Skin %%page%% %%sep%% %%sitename%%', 'meta_description' => 'Our bio retinol serum smooths the look of fine lines overnight with gentle bakuchiol and squalane, so skin looks brighter by morning.', 'og_title' => 'Wake up to smoother skin', 'og_description' => 'Plant powered and gentle.', 'x_title' => 'Wake up to smoother skin', 'x_description' => 'Plant powered and gentle.' ];
$ready = [ 'status' => 'ready', 'values' => $values, 'checks' => $checks_ok ];

KDNA_SeoWriter_Settings::install_defaults();
KDNA_SeoWriter_Settings::save( [ 'content' => [ 'min_words' => 10 ] ] );
KDNA_SeoWriter_Crypto::save_key( 'sk-ant-api03-TESTKEY000000000000000' );

echo "-- routing\n";
make_post( 10, 'post' );
$GLOBALS['meta'][10]['_yoast_wpseo_metadesc'] = 'Written by a person.';
$r = KDNA_SeoWriter_Queue::route( 'post', 10, 'post', $ready, 'auto' );
check( 'blog post, auto: empty fields saved', in_array( 'seo_title', $r['saved'], true ) && count( $r['saved'] ) === 6 );
check( 'blog post, auto: existing text left alone and not queued', ! in_array( 'meta_description', $r['saved'], true ) && ! $r['queued'] && get_post_meta( 10, '_yoast_wpseo_metadesc' ) === 'Written by a person.' );
$r = KDNA_SeoWriter_Queue::route( 'post', 10, 'post', array_merge( $ready, [ 'values' => [ 'meta_description' => $values['meta_description'] ] ] ), 'manual' );
check( 'Generate now: replacement for existing text goes to review', $r['queued'] === [ 'meta_description' ] && q( "SELECT flag FROM wp_kdna_sw_queue WHERE object_id = 10" )[0]['flag'] === 'attention' );

make_post( 20, 'page', 'About us' );
$r = KDNA_SeoWriter_Queue::route( 'post', 20, 'page', $ready, 'auto' );
check( 'page (Review type): nothing saved, all queued as new', ! $r['saved'] && count( $r['queued'] ) === 7 && ! get_post_meta( 20, '_yoast_wpseo_title' ) );
check( 'queued rows flagged new', count( q( "SELECT * FROM wp_kdna_sw_queue WHERE object_id = 20 AND flag = 'new'" ) ) === 7 );

make_post( 30, 'post' );
$bad = [ 'status' => 'needs_review', 'values' => $values, 'checks' => [ [ 'id' => 'title_width', 'field' => 'seo_title', 'ok' => false, 'message' => 'too wide' ] ] ];
$r = KDNA_SeoWriter_Queue::route( 'post', 30, 'post', $bad, 'auto' );
check( 'failed checks: blog post not saved, goes to review', ! $r['saved'] && in_array( 'seo_title', $r['queued'], true ) );
check( 'failed field flagged failed, others new', q( "SELECT flag FROM wp_kdna_sw_queue WHERE object_id = 30 AND field = 'seo_title'" )[0]['flag'] === 'failed' && q( "SELECT flag FROM wp_kdna_sw_queue WHERE object_id = 30 AND field = 'focus_keyphrase'" )[0]['flag'] === 'new' );

echo "-- locks\n";
update_post_meta( 10, '_yoast_wpseo_title', 'My own title' );
check( 'person edit locks the field', ! empty( KDNA_SeoWriter_State::refresh_locks( 'post', 10, KDNA_SeoWriter_Yoast::get_all( 'post', 10 ) )['locked']['seo_title'] ) );
update_post_meta( 10, '_yoast_wpseo_opengraph-title', '' ); // person emptied a plugin-written field
KDNA_SeoWriter_State::refresh_locks( 'post', 10, KDNA_SeoWriter_Yoast::get_all( 'post', 10 ) );
check( 'emptied by a person: locked, so automation has nothing to do', ! KDNA_SeoWriter_Scheduler::needs_work( 'post', 10, 'post' ) );
$r = KDNA_SeoWriter_Queue::route( 'post', 10, 'post', $ready, 'auto' );
check( 'auto route leaves locked fields alone', ! $r['saved'] && ! $r['queued'] && get_post_meta( 10, '_yoast_wpseo_title' ) === 'My own title' );
$r = KDNA_SeoWriter_Queue::route( 'post', 10, 'post', $ready, 'manual' );
check( 'Generate now on a locked field suggests a replacement for review', in_array( 'seo_title', $r['queued'], true ) && in_array( 'og_title', $r['queued'], true ) );
$st = KDNA_SeoWriter_State::field_statuses( 'post', 10, KDNA_SeoWriter_Yoast::get_all( 'post', 10 ), [] );
check( 'status shows locked', $st['seo_title'] === 'locked' && $st['focus_keyphrase'] === 'filled' );
KDNA_SeoWriter_State::unlock( 'post', 10, [ 'seo_title' ], KDNA_SeoWriter_Yoast::get_all( 'post', 10 ) );
check( 'unlock clears the lock and keeps the text', empty( KDNA_SeoWriter_State::get( 'post', 10 )['locked']['seo_title'] ) && get_post_meta( 10, '_yoast_wpseo_title' ) === 'My own title' );
check( 'still not locked after unlock (fingerprint now matches)', empty( KDNA_SeoWriter_State::refresh_locks( 'post', 10, KDNA_SeoWriter_Yoast::get_all( 'post', 10 ) )['locked']['seo_title'] ) );

echo "-- review queue\n";
$page = KDNA_SeoWriter_Queue::items( [], 1 );
check( 'items grouped by item', $page['total'] === 3 && count( $page['items'] ) === 3 );
$about = current( array_filter( $page['items'], fn( $i ) => $i['id'] === 20 ) );
check( 'item has live values, suffix and words-only title', $about['title_suffix'] === '- Apotheca' && current( array_filter( $about['fields'], fn( $f ) => $f['field'] === 'seo_title' ) )['suggested'] === 'Bio Retinol Serum for Smoother Looking Skin' );
check( 'filter by content type', KDNA_SeoWriter_Queue::items( [ 'subtype' => 'page' ] )['total'] === 1 );
check( 'filter by flag', KDNA_SeoWriter_Queue::items( [ 'flag' => 'failed' ] )['total'] === 1 );
$fd = KDNA_SeoWriter_Queue::items( [ 'field' => 'social' ] );
check( 'filter by field group shows only those rows', $fd['total'] === 3 && ! array_filter( $fd['items'][0]['fields'], fn( $f ) => ! str_starts_with( $f['field'], 'og_' ) && ! str_starts_with( $f['field'], 'x_' ) ) );
check( 'count badge', KDNA_SeoWriter_Queue::count_items() === 3 );
check( 'waiting fields for a list page', count( KDNA_SeoWriter_Queue::waiting_fields( 'post', [ 20, 30, 999 ] )[20] ) === 7 );

$rows  = q( "SELECT id, field FROM wp_kdna_sw_queue WHERE object_id = 20" );
$by    = array_column( $rows, 'id', 'field' );
$res   = KDNA_SeoWriter_Queue::approve( [ (int) $by['seo_title'], (int) $by['meta_description'] ], [ (int) $by['seo_title'] => 'About Bio Retinol Serum and Our Story' ] );
check( 'approve saves into Yoast', $res['approved'] === 2 && get_post_meta( 20, '_yoast_wpseo_metadesc' ) === $values['meta_description'] );
check( 'edited title saved with Yoast variables', get_post_meta( 20, '_yoast_wpseo_title' ) === 'About Bio Retinol Serum and Our Story %%page%% %%sep%% %%sitename%%' );
check( 'edited field locked, approved one not', ! empty( KDNA_SeoWriter_State::get( 'post', 20 )['locked']['seo_title'] ) && empty( KDNA_SeoWriter_State::get( 'post', 20 )['locked']['meta_description'] ) );
check( 'approved rows removed', ! q( "SELECT * FROM wp_kdna_sw_queue WHERE id IN ({$by['seo_title']}, {$by['meta_description']})" ) );
$by_field = array_column( q( "SELECT field, source FROM wp_kdna_sw_log WHERE kind = 'change' AND object_id = 20" ), 'source', 'field' );
check( 'log records which field was edited and which approved as written', $by_field === [ 'meta_description' => 'approved', 'seo_title' => 'edited' ] );

$kp = (int) $by['focus_keyphrase'];
check( 'reject', KDNA_SeoWriter_Queue::reject( [ $kp ] ) === 1 );
check( 'same suggestion not offered again', false === KDNA_SeoWriter_Queue::add( 'post', 20, 'page', 'focus_keyphrase', '', 'Bio Retinol Serum', [] ) );
check( 'a different suggestion is offered', true === KDNA_SeoWriter_Queue::add( 'post', 20, 'page', 'focus_keyphrase', '', 'retinol night serum', [] ) );
check( 'a newer suggestion replaces the older waiting one', count( q( "SELECT * FROM wp_kdna_sw_queue WHERE object_id = 20 AND field = 'focus_keyphrase' AND status = 'pending'" ) ) === 1 );

$GLOBALS['can'] = false;
$res = KDNA_SeoWriter_Queue::approve_items( [ 'post:30' ] );
check( 'approve refused without permission', 0 === $res['approved'] && $res['errors'] );
$GLOBALS['can'] = true;

KDNA_SeoWriter_Queue::regenerate( [ 'post:30' ] );
check( 'regenerate clears rows and queues a job', ! q( "SELECT * FROM wp_kdna_sw_queue WHERE object_id = 30 AND status = 'pending'" ) && as_has_scheduled_action( 'kdna_sw_generate', [ 'post', 30, 'post', 'regenerate' ] ) && KDNA_SeoWriter_State::job_waiting( 'post', 30 ) );

// Bulk approve: 100 items in batches of ten, as the screen sends them.
for ( $i = 1000; $i < 1100; $i++ ) { make_post( $i, 'page', "Page $i" ); KDNA_SeoWriter_Queue::route( 'post', $i, 'page', $ready, 'auto' ); }
$keys = KDNA_SeoWriter_Queue::matching_keys( [ 'subtype' => 'page' ] );
$t0 = microtime( true ); $done = 0;
foreach ( array_chunk( $keys, 10 ) as $batch ) { $done += KDNA_SeoWriter_Queue::approve_items( $batch )['approved']; }
$secs = microtime( true ) - $t0;
check( "bulk approve of 100 items (" . round( $secs, 2 ) . 's here, in batches of 10)', count( $keys ) === 101 && get_post_meta( 1099, '_yoast_wpseo_title' ) !== '' && $done >= 700 );

echo "-- scheduler\n";
$GLOBALS['as'] = [];
$p = make_post( 40, 'post' ); $p->post_status = 'publish';
KDNA_SeoWriter_Scheduler::on_post_saved( 40, $p );
check( 'publish with empty fields queues one job about two minutes later', count( $GLOBALS['as'] ) === 1 && $GLOBALS['as'][0]['args'] === [ 'post', 40, 'post', 'auto' ] && $GLOBALS['as'][0]['ts'] >= time() + 119 );
KDNA_SeoWriter_Scheduler::on_post_saved( 40, $p );
check( 'saving again does not queue a second job', count( $GLOBALS['as'] ) === 1 );
$GLOBALS['as'] = []; $GLOBALS['revision'] = 40;
KDNA_SeoWriter_Scheduler::on_post_saved( 40, $p );
check( 'revisions ignored', ! $GLOBALS['as'] ); $GLOBALS['revision'] = false;
$p->post_status = 'draft'; KDNA_SeoWriter_Scheduler::on_post_saved( 40, $p );
check( 'drafts ignored', ! $GLOBALS['as'] ); $p->post_status = 'publish';
foreach ( $values as $f => $v ) KDNA_SeoWriter_Yoast::set( 'post', 40, [ $f => $v ] );
KDNA_SeoWriter_Scheduler::on_post_saved( 40, $p );
check( 'all fields filled: no job', ! $GLOBALS['as'] );
KDNA_SeoWriter_Settings::save( [ 'content' => [ 'post_types' => [ 'product' => 'off' ] ] ] );
$pr = make_post( 41, 'product' ); $pr->post_status = 'publish';
KDNA_SeoWriter_Scheduler::on_post_saved( 41, $pr );
check( 'content type Off: no job', ! $GLOBALS['as'] );
KDNA_SeoWriter_Settings::save( [ 'automation' => [ 'on_publish' => false ] ] );
$p2 = make_post( 42, 'post' ); $p2->post_status = 'publish';
KDNA_SeoWriter_Scheduler::on_post_saved( 42, $p2 );
check( 'generate on publish switched off: no job', ! $GLOBALS['as'] );
KDNA_SeoWriter_Settings::save( [ 'automation' => [ 'on_publish' => true ] ] );

$next = KDNA_SeoWriter_Scheduler::next_sweep_time();
$local = ( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() );
check( 'next sweep at 2am site time, in the future', $local->format( 'H:i' ) === '02:00' && $next > time() && $next <= time() + DAY_IN_SECONDS );
KDNA_SeoWriter_Scheduler::schedule_sweep( true );
check( 'sweep booked once', 1 === count( array_filter( $GLOBALS['as'], fn( $a ) => $a['hook'] === 'kdna_sw_sweep' ) ) );
KDNA_SeoWriter_Settings::save( [ 'automation' => [ 'nightly' => false ] ] ); KDNA_SeoWriter_Scheduler::schedule_sweep( true );
check( 'nightly off unbooks it', ! array_filter( $GLOBALS['as'], fn( $a ) => $a['hook'] === 'kdna_sw_sweep' ) );
KDNA_SeoWriter_Settings::save( [ 'automation' => [ 'nightly' => true ] ] );

echo "-- jobs, usage and the spend limit\n";
$reply = fn( array $a ) => [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn', 'content' => [ [ 'type' => 'text', 'text' => json_encode( $a ) ] ], 'usage' => [ 'input_tokens' => 1000000, 'output_tokens' => 100000, 'cache_read_input_tokens' => 2000000, 'cache_creation_input_tokens' => 400000 ] ] ) ];
$good = [ 'focus_keyphrase' => 'plant retinol serum', 'seo_title' => 'Plant Retinol Serum for Smoother Looking Skin', 'meta_description' => 'Our plant retinol serum smooths the look of fine lines overnight with gentle bakuchiol and squalane, so skin looks brighter by morning.', 'social_title' => 'Wake up to smoother skin', 'social_description' => 'Plant powered and gentle.', 'slug' => 'plant-retinol-serum', 'opening_line' => '', 'headings' => [] ];
$GLOBALS['http'] = []; $GLOBALS['responses'] = [ $reply( $good ) ];
make_post( 50, 'post' );
$good['meta_description'] = str_replace( 'plant retinol serum', 'plant retinol serum', $good['meta_description'] );
$GLOBALS['posts'][50]->post_content = str_replace( 'bio retinol', 'plant retinol', $GLOBALS['posts'][50]->post_content );
KDNA_SeoWriter_State::set_job( 'post', 50, true );
KDNA_SeoWriter_Scheduler::run_job( 'post', 50, 'post', 'manual' );
$last = KDNA_SeoWriter_State::get( 'post', 50 )['last'];
check( 'Generate now job saves a blog post and records the outcome', 'saved' === $last['status'] && get_post_meta( 50, '_yoast_wpseo_focuskw' ) === 'plant retinol serum' );
check( 'job flag cleared after the job', ! KDNA_SeoWriter_State::job_waiting( 'post', 50 ) && get_post_meta( 50, 'kdna_sw_attempted' ) > 0 );

$u = KDNA_SeoWriter_Usage::this_month();
// Haiku 5.5 at US$0.10 in / US$0.50 out per million: 1M in = 0.10, 0.1M out = 0.05, 2M cache reads = 0.02, 0.4M cache writes = 0.05.
check( 'cost estimate: input, output, cache read and write (' . $u['cost'] . ')', abs( $u['cost'] - 0.22 ) < 0.0001 && $u['requests'] === 1 );
KDNA_SeoWriter_Settings::save( [ 'connection' => [ 'spend_limit' => 0.2 ] ] ); KDNA_SeoWriter_Usage::reset();
check( 'spend limit reached', KDNA_SeoWriter_Usage::limit_reached() );
$GLOBALS['http'] = [];
make_post( 51, 'post' );
KDNA_SeoWriter_Scheduler::run_job( 'post', 51, 'post', 'auto' );
check( 'automatic job paused at the limit, without calling Claude', 'paused' === KDNA_SeoWriter_State::get( 'post', 51 )['last']['status'] && ! $GLOBALS['http'] );
$GLOBALS['as'] = []; $p51 = $GLOBALS['posts'][51]; $p51->post_status = 'publish';
KDNA_SeoWriter_Scheduler::on_post_saved( 51, $p51 );
check( 'nothing queued on publish at the limit', ! $GLOBALS['as'] );
KDNA_SeoWriter_Settings::save( [ 'connection' => [ 'spend_limit' => 0 ] ] ); KDNA_SeoWriter_Usage::reset();
check( 'limit 0 means no limit', ! KDNA_SeoWriter_Usage::limit_reached() );

echo "-- save guard\n";
make_post( 60, 'post' );
$loaded = time() - 100;
KDNA_SeoWriter_Writer::save( 'post', 60, 'post', [ 'seo_title' => 'Saved in the background %%sep%% %%sitename%%', 'meta_description' => 'Background description.' ], 'auto', true );
$_POST = [ 'ID' => '60', 'kdna_sw_loaded' => (string) $loaded, 'yoast_wpseo_title' => '', 'yoast_wpseo_metadesc' => 'Typed by the editor', 'yoast_wpseo_focuskw' => '' ];
KDNA_SeoWriter_Guard::guard_post( 60 );
check( 'stale empty field gets the stored value back', stripslashes( $_POST['yoast_wpseo_title'] ) === 'Saved in the background %%sep%% %%sitename%%' );
check( 'text typed by the editor is kept', $_POST['yoast_wpseo_metadesc'] === 'Typed by the editor' );
check( 'empty field the plugin never wrote stays empty', $_POST['yoast_wpseo_focuskw'] === '' );
$_POST = [ 'ID' => '60', 'kdna_sw_loaded' => (string) ( time() + 5 ), 'yoast_wpseo_title' => '' ];
KDNA_SeoWriter_Guard::guard_post( 60 );
check( 'editor opened after the write: a cleared field stays cleared', $_POST['yoast_wpseo_title'] === '' );
$_POST = [ 'ID' => '60', 'yoast_wpseo_title' => '' ];
KDNA_SeoWriter_Guard::guard_post( 60 );
check( 'no timestamp: nothing changed', $_POST['yoast_wpseo_title'] === '' );
$GLOBALS['terms'][70] = new WP_Term(); $GLOBALS['terms'][70]->term_id = 70; $GLOBALS['terms'][70]->taxonomy = 'category';
KDNA_SeoWriter_Writer::save( 'term', 70, 'category', [ 'meta_description' => 'Category text.' ], 'auto', true );
$_POST = [ 'kdna_sw_loaded' => (string) $loaded, 'wpseo_desc' => '', 'wpseo_title' => '' ];
KDNA_SeoWriter_Guard::guard_term( 70, 0, 'category' );
check( 'term: stale empty description gets the stored value back', stripslashes( $_POST['wpseo_desc'] ) === 'Category text.' && $_POST['wpseo_title'] === '' );
$_POST = [];

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
