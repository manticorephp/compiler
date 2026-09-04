<?php
// @epic: variadic-byref
// @why:  php declares sscanf/fscanf/array_multisort with `&...$vars`; every one
//        of them has to be special-cased at the call site until this works.

// A by-ref VARIADIC parameter compiles, reports the right argument count, and
// writes NOTHING back. It does not refuse, it does not crash — the caller's
// variables simply keep their old values, which is the worst class of answer.
//
// The mechanism is named in the tree already, in the array_multisort desugar
// (LowerExprs): "the caller packs trailing args into one array_lit, so the pack
// is a VALUE and the callee's writes land in a throwaway alloca". Zend
// special-cases array_multisort in the engine for exactly the same reason.
//
// Consequence: every php function declared with `&...$vars` is unimplementable
// as a plain declaration here. sscanf is desugared at the call site instead
// (tests/aot/cases/sscanf_byref_return.php); fscanf is not, because its subject
// is a stream that must be read exactly once and the desugar computes its two
// results with two calls (finding: fscanf-byref-form-absent).

function fill(int $base, mixed &...$vars): int
{
    $k = count($vars);
    for ($i = 0; $i < $k; $i++) {
        $vars[$i] = $base + $i;
    }
    return $k;
}

$a = 0;
$b = 0;
$got = fill(10, $a, $b);

var_dump($got);   // 2 — the ARITY crosses correctly, which is what hides it
var_dump($a);     // php: int(10)   manticore: int(0)
var_dump($b);     // php: int(11)   manticore: int(0)

// A typed by-ref variadic behaves the same way — this is not about erasure.
function bump(int &...$ns): void
{
    foreach ($ns as $i => $_) {
        $ns[$i] = $ns[$i] + 1;
    }
}

$x = 5;
$y = 7;
bump($x, $y);
var_dump($x, $y);   // php: int(6) int(8)   manticore: int(5) int(7)

// The CONTROL: a fixed-arity by-ref parameter writes back correctly, so the
// hole is the pack, not by-ref parameters in general.
function fixed(int &$one, int &$two): void
{
    $one = 100;
    $two = 200;
}

$p = 0;
$q = 0;
fixed($p, $q);
var_dump($p, $q);   // int(100) int(200) — correct today, and must stay correct
