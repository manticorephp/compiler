<?php
class Cat { public function speak(): string { return "meow"; } public function kind(): string { return "cat"; } }
class Counter { public function speak(): int { return 42; } public function kind(): string { return "num"; } }
function make(string $c): object { return new $c(); }
// static method name, disagreeing returns across candidates -> cell + per-arm box
$a = make('Cat');
var_dump($a->speak());
var_dump($a->kind());
$b = make('Counter');
var_dump($b->speak());
var_dump($b->kind());
// dynamic method name, disagreeing returns
function callm(object $o, string $m) { return $o->$m(); }
var_dump(callm(new Cat(), 'speak'));
var_dump(callm(new Counter(), 'speak'));
var_dump(callm(new Cat(), 'kind'));
