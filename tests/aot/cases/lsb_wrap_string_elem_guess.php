<?php
// A `(string)`-read param element is no vec[string] guess when the body stores objects/arrays back into it;
// a class reached only through `$cls::$m(...)` keeps its __toString arm.
abstract class AStr {
    protected string $s = '';
    public static function wrap(array $values): array
    {
        $i = 0;
        $keys = null;
        foreach ($values as $k => $v) {
            if (\is_string($k) && '' !== $k && $k !== $j = (string) new static($k)) {
                $keys ??= array_keys($values);
                $keys[$i] = $j;
            }
            if (\is_string($v)) {
                $values[$k] = new static($v);
            } elseif (\is_array($v) && $values[$k] !== $v = static::wrap($v)) {
                $values[$k] = $v;
            }
            ++$i;
        }
        return null !== $keys ? array_combine($keys, $values) : $values;
    }
    public function __toString(): string { return $this->s; }
}
final class BStr extends AStr { public function __construct(string $string = '') { $this->s = $string; } }
final class UStr extends AStr { public function __construct(string $string = '') { $this->s = strtoupper($string); } }

function show(array $r, string $pad = ''): void
{
    foreach ($r as $k => $v) {
        if (is_array($v)) { echo $pad, $k, ":\n"; show($v, $pad . '  '); continue; }
        echo $pad, $k, '=', get_class($v), ':', $v, "\n";
    }
}
function mk(string $c, string $n): \Closure { return $c::$n(...); }

$in = ['a', ['b', ['c']], 'x' => 'y'];
show(mk('BStr', 'wrap')($in));
show(mk('UStr', 'wrap')($in));
$f = BStr::wrap(...);
show($f(['p', 'q' => ['r']]));
