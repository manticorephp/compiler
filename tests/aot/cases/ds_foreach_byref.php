<?php
use Manticore\Ds\{Map, Set, Vec};
$m = new Map(); $m->set('a', 1);
$s = new Set(); $s->add(1);
$v = Vec::fromArray([1]);
try { foreach ($m as &$x) {} } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
unset($x);
try { foreach ($s as &$x) {} } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
unset($x);
try { foreach ($v as &$x) {} } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
function nb(?Map $m): void { foreach ($m as &$x) { echo $x; } echo "null by-ref walks nothing\n"; }
nb(null);
