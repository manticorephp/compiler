<?php
// A mutated static array property: the first string-keyed store reallocs
// from the empty [] default, so the store must thread the new buffer back
// into the static-prop global cell — else the read reloads the stale ptr.
final class Reg {
    /** @var array<string,string> */
    public static array $map = [];
    public static function lookup(string $k): string {
        return self::$map[$k] ?? '(none)';
    }
}
Reg::$map['a'] = 'parentA';
Reg::$map['b'] = 'parentB';
echo Reg::lookup('a'), "\n";
echo Reg::lookup('b'), "\n";
echo Reg::lookup('missing'), "\n";
