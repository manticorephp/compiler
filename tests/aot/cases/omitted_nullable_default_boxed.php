<?php
// An omitted `?int $x = null` reached the callee as a raw 0 through a call
// lowering had not padded (a callable array, call_user_func): the float 0.0 of
// a cell, never null. php-cs-fixer's Tokens::findGivenKind scanned nothing.
final class T {
    public function f($k, int $start = 0, ?int $end = null, mixed $m = 7): string
    {
        return var_export($end, true) . '/' . $start . '/' . var_export($m, true);
    }
}
$t = new T();
echo $t->f(1), "\n";
echo call_user_func([$t, 'f'], 1), "\n";
echo call_user_func([$t, 'f'], 1, 2), "\n";
$c = [$t, 'f'];
echo $c(1), "\n";
echo call_user_func_array([$t, 'f'], [1]), "\n";
