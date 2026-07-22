<?php

namespace Compile\Mir;

/**
 * Class layout descriptor carried on the {@see Module}.
 *
 * Object ABI:
 *   offset 0  : ptr class descriptor ({i64 class_id, ptr drop_fn})
 *   offset 8  : i64 refcount
 *   offset 16 : property 0
 *   offset 24 : property 1
 *   …          (8 bytes each, declaration order)
 *
 * `propertyNames` is the ordered list used to compute offsets;
 * `propertyTypes` maps each name to its MIR {@see Type} so
 * `InferTypes` can resolve `$obj->prop` reads and `EmitLlvm` can
 * pick the right load/store coercion. Methods live in the module's
 * function list under the `Class__method` mangled name — this
 * descriptor only tracks which method names exist for dispatch
 * resolution.
 */
final class ClassDef
{
    /**
     * @param string[]            $propertyNames ordered, for offsets
     * @param array<string, Type> $propertyTypes name → type
     * @param array<string, true> $methodNames   declared method names
     */
    public function __construct(
        public readonly string $name,
        public readonly int $classId,
        public array $propertyNames,
        public array $propertyTypes,
        public array $methodNames,
        public string $parent = '',
        public array $interfaces = [],
        public array $staticPropNames = [],
        public array $staticPropTypes = [],
        public bool $isStruct = false,
        public bool $hasBag = false,
        /**
         * PHP 8.4 property hooks: property name → ['get' => symbol|null,
         * 'set' => symbol|null], where symbol is the pre-mangle hook-method name
         * (`<DeclaringClass>____hook_<prop>_get`). Inherited from the parent.
         * @var array<string, array{get: ?string, set: ?string}>
         */
        public array $propHooks = [],
    ) {}

    /** Declared shape of each method — visibility, static, params, return hint.
     *  `$methodNames` answers only "does it exist"; this is what tells a static
     *  method from an instance one, and is the source the reflection metadata
     *  tables are emitted from.
     *
     *  NOT keyed identically to `$methodNames`, in BOTH directions:
     *   - it carries INHERITED methods, which `$methodNames` does not (that one
     *     is declared + trait-mixed only);
     *   - a compiler-synthesised entry (a `__hook_<prop>_get`, or the ctor
     *     synthesised for defaulted props) appears in `$methodNames` with NO
     *     entry here, because no user declaration exists to describe.
     *  So a consumer must treat a missing key as "not user-declared", never as
     *  an error, and must not assume the two agree on size.
     *
     *  Insertion order is own → trait → inherited, matching what PHP's
     *  `ReflectionClass::getMethods()` reports (verified against php 8.5).
     *  @var array<string, MethodMeta> */
    public array $methodMeta = [];

    /** Class-level `#[Attr]` names, verbatim as written (no namespace
     *  resolution — the compiler's own attribute lookups are raw string
     *  matches, and this inherits that).
     *  @var string[] */
    public array $attributes = [];

    /** `final class` / `abstract class` — PHP reports these, and nothing in MIR
     *  recorded them because dispatch never needed to ask. */
    public bool $isFinal = false;
    public bool $isAbstract = false;

    /** Names of properties declared `array` (or a typed array). `clone` must
     *  value-copy their array slot even when a bare `array` hint erased the
     *  element type to unknown (else the clone aliases the original's buffer).
     *  @var array<string, bool> */
    public array $propertyArrayHinted = [];

    /** Names of `readonly` properties — a write from outside the declaring class
     *  scope is a fatal `Error` (Cannot modify readonly property).
     *  @var array<string, bool> */
    public array $propertyReadonly = [];

    /** Declared `@template` parameters, in order (`['T']` for `@template T`). A
     *  use site binds them positionally via {@see Type::$typeArgs}.
     *  @var string[] */
    public array $typeParams = [];

    /** Method name → its declared return type STILL CARRYING type variables
     *  (`T`, `T[]`). The MIR signature holds the erased form, because the class
     *  has one shared compiled body; this un-erased copy exists only so a call
     *  site with a bound receiver (`Box<Tag>`) can substitute and type the
     *  result concretely.
     *  @var array<string, Type> */
    public array $genericReturns = [];

    /** `@template T of Animal` — the upper bound of a type parameter. Not a check:
     *  a bounded `T` erases to the BOUND (a raw pointer) instead of a tagged cell.
     *  @var array<string, Type> */
    public array $typeParamBounds = [];

    /** `@template T = int` — the type a parameter takes when a use site names none.
     *  @var array<string, Type> */
    public array $typeParamDefaults = [];

    /** The generic class this one is a REIFIED specialization of (`Box` for
     *  `Box$of$float`), or '' for an ordinary class. The specialization also
     *  carries the origin as its {@see $parent}, which is what makes
     *  `instanceof Box`, `catch (Box)` and virtual dispatch on a bare `Box`
     *  receiver keep seeing it — they all walk the parent chain. */
    public string $originClass = '';

    /** `#[TypeDef(repr: …)]` — the machine type this value type declares
     *  (`u8`, `i32`, `f32`). Empty for an ordinary class. A TypeDef ClassDef is
     *  NEVER added to the module's class list: it exists only so the front end
     *  can resolve `$c->value` / `$byte->method()` against a declaration that has no runtime
     *  form at all. See {@see Passes\LowerTypeDefs}. */
    public string $typeDefRepr = '';

