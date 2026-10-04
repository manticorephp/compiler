<?php
// A TYPED property bound by reference to a param of another scalar type: php
// refuses the binding with a TypeError and the property keeps its value. We
// coerced silently. (The message's ", called in <file> on line <n>" tail is
// trimmed: the path is the test runner's.)
function app(string &$x): void { $x .= 'a'; }
function fl(float &$f): void { $f += 0.5; }
function toInt(string &$c): void { $c = 7; }
final class P {
    public int $n = 5; public string $s = '12'; public ?int $m = null; public float $f = 1.5;
    public function bump(string &$x): void { $x .= '!'; }
}
function msg(\TypeError $e): string { $m = $e->getMessage(); $c = strpos($m, ', called in'); return get_class($e) . ': ' . ($c === false ? $m : substr($m, 0, $c)); }
$o = new P();
try { app($o->n); } catch (\TypeError $e) { echo msg($e), "\n"; }
var_dump($o->n);
try { fl($o->n); } catch (\TypeError $e) { echo msg($e), "\n"; }
try { app($o->m); } catch (\TypeError $e) { echo msg($e), "\n"; }
try { app($o->f); } catch (\TypeError $e) { echo msg($e), "\n"; }
try { $o->bump($o->n); } catch (\TypeError $e) { echo msg($e), "\n"; }
var_dump($o->n, $o->m, $o->f);
// Same type: the binding is fine, and a write of another kind comes back coerced.
app($o->s); var_dump($o->s);
toInt($o->s); var_dump($o->s);
