<?php
// Stage 1 logic tests: encryption, settings cleaning, page reader, Anthropic client (with fake responses).
require __DIR__ . '/stubs.php';
$P = dirname( __DIR__ ) . '/kdna-seo-writer/';
foreach (['class-kdna-sw-settings','class-kdna-sw-crypto','class-kdna-sw-claude','class-kdna-sw-reader','class-kdna-sw-install','class-kdna-sw-usage','class-kdna-sw-yoast'] as $f) require $P."includes/$f.php";
$fail=0; function check($name,$cond){ global $fail; echo ($cond?'PASS ':'FAIL ').$name."\n"; if(!$cond) $fail++; }

// --- Crypto
check('no key yet', !KDNA_SeoWriter_Crypto::has_key() && KDNA_SeoWriter_Crypto::get_key()==='');
KDNA_SeoWriter_Crypto::save_key('sk-ant-api03-SECRETVALUE1234567890');
$stored = get_option('kdna_sw_api_key');
check('stored value is encrypted', strpos($stored,'SECRETVALUE')===false && str_starts_with($stored,'kdnasw1:'));
check('round trip', KDNA_SeoWriter_Crypto::get_key()==='sk-ant-api03-SECRETVALUE1234567890');
$GLOBALS['salt']='changed';
check('changed salts cannot unlock', KDNA_SeoWriter_Crypto::has_key() && !KDNA_SeoWriter_Crypto::key_is_readable());
unset($GLOBALS['salt']);

// --- Settings
KDNA_SeoWriter_Settings::install_defaults();
check('defaults installed', get_option('kdna_sw_settings')['advanced']['desc_max']===156);
check('post defaults to auto', KDNA_SeoWriter_Settings::mode_for('post_type','post')==='auto');
check('page defaults to review', KDNA_SeoWriter_Settings::mode_for('post_type','page')==='review');
check('category defaults to review', KDNA_SeoWriter_Settings::mode_for('taxonomy','category')==='review');
$s = KDNA_SeoWriter_Settings::save(['connection'=>['model'=>'evil-model','spend_limit'=>'12.5','rates'=>['claude-haiku-5-5'=>['input'=>'0.2','output'=>'-3']]],
 'brand'=>['name'=>'<b>Apotheca</b>','spelling'=>'fr','no_em_dashes'=>false,'claims'=>"line1\nline2"],
 'content'=>['post_types'=>['page'=>'off','nope'=>'auto','post'=>'bogus'],'jet_fields'=>['page'=>'intro, faq.answer; DROP TABLE','Bad Type!'=>'x'],'min_words'=>'-5','fields'=>['seo_title'=>true]],
 'hacker'=>['x'=>1]]);
check('bad model falls back', $s['connection']['model']==='claude-haiku-5-5');
check('spend limit float', $s['connection']['spend_limit']===12.5);
check('rates clean', $s['connection']['rates']['claude-haiku-5-5']['input']===0.2 && $s['connection']['rates']['claude-haiku-5-5']['output']===0.0 && $s['connection']['rates']['claude-sonnet-5-5']['input']===2.0);
check('tags stripped', $s['brand']['name']==='Apotheca');
check('spelling fallback', $s['brand']['spelling']==='en-GB');
check('bool saved', $s['brand']['no_em_dashes']===false);
check('textarea keeps lines', $s['brand']['claims']==="line1\nline2");
check('mode map cleaned', $s['content']['post_types']===['page'=>'off']);
check('page now off', KDNA_SeoWriter_Settings::mode_for('post_type','page')==='off');
check('post bogus ignored -> auto', KDNA_SeoWriter_Settings::mode_for('post_type','post')==='auto');
check('jet paths cleaned', $s['content']['jet_fields']===['page'=>'intro, faq.answer, DROP, TABLE', 'badtype'=>'x']);
check('min words non-negative', $s['content']['min_words']===5);
check('fields unticked', $s['content']['fields']['meta_description']===false && $s['content']['fields']['seo_title']===true);
check('unknown group ignored', !isset($s['hacker']));
check('untouched group kept', $s['automation']['sweep_time']==='02:00');

