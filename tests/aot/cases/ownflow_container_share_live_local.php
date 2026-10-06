<?php
// An erased array local stored into a cell container (whose boxing takes no
// count of a raw word) stays readable after the container is gone — the
// container takes its own +1 because the local is read again: straight-line,
// in a loop, across an if/else join.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function erase(array $a): array { return $a; }
function straight(): void
{
    $r = erase(['k' => new D('s')]);
    $c = [1, "x"];
    $c[] = $r;
    unset($c);
    echo "read ", $r['k']->n, "\n";
}
function loop(): void
{
    $r = erase(['k' => new D('l')]);
    for ($i = 0; $i < 3; $i++) {
        $c = [1, "x"];
        $c[] = $r;
        unset($c);
        echo "read ", $i, " ", $r['k']->n, "\n";
    }
}
function joined(bool $f): void
{
    $r = erase(['k' => new D($f ? 'jt' : 'jf')]);
    $c = [1, "x"];
    if ($f) { $c[] = $r; } else { echo "skip\n"; }
    unset($c);
    echo "read ", $r['k']->n, "\n";
}
straight();
echo "--\n";
loop();
echo "--\n";
joined(true);
joined(false);
echo "done\n";
