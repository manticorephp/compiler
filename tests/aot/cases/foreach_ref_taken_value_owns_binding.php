<?php
// A loop variable that is also referenced (`$a[$k] = &$v`) lives in a box the
// frame owns and drops at return; the loop must give the box its own count on
// each borrowed element, or the return frees what the caller's array holds.
final class D
{
    public array $tags = [];
    public function __construct(int $i) { $this->tags['k'] = ['x' . $i]; }
}

function g($values, $isRef)
{
    foreach ($values as $k => $value) {
        if ($isRef) {
            $values[$k] = &$value;
        }
    }
    return $values;
}

function h(array $a)
{
    foreach ($a as $k => $v) {
        $a[$k] = &$v;
    }
    unset($v);
    return $a;
}

for ($i = 0; $i < 3; $i++) {
    $d = new D($i);
    $r = g(['tags' => $d->tags, 'n' => 'v' . $i], false);
    unset($r);
    $d->tags['m'] = [$i];
    echo json_encode($d->tags), "\n";
    $s = ['p' => ['q' . $i], 'r' => [1, 2]];
    $t = h($s);
    echo json_encode($t), ' ', json_encode($s), "\n";
}
