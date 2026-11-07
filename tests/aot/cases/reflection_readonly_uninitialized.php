<?php
// ReflectionProperty sees a readonly slot uninitialized on a constructorless instance, writes it once, and refuses the second write (Doctrine's ReadonlyAccessor).
class Tag { private ?int $id = null; private readonly string $name; public readonly array $list;
  public function __construct(string $n) { $this->name = $n; $this->list = [1]; } public function n() { return $this->name; } }
$rc = new ReflectionClass(Tag::class);
$t = $rc->newInstanceWithoutConstructor();
$p = $rc->getProperty('name');
var_dump($p->isInitialized($t), $p->isReadOnly());
try { $p->getValue($t); } catch (Error $e) { echo $e->getMessage(), "\n"; }
$p->setValue($t, 'x');
var_dump($p->isInitialized($t), $p->getValue($t), $t->n());
try { $p->setValue($t, 'y'); } catch (Error $e) { echo get_class($e), ':', $e->getMessage(), "\n"; }
$u = new Tag('z'); var_dump($p->isInitialized($u)); echo $p->getValue($u), "\n";
try { $p->setValue($u, 'y'); } catch (Error $e) { echo $e->getMessage(), "\n"; }
$l = $rc->getProperty('list'); var_dump($l->isInitialized($t)); $l->setValue($t, [1, 2]); echo json_encode($l->getValue($t)), "\n";
$i = $rc->getProperty('id'); var_dump($i->isInitialized($t), $i->getValue($t));

class Ho { public int $a = 1; public string $b { get => 'x'; set { } } public int $c { get => 2; } }
foreach (['a', 'b', 'c'] as $n) { $r = new ReflectionProperty(Ho::class, $n); echo $n, (int)$r->hasHooks(), (int)$r->hasHook(PropertyHookType::Get), (int)$r->hasHook(PropertyHookType::Set), "\n"; }
