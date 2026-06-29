<?php
class Util {
    public function up(string $s): string { return strtoupper($s); }
    public static function neg(int $x): int { return -$x; }
}
$o = new Util();
// variable-held callable literals invoked directly (const-prop)
$g = "strtoupper";        echo $g("hi"), "\n";          // HI
$gs = "Util::neg";        echo $gs(5), "\n";            // -5
$ga = ["Util", "neg"];    echo $ga(7), "\n";            // -7
$gm = [$o, "up"];         echo $gm("ab"), "\n";         // AB
// call_user_func family
echo call_user_func("strtoupper", "yo"), "\n";          // YO
echo call_user_func(["Util", "neg"], 9), "\n";          // -9
echo call_user_func([$o, "up"], "cd"), "\n";            // CD
function add($a, $b) { return $a + $b; }
echo call_user_func_array("add", [3, 4]), "\n";         // 7
// string / array callable passed to stdlib callable params
echo implode(",", array_map("strtoupper", ["a", "b"])), "\n";   // A,B
echo implode(",", array_map(["Util", "neg"], [1, 2])), "\n";    // -1,-2
$nums = [3, 1, 2];
usort($nums, "cmp_nums");
echo implode(",", $nums), "\n";                         // 1,2,3
function cmp_nums($a, $b) { return $a - $b; }
