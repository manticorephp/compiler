<?php

namespace Compile\Mir\Passes;

use Compile\Mir\DefinedLocals;
use Compile\Mir\FunctionDef;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\NullConst;
use Compile\Mir\Pass;
use Compile\Mir\StoreLocal;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * Define the locals whose ONLY definition is a by-reference argument position.
 *
 * Passing an UNDEFINED variable by reference is how php spells an
 * out-parameter: the call creates it as NULL and the callee writes through it.
 * Only *reading* an undefined variable warns. We used to refuse the program
 * outright —
 *
 *   MIR.verify: dangling local $collected read in __main but never defined
 *
 * — and the refusal was, perversely, the only thing keeping the program alive:
 * without a `StoreLocal` the local never gets a slot ({@see
 * EmitLlvmLocals::preallocateLocals} allocates on stores), so
 * `isByRefAddressable` says no, the by-ref arm at the call site is skipped, and
 * {@see EmitLlvmLocals::emitLoadLocal} hands the callee a literal `i64 0` to
 * store through.
 *
 * One synthesized entry init fixes the whole chain at once: `Verify` sees a
 * definition, `preallocateLocals` mints the alloca, the slot is genuinely
 * initialized, and the emitter's existing address path fires. Nothing else in
 * the pipeline changes.
 *
 * Placing the store at function ENTRY is sound precisely because the local has
 * no definition ANYWHERE in the function — there is nothing to clobber, and a
 * call inside a loop must not re-null the variable on every iteration.
 *
 * The value is NULL, not the parameter type's zero. {@see
 * LowerFromAst::collectRefOutInits} does use `[]` for an array out-param, and
 * is right to: `#[RefOut]` is an explicit promise that the callee always
 * writes, so the init is never observable. A bare `&$x` carries no such
 * promise — `function maybe(bool $do, ?array &$out) { if ($do) { $out = [1]; } }`
 * called with `false` leaves php's NULL, and `[]` would print `array(0) {}`.
 * The declared type still rides along on `declaredType` so the slot keeps the
 * callee's element repr instead of re-inferring to `unknown`.
 *
 * ⚠ A `MethodCall_` whose receiver class is not statically known is SKIPPED.
 * The emitter widens harder than this pass does ({@see
 * EmitLlvmObjects::emitMethodCall} falls back to scanning every class for a
 * declarer), so for an erased receiver the two disagree — but only in the safe
 * direction: the emitter finds a mask, this pass did not vivify, no slot
 * exists, and `Verify` still throws. A miss here is loud, never silent.
 */
