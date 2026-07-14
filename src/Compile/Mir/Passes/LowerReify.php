<?php

namespace Compile\Mir\Passes;

use Compile\Mir\FunctionDef;
use Compile\Mir\Module;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\StoreLocal;
use Compile\Mir\Type;

/**
 * Reified generic classes — `Box<float>` gets its OWN class, with float
 * properties and float-typed method bodies, instead of one erased body whose
 * `T` values ride as tagged cells.
 *
 * Why a class per binding, and not just specialized methods: a property's
 * element type lives on the ClassDef, and the rc release walker reads the
 * types from THERE to free an instance. Specialized methods over a shared
 * ClassDef would store raw doubles into a slot the walker still frees as a
 * cell. Layout-per-binding means class-per-binding — reification.
 *
 * The specialization keeps PHP's answers because it is lowered as a SUBCLASS
 * of its origin: `Box$of$float` has `parent = 'Box'`, so `instanceof Box`,
 * `catch (Box)` and virtual dispatch (all of which walk the parent chain over
 * a compile-time class-id set) already see it; and it carries
 * {@see \Compile\Mir\ClassDef::$displayName} = `Box`, which is what
 * `get_class()` / `::class` / var_dump print. The parent edge is sound for
 * LAYOUT too: the specialization is built from the same decl, so its property
 * names and order — and therefore every offset — match the origin's exactly.
 *
 * A binding is only ever written in a docblock (`@var Box<float> $b`), so the
 * whole program's docblocks are pre-scanned up front ({@see reifyPreScan}).
 * That happens before any body is lowered, so a spec class is in the class
 * table by the time a body mentions it — no lazy build, no reentrancy into
 * buildClassDef.
 *
 * A trait on the one {@see LowerFromAst} host.
 */
trait LowerReify
{
    /** How many specializations one generic class may have before the rest fall
     *  back to the erased body. Mirrors Monomorphize's cap: past this, code size
     *  outgrows what the boxing saves. */
    private const REIFY_CAP = 8;

    /**
     * Every `Cls<Args>` binding written anywhere in the program's docblocks,
     * turned into a specialized class. Runs once, right after the class
     * pre-pass has built the ordinary ClassDefs (a spec is lowered from the
     * origin's decl, so the origin's parents must already exist).
     */
    private function reifyPreScan(Module $module): void
    {
        foreach ($this->program->docComments as $doc) {
            foreach ($this->genericUsesIn($doc) as $use) {
                $this->reifyClass($use, $module);
            }
        }
    }

