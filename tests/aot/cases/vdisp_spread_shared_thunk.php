<?php
// Plain and spread call sites of one polymorphic method share dispatch thunks keyed by the spread shape.
interface I { public function m($x, ...$rest); public function n($x, $y = 'dy'); }
class A implements I {
    public function m($x, ...$rest) { return 'A' . $x . ':' . implode(',', $rest); }
    public function n($x, $y = 'dy') { return 'An' . $x . $y; }
}
class B implements I {
    public function m($x, ...$rest) { return 'B' . $x . ':' . implode(',', $rest); }
    public function n($x, $y = 'dy') { return 'Bn' . $x . $y; }
}
function p1(I $o) { return $o->m(1); }
function p2(I $o) { return $o->m(1, 2, 3); }
function s1(I $o, array $r) { return $o->m(1, 2, ...$r); }
function s2(I $o, array $r) { return $o->m(1, 2, 3, ...$r); }
function s3(I $o, array $r) { return $o->m(...$r); }
function q1(I $o) { return $o->n(1); }
function q2(I $o) { return $o->n(1, 2); }
function r1(I $o, array $r) { return $o->n(1, ...$r); }
function r2(I $o, array $r) { return $o->n(...$r); }
function r3(I $o, array $r) { return $o->n(1, 2, ...$r); }
foreach ([new A(), new B()] as $o) {
    echo p1($o), ' ', p2($o), ' ', s1($o, [7, 8]), ' ', s2($o, [9]), ' ', s3($o, [4, 5]), "\n";
    echo q1($o), ' ', q2($o), ' ', r1($o, ['x']), ' ', r1($o, []), ' ', r2($o, ['u', 'v']), ' ', r2($o, ['u']), ' ', r3($o, ['w']), "\n";
}
