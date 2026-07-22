<?php
// A `mixed` scalar-bag property must not be poisoned into a raw store by an
// UNRELATED class whose same-named property is used as a raw array base — the
// reader assumes a tagged cell, so a raw scalar came back as garbage float
// (int 1 -> 4.94e-324). cellPropBoxed is keyed by declaring class + name.
class Bag {
    public mixed $v = [];
    public function add($x): void { $this->v[] = $x; }
    public function all(): array { return $this->v; }
}
class Box { public mixed $v; }

// Inheritance: an inherited array-base slot stays consistent across the chain.
class Base { public mixed $data = []; public function push($x) { $this->data[] = $x; } public function get() { return $this->data; } }
class Derived extends Base { public function total() { $n = 0; foreach ($this->data as $x) { $n += $x; } return $n; } }
class Holder { public mixed $data; }

// Same class, genuinely heterogeneous (scalar + array) -> stays boxed.
class Mix { public mixed $val; }

$b = new Bag(); $b->add(1); $b->add(2);
echo implode(",", $b->all()), "\n";

$a = new Box();
$a->v = 42;      var_dump($a->v);
$a->v = "hello"; var_dump($a->v);
$a->v = 3.5;     var_dump($a->v);
$a->v = true;    var_dump($a->v);

$d = new Derived(); $d->push(10); $d->push(20);
echo implode(",", $d->get()), " sum=", $d->total(), "\n";

$h = new Holder();
$h->data = 7;    var_dump($h->data);
$h->data = "ok"; var_dump($h->data);

$m = new Mix();
$m->val = 5;       var_dump($m->val);
$m->val = [1,2,3]; var_dump($m->val);
$m->val = "z";     var_dump($m->val);
