<?php

// The cursor family across every argument shape the corpus writes:
// `key(class_implements($c))` in symfony/error-handler FlattenException and
// symfony/console Application, `current(array_diff(...))` in symfony/routing
// AttributeClassLoader, `key($config[$k])` in symfony/config Processor.
//
// ⚠ This is a SEMANTICS LOCK, not the regression test for the by-value
// signature change that landed alongside it. It was written expecting the
// non-lvalue arguments below to be refused while current()/key() were declared
// `array &$arr`, and they are NOT: a non-lvalue at a static by-ref slot gets
// backed by a throwaway alloca, and current/key are intercepted as codegen
// builtins (biArrayCursor) before the declaration is consulted at all. Restoring
// the `&` and rebuilding leaves every line below passing.
//
// The by-value change is gated by docs/audit/data/byref-arity.tsv instead — the
// ReflectionFunction diff against Zend, where the row simply disappears. What is
// locked here is that neither spelling disturbs the array: only next/prev/reset/
// end move the cursor, and only they COW.

$src = ['b' => 2, 'a' => 1, 'c' => 3];

// call results, not lvalues
var_dump(key(array_filter($src, fn ($v) => $v > 1)));
var_dump(current(array_values($src)));
var_dump(current(array_diff(array_keys($src), ['b'])));

// a literal, the least lvalue-ish argument there is
var_dump(current([10, 20, 30]));
var_dump(key(['z' => 1, 'y' => 2]));

// a plain local still works, and reading through it must not disturb the array
$a = ['x' => 7, 'y' => 8];
var_dump(current($a), key($a));
var_dump($a);

// an ELEMENT argument: by-ref this reached the vivifying element-slot path, so
// the array must come back out with exactly the one key it went in with
$nested = ['present' => ['p' => 1, 'q' => 2]];
var_dump(key($nested['present']), current($nested['present']));
var_dump(count($nested));
var_dump($nested);

// the cursor family that really does move the pointer is untouched
$c = [1, 2, 3];
var_dump(current($c));
next($c);
var_dump(current($c), key($c));
reset($c);
var_dump(current($c), key($c));
end($c);
var_dump(current($c), key($c));

// empty array: php answers false / null
$e = [];
var_dump(current($e), key($e));
