<?php
// An OMITTED `?array &$out = null` argument is backed by a throwaway slot the
// callee writes an owned array into; the post-call drop named no flavor for
// the erased `?array` type, so every such call leaked what the callee wrote —
// php-cs-fixer's `Preg::match($re, $s)` left each matches array behind.
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
function fill(string $n, ?array &$out = null): bool { $out = [new Tok($n)]; return true; }
final class Wrap {
    public static function fill(string $n, ?array &$out = null): bool { $out = [new Tok($n)]; return true; }
    public function fillM(string $n, ?array &$out = null): bool { $out = [new Tok($n)]; return true; }
}
echo fill('f') ? "yes\n" : "no\n";
echo "after function\n";
echo Wrap::fill('s') ? "yes\n" : "no\n";
echo "after static\n";
echo (new Wrap())->fillM('m') ? "yes\n" : "no\n";
echo "after method\n";
// …and a named out-variable owns what the callee left in it.
function named(): void { fill('kept', $kept); echo \count($kept), "\n"; }
named();
echo "end\n";
