<?php
// MYSQLI_USE_RESULT: num_rows before / after the full read, lengths, data_seek refusal, "Commands out of sync" for every
// command while the result is open (exact texts, every report mode), free / destructor releasing the connection, iterate once.
function t(string $l, callable $f): void
{
    try { $v = json_encode($f()); echo $l, ': ', $v, "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), '|', $e instanceof mysqli_sql_exception ? $e->getSqlState() : '', "\n"; }
}
function st(mysqli $m): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno . ' state=' . $m->sqlstate . ' err=' . $m->error;
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
$m->query('CREATE TEMPORARY TABLE t6 (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(20))');
$m->query("INSERT INTO t6 (a) VALUES ('x'),('y'),('z')");
$q = 'SELECT id, a FROM t6 ORDER BY id';
$r = $m->query($q, MYSQLI_USE_RESULT);
t('type', fn() => $r->type);
t('state', fn() => st($m));
t('num_rows before', fn() => $r->num_rows);
t('data_seek', fn() => $r->data_seek(1));
t('data_seek -1', fn() => $r->data_seek(-1));
t('data_seek p', fn() => mysqli_data_seek($r, 0));
t('num_rows p', fn() => mysqli_num_rows($r));
t('field_seek', fn() => [$r->field_seek(1), $r->current_field, $r->field_count]);
t('lengths none', fn() => $r->lengths);
t('row1', fn() => $r->fetch_row());
t('lengths1', fn() => $r->lengths);
t('num_rows mid', fn() => $r->num_rows);
echo "--- commands while open\n";
t('query', fn() => $m->query('SELECT 1'));
t('state', fn() => st($m));
t('query use', fn() => $m->query('SELECT 1', MYSQLI_USE_RESULT));
t('real_query', fn() => $m->real_query('SELECT 1'));
t('ping', fn() => @$m->ping());
t('select_db', fn() => $m->select_db(getenv('MYSQLI_DB')));
t('set_charset', fn() => $m->set_charset('utf8mb4'));
t('set_charset bad', fn() => $m->set_charset('nope'));
t('state', fn() => st($m));
t('store_result', fn() => $m->store_result());
t('use_result', fn() => $m->use_result());
t('more_results', fn() => $m->more_results());
t('next_result', fn() => [$m->next_result(), $m->errno]);
t('escape', fn() => $m->real_escape_string("a'"));
t('procedural', fn() => mysqli_query($m, 'SELECT 1'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
t('strict query', fn() => $m->query('SELECT 1'));
t('strict real_query', fn() => $m->real_query('SELECT 1'));
t('strict ping', fn() => @$m->ping());
t('strict select_db', fn() => $m->select_db(getenv('MYSQLI_DB')));
t('strict set_charset', fn() => $m->set_charset('utf8mb4'));
mysqli_report(MYSQLI_REPORT_OFF);
echo "--- finish the read\n";
t('row2', fn() => $r->fetch_row());
t('row3', fn() => $r->fetch_row());
t('num_rows before end', fn() => $r->num_rows);
t('end', fn() => $r->fetch_row());
t('num_rows after', fn() => $r->num_rows);
t('end2', fn() => $r->fetch_row());
t('lengths after end', fn() => $r->lengths);
t('state', fn() => st($m));
t('data_seek after', fn() => $r->data_seek(0));
t('query after', fn() => $m->query('SELECT 2')->fetch_row());
echo "--- free early\n";
$r = $m->query($q, MYSQLI_USE_RESULT);
$r->fetch_row();
$r->free();
t('query after free', fn() => $m->query('SELECT 3')->fetch_row());
t('state', fn() => st($m));
echo "--- destructor releases the connection\n";
$r = $m->query($q, MYSQLI_USE_RESULT);
$r->fetch_row();
$r = null;
t('query after drop', fn() => $m->query('SELECT 4')->fetch_row());
(function () use ($m) { $r = $m->query($q = 'SELECT id FROM t6', MYSQLI_USE_RESULT); $r->fetch_row(); })();
t('query after scope', fn() => $m->query('SELECT 5')->fetch_row());
echo "--- use_result\n";
t('rq', fn() => $m->real_query('SELECT id FROM t6'));
t('state', fn() => st($m));
$r = $m->use_result();
t('class', fn() => get_class($r));
t('num_rows', fn() => $r->num_rows);
t('more_results', fn() => $m->more_results());
t('fetch_all', fn() => $r->fetch_all());
t('num_rows', fn() => $r->num_rows);
t('state', fn() => st($m));
t('rq store', function () use ($m) { $m->real_query('SELECT id FROM t6'); $r = $m->store_result(); return [$r->num_rows, $r->type, $m->store_result()]; });
t('procedural', function () use ($m) {
    $r = mysqli_query($m, 'SELECT id FROM t6', MYSQLI_USE_RESULT);
    $o = [mysqli_num_rows($r), mysqli_fetch_row($r), mysqli_fetch_lengths($r)];
    mysqli_free_result($r);
    return $o;
});
echo "--- warnings arrive with the end of the rows\n";
$r = $m->query('SELECT 1/0', MYSQLI_USE_RESULT);
t('hdr', fn() => st($m));
$r->fetch_row();
t('row', fn() => st($m));
$r->fetch_row();
t('end', fn() => st($m));
$r->free();
echo "--- iterate once\n";
set_error_handler(fn() => true);
t('foreach', function () use ($m) {
    $r = $m->query('SELECT 1 a UNION SELECT 2 UNION SELECT 3', MYSQLI_USE_RESULT);
    $o = [];
    foreach ($r as $k => $v) { $o[] = [$k, $v]; }
    $e = [];
    foreach ($r as $v) { $e[] = $v; }
    return [$o, $e];
});
restore_error_handler();
t('foreach buffered twice', function () use ($m) {
    $r = $m->query('SELECT 1 a UNION SELECT 2');
    $r->fetch_row();
    $o = [];
    foreach ($r as $k => $v) { $o[] = [$k, $v]; }
    foreach ($r as $k => $v) { $o[] = [$k, $v]; }
    return $o;
});
echo "--- connection closed under an open result\n";
t('close', function () {
    $c = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
    $r = $c->query('SELECT 1 UNION SELECT 2', MYSQLI_USE_RESULT);
    $r->fetch_row();
    $c->close();
    return [$r->num_rows];
});