    /**
     * The `Name<...>` spellings inside one docblock, outermost only. Hand-rolled
     * (the compiler has no PCRE): walk to a `<`, take the identifier before it,
     * then match to the balanced `>`.
     *
     * @return string[] e.g. `['Box<float>', 'Pair<int, Tag>']`
     */
    private function genericUsesIn(string $doc): array
    {
        $out = [];
        $n = \strlen($doc);
        $i = 0;
        while ($i < $n) {
            if (\substr($doc, $i, 1) !== '<') { $i = $i + 1; continue; }
            // Identifier immediately before the `<`.
            $s = $i;
            while ($s > 0 && $this->isIdentChar(\substr($doc, $s - 1, 1))) { $s = $s - 1; }
            if ($s === $i) { $i = $i + 1; continue; }
            // Balanced close.
            $depth = 0;
            $j = $i;
            $end = -1;
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c === '<') { $depth = $depth + 1; }
                if ($c === '>') {
                    $depth = $depth - 1;
                    if ($depth === 0) { $end = $j; break; }
                }
                // A docblock line ends before the type does — malformed; give up.
                if ($c === "\n") { break; }
                $j = $j + 1;
            }
            if ($end < 0) { $i = $i + 1; continue; }
            $out[] = \substr($doc, $s, $end - $s + 1);
            $i = $end + 1;
        }
        return $out;
    }

    private function isIdentChar(string $c): bool
    {
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')
            || ($c >= '0' && $c <= '9') || $c === '_' || $c === '\\';
    }

    /**
     * Build (once) the specialized class for one `Cls<Args>` spelling, and
     * return its name — or '' when the binding is not reifiable, in which case
     * the use site keeps today's erased `obj<Cls>` + typeArgs behaviour.
     *
     * Not reifiable: a non-class (`array<…>`, `Generator<…>`, a generic trait),
     * an arity mismatch, an argument that is not a concrete representation
     * (a type variable, `mixed`, an erased array), or a class already at
     * {@see REIFY_CAP} specializations.
     */
    private function reifyClass(string $use, Module $module): string
    {
        $lt = \strpos($use, '<');
        if ($lt === false || $lt <= 0) { return ''; }
        $origin = $this->reifyResolveClass(\ltrim(\substr($use, 0, $lt), '?\\'));
        if ($origin === '') { return ''; }
        $decl = $this->classDecls[$origin] ?? null;
        if ($decl === null) { return ''; }
        if ($this->classDeclKind($decl) !== 'class') { return ''; }
        // A spec is never itself generic — guards a `Box<float>` written inside
        // Box's own docblocks from recursing.
        if (isset($this->reifyOrigin[$origin])) { return ''; }
        $params = $this->classTypeParams($origin);
        if ($params === []) { return ''; }
        $inner = \substr($use, $lt + 1, \strlen($use) - $lt - 2);
        $args = $this->lowerTypeArgs($inner);
        if (\count($args) !== \count($params)) { return ''; }
        $spec = $this->reifySpecName($origin, $args);
        if ($spec === '') { return ''; }
        if (isset($this->classTable[$spec])) { return $spec; }
        if ($this->hasExposedTypeParamProp($decl)) { return ''; }
        $count = 0;
        foreach ($this->reifyOrigin as $so => $o) {
            if ($o === $origin) { $count = $count + 1; }
        }
        if ($count >= self::REIFY_CAP) { return ''; }
        // The substitution the spec's members lower under: `T` → `float`.
        // lowerTypeHint consults it BEFORE `T` can become a type variable, so
        // every property, param and return of the copy comes out concrete.
        $subst = [];
        $i = 0;
        foreach ($params as $p) {
            $subst[$p] = $args[$i];
            $i = $i + 1;
        }
        $this->reifyOrigin[$spec] = $origin;
        $this->reifySubst[$spec] = $subst;
        // A generic PARENT holds the members: `/** @extends Base<T> */ class Bag
        // extends Base {}` declares nothing itself. Specializing only Bag would
        // inherit Base's ERASED layout and its erased `get`, and the binding —
        // which used to be recovered by climbing the chain at the call site —
        // would now be gone (the spec carries no typeArgs). So reify the parent
        // under the re-mapped arguments and extend THAT.
        $sdecl = $this->respecClassDecl($decl, $spec, $this->reifiedParent($origin, $subst, $module));
        $this->classDecls[$spec] = $sdecl;
        $this->knownClassNames[$spec] = true;
        $cd = $this->buildClassDef($sdecl, $this->stableClassId($spec));
        // With no specialized parent to extend, the ORIGIN becomes the parent —
        // set AFTER the layout is built, or buildClassDef would prepend the
        // origin's own (erased) properties on top of the specialized ones. Where
        // there IS a specialized parent, that edge is already correct and the
        // link back to the origin rides on `originClass`, which classIsA /
        // descendant enumeration / catch all follow.
        if ($cd->parent === '') { $cd->parent = $origin; }
        $cd->originClass = $origin;
        $cd->displayName = $origin;
        // A spec is NOT generic: its parameters are gone, substituted away. Left
        // in place they would make a call site try to re-bind an already-bound
        // body (genericReturnType) and re-erase a concrete return to a cell.
        $cd->typeParams = [];
        $cd->genericReturns = [];
        $this->classTable[$spec] = $cd;
        $module->addClass($cd);
        $this->reifyMethodQueue[$spec] = $sdecl;
        return $spec;
    }

    /**
     * The spec class name for a binding, or '' when an argument has no concrete
     * representation. Pure — {@see reifyClass} builds under this name, and
     * {@see lowerTypeHint} looks the built class up by it.
     *
     * @param Type[] $args
     */
    private function reifySpecName(string $origin, array $args): string
    {
        if ($args === []) { return ''; }
        $key = '';
        foreach ($args as $a) {
            $k = $this->reifyTypeKey($a);
            if ($k === '') { return ''; }
            $key = $key === '' ? $k : $key . '__' . $k;
        }
        // A valid PHP IDENTIFIER, not just a valid LLVM symbol: the synthesized
        // var_dump object-dumper is generated as PHP SOURCE naming every class,
        // and is then parsed. (Monomorphize's `$mono$` separator is safe only
        // because it names functions, which are never re-parsed.)
        return $origin . '__of__' . $key;
    }

    /**
     * The already-built specialization for a binding, or '' if there is none —
     * the use site then keeps the erased `obj<Cls>` + typeArgs behaviour.
     *
     * @param Type[] $args
     */
    private function reifiedClassFor(string $origin, array $args): string
    {
        $spec = $this->reifySpecName($origin, $args);
        if ($spec === '') { return ''; }
        return isset($this->classTable[$spec]) ? $spec : '';
    }

    /** A docblock's class name → the class it names. The docblocks are scanned
     *  flat (no file context), so only an FQN or an unambiguous short name
     *  resolves; anything else simply stays erased. */
    private function reifyResolveClass(string $name): string
    {
        if ($name === '') { return ''; }
        if (isset($this->classDecls[$name])) { return $name; }
        if (\strpos($name, '\\') === false
            && isset($this->shortClassFqn[$name])
            && !isset($this->shortClassAmbiguous[$name])) {
            return $this->shortClassFqn[$name];
        }
        return '';
    }

    /**
     * Whether the class exposes a property whose type is a type parameter to code
     * OUTSIDE it — a `public T $value` / `public array $items` of `T[]`.
     *
     * Such a class cannot be reified. A method reached through the erased `Box`
     * gets an erased thunk to adapt the representation; a FIELD read has no such
     * hook — `$b->value` on an `obj<Box>` receiver reads the slot at the origin's
     * (cell) type straight out of the object, and a specialization holds a raw
     * double there. The container idiom keeps its storage private, which is why
     * this costs nothing in practice; anything else stays erased, exactly as it
     * is today.
     */
    private function hasExposedTypeParamProp(\Parser\Ast\ClassDecl $decl): bool
    {
        $saved = $this->currentTypeParams;
        $this->currentTypeParams = $this->docTemplates($decl->docComment);
        $exposed = false;
        foreach ($decl->properties as $prop) {
            if ($prop->isStatic) { continue; }
            if ($this->propVisibility($prop) !== 'public') { continue; }
            $doc = $this->docTagType($prop->docComment, '@var', '');
            if ($this->lowerTypeHint($this->effectiveHint($prop->typeHint, $doc))->hasTypeVar()) {
                $exposed = true;
            }
        }
        foreach ($decl->methods as $m) {
            if ($m->name !== '__construct') { continue; }
            foreach ($m->params as $p) {
                if ($p->promoted !== 'public') { continue; }
                $doc = $this->docTagType($m->docComment, '@param', $p->name);
                if ($this->lowerTypeHint($this->effectiveHint($p->typeHint, $doc))->hasTypeVar()) {
                    $exposed = true;
                }
            }
        }
        $this->currentTypeParams = $saved;
        return $exposed;
    }

    /** Read visibility through a typed param (T5: an untyped `$prop->visibility`
     *  read off a foreach element resolves the wrong field offset under self-host). */
    private function propVisibility(\Parser\Ast\PropertyDecl $prop): string
    {
        return $prop->visibility;
    }

    /** Whether this declaration is a specialization's copy (read through a typed
     *  param — T5 offset). */
    private function isReifiedDecl(\Parser\Ast\ClassDecl $decl): bool
    {
        return isset($this->reifySubst[$decl->name]);
    }

    /** The `@template` parameters of an already-registered class. @return string[] */
    private function classTypeParams(string $cls): array
    {
        $cd = $this->classTable[$cls] ?? null;
        if ($cd === null) { return []; }
        return $cd->typeParams;
    }

    /** Read a decl's kind through a typed param (T5: an untyped `$decl->kind`
     *  read off an assoc value resolves the wrong offset under self-host). */
    private function classDeclKind(\Parser\Ast\ClassDecl $decl): string
    {
        return $decl->kind;
    }

    /**
     * The specialization of the origin's generic PARENT, under the arguments the
     * origin passes it (`@extends Base<T>` + `T = float` → `Base<float>`), or ''
     * when the parent is not generic (or could not be reified — the child then
     * simply keeps the erased parent, exactly as today).
     *
     * @param array<string, Type> $subst
     */
    private function reifiedParent(string $origin, array $subst, Module $module): string
    {
        $cd = $this->classTable[$origin] ?? null;
        if ($cd === null || $cd->parent === '' || $cd->parentTypeArgs === []) { return ''; }
        $pcd = $this->classTable[$cd->parent] ?? null;
        if ($pcd === null || $pcd->typeParams === []) { return ''; }
        // The arguments are written in terms of the CHILD's parameters (`Base<T>`);
        // the child's own binding turns them concrete.
        $pargs = [];
        foreach ($cd->parentTypeArgs as $pa) {
            $pargs[] = $pa->substitute($subst)->eraseTypeVars();
        }
        $pspec = $this->reifySpecName($cd->parent, $pargs);
        if ($pspec === '') { return ''; }
        if (isset($this->classTable[$pspec])) { return $pspec; }
        $pdecl = $this->classDecls[$cd->parent] ?? null;
        if ($pdecl === null) { return ''; }
        return $this->reifyClass($cd->parent . '<' . $this->reifyArgsSpelling($pargs) . '>', $module);
    }

    /** Spell a bound argument list back out so it can go through the one entry
     *  point that builds a specialization. @param Type[] $args */
    private function reifyArgsSpelling(array $args): string
    {
        $out = '';
        foreach ($args as $a) {
            $s = $this->typeSpelling($a);
            if ($s === '') { return ''; }
            $out = $out === '' ? $s : $out . ', ' . $s;
        }
        return $out;
    }

    /** A type as a hint the lowerer can read back (`float`, `\App\Tag`). */
    private function typeSpelling(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_INT)    { return 'int'; }
        if ($k === Type::KIND_FLOAT)  { return 'float'; }
        if ($k === Type::KIND_BOOL)   { return 'bool'; }
        if ($k === Type::KIND_STRING) { return 'string'; }
        if ($k === Type::KIND_OBJ)    { return '\\' . ($t->class ?? ''); }
        return '';
    }

    /** The same class declaration under a different name — the specialization's
     *  source. Every member is shared with the origin; only the substitution
     *  (keyed on the new name) makes the lowering differ. `$parent`, when given,
     *  re-points `extends` at the specialization of a generic base. */
    private function respecClassDecl(\Parser\Ast\ClassDecl $d, string $name, string $parent = ''): \Parser\Ast\ClassDecl
    {
        return new \Parser\Ast\ClassDecl(
            $d->kind,
            $name,
            $parent !== '' ? [$parent] : $d->extends,
            $d->implements,
            $d->attributes,
            $d->properties,
            $d->methods,
            $d->consts,
            $d->cases,
            $d->isFinal,
            $d->isAbstract,
            $d->isReadonly,
            $d->enumBackingType,
            $d->span,
            $d->uses,
            $d->traitAdaptations,
            $d->docComment,
            $d->useDocs,
        );
    }

    /**
     * A binding argument's key in the spec class name — and the gate on whether
     * it can be reified at all. Only types with a CONCRETE representation
     * qualify; a cell / unknown / type variable is exactly what erasure already
     * does, so specializing on it buys nothing and risks a wrong layout.
     */
    private function reifyTypeKey(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_INT)    { return 'int'; }
        if ($k === Type::KIND_FLOAT)  { return 'float'; }
        if ($k === Type::KIND_BOOL)   { return 'bool'; }
        if ($k === Type::KIND_STRING) { return 'string'; }
        if ($k === Type::KIND_OBJ) {
            $c = $t->class ?? '';
            if ($c === '') { return ''; }
            // An enum is an ordinal, not a heap object — it has no rc header, so a
            // specialized slot holding one must not be walked as an object.
            if (isset($this->enumTable[$c])) { return ''; }
            return 'o_' . $this->sanitizeSym($c);
        }
        return '';
    }

    /** Lower the methods of every specialized class. Runs after the ordinary
     *  classes' methods, and re-drains: lowering a spec's body can mention a
     *  binding no docblock scanned so far did. */
    private function lowerReifiedMethods(Module $module): void
    {
        $guard = 0;
        $done = [];
        while ($this->reifyMethodQueue !== [] && $guard < 64) {
            $guard = $guard + 1;
            $queue = $this->reifyMethodQueue;
            $this->reifyMethodQueue = [];
            foreach ($queue as $spec => $sdecl) {
                $this->lowerClassMethods($sdecl, $module);
                $done[$spec] = true;
            }
        }
        foreach ($done as $spec => $_) {
            $this->emitErasedThunks($spec, $module);
        }
    }

    /**
     * The ERASED entry points of a specialized class — what makes a bare `Box $b`
     * keep working.
     *
     * A specialized `Box$of$float::get` returns a RAW double and `Box$of$string::get`
     * a RAW pointer. Both are i64 at the ABI, and a caller that only knows the
     * erased `Box` cannot tell them apart — it reads the result as a tagged cell
     * and gets garbage (a raw 2^50 int comes back as 0). So each specialized method
     * gets a SECOND entry with the ORIGIN's (erased) signature, which the dispatch
     * switch calls whenever the receiver's static type is the bare origin:
     *
     *     Box$of$float__get$erased(cell $this, int $i): cell   // boxes the double
     *     Box$of$float__get       (obj  $this, int $i): float  // the raw entry
     *
     * This is Rust's `dyn Trait` vtable and C#'s reified generics: one entry per
     * ABI, chosen per call site. The thunk is synthesized as ORDINARY MIR — a call
     * to the raw entry, in a function declared with the erased types — so the
     * compiler's existing cell↔raw coercions (unboxCellArg on the way in, the
     * return boxing on the way out) generate it. No hand-written IR.
     */
    private function emitErasedThunks(string $spec, Module $module): void
    {
        $origin = $this->reifyOrigin[$spec] ?? '';
        if ($origin === '') { return; }
        foreach ($this->chainMethodNames($spec) as $m => $_) {
            if ($m === '__construct') { continue; }
            // Resolve through BOTH chains: the specialized method may be
            // inherited from a specialized parent (`Bag<float>` declares nothing
            // — `get` lives on `Base<float>`), and the erased signature it must
            // present likewise comes from wherever the ORIGIN declares it.
            $base = $this->chainMethodFn($module, $origin, $m);
            $spun = $this->chainMethodFn($module, $spec, $m);
            if ($base === null || $spun === null) { continue; }
            // Nothing was erased away — the raw entry already IS the erased one.
            if (!$this->sigDiffers($base, $spun)) { continue; }
            $module->addFunction($this->erasedThunkFor($spec, $m, $base));
        }
    }

    /** Every method name reachable on a class, including inherited ones.
     *  @return array<string, bool> */
    private function chainMethodNames(string $class): array
    {
        $out = [];
        $c = $class;
        $guard = 0;
        while ($c !== '' && $guard < 32) {
            $guard = $guard + 1;
            $cd = $this->classTable[$c] ?? null;
            if ($cd === null) { break; }
            foreach ($cd->methodNames as $m => $_) { $out[$m] = true; }
            $c = $cd->parent;
        }
        return $out;
    }

    /** The lowered function a class resolves for `$method`, walking its parents. */
    private function chainMethodFn(Module $module, string $class, string $method): ?FunctionDef
    {
        $c = $class;
        $guard = 0;
        while ($c !== '' && $guard < 32) {
            $guard = $guard + 1;
            $fn = $this->findFunctionDef($module, $c . '__' . $method);
            if ($fn !== null) { return $fn; }
            $cd = $this->classTable[$c] ?? null;
            if ($cd === null) { return null; }
            $c = $cd->parent;
        }
        return null;
    }

    /** Whether specializing changed the ABI: a param or the return of the spec's
     *  method has a different representation than the origin's erased one. When
     *  nothing moved, the raw entry is already callable from an erased site. */
    private function sigDiffers(FunctionDef $base, FunctionDef $spec): bool
    {
        if ($base->returnType->kind !== $spec->returnType->kind) { return true; }
        $bp = $base->params;
        $sp = $spec->params;
        if (\count($bp) !== \count($sp)) { return true; }
        $i = 0;
        foreach ($bp as $p) {
            $s = $sp[$i];
            $i = $i + 1;
            if ($p->type->kind !== $s->type->kind) { return true; }
        }
        return false;
    }

    /**
     * `Spec__m$erased`: the origin's erased signature, a monomorphic call to the
     * spec's raw `m`, and the result returned through the erased return type.
     * `$this` keeps the SPEC's type so the inner call resolves directly (no
     * dispatch switch, no recursion back into this thunk).
     */
    private function erasedThunkFor(string $spec, string $method, FunctionDef $base): FunctionDef
    {
        $params = [];
        $args = [];
        $i = 0;
        foreach ($base->params as $p) {
            $t = $i === 0 ? Type::obj($spec) : $p->type;
            $params[] = new \Compile\Mir\Param($p->name, $t, $p->byRef, $p->variadic);
            if ($i > 0) { $args[] = new \Compile\Mir\LoadLocal($p->name, $t); }
            $i = $i + 1;
        }
        $recv = new \Compile\Mir\LoadLocal('this', Type::obj($spec));
        $call = new \Compile\Mir\MethodCall_($recv, $method, $args, $base->returnType);
        $stmts = [];
        if ($base->returnType->kind === Type::KIND_VOID) {
            $stmts[] = $call;
            $stmts[] = new \Compile\Mir\Return_(null, Type::void());
        } else {
            $stmts[] = new \Compile\Mir\Return_($call, Type::void());
        }
        return new FunctionDef(
            name: $spec . '__' . $method . '$erased',
            params: $params,
            returnType: $base->returnType,
            body: new \Compile\Mir\Block($stmts, Type::void()),
        );
    }

    /**
     * A PROPERTY that holds a bound container — `/** @var Box<float> *\/ private
     * Box $box;` — typed as the specialization, so `$this->box->get(0)` is a
     * direct raw call instead of an erased dispatch through a thunk.
     *
     * The same rule as everywhere else applies: a slot may only name a spec if
     * the compiler also OWNS what goes into it. Here that is decided from the
     * lowered MIR rather than guessed from the source — every store to the
     * property in the whole module must be a `new Box(...)`. Anything the pass
     * cannot attribute (a store through an untyped receiver, a dynamic
     * `$o->$name =`, a `clone with`) makes it give up and leave the property
     * erased, which is always correct.
     *
     * Runs last, when every body — including the specializations' — is lowered.
     */
    private function reifyProperties(Module $module): void
    {
        if ($this->reifyOrigin === []) { return; }
        foreach ($this->classTable as $cls => $cd) {
            // A specialization's own properties are already concrete.
            if ($cd->originClass !== '') { continue; }
            foreach ($cd->propertyTypes as $p => $pt) {
                if ($pt->kind !== Type::KIND_OBJ || $pt->typeArgs === []) { continue; }
                $spec = $this->reifiedClassFor($pt->class ?? '', $pt->typeArgs);
                if ($spec === '') { continue; }
                $stores = $this->attributableStores($module, $cls, $p, $pt->class ?? '');
                if ($stores === []) { continue; }
                $cd->propertyTypes[$p] = Type::obj($spec);
                foreach ($stores as $st) {
                    $st->value = new NewObj($spec, $this->storedNew($st)->args, Type::obj($spec));
                    $st->type = Type::obj($spec);
                }
            }
        }
    }

    /**
     * Every store to `$cls::$prop` in the module, but ONLY if all of them are a
     * `new $origin(...)` and every one of them can be attributed to a receiver of
     * a known class. Returns [] the moment anything is unaccounted for — the
     * property then stays erased.
     *
     * @return StoreProperty[]
     */
    private function attributableStores(Module $module, string $cls, string $prop, string $origin): array
    {
        $out = [];
        foreach ($module->functions as $fn) {
            // `$this` is still typed `unknown` here (InferTypes types it later), so
            // a store through it is attributed by the class that OWNS the body —
            // recorded when the method was lowered. Not by parsing the function
            // name: a specialization's name contains `__` as well, so `Box__` is a
            // prefix of `Box__of__float__add` too.
            $owner = $this->methodOwner[$fn->name] ?? '';
            foreach ($this->flattenNodes($fn->body) as $n) {
                // A dynamic write or a `clone with` could put anything in the slot
                // and is not worth modelling — give up on the whole property.
                if ($n instanceof \Compile\Mir\StoreDynProp_) { return []; }
                if ($n instanceof \Compile\Mir\Clone_) {
                    foreach ($n->withProps as $pair) {
                        if ($pair->name === $prop) { return []; }
                    }
                }
                if (!($n instanceof \Compile\Mir\StoreProperty)) { continue; }
                if ($n->property !== $prop) { continue; }
                $mine = $this->storeTargetsClass($n, $cls, $owner);
                // Not ours (a same-named property on an unrelated class).
                if ($mine === 0) { continue; }
                // Ours, or unattributable — an untyped receiver outside the class
                // could still BE the class, so it cannot be ruled out.
                if ($mine < 0) { return []; }
                $v = $n->value;
                if (!($v instanceof NewObj) || $v->class !== $origin) { return []; }
                $out[] = $n;
            }
        }
        return $out;
    }

    /**
     * Does this store write `$cls`'s slot? 1 = yes, 0 = no (someone else's
     * same-named property), -1 = cannot tell (the caller must give up).
     *
     * `$owner` is the class whose body the store sits in, or '' outside any
     * method — a `$this` store there is impossible, but an UNRECORDED owner is
     * not something to assume about, so it gives up.
     */
    private function storeTargetsClass(\Compile\Mir\StoreProperty $st, string $cls, string $owner): int
    {
        $obj = $st->object;
        if ($obj instanceof \Compile\Mir\LoadLocal && $obj->name === 'this') {
            if ($owner === '') { return -1; }
            return $owner === $cls ? 1 : 0;
        }
        if ($obj->type->kind === Type::KIND_OBJ) {
            return ($obj->type->class ?? '') === $cls ? 1 : 0;
        }
        return -1;
    }

    /** Read the NewObj a store carries through a typed local (T5 offset). */
    private function storedNew(\Compile\Mir\StoreProperty $st): NewObj
    {
        return $st->value;
    }

    /** Every node in a body, flattened via the structural child iterator.
     *  @return Node[] */
    private function flattenNodes(Node $root): array
    {
        $out = [];
        $stack = [$root];
        $guard = 0;
        while ($stack !== [] && $guard < 2000000) {
            $guard = $guard + 1;
            $n = \array_pop($stack);
            $out[] = $n;
            foreach (\Compile\Mir\Walk::children($n) as $c) { $stack[] = $c; }
        }
        return $out;
    }

    /**
     * The one property read reification cannot make sound: a type-parameter slot
     * read through the ERASED origin (`Box $other` inside Box's own scope, where
     * a private property is reachable) while the object is really a
     * specialization holding a raw double there.
     *
     * A method has an erased thunk to adapt the representation; a field read has
     * no hook to hang one on. {@see hasExposedTypeParamProp} already keeps the
     * class un-reified when such a property is visible from OUTSIDE, so what is
     * left is class-internal sibling access — and the fix is one word at the site:
     * write the binding (`Box<T> $other`) and the receiver resolves to the
     * specialization. Refuse to compile rather than emit a value read at the
     * wrong representation, which is silent and prints garbage.
     */
    private function checkErasedGenericPropRead(Node $obj, string $prop): void
    {
        if ($this->reifyOrigin === []) { return; }
        if ($obj->type->kind !== Type::KIND_OBJ) { return; }
        // `$this` is never the hazard: inside the ORIGIN's body every instance
        // really is erased (a specialization runs its OWN copy of the method,
        // where `$this` is the spec and the slot is read at its concrete type).
        if ($obj instanceof \Compile\Mir\LoadLocal && $obj->name === 'this') { return; }
        $cls = $obj->type->class ?? '';
        if ($cls === '' || !$this->hasSpecializations($cls)) { return; }
        // Only a reader in CLASS SCOPE can reach here: a type-parameter property
        // visible from outside keeps the class un-reified entirely
        // ({@see hasExposedTypeParamProp}), so a private/protected slot is all
        // that is left — reachable from the class itself or a subclass. Code
        // outside any class is either looking at a property it could not access,
        // or (like the synthesized var_dump dumper) has already narrowed the
        // receiver with `instanceof` and reads the specialization at its own
        // concrete types.
        if ($this->currentLowerClass === '') { return; }
        $cd = $this->classTable[$cls] ?? null;
        if ($cd === null) { return; }
        $pt = $cd->propertyTypes[$prop] ?? null;
        if ($pt === null || !$this->specChangesProp($cls, $prop, $pt)) { return; }
        throw new \RuntimeException(
            'generic property `' . $cls . '::$' . $prop . '` read through the erased `'
            . $cls . '` while `' . $cls . '` is also used with a binding; the '
            . 'representation of that slot depends on the binding. Annotate the '
            . 'receiver with one (e.g. `' . $cls . '<T>`).'
        );
    }

    /** Whether any specialization of `$cls` was built. */
    private function hasSpecializations(string $cls): bool
    {
        foreach ($this->reifyOrigin as $spec => $origin) {
            if ($origin === $cls) { return true; }
        }
        return false;
    }

    /** Whether specializing `$cls` moves `$prop` to a different representation
     *  than the erased `$pt` the reading site would assume. */
    private function specChangesProp(string $cls, string $prop, Type $pt): bool
    {
        foreach ($this->reifyOrigin as $spec => $origin) {
            if ($origin !== $cls) { continue; }
            $scd = $this->classTable[$spec] ?? null;
            if ($scd === null) { continue; }
            $st = $scd->propertyTypes[$prop] ?? null;
            if ($st === null) { continue; }
            if ($st->kind !== $pt->kind) { return true; }
            $se = $st->element;
            $pe = $pt->element;
            if ($se !== null && $pe !== null && $se->kind !== $pe->kind) { return true; }
        }
        return false;
    }

    private function findFunctionDef(Module $module, string $name): ?FunctionDef
    {
        foreach ($module->functions as $fn) {
            if ($fn->name === $name) { return $fn; }
        }
        return null;
    }

    /**
     * Point a `new Box()` at the specialization its target slot was declared to
     * hold: `/** @var Box<float> $b *\/ $b = new Box();`. The binding lives on
     * the STORE (the declared type), never on the `new` — PHP has no syntax for
     * it — so this is where the two meet.
     *
     * The ctor arg padding done during lowering stays valid: the specialization
     * is built from the same decl, so its constructor takes the same parameters.
     */
    private function reifiedNew(StoreLocal $store): void
    {
        $declared = $store->declaredType;
        if ($declared === null || $declared->kind !== Type::KIND_OBJ) { return; }
        if ($declared->typeArgs === []) { return; }
        $value = $store->value;
        // ONLY a `new` of the very class the binding names. Anything else — a
        // factory call, another local — hands us an object built elsewhere, and
        // an object built elsewhere is erased. The slot then stays erased too
        // (the declared type is left exactly as it was), which is correct: the
        // erased path is what it has always been.
        if (!($value instanceof NewObj)) { return; }
        $origin = $declared->class ?? '';
        if ($origin === '' || $value->class !== $origin) { return; }
        $spec = $this->reifiedClassFor($origin, $declared->typeArgs);
        if ($spec === '') { return; }
        $store->value = new NewObj($spec, $value->args, Type::obj($spec));
        $store->declaredType = Type::obj($spec);
    }
}
