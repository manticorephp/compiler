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

    /**
     * `$fields` / `$nullableFields` keys are `shapeKey`-encoded — never a mixed
     * int|string map, which the self-hosted compiler cannot read back.
     * @param array<string,self>|null $fields
     * @param array<string,true> $nullableFields
     */
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
         * SHAPE: per-field types of a docblock `array{…}` or a string-key
         * literal, in declared order. A shape is REPRESENTATIONALLY the plain
         * array it sits on (same `element`/`key`, same runtime buffer) — every
         * consumer that ignores this payload treats it as that array. Only
         * shape-aware code ({@see isShape}, {@see shapeField}) reads it. A
         * control-flow merge of two DIFFERENT shapes drops it.
         * @var array<string,self>|null keyed by {@see shapeKey}
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
        /**
         * Keys of {@see $fields} whose value may be NULL at run time — declared
         * `?T`, `T|null` or `key?:` — so the runtime shape check accepts a NULL
         * cell there and nowhere else.
         * @var array<string,true> keyed by {@see shapeKey}
         */
        public readonly array $nullableFields = [],
        /**
         * The shape is a DOCBLOCK claim (`@param array{…}`, `@return`, `@var`):
         * its fields are the whole key set and every field type is the
         * author's word, so TypeCheck refuses a literal, a return or a
         * constant-key read that contradicts it. A shape INFERRED from a
         * literal's own elements is a description, not a claim — the same
         * program may read a key the literal never spelled (php answers null
         * with a warning) or hand a wider literal to the same parameter.
         */
        public readonly bool $declared = false,
    ) {
        self::$nextId = self::$nextId + 1;
        $this->id = self::$nextId;
    }

    /**
     * Identity of this exact instance, and the alphabet the composite
     * cache keys are written in ({@see $interned}). An id rather than a
     * recursive structural string: the key is built on EVERY factory call,
     * three million times per build, and walking a nested type there would
     * cost more than the allocation it saves.
     */
    public int $id = 0;
    private static int $nextId = 0;

    /**
     * The parameterless kinds are INTERNED — one object each, for the whole
     * compile. Every field of a Type is readonly, so a shared instance cannot
     * be told from a fresh one; the eight constructors below were simply
     * minting a new object per call, and a 510 KB input asked for 221 541 of
     * them — int 60 179, unknown 52 513, string 46 161, void 36 221 — which is
     * 90% of every Type this compiler builds.
     *
     * The bare `cell` joins them: `cell()` takes an atom list, but every one of
     * its 179 call sites passes NOTHING, and `heap` charged 424 358 live blocks
     * (39 MB of a 1.27 GB peak) to it. The parameter stays for the API; only the
     * empty case is shared. `numericCell()` is parameterless outright.
     *
     * One slot per kind rather than a keyed array: an array element is an
     * ERASED channel, and reading a Type back out of one would put it through
     * the cell boundary at every call. A `bool` flag rather than testing a slot
     * against null: `=== null` on an object slot is the unsound-native,
     * invisible-under-Zend shape, and there is no reason to walk into it.
     */
    private static bool $scalarsBuilt = false;
    private static ?self $tVoid = null;
    private static ?self $tNull = null;
    private static ?self $tBool = null;
    private static ?self $tInt = null;
    private static ?self $tFloat = null;
    private static ?self $tString = null;
    private static ?self $tUnknown = null;
    private static ?self $tClosure = null;
    private static ?self $tCell = null;
    private static ?self $tNumericCell = null;

    private static function buildScalars(): void
    {
        if (self::$scalarsBuilt) { return; }
        self::$scalarsBuilt = true;
        self::$tVoid    = new self(self::KIND_VOID);
        self::$tNull    = new self(self::KIND_NULL);
        self::$tBool    = new self(self::KIND_BOOL);
        self::$tInt     = new self(self::KIND_INT);
        self::$tFloat   = new self(self::KIND_FLOAT);
        self::$tString  = new self(self::KIND_STRING);
        self::$tUnknown = new self(self::KIND_UNKNOWN);
        self::$tClosure = new self(self::KIND_CLOSURE);
        self::$tCell        = new self(self::KIND_CELL);
        self::$tNumericCell = new self(self::KIND_CELL, numeric: true);
    }

    public static function void():    self { self::buildScalars(); return self::$tVoid; }
    public static function null_():   self { self::buildScalars(); return self::$tNull; }
    public static function bool_():   self { self::buildScalars(); return self::$tBool; }
    public static function int_():    self { self::buildScalars(); return self::$tInt; }
    public static function float_():  self { self::buildScalars(); return self::$tFloat; }
    public static function string_(): self { self::buildScalars(); return self::$tString; }
    public static function unknown(): self { self::buildScalars(); return self::$tUnknown; }
    public static function closure(): self { self::buildScalars(); return self::$tClosure; }

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

    /**
     * COMPOSITE types are interned as well, and they are where the objects
     * actually are. Interning the parameterless kinds left the composites
     * minting one object per call: a full self-host build asked for
     * **3 169 269 Types and wanted 795** — 99.97%% duplicates, of which
     * `vec` 2 099 389, `assoc` 880 904 and `obj` 168 886. `vec[string]`
     * alone was 776 084 objects.
     *
     * Sound for the same reason the scalars are: every field is readonly, so
     * a shared instance cannot be told from a fresh one.
     *
     * ⚠ The old comment on the scalar slots warned that a keyed array would
     * put a Type through the CELL boundary on every read. Measured, it does
     * not: with `@var array<string,self>` on the property the read carries no
     * cell op at all (checked in the emitted IR), and a stand doing 800 000
     * lookups peaked at 1.11 MB against 91.16 MB for the same run allocating
     * each time, at equal CPU.
     *
     * @var array<string,self>
     */
    private static array $interned = [];

    public static function vec(self $element): self
    {
        $k = 'v' . (string)$element->id;
        $hit = self::$interned[$k] ?? null;
        if ($hit !== null) { return $hit; }
        $t = new self(self::KIND_ARRAY, element: $element);
        self::$interned[$k] = $t;
        return $t;
    }

    /**
     * An array type from its parts, interned through {@see vec} / {@see assoc}
     * whenever it carries no record shape.
     *
     * Interning only the two public factories left two thirds of the array
     * types un-shared: `join` runs at every control-flow merge and
     * `substitute` / `eraseTypeVars` at every generic use, and all three
     * rebuilt the type with a bare `new self`. One constructor for the shape,
     * so a new caller cannot quietly opt out of the cache again.
     *
     * @param array<string,self>|null $fields  @param array<string,true> $nullable
     */
    private static function arrayOf(?self $element, ?self $key, ?array $fields, array $nullable = [], bool $declared = false): self
    {
        if ($fields !== null) {
            return new self(self::KIND_ARRAY, element: $element, key: $key, fields: $fields, nullableFields: $nullable, declared: $declared);
        }
        $el = $element ?? self::unknown();
        return $key === null ? self::vec($el) : self::assoc($key, $el);
    }

    /**
     * A tuple shape — int keys, packed (`array{0:Node,1:bool}`). Same memory as
     * `vec[$element]`; only `fields` is extra.
     * @param array<string,self> $fields  @param array<string,true> $nullable
     */
    public static function tuple(array $fields, self $element, array $nullable = [], bool $declared = false): self
    {
        return new self(self::KIND_ARRAY, element: $element, fields: $fields, nullableFields: $nullable, declared: $declared);
    }

    public static function assoc(self $key, self $value): self
    {
        $k = 'a' . (string)$key->id . '.' . (string)$value->id;
        $hit = self::$interned[$k] ?? null;
        if ($hit !== null) { return $hit; }
        $t = new self(self::KIND_ARRAY, element: $value, key: $key);
        self::$interned[$k] = $t;
        return $t;
    }

    /**
     * A record shape (a string-key literal with known per-field types). The
     * caller passes the already-computed `$element` (the same assoc element
     * inferArrayLit derives — cell for a mixed literal, else the concrete
     * type), so a record is IDENTICAL in memory to the plain assoc; only
     * `fields` is extra. Key is string. Every shape-unaware consumer treats it
     * as `assoc[string, $element]`.
     * @param array<string,self> $fields  @param array<string,true> $nullable
     */
    public static function record(array $fields, self $element, array $nullable = [], bool $declared = false): self
    {
        return new self(self::KIND_ARRAY, element: $element, key: self::string_(), fields: $fields, nullableFields: $nullable, declared: $declared);
    }

    /**
     * A docblock shape from its parsed fields: int keys only → tuple, string
     * keys only → record, both → a cell-keyed shape (the tag-dispatched key
     * channel). The element is {@see shapeElement}. `$fields`/`$nullable` keys
     * arrive PRE-ENCODED ({@see shapeKey}) — the caller has already converted.
     * `$declared` marks a docblock claim ({@see $declared}).
     * @param array<string,self> $fields  @param array<string,true> $nullable
     */
    public static function shapeOf(array $fields, array $nullable, bool $declared = false): self
    {
        $anyStr = false;
        $anyInt = false;
        foreach ($fields as $ek => $f) {
            if (self::shapeKeyIsInt($ek)) { $anyInt = true; continue; }
            if ($ek !== '' && $ek[0] === 's') { $anyStr = true; continue; }
            throw new \RuntimeException('shapeOf: key not shapeKey-encoded: ' . $ek);
        }
        $el = self::shapeElement($fields);
        if (!$anyStr) { return self::tuple($fields, $el, $nullable, $declared); }
        if (!$anyInt) { return self::record($fields, $el, $nullable, $declared); }
        return new self(self::KIND_ARRAY, element: $el, key: self::cell(), fields: $fields, nullableFields: $nullable, declared: $declared);
    }

    /**
     * The one element repr a shape's buffer holds: the fields' common type when
     * every field spells the SAME type, else a tagged CELL. A typevar, union,
     * null or cell field forces the cell — the shared generic body sees `T`
     * as a cell, so the buffer must be a cell buffer at every instantiation.
     * @param array<string,self> $fields
     */
    private static function shapeElement(array $fields): self
    {
        $first = null;
        foreach ($fields as $f) {
            $k = $f->kind;
            if ($k === self::KIND_CELL || $k === self::KIND_NULL || $k === self::KIND_UNKNOWN
                || $k === self::KIND_UNION || $k === self::KIND_TYPEVAR || $f->hasTypeVar()) {
                return self::cell();
            }
            if ($first === null) { $first = $f; continue; }
            if ($first->toString() !== $f->toString()) { return self::cell(); }
        }
        return $first ?? self::cell();
    }

    /** True when this array carries a known per-field shape ({@see $fields}). */
    public function isShape(): bool
    {
        return $this->kind === self::KIND_ARRAY && $this->fields !== null;
    }

    /**
     * True when a shape sits anywhere in this type — the type itself, its
     * element, its key, or a field of one. A literal argument adopts a
     * parameter type that CONTAINS a shape (`vec[array{0:obj<Node>,1:bool}]`),
     * not only one that IS a shape, or the outer literal keeps its
     * field-blind element and a foreach over it loses the fields
     * ({@see \Compile\Mir\Passes\InferCalls::adoptLitParamElem}).
     */
    public function hasShape(): bool
    {
        if ($this->isShape()) { return true; }
        if ($this->element !== null && $this->element->hasShape()) { return true; }
        if ($this->key !== null && $this->key->hasShape()) { return true; }
        if ($this->fields !== null) {
            foreach ($this->fields as $f) {
                if ($f->hasShape()) { return true; }
            }
        }
        return false;
    }

    /** A string-keyed shape. */
    public function isRecord(): bool
    {
        return $this->isShape() && $this->key !== null && $this->key->kind === self::KIND_STRING;
    }

    /** True when PHP would canonicalise this STRING key to an int key at the
     *  array boundary — `'0'` and `'-1'` are int, `'01'` and `'-0'` are not
     *  (php's own rule: `0` or an optional `-` then a nonzero leading digit
     *  and only digits after). Written without `preg_*` — self-host cost. */
    public static function isIntKey(string $k): bool
    {
        if ($k === '0') { return true; }
        $s = $k;
        if ($s !== '' && $s[0] === '-') { $s = \substr($s, 1); }
        if ($s === '' || $s[0] < '1' || $s[0] > '9') { return false; }
        return \ctype_digit($s);
    }

    /** The map key a shape field is stored under: `i<n>` for an int key, `s<name>` for a string key.
     *  A single string-keyed map on purpose — a PHP array mixing int and string keys is a
     *  polymorphic-key channel the self-hosted compiler cannot read back reliably. A STRING `$k`
     *  canonicalises through {@see isIntKey} first — `'0'`/`'-1'` are int keys to PHP too. */
    public static function shapeKey(int|string $k): string
    {
        if (\is_int($k)) { return 'i' . (string)$k; }
        if (self::isIntKey($k)) { return 'i' . $k; }
        return 's' . $k;
    }

    /** The user-facing key of an encoded shape key (`i0` → `0`, `sname` → `name`). */
    public static function shapeKeyLabel(string $ek): string
    {
        return \substr($ek, 1);
    }

    /** True when an encoded shape key is an int key. */
    public static function shapeKeyIsInt(string $ek): bool
    {
        return $ek !== '' && $ek[0] === 'i';
    }

    /** The declared type of field `$k`, or null when the shape has no such key. */
    public function shapeField(int|string $k): ?self
    {
        if ($this->fields === null) { return null; }
        return $this->fields[self::shapeKey($k)] ?? null;
    }

    /** Direct lookup by an already-ENCODED key — for a caller iterating {@see $fields} itself. */
    public function shapeFieldAt(string $ek): ?self
    {
        if ($this->fields === null) { return null; }
        return $this->fields[$ek] ?? null;
    }

    public function shapeFieldNullable(int|string $k): bool
    {
        return isset($this->nullableFields[self::shapeKey($k)]);
    }

    /** The same array with another element — the shape rides along. */
    public function withElement(self $el): self
    {
        return self::arrayOf($el, $this->key, $this->fields, $this->nullableFields, $this->declared);
    }

    /** `array{0:obj<Node>,1?:bool}` — diagnostics only; {@see toString} stays golden-stable. */
    public function shapeString(): string
    {
        if (!$this->isShape()) { return $this->toString(); }
        $parts = [];
        foreach ($this->fields as $ek => $f) {
            $parts[] = self::shapeKeyLabel($ek) . (isset($this->nullableFields[$ek]) ? '?' : '') . ':' . $f->shapeString();
        }
        return 'array{' . \implode(',', $parts) . '}';
    }

    /**
     * The php / PHPStan spelling, for a message a php programmer reads:
     * `array{0: Node, 1: ?bool}`, `string[]`, `array<string, int>`, a class by
     * its name, `cell` as `mixed`. Diagnostics only — {@see toString} is the
     * golden-stable MIR spelling.
     */
    public function phpString(): string
    {
        if ($this->isShape()) {
            $parts = [];
            foreach ($this->fields as $ek => $f) {
                $fs = $f->phpString();
                if (isset($this->nullableFields[$ek]) && $f->kind !== self::KIND_CELL
                    && $f->kind !== self::KIND_UNION && $f->kind !== self::KIND_NULL) {
                    $fs = '?' . $fs;
                }
                $parts[] = self::shapeKeyLabel($ek) . ': ' . $fs;
            }
            return 'array{' . \implode(', ', $parts) . '}';
        }
        if ($this->kind === self::KIND_ARRAY) {
            $el = $this->element === null ? 'mixed' : $this->element->phpString();
            if ($this->key !== null && $this->key->kind !== self::KIND_INT) {
                return 'array<' . $this->key->phpString() . ', ' . $el . '>';
            }
            return $el . '[]';
        }
        if ($this->kind === self::KIND_OBJ) { return \ltrim($this->class ?? 'object', '\\'); }
        if ($this->kind === self::KIND_CLOSURE) { return 'Closure'; }
        if ($this->kind === self::KIND_CELL) {
            if ($this->atoms === []) { return 'mixed'; }
            $parts = [];
            foreach ($this->atoms as $atom) { $parts[] = $atom->phpString(); }
            return \implode('|', $parts);
        }
        if ($this->kind === self::KIND_UNION) {
            $parts = [];
            foreach ($this->atoms as $atom) { $parts[] = $atom->phpString(); }
            return \implode('|', $parts);
        }
        if ($this->kind === self::KIND_UNKNOWN) { return 'mixed'; }
        return $this->kind;
    }

    /** Same keys, same nullability, same field types. */
    public function sameShape(self $o): bool
    {
        if (!$this->isShape() || !$o->isShape()) { return false; }
        return $this->shapeString() === $o->shapeString();
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
        $k = 'o' . $class;
        $hit = self::$interned[$k] ?? null;
        if ($hit !== null) { return $hit; }
        $t = new self(self::KIND_OBJ, class: $class);
        self::$interned[$k] = $t;
        return $t;
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

    /** The `#[TypeDef]` class this value was declared as, or null. */
    public function typeDefClass(): ?string
    {
        if ($this->kind !== self::KIND_INT && $this->kind !== self::KIND_FLOAT
            && $this->kind !== self::KIND_STRING) {
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
            $fs = $this->fields;
            $changed = false;
            if ($fs !== null) {
                $nf = [];
                foreach ($fs as $k => $f) {
                    $s = $f->substitute($bindings);
                    if ($s !== $f) { $changed = true; }
                    $nf[$k] = $s;
                }
                if ($changed) { $fs = $nf; }
            }
            if ($el === $this->element && $ky === $this->key && !$changed) { return $this; }
            // The ELEMENT is never recomputed from the substituted fields: the
            // shared body already fixed the buffer repr (a typevar field made
            // it a cell buffer), and a call site typing `array{0:Node,1:int}`
            // over that buffer must keep reading cells.
            return self::arrayOf($el, $ky, $fs, $this->nullableFields, $this->declared);
        }
        return $this;
    }

    /** Whether this type mentions a type variable anywhere (worth substituting). */
    public function hasTypeVar(): bool
    {
        if ($this->kind === self::KIND_TYPEVAR) { return true; }
        if ($this->element !== null && $this->element->hasTypeVar()) { return true; }
        if ($this->key !== null && $this->key->hasTypeVar()) { return true; }
        if ($this->fields !== null) {
            foreach ($this->fields as $f) { if ($f->hasTypeVar()) { return true; } }
        }
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
            $fs = $this->fields;
            if ($fs !== null) {
                $nf = [];
                foreach ($fs as $k => $f) { $nf[$k] = $f->eraseTypeVars(); }
                $fs = $nf;
            }
            return self::arrayOf(
                $this->element !== null ? $this->element->eraseTypeVars() : null,
                $this->key !== null ? $this->key->eraseTypeVars() : null,
                $fs,
                $this->nullableFields,
                $this->declared,
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

    /**
     * The atom-less cell is INTERNED — see {@see buildScalars}. `\count()` and
     * not `$atoms === []`: array `===` compares pointers here, so the
     * literal-empty test is not a reliable emptiness check.
     *
     * @param self[] $atoms
     */
    public static function cell(array $atoms = []): self
    {
        if (\count($atoms) === 0) { self::buildScalars(); return self::$tCell; }
        return new self(self::KIND_CELL, atoms: $atoms);
    }

    /** A numeric (`int|float`) cell — a NaN-boxed value known to be int OR
     *  float, so arithmetic over it promotes at runtime instead of forcing int. */
    public static function numericCell(): self
    {
        self::buildScalars();
        return self::$tNumericCell;
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
    /** KIND_CLOSURE, or an `obj<__closure_N>` closure-literal handle. */
    public static function isClosureLike(Type $t): bool
    {
        return $t->kind === self::KIND_CLOSURE
            || ($t->kind === self::KIND_OBJ
                && \strncmp($t->class ?? '', '__closure_', 10) === 0);
    }

    /**
     * A kind whose values are always heap pointers and can therefore ride a
     * tagged cell losslessly. The union of two DIFFERENT such kinds is a cell.
     */
    private static function isContainerish(string $kind): bool
    {
        return $kind === self::KIND_ARRAY || $kind === self::KIND_OBJ
            || $kind === self::KIND_UNION || $kind === self::KIND_CELL;
    }

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
            // A NULL arm joined with a POINTER scalar (string) keeps that type —
            // its null rides the slot RAW as ptr 0, so the merged value stays
            // self-describing (`$x = null; if ($c) $x = "s";` is a `?string`, read
            // as a string, `=== null` = ptr==0) instead of erasing to unknown and
            // being mis-read by a type-directed consumer. Same rationale as the
            // `?C` obj rule above. INT/FLOAT/BOOL are NOT pointer kinds (null would
            // collide with 0/0.0/false) and take the tagged-cell merge shadow.
            if ($this->kind === self::KIND_NULL && $other->kind === self::KIND_STRING) {
                return $other;
            }
            if ($other->kind === self::KIND_NULL && $this->kind === self::KIND_STRING) {
                return $this;
            }
            // Two DIFFERENT container-ish kinds: the honest top is a CELL, not
            // `unknown`. A cell is self-describing — it carries its tag, so
            // instanceof / is_array / gettype still answer — while `unknown`
            // rides RAW and is indistinguishable from an int at every consumer,
            // AND carries no repr, so the value is neither boxed nor RETAINED
            // when it is re-stored. That last part is what crashed symfony's
            // Table: `array_merge($this->headers, [$divider], $this->rows)`
            // joined `vec[vec[string]]` with `vec[obj<TableSeparator>]`, the
            // element lattice gave `unknown`, and the merged array kept a raw
            // pointer to a separator the caller's temporary literal then freed.
            // Scalars are deliberately NOT included: a null|int / numeric-cell
            // merge has its own discipline elsewhere that must not be perturbed.
            if (self::isContainerish($this->kind) && self::isContainerish($other->kind)) {
                return self::cell();
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
        // longer a TypeDef, and CheckTypeDefs would then reject a use that is fine.
        if ($this->class !== $other->class
            && ($this->kind === self::KIND_INT || $this->kind === self::KIND_FLOAT
                || $this->kind === self::KIND_STRING)) {
            return new self($this->kind);
        }
        // Arrays join element- AND key-wise so a control-flow merge keeps a
        // refined shape (`vec[unknown]` ∪ `vec[string]` → `vec[string]`; a
        // loop body that appends a typed value must not reset to
        // `vec[unknown]` on the back-edge).
        //
        // KEYS: a vec's null key is not "no keys", it is INT keys, so a
        // concrete vec joined with a string-keyed assoc is an array that can
        // hand out EITHER — a CELL key, the one channel that carries its own
        // tag (`__mir_array_key_cell_at` classifies packed vs hashed at
        // runtime). Lifting it to the string key instead typed the int arm's
        // key as a string and `$c ? ["x" => "1"] : ["2"]` printed `=2` for
        // key 0. An UNREFINED `[]` still defers — it has no keys at all, which
        // is what lets `if (!$xs) return []; return ["k" => …];` stay an assoc.
        if ($this->kind === self::KIND_ARRAY) {
            // The SAME shape on both arms survives the merge; any other pair of
            // arrays drops the fields (the element/key join below is the
            // honest answer for "one of two different shapes").
            if ($this->isShape() && $other->isShape() && $this->sameShape($other)) { return $this; }
            $key = self::joinArrayKey($this, $other);
            return self::arrayOf($this->joinElement($this->element, $other->element), $key, null);
        }
        return $this;
    }

    /**
     * Join the KEY channels of two array types.
     *
     * A null key means int keys (a vec) — except on an UNREFINED array
     * (`[]`, element null/unknown), which has no keys to speak of and
     * defers to the other side. Two disagreeing concrete channels (int vs
     * string) are a CELL: it is the only key repr that is self-describing.
     */
    private static function joinArrayKey(Type $a, Type $b): ?Type
    {
        if ($a->key === null && $b->key === null) { return null; }
        if ($a->key !== null && $b->key !== null) {
            return $a->joinElement($a->key, $b->key);
        }
        $keyed = $a->key !== null ? $a : $b;
        $vec = $a->key !== null ? $b : $a;
        $unrefined = $vec->element === null || $vec->element->kind === self::KIND_UNKNOWN;
        if ($unrefined) { return $keyed->key; }
        $kk = $keyed->key->kind;
        if ($kk === self::KIND_INT || $kk === self::KIND_UNKNOWN) { return $keyed->key; }
        return self::cell();
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
