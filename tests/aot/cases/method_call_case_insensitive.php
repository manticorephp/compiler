<?php
class A { public function getThing() { return 'ok'; } public static function sm() { return 's'; } }
$a = new A;
echo $a->GETTHING(), "\n";
echo A::SM(), "\n";
