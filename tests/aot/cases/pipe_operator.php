<?php
$double = fn($x) => $x * 2;
$inc = fn($x) => $x + 1;
class N { public function dbl(int $x): int { return $x*2; } public static function neg(int $x): int { return -$x; } }
$o = new N();
echo (5 |> $double), "\n";              // 10
echo (5 |> $double |> $inc), "\n";      // 11
echo ("hi" |> strtoupper(...)), "\n";   // HI
echo ([1,2,3] |> array_sum(...)), "\n"; // 6
echo ([1,2,3] |> count(...)), "\n";     // 3
echo (10 |> $o->dbl(...)), "\n";        // 20
echo (10 |> N::neg(...)), "\n";         // -10
echo ("hey" |> "strtoupper"), "\n";     // HEY
echo (8 |> [$o,"dbl"]), "\n";           // 16
echo (9 |> ["N","neg"]), "\n";          // -9
function sq($n) { return $n * $n; }
echo (4 |> sq(...) |> $inc), "\n";      // 17
echo (2 + 3 |> $inc), "\n";             // 6  (+ tighter than |>)
echo (true ? 7 : 8 |> $inc), "\n";      // 7  (|> higher than ?:)
$n = null;
echo ($n ?? 5 |> $inc), "\n";           // 6  (|> lower than ??)
