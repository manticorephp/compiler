<?php
// bindColumn / PDO::FETCH_BOUND / by-reference bindParam against a live server (oracle: php)
function mk(array $o): PDO { return new PDO((string) getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
function t(string $l, callable $f): void {
    echo "-- $l\n";
    try { $f(); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
function show(mixed ...$xs): void {
    foreach ($xs as $i => $x) {
        if (is_resource($x)) { $x = 'stream:' . stream_get_contents($x); }
        echo $i + 1, ' ', var_export($x, true), "\n";
    }
}
{
    $db = mk([]);

$db->exec('create temporary table t (id int primary key, name text, f float8, n text, b bytea)');
$db->exec("insert into t values (1,'a',1.5,null,'bl'),(2,'bb',2.5,'x','zz')");
t('idx+name', function () use ($db) {
    $s = $db->prepare('select id, name, f, n from t order by id');
    var_dump($s->bindColumn(1, $a), $s->bindColumn('name', $b), $s->bindColumn(3, $c), $s->bindColumn(4, $e));
    $s->execute();
    while ($r = $s->fetch(PDO::FETCH_BOUND)) { var_dump($r); show($a, $b, $c, $e); }
    var_dump($s->fetch(PDO::FETCH_BOUND));
});
t('types', function () use ($db) {
    $s = $db->prepare('select id, name, f, n, id from t order by id');
    $s->bindColumn(1, $a, PDO::PARAM_STR); $s->bindColumn(2, $b, PDO::PARAM_INT); $s->bindColumn(3, $c, PDO::PARAM_INT);
    $s->bindColumn(4, $e, PDO::PARAM_NULL); $s->bindColumn(5, $g, PDO::PARAM_BOOL);
    $s->execute();
    while ($s->fetch(PDO::FETCH_BOUND)) { show($a, $b, $c, $e, $g); }
});
t('lob', function () use ($db) {
    $s = $db->prepare('select b, name from t');
    $s->bindColumn(1, $a, PDO::PARAM_LOB); $s->bindColumn(2, $b, PDO::PARAM_LOB);
    $s->execute(); $s->fetch(PDO::FETCH_BOUND);
    show($a, $b);
});
foreach ([['STR', 2], ['INT', 1], ['BOOL', 5], ['NULL', 0], ['LOB', 3], ['NATIVE', 99]] as [$nm, $ty]) {
    t("matrix $nm", function () use ($db, $ty) {
        $s = $db->prepare("select 5::int as i, 2.5::float8 as f, 'abc'::text as s, null::int as n, true as bt, false as bf, '12'::text as ns, ''::text as es, 7.50::numeric as nm");
        $s->bindColumn(1, $c1, $ty); $s->bindColumn(2, $c2, $ty); $s->bindColumn(3, $c3, $ty);
        $s->bindColumn(4, $c4, $ty); $s->bindColumn(5, $c5, $ty); $s->bindColumn(6, $c6, $ty);
        $s->bindColumn(7, $c7, $ty); $s->bindColumn(8, $c8, $ty); $s->bindColumn(9, $c9, $ty);
        $s->execute(); $s->fetch(PDO::FETCH_BOUND);
        show($c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9);
    });
}
t('nonexistent', function () use ($db) {
    $s = $db->prepare('select id from t order by id');
    var_dump($s->bindColumn('zzz', $a), $s->bindColumn(5, $b));
    try { $s->bindColumn(0, $c); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
    $s->execute(); var_dump($s->fetch(PDO::FETCH_BOUND)); print_r($s->errorInfo());
});
t('after execute / re-execute', function () use ($db) {
    $s = $db->prepare('select id, name from t order by id');
    $s->execute();
    $s->bindColumn(1, $a); $s->bindColumn('name', $b);
    $s->fetch(PDO::FETCH_BOUND); show($a, $b);
    $s->closeCursor(); $s->execute(); $s->fetch(PDO::FETCH_BOUND); show($a, $b);
});
t('fetchAll', function () use ($db) {
    $s = $db->prepare('select id from t order by id'); $s->bindColumn(1, $a); $s->execute();
    var_dump($s->fetchAll(PDO::FETCH_BOUND)); show($a);
});
t('foreach', function () use ($db) {
    $s = $db->prepare('select id, name from t order by id'); $s->bindColumn(1, $a); $s->bindColumn(2, $b);
    $s->setFetchMode(PDO::FETCH_BOUND); $s->execute();
    foreach ($s as $k => $r) { var_dump($k, $r); show($a, $b); }
});
t('bound under other modes', function () use ($db) {
    $s = $db->prepare('select id, name from t order by id'); $s->bindColumn(1, $a); $s->bindColumn(2, $b, PDO::PARAM_INT); $s->execute();
    print_r($s->fetch(PDO::FETCH_ASSOC)); show($a, $b);
    var_dump($s->fetchColumn(1)); show($a, $b);
    $s->execute(); print_r($s->fetchAll(PDO::FETCH_NUM)); show($a, $b);
});
t('stringify', function () use ($db) {
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    $s = $db->prepare('select id, f, n from t order by id'); $s->bindColumn(1, $a, PDO::PARAM_INT); $s->bindColumn(2, $b, PDO::PARAM_BOOL); $s->bindColumn(3, $c, PDO::PARAM_NULL);
    $s->execute(); $s->fetch(PDO::FETCH_BOUND); show($a, $b, $c);
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
});
foreach ([PDO::NULL_TO_STRING, PDO::NULL_EMPTY_STRING] as $o) {
    t("oracle $o", function () use ($db, $o) {
        $db->setAttribute(PDO::ATTR_ORACLE_NULLS, $o);
        $s = $db->prepare("select 'abc' as a, null as b, '' as c, '' as d, null as e");
        $s->bindColumn(1, $o1, PDO::PARAM_NULL); $s->bindColumn(2, $o2); $s->bindColumn(3, $o3); $s->bindColumn(4, $o4, PDO::PARAM_INT); $s->bindColumn(5, $o5, PDO::PARAM_INT);
        $s->execute(); $s->fetch(PDO::FETCH_BOUND); show($o1, $o2, $o3, $o4, $o5);
        $db->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
    });
}
t('case', function () use ($db) {
    $db->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
    $s = $db->prepare('select id, name from t order by id');
    var_dump($s->bindColumn('NAME', $a), $s->bindColumn('name', $b));
    $s->execute(); $s->fetch(PDO::FETCH_BOUND); show($a, $b);
    $db->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
});
t('bindParam mutate', function () use ($db) {
    $v = 1; $s = $db->prepare('select ? as a'); $s->bindParam(1, $v); $v = 2;
    $s->execute(); echo json_encode($s->fetch(PDO::FETCH_ASSOC)), "\n";
    $v = 3; $s->execute(); echo json_encode($s->fetch(PDO::FETCH_ASSOC)), "\n";
});
t('bindParam named + types', function () use ($db) {
    $v = '12'; $w = null; $s = $db->prepare('select :a as a, :b as b');
    $s->bindParam(':a', $v, PDO::PARAM_INT); $s->bindParam('b', $w, PDO::PARAM_INT); $v = '13'; $w = 4;
    $s->execute(); echo json_encode($s->fetch(PDO::FETCH_ASSOC)), "\n";
    $w = null; $s->execute(); echo json_encode($s->fetch(PDO::FETCH_ASSOC)), "\n";
});
t('bindParam then bindValue / array', function () use ($db) {
    $v = 1; $s = $db->prepare('select ? as a'); $s->bindParam(1, $v); $s->bindValue(1, 7); $v = 2;
    $s->execute(); echo json_encode($s->fetch(PDO::FETCH_ASSOC)), "\n";
    $s->bindValue(1, 7); $s->bindParam(1, $v); $v = 5;
    $s->execute(); echo json_encode($s->fetch(PDO::FETCH_ASSOC)), "\n";
    $s->execute([9]); echo json_encode($s->fetch(PDO::FETCH_ASSOC)), "\n";
    $v = 6; $s->execute(); echo json_encode($s->fetch(PDO::FETCH_ASSOC)), "\n";
});
t('bindParam insert loop', function () use ($db) {
    $s = $db->prepare('insert into t (id, name) values (:i, :n)');
    $s->bindParam(':i', $i, PDO::PARAM_INT); $s->bindParam(':n', $n);
    foreach ([[10, 'x'], [11, 'y']] as [$i, $n]) { $s->execute(); }
    echo json_encode($db->query('select id, name from t where id >= 10 order by id')->fetchAll(PDO::FETCH_NUM)), "\n";
});
t('bindParam bad index', function () use ($db) {
    $v = 1; $s = $db->prepare('select ? as a'); $s->bindParam(0, $v);
});
}
