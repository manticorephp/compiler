<?php

namespace Compile\Mir\Flow;

use Compile\Mir\FunctionDef;
use Compile\Mir\Node;
use Compile\Mir\Ownership;
use Compile\Mir\Passes\InsertMemoryOps;
use Compile\Mir\StoreLocal;
use Compile\Mir\Type;
use Compile\Mir\VecCopyOnAssign;
use Compile\Mir\Walk;

/**
 * The MIXED verdict of one function: the locals whose slot is a raw string /
 * object / array on some paths and a cell on others — InferTypes' flow-sensitive
 * promotion at an if/else merge, where a self-boxing `$x = box($x)` converts the
 * slot in place. No one static flavor releases both halves, so
 * {@see \Compile\Mir\Passes\OwnershipFlow} interns such a name at the `mix`
 * release class and the emitter keeps a per-slot "holds a cell" flag beside it
 * ({@see \Compile\Mir\Passes\EmitLlvmMemory::mixedReleaseIr}).
 *
 * A name qualifies when it took OWNED stores into both a raw slot and a cell
 * slot, every raw one of one flavor, and nothing else about it disagrees: a
 * borrowed store, an array alias it is the source of, a foreach that does not
 * co-own it, a param, a generator frame, a foreach variable, a name a reference
 * can reach (a write through the alias lands without the store that keeps the
 * flag), or a raw half with no tag a cell drop can trust.
 */
final class MixedSlots
{
    private Ownership $own;
    /** @var array<string, \Compile\Mir\EnumDef> */
    private array $enums;
    /** @var array<string, \Compile\Mir\ClassDef> */
    private array $classes;
    /** @var array<string, bool[]> fn name → per-param by-ref mask */
    private array $refMasks;
    /** @var array<string, bool> fn name → its variadic tail is by-ref */
    private array $refVariadic;
    /** @var array<string, int> closure fn name → capture count */
    private array $closureCaptureCount;

    /** @var array<string, bool> */
    private array $blocked = [];
    /** @var array<string, Type> */
    private array $type = [];
    /** @var array<string, Type> */
    private array $rawType = [];
    /** @var array<string, bool> */
    private array $cellSeen = [];
    /** @var array<string, bool> */
    private array $foreachVar = [];
    /** @var array<string, bool> */
    private array $refName = [];
    /** @var array<string, bool> */
    private array $feVeto = [];
    /** @var array<string, bool> */
    private array $mutatedVecs = [];
    private bool $feEnabled = true;

    /**
     * @param array<string, \Compile\Mir\EnumDef> $enums
     * @param array<string, \Compile\Mir\ClassDef> $classes
     * @param array<string, bool[]> $refMasks
     * @param array<string, bool> $refVariadic
     * @param array<string, int> $closureCaptureCount
     */
    public function __construct(Ownership $own, array $enums, array $classes, array $refMasks,
                                array $refVariadic, array $closureCaptureCount)
    {
        $this->own = $own;
        $this->enums = $enums;
        $this->classes = $classes;
        $this->refMasks = $refMasks;
        $this->refVariadic = $refVariadic;
        $this->closureCaptureCount = $closureCaptureCount;
    }

    /** @return array<string, Type> MIXED name → its raw slot type */
    public function verdict(FunctionDef $fn): array
    {
        $this->blocked = [];
        $this->type = [];
        $this->rawType = [];
        $this->cellSeen = [];
        $this->foreachVar = [];
        $this->refName = [];
        $this->collectRefNames($fn->body);
        $this->mutatedVecs = VecCopyOnAssign::mutatedLocals($fn->body);
        $this->feEnabled = \Compile\Debug::$feOnly === ''
            || \str_contains($fn->name, \Compile\Debug::$feOnly);
        $this->feVeto = InsertMemoryOps::foreachOwnVetoes($fn->body, $this->enums, $this->classes);
        $this->scan($fn->body);
        if ($fn->isGenerator) { return []; }
        $params = [];
        foreach ($fn->params as $p) { $params[$p->name] = true; }
        /** @var array<string, Type> $out */
        $out = [];
        foreach ($this->rawType as $name => $rawT) {
            if (!isset($this->cellSeen[$name]) || isset($this->blocked[$name])) { continue; }
            if (isset($params[$name]) || isset($this->foreachVar[$name]) || isset($this->refName[$name])) { continue; }
            if (!$this->mixableRaw($rawT)) { continue; }
            $out[$name] = $rawT;
        }
        return $out;
    }

