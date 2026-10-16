<?php
class P { public function __construct(public int $v) {} }
function show(array $a): string { return json_encode($a); }
function mk(string $kind, int $n): array {
    $a = [];
    for ($i = 0; $i < $n; $i++) {
        $a[] = match ($kind) { 'int' => $i * 3 - 7, 'str' => "s" . $i . str_repeat("x", $i % 5), 'float' => $i + 0.5, 'bool' => $i % 2 === 0,
            'arr' => [$i, "a$i"], 'mixed' => ($i % 3 === 0 ? $i : ($i % 3 === 1 ? "m$i" : null)) };
    }
    return $a;
}
foreach (['int', 'str', 'float', 'bool', 'arr', 'mixed'] as $kind) {
    foreach ([0, 1, 2, 7, 8, 9, 17, 1000] as $n) {
        $a = mk($kind, $n);
        $copy = $a;
        $r = array_reverse($a);
        $s1 = array_slice($a, 1);
        $s2 = array_slice($a, 2, 5);
        $s3 = array_slice($a, -3);
        $s4 = array_slice($a, 1, -1);
        $s5 = array_slice($a, 0, null, true);
        $s6 = array_slice($a, 3, 4, true);
        $m = array_merge($a, $a);
        $m3 = array_merge($a, [], $a, mk($kind, 2));
        echo $kind, ' ', $n, ' ', md5(show($r) . show($s1) . show($s2) . show($s3) . show($s4) . show($s5) . show($s6) . show($m) . show($m3)), "\n";
        // ownership: results are independent of the source
        if ($n > 2) {
            $r[0] = 'changed'; $s1[0] = 'changed'; $m[0] = 'changed'; $m3[1] = 'changed';
            unset($s2[0]);
            echo $a === $copy ? "source intact" : "SOURCE CHANGED", "\n";
        }
    }
}
// reverse / slice / merge keep string keys, renumber ints, honour preserve_keys
$h = ['a' => 1, 5 => 2, 'b' => 3, 9 => 4];
echo show(array_reverse($h)), show(array_reverse($h, true)), show(array_slice($h, 1, 2)), show(array_slice($h, 1, 2, true)), show(array_merge($h, [7, 'a' => 9])), "\n";
// a list that went through unset is no longer a list
$u = [10, 20, 30, 40, 50]; unset($u[1]);
echo show(array_reverse($u)), show(array_slice($u, 1, 2)), show(array_slice($u, 1, 2, true)), show(array_merge($u, $u)), "\n";
$u2 = [10, 20, 30]; unset($u2[2]);
echo show(array_reverse($u2)), show(array_slice($u2, 0)), show(array_merge($u2, [1])), "\n";
// out-of-range offsets and lengths
$a = [1, 2, 3];
echo show(array_slice($a, 5)), show(array_slice($a, 3)), show(array_slice($a, -9)), show(array_slice($a, 1, 0)), show(array_slice($a, 1, -5)), show(array_slice($a, -2, 1)), show(array_slice($a, 0, 100)), "\n";
echo show(array_merge()), show(array_merge([])), show(array_merge([], [])), show(array_merge([1], [])), show(array_merge(['x' => 1], ['x' => 2])), "\n";
// objects stay alive through the result
$objs = [new P(1), new P(2), new P(3)];
$sl = array_slice($objs, 1); $rv = array_reverse($objs); $mg = array_merge($objs, $objs);
unset($objs);
echo $sl[0]->v, $rv[0]->v, $mg[5]->v, count($mg), "\n";
$sl = null; $rv = null;
echo $mg[0]->v, $mg[3]->v, "\n";
// strings stay alive; nested arrays are independent
$strs = [str_repeat("a", 40) . "1", str_repeat("b", 40) . "2"];
$rs = array_reverse($strs); $strs = null;
echo $rs[0], ' ', $rs[1], "\n";
$nest = [[1, 2], [3, 4]];
$rn = array_reverse($nest); $rn[0][] = 99;
echo show($nest), show($rn), "\n";
// closure lists are owned by their repr bits: the result frees its closures
class Live { public static int $n = 0; function __construct(public int $v) { self::$n++; } function __destruct() { self::$n--; } function hook(): \Closure { return function () { return $this->v; }; } }
function cl(): array { $a = []; for ($i = 0; $i < 4; $i++) { $a[] = (new Live($i))->hook(); } return $a; }
function closures(): void {
    $a = cl(); $s = array_slice($a, 1, 2); $r = array_reverse($a); $m = array_merge($a, $s);
    unset($a);
    echo Live::$n, ' ', $s[0](), $r[0](), $m[5](), "\n";
    unset($s, $r, $m);
    echo Live::$n, "\n";
}
closures();
// arrays reaching the copies through an untyped channel
function untyped($x) { return [array_reverse($x), array_slice($x, 1), array_merge($x, $x)]; }
echo json_encode(untyped([1, 2, 3])), json_encode(untyped(["a", "b"])), json_encode(untyped([1, "b", null, 2.5])), "\n";
