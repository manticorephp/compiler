<?php
function f($s) {
    $result = $tail = '';
    $i = 1; $len = strlen($s);
    $last = substr($s, 0, $i);
    while ($i < $len) {
        if ($s[$i] < "\x80") {
            if ($tail) { $last .= $tail; $tail = ''; }
            $result .= $last;
            $last = $s[$i];
            ++$i;
            continue;
        }
        $u = substr($s, $i, 3);
        $result .= $last;
        $last = $u;
        $i += 3;
    }
    return $result . $last . $tail;
}
foreach (["x\u{2593}\u{2591}", "ab", "x\u{2593}y\u{2591}z"] as $s) { echo bin2hex(f($s)), "\n"; }
