<?php
// Sibling subclasses declare `$value` with different types; the abstract base
// declares none. A read through the base type is the join of every sibling's
// declaration, not the first one found (it typed int and boxed an object
// pointer as an integer).
abstract class Expr { public function __construct(public readonly string $kind) {} }
final class Num extends Expr { public function __construct(public readonly int $value) { parent::__construct('num'); } }
final class Name extends Expr { public function __construct(public readonly string $value) { parent::__construct('name'); } }
final class Spread extends Expr { public function __construct(public readonly Expr $value) { parent::__construct('spread'); } }
final class Pair extends Expr { public function __construct(public readonly Expr $left, public readonly ?Expr $right) { parent::__construct('pair'); } }

abstract class Box { public function __construct(public string $tag) {} }
final class IntBox extends Box { public int $v = 1; public function __construct() { parent::__construct('i'); } }
final class FloatBox extends Box { public float $v = 1.5; public function __construct() { parent::__construct("f"); } }

/** @param Expr[] $args @return mixed[] */
function unwrap(array $args): array
{
    return [$args[0]->value];
}

function show(mixed $v): string
{
    if ($v instanceof Expr) { return 'Expr(' . $v->kind . ')'; }
    return \get_debug_type($v) . '(' . \var_export($v, true) . ')';
}

/** @param Expr[] $xs */
function each(array $xs): void
{
    foreach ($xs as $x) {
        $v = $x->value;
        echo $x->kind, ' -> ', show($v), "\n";
        echo '  wrapped: ', show(unwrap([$x])[0]), "\n";
        echo '  isset: ', isset($x->value) ? 'y' : 'n', "\n";
        echo '  coalesce: ', show($x->value ?? 'none'), "\n";
    }
}

$inner = new Name('x');
each([new Num(7), new Name('abc'), new Spread($inner), new Spread(new Num(3))]);
echo $inner->value, "\n";

/** @param Box[] $bs */
function bump(array $bs): void
{
    foreach ($bs as $b) {
        $b->v += 1;
        echo $b->tag, ' ', \var_export($b->v, true), "\n";
    }
}
bump([new IntBox(), new FloatBox()]);

function viaUnion(Num|Name|Spread $x): string
{
    return show($x->value);
}
echo viaUnion(new Num(5)), ' ', viaUnion(new Name('n')), ' ', viaUnion(new Spread($inner)), "\n";

// Same-typed siblings one field apart: each object's own offset, read and write.
abstract class Op { public function __construct(public string $op) {} }
final class CallOp extends Op { /** @param int[] $args */ public function __construct(public array $args) { parent::__construct('call'); } }
final class MethOp extends Op { /** @param int[] $args */ public function __construct(public string $method, public array $args) { parent::__construct('meth'); } }

/** @param Op[] $ops */
function argsOf(array $ops): void
{
    foreach ($ops as $o) {
        $o->args[] = 9;
        $o->args = \array_merge($o->args, [\count($o->args)]);
        echo $o->op, ' ', \implode(',', $o->args), "\n";
    }
}
argsOf([new CallOp([1, 2]), new MethOp('m', [5])]);

// Through an interface: implementers declare `$payload` int, string, object.
interface Msg {}
final class IntMsg implements Msg { public function __construct(public int $payload) {} }
final class StrMsg implements Msg { public function __construct(public string $payload) {} }
final class ObjMsg implements Msg { public function __construct(public Msg $payload) {} }

/** @param Msg[] $ms */
function payloads(array $ms): void
{
    foreach ($ms as $m) {
        echo show2($m->payload), ' ', isset($m->payload) ? 'set' : 'unset', "\n";
        $m->payload = $m->payload;
    }
}
function show2(mixed $v): string
{
    return \is_object($v) ? \get_class($v) : \get_debug_type($v) . '(' . \var_export($v, true) . ')';
}
payloads([new IntMsg(4), new StrMsg('s'), new ObjMsg(new IntMsg(1))]);
