<?php
// A static property handed to a by-ref parameter rode the throwaway-slot path:
// `uksort(self::$defs, …)` sorted a temporary and the property kept its order.
final class Def { public function __construct(public string $n) {} }
final class RS {
    private static ?array $defs = null;
    public static function get(): array {
        if (null === self::$defs) {
            self::$defs = [];
            foreach (['S', 'P', 'A'] as $n) { self::$defs['@' . $n] = new Def($n); }
            uksort(self::$defs, static fn (string $x, string $y): int => strnatcasecmp($x, $y));
        }
        return self::$defs;
    }
    private static array $plain = ['b' => 1, 'a' => 2];
    public static function plain(): array { ksort(self::$plain); return self::$plain; }
}
echo implode(',', array_keys(RS::get())), "\n";
echo implode(',', array_keys(RS::get())), "\n";
echo implode(',', array_keys(RS::plain())), "\n";
