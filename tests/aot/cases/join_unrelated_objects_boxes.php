<?php
final class P { public function name(): string { return 'p'; } }
final class Q { public function name(): string { return 'q'; } }
function pick(int $i): string {
    $x = new P();
    if ($i > 0) { $x = new Q(); }
    return $x->name() . ($x instanceof Q ? '+' : '-') . get_class($x);
}
echo pick(0), ' ', pick(1), "\n";
