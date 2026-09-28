<?php
// peek_f64 / poke_f64 (a superset builtin, no Zend oracle): a C double in raw
// memory, bit-exact both ways.
#[\Ffi\Library('c'), \Ffi\Symbol('malloc')]
function c_malloc(#[\Ffi\CType('size_t')] int $n): \Ffi\Ptr {}
#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function c_free(\Ffi\Ptr $p): void {}

$p = c_malloc(16);
foreach ([0.0, -0.0, 1.5, -1234.5678, 1e308, 5e-324, M_PI, INF, -INF] as $d) {
    poke_f64($p, 8, $d);
    var_dump(peek_f64($p, 8));
}
poke_f64($p, 0, 1.5);
var_dump(peek_i64($p, 0));
poke_i64($p, 0, -4611686018427387904);
var_dump(peek_f64($p, 0));
poke_f64($p, 0, 3);
var_dump(peek_f64($p, 0));
poke_f64($p, 0, NAN);
var_dump(is_nan(peek_f64($p, 0)));
c_free($p);
