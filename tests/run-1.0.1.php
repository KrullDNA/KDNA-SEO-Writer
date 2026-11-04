<?php
// Version 1.0.1 tests: heading markers on JetEngine fields, repeaters read row by
// row, rich text not double wrapped, and content types left out automatically.
// Run: php tests/run-1.0.1.php
require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-wp.php';
define( 'DAY_IN_SECONDS', 86400 );
$P = dirname( __DIR__ ) . '/kdna-seo-writer/';
foreach ( [ 'plugin', 'settings', 'crypto', 'claude', 'reader', 'install', 'yoast', 'rules', 'keyphrases', 'state', 'log', 'cache', 'writer', 'usage', 'alt-text', 'report' ] as $f ) require $P . "includes/class-kdna-sw-$f.php";

$fail = 0;
function check( $name, $cond ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; if ( ! $cond ) $fail++; }
KDNA_SeoWriter_Settings::install_defaults();

echo "-- field list\n";
check( 'heading markers kept, made lowercase; unknown ones dropped', KDNA_SeoWriter_Settings::clean_field_paths( "intro, article_sections.section_heading:H2\nbad:h9, x.y:p, key-title-1:h3, -natural" ) === 'intro, article_sections.section_heading:h2, bad, x.y, key-title-1:h3, -natural' );
check( 'parsed into keys and tag', KDNA_SeoWriter_Settings::field_paths( 'intro, article_sections.section_heading:h2' ) === [ [ 'keys' => [ 'intro' ], 'tag' => 'p' ], [ 'keys' => [ 'article_sections', 'section_heading' ], 'tag' => 'h2' ] ] );

echo "-- reading JetEngine fields\n";
KDNA_SeoWriter_Settings::save( [ 'content' => [ 'min_words' => 5, 'jet_fields' => [ 'post' => 'subheading, intro, aeo_answer_block, article_sections.section_heading:h2, article_sections.section_bodycopy' ] ] ] );
$p = new WP_Post(); $p->ID = 7; $p->post_type = 'post'; $p->post_title = 'Why skin changes in perimenopause'; $p->post_content = ''; $p->post_name = 'perimenopause-skin';
$GLOBALS['posts'][7] = $p;
$GLOBALS['meta'][7] = [
	'subheading'       => 'What shifts, and what helps',
	'intro'            => '<p>Skin often feels drier in perimenopause as oestrogen levels change.</p>',
	'aeo_answer_block' => '<p>In short: barrier care and gentle actives help skin feel comfortable.</p>',
	'article_sections' => [
		'item-0' => [ 'section_heading' => 'Why skin feels drier', 'section_bodycopy' => '<p>Lower oestrogen means less natural oil.</p>', 'section_image' => '55' ],
		'item-1' => [ 'section_heading' => 'Barrier care that helps', 'section_bodycopy' => '<p>Ceramides and humectants support the skin barrier.</p>', 'section_image' => '56' ],
	],
	'sources'          => '<p>Smith et al. 2024</p>',
];
$read = KDNA_SeoWriter_Reader::read_post( 7 );
check( 'JetEngine listed as a source', in_array( 'jetengine', $read['sources'], true ) );
check( 'section headings count as H2 headings', $read['h2'] === [ 'Why skin feels drier', 'Barrier care that helps' ] );
$t = $read['text'];
$order = [ strpos( $t, 'What shifts' ), strpos( $t, 'Skin often feels drier' ), strpos( $t, 'In short' ), strpos( $t, 'Why skin feels drier' ), strpos( $t, 'Lower oestrogen' ), strpos( $t, 'Barrier care that helps' ), strpos( $t, 'Ceramides' ) ];
check( 'read in page order, each heading followed by its own text', ! in_array( false, $order, true ) && $order === array_values( array_unique( $order ) ) && $order == array_values( ( function ( $o ) { sort( $o ); return $o; } )( $order ) ) );
check( 'first paragraph is the intro', $read['first_paragraph'] === 'Skin often feels drier in perimenopause as oestrogen levels change.' );
check( 'image IDs and unlisted fields left out', ! str_contains( $t, '55' ) && ! str_contains( $t, 'Smith' ) );
$html = ( new ReflectionMethod( 'KDNA_SeoWriter_Reader', 'jetengine_html' ) )->invoke( null, 7, 'post' );
check( 'rich text not wrapped in a second paragraph', ! str_contains( $html, '<p><p>' ) && str_contains( $html, '<h2>Why skin feels drier</h2><p>Lower oestrogen' ) );

$GLOBALS['meta'][7]['_yoast_wpseo_focuskw'] = 'perimenopause skin';
$sub = array_column( KDNA_SeoWriter_Report::build( 'post', 7 )['checks'], null, 'id' )['subheadings'];
check( 'keyphrase report now sees the headings', str_contains( $sub['message'], 'of 2 subheadings' ) );

KDNA_SeoWriter_Settings::save( [ 'content' => [ 'jet_fields' => [ 'post' => 'article_sections.section_bodycopy' ] ] ] );
$one = KDNA_SeoWriter_Reader::read_post( 7 );
check( 'a single repeater sub-field still reads every row', str_contains( $one['text'], 'Lower oestrogen' ) && str_contains( $one['text'], 'Ceramides' ) && ! str_contains( $one['text'], 'Why skin feels drier' ) );

echo "-- content types left out\n";
check( 'JetEngine listing items are internal', KDNA_SeoWriter_Settings::hidden_reason( 'post_type', 'jet-engine' ) === 'internal' && KDNA_SeoWriter_Settings::mode_for( 'post_type', 'jet-engine' ) === 'off' );
check( 'Elementor templates are internal', KDNA_SeoWriter_Settings::hidden_reason( 'post_type', 'elementor_library' ) === 'internal' );
$GLOBALS['not_viewable'] = [ 'apglos_term' ];
check( 'a type with no public pages is left out', KDNA_SeoWriter_Settings::hidden_reason( 'post_type', 'apglos_term' ) === 'no_pages' );
$GLOBALS['not_viewable'] = [];
$GLOBALS['opts']['wpseo_titles'] = [ 'noindex-apglos_term' => true, 'noindex-tax-pa_size' => true, 'noindex-post' => false ];
check( 'a type Yoast keeps out of search is left out', KDNA_SeoWriter_Settings::hidden_reason( 'post_type', 'apglos_term' ) === 'noindex' && KDNA_SeoWriter_Settings::mode_for( 'post_type', 'apglos_term' ) === 'off' );
check( 'same for taxonomies', KDNA_SeoWriter_Settings::hidden_reason( 'taxonomy', 'pa_size' ) === 'noindex' );
KDNA_SeoWriter_Settings::save( [ 'content' => [ 'post_types' => [ 'apglos_term' => 'auto' ] ] ] );
check( 'a saved mode cannot switch a hidden type back on', KDNA_SeoWriter_Settings::mode_for( 'post_type', 'apglos_term' ) === 'off' );
check( 'normal types unaffected', KDNA_SeoWriter_Settings::mode_for( 'post_type', 'post' ) === 'auto' && KDNA_SeoWriter_Settings::mode_for( 'post_type', 'page' ) === 'review' && KDNA_SeoWriter_Settings::mode_for( 'taxonomy', 'category' ) === 'review' );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
