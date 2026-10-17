<?php
function lt(string $a, string $b): string { return ($a < $b ? 'T' : 'F') . ($a <= $b ? 'T' : 'F') . ($a > $b ? 'T' : 'F') . ($a >= $b ? 'T' : 'F') . ($a <=> $b); }
foreach ([["10", "9"], ["9", "10"], ["1e1", "10"], ["abc", "abd"], ["10", "abc"], ["abc", "10"], [" 10", "9"], ["10 ", "9"], ["", "0"], ["0", ""], ["-5", "3"], ["1.5", "1.25"], ["0x10", "16"], ["a", "B"]] as [$x, $y]) {
    echo json_encode($x), json_encode($y), ' ', lt($x, $y), "\n";
}
$l = ["10", "9", "2", "1e1", "abc", "ABC", "", "-1", "1.5"];
sort($l); echo json_encode($l), "\n";
rsort($l); echo json_encode($l), "\n";
$s = ["b" => "10", "a" => "9", "c" => "x", "d" => "1e1"];
asort($s); echo json_encode($s), "\n";
arsort($s); echo json_encode($s), "\n";
$w = ["pear", "apple", "fig", "banana"]; sort($w); echo json_encode($w), "\n";
