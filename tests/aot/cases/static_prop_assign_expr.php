<?php
// An assignment is an EXPRESSION, and its value is the value ASSIGNED — never
// the slot's storage encoding. A cell-boxing slot (`mixed`, or an unhinted
// property) must still hand the RAW value to whatever consumes the assignment,
// or a consumer typed `string` inttoptr's a NaN-tagged word and dereferences it.
//
// `$v = (M::$d = 'x')` segfaulted; `$v = (M::$i = 42)` silently read
// -4222124650659798. The same bug rode the instance-property store.

class M
{
    public static $unhinted = '';
    public static mixed $mixed = '';
    public static string $hinted = '';
    public static ?string $nullable = null;
    public static $counter = 0;
}

class O
{
    public mixed $m = '';
    public string $s = '';
    public $bare = 0;
}

// --- static property, every declaration that boxes ---
$a = (M::$unhinted = 'x');
echo $a, "|", M::$unhinted, "\n";

$b = (M::$mixed = 'y');
echo $b, "|", M::$mixed, "\n";

$c = (M::$hinted = 'z');
echo $c, "|", M::$hinted, "\n";

$d = (M::$nullable = 'n');
echo $d, "|", M::$nullable, "\n";

// --- int through a boxing slot: was a silent wrong answer, not a crash ---
$e = (M::$counter = 42);
var_dump($e);
var_dump(M::$counter);
echo M::$counter + 1, "\n";

// --- instance property, the same three shapes ---
$o = new O();
$f = ($o->m = 'p');
echo $f, "|", $o->m, "\n";

$g = ($o->s = 'q');
echo $g, "|", $o->s, "\n";

$h = ($o->bare = 7);
var_dump($h);
var_dump($o->bare);

// --- chained assignment: the middle store's value feeds the outer one ---
$i = ($o->m = M::$mixed = 'chain');
echo $i, "|", M::$mixed, "|", $o->m, "\n";

// --- the memoise idiom that made this reachable (polyfill-intl-icu) ---
class Cache
{
    private static $data;

    public static function get(): array
    {
        return self::$data ?? self::$data = ['a' => 1, 'b' => 2];
    }
}
$x = Cache::get();
echo $x['a'], "\n";
$y = Cache::get();
echo $y['b'], "\n";

// --- the assigned value is usable, not just printable ---
$s = (M::$unhinted = 'abc');
echo strlen($s), "\n";
echo strtoupper($s), "\n";
