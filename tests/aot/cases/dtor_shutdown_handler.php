<?php
set_exception_handler(function ($e) { echo "handler: ", $e->getMessage(), "\n"; });
class T { public function __destruct() { echo "T dtor\n"; throw new Exception("boom"); } }
class U { public function __destruct() { echo "U dtor\n"; } }
class K { static $a; static $b; }
K::$a = new T; K::$b = new U;
echo "end\n";
