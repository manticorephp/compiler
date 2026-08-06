<?php

namespace Compile\Mir;

/**
 * The parts of a {@see ClassDef} a dependent cannot re-derive from the
 * synthetic declaration a `.sig` hydrates into.
 *
 * Most of a class rebuilds itself: feed the importer's declaration through the
 * ordinary `buildClassDef` and the parent-prefixed slot order, the bag
 * inheritance, the static-property cells and the constant table all come out
 * the same. What does NOT come out the same is everything lowering computed
 * from information the dependent does not hold — the transitive interface
 * closure (built by walking the LIBRARY's declaration table), the element type
 * a bare `array` property got from how the library's own METHOD BODIES push
 * into it, and the narrow slot widths, which are read off the library's
 * `#[TypeDef]` repr table. Those travel verbatim and are stamped on after the
 * build, by {@see Passes\LowerClasses::applyExternMeta}.
 *
 * The layout numbers are not metadata but a CHECK: every other disagreement
 * between library and dependent degrades to a wrong answer, a disagreement
 * about offsets corrupts the heap.
 */
final class ExternClassMeta
{
    public string $name = '';

    /** 'class' | 'interface' | 'enum'. */
    public string $kind = 'class';

    /** Non-empty when the library exported the type only to say it CANNOT
     *  cross ('generic', 'typedef', 'layout') — the importer turns this into a
     *  diagnostic that names the reason instead of an unknown-class error. */
    public string $unsupported = '';

    /** The library's class id, re-derived and compared on import: a mismatch
     *  means the two compilers disagree about `stableClassId` itself. */
    public int $classId = 0;

    public string $parent = '';

    /** Already the TRANSITIVE closure — the direct `implements` line is not
     *  enough, and re-closing it needs the library's interface tree.
     *  @var string[] */
    public array $interfaces = [];

    public bool $isFinal = false;
    public bool $isAbstract = false;
    public bool $isStruct = false;
    public bool $hasBag = false;

    /** @var string[] */
    public array $attributes = [];

    /** @var array<string, bool> */
    public array $propertyArrayHinted = [];

    /** @var array<string, bool> */
    public array $propertyReadonly = [];

    /** @var array<string, int> */
    public array $propertyWidths = [];

    /** @var array<string, bool> */
    public array $propertySigned = [];

    /** @var array<string, bool> */
    public array $propertyFloat32 = [];

    /** @var array<string, true> */
    public array $methodNames = [];

    /** @var array<string, MethodMeta> */
    public array $methodMeta = [];

    /** @var array<string, PropertyMeta> */
    public array $propertyMeta = [];

    /** @var array<string, array{get: ?string, set: ?string}> */
    public array $propHooks = [];

    // ── the layout assertion ──────────────────────────────────────────────

    public int $size = 0;
    public int $hdr = 0;
    public int $bag = -1;
    public int $sum = 0;

    /** Human-readable `name@offset` list, for the drift message only. */
    public string $offsets = '';

    /** Method names the library `.o` really DEFINES a symbol for — own,
     *  trait-mixed and compiler-synthesised alike. An abstract or interface
     *  method is absent: there is nothing to declare.
     *  @var array<string, bool> name → isStatic */
    public array $symbolIsStatic = [];
}
