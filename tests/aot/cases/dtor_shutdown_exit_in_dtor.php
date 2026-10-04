<?php
class E { public function __destruct() { echo "E dtor\n"; exit(0); } }
class U { public function __destruct() { echo "U dtor\n"; } }
class K { static $a; static $b; }
K::$a = new E; K::$b = new U;
echo "end\n";
