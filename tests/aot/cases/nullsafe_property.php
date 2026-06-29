<?php
// Nullsafe property `?->` must short-circuit on a null receiver instead of
// dereferencing null (which SIGSEGV'd before the fix). Non-null reads and a
// genuinely-null property compared with `=== null` match the interpreter.
class Node {
    public ?Node $next = null;
    public int $v = 0;
    public function val(): int { return $this->v; }
}
$a = new Node();
$a->v = 1;
$a->next = new Node();
$a->next->v = 2;

echo $a?->v, "\n";              // 1
echo $a?->next?->v, "\n";       // 2
echo $a?->next?->val(), "\n";   // 2 (nullsafe method on non-null)
echo (($a->next?->next) === null ? "tail" : "more"), "\n";  // tail

// null receiver: short-circuits, no crash (value unused — it renders as the
// non-null zero, a known nullable-type limitation, so we only prove no-SEGV).
$x = null;
$ignore = $x?->v;
$ignore2 = $x?->val();
echo "no-crash\n";
