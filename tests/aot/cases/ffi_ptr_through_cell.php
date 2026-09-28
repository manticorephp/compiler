<?php
// A \Ffi\Ptr that crossed a cell (a `Ptr|false|null` union, a mixed array slot) reaches the
// raw-memory builtins as its address: they used to read the object-tagged word (0xFFF8…) and
// peek/poke/cstr_to_str dereferenced it.

#[\Ffi\Library('c'), \Ffi\Symbol('malloc')]
function c_malloc(#[\Ffi\CType('size_t')] int $n): \Ffi\Ptr {}

#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function c_free(\Ffi\Ptr $p): void {}

function maybe(int $n): \Ffi\Ptr|false|null
{
    return $n > 0 ? c_malloc($n) : ($n === 0 ? null : false);
}

$p = maybe(16);
poke_i32($p, 0, 0x64636261);
poke_i8($p, 4, 0);
echo cstr_to_str($p), " ", str_from_buffer($p, 3), " ", peek_i8($p, 1), " ", peek_i32(ptr_offset($p, 0), 0), "\n";
echo ptr_to_int($p) === ptr_to_int(ptr_offset($p, 0)) ? "same" : "differs", " ", ptr_to_int($p) > 0 ? "nonzero" : "zero", "\n";
/** @var array<string, mixed> $slots */
$slots = ["buf" => $p];
echo cstr_to_str($slots["buf"]), "\n";
$q = maybe(0) ?? $p;
echo peek_i8($q, 0), "\n";
var_dump(maybe(0), maybe(-1));
c_free($p);
