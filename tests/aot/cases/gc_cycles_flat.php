<?php
// Cycles are created continuously; the adaptive auto-collector must keep memory flat.
class Link { public $peer; public $pad = 'x'; }
function churn(int $n): void {
    for ($i = 0; $i < $n; $i++) {
        $a = new Link; $b = new Link;
        $a->peer = $b; $b->peer = $a;
    }
}
if ($argc > 99) { gc_collect_cycles(); } // links the collector; auto-collection needs it
churn(2000);
$before = memory_get_usage();
churn(400000);
$after = memory_get_usage();
echo ($after - $before) < 8 * 1024 * 1024 ? "flat\n" : "grew\n";
