<?php
// Prepared statements, the output side: bind_result + fetch unbuffered and after store_result (true / null / false),
// re-binding, fewer/more variables, the connection held by an unread result ("Commands out of sync"), data_seek, num_rows,
// free_result, reset, close, re-execute with rows unread, re-prepare, statement and connection state at every step.
function t(string $l, callable $f): void
{
    try { $v = $f(); echo $l, ': ', json_encode($v), "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), "\n"; }
}
function sst(mysqli_stmt $s): string
{
    $o = [];
    foreach (['affected_rows', 'insert_id', 'num_rows', 'param_count', 'field_count', 'errno', 'error', 'sqlstate'] as $k) {
        try { $o[] = $k . '=' . json_encode($s->$k); } catch (Throwable $e) { $o[] = $k . '!' . $e->getMessage(); }
    }
    return implode(' ', $o);
}
function cst(mysqli $m): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno . ' info=' . json_encode($m->info);
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
$m->query('CREATE TEMPORARY TABLE f9 (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(20), n INT, x DOUBLE)');
$m->query("INSERT INTO f9 (a, n, x) VALUES ('one', 1, 1.5), ('two', NULL, -2), ('three', 3, NULL), (NULL, 4, 0.25)");

echo "== unbuffered\n";
$s = $m->prepare('SELECT id, a, n, x FROM f9 WHERE id >= ? ORDER BY id');
echo 'prepared: ', sst($s), ' | ', cst($m), "\n";
$from = 1;
$s->bind_param('i', $from);
var_dump($s->bind_result($id, $a, $n, $x));
t('execute', fn() => $s->execute());
echo 'executed: ', sst($s), ' | ', cst($m), "\n";
t('query while unread', fn() => [$m->query('SELECT 1'), $m->errno, $m->error]);
for ($k = 0; $k < 6; $k++) {
    $r = $s->fetch();
    echo 'fetch ', $k, ': ', var_export($r, true), ' ', json_encode([$id, $a, $n, $x]), ' ', sst($s), "\n";
}
echo 'ended: ', cst($m), "\n";
t('query after end', fn() => $m->query('SELECT 1')->fetch_row());

echo "== rebind and partial reads\n";
$from = 3;
t('execute 2', fn() => $s->execute());
var_dump($s->bind_result($id2, $a2, $n2, $x2));
t('fetch 1', function () use (&$s, &$s3, &$id, &$a, &$id2, &$a2, &$rn, &$rx) { return [$s->fetch(), $id2, $a2]; });
echo 'old vars kept: ', json_encode([$id, $a, $n, $x]), "\n";
// executing again with rows unread: the rest of the result is dropped first
$from = 2;
t('re-execute with rows unread', fn() => [$s->execute(), sst($s)]);
t('fetch after re-exec', function () use (&$s, &$s3, &$id, &$a, &$id2, &$a2, &$rn, &$rx) { return [$s->fetch(), $id2, $a2]; });
t('bind count low', fn() => $s->bind_result($q1));
t('bind count high', fn() => $s->bind_result($q1, $q2, $q3, $q4, $q5));
t('p bind count', fn() => mysqli_stmt_bind_result($s, $q1));
t('free_result', fn() => [$s->free_result(), sst($s)]);
t('fetch after free', fn() => [$s->fetch(), sst($s)]);
t('query after free', fn() => $m->query('SELECT 2')->fetch_row());

echo "== store_result\n";
$from = 1;
t('execute', fn() => $s->execute());
t('num_rows before store', fn() => $s->num_rows);
t('store_result', fn() => $s->store_result());
echo 'stored: ', sst($s), ' | ', cst($m), "\n";
t('store twice', fn() => [$s->store_result(), sst($s)]);
t('query while stored', fn() => $m->query('SELECT 3')->fetch_row());
$s->bind_result($id, $a, $n, $x);
while (($r = $s->fetch()) === true) { echo 'row: ', json_encode([$id, $a, $n, $x]), "\n"; }
var_dump($r);
var_dump($s->fetch());
t('data_seek 2', fn() => $s->data_seek(2));
t('fetch after seek', function () use (&$s, &$s3, &$id, &$a, &$id2, &$a2, &$rn, &$rx) { return [$s->fetch(), $id, $a]; });
t('data_seek 0', fn() => $s->data_seek(0));
t('fetch after seek 0', function () use (&$s, &$s3, &$id, &$a, &$id2, &$a2, &$rn, &$rx) { return [$s->fetch(), $id]; });
t('data_seek past end', fn() => $s->data_seek(99));
t('fetch past end', function () use (&$s, &$s3, &$id, &$a, &$id2, &$a2, &$rn, &$rx) { return [$s->fetch(), $id]; });
t('data_seek -1', fn() => $s->data_seek(-1));
t('p data_seek -1', fn() => mysqli_stmt_data_seek($s, -1));
t('p data_seek 1', function () use (&$s, &$s3, &$id, &$a, &$id2, &$a2, &$rn, &$rx) { return [mysqli_stmt_data_seek($s, 1), $s->fetch(), $id]; });
t('num_rows', fn() => [$s->num_rows, $s->num_rows(), mysqli_stmt_num_rows($s)]);
t('free', fn() => [$s->free_result(), sst($s)]);
t('fetch freed', fn() => $s->fetch());
t('data_seek freed', fn() => $s->data_seek(0));

echo "== no bind_result\n";
$s->execute();
t('fetch unbound', fn() => [$s->fetch(), $s->fetch(), sst($s)]);
$s->free_result();
echo "== seek while unbuffered\n";
$s->execute();
$s->bind_result($id, $a, $n, $x);
t('seek unbuffered', function () use (&$s, &$s3, &$id, &$a, &$id2, &$a2, &$rn, &$rx) { return [$s->data_seek(2), $s->fetch(), $id]; });
t('num_rows unbuffered', fn() => $s->num_rows);
while ($s->fetch()) { }
t('num_rows after end', fn() => $s->num_rows);

echo "== reset\n";
$s->execute();
t('reset with rows unread', fn() => [$s->reset(), sst($s), cst($m)]);
t('fetch after reset', function () use (&$s, &$s3, &$id, &$a, &$id2, &$a2, &$rn, &$rx) { return [$s->fetch(), $id]; });
t('query after reset', fn() => $m->query('SELECT 4')->fetch_row());
t('reset again', fn() => $s->reset());
t('p reset', fn() => mysqli_stmt_reset($s));
$s->execute();
$s->store_result();
t('reset stored', fn() => [$s->reset(), sst($s)]);
t('fetch stored after reset', function () use (&$s, &$s3, &$id, &$a, &$id2, &$a2, &$rn, &$rx) { return [$s->fetch(), $id]; });

echo "== upsert stmt\n";
$u = $m->prepare('UPDATE f9 SET n = n + 1 WHERE id <= ?');
$lim = 2;
$u->bind_param('i', $lim);
t('fetch before execute', fn() => [$u->fetch(), sst($u)]);
$u->execute();
t('upsert state', fn() => [sst($u), cst($m)]);
t('upsert fetch', fn() => [$u->fetch(), sst($u)]);
t('upsert store', fn() => [$u->store_result(), sst($u)]);
t('upsert get_result', fn() => [$u->get_result(), sst($u)]);
t('upsert bind_result', fn() => $u->bind_result($z));
t('upsert result_metadata', fn() => $u->result_metadata());
t('upsert free', fn() => [$u->free_result(), sst($u)]);

echo "== types after fetch\n";
$s2 = $m->prepare('SELECT 1, 2.5, NULL, "s", CAST(1 AS UNSIGNED), -1');
$s2->execute();
$s2->bind_result($v1, $v2, $v3, $v4, $v5, $v6);
$s2->fetch();
var_dump($v1, $v2, $v3, $v4, $v5, $v6);
// a bound variable that holds a reference elsewhere sees the write; a typed property bound by reference coerces
$holder = ['k' => 'old'];
$s2->execute();
$s2->bind_result($w1, $holder['k'], $w3, $w4, $w5, $w6);
$s2->fetch();
var_dump($holder["k"]);
$s2->free_result();

echo "== re-prepare\n";
$s3 = $m->stmt_init();
t('init state', fn() => sst($s3));
t('init execute', fn() => $s3->execute());
t('init fetch', fn() => $s3->fetch());
t('init prepare', fn() => $s3->prepare('SELECT a FROM f9 WHERE id = ?'));
echo 'prepared: ', sst($s3), "\n";
$one = 1;
$s3->bind_param('i', $one);
$s3->execute();
$s3->bind_result($ra);
$s3->fetch();
echo 'got: ', $ra, "\n";
t('prepare again with rows unread', fn() => [$s3->prepare('SELECT n, x FROM f9 WHERE id = ?'), sst($s3)]);
t('old binding gone', fn() => [$s3->execute(), $s3->errno, $s3->error]);
$s3->bind_param('i', $one);
$s3->execute();
$s3->bind_result($rn, $rx);
t('fetch new', function () use (&$s, &$s3, &$id, &$a, &$id2, &$a2, &$rn, &$rx) { return [$s3->fetch(), $rn, $rx]; });
t('prepare bad', fn() => [$s3->prepare('SELEC nope'), sst($s3), cst($m)]);
t('after bad prepare execute', fn() => [$s3->execute(), sst($s3)]);
t('after bad prepare fetch', fn() => [$s3->fetch(), sst($s3)]);
t('prepare empty', fn() => [$s3->prepare(''), sst($s3)]);
t('mysqli::prepare empty', fn() => [$m->prepare(''), $m->errno, $m->error]);
t('mysqli::prepare bad', fn() => [$m->prepare('SELEC nope'), $m->errno, $m->sqlstate, $m->error]);
t('p prepare bad', fn() => [mysqli_prepare($m, 'SELECT * FROM no_such_table'), mysqli_errno($m), mysqli_error($m)]);
t('ctor bad', function () use ($m) { $x = new mysqli_stmt($m, 'SELEC nope'); return sst($x); });
t('ctor good', function () use ($m) { $x = new mysqli_stmt($m, 'SELECT ?'); return sst($x); });
t('ctor null', function () use ($m) { $x = new mysqli_stmt($m, null); return sst($x); });
t('ctor no query', function () use ($m) { $x = new mysqli_stmt($m); return [sst($x), $x->prepare('SELECT 1, 2'), sst($x)]; });
t('p stmt_init', function () use ($m) { $x = mysqli_stmt_init($m); return [mysqli_stmt_prepare($x, 'SELECT 1'), mysqli_stmt_field_count($x), mysqli_stmt_param_count($x)]; });

echo "== close\n";
$s4 = $m->prepare('SELECT id FROM f9 ORDER BY id');
$s4->execute();
t('close with rows unread', fn() => $s4->close());
t('conn after close', fn() => [cst($m), $m->query('SELECT 5')->fetch_row()]);
t('closed state', fn() => sst($s4));
t('closed execute', fn() => $s4->execute());
t('closed fetch', fn() => $s4->fetch());
t('closed close', fn() => $s4->close());
t('closed p errno', fn() => mysqli_stmt_errno($s4));
t('closed id', fn() => $s4->id);
t('closed error_list', fn() => $s4->error_list);
$s5 = $m->prepare('SELECT id FROM f9 ORDER BY id');
$s5->execute();
unset($s5);
t('conn after unset with rows unread', fn() => $m->query('SELECT 6')->fetch_row());
