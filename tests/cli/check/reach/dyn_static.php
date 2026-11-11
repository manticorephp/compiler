<?php
class S { public static function s(): int { return 1; } public function inst(): int { return 0; } }
class Plain { public static function p(): int { return 2; } }
$c = implode('', ['S']);
$m = implode('', ['s']);
echo $c::$m(), Plain::p(), "\n";
