<?php
// A src file's top-level code runs where it is required, not at startup.
echo "before require\n";
$r = require __DIR__ . '/../routes/web.php';
echo "after require ", json_encode($r), "\n";
var_dump(require __DIR__ . '/../routes/plain.php');
require __DIR__ . '/../routes/guarded.php';
echo "after guarded ", get_class(new Lazy\TokenPolyfill(1, "x")), "\n";
require __DIR__ . '/../routes/vars.php';
echo "vars ", lazy_cfg_path(), " ", lazy_count(), " ", $count, "\n";
echo "xmlrpc ", var_export(defined("LAZY_XMLRPC_REQUEST"), true), " ", var_export(defined("LAZY_OTHER"), true), "\n";
function lazy_probe(): string {
    return (function_exists('lazy_late_helper') ? 'y' : 'n') . (function_exists('lazy_xmlrpc_server') ? 'y' : 'n');
}
echo "before late ", lazy_probe(), "\n";
require __DIR__ . '/../routes/late.php';
echo "after late ", lazy_probe(), " ", lazy_late_helper(), "\n";
function lazy_probe2(): string { return function_exists('lazy_decl_only') ? 'y' : 'n'; }
echo "before decl_only ", lazy_probe2(), "\n";
require_once __DIR__ . '/../routes/decl_only.php';
echo "after decl_only ", lazy_probe2(), " ", lazy_decl_only(), "\n";
