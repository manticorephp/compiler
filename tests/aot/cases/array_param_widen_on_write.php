<?php
// A bare `array` param specialized from its callers (vec[int], vec[string], ...)
// must still hold any element kind its body (or a callee) writes into it.

final class P { public function __construct(public int $v) {} }

function show(string $tag, mixed $a): void { echo $tag, ' ', json_encode($a), "\n"; }

function appInt(array $a) { $a[] = 7; return $a; }
function appFloat(array $a) { $a[] = 1.5; return $a; }
function appBool(array $a) { $a[] = true; return $a; }
function appStr(array $a) { $a[] = "x"; return $a; }
function appArr(array $a) { $a[] = [1, "y"]; return $a; }
function appObj(array $a) { $a[] = new P(9); return $a; }
function appNull(array $a) { $a[] = null; return $a; }

function setStr(array $a) { $a[0] = "s"; return $a; }
function setInt(array $a) { $a[1] = 42; return $a; }
function setFloat(array $a) { $a['k'] = 2.5; return $a; }

/** Store a VALUE (not a literal) of another kind. */
function setVar(array $a, string $s) { $a[] = $s; return $a; }

$bufs = [
    'int' => [10, 20],
    'float' => [0.5, 1.25],
    'str' => ["a", "b"],
    'obj' => [new P(1), new P(2)],
    'arr' => [[1], [2]],
    'bool' => [false, true],
];
show('int+int', appInt($bufs['int']));
show('int+float', appFloat($bufs['int']));
show('int+bool', appBool($bufs['int']));
show('int+str', appStr($bufs['int']));
show('int+arr', appArr($bufs['int']));
show('int+obj', appObj($bufs['int']));
show('int+null', appNull($bufs['int']));
show('float+int', appInt([0.5, 1.25]));
show('float+str', appStr([0.5, 1.25]));
show('float+null', appNull([0.5, 1.25]));
show('str+int', appInt(["a", "b"]));
show('str+float', appFloat(["a", "b"]));
show('str+bool', appBool(["a", "b"]));
show('str+obj', appObj(["a", "b"]));
show('str+arr', appArr(["a", "b"]));
show('obj+str', appStr([new P(1), new P(2)]));
show('obj+int', appInt([new P(1), new P(2)]));
show('arr+str', appStr([[1], [2]]));
show('arr+int', appInt([[1], [2]]));
show('bool+str', appStr([false, true]));
show('set int<-str', setStr([10, 20]));
show('set str<-int', setInt(["a", "b"]));
show('set obj<-int', setInt([new P(1), new P(2)]));
show('set int<-float key', setFloat([10, 20]));
show('var int<-str', setVar([10, 20], "v"));
show('var float<-str', setVar([0.5], "v"));

// Builtins / prelude functions that write the param copy.
function pushStr(array $a) { array_push($a, "p", 3); return $a; }
function unshiftStr(array $a) { array_unshift($a, "u"); return $a; }
function spliceStr(array $a) { $r = array_splice($a, 1, 1, ["x", 2.5]); return [$a, $r]; }
show('push', pushStr([1, 2]));
show('unshift', unshiftStr([1, 2]));
show('splice', spliceStr([10, 20, 30]));
show('splice float', spliceStr([0.5, 1.5]));

// By-ref param written directly, through a forwarding param, and from a by-value param.
function refStr(array &$a) { $a[] = "r"; }
function refFwd(array &$a) { refStr($a); $a[] = 5; }
function byValToRef(array $a) { refStr($a); return $a; }
$x = [1, 2];
refStr($x);
show('ref', $x);
$y = [1.5];
refFwd($y);
show('ref fwd', $y);
show('val->ref', byValToRef([7, 8]));

// foreach by reference writing another kind into the param.
function feRef(array $a) { foreach ($a as &$v) { $v = "e" . $v; } unset($v); return $a; }
show('foreach&', feRef([1, 2]));

// list()/destructuring into elements.
function destr(array $a) { [$a[0], $a[1]] = ["d", 3.5]; return $a; }
show('list', destr([1, 2]));

// Properties: other sites made the element int.
final class Bag {
    public array $items = [];
    public static array $all = [];
    public function addInt(int $i): void { $this->items[] = $i; }
    public function addStr(): void { $this->items[] = "s"; }
}
$b = new Bag();
$b->addInt(1);
$b->addInt(2);
$b->addStr();
show('prop', $b->items);
Bag::$all[] = 1;
Bag::$all[] = "t";
show('static', Bag::$all);

// Closures: by-value capture copied and written, by-ref capture written.
function closVal(array $a) { $f = function () use ($a) { $a[] = "c"; return $a; }; return $f(); }
function closRef(array $a) { $f = function () use (&$a) { $a[] = "c"; }; $f(); return $a; }
show('clos val', closVal([1, 2]));
show('clos ref', closRef([1, 2]));

// The typed fast path still reads its own kind back.
function sumAfter(array $a) { $a[] = 3; $s = 0; foreach ($a as $v) { $s += $v; } return $s; }
echo sumAfter([1, 2]), "\n";

// The entry conversion owns its rebuild: the caller's objects die with the caller.
final class D { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
function widenObjs(array $p): int { $p[] = "x"; return \count($p); }
function refElem(array $p): int { $r = &$p[0]; $r = "s"; return \count($p); }
function ownerFrame(): void { $a = [new D('o1'), new D('o2')]; echo widenObjs($a), refElem($a), "\n"; }
ownerFrame();
echo "after owner\n";
