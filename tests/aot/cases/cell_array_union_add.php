<?php
// `+` of two mixed (cell) operands holding arrays is the union, not arithmetic.
$_ENV['MC_UNION_X'] = 'bar';
$_SERVER += $_ENV;
echo $_SERVER['MC_UNION_X'], "\n";
function un(mixed $a, mixed $b): mixed { return $a + $b; }
print_r(un(['a' => 1], ['a' => 2, 'b' => 3]));
echo un(2, 3), " ", un(1.5, 1), " ", un("4", 1), "\n";
$m = [[1, 2], [5, 6, 7]];
$acc = []; foreach ($m as $row) { $acc = $acc + $row; } print_r($acc);
try { un([1], 2); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { un(2, [1]); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { un("x", 1); } catch (TypeError $e) { echo get_class($e), "\n"; }
