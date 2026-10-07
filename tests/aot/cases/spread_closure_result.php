<?php
final class K { public function __construct(public string $t) {} public function __destruct() { echo "~K {$this->t}\n"; } }
$c = function (): array { return [new K('a'), new K('b')]; };
$x = [...$c()];
echo count($x), " ", $x[1]->t, "\n";
$x = null;
echo "end\n";
