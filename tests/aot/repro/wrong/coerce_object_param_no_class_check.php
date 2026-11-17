<?php
// A mixed value of the wrong class, or null, bound to a class-typed parameter passes without a TypeError.
class Foo { public int $n = 1; }
class Bar { public int $n = 2; }
function h(Foo $o): int { return 7; }
function m(mixed $v): mixed { return $v; }
try { echo h(m(new Bar())), "\n"; } catch (TypeError $e) { echo explode(', called in', $e->getMessage())[0], "\n"; }
try { echo h(m(null)), "\n"; } catch (TypeError $e) { echo explode(', called in', $e->getMessage())[0], "\n"; }
