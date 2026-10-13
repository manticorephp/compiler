<?php
class A { static function f() { return get_called_class(); } function g() { return get_called_class(); } }
class B extends A {}
echo B::f(), " ", A::f(), " ", (new B)->g(), "\n";
set_error_handler(function (int $n, string $s) { echo "h:$n:$s\n"; return true; });
user_error("hi", E_USER_WARNING);
user_error("plain");
$inc = get_included_files();
echo count($inc), " ", basename($inc[0]), " ", count(get_required_files()), "\n";
echo is_string(zend_version()) ? "zv-ok" : "zv-bad", "\n";
$ext = get_loaded_extensions();
echo in_array('Core', $ext, true) ? "core" : "no-core", " ", in_array('SPL', $ext, true) ? "spl" : "no-spl", "\n";
echo implode(",", array_keys(gc_status())), "\n";
echo gc_status()['threshold'], " ", gc_status()['runs'], "\n";
