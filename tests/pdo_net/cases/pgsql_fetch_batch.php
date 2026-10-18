<?php
// Manticore-only (PGSQL_ATTR_FETCH_BATCH is a superset: php has no cursor attribute); expectation lives in the sibling .expected file.
// Portal batches: Execute with a row limit, PortalSuspended, the next Execute on demand.
function mk(int $batch): PDO { return new PDO((string) getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 1300 => $batch]); }
function t(string $l, callable $f): void {
    try { $o = $l . ': ' . json_encode($f()) . "\n"; echo $o; }
    catch (Throwable $e) { echo $l, ': ', get_class($e), ' ', $e->getMessage(), ' ', json_encode($e->errorInfo ?? null), "\n"; }
}
function one(PDO $db, string $sql): mixed { $s = $db->query($sql); $v = $s->fetchColumn(); $s->closeCursor(); return $v; }
const Q = "select n, 'v' || n as s, n % 3 = 0 as b, case when n % 5 = 0 then null else n * 1.5 end as f, decode(lpad(to_hex(n % 256), 2, '0'), 'hex') as y from generate_series(1, %d) n";
$plain = mk(0);
$db = mk(1000);
t('attr', fn() => [$plain->getAttribute(1300), $db->getAttribute(1300)]);
t('negative', fn() => $db->setAttribute(1300, -1));
t('attr kept', fn() => $db->getAttribute(1300));

// identical rows to the buffered run, whatever the batch size against the row count
foreach ([1, 7, 999, 1000, 1001, 5000] as $batch) {
    $b = mk($batch);
    $s1 = $plain->query(sprintf(Q, 1000)); $want = [];
    while (($r = $s1->fetch(PDO::FETCH_ASSOC)) !== false) { $r['y'] = bin2hex(stream_get_contents($r['y'])); $want[] = $r; }
    $s2 = $b->query(sprintf(Q, 1000)); $got = [];
    while (($r = $s2->fetch(PDO::FETCH_ASSOC)) !== false) { $r['y'] = bin2hex(stream_get_contents($r['y'])); $got[] = $r; }
    echo "batch $batch: ", count($got), ' rows, ', $got === $want ? 'identical' : 'DIFFERENT', "\n";
    $s1 = null; $s2 = null;
}
t('exact multiple', function () { $b = mk(10); $s = $b->query('select n from generate_series(1, 30) n'); return count($s->fetchAll(PDO::FETCH_COLUMN)); });
t('empty result', function () { $b = mk(10); $s = $b->query('select n from generate_series(1, 0) n'); return [$s->fetch(), $s->rowCount(), $s->columnCount()]; });
t('fetchAll', function () use ($db) { $s = $db->query('select n from generate_series(1, 2500) n'); $a = $s->fetchAll(PDO::FETCH_COLUMN); return [count($a), array_sum($a)]; });
t('foreach', function () { $b = mk(3); $o = []; $s = $b->query('select n from generate_series(1, 8) n'); foreach ($s as $r) { $o[] = $r['n']; } return $o; });

// rowCount / columnCount
$b = mk(4);
$s = $b->query('select n, n * 2 as d from generate_series(1, 10) n');
t('columnCount', fn() => $s->columnCount());
t('rowCount before', fn() => $s->rowCount());
$s->fetch(); $s->fetch(); $s->fetch(); $s->fetch(); $s->fetch();
t('rowCount in batch 2', fn() => $s->rowCount());
$s->fetchAll();
t('rowCount at end', fn() => $s->rowCount());
$s = null;
t('dml rowCount', function () use ($b) { $b->exec('create temp table tb(a int)'); $s = $b->prepare('insert into tb select generate_series(1, 25)'); $s->execute(); return [$s->rowCount(), $s->columnCount(), one($b, 'select count(*) from tb')]; });

// the connection is held while the portal is open
$s = $b->query('select n from generate_series(1, 10) n');
t('exec while open', fn() => $b->exec('select 1'));
t('query while open', fn() => $b->query('select 2'));
t('prepare+execute while open', function () use ($b) { $p = $b->prepare('select 3'); $p->execute(); return 1; });
t('begin while open', fn() => $b->beginTransaction());
t('quote', fn() => $b->quote("a'b"));
t('fetch 1', fn() => $s->fetch(PDO::FETCH_NUM));
t('fetch rest', fn() => count($s->fetchAll()));
t('usable after end', fn() => one($b, 'select 4'));

