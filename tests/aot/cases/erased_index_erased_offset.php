<?php

// An ERASED subject indexed by an ERASED offset. `foreach` over a vec[cell]
// types the key AND the value as cells, so `$op[$len - 1]` has a cell base and
// a cell index at once -- the shape symfony/expression-language's
// generate_operator_regex.php builds. The runtime string-or-array classify was
// guarded on the INDEX being statically int, so this form skipped it and handed
// a string pointer to __mir_array_get_int (SIGSEGV). `$op[0]` on the very same
// subject worked, which is what made it look like a base-type bug.
//
// A class implementing ArrayAccess is present on purpose: the erased-offsetGet
// arm fires whenever the module has ANY such class, and it SHADOWED the string
// arm entirely -- so without one here the interesting path is not the one a
// real application takes.

class Bag implements ArrayAccess
{
    public static string $log = '';

    /** @var array<string,string> */
    private array $d = ['x' => 'X', 'y' => 'Y'];

    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    // Records the lookup rather than handing the value back: a CONCRETE string
    // returned through a `mixed` channel is not boxed today (an independent,
    // pre-existing erasure bug -- `$bag->offsetGet('y')` on a fully TYPED
    // receiver misreads identically), and this case is about which ARM runs.
    public function offsetGet(mixed $o): mixed { self::$log = self::$log . 'get(' . $o . ')'; return 0; }
    public function offsetSet(mixed $o, mixed $v): void { $this->d[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
}

$ops = ['not', 'in', 'starts with', 'ends with'];
$ops = array_combine($ops, array_map('strlen', $ops));
arsort($ops);

// cell base + cell index: the crash
foreach ($ops as $op => $len) {
    echo $op[$len - 1];
}
echo "\n";

// cell base + constant index: worked before, must keep working
foreach ($ops as $op => $len) {
    echo $op[0];
}
echo "\n";

// both offsets in one expression, the original's exact shape
foreach ($ops as $op => $len) {
    echo (ctype_alpha($op[0]) ? 'a' : '.'), (ctype_alpha($op[$len - 1]) ? 'a' : '.'), ' ';
}
echo "\n";

// an erased ARRAY subject still takes the array path, keyed by a cell
function pick(mixed $row, mixed $i): mixed { return $row[$i]; }

echo pick([10, 20, 30], 1), "\n";
echo pick(['k' => 'v'], 'k'), "\n";

// an erased STRING subject through the same erased channel
echo pick('abcdef', 2), "\n";

// an erased ArrayAccess OBJECT still dispatches offsetGet -- the arm that used
// to shadow the string one
pick(new Bag(), 'y');
echo Bag::$log, "\n";
