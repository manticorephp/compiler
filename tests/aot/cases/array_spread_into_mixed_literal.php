<?php
// A spread whose source has a CONCRETE element type landing in a literal whose
// elements are mixed must box each element (php-cs-fixer SingleLineThrowFixer:
// `[...self::AROUND, ...self::BEFORE]` over ['(', [T_DOUBLE_COLON]] + [')', …]).
final class Box7 { public int $v = 7; }
final class K {
    private const A = ['['];
    private const B = ['(', [T_DOUBLE_COLON]];
    private const C = [')', ']', ',', ';'];
    public static function next(): array { return [...self::B, ...self::C]; }
    public static function prev(): array { return [...self::A, ...self::B]; }
}
var_dump(K::next(), K::prev());
var_dump(in_array(')', K::next(), true));

$mixed = ['x', [1]];
$strs = ['y', 'z'];
$ints = [1, 2];
$floats = [1.5, -0.25];
$bools = [true, false];
$objs = [new Box7()];
$nested = [[1, 2], [3]];
var_dump([...$mixed, ...$strs]);
var_dump([...$strs, ...$mixed]);
var_dump([...$ints, ...$strs]);
var_dump([1, 'a', ...$floats]);
var_dump(['k', ...$bools, ...$ints]);
var_dump([null, ...$objs]);
var_dump(['s', ...$nested]);
$assoc = ['p' => 'q', 'r' => 's'];
var_dump(['k' => 1, ...$assoc]);
$fresh = static fn (): array => ['f1', 'f2'];
var_dump([0, ...$fresh()]);
echo json_encode([...$ints, ...$strs, ...$floats]), "\n";
function spread_mixed(mixed $m): array { return [1, ...$m]; }
var_dump(spread_mixed(['p', 'q']), spread_mixed(['k' => 2.5]));
$g = static fn (): array => ['g1', 2];
var_dump(['a', ...$g()], [...$g()]);
