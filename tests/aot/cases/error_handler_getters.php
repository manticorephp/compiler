<?php
function h1($no, $str) { return true; }
function e1(Throwable $e) {}
var_dump(get_error_handler(), get_exception_handler());
set_error_handler('h1');
set_exception_handler('e1');
var_dump(get_error_handler(), get_exception_handler());
set_error_handler(null);
var_dump(get_error_handler());
restore_error_handler();
var_dump(get_error_handler());
restore_error_handler();
restore_exception_handler();
var_dump(get_error_handler(), get_exception_handler());
$f = function ($no, $str) { return false; };
set_error_handler($f);
var_dump(get_error_handler() === $f);
