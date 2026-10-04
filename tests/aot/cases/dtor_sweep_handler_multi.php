<?php
set_exception_handler(function ($e) { echo "handler: ", $e->getMessage(), "
"; });
class T { public function __construct(public $n){} public function __destruct() { echo "T{$this->n} dtor
"; 
throw new Exception("boom{$this->n}");
} }
class K { static $a; static $b; static $c; }
K::$a = new T(1); K::$b = new T(2);
K::$c = new T(3);
echo "end
";
