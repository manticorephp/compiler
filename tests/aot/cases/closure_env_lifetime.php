<?php
// A capturing closure's env is refcounted (magic/retain/drop/rc at negative
// offsets, value ptr still at fn_ptr) and its generated drop releases exactly
// the captures the literal retained. Everything here is a way for that to go
// WRONG by one: each shape either double-frees a capture or hands a live
// closure a freed one, and php is the oracle for all of it.

// 1. the plain shape: build, call, drop, repeat — the captured string must
//    still be intact at every call
$out = [];
for ($i = 0; $i < 3; $i++) {
    $s = substr("prefix-value", 0, 6 + $i);
    $f = function () use ($s) { return strtoupper($s); };
    $out[] = $f();
}
echo implode(",", $out), "\n";

// 2. a closure RETURNED from a function: the +1 transfers, the caller owns it
function makeAdder(int $n): callable
{
    $label = "add" . $n;
    return function (int $x) use ($n, $label) { return $label . ":" . ($x + $n); };
}
$a = makeAdder(5);
$b = makeAdder(7);
echo $a(1), " ", $b(1), " ", $a(2), "\n";

// 3. a closure stored in an ARRAY and read back out — the array's copy must
//    outlive the local that built it
function collect(): array
{
    $fns = [];
    foreach (["x", "y"] as $tag) {
        $fns[] = function (int $v) use ($tag) { return $tag . $v; };
    }
    return $fns;
}
$fns = collect();
echo $fns[0](1), $fns[1](2), "\n";

// 4. an ALIAS: two locals, one env
$msg = "shared" . "-env";
$one = function () use ($msg) { return $msg; };
$two = $one;
$one = null;
echo $two(), "\n";

// 5. a closure captured BY another closure
$inner = function (string $t) { return "[" . $t . "]"; };
$outer = function (string $t) use ($inner) { return $inner($t) . $inner($t); };
echo $outer("z"), "\n";

// 6. BY-REF capture: the slot address is packed, never an rc value
$count = 0;
$bump = function () use (&$count) { $count = $count + 1; };
$bump();
$bump();
echo $count, "\n";

// 7. an object capture, with a destructor to make the drop observable
class Res
{
    public function __construct(public string $name)
    {
    }

    public function __destruct()
    {
        echo "free ", $this->name, "\n";
    }
}
function useRes(): string
{
    $r = new Res("inner");
    $g = function () use ($r) { return $r->name; };
    return $g();
}
echo useRes(), "\n";

// 8. bindTo: the copy co-owns what it aliases, both stay usable
class Ctx
{
    public string $who = "ctx";

    public function run(): string
    {
        $tag = "t" . "ag";
        $f = function () use ($tag) { return $this->who . "/" . $tag; };
        $other = new Ctx();
        $other->who = "other";
        $g = $f->bindTo($other, Ctx::class);
        return $f() . " " . $g();
    }
}
$c = new Ctx();
echo $c->run(), "\n";

// 9. a static (no-capture) closure stays a plain, unowned struct
$plain = static function (int $x): int { return $x * 3; };
echo $plain(4), " ", $plain(5), "\n";

// 10. array_map / usort borrow the closure, they never own it
$src = [3, 1, 2];
$k = 10;
$mapped = array_map(function (int $v) use ($k) { return $v + $k; }, $src);
usort($mapped, function (int $x, int $y) { return $x <=> $y; });
echo implode(",", $mapped), "\n";
echo "end\n";
