<?php

namespace Compile\Mir;

/**
 * MIR type lattice. Inspired by HHIR — scalar primitives plus
 * vec / assoc / object-by-class. Tagged union ({@see KIND_CELL})
 * carries the set of atoms it can hold so future passes can
 * narrow the refinement.
 *
 * Flat shape, `kind` discriminant + optional payload fields. Self-
 * host pre-scan narrows on `kind` cheaply; deep subclass trees
 * would push it past current limits.
 */
final class Type
{
    public const KIND_VOID    = 'void';
    public const KIND_NULL    = 'null';
    public const KIND_BOOL    = 'bool';
    public const KIND_INT     = 'int';
    public const KIND_FLOAT   = 'float';
    public const KIND_STRING  = 'string';
    /**
     * ONE array kind (Stage 4 unified PhpArray). A "vec" is an array
     * with no explicit key type ({@see $key} null — implicit int keys);
     * an "assoc" is an array whose key type is string. The packed/hashed
     * split is a runtime detail, not a static kind. Use {@see isVec} /
     * {@see isAssoc} / {@see isArray} to discriminate.
     */
    public const KIND_ARRAY   = 'array';
    public const KIND_OBJ     = 'obj';
    public const KIND_CLOSURE = 'closure';
    public const KIND_CELL    = 'cell';
    public const KIND_UNKNOWN = 'unknown';
    /**
     * A STATIC union of object classes (`B|C` from `cond ? new B : new C`, a
     * heterogeneous object array, or a `subclassPropType`). {@see $atoms} holds
     * the member `obj<…>` types. Same representation as a bare object pointer
     * (all arms are ptr) — NO boxing; the method-call site dispatches on the
     * runtime class_id over the atoms' descendants. A union it can't handle
     * degrades to {@see KIND_UNKNOWN} (raw i64) at every other consumer, so the
     * kind is inert until a consumer opts in. Non-object unions are NOT formed
     * (they stay `cell`/`unknown`) — this is the object-polymorphism lattice.
     */
    public const KIND_UNION   = 'union';

    /**
     * A docblock TYPE VARIABLE — the `T` of a `@template T` class/interface.
     * {@see $class} holds the variable's name.
     *
     * It exists only in the declaration of a generic class: inside the shared
     * compiled body a `T` value travels in its RAW representation (a double's
     * bits, a string/object pointer, an int), exactly as an erased value does
     * today — so one compiled body serves every instantiation. What generics add
     * is that the CALL SITE recovers the binding ({@see $typeArgs}) and types the
     * result concretely, instead of falling back to `unknown` and picking the
     * integer path for `+` / `.` / echo (which silently printed a pointer or a
     * double's bit pattern).
     *
     * An UNBOUND `T` erases to a tagged `cell`: nothing is known about it, so the
     * value must carry its own type at runtime. A BOUNDED `T of Animal` erases to
     * `obj<Animal>` instead — a raw pointer, no boxing — because the bound already
     * says what the value representationally IS. That is why a bound is more than
     * an analyzer's check here: it changes the emitted code.
     */
    public const KIND_TYPEVAR = 'typevar';

    public function __construct(
        public readonly string $kind,
        public readonly ?self $element = null,
        public readonly ?self $key = null,
        public readonly ?string $class = null,
        /** @var self[] */
        public readonly array $atoms = [],
        /** A `cell` whose every arm is numeric (`int|float`): same NaN-boxed
         *  repr as a plain cell, but arithmetic may promote at runtime (the
         *  cell-arith path). A plain mixed cell keeps the integer path. A bool,
         *  not a Type[] atom list — the latter is a self-host miscompile hazard. */
        public readonly bool $numeric = false,
        /**
         * RECORD shape: a string-key literal's per-field types, in insertion
         * order (`{id:int, name:string, …}`). A record is REPRESENTATIONALLY an
         * `assoc[string, <join of field types>]` (same runtime PhpArray, same
         * `element`/`key`) — every consumer that ignores this payload treats it
         * as that assoc. Only shape-aware code ({@see isRecord}) reads it. Any
         * control-flow merge or element mutation drops it (the array-join branch
         * rebuilds a plain array), degrading to today's `assoc[string,cell]`.
         * @var array<string,self>|null
         */
        public readonly ?array $fields = null,
        /**
         * Bound type arguments of a generic class use — the `Node` of a
         * `Box<Node>`. Positionally matched against the class's `@template`
         * parameters ({@see ClassDef::$typeParams}). Empty for a non-generic
         * type. Purely a compile-time payload: it changes how a call site TYPES
         * the result, never the runtime representation.
         * @var self[]
         */
        public readonly array $typeArgs = [],
    ) {}

