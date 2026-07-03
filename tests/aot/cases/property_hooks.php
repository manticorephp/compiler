<?php
// PHP 8.4 property hooks — get/set, arrow + block, backed + virtual.
class Temperature {
    public float $celsius = 0.0 {
        get => $this->celsius;
        set(float $v) { if ($v < -273.15) { $v = -273.15; } $this->celsius = $v; }
    }
    public float $fahrenheit {
        get => $this->celsius * 9.0 / 5.0 + 32.0;
    }
}
$t = new Temperature();
$t->celsius = 25.0;
echo $t->celsius, " ", $t->fahrenheit, "\n";
$t->celsius = -300.0;                 // clamped by the set block
echo $t->celsius, "\n";

class Name {
    public string $value = "" {
        get => strtoupper($this->value);
        set(string $v) => trim($v);
    }
}
$n = new Name();
$n->value = "  bob  ";
echo "[", $n->value, "]\n";

class Counter {
    public int $count = 10 { set(int $v) => $v; get => $this->count; }
}
$c = new Counter();
$c->count += 5;                       // read-hook + write-hook
$c->count++;
echo $c->count, "\n";

// virtual computed property
class Rect {
    public function __construct(public int $w, public int $h) {}
    public int $area { get => $this->w * $this->h; }
}
$r = new Rect(3, 4);
echo $r->area, "\n";

// default init bypasses the set hook; later writes go through it
class Doubler {
    public int $x = 7 { set(int $v) => $v * 100; }
}
$d = new Doubler();
echo $d->x;                           // 7 (default, not doubled)
$d->x = 3;
echo " ", $d->x, "\n";                // 300

// inherited hook
class Base { public string $label { get => "<" . $this->label . ">"; } }
class Derived extends Base {}
$dd = new Derived();
$dd->label = "hi";
echo $dd->label, "\n";
