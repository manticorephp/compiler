<?php
// array_splice of strings into an `array $a` param that call sites pass ints throws TypeError
// issue: #307
function t(array $a, int $off, ?int $len = null, mixed $repl = []): void {
    $r = array_splice($a, $off, $len, $repl);
    echo json_encode([$a, $r]), "\n";
}
t([10, 20, 30], 1, 1, ["x"]);