    public static function void():    self { return new self(self::KIND_VOID); }
    public static function null_():   self { return new self(self::KIND_NULL); }
    public static function bool_():   self { return new self(self::KIND_BOOL); }
    public static function int_():    self { return new self(self::KIND_INT); }
    public static function float_():  self { return new self(self::KIND_FLOAT); }
    public static function string_(): self { return new self(self::KIND_STRING); }
    public static function unknown(): self { return new self(self::KIND_UNKNOWN); }
    public static function closure(): self { return new self(self::KIND_CLOSURE); }

    /**
     * A callable with its signature known — `callable(int): string`.
     *
     * The signature rides in {@see $typeArgs} — the return type first, then the
     * parameters. NOT in {@see $element}: many consumers read `element` to mean
     * "this is a container", and a closure carrying one there segfaults them.
     * `typeArgs` is a compile-time-only payload nothing else inspects.
     *
     * Same representation as a bare closure (a struct pointer); the payload only
     * lets an invoke site type its RESULT concretely instead of taking the
     * uniform tagged-cell return of a dynamically-dispatched callable.
     *
     * @param self[] $params
     */
    public static function closureOf(?self $ret, array $params): self
    {
        $sig = [];
        $sig[] = $ret ?? self::unknown();
        foreach ($params as $p) { $sig[] = $p; }
        return new self(self::KIND_CLOSURE, typeArgs: $sig);
    }

    /** The declared return type of a `callable(…): R`, or null for a bare callable. */
    public function closureReturn(): ?self
    {
        if ($this->kind !== self::KIND_CLOSURE) { return null; }
        if ($this->typeArgs === []) { return null; }
        $r = $this->typeArgs[0];
        if ($r->kind === self::KIND_UNKNOWN) { return null; }
        return $r;
    }

    public static function vec(self $element): self
    {
        return new self(self::KIND_ARRAY, element: $element);
    }

    public static function assoc(self $key, self $value): self
    {
        return new self(self::KIND_ARRAY, element: $value, key: $key);
    }

    /**
     * A record shape (a string-key literal with known per-field types). The
     * caller passes the already-computed `$element` (the same assoc element
     * inferArrayLit derives — cell for a mixed literal, else the concrete
     * type), so a record is IDENTICAL in memory to the plain assoc; only
     * `fields` is extra. Key is string. Every shape-unaware consumer treats it
     * as `assoc[string, $element]`.
     * @param array<string,self> $fields
     */
    public static function record(array $fields, self $element): self
    {
        return new self(
            self::KIND_ARRAY,
            element: $element,
            key: self::string_(),
            fields: $fields,
        );
    }

    /** True when this array carries a known per-field shape ({@see $fields}). */
    public function isRecord(): bool
    {
        return $this->kind === self::KIND_ARRAY && $this->fields !== null;
    }

    /** Any array (vec or assoc — they share {@see KIND_ARRAY}). */
    public function isArray(): bool
    {
        return $this->kind === self::KIND_ARRAY;
    }

    /** A string-keyed array ("assoc"): {@see $key} is a string type. */
    public function isAssoc(): bool
    {
        return $this->kind === self::KIND_ARRAY
            && $this->key !== null && $this->key->kind === self::KIND_STRING;
    }

    /** An int-keyed / unkeyed array ("vec"): an array that is not assoc. */
    public function isVec(): bool
    {
        return $this->kind === self::KIND_ARRAY
            && !($this->key !== null && $this->key->kind === self::KIND_STRING);
    }

    public static function obj(string $class): self
    {
        return new self(self::KIND_OBJ, class: $class);
    }

