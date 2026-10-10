<?php
// ReflectionMethod / ReflectionFunction members derivable without new metadata.
namespace App\Svc;
class Box
{
    public function __construct(public int $n = 1) {}
    public function __destruct() {}
    public function add(int $x, int ...$more): int { return $this->n + $x + array_sum($more); }
    public function plus(int $x): int { return $this->n + $x; }
    public static function make(): static { return new static(); }
    private function secret(string $s): string { return strtoupper($s); }
    public function byRef(array &$a, int $b): void { $a[] = $b; }
}
function helper(int $a, int $b = 2): int { return $a + $b; }
function spread(string ...$xs): int { return count($xs); }

$m = new \ReflectionMethod(Box::class, '__construct');
var_dump($m->isConstructor(), $m->isDestructor());
$d = new \ReflectionMethod(Box::class, '__destruct');
var_dump($d->isConstructor(), $d->isDestructor());
$a = new \ReflectionMethod(Box::class, 'add');
var_dump($a->isConstructor(), $a->isVariadic(), $a->getShortName(), $a->getNamespaceName(), $a->inNamespace());
var_dump($a->isInternal(), $a->isUserDefined(), $a->returnsReference(), $a->hasTentativeReturnType(), $a->getTentativeReturnType());
var_dump($a->getExtension(), $a->getExtensionName());
var_dump(basename($a->getFileName()));
var_dump((new \ReflectionMethod(Box::class, 'make'))->isVariadic());

$box = new Box(10);
echo (new \ReflectionMethod(Box::class, 'plus'))->getClosure($box)(5), "\n";
echo (new \ReflectionMethod(Box::class, 'make'))->getClosure()()->n, "\n";
echo (new \ReflectionMethod(Box::class, 'secret'))->getClosure($box)('x'), "\n";

$f = new \ReflectionFunction('App\Svc\helper');
var_dump($f->isVariadic(), (new \ReflectionFunction('App\Svc\spread'))->isVariadic());
var_dump($f->isClosure(), (new \ReflectionFunction(fn() => 1))->isClosure());
var_dump($f->returnsReference(), $f->hasTentativeReturnType(), $f->getTentativeReturnType());
var_dump($f->getExtension(), $f->getExtensionName());

$p = $a->getParameters();
var_dump($p[0]->isPassedByReference(), $p[0]->canBePassedByValue());
$r = (new \ReflectionMethod(Box::class, 'byRef'))->getParameters();
var_dump($r[0]->isPassedByReference(), $r[0]->canBePassedByValue(), $r[1]->isPassedByReference());
