<?php
// A bare-`array` param's element type is recovered from the call sites: a helper
// called only with string arrays sees string foreach values (echo / string ops
// render correctly instead of as a raw pointer).
function shout(array $words): string {
    $out = "";
    foreach ($words as $w) {
        $out .= strtoupper($w) . " ";
    }
    return rtrim($out);
}
echo shout(["foo", "bar", "baz"]), "\n";

function lengths(array $items): string {
    $parts = [];
    foreach ($items as $s) {
        $parts[] = strlen($s);
    }
    return implode(",", $parts);
}
echo lengths(["a", "bb", "ccc"]), "\n";

function firstChars(array $list): string {
    $r = "";
    foreach ($list as $v) {
        $r .= $v[0];
    }
    return $r;
}
echo firstChars(["apple", "banana", "cherry"]), "\n";
