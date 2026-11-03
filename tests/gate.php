<?php
// Checks the plugin registers nothing on public page requests. Usage: php tests/gate.php <request-uri> [rest_route] [admin]
require __DIR__ . '/stubs.php';
$GLOBALS['wp_version']='6.8';
$GLOBALS['hooks']=0;
function add_action(...$a){ $GLOBALS['hooks']++; } function add_filter(...$a){ $GLOBALS['hooks']++; }
function register_activation_hook(...$a){ $GLOBALS['hooks']++; }
$_SERVER['REQUEST_URI'] = $argv[1]; if (isset($argv[2])) $_GET['rest_route']=$argv[2];
$GLOBALS['is_admin'] = ($argv[3] ?? '') === 'admin';
function plugin_basename($f){ return basename(dirname($f)).'/'.basename($f); }
require dirname( __DIR__ ) . '/kdna-seo-writer/kdna-seo-writer.php';
echo $_SERVER['REQUEST_URI'], ' => hooks registered: ', $GLOBALS['hooks'], ', classes loaded: ', class_exists('KDNA_SeoWriter_Plugin',false)?'yes':'no', "\n";
