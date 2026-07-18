<?php

namespace Manticore\Attr;

/**
 * Mark an `array` parameter of a bundled-stdlib function as ELEMENT-CONSUMING:
 * the callee reads element VALUES (casts / concatenates / stringifies them),
 * so it needs a self-describing (cell-tagged) array, not the raw element repr
 * a concrete `vec[string]` / `vec[int]` caller passes.
 *
 *   #[Manticore\Attr\CellArg] array $fields   // fputcsv: `(string)$field`
 *
 * A stdlib function is compiled ONCE, so its `array` param carries a fixed
 * element repr in the `.sig` (`mixed[]`). A caller passing a concrete-element
 * array does not match — the raw slots then decode as tagged cells → garbage.
 * This flag tells the call site to cellify (box each element) the concrete
 * array before the call, so the callee always sees a self-describing cell
 * array. It is NOT set on element-PRESERVING passthrough functions
 * (array_merge / array_combine move slots raw), which must keep the raw repr.
 *
 * The signature stays php-identical (`array $fields`); this is compiler
 * metadata carried in the `.sig` exactly like `byref` / `refout`.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
final class CellArg
{
    /** @param string ...$params names of element-consuming array params (function form) */
    public function __construct(public readonly string ...$params) {}
}
