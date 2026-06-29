<?php
// match() over a boxed-cell subject (an untyped param) must compare by tag, not
// the raw NaN-boxed bits — before the fix every arm fell through to default.
// Multiple values per arm + strict === (int 1 is not string "1").
function classify($x): string {
    return match ($x) {
        1, 2 => "low",
        3 => "mid",
        "a", "b" => "alpha",
        default => "other",
    };
}
echo classify(1), "\n";
echo classify(2), "\n";
echo classify(3), "\n";
echo classify("a"), "\n";
echo classify("b"), "\n";
echo classify(9), "\n";
echo classify("z"), "\n";

// strict: int 1 and string "1" land on different arms
function strict($x): string {
    return match ($x) { 1 => "int1", "1" => "str1", default => "no" };
}
echo strict(1), " ", strict("1"), "\n";
