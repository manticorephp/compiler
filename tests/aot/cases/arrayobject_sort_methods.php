<?php
// ArrayObject and ArrayIterator sort methods: asort, ksort, uasort, uksort, natsort, natcasesort.
function show(string $label, iterable $x): void
{
    $parts = [];
    foreach ($x as $k => $v) { $parts[] = $k . '=' . $v; }
    echo $label, ': ', implode(' ', $parts), "\n";
}
$data = ['b' => 'img12', 'a' => 'IMG10', 'd' => 'img2', 'c' => 'img1'];

foreach ([ArrayObject::class, ArrayIterator::class] as $cls) {
    echo "-- $cls\n";
    $o = new $cls($data);
    var_dump($o->ksort());
    show('ksort', $o);
    $o = new $cls($data);
    $o->ksort(SORT_STRING);
    show('ksort SORT_STRING', $o);
    $o = new $cls($data);
    var_dump($o->asort());
    show('asort', $o);
    $o = new $cls($data);
    $o->asort(SORT_STRING | SORT_FLAG_CASE);
    show('asort case-insensitive', $o);
    $o = new $cls($data);
    var_dump($o->uasort(fn(string $x, string $y): int => strlen($x) <=> strlen($y) ?: strcmp($x, $y)));
    show('uasort', $o);
    $o = new $cls($data);
    var_dump($o->uksort(fn(string $x, string $y): int => strcmp($y, $x)));
    show('uksort', $o);
    $o = new $cls($data);
    var_dump($o->natsort());
    show('natsort', $o);
    $o = new $cls($data);
    var_dump($o->natcasesort());
    show('natcasesort', $o);
}

echo "-- iterator over a sorted ArrayObject\n";
$ao = new ArrayObject([3 => 'c', 1 => 'a', 2 => 'b']);
$it = $ao->getIterator();
$ao->ksort();
show('live iterator', $it);
$it->asort();
show('iterator asort writes through', $ao);
