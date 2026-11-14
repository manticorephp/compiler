<?php
// A throw 25 frames deep unwinds through frames that own objects with destructors; then a rethrow passes a finally.
final class Guard {
    public static bool $quiet = false;
    public function __construct(private int $n) {}
    public function __destruct() { if (!self::$quiet && $this->n % 6 === 0) { echo "dtor ", $this->n, "\n"; } }
}
function dive(int $n): int {
    $g = new Guard($n);
    if ($n === 0) { throw new RuntimeException("bottom"); }
    return dive($n - 1) + 1;
}
try { dive(25); } catch (RuntimeException $e) { echo "caught ", $e->getMessage(), "\n"; }
function again(): void {
    try {
        try { dive(3); } catch (RuntimeException $e) { echo "inner\n"; throw new LogicException("re", 0, $e); }
    } finally { echo "finally\n"; }
}
try { again(); } catch (LogicException $e) { echo get_class($e), " ", $e->getMessage(), " <- ", $e->getPrevious()->getMessage(), "\n"; }
Guard::$quiet = true;
$sum = 0;
for ($i = 0; $i < 2000; $i++) { try { dive($i % 7); } catch (RuntimeException $e) { $sum++; } }
echo "loop ", $sum, "\n";
