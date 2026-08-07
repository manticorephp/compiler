<?php
// @epic: reflection-tier4
// @why: ArgumentMetadataFactory reads union types off controller arguments, and
//       DI skips a union-hinted constructor argument rather than misresolving it.
//       prelude/reflection.php returns ReflectionNamedType|null everywhere; there
//       is no ReflectionUnionType or ReflectionIntersectionType.

interface CapA {}
interface CapB {}

final class CapUnion
{
    public function m(int|string $u, CapA&CapB $i, ?int $n, mixed $m): void {}
}

foreach ((new ReflectionMethod(CapUnion::class, 'm'))->getParameters() as $p) {
    $t = $p->getType();
    echo $p->getName(), ' class=', $t === null ? 'null' : get_class($t);
    echo ' str=', $t === null ? '-' : (string)$t;
    echo ' nullable=', $t === null ? '-' : var_export($t->allowsNull(), true), "\n";
}
