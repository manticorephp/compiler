<?php
// `$o->p = &$v` binds the slot whatever its declared type, and an array written through either name is one array (Symfony sessions).
class O { public array $p = []; public function b(array &$x): void { $this->p = &$x; } public function w(): void { $this->p["k"] = 1; } }
$o = new O; $s = ["i" => 0]; $o->b($s); $o->w(); $s["z"] = 2;
echo json_encode($s), json_encode($o->p), "\n";

$u = new O; $a = []; $u->p = &$a; $a["x"] = 1; $u->p["y"] = 2; $u->p[] = 3;
echo json_encode($a), json_encode($u->p), count($u->p), "\n";

class Holder { public array $d = []; public ?int $n = 0; public O $o; public I $i;
  public function __construct() { $this->o = new O; $this->o->b($this->d); $this->i = new I; $this->i->b($this->n); } }
class I { private ?int $p = null; public function b(?int &$x): void { $this->p = &$x; } public function up(): void { ++$this->p; } public function g(): ?int { return $this->p; } }
$h = new Holder; $h->o->w(); $h->d["z"] = 2; $h->i->up(); $h->n += 5;
echo json_encode($h->d), json_encode($h->o->p), $h->n, $h->i->g(), "\n";

// an element bound by a callee, on a local and on a property
class Bag { private array $a = []; public function init(array &$x): void { $this->a = &$x; } public function s(string $k, mixed $v): void { $this->a[$k] = $v; } public function all(): array { return $this->a; } }
$root = []; $root["bag"] = []; $b = new Bag; $b->init($root["bag"]); $b->s("q", 1); $root["bag"]["r"] = 2;
echo json_encode($root), json_encode($b->all()), "\n";
class Store { public array $data = []; public function bind(Bag $b): void { $this->data["b"] = []; $b->init($this->data["b"]); } }
$st = new Store; $b2 = new Bag; $st->bind($b2); $b2->s("s", 3);
echo json_encode($st->data), json_encode($b2->all()), "\n";

// the Symfony session shape: a proxy adds an element reference, the bag binds it, $_SESSION sees all of it
class Proxy { private array $data; private ?int $idx; public function __construct(private Bag $bag, array &$data, ?int &$idx) { $this->data = &$data; $this->idx = &$idx; }
  public function key(): string { return 'attrs'; }
  public function initialize(array &$array): void { ++$this->idx; $this->data[$this->key()] = &$array; $this->bag->init($array); }
  public function bag(): Bag { ++$this->idx; return $this->bag; } }
class Sess { private array $data = []; private ?int $idx = 0; public Proxy $p;
  public function __construct(Bag $b) { $this->p = new Proxy($b, $this->data, $this->idx); }
  public function dump(): string { return json_encode($this->data) . $this->idx; } }
function load(Proxy $p, ?array &$session = null): void { if ($session === null) { $session = &$_SESSION; } $session['attrs'] = isset($session['attrs']) && is_array($session['attrs']) ? $session['attrs'] : []; $p->initialize($session['attrs']); }
$_SESSION = ['attrs' => ['old' => 1]];
$bag = new Bag; $se = new Sess($bag); load($se->p);
$se->p->bag()->s('new', 2);
echo json_encode($_SESSION), $se->dump(), json_encode($bag->all()), "\n";

// clone shares a bound slot; a typed bound slot still answers its declared type
$c1 = new O; $v = [1]; $c1->p = &$v; $c2 = clone $c1; $c2->p[] = 2; echo json_encode($v), json_encode($c1->p), "\n";
function arr(array $x): int { return count($x); } echo arr($c1->p), "\n";
foreach ($c1->p as $e) { echo $e; } echo "\n";
