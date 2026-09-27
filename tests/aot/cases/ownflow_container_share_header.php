<?php
// A local stored into a cell container from a loop HEADER, or from an `if`
// condition whose branch rebinds the name, stays readable after the container
// is freed: the container takes its +1 on the read itself.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function erase(array $a): array { return $a; }
function inHeader(): void
{
    $r = erase(['k' => new D('h')]);
    $c = [1, "x"];
    for ($i = 0; ($c[] = $r) && $i < 2; $i++) { echo "it ", $i, "\n"; }
    unset($c);
    echo "read ", $r['k']->n, "\n";
}
function cond(bool $f): void
{
    $r = erase(['k' => new D($f ? 'ct' : 'cf')]);
    $c = [1, "x"];
    if (($c[] = $r) && $f) {
        unset($c);
        echo "read ", $r['k']->n, "\n";
        $r = erase(['k' => new D('rebound')]);
    }
    unset($c);
    echo "then ", $r['k']->n, "\n";
}
inHeader();
cond(true);
cond(false);
echo "done\n";
