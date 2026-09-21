<?php
// A superglobal handed to a CLOSURE's by-ref param — a known closure and a
// dynamic `\Closure` callee — is stored by the callee at the PARAM's type; the
// cell's whole-store release must not trust a flavor those writes never made.
$f = function (array &$a): void {
    $a['k'] = 'v' . count($a);
};
function run(\Closure $g): void {
    $g($_GET);
}
function roundTrip(int $i): void {
    global $f;
    $_GET = ['a' => 'x' . $i, 'b' => str_repeat('y', $i + 3)];
    $f($_GET);
    echo json_encode($_GET), "\n";
    run($f);
    echo json_encode($_GET), "\n";
    $_GET['c'] = 'z' . $i;
    echo count($_GET), " ", $_GET['k'], "\n";
    $_GET = [];
}
for ($i = 0; $i < 3; $i++) { roundTrip($i); }
echo json_encode($_GET), "\n";