final class VivifyRefArgs implements Pass
{
    public const NAME = 'vivify-ref-args';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferTypes::NAME]; }

    /** @var array<string, bool[]> mangled fn name → per-param by-ref mask */
    private array $refMasks = [];

    /** @var array<string, Type[]> mangled fn name → per-param declared type */
    private array $paramTypes = [];

    /** @var array<string, int> closure fn name → capture count */
    private array $closureCaptures = [];

    /** Bit `i` ⟺ some closure declares its call slot `i` by-ref
     *  ({@see \Compile\Mir\FunctionSignatures::closureRefUnion}). */
    private int $closureRefUnion = 0;

    /** @var array<string, string> method name → some declaring fn (interface receivers) */
    private array $anyDeclarer = [];

    /** @var array<string, string> class name → parent class name */
    private array $classParent = [];

    /** @var array<string, true> locals already defined in the current function */
    private array $defined = [];

    /** @var array<string, Type> candidate local → the callee's declared param type */
    private array $candidates = [];

    public function run(Module $module): Module
    {
        $this->refMasks = [];
        $this->paramTypes = [];
        foreach ($module->functions as $fn) {
            $mask = [];
            $ptypes = [];
            foreach ($fn->params as $p) {
                $mask[] = $p->byRef;
                // A declared `array` / `?array` lowers to KIND_UNKNOWN, which
                // rides RAW — a fresh slot typed that way cannot describe what
                // the callee wrote and reads back as denormal doubles. Give the
                // VIVIFIED slot a CELL: the one repr that carries both php's
                // NULL (the callee may skip the write) and whatever array shape
                // the callee builds, without committing to a key or element
                // repr up front. The call site then adapts through the existing
                // cell-lvalue/raw-param scratch-and-rebox path.
                //
                // ⛔ Retyping the PARAMETER itself to cell was tried and is
                // wrong: a closure's `use (&$x)` capture is also a by-ref
                // param, but it binds an ADDRESS rather than carrying a value,
                // and blanket-retyping broke the self-host build outright.
                // Only the caller's own slot is ours to choose.
                $ptypes[] = ($p->type->kind === Type::KIND_UNKNOWN)
                    ? Type::cell()
                    : $p->type;
            }
            $this->refMasks[$fn->name] = $mask;
            $this->paramTypes[$fn->name] = $ptypes;
        }
        // method name → SOME declaring function, for an interface-typed
        // receiver. Keyed off the FIRST `__` so `C____construct` reads back as
        // `__construct` rather than `construct`.
        $this->anyDeclarer = [];
        foreach ($this->refMasks as $fname => $unused) {
            $i = \strpos($fname, '__');
            if ($i === false) { continue; }
            $m = \substr($fname, $i + 2);
            if ($m === '' || isset($this->anyDeclarer[$m])) { continue; }
            $this->anyDeclarer[$m] = $fname;
        }
        $this->closureCaptures = $module->closureCaptures;
        $this->closureRefUnion = \Compile\Mir\FunctionSignatures::closureRefUnion(
            $module->functions, $module->closureCaptures);
        $this->classParent = [];
        foreach ($module->classes as $cname => $cd) {
            $this->classParent[$cname] = $cd->parent;
        }

        foreach ($module->functions as $fn) {
            $this->vivifyFunction($fn, $module);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function vivifyFunction(FunctionDef $fn, Module $module): void
    {
        $this->defined = DefinedLocals::collect($fn);
        $this->candidates = [];
        $this->walk($fn->body);
        if (\count($this->candidates) === 0) { return; }

        // A `global $x` name in __main writes through `@g_x`, not a stack slot
        // ({@see EmitLlvmModule}), so an init here would neither create the
        // slot the by-ref path needs nor mean what it says.
        $skip = [];
        if ($fn->name === '__main') {
            foreach ($module->globalVarNames as $gname) { $skip[$gname] = true; }
        }

        $inits = [];
        foreach ($this->candidates as $name => $declared) {
            if (isset($skip[$name])) { continue; }
            if (isset($this->defined[$name])) {
                // `$x = null; f($x);` is the same out-parameter idiom spelled
                // with an explicit initialiser, and it was broken the same way:
                // the slot types `null`, every read of it folds to NULL, and
                // the callee's write is invisible to the type system. Seed the
                // declared type onto that init — but ONLY when every definition
                // is a null, so a real value is never retyped out from under
                // its own stores.
                $this->retypeNullInits($fn->body, $name, $declared);
                continue;
            }
            $init = new StoreLocal($name, new NullConst(Type::null_()), $declared);
            $init->declaredType = $declared;
            $inits[] = $init;
        }
        if (\count($inits) === 0) { return; }
        $fn->body->stmts = \array_merge($inits, $fn->body->stmts);
    }

    /**
     * Seed `$declared` onto every `$name = null` init in the body, when that is
     * the ONLY way the local is written. Bails on the first store of anything
     * else — a local that carries a real value keeps the type its own stores
     * give it.
     */
    private function retypeNullInits(Node $body, string $name, Type $declared): void
    {
        $this->nullInits = [];
        $this->nullInitName = $name;
        $this->nullInitOk = true;
        $this->scanNullInits($body);
        if (!$this->nullInitOk || \count($this->nullInits) === 0) { return; }
        foreach ($this->nullInits as $sl) { $sl->declaredType = $declared; }
    }

    /** @var StoreLocal[] */
    private array $nullInits = [];
    private string $nullInitName = '';
    private bool $nullInitOk = true;

    private function scanNullInits(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $sl = $this->asStoreLocal($n);
            if ($sl->name === $this->nullInitName) {
                if ($sl->value->kind === Node::KIND_NULL_CONST) { $this->nullInits[] = $sl; }
                else { $this->nullInitOk = false; }
            }
        }
        // Any OTHER way of writing THIS local means it is not a plain
        // null-initialised out-var.
        $k = $n->kind;
        if ($k === Node::KIND_FOREACH) {
            $fe = $this->asForeach($n);
            if ($fe->valueVar === $this->nullInitName || $fe->keyVar === $this->nullInitName) {
                $this->nullInitOk = false;
            }
        } elseif ($k === Node::KIND_INCDEC) {
            if ($n->name === $this->nullInitName) { $this->nullInitOk = false; }
        } elseif ($k === Node::KIND_REF_ALIAS || $k === Node::KIND_REF_BIND
            || $k === Node::KIND_REF_ADDR) {
            if ($n->target === $this->nullInitName) { $this->nullInitOk = false; }
        } elseif ($k === Node::KIND_STATIC_LOCAL_DECL) {
            if ($n->name === $this->nullInitName) { $this->nullInitOk = false; }
        }
        foreach (Walk::children($n) as $c) { $this->scanNullInits($c); }
    }

    private function walk(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_CALL) {
            $c = $this->asCall($n);
            $this->scanArgs($c->function, $c->args, 0);
        } elseif ($k === Node::KIND_STATIC_CALL) {
            // A selfish instance call prepends `$this` at lowering, so the
            // static form indexes from 0 like a free call.
            $sc = $this->asStaticCall($n);
            $this->scanArgs($this->resolveMethodFn($sc->class, $sc->method), $sc->args, 0);
        } elseif ($k === Node::KIND_NEW_OBJ) {
            $no = $this->asNewObj($n);
            $this->scanArgs($no->class . '____construct', $no->args, 1);
        } elseif ($k === Node::KIND_METHOD_CALL) {
            $mc = $this->asMethodCall($n);
            $recv = $mc->object->type->class ?? '';
            $target = $recv === '' ? '' : $this->resolveMethodFn($recv, $mc->method);
            if ($target !== '') {
                $this->scanArgs($target, $mc->args, 1);
            } elseif ($recv !== '') {
                // Nothing in this closed world declares the method — the class
                // lives in a package a lower tier does not include, or the
                // extension is absent. Same reasoning as an unknown free
                // function: the call cannot succeed, so its arguments'
                // definedness is not a property worth refusing the file over.
                // `$container->resolveEnvPlaceholders($n, null, $usedEnvs)` in
                // symfony/cache is a T3 collaborator seen from T2.
                $this->vivifyBareLocals($mc->args);
            }
        } elseif ($k === Node::KIND_INVOKE) {
            $iv = $this->asInvoke($n);
            $cls = $iv->callee->type->class ?? '';
            if ($cls !== '' && isset($this->closureCaptures[$cls])) {
                $this->scanArgs($cls, $iv->args, $this->closureCaptures[$cls]);
            } elseif ($this->closureRefUnion !== 0 && $this->isErasedCallee($iv->callee)) {
                $this->scanErasedArgs($iv->args);
            }
        }
        foreach (Walk::children($n) as $c) { $this->walk($c); }
    }

    /**
     * Record every bare local sitting at a by-ref parameter of `$fnName`.
     * `$base` is the callee's parameter index of argument 0 — 1 where the
     * receiver occupies `params[0]`, the capture count for a closure.
     *
     * @param Node[] $args
     */
    private function scanArgs(string $fnName, array $args, int $base): void
    {
        if ($fnName === '') { return; }
        if (!isset($this->refMasks[$fnName])) {
            // A callee the program neither defines nor gets intrinsically — an
            // absent extension, `apcu_fetch($ids, $ok)` behind
            // `ApcuAdapter::isSupported()`. Its by-ref mask is unknowable, but
            // so is everything else about it: the call cannot succeed, and php
            // fails AT it rather than refusing the file. Vivify its bare locals
            // so a guarded, never-taken branch does not cost the whole program.
            // A typo'd argument to a real builtin still fails Verify — that is
            // what the isKnownFunction check preserves.
            if (\Analyze\Builtins::isKnownFunction($fnName)) { return; }
            $this->vivifyBareLocals($args);
            return;
        }
        $mask = $this->refMasks[$fnName];
        $ptypes = $this->paramTypes[$fnName];
        $ai = 0;
        foreach ($args as $a) {
            $pi = $base + $ai;
            $ai = $ai + 1;
            if (!($mask[$pi] ?? false)) { continue; }
            if ($a->kind !== Node::KIND_LOAD_LOCAL) { continue; }
            $ll = $this->asLoadLocal($a);
            if (isset($this->candidates[$ll->name])) { continue; }
            $this->candidates[$ll->name] = $ptypes[$pi] ?? Type::unknown();
        }
    }

    /**
     * A callee with no statically known `__closure_N` — a `\Closure`-typed
     * property, a `callable` parameter, an element out of a `vec[cell]`. The
     * emitter recovers such a callee's by-ref mask at run time from its fn ptr
     * ({@see EmitLlvmCalls::closureRefMaskChain}); this pass has to agree that
     * the argument MIGHT be an out-parameter, or `Verify` rejects the program
     * before that machinery ever runs.
     *
     * A string-typed callee is a function NAME dispatch, and a `DynProp_` one a
     * dynamic method — both rebuild real call nodes per arm at emit time, so
     * neither is claimed here. Missing one stays loud.
     */
    private function isErasedCallee(Node $callee): bool
    {
        if ($callee->kind === Node::KIND_DYN_PROP) { return false; }
        $k = $callee->type->kind;
        if ($k === Type::KIND_STRING) { return false; }
        return $k === Type::KIND_CELL || $k === Type::KIND_UNKNOWN || $k === Type::KIND_CLOSURE;
    }

    /**
     * Bare locals at a call slot SOME closure in the module declares by-ref.
     * The union is the gate: it is 0 for any program that never writes a by-ref
     * closure parameter, so a mistyped variable there still fails `Verify`
     * rather than quietly becoming NULL.
     *
     * @param Node[] $args
     */
    private function scanErasedArgs(array $args): void
    {
        $ai = 0;
        foreach ($args as $a) {
            $slot = $ai;
            $ai = $ai + 1;
            if ($slot > 62 || ($this->closureRefUnion & (1 << $slot)) === 0) { continue; }
            if ($a->kind !== Node::KIND_LOAD_LOCAL) { continue; }
            $ll = $this->asLoadLocal($a);
            if (isset($this->candidates[$ll->name])) { continue; }
            // CELL, not unknown: the callee is only known at run time, so the
            // slot has to be self-describing for the caller to read back what
            // was written — and it is a cell that the invoke's scratch-and-
            // rebox path keys on ({@see EmitLlvmCalls::emitDynByRefArg}).
            $this->candidates[$ll->name] = Type::cell();
        }
    }

    /**
     * Treat every bare local argument as possibly an out-parameter. Only for a
     * callee NOTHING in the program declares — the call is already an error, so
     * this trades a refusal we cannot justify for the failure php actually has.
     *
     * @param Node[] $args
     */
    private function vivifyBareLocals(array $args): void
    {
        foreach ($args as $a) {
            if ($a->kind !== Node::KIND_LOAD_LOCAL) { continue; }
            $ll = $this->asLoadLocal($a);
            if (isset($this->candidates[$ll->name])) { continue; }
            $this->candidates[$ll->name] = Type::cell();
        }
    }

    /**
     * The module function name implementing `$class::$method`, walking the
     * parent chain — methods live in the function list as `Class__method` and
     * an inherited one is only present under its DECLARING class. '' when no
     * ancestor declares it (an interface, a builtin, an erased receiver).
     */
    private function resolveMethodFn(string $class, string $method): string
    {
        $cur = $class;
        $guard = 0;
        while ($cur !== '' && $guard < 64) {
            $cand = $cur . '__' . $method;
            if (isset($this->refMasks[$cand])) { return $cand; }
            $cur = $this->classParent[$cur] ?? '';
            $guard = $guard + 1;
        }
        // No ancestor declares it — an INTERFACE receiver, which is how a real
        // application spells a collaborator (`?MarshallerInterface $marshaller`
        // then `$this->marshaller->marshall($values, $failed)`). The
        // implementation lives on some class that implements it, and php
        // requires an override's by-ref-ness to match the declaration, so ANY
        // declarer answers the question. This mirrors the widening
        // {@see EmitLlvmObjects::emitMethodCall} already does when it scans
        // every class for a declarer.
        return $this->anyDeclarer[$method] ?? '';
    }

    // Typed reads — a base-typed `$n` resolves fields by OFFSET under
    // self-host and would pick the wrong slot.
    private function asCall(Node $n): \Compile\Mir\Call { return $n; }
    private function asStaticCall(Node $n): \Compile\Mir\StaticCall_ { return $n; }
    private function asNewObj(Node $n): \Compile\Mir\NewObj { return $n; }
    private function asMethodCall(Node $n): \Compile\Mir\MethodCall_ { return $n; }
    private function asInvoke(Node $n): \Compile\Mir\Invoke_ { return $n; }
    private function asLoadLocal(Node $n): \Compile\Mir\LoadLocal { return $n; }
    private function asStoreLocal(Node $n): StoreLocal { return $n; }
    private function asForeach(Node $n): \Compile\Mir\Foreach_ { return $n; }
}