    /** The single property a `#[TypeDef]` carries — the value itself. */
    public string $typeDefProp = '';

    /** The name PHP must report — `Box` for every `Box$of$T`. `get_class()`,
     *  `::class` and var_dump print THIS, never the internal spec name. Empty
     *  for an ordinary class, which is its own display name. */
    public string $displayName = '';

    /** The name this class reports to PHP (`get_class`, `::class`, var_dump). */
    public function display(): string
    {
        return $this->displayName !== '' ? $this->displayName : $this->name;
    }

    /** The arguments this class passes to its generic PARENT, from
     *  `/** @extends Base<T> *\/`. May itself mention this class's own type
     *  parameters — climbing the chain re-maps them.
     *  @var Type[] */
    public array $parentTypeArgs = [];

    /** Whether this class carries a dynamic-property bag. */
    public function usesBag(): bool
    {
        return $this->hasBag;
    }

    /**
     * Byte offset of the dynamic-property bag (an assoc ptr) placed
     * after the declared properties; -1 when the class has no bag.
     */
    public function bagOffset(): int
    {
        if (!$this->hasBag) { return -1; }
        return $this->layoutEnd();
    }

    /**
     * Byte offset just past the last property — where the bag goes, and what the
     * instance size is built from.
     *
     * Each slot is aligned to its own width, so a narrow property can never leave
     * the NEXT one straddling a word. With every slot 8 bytes wide (today) the
     * alignment is a no-op and this is exactly `header + 8 * count`.
     */
    private function layoutEnd(): int
    {
        $off = $this->headerSize();
        foreach ($this->propertyNames as $name) {
            $w = $this->propertyWidth($name);
            $off = $this->alignUp($off, $w) + $w;
        }
        return $this->alignUp($off, 8);
    }

    private function alignUp(int $off, int $align): int
    {
        $rem = $off % $align;
        return $rem === 0 ? $off : $off + ($align - $rem);
    }

    /** Whether this class directly declares static property `$name`. */
    public function hasStaticProp(string $name): bool
    {
        foreach ($this->staticPropNames as $n) {
            if ($n === $name) { return true; }
        }
        return false;
    }

    /** Header bytes before the first property (0 for `#[Struct]`). */
    public function headerSize(): int
    {
        return $this->isStruct ? 0 : 16;
    }

    /** Slots narrower than a word: property name → 1 / 2 / 4, from the `repr` of
     *  the `#[TypeDef]` it is declared as. Filled at lowering, where the TypeDef
     *  table is in scope. Absent → a full 8-byte word.
     *  @var array<string, int> */
    public array $propertyWidths = [];

    /** Narrow slots that must SIGN-extend on load (`i8`/`i16`/`i32`, not `u*`).
     *  @var array<string, bool> */
    public array $propertySigned = [];

    /** Narrow slots holding an `f32` — a float, not an integer.
     *  @var array<string, bool> */
    public array $propertyFloat32 = [];

    /** True when declared inside the injected prelude (the `[0, $preludeCount)`
     *  statement window). Reflection Ф2 uses it to decide a synthesized invoke
     *  trampoline's linkage. DELIBERATELY LAST: adding a field mid-struct shifts
     *  the offsets of `originClass` / `interfaces` etc., which re-triggers the
     *  latent `ClassDef|null`-typed-non-null SIGSEGV in {@see
     *  Passes\EmitLlvm::classIsA} (a layout-shift landmine that comment documents).
     *  Keeping it at the end leaves every existing field's offset untouched. */
    public bool $isPreludeClass = false;

    /** Declared shape of each instance property — visibility, static, readonly,
     *  the as-written type hint. `propertyNames`/`propertyTypes` answer layout;
     *  this is the reflection source `ReflectionProperty` reads. Own → inherited,
     *  matching php's getProperties() order. A missing key = not user-declared
     *  (e.g. a compiler-synthesised slot), never an error. Appended LAST for the
     *  same offset-stability reason as {@see $isPreludeClass}.
     *  @var array<string, PropertyMeta> */
    public array $propertyMeta = [];

    /**
     * Width in bytes of `$prop`'s slot: 8 unless the property is declared as a
     * `#[TypeDef]` whose `repr` is narrower.
     *
     * This is the ONE place a slot's size is decided — the emitter never assumes
     * eight — which is what makes `repr` an honest promise rather than a validated
     * label. See {@see Passes\LowerTypeDefs}.
     */
    public function propertyWidth(string $prop): int
    {
        return $this->propertyWidths[$prop] ?? 8;
    }

    /** Byte offset of `$prop` within an instance, or -1 if unknown. */
    public function propertyOffset(string $prop): int
    {
        $off = $this->headerSize();
        foreach ($this->propertyNames as $name) {
            $w = $this->propertyWidth($name);
            $off = $this->alignUp($off, $w);
            if ($name === $prop) { return $off; }
            $off = $off + $w;
        }
        return -1;
    }

    /** Instance size in bytes: header + every property's slot + the bag slot. */
    public function instanceSize(): int
    {
        return $this->layoutEnd() + ($this->hasBag ? 8 : 0);
    }
}
