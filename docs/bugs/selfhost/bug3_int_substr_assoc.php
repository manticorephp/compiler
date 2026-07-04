<?php
// BUG: an int value mixed with substr-derived string values in the SAME assoc
// literal (built in a loop) scrambles the values (or SIGSEGVs). A homogeneous
// all-string assoc compiles clean; adding one int key corrupts it.
// Expected (php): "W->run @4".  Actual (mc): "go->run @4" (class from next frame)
//                 or SIGSEGV / empty output.
function frames(array $names, array $lines): array {
    $out = []; $i = 0;
    while ($i < count($names)) {
        $name = $names[$i]; $ln = $lines[$i]; $type = "";
        $p = strpos($name, "->");
        if ($p !== false) { $type = "->"; }
        if ($type !== "") {
            $cls = substr($name, 0, $p); $fn = substr($name, $p + 2);
            $out[] = ["line" => $ln, "function" => $fn, "class" => $cls];  // int + 2 substr strings
        } else { $out[] = ["line" => $ln, "function" => $name]; }
        $i = $i + 1;
    }
    return $out;
}
foreach (frames(["inner","W->run","X->go"], [2,4,5]) as $f) {
    $c = isset($f['class']) ? $f['class']."->" : "";
    echo $c, $f['function'], " @", $f['line'], "\n";
}
