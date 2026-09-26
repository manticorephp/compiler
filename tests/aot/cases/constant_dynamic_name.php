<?php
// constant() / defined() with a computed name resolve at run time: a global
// predefined, a define()d and a class constant, each named somewhere in a
// string literal (php-cs-fixer's Token::getKeywords builds its keyword table
// with constant('T_ARRAY')). An unknown name throws, as php does.
define('APP_MODE', 'prod');
final class Cfg { public const LIMIT = 42; }

function kinds(array $names): array
{
    $k = [];
    foreach ($names as $n) { $v = \constant($n); $k[$v] = $v; }
    return $k;
}

$kw = kinds(['T_ABSTRACT', 'T_ARRAY', 'T_AS']) + [10001 => 10001];
var_dump(count($kw), isset($kw[T_ARRAY]));
foreach (['APP_MODE', 'Cfg::LIMIT', '\PHP_INT_SIZE', 'E_ALL'] as $n) {
    var_dump(constant($n), defined($n));
}
$missing = 'NOPE_' . 'NOT_THERE';
var_dump(defined($missing));
try { constant($missing); } catch (\Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
