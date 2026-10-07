<?php
// A local that rides a CELL (two kinds share it), a global and a superglobal,
// handed to a KNOWN closure's typed by-ref param: the closure gets a scratch of
// the decoded payload and its write comes back re-boxed.
function frameLocal(): void {
    $f = function (array &$a): void { $a['k'] = 'v' . count($a); };
    $g = null;
    $g = ['a' => 'x'];
    if (rand(0, 0) === 1) { $g = 5; }
    $f($g);
    echo json_encode($g), "\n";
}
function viaGlobal(): void {
    global $h;
    $f = function (array &$a): void { $a['k'] = 'w'; };
    $h = ['a' => 'y'];
    $f($h);
    echo json_encode($h), "\n";
}
$h = null;
frameLocal();
viaGlobal();
$f = function (array &$a): void { $a['q'] = 'n' . count($a); };
$_GET = ['z' => 'one'];
$f($_GET);
echo json_encode($_GET), "\n";
function viaDynamic(\Closure $d): void {
    global $h;
    $h = ['a' => 'd'];
    $d($h);
    echo json_encode($h), "\n";
    $d($_GET);
    echo json_encode($_GET), "\n";
}
viaDynamic($f);
