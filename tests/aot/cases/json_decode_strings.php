<?php
// json_decode string scanning: parseString takes unescaped RUNS with strcspn +
// one substr, so the escape-free path, the escape path, and the boundary
// between them all have to agree with the byte-at-a-time semantics they replace.
$cases = [
    '{"a":1,"b":"plain"}',
    '{"esc":"line\nbreak\ttab\r\\\\slash\"quote\/fwd"}',
    '{"lead":"\nstarts with an escape"}',
    '{"trail":"ends with an escape\n"}',
    '{"mix":"start\nmiddle plain run here\tend"}',
    '{"only":"\\\\"}',
    '{"empty":"","one":"x"}',
    '{"u":"café ❤ A"}',
    '{"uu":"ABC"}',
    '{"ctrl":"\b\f"}',
    '{"nest":{"deep":["a","b\\\\c",""]}}',
];
foreach ($cases as $j) {
    $v = json_decode($j, true);
    foreach ($v as $k => $val) {
        if (is_array($val)) {
            echo $k, " => [", implode("|", $val["deep"]), "]\n";
        } else {
            echo $k, " => ", $val, " (len ", strlen((string)$val), ")\n";
        }
    }
}

// Numbers: an int accumulates without a substr; a float still goes through one.
$n = json_decode('[0,1,-1,42,-42,3.14,-3.14,1e5,1.5e-3,1E+2,7]');
foreach ($n as $x) { var_dump($x); }
