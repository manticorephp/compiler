<?php
// Cell -> typed coercion: error texts and conversions the native binary already matches php on (pinned before table coercion, docs/design/table-coercion.md). Rows that still differ are repros under tests/aot/repro.
enum Suit: string { case H = 'h'; case S = 's'; }
class Foo { public int $n = 1; public string $s = ''; public ?Foo $o = null; public function get(int $k): int { return $this->n + $k; } }
class NoStr {}
class WithStr { public function __toString(): string { return "ws"; } }
function m(mixed $v): mixed { return $v; }
function takeFoo(Foo $o): int { return $o->n; }
function takeInt(int $x): int { return $x; }
function takeStr(string $s): string { return "[" . $s . "]"; }
function takeFloat(float $f): float { return $f; }
function takeBool(bool $b): string { return $b ? "T" : "F"; }
function takeArr(array $a): int { return \count($a); }
function takeSuit(Suit $s): string { return $s->value; }
function retInt(mixed $v): int { return $v; }
function retStr(mixed $v): string { return $v; }
const KEEP = ['recv int' => true, 'int int' => true, 'str int' => true, 'float int' => true, 'bool int' => true, 'foo int' => true, 'retint int' => true, 'retstr int' => true, 'prop-int int' => true, 'prop-str int' => true, 'recv numstr' => true, 'int numstr' => true, 'str numstr' => true, 'float numstr' => true, 'foo numstr' => true, 'retint numstr' => true, 'retstr numstr' => true, 'prop-int numstr' => true, 'prop-str numstr' => true, 'recv lead' => true, 'int lead' => true, 'str lead' => true, 'float lead' => true, 'foo lead' => true, 'retint lead' => true, 'retstr lead' => true, 'prop-int lead' => true, 'prop-str lead' => true, 'recv str' => true, 'str str' => true, 'foo str' => true, 'retstr str' => true, 'prop-int str' => true, 'prop-str str' => true, 'recv true' => true, 'int true' => true, 'str true' => true, 'float true' => true, 'bool true' => true, 'foo true' => true, 'retint true' => true, 'retstr true' => true, 'prop-int true' => true, 'prop-str true' => true, 'recv false' => true, 'int false' => true, 'str false' => true, 'float false' => true, 'bool false' => true, 'foo false' => true, 'retint false' => true, 'retstr false' => true, 'prop-int false' => true, 'prop-str false' => true, 'prop-int null' => true, 'prop-int arr' => true, 'recv foo' => true, 'foo foo' => true, 'str withstr' => true, 'retstr withstr' => true, 'prop-str withstr' => true, 'arr arr' => true, 'suit' => true, 'str float' => true, 'float float' => true, 'retstr float' => true, ];
function run(string $label, callable $f): void {
    if (!isset(KEEP[$label])) { return; }
    try { $r = $f(); echo $label, ': '; var_dump($r); }
    catch (\Throwable $e) { echo $label, ': ', \get_class($e), ': ', explode(', called in', $e->getMessage())[0], "\n"; }
}
$vals = ['int' => 5, 'numstr' => '12', 'lead' => ' 7', 'str' => 'abc', 'true' => true, 'false' => false, 'null' => null, 'arr' => [1, 2], 'foo' => new Foo(), 'withstr' => new WithStr()];
foreach ($vals as $k => $v) {
    if ($v !== null && !\is_array($v)) { run("recv $k", fn() => m($v)->get(1)); }
    run("int $k", fn() => takeInt(m($v)));
    run("str $k", fn() => takeStr(m($v)));
    run("float $k", fn() => takeFloat(m($v)));
    run("bool $k", fn() => takeBool(m($v)));
    run("foo $k", fn() => takeFoo(m($v)));
    run("retint $k", fn() => retInt(m($v)));
    run("retstr $k", fn() => retStr(m($v)));
    run("prop-int $k", function () use ($v) { $o = new Foo(); $o->n = m($v); return $o->n; });
    run("prop-str $k", function () use ($v) { $o = new Foo(); $o->s = m($v); return $o->s; });
}
run("arr arr", fn() => takeArr(m([1, 2, 3])));
run("suit", fn() => takeSuit(m(Suit::S)));
run("nostr", fn() => takeStr(m(new NoStr())));
run("str float", fn() => takeStr(m(2.5)));
run("float float", fn() => takeFloat(m(2.5)));
run("retstr float", fn() => retStr(m(2.5)));
