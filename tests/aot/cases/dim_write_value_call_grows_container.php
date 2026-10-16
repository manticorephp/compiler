<?php
// A right-hand side that writes the same static / property array the store targets.
final class K {
    /** @var array<string, int> */
    private static array $m = [];
    /** @var array<string, Closure> */
    private static array $h = [];
    /** @var array<string, int> */
    private array $c = [];

    private static function mk(string $k): int
    {
        if ($k === 'B') { self::$m['A'] = 1; self::$m['Z'] = 3; }
        return strlen($k) + 7;
    }
    private static function hy(string $c): \Closure
    {
        if ($c === 'B') { return self::$h['A'] ??= self::hy('A'); }
        return fn() => $c;
    }
    private function build(string $k): int
    {
        if ($k === 'B') { $this->c['A'] = 1; $this->c['Z'] = 3; }
        return strlen($k) + 7;
    }
    public static function run(): void
    {
        self::$m['B'] = self::mk('B');
        self::$m['A'] = self::mk('A');
        echo json_encode(self::$m), "\n";
        echo (self::$h['B'] ??= self::hy('B'))(), (self::$h['A'] ??= self::hy('A'))(), "\n";
        $o = new K();
        $o->c['B'] = $o->build('B');
        $o->c['Q'] ??= $o->build('Q');
        echo json_encode($o->c), "\n";
        $x = (self::$m['N'] = self::mk('N')) + 1;
        echo $x, "\n";
    }
}
K::run();
