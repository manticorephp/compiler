<?php
final class Node {
    public function __construct(
        public readonly string $name,
        public readonly ?Node $next,
    ) {}
}
$tail = new Node("b", null);
$head = new Node("a", $tail);
echo $head->name, "->", $head->next->name;
