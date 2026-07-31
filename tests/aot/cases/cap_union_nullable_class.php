<?php
// @epic: type-lowering
// @why: `?S` and `S|null` are ONE type in php, but lowerTypeHint sent every hint
//       containing a `|` to a cell, so the union spelling lost its class. The
//       runtime object stayed intact — only the STATIC type died, taking
//       __toString dispatch, get_debug_type and method_exists with it.
//       prelude/reflection.php spells all four of its optional returns this way.

final class UnS
{
    public function __toString(): string { return 'SSS'; }
}

final class UnH
{
    public function shorthand(): ?UnS { return new UnS(); }
    public function union(): UnS|null { return new UnS(); }
    public function plain(): UnS { return new UnS(); }
    public function nullArm(): null|UnS { return new UnS(); }
}

function unFn(): UnS|null { return new UnS(); }

$h = new UnH();

$a = $h->shorthand();
echo 'shorthand  ', get_debug_type($a), ' ', (string)$a, "\n";
$b = $h->union();
echo 'union      ', get_debug_type($b), ' ', (string)$b, "\n";
$c = $h->plain();
echo 'plain      ', get_debug_type($c), ' ', (string)$c, "\n";
$d = $h->nullArm();
echo 'null|T     ', get_debug_type($d), ' ', (string)$d, "\n";
$e = unFn();
echo 'function   ', get_debug_type($e), ' ', (string)$e, "\n";
var_dump(method_exists($b, '__toString'));

// A real multi-arm union has no single class to dispatch on and stays a cell.
function unMulti(int $n): UnS|string|null { return $n > 0 ? new UnS() : 'plain'; }
var_dump(is_object(unMulti(1)));
var_dump(unMulti(0));

// The `|` inside a generic argument belongs to the INNER type — splitting there
// would invent arms. `array<int, string|null>` must stay an array.
/** @param array<int, string|null> $rows */
function unRows(array $rows): int { return count($rows); }
var_dump(unRows(['a', null, 'c']));
