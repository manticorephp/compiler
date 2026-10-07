<?php
function attempt(callable $f): void
{
    try { $f(); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

$min = new SplMinHeap();
foreach ([5, 3, 8, 1, 9, 3, 7] as $v) { var_dump($min->insert($v)); }
echo count($min), ' ', $min->top(), ' ', $min->key(), "\n";
var_dump($min);
foreach ($min as $k => $v) { echo "$k=$v "; }
echo "| ", count($min), "\n";
var_dump($min->isEmpty(), $min->valid(), $min->current());
attempt(function () use ($min) { $min->extract(); });
attempt(function () use ($min) { $min->top(); });

$max = new SplMaxHeap();
foreach (['pear', 'apple', 'fig', 'kiwi'] as $v) { $max->insert($v); }
echo $max->extract(), ' ', $max->extract(), ' ', count($max), "\n";
$copy = unserialize(serialize($max));
echo serialize($max), "\n", get_class($copy), ' ', $copy->extract(), ' ', count($max), "\n";

final class ByLen extends SplHeap
{
    protected function compare(mixed $value1, mixed $value2): int { return strlen($value1) <=> strlen($value2); }
}
$h = new ByLen();
foreach (['aa', 'b', 'cccc', 'dd', 'e', 'ffff'] as $v) { $h->insert($v); }
$out = [];
while (!$h->isEmpty()) { $out[] = $h->extract(); }
echo implode(',', $out), "\n";

final class Flaky extends SplMinHeap
{
    public bool $boom = false;
    protected function compare(mixed $value1, mixed $value2): int
    {
        if ($this->boom) { throw new LogicException('no order'); }
        return parent::compare($value1, $value2);
    }
}
$f = new Flaky();
$f->insert(2);
$f->insert(1);
$f->boom = true;
attempt(function () use ($f) { $f->insert(0); });
var_dump($f->isCorrupted(), count($f));
attempt(function () use ($f) { $f->top(); });
attempt(function () use ($f) { $f->insert(4); });
attempt(function () use ($f) { $f->next(); });
$f->boom = false;
var_dump($f->recoverFromCorruption(), $f->isCorrupted(), $f->top());

$q = new SplPriorityQueue();
$q->insert('low', 1);
$q->insert('high', 10);
$q->insert('mid', 5);
$q->insert('mid2', 5);
$q->insert('pair', [5, 1]);
echo count($q), ' ', $q->top(), ' ', $q->getExtractFlags(), "\n";
var_dump($q->setExtractFlags(SplPriorityQueue::EXTR_BOTH));
var_dump($q->extract());
$q->setExtractFlags(SplPriorityQueue::EXTR_PRIORITY);
var_dump($q->top());
$q->setExtractFlags(SplPriorityQueue::EXTR_DATA);
attempt(function () use ($q) { $q->setExtractFlags(0); });
print_r($q);
echo serialize($q), "\n";
foreach ($q as $k => $v) { echo "$k=$v "; }
echo "| ", count($q), "\n";
attempt(function () use ($q) { $q->extract(); });

final class Reversed extends SplPriorityQueue
{
    public function compare(mixed $priority1, mixed $priority2): int { return $priority2 <=> $priority1; }
}
$r = new Reversed();
foreach ([3 => 'c', 1 => 'a', 2 => 'b', 0 => 'z'] as $p => $d) { $r->insert($d, $p); }
$out = [];
while ($r->valid()) { $out[] = $r->extract(); }
echo implode('', $out), "\n";

// a larger run against a sorted copy
$big = new SplMinHeap();
$ref = [];
$x = 12345;
for ($i = 0; $i < 2000; $i++) { $x = ($x * 1103515245 + 12345) & 0x7fffffff; $big->insert($x % 1000); $ref[] = $x % 1000; }
sort($ref);
$ok = true;
foreach ($ref as $v) { if ($big->extract() !== $v) { $ok = false; } }
var_dump($ok, count($big));
