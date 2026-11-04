<?php
// Shared SQLite $wpdb and helpers for tests that run the plugin's real SQL
// (used by run-stage4.php and run-1.0.3.php). Load after stubs.php and stubs-wp.php.
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
