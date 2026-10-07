<?php
final class Tk {
    public function __construct(public string $c) {}
    public function equalsAny(array $others): bool { echo \count($others), ' '; return false; }
    public function same(Tk $o): bool { echo $o->c, ' '; return false; }
}
function mk(): mixed { return new Tk('a'); }
function q(array $tokens): void {
    $f = fn (): bool => mk()->equalsAny($tokens);
    $f(); $f(); $f();
}
q(['x', 'b', ...[[1]]]);
echo "\n";
function r(Tk $t): void {
    $f = fn (): bool => mk()->same($t);
    $f(); $f(); $f();
}
r(new Tk('z'));
echo "\n";
