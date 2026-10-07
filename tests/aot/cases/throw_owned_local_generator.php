<?php
// @serial: a memory measurement.
// `throw $e` of a caught (owned) exception out of a generator frame and out of
// a closure: the throw takes the local's reference, the frame's teardown must
// not give it back a second time, and nothing leaks.
final class E extends Exception {
    public static int $live = 0;
    public string $pad;
    public function __construct(string $m) { parent::__construct($m); self::$live++; $this->pad = str_repeat('e', 256); }
    public function __destruct() { self::$live--; }
}
function gen(int $i): Generator {
    try { yield 1; throw new E('g' . $i); }
    catch (E $e) { yield 2; throw $e; }
}
function viaGen(int $i): int {
    $n = 0;
    try { foreach (gen($i) as $v) { $n += $v; } }
    catch (E $e) { $n += strlen($e->getMessage()); }
    return $n;
}
function viaClosure(int $i): int {
    $f = function () use ($i): void {
        try { throw new E('c' . $i); } catch (E $e) { throw $e; }
    };
    try { $f(); } catch (E $e) { return strlen($e->getMessage()); }
    return 0;
}
$sum = 0;
for ($i = 0; $i < 1000; $i++) { $sum += viaGen($i) + viaClosure($i); }
echo 'live=', E::$live, "\n";
$b = memory_get_usage();
for ($i = 0; $i < 20000; $i++) { $sum += viaGen($i) + viaClosure($i); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' live=', E::$live, ' ', $g < 2 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
