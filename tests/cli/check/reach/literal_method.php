<?php
class X {
    public function a(): void { echo "a\n"; }
    public function b(): void { echo "b\n"; }
    public function c(): void { echo "c\n"; }
    public static function sa(): void { echo "sa\n"; }
    public static function sc(): void { echo "sc\n"; }
}
function viaLocal(X $o, bool $flag): void {
    if ($flag) { $m = 'a'; } else { $m = 'b'; }
    $o->$m();
}
function viaClassAndName(bool $flag): void {
    if ($flag) { $cls = X::class; } else { $cls = X::class; }
    if ($flag) { $n = 'sa'; } else { $n = 'sa'; }
    $cls::$n();
}
function viaConcat(bool $flag): void {
    if ($flag) { $suffix = 'a'; } else { $suffix = 'a'; }
    $callable = 'X::s' . $suffix;
    $callable();
}
viaLocal(new X, true);
viaClassAndName(true);
viaConcat(true);
