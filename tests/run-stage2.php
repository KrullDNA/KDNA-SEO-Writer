<?php
// Stage 2 tests: rule checks (unit-style), Yoast field handling, and the full
// generate, retry, save and undo flow against stand-in Yoast and fake Claude replies.
// Run: php tests/run-stage2.php
require __DIR__ . '/stubs.php';
$P = dirname( __DIR__ ) . '/kdna-seo-writer/';

// ---- Extra stand-ins for this stage -------------------------------------------------
define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'WPSEO_VERSION', '28.9' );
$GLOBALS['meta'] = []; $GLOBALS['tmeta'] = []; $GLOBALS['posts'] = []; $GLOBALS['built'] = []; $GLOBALS['purged'] = [];
class WP_Post { public $ID; public $post_type='page'; public $post_content=''; public $post_password=''; public $post_title=''; public $post_status='publish'; }
class WP_Term { public $term_id; public $taxonomy; public $name; public $description=''; }
function get_post($id){ return $GLOBALS['posts'][$id] ?? null; }
function get_post_type($id){ return $GLOBALS['posts'][$id]->post_type ?? ''; }
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

foreach (['plugin','settings','crypto','claude','reader','install','yoast','rules','keyphrases','state','log','cache','writer'] as $f) require $P."includes/class-kdna-sw-$f.php";
KDNA_SeoWriter_Keyphrases::hooks();

$fail = 0;
function check($name,$cond){ global $fail; echo ($cond?'PASS ':'FAIL ').$name."\n"; if(!$cond) $fail++; }
function ok_ids($r){ $o=[]; foreach ($r['checks'] as $c) $o[$c['id'].'@'.$c['field']] = $c['ok']; return $o; }
$fields_all = ['seo_title'=>true,'meta_description'=>true,'focus_keyphrase'=>true,'social'=>true];
KDNA_SeoWriter_Settings::install_defaults();

