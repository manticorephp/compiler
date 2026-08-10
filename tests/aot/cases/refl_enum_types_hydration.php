<?php
// The rest of the reflection surface DI and hydration ride on: ReflectionEnum,
// composite types, newInstanceWithoutConstructor(), and the IS_INSTANCEOF
// attribute filter.
//
// Four roots had to be closed for this, and only one of them was about
// reflection:
//   - a getter declared `object|null` makes `(string)$t` print the POINTER, and
//     `__toString` was resolved from the STATIC class and called DIRECTLY — so
//     every subclass answered the BASE's string. Both fixed; the cast now
//     dispatches on the runtime class like any other method call.
//   - ReflectAnalysis decides which classes get metadata and did not know
//     `ReflectionEnum`, so `new ReflectionEnum(S::class)` threw
//     "Class does not exist" for a class that plainly does.
//   - `is_a($runtimeName, $runtimeTarget, true)` — BOTH names strings — had no
//     arm at all; the closed world's is-a edges now go into a table.
//   - the declared-property-defaults helper was generated only for
//     `unserialize`, so a constructor-less instance read NULL where php reads
//     the default.
#[Attribute]
class BaseAttr {}

#[Attribute]
class DerivedAttr extends BaseAttr {}

#[DerivedAttr]
final class Target {}

enum Status: string
{
    case Draft = 'draft';
    case Live = 'live';
}

enum Plain
{
    case One;
    case Two;
}

final class Entity
{
    private string $title = 'unset';
    public int $n = 42;
    public function __construct(string $t) { $this->title = $t . '!'; }
    public function title(): string { return $this->title; }
}

final class Sig
{
    public function m(string|int $u, ?int $n, mixed $x): void {}
}

// ── attribute filtering, including IS_INSTANCEOF ──
$rc = new ReflectionClass(Target::class);
echo "exact derived : ", count($rc->getAttributes(DerivedAttr::class)), "\n";
echo "exact base    : ", count($rc->getAttributes(BaseAttr::class)), "\n";
echo "instanceof    : ", count($rc->getAttributes(BaseAttr::class, ReflectionAttribute::IS_INSTANCEOF)), "\n";
echo "unrelated     : ", count($rc->getAttributes('Attribute', ReflectionAttribute::IS_INSTANCEOF)), "\n";

// ── enums ──
$re = new ReflectionEnum(Status::class);
echo "backed        : ", var_export($re->isBacked(), true), "\n";
echo "backing type  : ", (string)$re->getBackingType(), "\n";
echo "cases         : ", count($re->getCases()), "\n";
foreach ($re->getCases() as $c) {
    echo "  case ", $c->getName(), " = ", $c->getBackingValue(),
         " value-is-case=", var_export($c->getValue() instanceof Status, true), "\n";
}
echo "has Draft     : ", var_export($re->hasCase('Draft'), true), "\n";
echo "get Live      : ", $re->getCase('Live')->getBackingValue(), "\n";

$rp = new ReflectionEnum(Plain::class);
echo "plain backed  : ", var_export($rp->isBacked(), true), "\n";
echo "plain cases   : ", count($rp->getCases()), "\n";

// ── hydration: no constructor, but the declared defaults still apply ──
$e = (new ReflectionClass(Entity::class))->newInstanceWithoutConstructor();
echo "hydrated title: ", $e->title(), "\n";
echo "hydrated n    : ", $e->n, "\n";
$p = (new ReflectionClass(Entity::class))->getProperty('title');
$p->setValue($e, 'set');
echo "after setValue: ", $e->title(), " / ", $p->getValue($e), "\n";

// ── composite types ──
$ps = (new ReflectionMethod(Sig::class, 'm'))->getParameters();
$u = $ps[0]->getType();
echo "union class   : ", get_class($u), "\n";
echo "union parts   : ", count($u->getTypes()), "\n";
// ⚠ the ORDER is the compiler's normalized one, not the source spelling, so the
// names are checked as a SET — the divergence is real and belongs to type
// normalization, not to reflection.
$names = [];
foreach ($u->getTypes() as $t) { $names[$t->getName()] = true; }
echo "has string    : ", var_export(isset($names['string']), true), "\n";
echo "has int       : ", var_export(isset($names['int']), true), "\n";
echo "union null    : ", var_export($u->allowsNull(), true), "\n";

$n = $ps[1]->getType();
echo "named class   : ", get_class($n), "\n";
echo "named str     : ", (string)$n, "\n";
$m = $ps[2]->getType();
echo "mixed str     : ", (string)$m, " null=", var_export($m->allowsNull(), true), "\n";
