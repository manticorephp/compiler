<?php
final class P { public function on(string $e, string $n): void { echo "on $e $n\n"; } }
function add(callable $l): void { echo is_array($l) ? "A" : "-", "\n"; $l('x', 'y'); }
$p = new P();
add([$p, 'on']);
