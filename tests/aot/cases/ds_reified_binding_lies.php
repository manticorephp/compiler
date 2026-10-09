<?php
// A template binding is a docblock claim: a bound Map or user generic never coerces or reinterprets an argument that does not fit it.
use Manticore\Ds\Map;

/** @template T */
final class Box
{
    /** @param T $v */
    public function put(mixed $v): string { return get_debug_type($v); }
}

/** @var Map<string,int> $m */
$m = new Map();
$m->set("5", 50);
try { echo $m->get(5), "\n"; } catch (OutOfBoundsException $e) { echo 'OutOfBounds: ', $e->getMessage(), "\n"; }
echo $m->get("5"), "\n";
var_dump($m->has(5));
try { echo $m->get(2.5), "\n"; } catch (TypeError $e) { echo 'TypeError: ', $e->getMessage(), "\n"; }
try { echo $m->get(true), "\n"; } catch (TypeError $e) { echo 'TypeError: ', $e->getMessage(), "\n"; }
try { echo $m->get(new stdClass()), "\n"; } catch (OutOfBoundsException $e) { echo 'OutOfBounds: ', $e->getMessage(), "\n"; }
$m->set(5, 500);
echo count($m), "\n";
echo $m->get(5), "\n";
echo $m->get("5"), "\n";
try { $m->set(2.5, 1); echo "stored\n"; } catch (TypeError $e) { echo 'TypeError: ', $e->getMessage(), "\n"; }
try { $m->set(true, 1); echo "stored\n"; } catch (TypeError $e) { echo 'TypeError: ', $e->getMessage(), "\n"; }
$o = new stdClass();
$m->set($o, 9);
echo $m->get($o), "\n";
echo count($m), "\n";

/** @var Map<int,string> $q */
$q = new Map();
$q->set("7", "str7");
$q->set(7, "int7");
echo count($q), "\n";
echo $q->get("7"), "\n";
echo $q->get(7), "\n";
$q->set(1 << 50, "big");
echo $q->get(1 << 50), "\n";
echo count($q), "\n";

/** @var Box<string> $b */
$b = new Box();
echo $b->put(5), "\n";
echo $b->put("s"), "\n";
echo $b->put(2.5), "\n";
