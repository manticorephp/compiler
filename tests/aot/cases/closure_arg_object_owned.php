<?php
final class O { public function __construct(public string $t) {} public function __destruct() { echo "~{$this->t}\n"; } }
function run(): void {
    $g = function ($v) { echo get_class($v), "\n"; };
    $g(new O('a'));
    echo "after-a\n";
    $h = function ($v) { return $v; };
    $o = $h(new O('b'));
    echo $o->t, "\n";
    $o = null;
    echo "after-b\n";
    $k = new O('c');
    $g($k);
    $x = $h($k);
    $k = null;
    echo "k-null\n";
    $x = null;
    echo "after-c\n";
}
run();
echo "end\n";
function run2(): void {
    $g = function (O $v) { echo $v->t, "\n"; };
    $g(new O('a'));
    echo "after-a\n";
    $h = function (O $v): O { return $v; };
    $o = $h(new O('b'));
    $o = null;
    echo "after-b\n";
    $s = function (string $v) { echo strlen($v), "\n"; };
    $s(str_repeat('x', 3));
}
run2();
echo "end2\n";
