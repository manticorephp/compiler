<?php
// `array_shift`/`array_pop` answer NULL on an empty array, and an ERASED
// consumer has to see it: the raw runtime answered 0, and `null !== $x` over an
// unknown-typed $x only ever compared the carrier against 0, so a boxed NULL
// read as "not null" either way.
// symfony's ArgvInput drains its token list with
// `while (null !== $token = array_shift($this->parsed))` — it ran one extra
// iteration past the end and every command reported "Too many arguments".
class Q
{
    private array $items = [];
    public function fill(array $t): void { $this->items = $t; }
    public function drain(): int
    {
        $n = 0;
        while (null !== $tok = \array_shift($this->items)) {
            $n = $n + 1;
            if ($n > 8) { echo "RUNAWAY\n"; break; }
        }
        return $n;
    }
    public function drainPop(): int
    {
        $n = 0;
        while (null !== $tok = \array_pop($this->items)) {
            $n = $n + 1;
            if ($n > 8) { echo "RUNAWAY\n"; break; }
        }
        return $n;
    }
}
$q = new Q();
$q->fill(['a', 'b', 'c']);
echo 'shift drained=', $q->drain(), "\n";
$q->fill(['a', 'b']);
echo 'pop drained=', $q->drainPop(), "\n";
$q->fill([]);
echo 'empty drained=', $q->drain(), "\n";

$e = [];
var_dump(\array_shift($e));
var_dump(\array_pop($e));
var_dump(null === \array_shift($e));

$m = [1, 'two', 3.5, null, true];
while (null !== $v = \array_shift($m)) { var_dump($v); }
echo 'left=', \count($m), "\n";
