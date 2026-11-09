<?php
// A frame keeps its call-site line when the call goes through a receiver some class answers by __call, and through a dynamic $o->$m()
function where(): string { $t = (new Error(''))->getTrace(); return basename($t[1]['file'] ?? '-') . ':' . ($t[1]['line'] ?? '-'); }
class A { public function render(string $n): string { return where(); } }
class B { public function render(string $n): string { return 'b'; } }
class M { public function __call(string $n, array $a): string { return 'magic ' . $n; } }
function pick(int $i): object { return match ($i) { 0 => new A(), 1 => new B(), default => new M() }; }
function gen(): \Generator {
    yield pick(0)->render("x");
    $m = 'render';
    yield pick(0)->$m("y");
}
echo pick(0)->render("z"), "\n";
foreach (gen() as $v) { echo $v, "\n"; }
echo pick(2)->render("w"), "\n";
