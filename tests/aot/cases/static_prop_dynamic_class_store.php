<?php
// Writing a static property through a runtime class: `$obj::$p = v` and `$cls::$p = v`.
class Mailer { public static $validator = 'php'; }
class Other { public static $validator = 'none'; }
$m = new Mailer();
$m::$validator = static function (string $e): bool { return str_contains($e, '@'); };
var_dump((Mailer::$validator)('a@b'), Other::$validator);
$cls = 'Other';
$cls::$validator = 'set';
echo Other::$validator, ' ', $cls::$validator, "\n";
