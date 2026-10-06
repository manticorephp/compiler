<?php
// A TYPED by-ref param overwritten with another kind: php's reference is not
// typed past the entry coercion, so the caller's variable becomes that kind.
// The callee wrote the raw word into the caller's string / float / bool slot.
function toInt(string &$c): void { $c = 7; }
function toStr(float &$f): void { $f = 'f' . $f; }
function toArr(bool &$b): void { $b = [$b]; }
function keep(string &$c): void { $c = $c . '!'; }

$s = 'abc'; toInt($s); var_dump($s);
$f = 1.5; toStr($f); var_dump($f);
$b = true; toArr($b); var_dump($b);
$k = 'k'; keep($k); var_dump($k);
function inner(string $p): void { toInt($p); var_dump($p); }
inner('z');