// partial fetch, then close
$s = $b->query('select n from generate_series(1, 100000) n');
$s->fetch(); $s->fetch();
t('closeCursor', fn() => $s->closeCursor());
t('fetch after closeCursor', fn() => $s->fetch());
t('reuse after closeCursor', fn() => one($b, 'select 5'));
// early destruct
$s = $b->query('select n from generate_series(1, 100000) n');
$s->fetch();
$s = null;
t('reuse after destruct', fn() => one($b, 'select 6'));
// re-execute with rows left over
$p = $b->prepare('select n from generate_series(1, ?::int) n');
$p->execute([50]); $p->fetch();
t('re-execute', function () use ($p) { $p->execute([6]); return $p->fetchAll(PDO::FETCH_COLUMN); });
t('after re-execute', fn() => one($b, 'select 7'));
// a prepared statement closed (and deallocated) while another portal streams: deferred, nothing breaks
$p2 = $b->prepare('select 9');
$p2->execute(); $p2->fetchAll();
$s = $b->query('select n from generate_series(1, 20) n');
$s->fetch();
$p2 = null;
t('close stmt while open', fn() => count($s->fetchAll()));
t('after deferred close', fn() => one($b, 'select 8'));

// transaction: the portal lives inside it, an error poisons it as usual
t('txn stream', function () {
    $b = mk(5);
    $b->beginTransaction();
    $s = $b->query('select n from generate_series(1, 12) n');
    $n = count($s->fetchAll());
    $s = null;
    $b->exec('create temp table tx(a int)');
    $b->commit();
    return [$n, $b->inTransaction()];
});
t('txn close early', function () {
    $b = mk(5);
    $b->beginTransaction();
    $s = $b->query('select n from generate_series(1, 12) n');
    $s->fetch();
    $s->closeCursor();
    $b->exec('select 1');
    $r = $b->inTransaction();
    $b->rollBack();
    return $r;
});

// errors: after some rows (second batch), at the start (first batch), in a transaction
$e = mk(2);
$s = $e->query('select 10 / (n - 3) as v from generate_series(1, 9) n');
t('mid f1', fn() => $s->fetch(PDO::FETCH_NUM));
t('mid f2', fn() => $s->fetch(PDO::FETCH_NUM));
t('mid f3', fn() => $s->fetch(PDO::FETCH_NUM));
t('mid f4', fn() => $s->fetch(PDO::FETCH_NUM));
t('after mid error', fn() => one($e, 'select 11'));
$s = null;
t('first batch error', function () { $e = mk(100); $s = $e->query('select 10 / (n - 3) as v from generate_series(1, 9) n'); return 'no error'; });
t('syntax error', function () { $e = mk(100); $s = $e->query('selec 1'); return 'no error'; });
t('txn error', function () {
    $e = mk(2);
    $e->beginTransaction();
    $s = $e->query('select 10 / (n - 3) as v from generate_series(1, 9) n');
    $s->fetch(); $s->fetch();
    try { $s->fetch(); } catch (PDOException $x) { echo "  error ", $x->errorInfo[0], "\n"; }
    $s = null;
    try { $e->exec('select 1'); } catch (PDOException $x) { echo "  aborted ", $x->errorInfo[0], "\n"; }
    $e->rollBack();
    return one($e, 'select 12');
});

// memory stays flat over a big result; the buffered run of the same query does not
$base = memory_get_peak_usage();
$s = $db->query("select n, repeat('x', 64) as pad from generate_series(1, 300000) n");
$n = 0; $sum = 0; $len = 0;
while (($r = $s->fetch(PDO::FETCH_NUM)) !== false) { $n++; $sum += $r[0]; $len += strlen($r[1]); }
echo "rows=$n sum=$sum len=$len\n";
echo 'batched: ', memory_get_peak_usage() - $base < 16 * 1024 * 1024 ? 'flat' : 'grew', "\n";
$s = null;
$s = $plain->query("select n, repeat('x', 64) as pad from generate_series(1, 300000) n");
$n = 0;
while (($r = $s->fetch(PDO::FETCH_NUM)) !== false) { $n++; }
echo "buffered rows=$n ", memory_get_peak_usage() - $base < 16 * 1024 * 1024 ? 'flat' : 'grew', "\n";
$s = null;
$s = $db->query("select n, repeat('x', 64) as pad from generate_series(1, 300000) n");
$s->fetch();
$s->closeCursor();
t('close big', fn() => one($db, 'select 13'));
