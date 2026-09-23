<?php
// A reference box is freed when its LAST holder lets go — the frame that made
// it, a closure env that captured it, a REF cell in an array — and the value
// inside dies with it. The destructor is the witness: php runs it the moment
// the box goes, not at shutdown.
class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~{$this->n}\n"; }
}

function closureBox(): void
{
    $o = new D('closure');
    $g = function () use (&$o) { return $o->n; };
    echo $g(), "\n";
    echo "end closure\n";
}
closureBox();
echo "after closure\n";

function arrBox(): void
{
    $o = new D('arr');
    $r = [&$o];
    echo $r[0]->n, "\n";
    echo "end arr\n";
}
arrBox();
echo "after arr\n";

function escape(): array
{
    $o = new D('esc');
    return [&$o];
}
$e = escape();
echo "held ", $e[0]->n, "\n";
unset($e);
echo "after esc\n";

// The box outlives the frame that made it: the explicit return gives back the
// frame's count and the closure's keeps the box alive. The return path used to
// release the SLOT as the local's own type — the box address handed to the
// object release, which trapped.
class E
{
    public function __construct(public string $n) {}
}
function escClosure()
{
    $o = new E('escc');
    return function () use (&$o) { $o->n .= '!'; return strlen($o->n); };
}
$c = escClosure();
echo $c(), ' ', $c(), "\n";

function elem(): void
{
    $v = [new D('elem'), 1];
    $r = [&$v[0]];
    echo "end elem ", $r[0]->n, "\n";
}
elem();
echo "after elem\n";

function share(): array
{
    $x = 1;
    $a = [&$x];
    $b = $a;
    $b[0] = 5;
    return [$x, $a[0]];
}
var_dump(share());

function loop(): void
{
    for ($i = 0; $i < 3; $i++) {
        $o = new D("l$i");
        $r = [&$o];
        unset($r);
    }
    echo "end loop\n";
}
loop();
echo "done\n";

// unset() breaks the name's binding: the frame lets go of the box, and the
// value dies with the last holder — here the array, one line later.
function unbind(): void
{
    $o = new D('unbound');
    $r = [&$o];
    unset($o);
    echo "after unset o\n";
    unset($r);
    echo "after unset r\n";
}
unbind();
