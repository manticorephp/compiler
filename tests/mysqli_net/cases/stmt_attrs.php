<?php
// attr_get / attr_set: UPDATE_MAX_LENGTH and CURSOR_TYPE values, the ValueError texts (method and procedural), unknown
// attributes (PREFETCH_ROWS = 2 is not one mysqlnd's mysqli accepts); a read-only server cursor: rows fetched one by one
// with COM_STMT_FETCH while the connection stays free for other queries, store_result / get_result over a cursor,
// re-execute and close with a cursor open, a statement the server opens no cursor for.
function t(string $l, callable $f): void
{
    try { $v = $f(); echo $l, ': ', json_encode($v), "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), "\n"; }
}
function sst(mysqli_stmt $s): string
{
    $o = [];
    foreach (['affected_rows', 'num_rows', 'field_count', 'errno', 'error'] as $k) {
        try { $o[] = $k . '=' . json_encode($s->$k); } catch (Throwable $e) { $o[] = $k . '!' . $e->getMessage(); }
    }
    return implode(' ', $o);
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
$m->query('CREATE TABLE IF NOT EXISTS a9 (id INT PRIMARY KEY, v VARCHAR(10))');
$m->query('DELETE FROM a9');
$m->query("INSERT INTO a9 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd')");
$s = $m->prepare('SELECT id, v FROM a9 ORDER BY id');
t('defaults', fn() => [$s->attr_get(MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH), $s->attr_get(MYSQLI_STMT_ATTR_CURSOR_TYPE)]);
foreach ([[0, 0], [0, 1], [0, 2], [0, -1], [1, 0], [1, 1], [1, 2], [1, 4], [1, 8], [1, -1], [2, 0], [2, 1], [3, 1], [-1, 0], [99, 1]] as [$a, $v]) {
    t("set $a=$v", fn() => [$s->attr_set($a, $v), $s->errno]);
}
foreach ([0, 1, 2, 3, -1] as $a) { t("get $a", fn() => $s->attr_get($a)); }
t('p set bad attr', fn() => mysqli_stmt_attr_set($s, 5, 0));
t('p set bad value', fn() => mysqli_stmt_attr_set($s, MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH, 7));
t('p set cursor bad', fn() => mysqli_stmt_attr_set($s, MYSQLI_STMT_ATTR_CURSOR_TYPE, 9));
t('p get bad attr', fn() => mysqli_stmt_attr_get($s, 9));
t('p get', fn() => [mysqli_stmt_attr_get($s, MYSQLI_STMT_ATTR_CURSOR_TYPE), mysqli_stmt_attr_set($s, MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_NO_CURSOR)]);

echo "== cursor\n";
t('set cursor', fn() => $s->attr_set(MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_READ_ONLY));
t('execute', fn() => [$s->execute(), sst($s)]);
$s->bind_result($id, $v);
t('query with cursor open', fn() => $m->query('SELECT 42')->fetch_row());
for ($k = 0; $k < 3; $k++) {
    $r = $s->fetch();
    echo 'fetch ', $k, ': ', json_encode([$r, $id, $v]), ' ', sst($s), "\n";
    echo '  interleaved: ', json_encode($m->query('SELECT COUNT(*) FROM a9')->fetch_row()), "\n";
}
t('re-execute mid cursor', fn() => [$s->execute(), sst($s)]);
for ($k = 0; $k < 6; $k++) {
    $r = $s->fetch();
    echo 'fetch ', $k, ': ', json_encode([$r, $id, $v]), ' ', sst($s), "\n";
}
t('store over cursor', function () use ($s) { $s->execute(); return [$s->store_result(), sst($s)]; });
$out = [];
while ($s->fetch()) { $out[] = $id . $v; }
echo 'stored rows: ', json_encode($out), "\n";
t('get_result over cursor', function () use ($s) { $s->execute(); $r = $s->get_result(); return [$r->num_rows, $r->fetch_all(), sst($s)]; });
t('free mid cursor', function () use ($s, $m) { $s->execute(); $s->fetch(); $s->free_result(); return [sst($s), $m->query('SELECT 1')->fetch_row()]; });
t('reset mid cursor', function () use ($s, $m) { $s->execute(); $s->fetch(); return [$s->reset(), sst($s), $s->fetch(), $m->query('SELECT 2')->fetch_row()]; });
t('two cursors', function () use ($m) {
    $x = $m->prepare('SELECT id FROM a9 ORDER BY id');
    $y = $m->prepare('SELECT v FROM a9 ORDER BY id DESC');
    $x->attr_set(MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_READ_ONLY);
    $y->attr_set(MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_READ_ONLY);
    $x->execute(); $y->execute();
    $x->bind_result($a); $y->bind_result($b);
    $o = [];
    while ($x->fetch() && $y->fetch()) { $o[] = $a . $b; }
    return $o;
});
t('no cursor for upsert', function () use ($m) {
    $x = $m->prepare('UPDATE a9 SET v = v WHERE id = 1');
    $x->attr_set(MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_READ_ONLY);
    return [$x->execute(), $x->affected_rows, $x->field_count];
});
t('cursor on SELECT literal', function () use ($m) {
    $x = $m->prepare('SELECT 1, 2');
    $x->attr_set(MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_READ_ONLY);
    $x->execute();
    $x->bind_result($a, $b);
    $o = [$x->fetch(), $a, $b, $x->fetch()];
    return $o;
});
t('close mid cursor', function () use ($m) {
    $x = $m->prepare('SELECT id FROM a9');
    $x->attr_set(MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_READ_ONLY);
    $x->execute();
    $x->fetch();
    $x->close();
    return $m->query('SELECT 3')->fetch_row();
});
t('attrs after re-prepare', fn() => [$s->prepare('SELECT 1'), $s->attr_get(MYSQLI_STMT_ATTR_CURSOR_TYPE), $s->attr_get(MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH)]);
$m->query('DROP TABLE a9');
