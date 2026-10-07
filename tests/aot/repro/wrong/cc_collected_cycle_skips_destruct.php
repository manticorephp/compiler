<?php
// A cycle collected by gc_collect_cycles() never runs __destruct on its objects (php runs it during the collection).
final class Node { public ?Node $next = null; public function __construct(public string $name) {} public function __destruct() { echo "destruct {$this->name}\n"; } }
function make(): void { $a = new Node('a'); $b = new Node('b'); $a->next = $b; $b->next = $a; }
make();
echo "before gc\n";
echo gc_collect_cycles() > 0 ? "collected\n" : "nothing\n";
echo "after gc\n";
