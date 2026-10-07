<?php
// A local that holds an ARRAY on one path and an OBJECT (or a string) on
// another took two raw release flavors, and the ownership plan blocked it:
// every value it ever held leaked (php-cs-fixer: 2.4 GB). Its flag now names
// which raw flavor a store left.
final class Box {
    public array $items = [];
    public function __construct(public string $tag) {}
    public function __destruct() { echo "dtor ", $this->tag, "\n"; }
}
function mk(string $tag): Box { $b = new Box($tag); $b->items = [new Box($tag . '.in')]; return $b; }
function one(string $mode): int {
    if ($mode === 'a') { $t = [mk('a1'), mk('a2')]; }
    elseif ($mode === 'o') { $t = mk('o'); }
    elseif ($mode === 's') { $t = str_repeat('s', 3); }
    elseif ($mode === 'r') { $t = mk('r'); return 1; }
    elseif ($mode === 'w') { $t = mk('w1'); $t = [mk('w2')]; $t = mk('w3'); }
    else { $t = null; }
    echo "end ", $mode, "\n";
    return 0;
}
foreach (['o', 'a', 's', 'r', 'w', 'n'] as $m) { one($m); echo "after ", $m, "\n"; }
