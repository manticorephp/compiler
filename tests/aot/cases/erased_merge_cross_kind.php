<?php
// An erased (bare `array`) value and a value of another kind reaching one
// local over different paths — if/else, a loop back edge, switch arms, a
// catch — make the local a cell: every read sees the kind that is really
// there, and the erased array is released when it is replaced.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function erase(array $a): array { return $a; }
function kind(mixed $v): string { return is_string($v) ? 's' : (is_array($v) ? 'a' : (is_int($v) ? 'i' : '?')); }
function ifElse(bool $c): void
{
    $r = erase(['k' => new D('if')]);
    if ($c) { $r = 'x'; }
    echo "if ", is_string($r) ? 's' : 'a', "\n";
}
function loop(): void
{
    $r = erase(['k' => new D('loop')]);
    for ($i = 0; $i < 2; $i++) {
        echo "loop ", kind($r), "\n";
        $r = $i;
    }
    echo "after ", kind($r), "\n";
}
function sw(int $k): void
{
    $r = erase(['k' => new D('sw' . $k)]);
    switch ($k) {
        case 1: $r = 'one'; break;
        case 2: $r = 2; break;
    }
    echo "sw ", kind($r), "\n";
}
function tc(bool $t): void
{
    $r = erase(['k' => new D('tc')]);
    try {
        if ($t) { throw new RuntimeException('x'); }
    } catch (RuntimeException $e) {
        $r = 'caught';
    }
    echo "tc ", kind($r), "\n";
}
ifElse(true);
ifElse(false);
loop();
sw(1);
sw(2);
sw(3);
tc(true);
tc(false);
echo "done\n";
