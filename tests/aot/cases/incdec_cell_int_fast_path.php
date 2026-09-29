<?php
// `++` / `--` on a value whose kind is known only at run time steps an inline
// int cell in place; a step past the 48-bit inline range, a float, a numeric
// string, a letter string and null keep php's answers.
function step(mixed $v): void {
    $a = $v; $b = $v;
    $a++; $b--;
    var_dump($a, $b);
}
foreach ([5, -1, 0, 140737488355327, -140737488355328, 1.5, "9"] as $v) { step($v); }
function loop(?int $i): int { $n = 0; while ($i !== null && $i > 0) { --$i; $n++; } return $n; }
echo loop(1000), "\n";
