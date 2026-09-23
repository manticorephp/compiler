<?php
// An array's elements belong to the array, whoever else holds it: a second
// holder counts the buffer, and the elements die once, with the last holder.
// Each shape here leaked every element before — the co-own model gave each
// holder its own element refs and only some releases gave them back.
class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~{$this->n}\n"; }
}

function twice(): void
{
    $m = ['x', 7, new D('twice')];
    $c = [$m, $m];
    unset($m);
    echo "m gone ", count($c), "\n";
    unset($c);
    echo "c gone\n";
}
twice();

class P { public mixed $arr = null; }
function clonedProp(): void
{
    $p = new P();
    $p->arr = ['x', 7, new D('cloned')];
    $q = clone $p;
    unset($p);
    echo "p gone ", $q->arr[2]->n, "\n";
    unset($q);
    echo "q gone\n";
}
clonedProp();

final class Via { public static function mixed(mixed $m): array { $out = []; $out[] = $m; $out[] = $m; return $out; } }
function mixedParam(): void
{
    $r = Via::mixed(['k', new D('mixed')]);
    echo "held ", count($r), "\n";
    unset($r);
    echo "released\n";
}
mixedParam();

function merged(): void
{
    $all = [];
    for ($i = 0; $i < 2; $i++) { $all = array_merge($all, [new D("mg$i")]); }
    echo "merged ", count($all), "\n";
    unset($all);
    echo "merge gone\n";
}
merged();
echo "done\n";
