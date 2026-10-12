<?php
// method_exists() over an erased subject: a class NAME at run time is looked up as one.
class Base { public static function make(): static { return new static(); } public function inst(): int { return 1; } }
class K extends Base {}
function ids(mixed $v): mixed { return $v; }
var_dump(method_exists(ids('K'), ids('make')), method_exists(ids('K'), ids('inst')), method_exists(ids(new K()), ids('make')), method_exists(ids('Base'), ids('make')));
