<?php
class Base { protected static $defaultName; public int $own = 1; private string $secret = 's'; }
class Mid extends Base { public string $m = 'm'; }
class Leaf extends Mid {}

foreach ([['Base','defaultName'], ['Mid','defaultName'], ['Leaf','defaultName'],
          ['Leaf','own'], ['Leaf','m'], ['Mid','own']] as [$c, $p]) {
    $r = new \ReflectionProperty($c, $p);
    echo $c, '::', $p, " -> class=", $r->class, " static=", ($r->isStatic() ? 'Y' : 'n'),
         " sameAsQueried=", ($c === $r->class ? 'Y' : 'n'), "\n";
}
try { new \ReflectionProperty('Leaf', 'nope'); } catch (\ReflectionException $e) { echo "missing: ", $e->getMessage(), "\n"; }
$o = new Leaf();
$r = new \ReflectionProperty($o, 'own');
echo "from obj class=", $r->class, "\n";
