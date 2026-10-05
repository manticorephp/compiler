<?php
final class K { public function __construct(public string $t) {} public function __destruct() { echo "~K {$this->t}\n"; } }
final class R { /** @var array<int, mixed> */ public static array $f = []; /** @var array<int, mixed> */ public array $p = []; }
/** @var array<int, mixed> $l */
$l = []; $l[1] = new K('a'); $l = null; echo "-0\n";
/** @var array<int, mixed> $m */
$m = []; $k = new K('b'); $m[1] = $k; unset($k); unset($m[1]); echo "-1\n";
/** @var array<int, mixed> $n */
$n = [1 => new K('c')]; unset($n[1]); echo "-2\n";
$o = []; $o[1] = new K('d'); unset($o[1]); echo "-3\n";
R::$f[1] = new K('e'); unset(R::$f[1]); echo "-4\n";
$r = new R(); $r->p[1] = new K('f'); unset($r->p[1]); echo "-5\n";
$r->p[2] = new K('g'); $r->p[2] = 1; echo "-6\n";
/** @var array<int, mixed> $l2 */
$l2 = []; $l2[1] = new K('h'); $l2[1] = 2; echo "-7\n";
R::$f[1] = new K('i'); R::$f[1] = 'z'; echo "-8\n";
R::$f[2] = new K('j'); R::$f = []; echo "-9\n";
$r->p[1] = new K('k'); $r->p = []; echo "-10\n";
$r->p[1] = new K('l'); $r = null; echo "-11\n";
function cell_elem_scope(): void { /** @var array<int, mixed> $a */ $a = []; $a[1] = new K('m'); $a[2] = 'x'; }
cell_elem_scope(); echo "-12\n";
