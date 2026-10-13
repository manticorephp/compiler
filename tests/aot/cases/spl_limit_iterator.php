<?php
function show(Iterator $it): void {
    foreach ($it as $k => $v) { echo "$k=>$v "; }
    echo "\n";
}
$a = new ArrayIterator(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5]);
show(new LimitIterator($a, 1, 3));
show(new LimitIterator($a, 2));
show(new LimitIterator($a, 0, 2));
show(new LimitIterator(new IteratorIterator($a), 10, 2));
try { show(new LimitIterator($a, 10, 2)); } catch (OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
try { show(new LimitIterator($a, 0, 0)); } catch (OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
$l = new LimitIterator($a, 1, 3);
foreach ($l as $k => $v) {}
echo $l->getPosition(), "\n";
$l->rewind();
echo $l->getPosition(), $l->key(), $l->current(), "\n";
foreach ([0, 4, 3, 2, 1] as $p) {
    try { echo "seek ", $l->seek($p), " ", $l->key(), $l->current(), " ", $l->getPosition(), "\n"; }
    catch (OutOfBoundsException $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
}
$u = new LimitIterator($a, 2);
try { $u->seek(1); } catch (OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
echo $u->seek(4), "\n";
foreach ([[-1, 0], [0, -2]] as [$o, $n]) {
    try { new LimitIterator($a, $o, $n); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
}
function gen() { for ($i = 0; $i < 6; $i++) yield "k$i" => $i * 10; }
show(new LimitIterator(new IteratorIterator(gen()), 2, 3));
$w = new LimitIterator(new IteratorIterator(gen()), 1, 2);
foreach ($w as $v) {}
var_dump($w->valid());
$ii = new InfiniteIterator(new ArrayIterator([1, 2, 3]));
$out = [];
foreach (new LimitIterator($ii, 0, 8) as $k => $v) { $out[] = "$k:$v"; }
echo implode(",", $out), "\n";
$e = new InfiniteIterator(new ArrayIterator([]));
foreach ($e as $v) { echo "never\n"; }
echo "done\n";