// --- Reader (logic on rendered HTML)
KDNA_SeoWriter_Settings::save(['content'=>['min_words'=>10],'advanced'=>['max_chars'=>1000]]);
$r = new ReflectionMethod('KDNA_SeoWriter_Reader','result'); $r->setAccessible(true);
$html = '<style>.x{}</style><nav><a>Home</a><a>Shop</a></nav><h1 class="t">Bio <span>Retinol</span> Serum</h1><p>Short</p><p>A gentle bio retinol serum that smooths the look of fine lines overnight.</p><script>alert(1)</script><h2>How to use &amp; apply</h2><div>Apply two drops</div><h3>Ingredients</h3><p>Bakuchiol, squalane [old_shortcode id="3"] and rosehip.</p><!-- comment -->';
$out = $r->invoke(null, ['object_type'=>'post','object_id'=>5,'subtype'=>'product','title'=>'Bio Retinol','url'=>'u','sources'=>['blocks']], $html);
check('h1 from content', $out['h1']==='Bio Retinol Serum' && $out['h1_from']==='content');
check('first paragraph skips short', $out['first_paragraph']==='A gentle bio retinol serum that smooths the look of fine lines overnight.');
check('h2 decoded', $out['h2']===['How to use & apply']);
check('h3', $out['h3']===['Ingredients']);
check('no script/style/nav/shortcode', !preg_match('/alert|\.x\{|Home|old_shortcode|comment/',$out['text']));
check('words separated by blocks', str_contains($out['text'],"Apply two drops\nIngredients") || str_contains($out['text'],"Apply two drops\n"));
check('ok', $out['ok']===true);
echo "---- text ----\n".$out['text']."\n--------------\n";
$thin = $r->invoke(null, ['object_type'=>'post','object_id'=>6,'subtype'=>'page','title'=>'Contact','url'=>'u','sources'=>['blocks']], '<p>Call us today</p>');
check('thin skipped', !$thin['ok'] && $thin['reason']==='not_enough_content' && $thin['h1']==='Contact' && $thin['h1_from']==='title');
$long = $r->invoke(null, ['object_type'=>'post','object_id'=>7,'subtype'=>'page','title'=>'L','url'=>'u','sources'=>[]], '<p>'.str_repeat('word ', 600).'</p>');
check('trimmed', $long['trimmed'] && mb_strlen($long['text'])<=1002 && $long['word_count']===600);

// dig (JetEngine repeaters)
$d = new ReflectionMethod('KDNA_SeoWriter_Reader','dig'); $d->setAccessible(true);
$rep = ['item-0'=>['question'=>'What is it?','answer'=>'A serum.','img'=>'123'],'item-1'=>['question'=>'How often?','answer'=>'<p>Nightly.</p>']];
check('repeater sub-field', $d->invoke(null,$rep,['answer'])===['A serum.','<p>Nightly.</p>']);
check('whole repeater skips ids', $d->invoke(null,$rep,[])===['What is it?','A serum.','How often?','<p>Nightly.</p>']);
check('plain field', $d->invoke(null,'Hello there',[])===['Hello there']);
check('numeric skipped', $d->invoke(null,'4521',[])===[]);
check('nested path', $d->invoke(null,['a'=>['b'=>'deep text']],['a','b'])===['deep text']);

