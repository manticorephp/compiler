<?php

// is_callable on the class-name forms: PHP 8 needs an INSTANCE for a non-static
// method, so 'C::m' / ['C','m'] are callable only when m is static. The [$obj,'m']
// form supplies one, so a non-static method IS callable through it.

class Tools
{
    public static function stat(int $n): int { return $n; }
    public function inst(): int { return 1; }
}

class Child extends Tools
{
    public static function own(): int { return 3; }
}

$o = new Tools();

var_dump(is_callable('Tools::stat'));
var_dump(is_callable('Tools::inst'));
var_dump(is_callable('Tools::nope'));

var_dump(is_callable(['Tools', 'stat']));
var_dump(is_callable(['Tools', 'inst']));

var_dump(is_callable([$o, 'inst']));
var_dump(is_callable([$o, 'stat']));

// Inherited static resolves through the parent chain.
var_dump(is_callable('Child::stat'));
var_dump(is_callable('Child::own'));
var_dump(is_callable('Child::inst'));
