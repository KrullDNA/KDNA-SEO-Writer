<?php
// Stage 4 tests: images in the page reader, finding an item's images, image
// usage across pages, the alt text sweep query, alt text writing (with fake
// Claude replies and a real PNG), approve and undo, Decorative, and every
// keyphrase report rule. Posts, meta, queue and log run in SQLite.
// Run: php tests/run-stage4.php
require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-wp.php';
$P = dirname( __DIR__ ) . '/kdna-seo-writer/';

define( 'DAY_IN_SECONDS', 86400 ); define( 'OBJECT', 'OBJECT' );
$GLOBALS['can'] = true; $GLOBALS['as'] = [];
function current_user_can( $cap, ...$a ) { return $GLOBALS['can']; }
function wp_is_post_revision( $p ) { return false; }
function wp_is_post_autosave( $p ) { return false; }
function wp_doing_ajax() { return false; }
function human_time_diff( $t ) { return 'a moment'; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function get_edit_post_link( $id, $c = '' ) { return 'https://example.com/wp-admin/post.php?post=' . $id; }
function as_has_scheduled_action( $hook, $args = null, $group = '' ) { foreach ( $GLOBALS['as'] as $a ) { if ( $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) ) return true; } return false; }
function as_schedule_single_action( $ts, $hook, $args = [], $group = '' ) { $GLOBALS['as'][] = compact( 'ts', 'hook', 'args', 'group' ); return count( $GLOBALS['as'] ); }
function wp_update_post( $data, $err = false ) { $GLOBALS['posts'][ $data['ID'] ]->post_name = $data['post_name']; return $data['ID']; }

