<?php
// @why: php's `??` takes a full expression on its right, and assignment IS an
//       expression, so the memoise idiom parses as `$d = ($s ?? ($s = …))`.
//       Recursing into the coalesce parser instead stopped below `=`, left the
//       `=` for the caller, and made the whole coalesce an assignment TARGET
//       ("unsupported assign target kind NullCoalesce"). That is what blocked
//       tier 1 of the audit ladder, via polyfill-intl-icu's
//       `self::$data ?? self::$data = require …`.
$store = null;
$d = $store ?? $store = 'memoised';
echo $d, "|", $store, "\n";

// A second read must NOT re-run the right side.
$hits = 0;
function capBump(int &$n): string { $n = $n + 1; return 'made'; }
$cache = null;
$a = $cache ?? $cache = capBump($hits);
$b = $cache ?? $cache = capBump($hits);
echo $a, "|", $b, "|", $hits, "\n";

// `and` binds LOOSER than `??`, so this must stay ($x ?? $y) and false.
$x = null; $y = 'B';
var_dump($x ?? $y and false);

// Plain forms are unchanged.
$p = null;
echo ($p ?? 'dflt'), "\n";
