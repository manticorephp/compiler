<?php
function f(array ...$xs) { return count($xs); }
/** @param array<int,mixed> ...$xs */
function g(...$xs) { return count($xs); }
function build(): array {
    $l = [];
    foreach ([[10, ['a', 1]], [5, ['b', 2]]] as [$p, $x]) { $l[$p][] = $x; }
    return $l;
}
function show(string $t, $a, $b = null) { echo $t, ' ', json_encode($a), ' ', json_encode($b), "\n"; }

$l = build();
$m = array_merge(...$l);
show('merge', $l, $m);

$l = []; $l[0][] = 1; $l[1][] = 2;
$m = array_merge(...$l);
show('list', $l, $m);

$l = []; $l[0]['k'] = 1; $l[1]['j'] = 2;
$m = array_merge(...$l);
show('assoc', $l, $m);

$l = build();
$m = array_merge([9], ...$l);
show('prefix', $l, $m);

$l = build();
show('variadic', $l, f(...$l));
show('variadic2', $l, g(...$l));

$l = []; $l[0][] = ["a", 1]; $l[1][] = ["b", 2]; $pz = [[0]];
array_push($pz, ...$l);
show("push", $l, $pz);

$l = []; $l[0][1] = 1; $l[0][2] = 2; $l[1][2] = 5; $l[1][3] = 6;
$r = array_intersect_key(...$l);
show('intersect', $l, $r);

$l = build();
$z = [...$l, ...$l];
$z = null;
show('literal', $l);

$l = []; $l[0][0][] = 'p'; $l[1][0][] = 'q';
$m = array_merge(...$l);
$m2 = array_merge(...$m);
show('depth2', $l, $m2);
show('depth2m', $m);

// no leak: the spread must give back exactly the counts it took
function leakcheck(): string {
    $l = [];
    for ($i = 0; $i < 4; $i++) { for ($j = 0; $j < 8; $j++) { $l[$i][] = "s$j"; } }
    for ($k = 0; $k < 20000; $k++) {
        $m = array_merge(...$l);
        $z = [...$l];
        $z = null;
    }
    $before = memory_get_peak_usage();
    for ($k = 0; $k < 200000; $k++) {
        $m = array_merge(...$l);
        $z = [...$l];
        $z = null;
    }
    return memory_get_peak_usage() - $before < 1024 * 1024 ? 'flat' : 'LEAK';
}
echo leakcheck(), "\n";
