<?php
// NoRewindIterator
function g() { yield 'a' => 1; yield 'b' => 2; yield 'c' => 3; }
$n = new NoRewindIterator(g());
foreach ($n as $k => $v) { echo "$k=$v "; break; }
foreach ($n as $k => $v) { echo "$k=$v "; }
echo "\n";
$n = new NoRewindIterator(new ArrayIterator([1, 2, 3]));
$n->next();
foreach ($n as $v) { echo $v; }
echo "\n";
// RegexIterator
$a = new ArrayIterator(['apple', 'banana', 'avocado', 'cherry', 'a1b22']);
foreach (new RegexIterator($a, '/^a/') as $k => $v) { echo "$k=$v "; }
echo "\n";
foreach (new RegexIterator($a, '/^a/', RegexIterator::MATCH, RegexIterator::INVERT_MATCH) as $k => $v) { echo "$k=$v "; }
echo "\n";
foreach (new RegexIterator($a, '/(\d+)/', RegexIterator::GET_MATCH) as $k => $v) { echo $k, json_encode($v), " "; }
echo "\n";
foreach (new RegexIterator($a, '/\d+/', RegexIterator::ALL_MATCHES) as $k => $v) { echo $k, json_encode($v), " "; }
echo "\n";
foreach (new RegexIterator($a, '/an/', RegexIterator::SPLIT) as $k => $v) { echo $k, json_encode($v), " "; }
echo "\n";
$r = new RegexIterator($a, '/a/', RegexIterator::REPLACE);
$r->replacement = '#';
foreach ($r as $k => $v) { echo "$k=$v "; }
echo "\n";
$k = new ArrayIterator(['one' => 1, 'two' => 2, 'three' => 3]);
foreach (new RegexIterator($k, '/^t/', RegexIterator::MATCH, RegexIterator::USE_KEY) as $kk => $v) { echo "$kk=$v "; }
echo "\n";
$r = new RegexIterator($a, '/b/');
echo $r->getMode(), $r->getFlags(), $r->getPregFlags(), $r->getRegex(), "\n";
$r->setMode(RegexIterator::GET_MATCH);
echo $r->getMode(), "\n";
try { $r->setMode(7); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { new RegexIterator($a, '/a/', 9); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
// RecursiveArrayIterator + RecursiveRegexIterator + ParentIterator
$tree = ['a' => 1, 'b' => ['c' => 2, 'd' => ['e' => 3]], 'f' => 4, 'g' => []];
$ri = new RecursiveIteratorIterator(new RecursiveArrayIterator($tree), RecursiveIteratorIterator::SELF_FIRST);
foreach ($ri as $k => $v) { echo str_repeat(' ', $ri->getDepth()), $k, is_array($v) ? '' : "=$v", "\n"; }
$o = new RecursiveArrayIterator([new ArrayObject([1]), [2]]);
foreach ($o as $v) { var_dump($o->hasChildren()); }
$o = new RecursiveArrayIterator([new ArrayObject([1]), [2]], RecursiveArrayIterator::CHILD_ARRAYS_ONLY);
foreach ($o as $v) { var_dump($o->hasChildren()); }
$rx = new RecursiveRegexIterator(new RecursiveArrayIterator(['test1', ['tet3', 'test4', 'test5'], 'xtest']), '/^test/');
foreach (new RecursiveIteratorIterator($rx) as $k => $v) { echo "$k=$v "; }
echo "\n";
$p = new ParentIterator(new RecursiveArrayIterator([1, [2, [3, 4]], 5, [6]]));
foreach (new RecursiveIteratorIterator($p, RecursiveIteratorIterator::SELF_FIRST) as $k => $v) { echo $k, ":", count($v), " "; }
echo "\n";
// MultipleIterator
$m = new MultipleIterator();
$m->attachIterator(new ArrayIterator([1, 2, 3]));
$m->attachIterator(new ArrayIterator(['a', 'b']));
foreach ($m as $k => $v) { echo json_encode($k), json_encode($v), " "; }
echo "\n";
$m = new MultipleIterator(MultipleIterator::MIT_NEED_ANY | MultipleIterator::MIT_KEYS_ASSOC);
$m->attachIterator(new ArrayIterator([1, 2, 3]), 'num');
$m->attachIterator(new ArrayIterator(['a', 'b']), 'let');
foreach ($m as $k => $v) { echo json_encode($k), json_encode($v), " "; }
echo "\n";
echo $m->countIterators(), $m->getFlags(), "\n";
$z = new ArrayIterator([9]);
$m->attachIterator($z, 'z');
var_dump($m->containsIterator($z));
$m->detachIterator($z);
var_dump($m->containsIterator($z), $m->countIterators());
try { $m->attachIterator(new ArrayIterator([]), 'num'); } catch (InvalidArgumentException $e) { echo $e->getMessage(), "\n"; }
$e = new MultipleIterator();
var_dump($e->valid());
try { $e->current(); } catch (RuntimeException $x) { echo $x->getMessage(), "\n"; }
$m = new MultipleIterator();
$m->attachIterator(new ArrayIterator([1, 2]));
$m->attachIterator(new ArrayIterator([1]));
$m->rewind(); $m->next();
var_dump($m->valid());
try { $m->current(); } catch (RuntimeException $x) { echo $x->getMessage(), "\n"; }
try { $m->key(); } catch (RuntimeException $x) { echo $x->getMessage(), "\n"; }
$m->setFlags(MultipleIterator::MIT_NEED_ANY);
var_dump($m->valid(), $m->current());
$m = new MultipleIterator(MultipleIterator::MIT_KEYS_ASSOC);
$m->attachIterator(new ArrayIterator([1]));
$m->rewind();
try { $m->current(); } catch (InvalidArgumentException $x) { echo $x->getMessage(), "\n"; }