    /**
     * A `#[TypeDef]` value — its CARRIER scalar (int/float), tagged with the
     * declaring class name.
     *
     * The kind stays KIND_INT / KIND_FLOAT: every codegen site that asks
     * `isInt()` / `isFloat()` keeps working untouched, so a TypeDef costs no
     * allocation, no refcount and no indirection — it IS the machine scalar.
     * The class name rides along purely so the front end can resolve `$byte->value`
     * and `$byte->method()` against the declaration, and so {@see Passes\TypeCheck} can
     * refuse the sites where an erased value would be observed as an OBJECT
     * (`===`, var_dump, a cell/mixed slot) and diverge from Zend.
     *
     * Same trick as {@see record}: a payload that shape-unaware consumers may
     * ignore, because without it the type is still exactly right.
     */
    public static function typeDef(string $class, self $carrier): self
    {
        return new self($carrier->kind, class: $class);
    }

    /** The `#[TypeDef]` class this scalar was declared as, or null. */
    public function typeDefClass(): ?string
    {
        if ($this->kind !== self::KIND_INT && $this->kind !== self::KIND_FLOAT) {
            return null;
        }
        return $this->class;
    }

    /** This type with any `#[TypeDef]` tag dropped — the bare carrier scalar. */
    public function stripTypeDef(): self
    {
        if ($this->typeDefClass() === null) { return $this; }
        return new self($this->kind);
    }

    /**
     * The `T` of a `@template T` — see {@see KIND_TYPEVAR}.
     *
     * `$bound` is the upper bound of `@template T of Animal`, carried in
     * {@see $element}. It is not a mere check: it tells the compiler what a `T`
     * value REPRESENTATIONALLY is, so an unbound `T` (which must erase to a boxed
     * cell, since nothing is known about it) becomes a raw `obj<Animal>` pointer.
     */
    public static function typeVar(string $name, ?self $bound = null): self
    {
        return new self(self::KIND_TYPEVAR, element: $bound, class: $name);
    }

    public function isTypeVar(): bool
    {
        return $this->kind === self::KIND_TYPEVAR;
    }

    /**
     * A use of a generic class with its arguments bound (`Box<Node>`).
     *
     * @param self[] $typeArgs
     */
    public static function objOf(string $class, array $typeArgs): self
    {
        return new self(self::KIND_OBJ, class: $class, typeArgs: $typeArgs);
    }

    /**
     * Replace every type variable in this type by its binding, recursively
     * (`T` → `Node`, `T[]` → `Node[]`). A variable with no binding is left
     * alone — an unbound typevar behaves exactly like today's erased `unknown`
     * at every consumer, so an un-annotated use degrades rather than miscompiles.
     *
     * @param array<string, self> $bindings type-parameter name → bound type
     */
    public function substitute(array $bindings): self
    {
        if ($this->kind === self::KIND_TYPEVAR) {
            $name = $this->class ?? '';
            if (isset($bindings[$name])) { return $bindings[$name]; }
            return $this;
        }
        if ($this->kind === self::KIND_ARRAY) {
            $el = $this->element !== null ? $this->element->substitute($bindings) : null;
            $ky = $this->key !== null ? $this->key->substitute($bindings) : null;
            if ($el === $this->element && $ky === $this->key) { return $this; }
            return new self(
                self::KIND_ARRAY,
                element: $el,
                key: $ky,
                fields: $this->fields,
            );
        }
        return $this;
    }

    /** Whether this type mentions a type variable anywhere (worth substituting). */
    public function hasTypeVar(): bool
    {
        if ($this->kind === self::KIND_TYPEVAR) { return true; }
        if ($this->element !== null && $this->element->hasTypeVar()) { return true; }
        if ($this->key !== null && $this->key->hasTypeVar()) { return true; }
        return false;
    }