// ================= Unit-style tests for the rule checks =============================
function test_normalise() {
  check('normalise lowercases and strips punctuation', KDNA_SeoWriter_Rules::normalise('Bio-Retinol Serum, for SKIN’S glow!') === "bio-retinol serum for skin's glow");
  check('content words drop function words', KDNA_SeoWriter_Rules::content_words('the best serum for dry skin') === ['best','serum','dry','skin']);
}
function test_title_width() {
  $w = KDNA_SeoWriter_Rules::title_width('Bio Retinol Serum for Fine Lines - Apotheca');
  check("title width plausible ($w px)", $w > 350 && $w < 450);
  check('wide letters measure wider', KDNA_SeoWriter_Rules::title_width('WWWW') > KDNA_SeoWriter_Rules::title_width('iiii'));
}
function test_keyphrase_rules() {
  global $fields_all;
  $base = ['seo_title'=>'x','seo_title_rendered'=>'','meta_description'=>'','social_title'=>'a','social_description'=>'b'];
  $r = KDNA_SeoWriter_Rules::check(['focus_keyphrase'=>'for the'] + $base, ['fields'=>['focus_keyphrase'=>true],'taken'=>[]]);
  check('only function words fails', ok_ids($r)['keyphrase_function_words@focus_keyphrase'] === false);
  $r = KDNA_SeoWriter_Rules::check(['focus_keyphrase'=>'best natural bio retinol night serum'] + $base, ['fields'=>['focus_keyphrase'=>true],'taken'=>[]]);
  check('six content words fails length', ok_ids($r)['keyphrase_length@focus_keyphrase'] === false);
  $r = KDNA_SeoWriter_Rules::check(['focus_keyphrase'=>'Bio Retinol Serum'] + $base, ['fields'=>['focus_keyphrase'=>true],'taken'=>['bio retinol serum']]);
  check('previously used (case-insensitive) fails', ok_ids($r)['keyphrase_unused@focus_keyphrase'] === false);
  $r = KDNA_SeoWriter_Rules::check(['focus_keyphrase'=>'bio retinol serum'] + $base, ['fields'=>['focus_keyphrase'=>true],'taken'=>['vitamin c serum']]);
  check('fresh 3-word keyphrase passes', $r['passed']);
}
function test_title_rules() {
  $ctx = ['fields'=>['seo_title'=>true],'taken'=>[]];
  $t = fn($rendered,$kp='bio retinol serum') => ok_ids(KDNA_SeoWriter_Rules::check(['focus_keyphrase'=>$kp,'seo_title'=>$rendered,'seo_title_rendered'=>$rendered], $ctx));
  check('keyphrase at start passes', $t('Bio Retinol Serum for Smoother Looking Skin - Apotheca')['title_keyphrase@seo_title']);
  check('leading function word allowed', $t('The Bio Retinol Serum for Smoother Looking Skin - Apotheca')['title_keyphrase@seo_title']);
  check('keyphrase later fails', ! $t('Smoother Skin with Our Bio Retinol Serum - Apotheca')['title_keyphrase@seo_title']);
  check('partial keyphrase fails', ! $t('Bio Retinol for Smoother Looking Skin Overnight - Apotheca')['title_keyphrase@seo_title']);
  check('too wide fails', ! $t('Bio Retinol Serum for Smoother Looking Skin, Fewer Fine Lines and a Brighter Glow - Apotheca')['title_width@seo_title']);
  check('too narrow fails', ! $t('Bio Retinol Serum - Apotheca')['title_width@seo_title']);
  check('good width passes', $t('Bio Retinol Serum for Smoother Looking Skin - Apotheca')['title_width@seo_title']);
}
function test_description_rules() {
  $ctx = ['fields'=>['meta_description'=>true],'taken'=>[]];
  $d = fn($desc,$kp='bio retinol serum') => ok_ids(KDNA_SeoWriter_Rules::check(['focus_keyphrase'=>$kp,'meta_description'=>$desc], $ctx));
  $good = 'Our bio retinol serum smooths the look of fine lines overnight with gentle bakuchiol and squalane, so skin looks brighter by morning.';
  check('good length ('.mb_strlen($good).') passes', $d($good)['description_length@meta_description']);
  check('keyphrase present passes', $d($good)['description_keyphrase@meta_description']);
  check('120 characters fails (Yoast wants more than 120)', ! $d(str_repeat('a', 120))['description_length@meta_description']);
  check('171 characters fails', ! $d(str_repeat('a ', 85).'b')['description_length@meta_description']);
  check('missing word fails', ! $d('Our bio serum smooths the look of fine lines overnight with gentle bakuchiol and squalane so skin looks brighter by the morning light.')['description_keyphrase@meta_description']);
  $three = 'Bio retinol serum here. Bio retinol serum there. Bio retinol serum everywhere for smoother looking skin and a brighter glow overnight.';
  check('three sentences with keyphrase fails (stuffing)', ! $d($three)['description_keyphrase@meta_description']);
}
function test_brand_rules() {
  KDNA_SeoWriter_Settings::save(['brand'=>['avoid'=>"cures\nanti-aging, miracle",'no_em_dashes'=>true,'no_exclaim_titles'=>true,'no_prices_dates'=>true]]);
  check('banned word found', (bool) KDNA_SeoWriter_Rules::brand_problems('This cures dull skin', false));
  check('banned word inside another word ignored', ! KDNA_SeoWriter_Rules::brand_problems('Securest packaging', false));
  check('banned phrase with hyphen', (bool) KDNA_SeoWriter_Rules::brand_problems('An anti-aging serum', false));
  check('em dash found', (bool) KDNA_SeoWriter_Rules::brand_problems('Glow — naturally', false));
  check('exclamation in title found', (bool) KDNA_SeoWriter_Rules::brand_problems('Glow now!', true));
  check('exclamation in description allowed', ! KDNA_SeoWriter_Rules::brand_problems('Glow now!', false));
  foreach (['Only $49', 'Save 20% off today', 'From AUD 30', '49.95 dollars'] as $p) check("price found: $p", KDNA_SeoWriter_Rules::has_price($p));
  foreach (['New for 2026', 'On 3 March', 'Ends June 30th', 'Until 12/05/2026'] as $p) check("date found: $p", KDNA_SeoWriter_Rules::has_date($p));
  foreach (['You may notice smoother skin', 'Apply 2 drops', 'A 30 ml bottle'] as $p) check("no false date/price: $p", ! KDNA_SeoWriter_Rules::has_date($p) && ! KDNA_SeoWriter_Rules::has_price($p));
  $r = KDNA_SeoWriter_Rules::check(['focus_keyphrase'=>'bio retinol serum','seo_title'=>'Bio Retinol Serum That Cures %%sep%% %%sitename%%','seo_title_rendered'=>'Bio Retinol Serum That Cures - Apotheca'], ['fields'=>['seo_title'=>true],'taken'=>[]]);
  check('banned word in SEO title blocks', ! $r['passed'] && in_array(false, array_column(array_filter($r['checks'], fn($c)=>$c['id']==='brand_seo_title'),'ok'), true));
  $r = KDNA_SeoWriter_Rules::check(['focus_keyphrase'=>'bio retinol serum','seo_title'=>'Bio Retinol Serum for Smoother Looking Skin %%sep%% %%sitename%%','seo_title_rendered'=>'Bio Retinol Serum for Smoother Looking Skin — Apotheca'], ['fields'=>['seo_title'=>true],'taken'=>[]]);
  check('em dash from Yoast separator not blamed on the plugin', $r['passed']);
  KDNA_SeoWriter_Settings::save(['brand'=>['avoid'=>'']]);
}
foreach (['test_normalise','test_title_width','test_keyphrase_rules','test_title_rules','test_description_rules','test_brand_rules'] as $t) { echo "-- $t\n"; $t(); }

