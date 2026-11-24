<?php
// String `==` and `<=>` and is_numeric() decide "numeric" by the leading bytes:
// whitespace, a sign, then a digit or `.`. Anything else is compared as bytes.
$pairs = [
    [" 1", "1"], ["1 ", "1"], ["\n\t1", "1.0"], ["1e3", "1000"], [".5", "0.5"],
    ["+.5", "0.5"], ["-.5", "-0.50"], [" .5", "0.5"], ["+1", "1"], ["-0", "0"],
    ["abc", "ABC"], ["abc", "0"], ["inf", "INF"], ["nan", "NAN"], ["0x1A", "26"],
    ["x1", "1"], ["", "0"], [" ", "0"], ["+", "0"], ["-", "0"], [".", "0"],
    ["1a", "1"], ["e1", "e1"], ["_1", "1"], ["\v1", "1"], ["\f1", "1"],
    ["1.", "1"], ["-.e1", "0"], ["00", "0"], ["10", "1e1"],
];
foreach ($pairs as [$a, $b]) {
    echo json_encode($a), " ", json_encode($b), " ",
        var_export($a == $b, true), " ", $a <=> $b, " ",
        var_export(is_numeric($a), true), " ", (float)$a, "\n";
}