    /**
     * Drop every type variable to `unknown` — the type as the SHARED compiled
     * body must see it.
     *
     * A generic class has one body serving every instantiation, so inside it a
     * `T` really is erased, and the MIR/codegen must be handed exactly the type
     * it would have had before generics existed (no consumer downstream knows
     * KIND_TYPEVAR, and letting one leak in would risk a wrong array/rc path).
     * The un-erased form is kept beside it, in {@see ClassDef::$genericReturns},
     * purely so a CALL SITE can substitute its binding.
     */
    public function eraseTypeVars(): self
    {
        // A typevar erases to CELL, not `unknown`. That is this compiler's
        // standing invariant — an erased value must carry its runtime tag —
        // and it is exactly what the shared body needs: `T` boxes on the way in
        // and the tag survives, so concat / arithmetic / echo dispatch on it
        // correctly at any instantiation. Erasing to `unknown` instead hands the
        // consumer a raw i64 and it silently takes the integer path (printing a
        // pointer, or a double's bit pattern) — the bug this feature exists to
        // remove.
        if ($this->kind === self::KIND_TYPEVAR) {
            // A BOUNDED `T of Animal` is known to be an object, so it erases to
            // that object — a raw pointer, no boxing. Only a wholly unknown `T`
            // needs the tagged cell.
            if ($this->element !== null) { return $this->element; }
            return self::cell();
        }
        if ($this->kind === self::KIND_ARRAY && $this->hasTypeVar()) {
            return new self(
                self::KIND_ARRAY,
                element: $this->element !== null ? $this->element->eraseTypeVars() : null,
                key: $this->key !== null ? $this->key->eraseTypeVars() : null,
                fields: $this->fields,
            );
        }
        return $this;
    }

    /**
     * A `Generator` value; `element` is the yielded value type, `key` the
     * yielded key type (both nullable) — i.e. Generator<TKey, TValue>.
     */
    public static function generator(?self $value, ?self $key = null): self
    {
        return new self(self::KIND_OBJ, element: $value, key: $key, class: 'Generator');
    }

    public function isGenerator(): bool
    {
        return $this->kind === self::KIND_OBJ && $this->class === 'Generator';
    }

    /** @param self[] $atoms */
    public static function cell(array $atoms = []): self
    {
        return new self(self::KIND_CELL, atoms: $atoms);
    }

    /** A numeric (`int|float`) cell — a NaN-boxed value known to be int OR
     *  float, so arithmetic over it promotes at runtime instead of forcing int. */
    public static function numericCell(): self
    {
        return new self(self::KIND_CELL, numeric: true);
    }

    /** A `cell` whose arms are all numeric (int|float) — arithmetic may promote. */
    public function isNumericCell(): bool
    {
        return $this->kind === self::KIND_CELL && $this->numeric;
    }

    /**
     * Build a static union from object arms (a member may itself be a union —
     * its atoms are spread in). ONLY all-object unions form: a non-object arm,
     * or more than 6 distinct classes, degrades to `unknown` (the union stays
     * inert at consumers that can't reason about it). A single distinct class
     * collapses to that `obj<…>`.
     *
     * @param self[] $arms
     */
    public static function union(array $arms): self
    {
        $classes = [];   // distinct class name → obj Type, insertion order
        foreach ($arms as $a) {
            if ($a->kind === self::KIND_UNION) {
                foreach ($a->atoms as $at) {
                    $cn = $at->class ?? '';
                    if ($cn === '') { return self::unknown(); }
                    if (!isset($classes[$cn])) { $classes[$cn] = $at; }
                }
                continue;
            }
            if ($a->kind !== self::KIND_OBJ) { return self::unknown(); }
            $cn = $a->class ?? '';
            if ($cn === '') { return self::unknown(); }
            if (!isset($classes[$cn])) { $classes[$cn] = $a; }
        }
        $atoms = \array_values($classes);
        $n = \count($atoms);
        if ($n === 0) { return self::unknown(); }
        if ($n === 1) { return $atoms[0]; }
        if ($n > 6)   { return self::unknown(); }
        return new self(self::KIND_UNION, atoms: $atoms);
    }

    /** A static object union (`B|C`) — {@see $atoms} are the member obj types. */
    public function isUnion(): bool
    {
        return $this->kind === self::KIND_UNION;
    }

