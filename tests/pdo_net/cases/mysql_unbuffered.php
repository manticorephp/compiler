<?php
// Unbuffered cursors (MYSQL_ATTR_USE_BUFFERED_QUERY = false, 1000): rows are read from the socket one at a time.
function mk(bool $emu): PDO { return new PDO((string) getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 1000 => false, PDO::ATTR_EMULATE_PREPARES => $emu]); }
function t(string $l, callable $f): void {
    try { $o = $l . ': ' . json_encode($f()) . "\n"; echo $o; }
    catch (Throwable $e) { echo $l, ': ', get_class($e), ' ', $e->getMessage(), ' ', json_encode($e->errorInfo ?? null), "\n"; }
}
function one(PDO $db, string $sql): mixed { $s = $db->query($sql); $v = $s->fetchColumn(); $s->closeCursor(); return $v; }
foreach ([true, false] as $emu) {
    echo "== emulate=", var_export($emu, true), "\n";
    $db = mk($emu);
    t('attr', fn() => $db->getAttribute(1000));
    $s = $db->query('select 1 a union select 2 union select 3');
    t('rowCount', fn() => $s->rowCount());
    t('colCount', fn() => $s->columnCount());
    t('exec-while-open', fn() => $db->exec('select 1'));
    t('query-while-open', fn() => $db->query('select 2')->fetchAll());
    t('prepare-exec-while-open', function () use ($db) { $p = $db->prepare('select 3'); $p->execute(); return $p->fetchAll(); });
    t('quote', fn() => $db->quote("a'b"));
    t('begin', fn() => $db->beginTransaction());
    t('inTransaction', fn() => $db->inTransaction());
    t('fetch1', fn() => $s->fetch(PDO::FETCH_NUM));
    t('rowCount after1', fn() => $s->rowCount());
    t('fetch2', fn() => $s->fetch(PDO::FETCH_NUM));
    t('fetch3', fn() => $s->fetch(PDO::FETCH_NUM));
    t('fetch4', fn() => $s->fetch(PDO::FETCH_NUM));
    t('rowCount after end', fn() => $s->rowCount());
    t('query after exhausted', fn() => one($db, 'select 9'));
    // partial fetch, then close
    $s = $db->query('select 1 a union select 2 union select 3');
    $s->fetch();
    t('closeCursor', fn() => $s->closeCursor());
    t('fetch after closeCursor', fn() => $s->fetch());
    t('reuse after closeCursor', fn() => one($db, 'select 10'));
    // early destruct
    $s = $db->query('select 1 a union select 2 union select 3');
    $s->fetch();
    unset($s);
    t('reuse after unset', fn() => one($db, 'select 11'));
    $s = null;
    // re-execute the same statement with rows left over
    $p = $db->prepare('select 1 a union select 2');
    $p->execute(); $p->fetch();
    t('re-execute', function () use ($p) { $p->execute(); return $p->fetchAll(PDO::FETCH_COLUMN); });
    t('after reexec', fn() => one($db, 'select 12'));
    // a second statement while the first streams
    $p1 = $db->prepare('select 1 a union select 2'); $p1->execute();
    t('second prepare+execute', function () use ($db) { $p2 = $db->prepare('select 5'); $p2->execute(); return 1; });
    t('p1 still', fn() => $p1->fetchAll(PDO::FETCH_COLUMN));
    t('p2 after p1 done', function () use ($db) { $p2 = $db->prepare('select 5'); $p2->execute(); return $p2->fetchAll(PDO::FETCH_COLUMN); });
    // error after some rows
    $s = $db->query("select if(n=3, json_extract('bad','$'), n) v from (select 1 n union all select 2 union all select 3 union all select 4) x");
    t('mid f1', fn() => $s->fetch(PDO::FETCH_NUM));
    t('mid f2', fn() => $s->fetch(PDO::FETCH_NUM));
    t('mid f3', fn() => $s->fetch(PDO::FETCH_NUM));
    t('mid f4', fn() => $s->fetch(PDO::FETCH_NUM));
    $s->closeCursor();
    t('after err', fn() => one($db, 'select 13'));
    // types survive streaming (text and binary rows)
    $s = $db->query("select 7 i, 1.5 d, 'x' s, null n, cast('2024-01-02 03:04:05' as datetime) dt, 18446744073709551615 u");
    t('types', fn() => [$s->fetch(PDO::FETCH_ASSOC), $s->fetch(PDO::FETCH_ASSOC)]);
    // fetchAll / iteration / fetchColumn
    t('fetchAll', fn() => $db->query('select 1 a union select 2 union select 3')->fetchAll(PDO::FETCH_COLUMN));
    t('foreach', function () use ($db) { $o = []; foreach ($db->query('select 4 a union select 5') as $r) { $o[] = $r['a']; } return $o; });
    // no result set: still buffered semantics
    t('dml', fn() => $db->exec('do 1'));
    t('dml stmt', function () use ($db) { $s = $db->prepare('do 2'); $s->execute(); return [$s->rowCount(), $s->columnCount()]; });
    // multiple results
    if ($emu) {
        $s = $db->query('select 1 a; select 2 b, 3 c');
        t('multi1', fn() => $s->fetchAll(PDO::FETCH_NUM));
        t('nextRowset', fn() => $s->nextRowset());
        t('multi2', fn() => $s->fetchAll(PDO::FETCH_NUM));
        t('nr3', fn() => $s->nextRowset());
        t('after multi', fn() => one($db, 'select 14'));
        $s = $db->query('select 1 a union select 2; select 2 b, 3 c');
        $s->fetch();
        t('while more results', fn() => $db->query('select 1'));
        t('nextRowset partial', fn() => $s->nextRowset());
        t('multi2b', fn() => $s->fetchAll(PDO::FETCH_NUM));
        t('after multi', fn() => one($db, 'select 15'));
        $s = $db->query('select 1 a union select 2; select 2 b, 3 c');
        $s->fetch();
        t('closeCursor multi', fn() => $s->closeCursor());
        t('after closeCursor multi', fn() => one($db, 'select 16'));
        $s = $db->query('select 1 a union select 2; select 2 b, 3 c');
        unset($s);
        t('after unset multi', fn() => one($db, 'select 17'));
    }
    t('setAttribute buffered', function () use ($db) { $db->setAttribute(1000, true); return $db->getAttribute(1000); });
    t('buffered rowCount', fn() => $db->query('select 1 union select 2')->rowCount());
    t('buffered no 2014', function () use ($db) { $s = $db->query('select 1 union select 2'); return $db->query('select 3')->fetchColumn(); });
}
