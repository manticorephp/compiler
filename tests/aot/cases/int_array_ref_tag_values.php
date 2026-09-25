<?php
// A raw int[] element whose bits happen to look like a REFERENCE cell (tag
// nibble 9) is an int, not a box. With references in the module the element
// read, the foreach value and the overwrite dereferenced such words — the
// stdlib's xxh128 accumulators faulted on some inputs (this one among them).
function mkref(): int { $x = 1; $r = &$x; $r = 2; return $x; }

/** @param int[] $acc @return int[] */
function bump(array $acc): array
{
    for ($i = 0; $i < \count($acc); $i++) { $acc[$i] = $acc[$i] + 1; }
    return $acc;
}

$w = (0xFFF9 << 48) | 0x1234;
/** @var int[] $a */
$a = bump([$w, $w + 16, 7]);
var_dump($a[0] === $w + 1, $a[1] === $w + 17, $a[2], mkref());
$src = '<?php

namespace Compile\\Mir;

/**
 * The parts of a {@see ClassDef} a dependent cannot re-derive from the
 * synthetic declaration a `.sig` hydrates into.
 *
 * Most of a class rebuilds itself: feed the importer\'s declaration through the
 * ordinary `buildClassDef` and the parent-prefixed slot order, the bag
 * inheritance, the static-property cells and the constant table all come out
 * the same. What does NOT come out the same is everything lowering computed
 * from information the dependent does not hold — the transitive interface
 * closure (built by walking the LIBRARY\'s declaration table), the element type
 * a bare `array` property got from how the library\'s own METHOD BODIES push
 * into it, and the narrow slot widths, which are read off the library\'s
 * `#[TypeDef]` repr table. Those travel verbatim and are stamped on after the
 * build, by {@see Passes\\LowerClasses::applyExternMeta}.
 *
 * The layout numbers are not metadata but a CHECK: every other disagreement
 * between library and dependent degrades to a wrong answer, a disagreement
 * about offsets corrupts the heap.
 */
final class ExternClassMeta
{
    public string $name = \'\';

    /** \'class\' | \'interface\' | \'enum\'. */
    public string $kind = \'class\';

    /** Non-empty when the library exported the type only to say it CANNOT
     *  cross (\'generic\', \'typedef\', \'layout\') — the importer turns this into a
     *  diagnostic that names the reason instead of an unknown-class error. */
    public string $unsupported = \'\';

    /** The library\'s class id, re-derived and compared on import: a mismatch
     *  means the two compilers disagree about `stableClassId` itself. */
    public int $classId = 0;

    public string $parent = \'\';

    /** Already the TRANSITIVE closure — the direct `implements` line is not
     *  enough, and re-closing it needs the library\'s interface tree.
     *  @var string[] */
    public array $interfaces = [];

    public bool $isFinal = false;
    public bool $isAb';
echo strlen($src), ' ', hash('xxh128', $src), "\n";
