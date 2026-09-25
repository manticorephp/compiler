<?php
final class K { public function __construct(public string $n) {} public function __destruct() { echo "~K {$this->n}\n"; } }
final class P {
    private static \WeakMap $active;
    public function start(): void { self::$active ??= new \WeakMap(); self::$active[$this] = true; }
    public static function n(): int { return \count(self::$active ?? []); }
}
$m = new WeakMap();
$a = new K('a'); $b = new K('b');
$m[$a] = 'va'; $m[$b] = 'vb';
echo count($m), " ", isset($m[$a]) ? 'y' : 'n', " ", $m[$b], "\n";
echo "drop a\n"; unset($a);
echo count($m), "\n";
unset($m[$b]);
echo count($m), "\n";
try { $m['x'] = 1; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
$r = WeakReference::create($b);
var_dump($r === WeakReference::create($b), $r->get() === $b);
unset($b);
var_dump($r->get());
$p = new P(); $p->start(); $q = new P(); $q->start();
echo P::n(), "\n";
unset($p);
echo P::n(), "\n";
echo "end\n";
