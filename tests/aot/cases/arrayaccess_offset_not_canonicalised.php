<?php
class AA implements ArrayAccess {
    public function offsetExists(mixed $o): bool { echo "E "; var_dump($o); return true; }
    public function offsetGet(mixed $o): mixed { echo "G "; var_dump($o); return 'v'; }
    public function offsetSet(mixed $o, mixed $v): void { echo "S "; var_dump($o); }
    public function offsetUnset(mixed $o): void { echo "U "; var_dump($o); }
}
class Holder { public AA $p; public function __construct() { $this->p = new AA; } }

function erased($o) {
    $o['1'] = 1; $o['1']; isset($o['1']); unset($o['1']); $x = $o['1'] ?? 0; $o['1'] .= 'x';
    $o['01'] = 1; $o['01']; isset($o['01']); unset($o['01']); $x = $o['01'] ?? 0;
    $o[true] = 1; $o[true]; isset($o[true]); unset($o[true]);
    $o[1.5] = 1; $o[1.5]; isset($o[1.5]); unset($o[1.5]);
    $o[null] = 1; $o[null]; isset($o[null]); unset($o[null]);
    $o[1] = 1; $o[1]; isset($o[1]); unset($o[1]);
    $o['-0'] = 1; $o[' 1'] = 1;
}
function typed() {
    $o = new AA;
    $o['1'] = 1; $o['1']; isset($o['1']); unset($o['1']); $x = $o['1'] ?? 0; $o['1'] .= 'x';
    $o['01'] = 1; $o['01']; isset($o['01']); unset($o['01']); $x = $o['01'] ?? 0;
    $o[true] = 1; $o[true]; isset($o[true]); unset($o[true]);
    $o[1.5] = 1; $o[1.5]; isset($o[1.5]); unset($o[1.5]);
    $o[null] = 1; $o[null]; isset($o[null]); unset($o[null]);
    $o[1] = 1; $o[1]; isset($o[1]); unset($o[1]);
    $o['-0'] = 1; $o[' 1'] = 1;
}
function prop() {
    $h = new Holder;
    $h->p['1'] = 1; $h->p['1']; isset($h->p['1']); unset($h->p['1']); $x = $h->p['1'] ?? 0; $h->p['1'] .= 'x';
    $h->p['01'] = 1; $h->p['01']; isset($h->p['01']); unset($h->p['01']);
    $h->p[true] = 1; $h->p[1.5] = 1; $h->p[null] = 1;
}
echo "-- typed\n"; typed();
echo "-- erased\n"; erased(new AA);
echo "-- prop\n"; prop();
$a = []; $a['1'] = 1; var_dump(array_keys($a));
class Outer implements ArrayAccess {
    public AA $in;
    public function __construct() { $this->in = new AA; }
    public function offsetExists(mixed $o): bool { echo "OE "; var_dump($o); return true; }
    public function offsetGet(mixed $o): mixed { echo "OG "; var_dump($o); return $this->in; }
    public function offsetSet(mixed $o, mixed $v): void {}
    public function offsetUnset(mixed $o): void {}
}
function nested($n) { $n['1']['2']; }
$n = new Outer; nested($n);
$n['1']['2']; unset($n['1']);
function arrk($a) { $a['1'] = 'x'; $a['01'] = 'y'; var_dump(array_keys($a), $a['1'], isset($a['1']), $a[1] ?? 'none'); unset($a['1']); var_dump(count($a)); }
arrk([]);
arrk([5 => 'z']);
