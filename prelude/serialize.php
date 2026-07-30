<?php

/**
 * `serialize()` — PHP's native serialization format.
 *
 * PRELUDE (compiled WITH the program), never stdlib: the object arm is
 * `__mc_ser_object`, which LowerFromAst GENERATES from the finished class table
 * (one `instanceof` arm per class, {@see LowerPrelude::serObjectSrc}), and the
 * stdlib `.sig` carries functions only — it can neither name a user class nor
 * take an object. Gated in Main on `serialize(`.
 *
 * VALUE NUMBERING. php-src increments the var-hash counter at the head of EVERY
 * `php_var_serialize_intern` — arrays, scalars and back-references alike — and
 * only additionally RECORDS objects in the id map. So `serialize([1, 2, $a, $a])`
 * is `a:4:{i:0;i:1;i:1;i:2;i:2;O:8:"stdClass":0:{}i:3;r:4;}`: the array is 1, the
 * two ints 2 and 3, `$a` is 4. Hence the unconditional `$st->n = $st->n + 1`
 * before any type test. Array KEYS are serialized by the walker itself and do
 * NOT count.
 *
 * The state rides on an object, not by-ref params: an object is a pointer, so a
 * mutation is visible to the caller with no new machinery, and no prelude file
 * uses `&$`.
 */

final class __McSerSt
{
    /** @var array<int,int> spl_object_id -> the value slot that first held it */
    public array $seen = [];
    public int $n = 0;
}

function serialize(mixed $value): string
{
    $st = new __McSerSt();
    return __mc_ser_val($value, $st);
}

/** `s:<byte length>:"<bytes>";` — strlen is byte-based and NUL-safe here. */
function __mc_ser_str(string $s): string
{
    return 's:' . (string)strlen($s) . ':"' . $s . '";';
}

/**
 * The recursive walker. RECURSE for every element (like var_dump / print_r):
 * passing a value through the `mixed $v` param re-boxes it to a proper cell,
 * normalizing a deeply-nested value whose string-ness was erased through the
 * cell-of-cell foreach. Each `is_*` guard NARROWS $v in-branch, which is what
 * makes the cast unbox correctly — a bare `(string)$v` on the `mixed` mistypes.
 */
function __mc_ser_val(mixed $v, __McSerSt $st): string
{
    $st->n = $st->n + 1;
    if (is_int($v)) { return 'i:' . (string)$v . ';'; }
    // Ryu shortest round-trip (php serialize_precision=-1), the same printer
    // var_dump uses: `d:1;` for 1.0 (NO forced `.0` — that is var_export's
    // form), `d:0.1;`, `d:1.0E+100;`, `d:INF;`, `d:-INF;`, `d:NAN;`, `d:-0;`.
    if (is_float($v)) { return 'd:' . __mir_float_repr($v) . ';'; }
    if (is_bool($v)) {
        $b = (string)$v;                       // true -> "1", false -> ""
        if ($b === '1') { return 'b:1;'; }
        return 'b:0;';
    }
    if (is_null($v)) { return 'N;'; }
    if (is_string($v)) { return __mc_ser_str((string)$v); }
    if (is_array($v)) {
        $out = 'a:' . (string)count($v) . ':{';
        foreach ($v as $k => $val) {
            if (is_int($k)) { $out = $out . 'i:' . (string)$k . ';'; }
            else { $out = $out . __mc_ser_str((string)$k); }
            $out = $out . __mc_ser_val($val, $st);
        }
        return $out . '}';
    }
    // Objects, enum cases and \Resource: `__mc_ser_object` is GENERATED from the
    // class table ({@see LowerPrelude::serObjectSrc}) — one instanceof arm per
    // class, so the property reads are typed and the mangled keys are baked in.
    return __mc_ser_object($v, $st);
}
