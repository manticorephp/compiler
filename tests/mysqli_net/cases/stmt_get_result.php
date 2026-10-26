<?php
// get_result(): a buffered mysqli_result over the binary protocol that behaves like a query() result (every fetch mode,
// fetch_field*, data_seek, lengths, iteration, free), the statement's state around it; execute_query() /
// mysqli_execute_query() with exact errors, state copied back to the connection, every report mode.
function t(string $l, callable $f): void
{
    try { $v = $f(); echo $l, ': ', json_encode($v), "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), ($e instanceof mysqli_sql_exception ? '|' . $e->getSqlState() : ''), "\n"; }
}
function sst(mysqli_stmt $s): string
{
    $o = [];
    foreach (['affected_rows', 'insert_id', 'num_rows', 'field_count', 'errno', 'error'] as $k) {
        try { $o[] = $k . '=' . json_encode($s->$k); } catch (Throwable $e) { $o[] = $k . '!' . $e->getMessage(); }
    }
    return implode(' ', $o);
}
function cst(mysqli $m): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno . ' state=' . $m->sqlstate . ' err=' . $m->error . ' info=' . json_encode($m->info);
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
$m->query('CREATE TEMPORARY TABLE g9 (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(20), n INT, x DOUBLE)');
$m->query("INSERT INTO g9 (a, n, x) VALUES ('one', 1, 1.5), ('two', NULL, -2), ('three', 3, NULL), ('', 4, 0.25)");

$s = $m->prepare('SELECT id, a, n, x, a AS a FROM g9 WHERE id >= ? ORDER BY id');
$from = 1;
$s->bind_param('i', $from);
$s->execute();
$r = $s->get_result();
echo 'after get_result: ', sst($s), ' | ', cst($m), "\n";
t('class', fn() => [get_class($r), $r->num_rows, $r->field_count, $r->current_field, $r->type, $r->lengths]);
t('fetch_assoc', fn() => [$r->fetch_assoc(), $r->lengths]);
t('fetch_row', fn() => [$r->fetch_row(), $r->lengths]);
t('fetch_array', fn() => $r->fetch_array());
t('fetch_object', fn() => $r->fetch_object());
t('fetch past end', fn() => [$r->fetch_row(), $r->lengths]);
t('data_seek', fn() => [$r->data_seek(1), $r->fetch_array(MYSQLI_NUM)]);
t('fetch_column', fn() => [$r->data_seek(0), $r->fetch_column(3), $r->fetch_column(1)]);
t('fetch_all', fn() => [$r->data_seek(0), $r->fetch_all(MYSQLI_ASSOC)]);
t('fetch_all both', fn() => [$r->data_seek(2), $r->fetch_all(MYSQLI_BOTH)]);
t('fetch_field', fn() => [$r->fetch_field(), $r->current_field]);
t('fetch_fields', fn() => array_map(fn($f) => [$f->name, $f->orgname, $f->table, $f->orgtable, $f->db, $f->type, $f->flags, $f->length, $f->max_length, $f->decimals, $f->charsetnr], $r->fetch_fields()));
t('fetch_field_direct', fn() => $r->fetch_field_direct(3));
t('field_seek', fn() => [$r->field_seek(2), $r->fetch_field()->name]);
t('iterate', function () use ($r) { $o = []; foreach ($r as $k => $row) { $o[$k] = $row; } return $o; });
t('iterate twice', function () use ($r) { $o = []; foreach ($r as $k => $row) { $o[] = $k; } return $o; });
t('stmt fetch after get_result', fn() => [$s->fetch(), sst($s)]);
t('stmt get_result twice', fn() => [$s->get_result(), sst($s)]);
t('query while result held', fn() => $m->query('SELECT 1')->fetch_row());
t('free', fn() => [$r->free(), $m->query('SELECT 2')->fetch_row()]);
t('closed result', fn() => $r->fetch_row());

echo "== re-execute while a result is held\n";
$s->execute();
$r1 = $s->get_result();
$from = 3;
$s->execute();
$r2 = $s->get_result();
t('two results', fn() => [$r1->fetch_all(), $r2->fetch_all()]);
t('stmt store then get_result', function () use ($s) { $s->execute(); $s->store_result(); return [$s->get_result(), sst($s)]; });
$s->free_result();
t('get_result unexecuted', function () use ($m) { $x = $m->prepare('SELECT 1'); return [$x->get_result(), sst($x)]; });
t('get_result after partial fetch', function () use ($s) {
    $s->execute();
    $s->bind_result($a, $b, $c, $d, $e);
    $s->fetch();
    return [$s->get_result(), sst($s)];
});
$s->free_result();
t('get_result empty', function () use ($m) { $x = $m->prepare('SELECT id FROM g9 WHERE id < 0'); $x->execute(); $r = $x->get_result(); return [$r->num_rows, $r->fetch_row(), $r->fetch_all(), $r->field_count]; });
t('result after stmt close', function () use ($m) { $x = $m->prepare('SELECT a FROM g9 ORDER BY id'); $x->execute(); $r = $x->get_result(); $x->close(); return $r->fetch_all(); });
t('p get_result', function () use ($m) { $x = mysqli_prepare($m, 'SELECT 7'); mysqli_stmt_execute($x); return mysqli_fetch_row(mysqli_stmt_get_result($x)); });

echo "== execute_query\n";
t('select', fn() => [$m->execute_query('SELECT a, n FROM g9 WHERE id > ? ORDER BY id', [1])->fetch_all(MYSQLI_ASSOC), cst($m)]);
t('select no params', fn() => $m->execute_query('SELECT 1 + 1')->fetch_row());
t('select null params', fn() => $m->execute_query('SELECT 1 + 2', null)->fetch_row());
t('select types', fn() => $m->execute_query('SELECT ?, ?, ?, 1, 1.5e0', [5, 'x', null])->fetch_row());
t('insert', fn() => [$m->execute_query('INSERT INTO g9 (a, n) VALUES (?, ?)', ['five', 5]), cst($m)]);
t('update', fn() => [$m->execute_query('UPDATE g9 SET n = n + 1 WHERE n > ?', [3]), cst($m)]);
t('syntax', fn() => [$m->execute_query('SELEC ?', [1]), cst($m)]);
t('exec error', fn() => [$m->execute_query('INSERT INTO g9 (id) VALUES (?)', [1]), cst($m)]);
t('few', fn() => [$m->execute_query('SELECT ?, ?', [1]), cst($m)]);
t('many', fn() => [$m->execute_query('SELECT ?', [1, 2]), cst($m)]);
t('assoc', fn() => [$m->execute_query('SELECT ?', ['k' => 1]), cst($m)]);
t('missing', fn() => [$m->execute_query('SELECT ?'), cst($m)]);
t('empty query', fn() => [$m->execute_query(''), cst($m)]);
t('p select', fn() => mysqli_execute_query($m, 'SELECT ? * 2', [21])->fetch_row());
t('p few', fn() => mysqli_execute_query($m, 'SELECT ?, ?', [1]));
t('p assoc', fn() => mysqli_execute_query($m, 'SELECT ?', [3 => 1]));
t('result type', function () use ($m) { $r = $m->execute_query('SELECT id FROM g9 ORDER BY id'); return [get_class($r), $r->type, $r->num_rows, $r->fetch_row()]; });
t('busy', function () use ($m) { $r = $m->query('SELECT 1', MYSQLI_USE_RESULT); $x = $m->execute_query('SELECT 2'); $o = [$x, cst($m)]; $r->free(); return $o; });
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
t('strict syntax', fn() => $m->execute_query('SELEC 1'));
t('strict exec error', fn() => $m->execute_query('INSERT INTO g9 (id) VALUES (?)', [1]));
t('strict few', fn() => $m->execute_query('SELECT ?, ?', [1]));
t('strict ok', fn() => $m->execute_query('SELECT ?', ['ok'])->fetch_row());
t('strict get_result twice', function () use ($m) { $x = $m->prepare('SELECT 1'); $x->execute(); $x->get_result(); return $x->get_result(); });
mysqli_report(MYSQLI_REPORT_ERROR);
t('error-only syntax', fn() => [@$m->execute_query('SELEC 1'), $m->errno]);
mysqli_report(MYSQLI_REPORT_OFF);
$m->close();
t('closed conn', fn() => $m->execute_query('SELECT 1'));
