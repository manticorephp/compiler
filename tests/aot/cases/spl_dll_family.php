<?php
function t(callable $f): void
{
    try { echo json_encode($f()), "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

$q = new SplQueue();
$s = new SplStack();
$d = new SplDoublyLinkedList();
t(fn () => [$q->getIteratorMode(), $s->getIteratorMode(), $d->getIteratorMode()]);
t(fn () => $q->setIteratorMode(SplDoublyLinkedList::IT_MODE_LIFO));
t(fn () => $s->setIteratorMode(SplDoublyLinkedList::IT_MODE_FIFO));
t(fn () => $s->setIteratorMode(3));
t(fn () => $s->setIteratorMode(2));
t(fn () => $d->setIteratorMode(7));
t(fn () => $d->setIteratorMode(0));
foreach ([1, 2, 3] as $v) { $q[] = $v; $s[] = $v; $d->push($v); }
var_dump($q);
print_r($s);
echo serialize($q), "\n", serialize($s), "\n";
t(fn () => [$s[0], $s[2], $q[0], $q['1'], $q[true]]);
t(fn () => $q['x']);
t(fn () => isset($q['x']));
t(fn () => [isset($q[5]), isset($q[2]), isset($q[-1])]);
t(fn () => $q[3]);
t(fn () => $q[9] = 1);
t(fn () => $q->offsetUnset(9));
$s->add(1, 'n');
$q->add(1, 'n');
$q->add(4, 'e');
t(fn () => $q->add(9, 1));
echo serialize($s), "\n", serialize($q), "\n";
unset($s[0], $q[0]);
$d->unshift(0);
$d[1] = 'one';
echo serialize($s), "\n", serialize($q), "\n", serialize($d), "\n";
t(fn () => [$d->top(), $d->bottom(), $d->pop(), $d->shift(), count($d), $d->isEmpty()]);
t(fn () => (new SplQueue())->pop());
t(fn () => (new SplQueue())->dequeue());
t(fn () => (new SplStack())->top());
t(fn () => (new SplDoublyLinkedList())->bottom());

$u = unserialize(serialize($s));
t(fn () => [get_class($u), $u->getIteratorMode(), count($u), $u->top()]);

t(fn () => [$q->current(), $q->key(), $q->valid()]);
$q->rewind();
t(fn () => [$q->current(), $q->key(), $q->valid()]);
$q->next();
$q->prev();
$q->prev();
t(fn () => [$q->current(), $q->key(), $q->valid()]);
foreach ($s as $k => $v) { echo "$k=$v "; }
echo "| ", count($s), "\n";

// DELETE mode drains while iterating
$drain = new SplDoublyLinkedList();
foreach (['a', 'b', 'c'] as $v) { $drain->push($v); }
$drain->setIteratorMode(SplDoublyLinkedList::IT_MODE_FIFO | SplDoublyLinkedList::IT_MODE_DELETE);
foreach ($drain as $k => $v) { echo "$k=$v "; }
echo "| ", count($drain), "\n";
$st = new SplStack();
foreach (['a', 'b', 'c'] as $v) { $st->push($v); }
$st->setIteratorMode(SplDoublyLinkedList::IT_MODE_LIFO | SplDoublyLinkedList::IT_MODE_DELETE);
foreach ($st as $k => $v) { echo "$k=$v "; }
echo "| ", count($st), "\n";

// a long queue: every dequeue is O(1)
$big = new SplQueue();
$sum = 0;
for ($i = 0; $i < 200000; $i++) { $big->enqueue($i); }
for ($i = 0; $i < 150000; $i++) { $sum += $big->dequeue(); }
for ($i = 0; $i < 10; $i++) { $big->enqueue(-$i); }
$big->unshift('front');
echo count($big), ' ', $sum, ' ', $big->bottom(), ' ', $big[1], ' ', $big->top(), "\n";
print_r(new SplDoublyLinkedList());
