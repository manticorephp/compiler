<?php
use function Async\async; use function Async\spawn;

function slow(string $s, int $n): string { \Async\delay(0.02); return $s . (string)$n; }

$a = async(function (): string {
    $t = spawn(function (): string { return slow('hi', 7); });   // suspend INSIDE a callee
    $v = $t->await();
    return 'a=[' . (string)$v . ']';
});
echo $a, "\n";

$b = async(function (): string {
    $t = spawn(function (): string { \Async\delay(0.02); return 'direct'; });  // suspend in the task body
    return 'b=[' . (string)$t->await() . ']';
});
echo $b, "\n";

function slowBuf(int $n): string { \Async\delay(0.02); return str_repeat('x', $n); }
$c = async(function (): string {
    $t = spawn(function (): string { $d = slowBuf(5); return $d === false ? 'F' : $d; });
    $v = $t->await();
    return 'c=[' . (string)$v . '] len=' . (string)strlen((string)$v);
});
echo $c, "\n";
