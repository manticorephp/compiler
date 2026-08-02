<?php
// An UNHINTED static property holding a FLOAT reads back as a garbage int.
//
// The store boxes (the declared type is KIND_UNKNOWN, which is in the boxing
// condition), so the slot holds `box_float(2.5)`. In this NaN-boxing scheme a
// plain double IS its own encoding — 2.5 is 0x4004000000000000, which is NOT in
// the NaN tag space. The UNKNOWN read path then emits a runtime guard:
//
//     %r10 = icmp ugt i64 %r9, -4503599627370496    ; in NaN space?
//     %r12 = and (lshr i64 %r9, 48), 15             ; tag nibble in [1,8]?
//     %r17 = call i64 @__manticore_box_int(i64 %r9) ; else: assume INT
//     %r18 = select i1 %r16, i64 %r9, i64 %r17
//
// "not tagged" is ambiguous — it means either a raw int or a double — and the
// adapter resolves it to INT unconditionally. So the double's bits come back as
// an integer. A `float` hint and a `mixed` hint are both correct; only the
// unhinted (KIND_UNKNOWN) channel diverges.
//
// Statement position, no crash, no value-position involved: this is NOT the
// assign-as-expression bug, which is closed by
// tests/aot/cases/static_prop_assign_expr.php.

class M
{
    public static float $hinted = 0.0;
    public static $unhinted = 0.0;
    public static mixed $mixed = 0.0;
}

M::$hinted = 2.5;
M::$unhinted = 2.5;
M::$mixed = 2.5;

var_dump(M::$hinted);    // float(2.5)
var_dump(M::$unhinted);  // php: float(2.5)   manticore: int(4612811918334230528)
var_dump(M::$mixed);     // float(2.5)

echo M::$hinted + 1.0, "\n";    // 3.5
echo M::$unhinted + 1.0, "\n";  // php: 3.5   manticore: 4.6128119183342E+18
echo M::$mixed + 1.0, "\n";     // 3.5