// ================= Yoast class ======================================================
echo "-- yoast\n";
check('post suffix', KDNA_SeoWriter_Yoast::title_suffix('%%title%% %%page%% %%sep%% %%sitename%%') === ' %%page%% %%sep%% %%sitename%%');
check('term suffix drops Archives', KDNA_SeoWriter_Yoast::title_suffix('%%term_title%% Archives %%page%% %%sep%% %%sitename%%') === ' %%page%% %%sep%% %%sitename%%');
check('no vars, no suffix', KDNA_SeoWriter_Yoast::title_suffix('%%title%%') === '');
check('build title strips stray %%', KDNA_SeoWriter_Yoast::build_seo_title('Glow %%Oil', 'post', 'page') === 'Glow Oil %%page%% %%sep%% %%sitename%%');
$GLOBALS['opts']['wpseo_titles'] = ['title-page'=>'%%title%% %%sep%% %%sitename%%','separator'=>'sc-pipe'];
check('reads Yoast template', KDNA_SeoWriter_Yoast::title_template('post','page') === '%%title%% %%sep%% %%sitename%%');
check('separator from Yoast', KDNA_SeoWriter_Yoast::separator() === '|');
$GLOBALS['terms'][7] = new WP_Term(); $GLOBALS['terms'][7]->term_id=7; $GLOBALS['terms'][7]->taxonomy='category';
$GLOBALS['opts']['wpseo_taxonomy_meta'] = ['category'=>[7=>['wpseo_canonical'=>'https://example.com/keep/','wpseo_noindex'=>'noindex','wpseo_title'=>'']]];
KDNA_SeoWriter_Yoast::set('term', 7, ['meta_description'=>'Hello'], 'category');
$tm = $GLOBALS['opts']['wpseo_taxonomy_meta']['category'][7];
check('term write keeps canonical and noindex', $tm['wpseo_canonical']==='https://example.com/keep/' && $tm['wpseo_noindex']==='noindex' && $tm['wpseo_desc']==='Hello');
check('term indexable rebuilt', in_array('term:7', $GLOBALS['built'], true));
KDNA_SeoWriter_Yoast::set('post', 5, ['seo_title'=>"Skin's Glow \\ Test"]);
check('post value saved with slashes intact', get_post_meta(5,'_yoast_wpseo_title') === "Skin's Glow \\ Test");
check('post indexable rebuilt', in_array('post:5', $GLOBALS['built'], true));
unset($GLOBALS['meta'][5]);

