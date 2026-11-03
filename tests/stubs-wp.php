<?php
// Stand-ins for the WordPress, Yoast and WP Rocket functions used from Stage 2 on.
// Loaded after stubs.php by run-stage2.php and run-stage3.php.
define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'WPSEO_VERSION', '28.9' );
$GLOBALS['meta'] = []; $GLOBALS['tmeta'] = []; $GLOBALS['posts'] = []; $GLOBALS['built'] = []; $GLOBALS['purged'] = [];
class WP_Post { public $ID; public $post_type='page'; public $post_content=''; public $post_password=''; public $post_title=''; public $post_status='publish'; }
class WP_Term { public $term_id; public $taxonomy; public $name; public $description=''; }
function get_post($id){ return $GLOBALS['posts'][$id] ?? null; }
function get_post_type($id){ return $GLOBALS['posts'][$id]->post_type ?? ( isset( $GLOBALS['attachments'][$id] ) ? 'attachment' : '' ); }
function get_the_title($p){ $p = is_object($p)?$p:get_post($p); return $p ? $p->post_title : ''; }
function get_permalink($p){ $p = is_object($p)?$p:get_post($p); return 'https://example.com/'.($p?$p->ID:0).'/'; }
function has_blocks($c){ return str_contains((string)$c,'<!-- wp:'); }
function do_blocks($c){ return $c; } function wpautop($c){ return $c; } function do_shortcode($c){ return $c; } function strip_shortcodes($c){ return $c; }
function setup_postdata($p){}
function get_post_meta($id,$k,$single=false){ return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id,$k,$v){ $old = $GLOBALS['meta'][$id][$k] ?? null; $GLOBALS['meta'][$id][$k] = is_string($v) ? stripslashes($v) : $v; do_action_stub($old===null?'added_post_meta':'updated_post_meta',0,$id,$k); return true; }
function delete_post_meta($id,$k){ unset($GLOBALS['meta'][$id][$k]); return true; }
function get_term_meta($id,$k,$s=false){ return $GLOBALS['tmeta'][$id][$k] ?? ''; }
function update_term_meta($id,$k,$v){ $GLOBALS['tmeta'][$id][$k]=$v; return true; }
function wp_slash($v){ return is_string($v) ? addslashes($v) : $v; }
function get_post_type_object($t){ return (object)['labels'=>(object)['singular_name'=>ucfirst($t)]]; }
function get_taxonomy($t){ return (object)['labels'=>(object)['singular_name'=>'Category'],'object_type'=>['post']]; }
function get_term($id,$tax=''){ return $GLOBALS['terms'][$id] ?? null; }
function get_term_link($t){ return 'https://example.com/category/x/'; }
function get_bloginfo($k){ return 'Apotheca'; }
function sanitize_title($s){ return trim(preg_replace('/[^a-z0-9]+/','-',strtolower($s)),'-'); }
function get_transient($k){ return $GLOBALS['opts']['_t_'.$k] ?? false; } function set_transient($k,$v,$e=0){ $GLOBALS['opts']['_t_'.$k]=$v; return true; } function delete_transient($k){ unset($GLOBALS['opts']['_t_'.$k]); return true; }
function _n($a,$b,$n,$d=null){ return $n==1?$a:$b; }
function wp_kses_post($s){ return $s; }
$GLOBALS['actions'] = [];
function add_action($h,$cb,$p=10,$n=1){ $GLOBALS['actions'][$h][] = $cb; }
function do_action_stub($h,...$args){ foreach ($GLOBALS['actions'][$h] ?? [] as $cb) call_user_func_array($cb,$args); }
function do_action(...$a){}
function rocket_clean_files($urls){ $GLOBALS['purged'] = array_merge($GLOBALS['purged'],$urls); }
// Stand-in Yoast
class WPSEO_Meta { public static $meta_prefix = '_yoast_wpseo_'; static function set_value($k,$v,$id){ return update_post_meta($id, self::$meta_prefix.$k, wp_slash($v)); } static function delete($k,$id){ return delete_post_meta($id, self::$meta_prefix.$k); } }
class WPSEO_Taxonomy_Meta {
  static $defaults = ['wpseo_title'=>'','wpseo_desc'=>'','wpseo_canonical'=>'','wpseo_noindex'=>'default','wpseo_focuskw'=>'','wpseo_opengraph-title'=>'','wpseo_opengraph-description'=>'','wpseo_twitter-title'=>'','wpseo_twitter-description'=>''];
  static function get_term_meta($t,$tax){ $all = get_option('wpseo_taxonomy_meta',[]); return array_merge(self::$defaults, $all[$tax][$t] ?? []); }
  static function set_values($t,$tax,$vals){ $clean = self::$defaults; foreach ($clean as $k=>$v) if (isset($vals[$k])) $clean[$k]=$vals[$k]; $all = get_option('wpseo_taxonomy_meta',[]); $all[$tax][$t]=$clean; update_option('wpseo_taxonomy_meta',$all); }
}
class FakeWatcher { public $type; function __construct($t){ $this->type=$t; } function build_indexable($id){ $GLOBALS['built'][] = $this->type.':'.$id; } }
function YoastSEO(){ return (object)['classes'=>new class { function get($c){ return new FakeWatcher(str_contains($c,'Term')?'term':'post'); } }]; }
function wpseo_replace_vars($s,$a){ return trim(preg_replace('/\s+/',' ',str_replace(['%%sep%%','%%sitename%%','%%page%%'],['-','Apotheca',''],$s))); }
class WPDB2 extends WPDB_Stub { public $postmeta='wp_postmeta'; public $posts='wp_posts'; public $insert_id=0; public $rows=[];
  function insert($t,$d,$f){ $this->insert_id++; $d['id']=$this->insert_id; $d['undone_at']=null; $this->rows[$this->insert_id]=$d; $GLOBALS['inserts'][]=[$t,$d]; return 1; }
  function prepare($q,...$a){ return [$q,$a]; }
  function get_row($p,$o){ $id=$p[1][0]; $r=$this->rows[$id]??null; return ($r && $r['kind']==='change') ? $r : null; }
  function get_results($p,$o=null){ if (str_contains($p[0],'meta_key')) { $out=[]; foreach ($GLOBALS['meta'] as $pid=>$m) if (!empty($m['_yoast_wpseo_focuskw'])) $out[]=(object)['post_id'=>$pid,'meta_value'=>$m['_yoast_wpseo_focuskw'],'post_type'=>'page']; return $out; }
    [$type,$id,$lim]=$p[1]; $out=[]; foreach (array_reverse($this->rows) as $r) if ($r['kind']==='change' && $r['object_type']===$type && $r['object_id']===$id) $out[]=$r; return $out; }
  function update($t,$d,$w,$f=null,$wf=null){ foreach ($d as $k=>$v) $this->rows[$w['id']][$k]=$v; return 1; } }
