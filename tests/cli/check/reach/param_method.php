<?php
class X {
    public function a(): void { echo "a\n"; }
    public function b(): void { echo "b\n"; }
}
function viaParam(X $o, string $m): void {
    if (strlen($m) > 3) { $m = 'a'; }
    $o->$m();
}
viaParam(new X, 'b');
