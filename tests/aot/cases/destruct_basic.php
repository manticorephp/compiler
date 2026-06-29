<?php
class Logger {
    public function __construct(public string $tag) { echo "open ", $this->tag, "\n"; }
    public function __destruct() { echo "close ", $this->tag, "\n"; }
}
function scope() {
    $a = new Logger("A");
    echo "in scope\n";
}
scope();
echo "after scope\n";
$x = new Logger("X");
unset($x);
echo "after unset\n";
$y = new Logger("Y");
$y = new Logger("Z");
echo "after overwrite\n";
