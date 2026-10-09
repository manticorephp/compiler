<?php
// A bound Map<string,V> coerces a float key to string instead of throwing TypeError like the unbound Map.
use Manticore\Ds\Map;
/** @var Map<string,int> $m */
$m = new Map();
try { $m[1.5] = 1; echo "stored\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { $m->set(2.5, 1); echo "stored\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
echo count($m), "\n";
/** @var Map<string,int> $n */
$n = new Map();
$n["5"] = 7;
try { echo $n[5], "\n"; } catch (OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
try { echo $n->get(5), "\n"; } catch (OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
