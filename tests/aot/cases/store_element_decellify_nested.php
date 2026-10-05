<?php
// A vec[cell] stored into an array<string, bool[]> element is rebuilt with raw
// elements: stored as is, a boxed false read back as true.
final class P { public function __construct(public bool $byRef) {} }
final class A { public function __construct(public bool $byRef, public int $z = 0) {} }
final class Sigs { /** @var array<string, bool[]> */ public array $ref = []; }
/** @param list<mixed> $ps */
function build(array $ps, Sigs $s): void {
    $mask = [];
    foreach ($ps as $p) { $mask[] = $p->byRef; }
    $s->ref['f'] = $mask;
}
$s = new Sigs();
build([new P(false), new A(true), new P(false)], $s);
$m = $s->ref['f'];
var_dump($m[0] ?? false, $m[1] ?? false, ($s->ref['f'] ?? [])[2] ?? false);
if ($m[0]) { echo "wrong\n"; } else { echo "ok\n"; }
