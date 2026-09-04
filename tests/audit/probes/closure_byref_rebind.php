<?php

// `bindTo` copies the closure env, fn pointer included — so the run-time mask
// chain, which identifies a closure by that pointer, still recognises the
// rebound value. A by-ref argument through a rebound closure must therefore
// bind exactly as it does through the original.

class Ctx
{
    public string $tag = 'ctx';

    public function run(\Closure $fn): string
    {
        $bound = $fn->bindTo($this, self::class);
        $r = $bound(3, $collected);
        var_dump($collected);
        return $r;
    }
}

$fn = function (int $n, ?array &$out): string {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $this->tag . $i;
    }
    return $this->tag . '/' . $n;
};

$c = new Ctx();
echo $c->run($fn), "\n";

// ⚠ `->call()` rebinds and invokes in one step but does NOT bind by reference:
// its own signature is `call(?object $newThis, mixed ...$args)`, so the
// arguments are already copies when they are forwarded. php warns and passes
// the value, so the out-variable stays as the caller left it. Pass a DEFINED
// one — an undefined one would add an "Undefined variable" warning on top.
$c2 = new Ctx();
$c2->tag = 'other';
$viaCall = ['untouched'];
echo @$fn->call($c2, 2, $viaCall), "\n";
var_dump($viaCall);
