<?php
// Arithmetic and comparison on two inline int cells take a call-free path;
// a result past the 48-bit inline range, an overflow into float, and a mixed
// operand pair keep the general answers.
function ops(mixed $a, mixed $b): void {
    var_dump($a + $b, $a - $b, $a * $b, $a < $b, $a <=> $b, $a == $b, $a >= $b);
}
ops(3, 5);
ops(-7, 2);
ops(140737488355327, 1);
ops(-140737488355328, 1);
ops(140737488355327, 140737488355327);
ops(PHP_INT_MAX, 1);
ops(4, 2.5);
ops(4, "4");
ops(10, "9");
ops("10", 9);
function loop(array $xs): int { $n = 0; foreach ($xs as $i => $x) { for ($j = $i; $j >= 0; --$j) { $n = $n + $j; } } return $n; }
echo loop(['a', 'b', 'c', 'd']), "\n";
