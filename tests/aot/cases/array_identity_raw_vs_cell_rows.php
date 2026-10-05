<?php
// `===` / `==` between a typed list of rows and an erased list (array_map result,
// usort'd copy) holding the same rows compares by value, as in Zend.
class E { public function __construct(public string $v) {} }
$a = new E('a');
$b = new E('b');
$x = [['start_index' => 0, 'expression' => $a], ['start_index' => 4, 'expression' => $b]];
$same = array_map(static fn (array $i) => $i, $x);
$other = array_map(static fn (array $i) => $i, [['start_index' => 0, 'expression' => $b]]);
var_dump($same === $x, $same !== $x, $same == $x, $x === $same);
var_dump($other === $x, $other !== $x, $other == $x);
$s = [['s' => 0, 'e' => 'q']];
$t = array_map(static fn (array $i) => $i, $s);
var_dump($t === $s, $t !== $s);
