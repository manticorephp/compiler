<?php

// `Class::${expr}` and `Class::$$var` — a static property whose NAME is
// computed. symfony/error-handler DebugClassLoader writes seven of these and
// nothing else in tiers 1-4 does; it was tier 4's first blocker, and a PARSE
// failure, which is the highest-confidence finding class there is.
//
// The class is resolved the ordinary way, so the candidate set is exactly its
// declared statics and is closed at compile time. That is what lets this lower
// to a chain over the concrete slots instead of a runtime name->slot map: a
// read becomes a ternary chain, and a store re-lowers the whole target once per
// candidate, so an arbitrarily deep index chain needs no new store node.
//
// A name matching nothing throws what php throws, WITH the offending name in
// the message — the last case here, and the message is assembled at runtime.

class Reg
{
    /** @var array<string,string> */
    private static array $final = [];
    /** @var array<string,string> */
    private static array $deprecated = [];
    /** @var array<string,string> */
    private static array $internal = [];
    /** @var array<string,string> */
    private static array $finalMethods = [];

    public static function note(string $annotation, string $class, string $desc): void
    {
        self::${$annotation}[$class] = $desc;
    }

    // the name is a computed EXPRESSION, not just a variable
    public static function noteMethod(string $annotation, string $class, string $m, string $d): void
    {
        self::${$annotation.'Methods'}[$class] = $d;
    }

    public static function has(string $property, string $key): bool
    {
        return isset(self::${$property}[$key]);
    }

    public static function get(string $property, string $key): string
    {
        return self::${$property}[$key];
    }

    /** @return array<string,string> */
    public static function dump(string $p): array { return self::${$p}; }

    // the `$$name` spelling of the same thing
    public static function viaDollarDollar(string $p, string $k, string $v): void
    {
        self::$$p[$k] = $v;
    }
}

foreach (['final', 'deprecated', 'internal'] as $ann) {
    Reg::note($ann, 'C' . $ann, ' ' . $ann . ' here.');
}
Reg::note('final', 'Second', ' also final.');
Reg::noteMethod('final', 'M', 'run', ' final method');
Reg::viaDollarDollar('internal', 'DD', ' via $$');

var_dump(Reg::has('final', 'Cfinal'), Reg::has('final', 'absent'));
var_dump(Reg::get('deprecated', 'Cdeprecated'));
foreach (['final', 'deprecated', 'internal', 'finalMethods'] as $p) {
    var_dump($p, Reg::dump($p));
}
try { Reg::get('nosuch', 'k'); } catch (\Throwable $e) { var_dump(get_class($e), $e->getMessage()); }
