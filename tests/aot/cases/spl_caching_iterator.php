<?php
$a = new ArrayIterator(['x' => 'a', 'y' => 'b', 'z' => 'c']);
$c = new CachingIterator($a);
foreach ($c as $k => $v) {
    echo $k, "=", $v, " str=", $c, " hasNext=", var_export($c->hasNext(), true), "\n";
}
var_dump($c->valid());
$c = new CachingIterator($a, CachingIterator::TOSTRING_USE_KEY);
foreach ($c as $v) { echo $c, ","; }
echo "\n";
$c = new CachingIterator($a, CachingIterator::TOSTRING_USE_CURRENT);
foreach ($c as $v) { echo $c, ","; }
echo "\n";
$c = new CachingIterator(new ArrayIterator([1, 2]), CachingIterator::TOSTRING_USE_INNER);
try { foreach ($c as $v) { echo "inner\n"; } } catch (Throwable $e) { echo get_class($e), "\n"; }
$c = new CachingIterator($a, CachingIterator::FULL_CACHE);
foreach ($c as $v) {}
print_r($c->getCache());
echo count($c), " ", isset($c['y']) ? "y" : "n", " ", $c['z'], "\n";
$c['w'] = 'new'; unset($c['x']);
print_r($c->getCache());
echo $c->getFlags(), "\n";
$c = new CachingIterator($a, 0);
foreach ($c as $v) {}
foreach ([fn() => (string)$c, fn() => count($c), fn() => $c['x'], fn() => $c->getCache()] as $f) {
    try { $f(); } catch (BadMethodCallException $e) { echo $e->getMessage(), "\n"; }
}
try { new CachingIterator($a, 3); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { $c->setFlags(6); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
$c = new CachingIterator($a, CachingIterator::CALL_TOSTRING);
try { $c->setFlags(0); } catch (InvalidArgumentException $e) { echo $e->getMessage(), "\n"; }
$c->setFlags(CachingIterator::CALL_TOSTRING | CachingIterator::FULL_CACHE);
echo $c->getFlags(), "\n";
// lookahead idiom: last element
$c = new CachingIterator(new ArrayIterator([1, 2, 3]));
foreach ($c as $v) { echo $v, $c->hasNext() ? "," : "\n"; }
$r = new RecursiveCachingIterator(new RecursiveArrayIterator([1, [2, 3], 4, [5, [6]]]), CachingIterator::FULL_CACHE);
foreach (new RecursiveIteratorIterator($r, RecursiveIteratorIterator::SELF_FIRST) as $k => $v) {
    echo $k, ":", is_array($v) ? "arr" : $v, " ";
}
echo "\n";
$r = new RecursiveCachingIterator(new RecursiveArrayIterator([[1], 2]), CachingIterator::FULL_CACHE);
foreach ($r as $k => $v) { echo $k, var_export($r->hasChildren(), true), ($r->getChildren() === null ? "none" : get_class($r->getChildren())), " "; }
echo "\n";
