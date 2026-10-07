<?php
// A bound Map<string,V> coerces a float key to string instead of throwing TypeError like the unbound Map.
// issue: #133
use Manticore\Ds\Map;
/** @var Map<string,int> $m */
$m = new Map();
try { $m[1.5] = 1; echo "stored\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { $m->set(2.5, 1); echo "stored\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
echo count($m), "\n";
