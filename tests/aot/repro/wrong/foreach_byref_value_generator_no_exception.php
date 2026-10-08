<?php
// foreach by reference over a by-value Generator iterates instead of throwing Zend's Exception
// issue: #140
function gen() { yield 1; yield 2; }
try { foreach (gen() as &$x) { echo $x; } } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
unset($x);
final class A implements IteratorAggregate { public function getIterator(): Generator { yield 1; } }
try { foreach (new A() as &$x) { echo $x; } } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
