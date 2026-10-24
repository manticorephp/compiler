<?php
var_dump(class_exists('mysqli'), function_exists('mysqli_connect'), defined('MYSQLI_ASSOC'), extension_loaded('mysqli'));
var_dump(class_exists('\mysqli_driver'), class_exists('mysqli_warning'));
$cls = 'mysqli_sql_exception';
$e = new $cls('boom');
var_dump(get_class($e), $e->getMessage(), $e instanceof RuntimeException);
