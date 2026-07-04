<?php
// `??` suppresses a null-deref in a property CHAIN: a null intermediate receiver
// yields the default instead of faulting; a present-but-null leaf too.
class Node {
    public ?Node $next = null;
    public int $v = 0;
    public ?int $opt = null;
    public string $label = "";
    public function __construct(int $v = 0, string $label = "") {
        $this->v = $v; $this->label = $label;
    }
}
$a = new Node(1, "a");
echo ($a->next->v ?? -1), "\n";               // -1 (next null)
echo ($a->next->label ?? "none"), "\n";       // none
echo ($a->next->next->v ?? -9), "\n";         // -9 (deep, next null)
var_dump($a->next->v ?? "def");               // string "def"

$a->next = new Node(2, "b");
echo ($a->next->v ?? -1), "\n";               // 2
echo ($a->next->label ?? "none"), "\n";       // b
echo ($a->next->next->v ?? -9), "\n";         // -9 (next->next null)
echo ($a->next->opt ?? -1), "\n";             // -1 (present leaf is null)
$a->next->opt = 8;
echo ($a->next->opt ?? -1), "\n";             // 8

$a->next->next = new Node(3, "c");
echo ($a->next->next->v ?? -9), "\n";         // 3
echo ($a->next->next->label ?? "none"), "\n"; // c
