<?php
ob_start();
class K { function m() { debug_print_backtrace(); } static function s() { (new K)->m(); } }
function f() { K::s(); }
function g() { f(); }
g();
echo "--\n";
function h() { debug_print_backtrace(0, 1); }
h();

echo str_replace(__DIR__ . '/', '', ob_get_clean());