    private function scan(Node $n): void
    {
        if ($n->kind === Node::KIND_FOREACH) {
            $fe = self::asForeach($n);
            $feOwns = $this->feEnabled
                && InsertMemoryOps::foreachValueCoOwns($fe, $this->enums, $this->classes)
                && !isset($this->feVeto[$fe->valueVar]);
            if ($feOwns) {
                $et = InsertMemoryOps::foreachValueSlotType($fe, $this->enums, $this->classes);
                if ($et !== null && isset($this->type[$fe->valueVar])) {
                    $prev = self::slotFlavor($this->type[$fe->valueVar]);
                    $now = self::slotFlavor($et);
                    if ($prev !== '' && $now !== '' && $prev !== $now) { $this->blocked[$fe->valueVar] = true; }
                }
                if ($et !== null && !isset($this->type[$fe->valueVar])) { $this->type[$fe->valueVar] = $et; }
                if ($et !== null && $et->kind === Type::KIND_CELL) {
                    $this->cellSeen[$fe->valueVar] = true;
                } elseif ($et !== null && !isset($this->rawType[$fe->valueVar])) {
                    $this->rawType[$fe->valueVar] = $et;
                }
            } else {
                $this->blocked[$fe->valueVar] = true;
            }
            $this->foreachVar[$fe->valueVar] = true;
            if ($fe->keyVar !== null) { $this->blocked[$fe->keyVar] = true; }
        }
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $this->scanStore(self::asStoreLocal($n));
            return;
        }
        foreach (Walk::children($n) as $c) { $this->scan($c); }
    }

    private function scanStore(StoreLocal $sl): void
    {
        $name = $sl->name;
        $value = $sl->value;
        $slotType = InsertMemoryOps::slotStoredType($sl);
        $boxedSlot = $slotType->kind === Type::KIND_CELL;
        if ($slotType->kind === Type::KIND_UNKNOWN && $this->own->erasedArrayPropRead($value)) {
            $slotType = Type::vec(Type::unknown());
        }
        if ($boxedSlot && $value->kind === Node::KIND_LOAD_LOCAL
            && self::asLoadLocal($value)->name === $name && $value->type->kind !== Type::KIND_CELL) {
            $this->cellSeen[$name] = true;
            return;
        }
        $ownedByRetain = \Compile\Debug::$rcElemReadOwns && $value->kind === Node::KIND_ARRAY_ACCESS;
        $ownedCopy = !$boxedSlot && VecCopyOnAssign::copies($value, $name, $this->mutatedVecs);
        if (!$boxedSlot && $value->kind === Node::KIND_LOAD_LOCAL
            && InsertMemoryOps::arrayAliasCoOwns($value->type, $sl->type, $this->enums, $this->classes)) {
            $ownedCopy = true;
        }
        if (($this->own->classify($value) > 0 || $ownedCopy) && !($ownedByRetain && $boxedSlot)) {
            if ($boxedSlot) {
                $this->cellSeen[$name] = true;
            } elseif (!isset($this->rawType[$name])) {
                $this->rawType[$name] = $slotType;
            } elseif (self::slotFlavor($this->rawType[$name]) !== self::slotFlavor($slotType)) {
                $this->blocked[$name] = true;
            }
            $prevFlavor = isset($this->type[$name]) ? self::slotFlavor($this->type[$name]) : '';
            $nowFlavor = self::slotFlavor($slotType);
            if ($prevFlavor !== '' && $nowFlavor !== '' && $prevFlavor !== $nowFlavor
                && $prevFlavor !== 'cell' && $nowFlavor !== 'cell') {
                $this->blocked[$name] = true;
            }
            if (!isset($this->type[$name])) { $this->type[$name] = $slotType; }
        } elseif (!self::isRcNeutralStore($value)
            && !($boxedSlot && self::isNonRcScalar($value->type))
            && !(self::isNonRcScalar($value->type) && self::isNonRcScalar($slotType))) {
            $this->blocked[$name] = true;
        }
        if (!$ownedCopy && $value->kind === Node::KIND_LOAD_LOCAL
            && $value->type->kind === Type::KIND_ARRAY) {
            $this->blocked[self::asLoadLocal($value)->name] = true;
        }
        $this->scan($value);
    }

    /** The release flavor family a slot of this type takes; two stores that
     *  disagree here have no single release right for both. */
    private static function slotFlavor(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_CELL) { return 'cell'; }
        if ($k === Type::KIND_STRING) { return 'str'; }
        if ($k === Type::KIND_ARRAY) {
            $el = $t->element;
            if ($el === null || $el->kind !== Type::KIND_ARRAY) { return 'arr'; }
            $name = 'arr';
            $cur = $el;
            while ($cur !== null && $cur->kind === Type::KIND_ARRAY) {
                $name = $name . ':arr';
                $cur = $cur->element;
            }
            return $name . ':' . ($cur === null ? '?' : (string)$cur->kind);
        }
        if ($k === Type::KIND_OBJ) { return 'obj'; }
        return '';
    }

    private static function isRcNeutralStore(Node $value): bool
    {
        if ($value->kind === Node::KIND_STRING_CONST) { return true; }
        return $value->kind === Node::KIND_NULL_CONST || $value->type->kind === Type::KIND_NULL;
    }

    private static function isNonRcScalar(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_BOOL || $k === Type::KIND_NULL;
    }

    /** A raw slot type a cell can hold and a tagged drop can release. */
    private function mixableRaw(Type $t): bool
    {
        if ($t->kind === Type::KIND_STRING || $t->kind === Type::KIND_ARRAY) { return true; }
        if ($t->kind !== Type::KIND_OBJ) { return false; }
        $cls = $t->class ?? '';
        if ($cls === '' || $cls === 'Generator') { return false; }
        return $this->own->objClassIsRc($cls);
    }

    /**
     * Every local a reference can reach, or that lives outside the frame: a
     * `static` / `global` binding, either side of `$r = &$d`, a `$r = &f()`
     * target, a by-ref closure capture, a by-ref foreach (its variable and the
     * array it walks), the root of a `&` address or reference cell, and the
     * root of every argument a by-ref parameter receives. An unresolved callee
     * pins every local argument.
     */
    private function collectRefNames(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $this->refName[self::asStaticLocalDecl($n)->name] = true;
        } elseif ($k === Node::KIND_REF_ALIAS) {
            $ra = self::asRefAlias($n);
            $this->refName[$ra->target] = true;
            $this->refName[$ra->source] = true;
        } elseif ($k === Node::KIND_REF_BIND) {
            $this->refName[self::asRefBind($n)->target] = true;
        } elseif ($k === Node::KIND_REF_ADDR) {
            $rd = self::asRefAddr($n);
            $this->refName[$rd->target] = true;
            $this->refRoot($rd->lvalue);
        } elseif ($k === Node::KIND_REF_CELL) {
            $this->refRoot(self::asRefCell($n)->refSource);
        } elseif ($k === Node::KIND_CLOSURE) {
            $cl = self::asClosure($n);
            $i = 0;
            foreach ($cl->captures as $cap) {
                if ($cl->captureByRef[$i] ?? false) { $this->refRoot($cap); }
                $i = $i + 1;
            }
        } elseif ($k === Node::KIND_FOREACH) {
            $fe = self::asForeach($n);
            if ($fe->byRef) {
                $this->refName[$fe->valueVar] = true;
                $this->refRoot($fe->array);
            }
        } else {
            $this->refArgRoots($n);
        }
        foreach (Walk::children($n) as $c) { $this->collectRefNames($c); }
    }

    private function refRoot(Node $n): void
    {
        $cur = $n;
        while (true) {
            $k = $cur->kind;
            if ($k === Node::KIND_LOAD_LOCAL) {
                $this->refName[self::asLoadLocal($cur)->name] = true;
                return;
            }
            if ($k === Node::KIND_PROPERTY_ACCESS) { $cur = self::asPropertyAccess($cur)->object; }
            elseif ($k === Node::KIND_ARRAY_ACCESS) { $cur = self::asArrayAccess($cur)->array; }
            else { return; }
        }
    }

    private function refArgRoots(Node $n): void
    {
        foreach ($this->byRefArgs($n, true) as $a) { $this->refRoot($a); }
    }

    /**
     * The arguments of call `$n` a by-ref parameter receives: by the callee's
     * mask when it resolves to a body, every argument of an unresolved
     * user callee, and — `$withBuiltins` — the first argument of the builtins
     * that walk an array in place.
     *
     * @return Node[]
     */
    public function byRefArgs(Node $n, bool $withBuiltins): array
    {
        $out = [];
        $k = $n->kind;
        $fn = '';
        $offset = 0;
        $args = [];
        $builtin = false;
        if ($k === Node::KIND_CALL) {
            $c = self::asCall($n);
            $fn = \ltrim($c->function, '\\');
            $args = $c->args;
            $builtin = true;
        } elseif ($k === Node::KIND_STATIC_CALL) {
            $sc = self::asStaticCall($n);
            $fn = $this->resolveMethodFn($sc->class, $sc->method);
            $args = $sc->args;
        } elseif ($k === Node::KIND_NEW_OBJ) {
            $no = self::asNewObj($n);
            $fn = $this->resolveMethodFn($no->class, '__construct');
            $args = $no->args;
            $offset = 1;
        } elseif ($k === Node::KIND_METHOD_CALL) {
            $mc = self::asMethodCall($n);
            $recv = $mc->object->type->class ?? '';
            $fn = $recv === '' ? '' : $this->resolveMethodFn($recv, $mc->method);
            $args = $mc->args;
            $offset = 1;
        } elseif ($k === Node::KIND_INVOKE) {
            $iv = self::asInvoke($n);
            $fn = $iv->callee->type->class ?? '';
            $args = $iv->args;
            $offset = $this->closureCaptureCount[$fn] ?? 0;
        } else {
            return $out;
        }
        if (!isset($this->refMasks[$fn])) {
            if ($builtin) {
                if ($withBuiltins && \count($args) > 0 && ($fn === 'current' || $fn === 'pos' || $fn === 'key'
                    || $fn === 'next' || $fn === 'prev' || $fn === 'reset' || $fn === 'end'
                    || $fn === 'array_pop' || $fn === 'array_shift' || $fn === 'array_unshift')) {
                    $out[] = $args[0];
                }
                return $out;
            }
            foreach ($args as $a) { $out[] = $a; }
            return $out;
        }
        $mask = $this->refMasks[$fn];
        $cnt = \count($mask);
        $tail = $this->refVariadic[$fn] ?? false;
        $i = 0;
        foreach ($args as $a) {
            $p = $i + $offset;
            $byRef = $p < $cnt ? $mask[$p] : false;
            if (!$byRef && $tail && $p >= $cnt - 1) { $byRef = true; }
            if ($byRef) { $out[] = $a; }
            $i = $i + 1;
        }
        return $out;
    }

    private function resolveMethodFn(string $class, string $method): string
    {
        $cur = $class;
        $guard = 0;
        while ($cur !== '' && $guard < 64) {
            $cand = $cur . '__' . $method;
            if (isset($this->refMasks[$cand])) { return $cand; }
            $cur = isset($this->classes[$cur]) ? $this->classes[$cur]->parent : '';
            $guard = $guard + 1;
        }
        return '';
    }

    private static function asStoreLocal(Node $n): StoreLocal { return $n; }
    private static function asLoadLocal(Node $n): \Compile\Mir\LoadLocal { return $n; }
    private static function asForeach(Node $n): \Compile\Mir\Foreach_ { return $n; }
    private static function asStaticLocalDecl(Node $n): \Compile\Mir\StaticLocalDecl_ { return $n; }
    private static function asRefAlias(Node $n): \Compile\Mir\RefAlias_ { return $n; }
    private static function asRefBind(Node $n): \Compile\Mir\RefBind_ { return $n; }
    private static function asRefAddr(Node $n): \Compile\Mir\RefAddr_ { return $n; }
    private static function asRefCell(Node $n): \Compile\Mir\RefCell_ { return $n; }
    private static function asClosure(Node $n): \Compile\Mir\Closure_ { return $n; }
    private static function asPropertyAccess(Node $n): \Compile\Mir\PropertyAccess_ { return $n; }
    private static function asArrayAccess(Node $n): \Compile\Mir\ArrayAccess_ { return $n; }
    private static function asCall(Node $n): \Compile\Mir\Call { return $n; }
    private static function asStaticCall(Node $n): \Compile\Mir\StaticCall_ { return $n; }
    private static function asNewObj(Node $n): \Compile\Mir\NewObj { return $n; }
    private static function asMethodCall(Node $n): \Compile\Mir\MethodCall_ { return $n; }
    private static function asInvoke(Node $n): \Compile\Mir\Invoke_ { return $n; }
}
