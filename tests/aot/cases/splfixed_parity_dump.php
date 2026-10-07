<?php
$f = SplFixedArray::fromArray([1, 'x', null, [2, 3]]);
var_dump($f);
print_r($f);
echo json_encode($f), ' ', json_encode(['k' => $f]), "\n";
var_dump($f->toArray() === [1, 'x', null, [2, 3]], count($f), $f->getSize());
$s = serialize($f);
echo $s, "\n";
$u = unserialize($s);
echo get_class($u), ' ', count($u), ' ', $u[1], ' ', json_encode($u->toArray()), "\n";
foreach ($f as $k => $v) { echo $k, '=', var_export($v, true), "\n"; }
var_dump(isset($f[0]), isset($f[2]), isset($f[9]), isset($f['1']), empty($f[1]));
$g = SplFixedArray::fromArray([3 => 'three', 1 => 'one']);
echo count($g), ' ', json_encode($g->toArray()), "\n";
$h = SplFixedArray::fromArray(['a' => 1, 'b' => 2], false);
echo json_encode($h->toArray()), "\n";
foreach ([fn () => $f[5], fn () => $f[-1], fn () => $f['a'], fn () => $f[true], fn () => new SplFixedArray(-1),
    fn () => $f->setSize(-1), fn () => SplFixedArray::fromArray(['a' => 1]), function () use ($f) { $f[9] = 1; }, function () use ($f) { $f[] = 1; },
    function () use ($f) { unset($f[9]); }] as $t) {
    try { $r = $t(); echo 'ok ', var_export($r, true), "\n"; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
$f->setSize(6); $f[5] = 'tail';
echo json_encode($f->toArray()), "\n";
$f->setSize(0);
echo count($f), ' ', json_encode($f->toArray()), "\n";