    /**
     * Join two types at a control-flow merge point. Same kind →
     * same type, anything else → `unknown`. Future passes refine
     * with proper union types (`int|float` → number, …).
     */
    public function unionWith(Type $other): Type
    {
        // Object arms (either side already a union) join into a static object
        // union so a control-flow merge of `new B` / `new C` stays dispatchable
        // (runtime class_id) instead of collapsing to unknown.
        if (($this->kind === self::KIND_OBJ || $this->kind === self::KIND_UNION)
            && ($other->kind === self::KIND_OBJ || $other->kind === self::KIND_UNION)) {
            if ($this->kind === self::KIND_OBJ && $other->kind === self::KIND_OBJ
                && $this->class === $other->class) {
                return $this;
            }
            return self::union([$this, $other]);
        }
        if ($this->kind !== $other->kind) {
            // A NULL arm joined with an object keeps the object type (PHP `?C`):
            // the class stays statically resolvable so a guarded
            // `$x !== null && $x->p` read hits the declared slot instead of the
            // offset-16 unknown-receiver fallback. A runtime null would
            // null-deref exactly as PHP does (and such reads are null-guarded).
            if ($this->kind === self::KIND_NULL
                && ($other->kind === self::KIND_OBJ || $other->kind === self::KIND_UNION)) {
                return $other;
            }
            if ($other->kind === self::KIND_NULL
                && ($this->kind === self::KIND_OBJ || $this->kind === self::KIND_UNION)) {
                return $this;
            }
            return self::unknown();
        }
        if ($this->kind === self::KIND_OBJ) {
            if ($this->class !== $other->class) { return self::unknown(); }
            return $this;
        }
        // Two scalars of the same kind but a different `#[TypeDef]` tag (one of
        // them possibly untagged) join to the BARE carrier. Keeping the tag would
        // let a merge with a plain int smuggle the marker onto a value that is no
        // longer a TypeDef, and TypeCheck would then reject a use that is fine.
        if ($this->class !== $other->class
            && ($this->kind === self::KIND_INT || $this->kind === self::KIND_FLOAT)) {
            return new self($this->kind);
        }
        // Arrays join element- AND key-wise so a control-flow merge keeps a
        // refined shape (`vec[unknown]` ∪ `vec[string]` → `vec[string]`; a
        // loop body that appends a typed value must not reset to
        // `vec[unknown]` on the back-edge). A null key (vec) joined with a
        // string key (assoc) lifts to the string key.
        if ($this->kind === self::KIND_ARRAY) {
            $key = ($this->key === null && $other->key === null)
                ? null
                : $this->joinElement($this->key, $other->key);
            return new self(
                self::KIND_ARRAY,
                element: $this->joinElement($this->element, $other->element),
                key: $key,
            );
        }
        return $this;
    }

    /** Join two optional element/key types; `unknown`/null defers to the other. */
    private function joinElement(?Type $a, ?Type $b): Type
    {
        if ($a === null || $a->kind === self::KIND_UNKNOWN) {
            return $b === null ? self::unknown() : $b;
        }
        if ($b === null || $b->kind === self::KIND_UNKNOWN) {
            return $a;
        }
        return $a->unionWith($b);
    }

    public function toString(): string
    {
        if ($this->kind === self::KIND_ARRAY) {
            // Preserve the vec[…] / assoc[…] presentation (golden-stable):
            // a string key reads as an assoc, otherwise a vec.
            if ($this->key !== null && $this->key->kind === self::KIND_STRING) {
                return 'assoc['
                    . $this->key->toString()
                    . ', '
                    . ($this->element?->toString() ?? '?')
                    . ']';
            }
            return 'vec[' . ($this->element?->toString() ?? '?') . ']';
        }
        if ($this->kind === self::KIND_OBJ) {
            return 'obj<' . ($this->class ?? '?') . '>';
        }
        if ($this->kind === self::KIND_CELL) {
            if ($this->atoms === []) { return 'cell'; }
            $parts = [];
            foreach ($this->atoms as $atom) { $parts[] = $atom->toString(); }
            return 'cell{' . implode('|', $parts) . '}';
        }
        if ($this->kind === self::KIND_UNION) {
            $parts = [];
            foreach ($this->atoms as $atom) { $parts[] = $atom->toString(); }
            return implode('|', $parts);
        }
        return $this->kind;
    }
}
