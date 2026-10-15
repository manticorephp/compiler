<?php
// `(array)$o` / get_object_vars() of a class with a dynamic bag free the declared map `+` copied, and `$c ? [] : (array)$o` is not retained twice (VarDumper's Caster::castObject).

#[\AllowDynamicProperties]
class Dy
{
    public string $s;
    public array $arr = ['k' => 'v'];
    public ?object $o = null;
    public function __construct() { $this->s = 'str' . mt_rand(1, 9); $this->o = new \ArrayObject([]); }
}
class Ex extends \RuntimeException { private array $h = ['a' => 1]; protected string $t = 'x'; }

function cast(object $o): int { return \count((array) $o); }
function castTyped(Dy $o): int { return \count((array) $o); }
function vars(object $o): int { return \count(get_object_vars($o)); }
function varsTyped(Dy $o): int { $v = get_object_vars($o); return \count($v); }
function castOrEmpty(object $o): int { $a = $o instanceof \Closure ? [] : (array) $o; $a = array_replace(['z' => null], $a); return \count($a); }

function churn(int $n): int
{
    $std = (object) ['a' => 1, 'b' => 's' . mt_rand(1, 9)];
    $dy = new Dy();
    $dy->extra = 'e' . mt_rand(1, 9);
    $dy->more = [1, 2];
    $ex = new Ex('m');
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $s += cast($std) + cast($dy) + castTyped($dy) + vars($dy) + vars($std) + varsTyped($dy) + castOrEmpty($ex) + castOrEmpty($dy);
    }
    return $s;
}

$dy = new Dy();
$dy->extra = 'e';
echo implode(',', array_keys((array) $dy)), ' ', implode(',', array_keys(get_object_vars($dy))), "\n";
echo churn(2000), "\n";
$before = memory_get_peak_usage();
churn(100000);
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
