<?php
// An obj's string property is released when the object is dropped
// (recursive drop walker). Loop stays flat; asserts correctness.
class Tag {
    public string $name;
    function __construct(string $name) { $this->name = $name; }
}
function tag(int $n): Tag { return new Tag("tag-" . $n); }
$total = 0;
$i = 0;
while ($i < 200) {
    $t = tag($i);                 // RcHeap Tag, dropped at scope exit
    $total = $total + strlen($t->name);
    $i = $i + 1;
}
echo $total, "\n";
