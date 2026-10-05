<?php
// A reference to an element of a TYPED buffer (`string[]`, `int[]`) promotes
// the buffer's element channel to cells, wherever the reference is taken: an
// `&...$xs` argument, an array literal `[&$a[$k]]`, `$r = &$a[$k]`. The
// emitter used to refuse the program ("a storable reference to an element of
// a string-elemented array").
function many(string $p, &...$slots): void { foreach ($slots as &$s) { $s = $p . $s; } }
function look(&...$slots): int { $n = 0; foreach ($slots as $s) { $n += strlen($s); } return $n; }
final class Bag { /** @var string[] */ public array $names = ['a', 'b']; }

$x = 'x';
$strArr = ['k' => 'v', 'm' => 'w'];
echo look($x, $strArr['k']), "\n";          // callee only reads
var_dump($strArr);
many('p-', $x, $strArr['k']);               // callee writes, caller sees it
var_dump($x, $strArr);

$ints = [1, 2, 3];
$l = [&$ints[1]];
$l[0] = 20;
unset($l);
var_dump($ints);

$b = new Bag();
many('q-', $b->names[0]);
var_dump($b->names);
