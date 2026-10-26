<?php
// mysqli::query / real_query / store_result / use_result / more_results / next_result: return values, counters,
// argument errors, a closed or uninitialised handle, report modes, procedural twins, subclasses.
function t(string $l, callable $f): void
{
    try { $v = json_encode($f()); echo $l, ': ', $v, "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), "\n"; }
}
function st(mysqli $m): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ' info=' . json_encode($m->info) . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno . ' state=' . $m->sqlstate . ' err=' . $m->error;
}
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = conn();
$m->query('CREATE TEMPORARY TABLE t6 (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(20), b INT NULL)');
t('insert', fn() => $m->query("INSERT INTO t6 (a,b) VALUES ('x',1),('y',NULL),('z',3)"));
t('state', fn() => st($m));
t('update', fn() => $m->query('UPDATE t6 SET b = b + 1'));
t('state', fn() => st($m));
t('delete', fn() => $m->query('DELETE FROM t6 WHERE id = 3'));
t('state', fn() => st($m));
t('insert2', fn() => $m->query("INSERT INTO t6 (a) VALUES ('w')"));
t('state', fn() => st($m));
t('select', fn() => get_class($m->query('SELECT * FROM t6')));
t('state', fn() => st($m));
t('select warn', fn() => $m->query('SELECT 1/0')->num_rows);
t('state', fn() => st($m));
t('bad', fn() => $m->query('SELEC'));
t('state', fn() => st($m));
t('error_list', fn() => $m->error_list);
t('ok again', fn() => $m->query('SELECT 1')->fetch_row());
t('state', fn() => st($m));
t('empty', fn() => $m->query(''));
t('empty p', fn() => mysqli_query($m, ''));
t('badmode', fn() => $m->query('SELECT 1', 7));
t('badmode p', fn() => mysqli_query($m, 'SELECT 1', 99));
t('mode bitmask neg', fn() => $m->query('SELECT 1', -1));
t('real_query empty', fn() => $m->real_query(''));
t('state', fn() => st($m));
t('real_query empty p', fn() => mysqli_real_query($m, ''));
echo "--- real_query / store_result / use_result\n";
t('rq', fn() => $m->real_query('SELECT 1 AS one'));
t('fc', fn() => $m->field_count);
t('more', fn() => $m->more_results());
t('rq while pending', fn() => $m->real_query('SELECT 2'));
t('state', fn() => st($m));
t('query while pending', fn() => $m->query('SELECT 2'));
t('ping while pending', fn() => @$m->ping());
t('select_db while pending', fn() => $m->select_db(getenv('MYSQLI_DB')));
t('set_charset while pending', fn() => $m->set_charset('utf8mb4'));
$r = $m->store_result();
t('stored', fn() => [get_class($r), $r->fetch_row()]);
t('store again', fn() => $m->store_result());
t('use again', fn() => $m->use_result());
t('state', fn() => st($m));
t('rq dml', fn() => $m->real_query('DELETE FROM t6 WHERE id = 999'));
t('store dml', fn() => $m->store_result());
t('use dml', fn() => $m->use_result());
t('state', fn() => st($m));
t('store none err', function () use ($m) { $m->query('SELEC'); $x = $m->store_result(); return [$x, $m->errno]; });
t('use none err', function () use ($m) { $m->query('SELEC'); $x = $m->use_result(); return [$x, $m->errno]; });
t('more none', fn() => $m->more_results());
t('next none', function () use ($m) { $m->query('SELEC'); $x = $m->next_result(); return [$x, $m->errno, $m->error]; });
t('next none p', fn() => [mysqli_more_results($m), mysqli_next_result($m)]);
echo "--- procedural\n";
t('p query', function () use ($m) {
    $r = mysqli_query($m, 'SELECT 1 a, 2 b');
    return [get_class($r), mysqli_num_rows($r), mysqli_num_fields($r), mysqli_field_count($m), mysqli_fetch_assoc($r), mysqli_affected_rows($m)];
});
t('p real_query', function () use ($m) {
    $ok = mysqli_real_query($m, 'SELECT 7');
    $r = mysqli_store_result($m);
    return [$ok, mysqli_fetch_row($r), mysqli_store_result($m), mysqli_use_result($m)];
});
t('p dml', fn() => [mysqli_query($m, 'UPDATE t6 SET b = 9'), mysqli_affected_rows($m), mysqli_info($m), mysqli_insert_id($m)]);
t('p insert_id', fn() => [mysqli_query($m, "INSERT INTO t6 (a) VALUES ('p')"), mysqli_insert_id($m)]);
echo "--- closed / uninitialised\n";
$c = conn();
$c->close();
t('closed query', fn() => $c->query('SELECT 1'));
t('closed rq', fn() => $c->real_query('SELECT 1'));
t('closed empty', fn() => $c->query(''));
t('closed store', fn() => $c->store_result());
t('closed use', fn() => $c->use_result());
t('closed more', fn() => $c->more_results());
t('closed next', fn() => $c->next_result());
t('closed p', fn() => mysqli_query($c, 'SELECT 1'));
$n = new mysqli();
t('init query', fn() => $n->query('SELECT 1'));
t('init rq', fn() => $n->real_query('SELECT 1'));
t('init store', fn() => $n->store_result());
t('init more', fn() => $n->more_results());
echo "--- report modes\n";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
t('strict bad', fn() => $m->query('SELEC'));
t('strict state', fn() => st($m));
t('strict rq bad', fn() => $m->real_query('SELEC'));
t('strict ok', fn() => $m->query('SELECT 1')->fetch_row());
t('strict rq empty', fn() => $m->real_query(''));
t('strict next', fn() => $m->next_result());
mysqli_report(MYSQLI_REPORT_OFF);
echo "--- subclass\n";
class MyM extends mysqli {}
$mm = new MyM(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
t('sub', fn() => get_class($mm->query('SELECT 1')));
echo "--- stored procedure: several results\n";
$m->query('DROP PROCEDURE IF EXISTS p6');
t('create', fn() => $m->query('CREATE PROCEDURE p6() BEGIN SELECT 1 a; SELECT 2 b; END'));
t('call', function () use ($m) {
    $r = $m->query('CALL p6()');
    $o = [get_class($r), $r->fetch_row(), $m->more_results()];
    $o[] = $m->query('SELECT 5');
    $o[] = $m->errno;
    $o[] = $m->next_result();
    $r2 = $m->store_result();
    $o[] = get_class($r2);
    $o[] = $r2->fetch_row();
    $o[] = $m->more_results();
    $o[] = $m->next_result();
    $o[] = $m->store_result();
    $o[] = $m->more_results();
    $o[] = $m->next_result();
    $o[] = $m->errno;
    $o[] = $m->query('SELECT 6')->fetch_row();
    return $o;
});
$m->query('DROP PROCEDURE p6');
