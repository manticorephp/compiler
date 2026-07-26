<?php
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class Rep { public function __construct(public int $n = 0) {} }
#[Attribute(Attribute::TARGET_METHOD)]
class MethodOnly {}
class Plain {}

class Host {
    #[Rep(1)] #[Rep(2)]
    public function m(): void {}
}

#[MethodOnly]
class BadTarget {}

#[Plain]
class NotAttr {}

$m = new ReflectionMethod('Host', 'm');
foreach ($m->getAttributes() as $a) {
    var_dump($a->getName(), $a->getTarget(), $a->isRepeated());
    $i = $a->newInstance();
    echo get_class($i), " n=", $i->n, "\n";
}
$c = (new ReflectionClass('BadTarget'))->getAttributes()[0];
var_dump($c->getName(), $c->getTarget(), $c->isRepeated());
try { $c->newInstance(); } catch (Error $e) { echo "E1: ", $e->getMessage(), "\n"; }
$p = (new ReflectionClass('NotAttr'))->getAttributes()[0];
try { $p->newInstance(); } catch (Error $e) { echo "E2: ", $e->getMessage(), "\n"; }