// ================= Full flow: generate, retry, save, undo ===========================
echo "-- writer flow\n";
unset($GLOBALS['opts']['wpseo_titles']);
KDNA_SeoWriter_Settings::save(['brand'=>['name'=>'Apotheca','avoid'=>'cures','claims'=>'No therapeutic claims.'],'content'=>['min_words'=>10]]);
KDNA_SeoWriter_Crypto::save_key('sk-ant-api03-TESTKEY000000000000000');
$p = new WP_Post(); $p->ID=42; $p->post_title='Bio Retinol Serum'; $p->post_type='product';
$p->post_content='<h1>Bio Retinol Serum</h1><p>Our bio retinol serum smooths the look of fine lines overnight using bakuchiol, a gentle plant alternative.</p><h2>How to use</h2><p>Apply three drops at night after cleansing.</p>';
$GLOBALS['posts'][42]=$p;
$GLOBALS['meta'][99]['_yoast_wpseo_focuskw'] = 'vitamin c serum';     // another page's keyphrase
$GLOBALS['meta'][42]['_yoast_wpseo_metadesc'] = 'Hand written description.'; // existing human text
$reply = fn(array $a) => ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode(['model'=>'claude-haiku-5-5','stop_reason'=>'end_turn','content'=>[['type'=>'text','text'=>json_encode($a)]],'usage'=>['input_tokens'=>900,'output_tokens'=>200,'cache_read_input_tokens'=>700]])];
$bad  = ['focus_keyphrase'=>'vitamin c serum','seo_title'=>'Smooth Skin Serum That Cures Lines','meta_description'=>'Too short.','social_title'=>'Wake up to smoother looking skin','social_description'=>'Our gentle plant powered serum works overnight.','slug'=>'Bio Retinol Serum','opening_line'=>'','headings'=>['How to use bio retinol serum']];
$good = ['focus_keyphrase'=>'bio retinol serum','seo_title'=>'Bio Retinol Serum for Smoother Looking Skin','meta_description'=>'Our bio retinol serum smooths the look of fine lines overnight with gentle bakuchiol and squalane, so skin looks brighter by morning.','social_title'=>'Wake up to smoother looking skin','social_description'=>'Our gentle plant powered serum works overnight.','slug'=>'bio-retinol-serum','opening_line'=>'','headings'=>[]];
$GLOBALS['http']=[]; $GLOBALS['responses'] = [ $reply($bad), $reply($good) ];
$r = KDNA_SeoWriter_Writer::generate('post', 42);
check('status ready after retry', $r['status']==='ready' && $r['attempts']===2);
$first  = json_decode($GLOBALS['http'][0]['args']['body'], true);
$second = json_decode($GLOBALS['http'][1]['args']['body'], true);
$msg1 = $first['messages'][0]['content'][0]['text']; $msg2 = $second['messages'][0]['content'][0]['text'];
check('prompt has taken keyphrases', str_contains($msg1, 'vitamin c serum'));
check('prompt has suffix and char range', str_contains($msg1, '[seo_title] - Apotheca') && preg_match('/between \d+ and \d+ characters/', $msg1));
check('prompt has page text in tags', str_contains($msg1, "<page_content>\nBio Retinol Serum"));
check('brand block cached and includes claim rules', ($first['system'][1]['cache_control']['type'] ?? '')==='ephemeral' && str_contains($first['system'][1]['text'], 'No therapeutic claims.') && str_contains($first['system'][1]['text'], '"cures"'));
check('system prompt identical on retry (cache-friendly)', $first['system'] === $second['system']);
check('retry names specific failures', str_contains($msg2, 'already used on another page') && str_contains($msg2, 'The meta description is 10 characters') && str_contains($msg2, 'avoid list'));
check('seo title saved with Yoast variables', $r['values']['seo_title'] === 'Bio Retinol Serum for Smoother Looking Skin %%page%% %%sep%% %%sitename%%');
check('rendered title', $r['rendered_title'] === 'Bio Retinol Serum for Smoother Looking Skin - Apotheca');
check('social copied to Facebook and X', $r['values']['og_title']===$r['values']['x_title'] && $r['values']['og_description']===$r['values']['x_description']);
check('slug suggestion cleaned', $r['suggestions']['slug']==='bio-retinol-serum');
check('key only in the header, never in the body', ! str_contains($GLOBALS['http'][0]['args']['body'], 'TESTKEY0000') && str_contains($GLOBALS['http'][0]['args']['headers']['x-api-key'], 'TESTKEY0000'));
$saved = KDNA_SeoWriter_Writer::save('post', 42, 'product', $r['values'], 'test', true);
check('save fills empty fields only', in_array('seo_title',$saved,true) && ! in_array('meta_description',$saved,true) && count($saved)===6);
check('existing description untouched', get_post_meta(42,'_yoast_wpseo_metadesc')==='Hand written description.');
check('Yoast keys written', get_post_meta(42,'_yoast_wpseo_focuskw')==='bio retinol serum' && get_post_meta(42,'_yoast_wpseo_opengraph-title')!=='' && get_post_meta(42,'_yoast_wpseo_twitter-description')!=='');
check('fingerprints stored', KDNA_SeoWriter_State::matches('post',42,'seo_title', get_post_meta(42,'_yoast_wpseo_title')));
check('WP Rocket purged that URL only', $GLOBALS['purged'] === ['https://example.com/42/']);
check('keyphrase register refreshed', in_array('bio retinol serum', KDNA_SeoWriter_Keyphrases::taken_by_others('post', 1), true));
$logs = KDNA_SeoWriter_Log::for_item('post', 42);
check('every write logged', count($logs) === 6 && $logs[0]['source']==='test');
$title_log = current(array_filter($logs, fn($l)=>$l['field']==='seo_title'));
check('undo works', KDNA_SeoWriter_Log::undo((int)$title_log['id']) === true && get_post_meta(42,'_yoast_wpseo_title') === '');
check('undo cannot run twice', is_wp_error(KDNA_SeoWriter_Log::undo((int)$title_log['id'])));
check('undo forgets fingerprint', ! KDNA_SeoWriter_State::matches('post',42,'seo_title',''));
$kp_log = current(array_filter($logs, fn($l)=>$l['field']==='focus_keyphrase'));
update_post_meta(42,'_yoast_wpseo_focuskw','person typed this');
$u = KDNA_SeoWriter_Log::undo((int)$kp_log['id']);
check('undo refuses if a person edited since', is_wp_error($u) && get_post_meta(42,'_yoast_wpseo_focuskw')==='person typed this');

