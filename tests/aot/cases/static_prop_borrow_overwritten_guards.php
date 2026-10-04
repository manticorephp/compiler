<?php
// A static property value still in use while the same slot is overwritten must
// stay alive until its use ends — the read co-owns across a writing call.
final class V {
    public function __construct(public string $s) {}
    public function __destruct() { echo "free ", $this->s, "\n"; }
    public function detach(): string { Box::$cur = new V('n'); return $this->s . '!'; }
}
final class Box {
    public static ?V $cur = null;
    /** @var V[] */
    public static array $items = [];
    public static string $str = '';
    public static function reset(): string { self::$str = str_repeat('y', 3); self::$cur = new V('r'); self::$items = []; return '|'; }
    public static function take(V $a, string $sep): string { return $a->s . $sep; }
    public static function first(V $v): string { self::$items = [new V('z')]; return $v->s; }
}
Box::$cur = new V('a');
$r = Box::$cur->detach(); echo $r, "\n";
echo "--\n";
Box::$str = str_repeat('x', 3);
$r = Box::$str . Box::reset(); echo $r, "\n";
echo "--\n";
Box::$cur = new V('c');
$r = Box::take(Box::$cur, Box::reset()); echo $r, "\n";
echo "--\n";
Box::$items = [new V('e0'), new V('e1')];
foreach (Box::$items as $it) { Box::$items = []; echo "it ", $it->s, "\n"; }
$it = null;
echo "--\n";
Box::$items = [new V('f0')];
$r = Box::first(Box::$items[0]); echo $r, "\n";
Box::$cur = null;
Box::$items = [];
echo "end\n";
