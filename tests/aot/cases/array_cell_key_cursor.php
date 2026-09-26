<?php
// A cell key (key(), an int|string value) in an array literal and in unset(),
// and the internal pointer across unset / packed->hashed promotion /
// compaction — sebastian/diff's Differ::getArrayDiffParted.
$a = ['x', 'y', 'z'];
end($a);
$k = key($a);
$e = [$k => 'v'];
var_dump($e);
$m = ['p' => 1, 'q' => 2];
end($m);
$k2 = key($m);
var_dump([$k2 => 'w']);
function rm(array &$f): void { foreach ($f as $k => $v) { if ($k < 2) { unset($f[$k]); } } }
$b = [1, 2, 3, 4];
rm($b);
var_dump($b);
function rm2(array &$f): array { unset($f[0]); return [$f]; }
$c = [1, 2, 3];
[$c] = rm2($c);
var_dump($c);
$a = ['a', 'b', 'c', 'd'];
end($a); prev($a);
unset($a[3]);
var_dump(current($a), key($a));
prev($a);
var_dump(current($a), key($a));
unset($a[2]);
var_dump(current($a), key($a));
$h = ['x' => 1, 'y' => 2, 'z' => 3];
end($h); prev($h); unset($h['z']);
var_dump(current($h), key($h));
function f3(array &$f): void { end($f); prev($f); unset($f[3]); var_dump(current($f)); prev($f); var_dump(current($f)); }
$b3 = ['a', 'b', 'c', 'd']; f3($b3);
final class D {
    /** @param array<int|string, int|string> $from @param array<int|string, int|string> $to */
    private static function parted(array &$from, array &$to): array
    {
        $start = []; $end = [];
        reset($to);
        foreach ($from as $k => $v) {
            $toK = key($to);
            if ($toK === $k && $v === $to[$k]) { $start[$k] = $v; unset($from[$k], $to[$k]); } else { break; }
        }
        end($from); end($to);
        do {
            $fromK = key($from); $toK = key($to);
            if (null === $fromK || null === $toK || current($from) !== current($to)) { break; }
            prev($from); prev($to);
            $end = [$fromK => $from[$fromK]] + $end;
            unset($from[$fromK], $to[$toK]);
        } while (true);
        return [$from, $to, $start, $end];
    }
    public function run(string $a, string $b): void
    {
        $from = preg_split('/(.*\R)/', $a, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $to = preg_split('/(.*\R)/', $b, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        [$from, $to, $start, $end] = self::parted($from, $to);
        echo json_encode([$from, $to, $start, $end]), "\n";
    }
}
(new D())->run("a\nb\nc\nd\n", "a\n\nb\nc\nd\n");
