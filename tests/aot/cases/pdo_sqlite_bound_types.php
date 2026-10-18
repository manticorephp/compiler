<?php
// bindColumn PARAM_* conversion of every native sqlite type, plus ATTR_STRINGIFY_FETCHES / ATTR_ORACLE_NULLS
$d = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = "select 5 as i, 2.5 as f, 'abc' as s, null as n, 1 as bt, 0 as bf, '12' as ns, '' as es, 7.50 as nm";
function show(mixed ...$xs): void {
    foreach ($xs as $i => $x) {
        if (is_resource($x)) { $x = 'stream:' . stream_get_contents($x); }
        echo $i + 1, ' ', var_export($x, true), "\n";
    }
}
foreach ([['STR', 2], ['INT', 1], ['BOOL', 5], ['NULL', 0], ['LOB', 3], ['ZVAL', -1], ['STMT', 4]] as [$nm, $t]) {
    echo "== $nm\n";
    $s = $d->prepare($sql);
    $s->bindColumn(1, $c1, $t); $s->bindColumn(2, $c2, $t); $s->bindColumn(3, $c3, $t);
    $s->bindColumn(4, $c4, $t); $s->bindColumn(5, $c5, $t); $s->bindColumn(6, $c6, $t);
    $s->bindColumn(7, $c7, $t); $s->bindColumn(8, $c8, $t); $s->bindColumn(9, $c9, $t);
    $s->execute();
    $s->fetch(PDO::FETCH_BOUND);
    show($c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9);
}
foreach ([false, true] as $str) {
    $d->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $str);
    echo "== stringify ", (int) $str, "\n";
    $s = $d->prepare($sql);
    $s->bindColumn(1, $c1); $s->bindColumn(2, $c2, PDO::PARAM_INT); $s->bindColumn(4, $c4, PDO::PARAM_NULL);
    $s->bindColumn(5, $c5, PDO::PARAM_BOOL); $s->bindColumn(8, $c8, PDO::PARAM_BOOL);
    $s->execute();
    $s->fetch(PDO::FETCH_BOUND);
    show($c1, $c2, $c4, $c5, $c8);
}
$d->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
$sql2 = "select 'abc' as a, null as b, null as c, '' as d, '' as e, '' as f";
foreach ([PDO::NULL_TO_STRING, PDO::NULL_EMPTY_STRING, PDO::NULL_NATURAL] as $o) {
    $d->setAttribute(PDO::ATTR_ORACLE_NULLS, $o);
    echo "== oracle $o\n";
    $s = $d->prepare($sql2);
    $s->bindColumn(1, $o1, PDO::PARAM_NULL); $s->bindColumn(2, $o2); $s->bindColumn(3, $o3, PDO::PARAM_INT);
    $s->bindColumn(4, $o4); $s->bindColumn(5, $o5, PDO::PARAM_INT); $s->bindColumn(6, $o6, PDO::PARAM_BOOL);
    $s->execute();
    $s->fetch(PDO::FETCH_BOUND);
    show($o1, $o2, $o3, $o4, $o5, $o6);
}
