<?php

// Passing an UNDEFINED variable by reference is how php spells an out-parameter:
// the call creates it (as NULL) and the callee writes through it. No notice, no
// warning — reading an undefined variable warns, passing one by reference does
// not. Manticore refuses the program instead:
//
//   compile failed: MIR.verify: dangling local $collected read in __main but
//   never defined
//
// It is what tier 2 stops on now, in symfony/cache AbstractAdapter::commit:
//
//   $byLifetime = (self::$mergeByLifetime)($this->deferred, $this->namespace,
//                                          $expiredIds, ...);
//   if ($expiredIds) { ... $this->doDelete($expiredIds); }
//
// where the closure's third parameter is `&$expiredIds` and the caller never
// assigns it. The shape is not closure-specific — a plain named function does
// the same, which is what makes it a general gap and not a dynamic-invoke one.
//
// Fix direction: a local in a BY-REF ARGUMENT position is a definition, exactly
// as `RefAddr_` already is for `$r = &$obj->prop`. The by-ref masks are known to
// the module by the time Verify runs, and `#[RefOut]` already carries the idea
// that such a parameter is a pure output safe to vivify at the caller; the slot
// then needs the same NULL entry-init every other local gets.

function fill(int $a, ?array &$out): int
{
    $out = [$a, $a + 1];
    return $a;
}

echo fill(7, $collected), "\n";
var_dump(count($collected));

$viaClosure = static function (int $a, ?array &$out): int {
    $out = [$a];
    return $a;
};
echo $viaClosure(3, $fromClosure), "\n";
var_dump(count($fromClosure));
