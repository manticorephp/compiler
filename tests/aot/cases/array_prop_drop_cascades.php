<?php
// Overwriting a property that holds a tree frees the WHOLE tree, as php does.
// The array property retains its elements on the store, so EVERY release of
// that buffer owes one back; a buffer-only release leaves one ref per element
// and the cascade stops at the first level (1 freed where php frees 149).
class C { public static int $f = 0; }
abstract class N { public function __destruct() { C::$f = C::$f + 1; } }
final class Leaf extends N { public int $v = 0; }
final class Pair extends N {
    public function __construct(public ?N $a = null, public ?N $b = null) {}
}
final class Blk extends N {
    /** @var N[] */
    public array $stmts = [];
    /** @param N[] $s */
    public function __construct(array $s = []) { $this->stmts = $s; }
}
final class Fn_ {
    public ?Blk $body = null;
}
$f = new Fn_();
$stmts = [];
for ($i = 0; $i < 50; $i++) {
    $l1 = new Leaf(); $l1->v = $i;
    $l2 = new Leaf(); $l2->v = $i + 1;
    $stmts[] = new Pair($l1, $l2);
}
$f->body = new Blk($stmts);
unset($stmts);
echo "built, freed so far=", C::$f, "\n";
$f->body = new Blk([]);
echo "after body swap freed=", C::$f, " (expect 151)\n";
