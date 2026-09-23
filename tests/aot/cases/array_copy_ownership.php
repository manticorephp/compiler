<?php
// Every array that holds an element owns a count on it: a property that a
// superglobal copies from, a spread, a `+` union. The destructor says when the
// last holder let go.
class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~{$this->n}\n"; }
}
class H
{
    /** @var array<string, D> */
    public array $data = [];
}

function session(): void
{
    $h = new H();
    for ($i = 0; $i < 3; $i++) {
        $h->data = ['user' => new D("s$i")];
        $_SESSION = $h->data;
        echo "stored $i\n";
    }
    $_SESSION = [];
    echo "cleared\n";
}
session();
echo "after session\n";

function spread(): void
{
    $a = [new D('sp1'), new D('sp2')];
    $b = [...$a, new D('sp3')];
    unset($a);
    echo "a gone ", count($b), "\n";
    unset($b);
    echo "b gone\n";
}
spread();

function union(): void
{
    $a = ['x' => new D('ua')];
    $b = ['y' => new D('ub')];
    $c = $a + $b;
    unset($a, $b);
    echo "sources gone ", count($c), "\n";
    unset($c);
    echo "union gone\n";
}
union();
echo "done\n";
