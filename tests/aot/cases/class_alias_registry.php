<?php
// `class_alias()` cannot mint a class in a closed world, but it can make a
// second NAME resolve to an existing one — and every name-resolved question
// (class_exists, ReflectionClass, an erased `new $n`) goes through the same
// registry, so they all inherit the alias.
class Original
{
    public int $v = 5;
    public function hello(): string { return "orig:" . $this->v; }
}

var_dump(class_alias('Original', 'AliasName'));
var_dump(class_exists('AliasName'));
var_dump(class_exists('Original'));
var_dump(class_exists('NeverDeclared'));

$r = new ReflectionClass('AliasName');
// php reports the TARGET's name for an alias, not the alias.
echo $r->getName(), "\n";

$n = 'AliasName';
var_dump(class_exists($n));

// A second alias for the same class, and an alias of an alias' target.
var_dump(class_alias('Original', 'Another'));
var_dump(class_exists('Another'));
