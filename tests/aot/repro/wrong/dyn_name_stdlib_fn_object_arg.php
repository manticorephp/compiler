<?php
// A stdlib function called by runtime name with an object/array argument no longer reads the pointer as bytes, but still throws Error / answers "Array" where php throws TypeError.
// issue: #332
class Conn {}
$f = 'ucfirst';
try { var_dump($f(new Conn)); } catch (\Throwable $e) { echo get_class($e), "\n"; }
try { var_dump($f([1])); } catch (\Throwable $e) { echo get_class($e), "\n"; }
