<?php
// Every view of an object with redeclared privates sees two slots under one name, in php's order.
namespace N;
trait T { private int $t = 7; public function tv(): int { return $this->t; } }
class G { private string $s = 'g'; public int $pub = 1; public function gs(): string { return $this->s; }
  public function setG(string $v): void { $this->s = $v; } }
class P extends G { private array $r = ['p']; private string $s = 'p'; protected int $prot = 2; private int $t = 70;
  public function __construct(private int $promo = 5) {}
  public function ps(): string { return $this->s . count($this->r) . $this->promo . $this->t; }
  public function push(mixed $x): void { $this->r[] = $x; } }
class C extends P { use T; private array $r = []; private string $s = 'c';
  public function __construct(private int $promo = 50) { parent::__construct(6); }
  public function cs(): string { return $this->s . count($this->r) . $this->promo . $this->t; }
  public function vars(): array { return get_object_vars($this); }
  public function pushC(mixed $x): void { $this->r[] = $x; } }
class D extends C { public function ds(): string { return $this->cs(); } }
class E extends \Exception { private $previous = 'mine'; public function mine() { return $this->previous; } }

$c = new C;
echo $c->gs(), ' ', $c->ps(), ' ', $c->cs(), ' ', $c->tv(), "\n";
$c->setG('G!'); $c->push(1.5); $c->push('x'); $c->pushC(9);
echo $c->gs(), ' ', $c->ps(), ' ', $c->cs(), "\n";
var_dump($c);
print_r($c);
var_export($c); echo "\n";
foreach ((array)$c as $k => $v) { echo str_replace("\0", '~', $k), ' '; } echo "\n";
print_r($c->vars());
echo json_encode($c), "\n";
$s = serialize($c);
echo str_replace("\0", '~', $s), "\n";
$u = unserialize($s);
echo $u->gs(), ' ', $u->ps(), ' ', $u->cs(), "\n";
$k = clone $c; $k->pushC(1); $k->push(2);
echo $c->ps(), ' ', $c->cs(), ' ', $k->ps(), ' ', $k->cs(), "\n";
var_dump($c == clone $c, $c == $k);
$d = new D; $d->push(1); echo $d->ds(), ' ', $d->ps(), "\n";
$names = []; foreach ((new \ReflectionClass(C::class))->getProperties() as $p) { $names[] = $p->class . '::' . $p->getName(); } sort($names); echo implode(' ', $names), "\n";
class RA { public $x = 1; protected $y = 2; } class RB extends RA { public $x = 3; protected $y = 4; }
echo (new \ReflectionProperty(RB::class, 'x'))->class, ' ', (new \ReflectionProperty(RB::class, 'y'))->class, ' ', (new \ReflectionProperty(RA::class, 'x'))->class, ' ', (new \ReflectionProperty(C::class, 'prot'))->class, "\n";
var_dump(property_exists(C::class, 'r'), property_exists(C::class, 'promo'));
$rp = new \ReflectionProperty(P::class, 'r'); echo count($rp->getValue($c)), "\n";
$rc = new \ReflectionProperty(C::class, 'r'); echo count($rc->getValue($c)), "\n";
$e = new E('m', 3, new \Exception('inner'));
echo $e->mine(), ' ', $e->getPrevious()->getMessage(), "\n";
