<?php
// `'LIT' === $x` where $x is ERASED: the slot holds a NaN-boxed cell on one
// path and a raw string pointer on another, and the raw comparison inttoptr'd
// the carrier as-is — dereferencing the tag bits.
// symfony's mbstring polyfill takes an untyped $fromEncoding, reassigns it from
// a `string|false` call, then compares it to 'BASE64'.
function detect(string $s): string|false
{
    if ($s === '') { return false; }
    return 'BASE64';
}
function pick($enc)
{
    if ($enc === null) { $enc = detect('x'); } else { $enc = \strtoupper($enc); }
    if ('BASE64' === $enc) { return 'was-base64'; }
    if ('UTF-8' !== $enc) { return 'other:' . $enc; }
    return 'utf8';
}
echo pick(null), "\n";
echo pick('utf-8'), "\n";
echo pick('latin1'), "\n";

function raw($v)
{
    return 'BASE64' === $v ? 'yes' : 'no';
}
echo raw('BASE64'), "\n";
echo raw('nope'), "\n";
echo raw(64), "\n";
echo raw(null), "\n";
echo raw(false), "\n";
echo raw(['BASE64']), "\n";
