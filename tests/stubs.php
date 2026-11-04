<?php
// Stand-in WordPress functions so the plugin's own logic can be tested without a WordPress install.
// Run: php tests/run-stage1.php   and   php tests/gate.php /some/public/page/
// Minimal stand-ins for the WordPress functions the plugin calls, for logic tests only.
define('ABSPATH', __DIR__ . '/');
$GLOBALS['opts'] = []; $GLOBALS['http'] = []; $GLOBALS['inserts'] = []; $GLOBALS['is_admin'] = false; $GLOBALS['cron'] = false;
class WP_Error { public $c; public $m; public $d; function __construct($c='',$m='',$d=[]){$this->c=$c;$this->m=$m;$this->d=$d;} function get_error_message(){return $this->m;} function get_error_code(){return $this->c;} }
function is_wp_error($x){ return $x instanceof WP_Error; }
function __($s,$d=null){return $s;} function esc_html__($s,$d=null){return $s;} function esc_html($s){return htmlspecialchars($s);} 
function sanitize_text_field($s){ return trim(preg_replace('/\s+/',' ',strip_tags((string)$s))); }
function sanitize_textarea_field($s){ return trim(strip_tags((string)$s)); }
function sanitize_key($k){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$k)); }
function absint($n){ return abs((int)$n); }
function rest_sanitize_boolean($v){ if (is_string($v)) { $v=strtolower($v); if (in_array($v,['false','0',''],true)) return false; } return (bool)$v; }
function get_option($k,$d=false){ return array_key_exists($k,$GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k,$v,$a=null){ $GLOBALS['opts'][$k]=$v; return true; }
function add_option($k,$v,$x='',$a=null){ if(!array_key_exists($k,$GLOBALS['opts'])) $GLOBALS['opts'][$k]=$v; return true; }
function delete_option($k){ unset($GLOBALS['opts'][$k]); return true; }
function wp_salt($s='auth'){ return $GLOBALS['salt'] ?? 'saltsaltsalt-'.$s; }
function wp_json_encode($v){ return json_encode($v); }
function get_post_types($a=[],$o='names'){ return ['post'=>'post','page'=>'page','product'=>'product']; }
function get_taxonomies($a=[],$o='names'){ return ['category'=>'category','post_tag'=>'post_tag']; }
function wp_strip_all_tags($s){ $s=preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si','',$s); return trim(strip_tags($s)); }
function wp_remote_request($u,$a){ $GLOBALS['http'][]=['url'=>$u,'args'=>$a]; $r=array_shift($GLOBALS['responses']); if ($r===null) { fwrite(STDERR, "TEST SETUP: no fake response queued\n"); return new WP_Error('no_fake','none'); } return $r; }
function wp_remote_retrieve_response_code($r){ return $r['response']['code']; }
function wp_remote_retrieve_body($r){ return $r['body']; }
function wp_remote_retrieve_header($r,$h){ return $r['headers'][$h] ?? ''; }
function wp_rand($a,$b){ return 0; }
function get_current_user_id(){ return $GLOBALS["uid"] ?? 1; }
function wp_set_current_user( $id ){ $GLOBALS["uid"] = (int) $id; }
function current_time($t,$g=0){ return '2026-11-04 00:00:00'; }
function wp_parse_url($u,$c=-1){ return parse_url($u,$c); }
function wp_unslash($v){ return $v; }
function rest_get_url_prefix(){ return 'wp-json'; }
function is_admin(){ return $GLOBALS['is_admin']; }
function wp_doing_cron(){ return $GLOBALS['cron']; }
function plugin_dir_path($f){ return dirname($f).'/'; } function plugin_dir_url($f){ return 'http://x/'; }
class WPDB_Stub { public $prefix='wp_'; function insert($t,$d,$f){ $GLOBALS['inserts'][]=[$t,$d]; return 1; } }
$GLOBALS['wpdb'] = new WPDB_Stub();
// Content type stand-ins (1.0.1): every type is public and viewable unless listed in $GLOBALS['not_viewable'].
function get_post_type_object($t){ return (object)['name'=>$t,'labels'=>(object)['singular_name'=>ucfirst($t),'name'=>ucfirst($t).'s']]; }
function get_taxonomy($t){ return (object)['name'=>$t,'labels'=>(object)['singular_name'=>'Category','name'=>'Categories'],'object_type'=>['post']]; }
function is_post_type_viewable($o){ return ! in_array( $o->name, $GLOBALS['not_viewable'] ?? [], true ); }
function is_taxonomy_viewable($o){ return ! in_array( $o->name, $GLOBALS['not_viewable'] ?? [], true ); }
function apply_filters( $h, $v, ...$a ) { return $v; }
