<?php
use Manticore\Ds\{Map, Set};
final class Holder { /** @var Map<string,int> */ public Map $m; public function __construct() { $this->m = new Map(); } }
/** @var Map<string,int> $m */
$m = new Map();
for ($i = 0; $i < 100; $i++) { $m["k$i"] = $i; }
$s = 0; for ($i = 0; $i < 100; $i++) { $s += $m["k$i"] + $m->get("k$i"); }
echo $s, ' ', isset($m['k5']) ? 'y' : 'n', isset($m['zz']) ? 'y' : 'n', ' ', $m->get('zz', -1), "\n";
try { $m->get('zz'); } catch (OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
$u = new Map(); try { $u[1.5] = 1; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
$h = new Holder(); $h->m->set('a', 1); echo $h->m['a'], "\n";
$set = new Set(); $o = new stdClass(); $set->add($o); var_dump($set->has($o), $set->has(new stdClass()));
