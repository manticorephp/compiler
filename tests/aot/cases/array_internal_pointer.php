<?php

// The internal-pointer family: current/key/next/prev/reset/end.
// php keeps a cursor per ARRAY VALUE; here it lives in bits 36-63 of the
// array header flags word, above the tombstone counter, so a COW clone
// carries the position with the value. Moving it is a WRITE: the builtin
// COWs, and the mutated-vec pre-scan counts next/prev/reset/end so a prior
// $b = $a copy-on-assigns instead of sharing the cursor.

// A fresh array starts on its first element.
$a = [10, 20, 30];
var_dump(current($a));
var_dump(key($a));

// next / prev walk it.
var_dump(next($a));
var_dump(key($a));
var_dump(next($a));
var_dump(key($a));
var_dump(next($a));      // past the end -> false
var_dump(key($a));       // -> null
var_dump(current($a));   // still past the end -> false

// reset / end reposition it.
var_dump(reset($a));
var_dump(key($a));
var_dump(end($a));
var_dump(key($a));
var_dump(prev($a));
var_dump(key($a));

// Off the front.
var_dump(prev($a));
var_dump(prev($a));
var_dump(key($a));

// String keys.
$b = ['x' => 1, 'y' => 2, 'z' => 3];
var_dump(current($b));
var_dump(key($b));
next($b);
var_dump(current($b));
var_dump(key($b));
var_dump(end($b));
var_dump(key($b));

// Mixed keys.
$c = ['k' => 'v', 7, 'w' => 9];
var_dump(key($c));
next($c);
var_dump(key($c));
var_dump(current($c));
next($c);
var_dump(key($c));

// Empty array.
$d = [];
var_dump(current($d));
var_dump(key($d));
var_dump(reset($d));
var_dump(end($d));
var_dump(next($d));

// Single element.
$e = ['only'];
var_dump(current($e));
var_dump(next($e));
var_dump(prev($e));
var_dump(reset($e));

// reset() returns the first VALUE, end() the last.
$f = ['a', 'b', 'c'];
var_dump(reset($f), end($f));

// The cursor does not disturb foreach or count.
$g = [1, 2, 3];
next($g);
next($g);
var_dump(count($g));
foreach ($g as $k => $v) { echo $k, '=', $v, ' '; }
echo "\n";
var_dump(key($g));

// Value semantics: an alias must NOT share the cursor.
$a = [1, 2, 3];
$b = $a;
next($a);
echo "a=", key($a), " b=", key($b), "\n";
// After a real separating write the cursors are definitely independent.
$c = [1, 2, 3];
$d = $c;
$d[] = 4;
next($c);
echo "c=", key($c), " d=", key($d), "\n";
