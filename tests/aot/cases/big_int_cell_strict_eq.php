<?php
// An int that does not fit the 48-bit inline cell rides a heap box, one per
// boxing: === / array_key_exists over two such cells must compare VALUES.
// spl_object_id on a Linux arm64 heap (0xaaaa…) is exactly such an int.
final class W {
    /** @var array<int, int> */ public static array $serial = [];
    /** @var array<int, array<int, mixed>> */ public static array $data = [];
}
$a = 187650995848200; $b = 12345;
foreach ([$a, $b] as $k) {
    W::$serial[$k] = 7;
    W::$data[1][$k] = "v$k";
    var_dump(isset(W::$serial[$k]), array_key_exists($k, W::$data[1]), W::$data[1][$k] ?? 'miss');
}
$m = []; $m[$a] = 'x'; var_dump(array_key_exists($a, $m), array_keys($m) === [$a]);
function viaMixed(mixed $k): mixed { $t = []; $t[$k] = 1; return array_key_first($t); }
var_dump(viaMixed($a), viaMixed($a) === $a);
var_dump(PHP_INT_MAX === (function (mixed $x): mixed { return $x; })(PHP_INT_MAX), in_array(1 << 50, [1, 1 << 50], true));
