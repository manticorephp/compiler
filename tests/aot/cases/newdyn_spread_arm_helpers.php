<?php
// new $cls(...$args): per-class spread arm helpers.
class Plain {}
class One { public function __construct(public int $a) {} }
class Def { public function __construct(public $a = 1, public $b = "d") {} }
class Two { public function __construct(public readonly int $x, public readonly string $y = "yy") {} }
class Child extends Two {}
class Str { public function __construct(public string $s, public float $f = 1.5) {} }
class G0 { public function __construct(public $p, public $q = 0) {} }
class G1 { public function __construct(public $p, public $q = 1) {} }
class G2 { public function __construct(public $p, public $q = 2) {} }
class G3 { public function __construct(public $p, public $q = 3) {} }
class G4 { public function __construct(public $p, public $q = 4) {} }
class G5 { public function __construct(public $p, public $q = 5) {} }
class G6 { public function __construct(public $p, public $q = 6) {} }
class G7 { public function __construct(public $p, public $q = 7) {} }
class G8 { public function __construct(public $p, public $q = 8) {} }
class G9 { public function __construct(public $p, public $q = 9) {} }
class G10 { public function __construct(public $p, public $q = 10) {} }
class G11 { public function __construct(public $p, public $q = 11) {} }
class G12 { public function __construct(public $p, public $q = 12) {} }
class G13 { public function __construct(public $p, public $q = 13) {} }
class G14 { public function __construct(public $p, public $q = 14) {} }
class G15 { public function __construct(public $p, public $q = 15) {} }
class G16 { public function __construct(public $p, public $q = 16) {} }
class G17 { public function __construct(public $p, public $q = 17) {} }
class G18 { public function __construct(public $p, public $q = 18) {} }
class G19 { public function __construct(public $p, public $q = 19) {} }
class G20 { public function __construct(public $p, public $q = 20) {} }
class G21 { public function __construct(public $p, public $q = 21) {} }
function mk(string $c, array $a) { try { $o = new $c(...$a); return json_encode($o); } catch (\Throwable $e) { return get_class($e) . ($e instanceof ArgumentCountError ? "" : ": " . $e->getMessage()); } }
function mk2(string $c, $f, array $a) { try { $o = new $c($f, ...$a); return json_encode($o); } catch (\Throwable $e) { return get_class($e); } }
foreach ([["Plain",[]],["One",[5]],["One",[]],["Def",[]],["Def",[7]],["Def",[7,"x"]],["Def",["b"=>"nm"]],["Two",[3]],["Two",[3,"z"]],["Two",["y"=>"k","x"=>9]],["Child",[4]],["Str",["s"]],["Str",["s",2.5]],["G0",[1]],["G7",[1,2]],["G21",[3]]] as [$c,$a]) echo $c, ": ", mk($c, $a), "\n";
echo mk2("Two", 1, []), mk2("Two", 1, ["q"]), mk2("G3", 1, [2]), "\n";
function mk3(string $c, array $a) { try { return json_encode(new $c(...array_values($a))); } catch (\Throwable $e) { return get_class($e); } }
function mk4(string $c, $a) { try { return json_encode(new $c(...$a)); } catch (\Throwable $e) { return get_class($e); } }
foreach ([["Plain", []], ["One", [5]], ["One", []], ["Def", [7, "x"]], ["Two", [3, "z"]], ["Child", [4]], ["Str", ["s", 2.5]], ["G5", [1]], ["G20", [1, 2]]] as [$c, $a]) {
    echo $c, ": ", mk3($c, $a), " ", mk4($c, $a), "\n";
}
echo mk4("Two", ["y" => "k", "x" => 9]), mk4("Def", ["b" => "nm"]), "\n";
