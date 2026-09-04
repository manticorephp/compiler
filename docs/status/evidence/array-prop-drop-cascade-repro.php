<?php
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
