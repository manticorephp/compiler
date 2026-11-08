<?php
final class U {
    private ?string $f;
    function __construct(?string $f) { $this->f = $f; }
    function with(?string $x): string {
        $x = $x === null ? null : strtoupper($x);
        return match ($x) { $this->f => 'same', default => 'diff' };
    }
}
echo (new U(null))->with(null), (new U('A'))->with('a'), (new U('A'))->with(null), (new U(null))->with('a'), "\n";
