<?php
// variadic_pack — a variadic call packs its trailing arguments into ONE array
// literal, so `array_merge($a, $b)` hands the callee a vec[vec[…]]. The literal
// OWNS those elements and its own release drops none of them: an ARRAY element
// has no release flavor. Before `79cd5db` / `2e1b880` the WHOLE of every
// argument rode out of every such call — 62.6 MB in the ownership table, and
// the first attempt at the fix freed the callee's result instead.
//
// Only the OWNED shape is here, and it is meant to stay FLAT in the LEAK table:
// an element that is a fresh producer transferred its +1, so the literal is its
// sole owner and frees it completely. The BORROWED half is still open and lives
// in tools/prof/packleak.php, out of the corpus, so this table stays at 0.
//
// Deterministic output for the parity check; the timing goes to STDERR.

/** @return string[] */
function vp_pieces(string $s, int $i): array
{
    return explode(",", $s . $i);
}

/** @return int[] */
function vp_nums(int $i): array
{
    return [$i, $i + 1, $i + 2];
}

$n = 60000 * $argc;
$sum = 0;
$t0 = microtime(true);
for ($i = 0; $i < $n; $i++) {
    // Two fresh call results and a literal, three-way — the shape that leaked.
    $m = array_merge(vp_pieces('a,b,c', $i), vp_pieces('d,e', $i), ['z']);
    $sum += count($m) + strlen($m[0]);
    // A read-only pack: array_diff never takes a value OUT of the pack, so its
    // elements are the buffer-only case even though it is the same syntax.
    $d = array_diff(vp_pieces('a,b,c', $i), ['b' . $i]);
    $sum += count($d);
    $x = array_intersect(vp_pieces('a,b,c', $i), ['a' . $i, 'c' . $i]);
    $sum += count($x);
    // Int elements: a pack whose element arrays have nothing to drop.
    $k = array_merge(vp_nums($i), vp_nums($i + 1));
    $sum += $k[0] + count($k);
}
$ms = (microtime(true) - $t0) * 1000.0;
echo $sum, "\n";
fprintf(STDERR, "variadic_pack %.1f ms\n", $ms);