$GLOBALS['wpdb'] = new WPDB2();

// Media stand-ins (Stage 4).
$GLOBALS['thumbs'] = []; $GLOBALS['attachments'] = [];
function get_post_thumbnail_id( $id ) { return $GLOBALS['thumbs'][ $id ] ?? 0; }
function get_post_mime_type( $id ) { return $GLOBALS['attachments'][ $id ]['mime'] ?? ''; }
function get_attached_file( $id ) { return $GLOBALS['attachments'][ $id ]['file'] ?? ''; }
function wp_get_post_parent_id( $id ) { return $GLOBALS['attachments'][ $id ]['parent'] ?? 0; }
function wp_attachment_is_image( $id ) { return isset( $GLOBALS['attachments'][ $id ] ); }
function wp_get_attachment_caption( $id ) { return $GLOBALS['attachments'][ $id ]['caption'] ?? ''; }
function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) { return 'https://example.com/uploads/' . $id . '-' . $size . '.png'; }
function wp_get_attachment_url( $id ) { return 'https://example.com/uploads/' . $id . '.png'; }
function image_get_intermediate_size( $id, $size ) { return false; }
function wp_get_upload_dir() { return [ 'basedir' => sys_get_temp_dir() ]; }
function wp_check_filetype( $f ) { return [ 'type' => str_ends_with( $f, '.png' ) ? 'image/png' : 'image/jpeg' ]; }
function wp_basename( $p ) { return basename( $p ); }
