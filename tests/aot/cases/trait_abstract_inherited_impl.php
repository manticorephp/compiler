<?php
// An abstract trait method is satisfied by the INHERITED implementation; it
// shadowed the parent's getName() (php-cs-fixer ConfigurableFixerTrait).
interface Named { public function getName(): string; }
abstract class Base implements Named { public function getName(): string { return 'base:' . static::class; } }
trait NeedsName { abstract public function getName(): string; public function label(): string { return '[' . $this->getName() . ']'; } }
final class Impl extends Base { use NeedsName; }
$i = new Impl();
echo $i->getName(), ' ', $i->label(), "\n";
$c = 'Impl'; $d = new $c(); echo $d->getName(), "\n";
function via(Named $n): string { return $n->getName(); }
echo via($i), "\n";
