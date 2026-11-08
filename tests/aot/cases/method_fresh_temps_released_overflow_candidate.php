<?php
class Hold { public function __construct(public string $n) {} public function __destruct() { echo "drop {$this->n}\n"; } }
class C {
    public function __construct(public ?Hold $h = null) {}
    public function contains($key) { return $key === 1; }
}
class Other { public function contains() { return count(func_get_args()); } }
function hold(string $n): Hold { return new Hold($n); }
function recv() { return (new C(new Hold("receiver")))->contains(1); }
function closureArg() { $h = new Hold("closure"); return (new C())->contains(function () use ($h) { return $h; }); }
function freshArg() { return (new C())->contains(hold("arg")); }
function strArg() { $s = str_repeat("x", 100000); return (new C())->contains($s . "y"); }
var_dump(recv());
echo "1\n";
var_dump(closureArg());
echo "2\n";
var_dump(freshArg());
echo "3\n";
var_dump(strArg());
$before = 0;
for ($i = 0; $i < 3000; $i++) {
    strArg();
    if ($i === 100) { $before = memory_get_peak_usage(); }
}
echo memory_get_peak_usage() - $before < 10000000 ? "flat\n" : "grew\n";
var_dump((new Other())->contains(1, 2, 3));
function hold2(string $n): array { return ['h' => new Hold($n)]; }
function arrLit() { return (new C())->contains([hold("lit0"), hold2("lit1")]); }
function mixedArg(): mixed { return hold("cell"); }
function cellArg() { return (new C())->contains(mixedArg()); }
function kept() { $c = new K(); $c->contains(hold("kept")); echo "still kept\n"; return $c; }
class K { public $x; public function contains($key) { $this->x = $key; return $this; } }
var_dump(arrLit());
echo "4\n";
var_dump(cellArg());
echo "5\n";
$k = kept();
echo "6\n";
unset($k);
echo "7\n";