// Elementor data fallback walker
$w = new ReflectionMethod('KDNA_SeoWriter_Reader','walk_elementor'); $w->setAccessible(true);
$tree=[['elType'=>'container','settings'=>['background_color'=>'#fff'],'elements'=>[
 ['elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'Glow Oil','header_size'=>'h1']],
 ['elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'Benefits']],
 ['elType'=>'widget','widgetType'=>'text-editor','settings'=>['editor'=>'<p>Lightweight facial oil.</p>','_css_classes'=>'x']],
 ['elType'=>'widget','widgetType'=>'accordion','settings'=>['tabs'=>[['tab_title'=>'Q1','tab_content'=>'A1']]]],
]]];
$h=''; $w->invokeArgs(null,[$tree,&$h]);
check('elementor walker', $h==='<h1>Glow Oil</h1><h2>Benefits</h2><p><p>Lightweight facial oil.</p></p><p>Q1</p><p>A1</p>');

// --- Claude client
$GLOBALS['responses'] = [[ 'response'=>['code'=>401], 'body'=>json_encode(['type'=>'error','error'=>['type'=>'authentication_error','message'=>'invalid x-api-key']]), 'headers'=>[] ]];
$t = KDNA_SeoWriter_Claude::test_connection();
check('wrong key plain english', !$t['ok'] && str_contains($t['message'],'did not accept the API key'));
check('key never in message', !str_contains($t['message'],'SECRETVALUE'));
check('models lookup first', str_ends_with($GLOBALS['http'][0]['url'],'models/claude-haiku-5-5') && $GLOBALS['http'][0]['args']['headers']['anthropic-version']==='2026-10-21');
$GLOBALS['http']=[];
$GLOBALS['responses'] = [
 ['response'=>['code'=>200],'body'=>json_encode(['id'=>'claude-haiku-5-5','display_name'=>'Claude Haiku 5.5']),'headers'=>[]],
 ['response'=>['code'=>529],'body'=>json_encode(['error'=>['type'=>'overloaded_error']]),'headers'=>['retry-after'=>'1']],
 ['response'=>['code'=>200],'body'=>json_encode(['model'=>'claude-haiku-5-5','stop_reason'=>'end_turn','content'=>[['type'=>'text','text'=>'OK']],'usage'=>['input_tokens'=>12,'output_tokens'=>3,'cache_read_input_tokens'=>0]]),'headers'=>[]],
];
$t = KDNA_SeoWriter_Claude::test_connection();
check('connect ok after overload retry', $t['ok'] && str_contains($t['message'],'Claude Haiku 5.5'));
$body = json_decode($GLOBALS['http'][1]['args']['body'],true);
check('no sampling params, effort low', !isset($body['temperature']) && $body['output_config']['effort']==='low' && $body['model']==='claude-haiku-5-5' && !isset($body['fallbacks']));
check('usage logged', end($GLOBALS['inserts'])[1]['input_tokens']===12 && end($GLOBALS['inserts'])[1]['kind']==='usage');
check('key not logged', !str_contains(json_encode($GLOBALS['inserts']),'SECRETVALUE'));

// structured + sonnet fallbacks + caching block
KDNA_SeoWriter_Settings::save(['connection'=>['model'=>'claude-sonnet-5-5']]);
$GLOBALS['http']=[];
$GLOBALS['responses'] = [['response'=>['code'=>200],'body'=>json_encode(['model'=>'claude-sonnet-5-5','stop_reason'=>'end_turn','content'=>[['type'=>'thinking','thinking'=>''],['type'=>'text','text'=>'{"keyphrase":"bio retinol"}']],'usage'=>['input_tokens'=>1,'output_tokens'=>1]]),'headers'=>[]]];
$schema=['type'=>'object','properties'=>['keyphrase'=>['type'=>'string']],'required'=>['keyphrase'],'additionalProperties'=>false];
$a = KDNA_SeoWriter_Claude::structured([KDNA_SeoWriter_Claude::text_block('Brand rules',true)], [KDNA_SeoWriter_Claude::text_block('Page')], $schema, ['object_type'=>'post','object_id'=>5,'purpose'=>'metadata']);
check('structured decode', $a===['keyphrase'=>'bio retinol']);
$req = $GLOBALS['http'][0]['args'];
$b = json_decode($req['body'],true);
check('sonnet fallback header+body', $req['headers']['anthropic-beta']==='server-side-fallback-2026-07-01' && $b['fallbacks']==='default');
check('json schema format', $b['output_config']['format']['type']==='json_schema' && $b['output_config']['effort']==='low');
check('cache_control on brand block', $b['system'][0]['cache_control']===['type'=>'ephemeral']);
$GLOBALS['responses'] = [['response'=>['code'=>200],'body'=>json_encode(['stop_reason'=>'refusal','content'=>[],'usage'=>[]]),'headers'=>[]]];
$a = KDNA_SeoWriter_Claude::structured([], [KDNA_SeoWriter_Claude::text_block('x')], $schema);
check('refusal error', is_wp_error($a) && $a->get_error_code()==='kdna_sw_refusal');
$GLOBALS['responses'] = [['response'=>['code'=>400],'body'=>json_encode(['error'=>['type'=>'invalid_request_error','message'=>'Your credit balance is too low to access the Anthropic API.']]),'headers'=>[]]];
$a = KDNA_SeoWriter_Claude::send(['messages'=>[]]);
check('billing message', is_wp_error($a) && str_contains($a->get_error_message(),'run out of credit'));
$GLOBALS['responses'] = [new WP_Error('http_request_failed','cURL error 6')];
$a = KDNA_SeoWriter_Claude::send(['messages'=>[]],['retries'=>0]);
check('unreachable message', is_wp_error($a) && str_contains($a->get_error_message(),'Could not reach Anthropic'));
check('image block', KDNA_SeoWriter_Claude::image_block('abc','image/png')['source']['data']==='YWJj' && is_wp_error(KDNA_SeoWriter_Claude::image_block('abc','image/tiff')));
KDNA_SeoWriter_Crypto::delete_key();
$a = KDNA_SeoWriter_Claude::send(['messages'=>[]]);
check('no key error', is_wp_error($a) && $a->get_error_code()==='kdna_sw_no_key');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