class WPDB_SQLite4 {
	public $prefix = 'wp_'; public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts'; public $termmeta = 'wp_termmeta'; public $insert_id = 0; public $pdo;
	function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->sqliteCreateFunction( 'CONCAT', fn( ...$p ) => implode( '', $p ), -1 );
		$this->pdo->exec( "CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT DEFAULT 'publish', post_content TEXT DEFAULT '', post_parent INTEGER DEFAULT 0, post_mime_type TEXT DEFAULT '', post_date TEXT DEFAULT '2026-01-01', post_password TEXT DEFAULT '' )" );
		$this->pdo->exec( "CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT )" );
		$this->pdo->exec( "CREATE TABLE wp_kdna_sw_queue ( id INTEGER PRIMARY KEY AUTOINCREMENT, object_type TEXT, object_id INTEGER, object_subtype TEXT, context_id INTEGER DEFAULT 0, field TEXT, current_value TEXT, suggested_value TEXT, checks TEXT, status TEXT DEFAULT 'pending', flag TEXT DEFAULT 'new', fingerprint TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT )" );
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
	function get_results( $q, $o = OBJECT ) { $rows = $this->pdo->query( $q )->fetchAll( PDO::FETCH_ASSOC ); return ARRAY_A === $o ? $rows : array_map( fn( $r ) => (object) $r, $rows ); }
	function get_row( $q, $o = OBJECT ) { $r = $this->get_results( $q, $o ); return $r[0] ?? null; }
	function get_var( $q ) { $r = $this->pdo->query( $q )->fetch( PDO::FETCH_NUM ); return $r ? $r[0] : null; }
	function get_col( $q ) { return $this->pdo->query( $q )->fetchAll( PDO::FETCH_COLUMN ); }
	function query( $q ) { return $this->pdo->exec( $q ); }
	function insert( $t, $d, $f = null ) { $cols = array_keys( $d ); $st = $this->pdo->prepare( "INSERT INTO $t (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')' ); $st->execute( array_values( $d ) ); $this->insert_id = (int) $this->pdo->lastInsertId(); return 1; }
	function update( $t, $d, $w, $f = null, $wf = null ) { $set = implode( ',', array_map( fn( $k ) => "$k = ?", array_keys( $d ) ) ); $where = implode( ' AND ', array_map( fn( $k ) => "$k = ?", array_keys( $w ) ) ); $st = $this->pdo->prepare( "UPDATE $t SET $set WHERE $where" ); $st->execute( array_merge( array_values( $d ), array_values( $w ) ) ); return $st->rowCount(); }
	function delete( $t, $w, $f = null ) { $where = implode( ' AND ', array_map( fn( $k ) => "$k = ?", array_keys( $w ) ) ); $st = $this->pdo->prepare( "DELETE FROM $t WHERE $where" ); $st->execute( array_values( $w ) ); return $st->rowCount(); }
}
$GLOBALS['wpdb'] = $db = new WPDB_SQLite4();

// Keep post meta in both the stand-in store and SQLite, so plugin reads and SQL agree.
function meta( $id, $key, $value ) {
	global $db;
	$GLOBALS['meta'][ $id ][ $key ] = $value;
	$db->pdo->prepare( 'DELETE FROM wp_postmeta WHERE post_id = ? AND meta_key = ?' )->execute( [ $id, $key ] );
	$db->pdo->prepare( 'INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( ?, ?, ? )' )->execute( [ $id, $key, (string) $value ] );
}
function page( $id, $content, $status = 'publish', $type = 'page', $title = 'Bio Retinol Serum' ) {
	global $db;
	$p = new WP_Post(); $p->ID = $id; $p->post_type = $type; $p->post_title = $title; $p->post_content = $content; $p->post_status = $status; $p->post_name = 'page-' . $id; $p->post_date_gmt = 'publish' === $status ? '2026-01-01 00:00:00' : '0000-00-00 00:00:00';
	$GLOBALS['posts'][ $id ] = $p;
	$db->pdo->prepare( 'INSERT OR REPLACE INTO wp_posts ( ID, post_type, post_status, post_content ) VALUES ( ?, ?, ?, ? )' )->execute( [ $id, $type, $status, $content ] );
	return $p;
}
function image( $id, $mime = 'image/png', $parent = 0, $file = '' ) {
	global $db;
	$GLOBALS['attachments'][ $id ] = [ 'mime' => $mime, 'file' => $file, 'parent' => $parent ];
	$db->pdo->prepare( 'INSERT OR REPLACE INTO wp_posts ( ID, post_type, post_status, post_parent, post_mime_type, post_date ) VALUES ( ?, ?, ?, ?, ?, ? )' )->execute( [ $id, 'attachment', 'inherit', $parent, $mime, '2026-01-' . str_pad( (string) ( $id % 28 + 1 ), 2, '0', STR_PAD_LEFT ) ] );
}
function q( $sql ) { return $GLOBALS['wpdb']->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }

foreach ( [ 'plugin', 'settings', 'crypto', 'claude', 'reader', 'install', 'yoast', 'rules', 'keyphrases', 'state', 'log', 'cache', 'writer', 'usage', 'queue', 'scheduler', 'alt-text', 'report' ] as $f ) require $P . "includes/class-kdna-sw-$f.php";

$fail = 0;
function check( $name, $cond ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; if ( ! $cond ) $fail++; }

KDNA_SeoWriter_Settings::install_defaults();
KDNA_SeoWriter_Settings::save( [ 'content' => [ 'min_words' => 10 ], 'brand' => [ 'avoid' => 'miracle' ] ] );
KDNA_SeoWriter_Crypto::save_key( 'sk-ant-api03-TESTKEY000000000000000' );

echo "-- page reader images\n";
$r = new ReflectionMethod( 'KDNA_SeoWriter_Reader', 'result' );
$out = $r->invoke( null, [ 'object_type' => 'post', 'object_id' => 1, 'subtype' => 'page', 'title' => 'T', 'url' => 'u', 'sources' => [] ], '<p>Some words here to read about the serum for a while longer.</p><img class="alignnone wp-image-5 size-large" src="https://x/a.jpg" alt="Serum &amp; dropper"><figure><img data-src="https://x/b.jpg" src="data:x" alt=""></figure><noscript><img class="wp-image-9" src="n.jpg"></noscript>' );
check( 'images listed with ID and alt', $out['images'][0] === [ 'id' => 5, 'alt' => 'Serum & dropper', 'src' => 'https://x/a.jpg' ] );
check( 'image without ID listed with ID 0, lazy-load source used', $out['images'][1]['id'] === 0 && $out['images'][1]['src'] === 'https://x/b.jpg' );
check( 'noscript copies ignored (not counted twice)', count( $out['images'] ) === 2 );

echo "-- matching\n";
check( 'short keyphrase needs every word', KDNA_SeoWriter_Alt_Text::mentions( 'Bio retinol in a bottle', 'bio retinol' ) && ! KDNA_SeoWriter_Alt_Text::mentions( 'Retinol bottle', 'bio retinol' ) );
check( 'long keyphrase needs more than half', KDNA_SeoWriter_Alt_Text::mentions( 'Night serum', 'retinol night serum for dry skin' ) === false && KDNA_SeoWriter_Alt_Text::mentions( 'Retinol night serum for skin', 'retinol night serum for dry skin' ) );

echo "-- finding images on an item\n";
foreach ( [ 5, 6, 7, 8, 9, 10 ] as $id ) image( $id );
image( 11, 'image/svg+xml' );
$GLOBALS['thumbs'][100] = 5;
page( 100, '<!-- wp:image {"id":7,"sizeSlug":"large"} --><figure><img class="wp-image-7"></figure><!-- /wp:image --><p>Our bio retinol serum smooths the look of fine lines overnight.</p><img class="wp-image-6" src="s.png" alt="">' );
meta( 100, '_elementor_data', json_encode( [ [ 'elType' => 'container', 'settings' => [ 'background_image' => [ 'id' => 9, 'url' => 'https://x/bg.jpg' ] ], 'elements' => [ [ 'widgetType' => 'image', 'settings' => [ 'image' => [ 'id' => '8', 'url' => 'https://x/i.jpg' ] ] ] ] ] ] ) );
meta( 100, '_product_image_gallery', '10,11' );
$found = KDNA_SeoWriter_Alt_Text::images_on_post( 100 );
$sorted = $found; sort( $sorted );
check( 'featured, content, block, Elementor image and background, gallery (SVG left out): ' . implode( ',', $found ), $sorted === [ 5, 6, 7, 8, 9, 10 ] && 5 === $found[0] );

echo "-- usage across pages\n";
page( 101, '<p>Another page.</p><img class="wp-image-6" src="s.png">' );
page( 102, '<img class="wp-image-5 size-full">', 'draft' );
check( 'image in two published pages', KDNA_SeoWriter_Alt_Text::usage( 6 ) === [ 100, 101 ] || KDNA_SeoWriter_Alt_Text::usage( 6 ) === [ 101, 100 ] );
meta( 100, '_thumbnail_id', '5' );
check( 'featured image on one page; drafts do not count', KDNA_SeoWriter_Alt_Text::usage( 5 ) === [ 100 ] );
check( 'Elementor and gallery usage found', KDNA_SeoWriter_Alt_Text::usage( 8 ) === [ 100 ] && KDNA_SeoWriter_Alt_Text::usage( 10 ) === [ 100 ] );
check( 'ID 1 does not match ID 10 or 11', KDNA_SeoWriter_Alt_Text::usage( 1 ) === [] );

echo "-- sweep candidates\n";
image( 20 ); image( 21, 'image/jpeg', 100 ); image( 22, 'image/webp' ); image( 23 ); image( 24 ); image( 25 ); image( 26, 'application/pdf' );
meta( 103, '_thumbnail_id', '20' ); page( 103, '<p>x</p>' );
meta( 23, '_wp_attachment_image_alt', 'Already described' );
meta( 24, 'kdna_sw_decorative', '1' );
meta( 25, 'kdna_sw_attempted', (string) time() );
$ids = array_column( KDNA_SeoWriter_Alt_Text::sweep_candidates( 50 ), 'id' );
// Images 5 and 20 are featured images, 21 is attached to a page, the rest are loose in the library.
check( 'featured first, then attached, then the rest; alt, decorative, recent and PDF left out: ' . implode( ',', $ids ), array_slice( $ids, 0, 3 ) === [ 20, 5, 21 ] && in_array( 22, $ids, true ) && ! array_intersect( $ids, [ 23, 24, 25, 26 ] ) );
check( 'context is the page it is featured on, or its parent', KDNA_SeoWriter_Alt_Text::sweep_candidates( 3 ) === [ [ 'id' => 20, 'context' => 103 ], [ 'id' => 5, 'context' => 100 ], [ 'id' => 21, 'context' => 100 ] ] );

echo "-- writing alt text\n";
$png = sys_get_temp_dir() . '/kdna-sw-test.png';
$gd  = imagecreatetruecolor( 40, 30 ); imagefill( $gd, 0, 0, imagecolorallocate( $gd, 200, 140, 60 ) ); imagepng( $gd, $png );
foreach ( [ 5, 6 ] as $id ) $GLOBALS['attachments'][ $id ]['file'] = $png;
meta( 100, '_yoast_wpseo_focuskw', 'bio retinol serum' );
$reply = fn( $alt ) => [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn', 'content' => [ [ 'type' => 'text', 'text' => json_encode( [ 'alt_text' => $alt ] ) ] ], 'usage' => [ 'input_tokens' => 900, 'output_tokens' => 20 ] ] ) ];
$GLOBALS['http'] = []; $GLOBALS['responses'] = [ $reply( 'Image of a bio retinol serum bottle' ), $reply( 'Bio retinol serum in an amber glass dropper bottle.' ) ];
$res = KDNA_SeoWriter_Alt_Text::generate( 5, 100 );
$body = json_decode( $GLOBALS['http'][0]['args']['body'], true );
$parts = $body['messages'][0]['content'];
check( 'image sent as a PNG image block, before the text', $parts[0]['type'] === 'image' && $parts[0]['source']['media_type'] === 'image/png' && base64_decode( $parts[0]['source']['data'] ) === file_get_contents( $png ) );
check( 'image used on one page: keyphrase offered', $res['keyphrase_allowed'] && str_contains( $parts[1]['text'], 'you may include the keyphrase if, and only if, it truthfully describes' ) );
check( 'Yoast image guidance given with counts', str_contains( $parts[1]['text'], 'the page has 2 images and 0 of their alt texts already mention the keyphrase' ) );
check( '"Image of" opening fails, retry fixes it, full stop trimmed', $res['status'] === 'ready' && $res['attempts'] === 2 && $res['alt'] === 'Bio retinol serum in an amber glass dropper bottle' );
check( 'brand rules sent as a cached block', ( $body['system'][1]['cache_control']['type'] ?? '' ) === 'ephemeral' && str_contains( $body['system'][1]['text'], 'miracle' ) );

$GLOBALS['http'] = []; $GLOBALS['responses'] = [ $reply( 'Bio retinol serum on a marble bench' ), $reply( 'Amber dropper bottle on a white marble bench' ) ];
$res = KDNA_SeoWriter_Alt_Text::generate( 6, 100 );
$text = json_decode( $GLOBALS['http'][0]['args']['body'], true )['messages'][0]['content'][1]['text'];
check( 'image on two pages: keyphrase not offered', ! $res['keyphrase_allowed'] && str_contains( $text, 'This image is used on 2 pages, so do not use any page\'s keyphrase' ) );
check( 'keyphrase in a shared image fails, retry gives a plain description', $res['status'] === 'ready' && $res['alt'] === 'Amber dropper bottle on a white marble bench' );

$c = [ 'keyphrase' => 'bio retinol serum', 'keyphrase_allowed' => true, 'pages' => 1 ];
$ids = fn( $alt ) => array_column( array_filter( KDNA_SeoWriter_Alt_Text::check( $alt, $c ), fn( $x ) => ! $x['ok'] ), 'id' );
check( 'too long fails', in_array( 'alt_length', $ids( str_repeat( 'word ', 30 ) ), true ) );
check( 'empty fails', in_array( 'alt_length', $ids( '' ), true ) );
check( '"Photo showing" fails, "Photographer at work" passes', in_array( 'alt_opening', $ids( 'Photo showing a bottle' ), true ) && ! $ids( 'Photographer at work in a studio' ) );
check( 'banned word fails', in_array( 'brand_alt_text', $ids( 'A miracle cream jar' ), true ) );
check( 'keyphrase fine when allowed', ! $ids( 'Bio retinol serum in a glass bottle' ) );

echo "-- queue, approve and undo\n";
$GLOBALS['responses'] = [ $reply( 'Amber dropper bottle on a white marble bench' ) ];
KDNA_SeoWriter_Alt_Text::queue( 6, 100, 'manual' );
check( 'job queued', as_has_scheduled_action( 'kdna_sw_alt', [ 6, 100, 'manual' ] ) );
KDNA_SeoWriter_Alt_Text::run_job( 6, 100, 'manual' );
$row = q( "SELECT * FROM wp_kdna_sw_queue WHERE object_type = 'attachment'" );
check( 'suggestion waits for review with its page', count( $row ) === 1 && (int) $row[0]['context_id'] === 100 && $row[0]['field'] === 'alt_text' && $row[0]['flag'] === 'new' );
check( 'nothing saved before approval', KDNA_SeoWriter_Alt_Text::get_alt( 6 ) === '' );
$item = KDNA_SeoWriter_Queue::items( [ 'subtype' => 'attachment' ] )['items'][0];
check( 'review item shows a thumbnail and the page', $item['key'] === 'attachment:6' && str_contains( $item['thumbnail'], '6-medium' ) && $item['context'] === 'Bio Retinol Serum' && $item['type_label'] === 'Image' );
$a = KDNA_SeoWriter_Queue::approve( [ (int) $row[0]['id'] ] );
check( 'approve saves the alt text in the media library', 1 === $a['approved'] && KDNA_SeoWriter_Alt_Text::get_alt( 6 ) === 'Amber dropper bottle on a white marble bench' );
check( 'WP Rocket cleared for the page it was written for', in_array( 'https://example.com/100/', $GLOBALS['purged'], true ) );
$log = q( "SELECT * FROM wp_kdna_sw_log WHERE kind = 'change' AND object_type = 'attachment'" );
check( 'change logged against the image', count( $log ) === 1 && $log[0]['source'] === 'approved' );
check( 'undo puts the empty alt back', true === KDNA_SeoWriter_Log::undo( (int) $log[0]['id'] ) && KDNA_SeoWriter_Alt_Text::get_alt( 6 ) === '' );

KDNA_SeoWriter_Queue::add( 'attachment', 7, 'attachment', 'alt_text', '', 'A bottle', [], false, 100 );
KDNA_SeoWriter_Alt_Text::save_decorative( [ 'ID' => 7 ], [ 'kdna_sw_decorative' => '1' ] );
check( 'marking Decorative removes the waiting suggestion', KDNA_SeoWriter_Alt_Text::is_decorative( 7 ) && ! KDNA_SeoWriter_Queue::waiting_fields( 'attachment', [ 7 ] ) );
$GLOBALS['http'] = [];
KDNA_SeoWriter_Alt_Text::run_job( 7, 100, 'manual' );
check( 'Decorative images are skipped without calling Claude', ! $GLOBALS['http'] );
check( 'Decorative images are not queued from their page', ! as_has_scheduled_action( 'kdna_sw_alt', [ 7, 100, 'auto' ] ) && KDNA_SeoWriter_Alt_Text::queue_for_post( 100, 'auto' ) >= 1 && ! as_has_scheduled_action( 'kdna_sw_alt', [ 7, 100, 'auto' ] ) );

echo "-- keyphrase report\n";
$intro = fn( $p ) => array_values( array_filter( KDNA_SeoWriter_Report::build( 'post', 200 )['checks'], fn( $c ) => $c['id'] === 'intro' ) )[0] ?? null;
$long  = str_repeat( 'More text about skincare routines. ', 80 );
page( 200, '<p>Our bio retinol serum smooths the look of fine lines overnight.</p><h2>How bio retinol works</h2><h2>Bio retinol serum benefits</h2><h2>Ingredients</h2><h2>Shipping</h2><p>' . $long . '</p>', 'draft' );
meta( 200, '_yoast_wpseo_focuskw', 'bio retinol serum' );
KDNA_SeoWriter_State::set_suggestions( 'post', 200, [ 'slug' => 'bio-retinol-serum', 'opening_line' => 'Our bio retinol serum works overnight.', 'headings' => [ 'Why bio retinol serum works' ], 'keyphrase' => 'bio retinol serum' ] );
$rep = KDNA_SeoWriter_Report::build( 'post', 200 );
$by  = array_column( $rep['checks'], null, 'id' );
check( 'intro: keyphrase in one sentence is good', $by['intro']['status'] === 'good' && $by['intro']['suggestion'] === '' );
check( 'subheadings: 2 of 4 (30% to 75%) is good', $by['subheadings']['status'] === 'good' && str_contains( $by['subheadings']['message'], '2 of 4' ) );
check( 'slug missing keyphrase: suggestion, Apply offered on a never-published draft', $by['slug']['status'] === 'bad' && $by['slug']['suggestion'] === 'bio-retinol-serum' && $by['slug']['can_apply'] && '' === $by['slug']['warning'] );
check( 'images: none in content', $by['images']['status'] === 'okay' );
check( 'apply slug on a draft', true === KDNA_SeoWriter_Report::apply_slug( 200 ) && $GLOBALS['posts'][200]->post_name === 'bio-retinol-serum' );
check( 'slug now passes', array_column( KDNA_SeoWriter_Report::build( 'post', 200 )['checks'], null, 'id' )['slug']['status'] === 'good' );

page( 201, '<p>Fine lines fade with retinol. Our bio formula is a serum.</p><h2>A</h2><h2>B</h2><h2>Bio retinol serum</h2><h2>Bio retinol serum again</h2><h2>Bio retinol serum more</h2><h2>Bio retinol serum still</h2><p>' . $long . '</p><img class="wp-image-5" alt="Bio retinol serum bottle"><img alt="Jar"><img alt="Hands"><img alt=""><img alt="Box"><img alt="Shelf">' );
meta( 201, '_yoast_wpseo_focuskw', 'bio retinol serum' );
KDNA_SeoWriter_State::set_suggestions( 'post', 201, [ 'slug' => 'bio-retinol-serum', 'opening_line' => 'Our bio retinol serum fades fine lines.', 'headings' => [], 'keyphrase' => 'bio retinol serum' ] );
$by = array_column( KDNA_SeoWriter_Report::build( 'post', 201 )['checks'], null, 'id' );
check( 'intro: words spread over sentences is "could be better", with suggestion', $by['intro']['status'] === 'okay' && $by['intro']['suggestion'] === 'Our bio retinol serum fades fine lines.' );
check( 'subheadings: 4 of 6 is good (max 4)', $by['subheadings']['status'] === 'good' );
check( 'published item: no Apply, redirect warning shown', ! $by['slug']['can_apply'] && str_contains( $by['slug']['warning'], 'redirect' ) );
check( 'images: 1 of 6 is too few (Yoast wants 2 to 4)', $by['images']['status'] === 'okay' && str_contains( $by['images']['message'], '1 of 6' ) );

page( 202, '<p>Nothing relevant here at all, just some words.</p><h2>Bio retinol serum</h2><h2>Bio retinol serum two</h2><h2>Bio retinol serum three</h2><h2>Bio retinol serum four</h2><p>' . $long . '</p><img alt="Bio retinol serum"><img alt="Bio retinol serum"><img alt="Jar">' );
meta( 202, '_yoast_wpseo_focuskw', 'bio retinol serum' );
$by = array_column( KDNA_SeoWriter_Report::build( 'post', 202 )['checks'], null, 'id' );
check( 'intro: missing is bad', $by['intro']['status'] === 'bad' );
check( 'subheadings: 4 of 4 is too many', $by['subheadings']['status'] === 'bad' && str_contains( $by['subheadings']['message'], 'too many' ) );
check( 'images: fewer than 5 with a match is good', $by['images']['status'] === 'good' );
check( 'suggestions for another keyphrase are not shown', KDNA_SeoWriter_State::set_suggestions( 'post', 202, [ 'slug' => 'x', 'opening_line' => 'Old line', 'headings' => [], 'keyphrase' => 'vitamin c' ] ) === null && array_column( KDNA_SeoWriter_Report::build( 'post', 202 )['checks'], null, 'id' )['intro']['suggestion'] === '' );

page( 203, '<p>Short page about bio retinol serum for night time use.</p>' );
meta( 203, '_yoast_wpseo_focuskw', 'bio retinol serum' );
$by = array_column( KDNA_SeoWriter_Report::build( 'post', 203 )['checks'], null, 'id' );
check( 'short text with no subheadings is fine', $by['subheadings']['status'] === 'good' );
page( 204, '<p>Words.</p>' . '<p>' . $long . '</p>' );
meta( 204, '_yoast_wpseo_focuskw', 'bio retinol serum' );
$by = array_column( KDNA_SeoWriter_Report::build( 'post', 204 )['checks'], null, 'id' );
check( 'long text with no subheadings needs work', $by['subheadings']['status'] === 'bad' );
page( 205, '<p>Plenty of words on this page to be read properly by the reader.</p>' );
check( 'no keyphrase yet: message only', KDNA_SeoWriter_Report::build( 'post', 205 )['checks'] === [] && '' !== KDNA_SeoWriter_Report::build( 'post', 205 )['message'] );
check( 'page content never changed by the report', $GLOBALS['posts'][201]->post_content === $GLOBALS['wpdb']->get_var( 'SELECT post_content FROM wp_posts WHERE ID = 201' ) );

echo "-- privacy\n";
page( 300, '<p>Welcome to our skincare range, made gently in small batches for sensitive skin.</p><p>[whoami]</p>' );
$GLOBALS['uid'] = 1;
$read = KDNA_SeoWriter_Reader::read_post( 300 );
check( 'content rendered as a logged-out visitor', str_contains( $read['text'], 'Signed in as user 0' ) && ! str_contains( $read['text'], 'user 1' ) );
check( 'the administrator is logged back in afterwards', 1 === get_current_user_id() );

@unlink( $png );
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
