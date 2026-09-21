<?php
// A superglobal handed to a CONSTRUCTOR's by-ref param (`new C($_GET)`): the
// ctor writes back at its own param type, so the cell's release contract has
// to be judged at that site exactly as at a call.
class C {
    /** @var array<string,string> */
    public array $copy;
    public function __construct(array &$a) {
        $a['ctor'] = 'c' . count($a);
        $this->copy = $a;
    }
}
function step(int $i): void {
    $_POST = ['p' => 'q' . $i, 'r' => str_repeat('s', $i + 2)];
    $c = new C($_POST);
    echo json_encode($_POST), " ", json_encode($c->copy), "\n";
    $_POST['t'] = 'u' . $i;
    echo count($_POST), " ", count($c->copy), "\n";
    $_POST = ['fresh' => (string)$i];
    echo json_encode($_POST), " ", $c->copy['ctor'], "\n";
}
for ($i = 0; $i < 3; $i++) { step($i); }
$_POST = [];
echo json_encode($_POST), "\n";
