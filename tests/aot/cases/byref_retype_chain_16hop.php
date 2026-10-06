<?php
// A by-ref array param whose element reaches a by-ref sink of another kind
// becomes a cell-element array, and every caller up the chain follows. The
// chain is declared callee-LAST, so one scan round moves one hop; it is longer
// than four rounds times the pipeline's InferTypes runs. The widening is a
// true fixpoint now; it used to stop after four rounds per run and leave the
// outer callers' arrays raw.
/** @param int[] $p */ function h16(array &$p): void { h15($p); }
/** @param int[] $p */ function h15(array &$p): void { h14($p); }
/** @param int[] $p */ function h14(array &$p): void { h13($p); }
/** @param int[] $p */ function h13(array &$p): void { h12($p); }
/** @param int[] $p */ function h12(array &$p): void { h11($p); }
/** @param int[] $p */ function h11(array &$p): void { h10($p); }
/** @param int[] $p */ function h10(array &$p): void { h9($p); }
/** @param int[] $p */ function h9(array &$p): void { h8($p); }
/** @param int[] $p */ function h8(array &$p): void { h7($p); }
/** @param int[] $p */ function h7(array &$p): void { h6($p); }
/** @param int[] $p */ function h6(array &$p): void { h5($p); }
/** @param int[] $p */ function h5(array &$p): void { h4($p); }
/** @param int[] $p */ function h4(array &$p): void { h3($p); }
/** @param int[] $p */ function h3(array &$p): void { h2($p); }
/** @param int[] $p */ function h2(array &$p): void { h1($p); }
/** @param int[] $p */ function h1(array &$p): void { app($p[1]); }
function app(string &$x): void { $x .= 'a'; }
$q = [3, 4];
h16($q);
var_dump($q);
echo $q[1] . '|' . ($q[0] + 1), "\n";
$r = [5, 6];
h9($r);
var_dump($r);
