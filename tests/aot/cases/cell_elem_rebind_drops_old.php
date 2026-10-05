<?php
final class K { public function __construct(public string $t) {} public function __destruct() { echo "~K {$this->t}\n"; } }
function mk(): mixed { return [new K('a'), new K('b')]; }
function rb(): void {
    $v = mk();
    $x = 5;
    $v[0] = &$x;
    echo "rebound\n";
    $y = 6;
    $v[0] = &$y;
    $x = 7;
    echo $v[0], "\n";
    $v[1] = &$y;
    echo "rebound2\n";
}
rb();
echo "end\n";