// still failing after retry -> needs review
$GLOBALS['responses'] = [ $reply($bad), $reply($bad) ];
$r = KDNA_SeoWriter_Writer::generate('post', 42);
check('still failing goes to needs_review', $r['status']==='needs_review' && $r['attempts']===2 && KDNA_SeoWriter_Rules::failures($r['checks']));
// thin page skipped
$t = new WP_Post(); $t->ID=43; $t->post_title='Contact'; $t->post_content='<p>Call us</p>'; $GLOBALS['posts'][43]=$t;
$r = KDNA_SeoWriter_Writer::generate('post', 43);
check('thin page skipped without calling Claude', $r['status']==='skipped' && $r['attempts']===0 && KDNA_SeoWriter_State::get('post',43)['skipped']==='not_enough_content');
// fields switched off are not returned
KDNA_SeoWriter_Settings::save(['content'=>['fields'=>['seo_title'=>true,'meta_description'=>true,'focus_keyphrase'=>true,'social'=>false]]]);
delete_post_meta(42,'_yoast_wpseo_focuskw'); // clear the keyphrase typed in the undo test above
$GLOBALS['http']=[]; $GLOBALS['responses'] = [ $reply($good) ];
$r = KDNA_SeoWriter_Writer::generate('post', 42);
check('social off: no social values', ! isset($r['values']['og_title']) && isset($r['values']['seo_title']) && $r['status']==='ready' && count($GLOBALS['http'])===1);

// an existing (human) keyphrase is kept and given to Claude
KDNA_SeoWriter_Settings::save(['content'=>['fields'=>['seo_title'=>true,'meta_description'=>true,'focus_keyphrase'=>true,'social'=>true]]]);
update_post_meta(42,'_yoast_wpseo_focuskw','retinol night serum');
$GLOBALS['http']=[]; $GLOBALS['responses'] = [ $reply($good), $reply($good) ];
$r = KDNA_SeoWriter_Writer::generate('post', 42);
$m = json_decode($GLOBALS['http'][0]['args']['body'], true)['messages'][0]['content'][0]['text'];
check('existing keyphrase sent to Claude', str_contains($m, 'already has the focus keyphrase "retinol night serum"'));
check('existing keyphrase kept in values', $r['values']['focus_keyphrase']==='retinol night serum');
check('checks run against the kept keyphrase', $r['status']==='needs_review' && in_array('The SEO title must start with the exact keyphrase "retinol night serum".', KDNA_SeoWriter_Rules::failures($r['checks']), true));

echo "\n--- sample prompt (first request) ---\n".$msg1."\n--- retry tail ---\n".substr($msg2, strpos($msg2,'It failed'))."\n";
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
