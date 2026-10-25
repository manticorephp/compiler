<?php
// Prepared statements, the input side: bind_param with i/d/s/b and NULL, the same variables re-read at every execute,
// value conversions per type letter (strings to i/d, floats to i, out-of-range to i, bools), execute(array) (every value a
// string), re-binding, the ArgumentCountError / ValueError texts, unbound / short binds, statement and connection state.
function t(string $l, callable $f): void
{
    try { $v = $f(); echo $l, ': ', json_encode($v), "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), "\n"; }
}
function sst(mysqli_stmt $s): string
{
    $o = [];
    foreach (['affected_rows', 'insert_id', 'num_rows', 'param_count', 'field_count', 'errno', 'error', 'sqlstate', 'error_list', 'id'] as $k) {
        try { $v = $s->$k; $o[] = $k . '=' . ($k === 'id' ? (is_int($v) && $v > 0 ? 'N' : json_encode($v)) : json_encode($v)); } catch (Throwable $e) { $o[] = $k . '!' . $e->getMessage(); }
    }
    return implode(' ', $o);
}
function rows(mysqli $m, string $q): string
{
    $r = $m->query($q);
    return json_encode($r->fetch_all(MYSQLI_ASSOC));
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
$m->query('CREATE TEMPORARY TABLE b9 (id INT AUTO_INCREMENT PRIMARY KEY, i BIGINT, d DOUBLE, s VARCHAR(64), b BLOB, u BIGINT UNSIGNED)');

$s = $m->prepare('INSERT INTO b9 (i, d, s, b) VALUES (?, ?, ?, ?)');
echo 'prepared: ', sst($s), "\n";
$i = 1; $d = 1.5; $str = 'one'; $b = 'blob1';
var_dump($s->bind_param('idsb', $i, $d, $str, $b));
t('exec 1', fn() => $s->execute());
echo 'after 1: ', sst($s), "\n";
echo 'conn: ar=', json_encode($m->affected_rows), ' id=', json_encode($m->insert_id), ' fc=', $m->field_count, "\n";
// the same variables, new values: execute reads them again
$i = -9223372036854775807 - 1; $d = -0.25; $str = "two\0with nul"; $b = 'ignored without send_long_data';
t('exec 2', fn() => $s->execute());
$i = null; $d = null; $str = null; $b = null;
t('exec null', fn() => $s->execute());
// conversions per type letter
$i = '42abc'; $d = '3.5e2'; $str = 17; $b = 1;
t('exec conv1', fn() => [$s->execute(), $s->errno, $s->error, $s->sqlstate]);
$i = 7.9; $d = 12; $str = 2.5; $b = true;
t('exec conv2', fn() => [$s->execute(), $s->errno, $s->error, $s->sqlstate]);
$i = true; $d = false; $str = true; $b = '';
t('exec conv3', fn() => [$s->execute(), $s->errno, $s->error, $s->sqlstate]);
$i = false; $d = true; $str = false; $b = 'x';
t('exec conv4', fn() => [$s->execute(), $s->errno, $s->error, $s->sqlstate]);
$i = 1e30; $d = '-inf?'; $str = -0.0; $b = 'y';
t('exec conv5', fn() => [$s->execute(), $s->errno, $s->error, $s->sqlstate]);
$i = '9223372036854775808'; $d = 1e300; $str = 1e20; $b = 'z';
t('exec conv6', fn() => [$s->execute(), $s->errno, $s->error, $s->sqlstate]);
$i = -1e19; $d = '  7'; $str = PHP_INT_MAX; $b = 'w';
t('exec conv7', fn() => [$s->execute(), $s->errno, $s->error, $s->sqlstate]);
$i = 'abc'; $d = ''; $str = ''; $b = 'v';
t('exec conv8', fn() => [$s->execute(), $s->errno, $s->error, $s->sqlstate]);
echo 'vars untouched: ', json_encode([$i, $d, $str, $b]), "\n";
echo rows($m, 'SELECT id, i, d, s, b FROM b9 ORDER BY id'), "\n";

// a variable bound twice
$m->query('DELETE FROM b9');
$x = 5;
$s2 = $m->prepare('INSERT INTO b9 (i, s) VALUES (?, ?)');
var_dump($s2->bind_param('is', $x, $x));
t('exec twice', fn() => $s2->execute());
$x = 'six';
t('exec twice b', fn() => $s2->execute());
// re-binding to other variables
$y = 100; $z = 'hundred';
var_dump($s2->bind_param('ds', $y, $z));
t('exec rebind', fn() => $s2->execute());
$y = 200;
t('exec rebind 2', fn() => $s2->execute());
// array elements and object properties as bound variables
$arr = [300, 'arr'];
var_dump($s2->bind_param('is', $arr[0], $arr[1]));
t('exec elems', fn() => $s2->execute());
$arr[0] = 301; $arr[1] = 'arr2';
t('exec elems 2', fn() => $s2->execute());
$o = new stdClass(); $o->a = 400; $o->b = 'obj';
var_dump($s2->bind_param('is', $o->a, $o->b));
$o->a = 401;
t('exec props', fn() => $s2->execute());
// undefined variables become NULL
var_dump($s2->bind_param('is', $undef1, $undef2));
t('exec undef', fn() => $s2->execute());
var_dump(isset($undef1), isset($undef2));
echo rows($m, 'SELECT i, s FROM b9 ORDER BY id'), "\n";

// argument errors
$s3 = $m->prepare('SELECT ?, ?');
t('types empty', fn() => $s3->bind_param('', $x));
t('types short', fn() => $s3->bind_param('i', $x, $y));
t('types long', fn() => $s3->bind_param('iii', $x, $y));
t('count != params', fn() => $s3->bind_param('i', $x));
t('count != params 3', fn() => $s3->bind_param('iii', $x, $y, $z));
t('bad letter', fn() => $s3->bind_param('ix', $x, $y));
t('upper letter', fn() => $s3->bind_param('Is', $x, $y));
t('after errors', fn() => sst($s3));
t('p types empty', fn() => mysqli_stmt_bind_param($s3, '', $x));
t('p bad letter', fn() => mysqli_stmt_bind_param($s3, 'iz', $x, $y));
t('p count', fn() => mysqli_stmt_bind_param($s3, 'i', $x));
t('p ok', fn() => mysqli_stmt_bind_param($s3, 'ss', $x, $y));
$s0 = $m->prepare('SELECT 1');
t('no params bind', fn() => $s0->bind_param('i', $x));
t('no params bind empty', fn() => $s0->bind_param(''));

// executing without binding
$s4 = $m->prepare('SELECT ?, ?, ?');
t('exec unbound', fn() => $s4->execute());
t('unbound state', fn() => sst($s4));

// execute(array)
$s5 = $m->prepare('SELECT ? AS a, ? AS b, ? AS c');
t('exec arr', fn() => $s5->execute([1, 2.5, null]));
t('exec arr res', fn() => $s5->get_result()->fetch_all());
t('exec arr types', function () use ($s5) {
    $s5->execute([true, false, -0.0]);
    $r = $s5->get_result();
    $o = [];
    foreach ($r->fetch_fields() as $f) { $o[] = $f->type; }
    return [$r->fetch_row(), $o];
});
t('exec arr few', fn() => $s5->execute([1, 2]));
t('exec arr many', fn() => $s5->execute([1, 2, 3, 4]));
t('exec arr empty', fn() => $s5->execute([]));
t('exec arr assoc', fn() => $s5->execute(['a' => 1, 'b' => 2, 'c' => 3]));
t('exec arr holes', fn() => $s5->execute([0 => 1, 2 => 2, 1 => 3]));
t('exec arr null', fn() => $s5->execute(null));
t('exec arr null res', fn() => $s5->get_result()->fetch_all());
t('p exec arr', fn() => mysqli_stmt_execute($s5, ['x', 'y', 'z']));
t('p exec arr res', fn() => mysqli_stmt_get_result($s5)->fetch_row());
t('p exec arr few', fn() => mysqli_stmt_execute($s5, [1]));
t('p exec arr assoc', fn() => mysqli_stmt_execute($s5, ['k' => 1]));
t('legacy mysqli_execute', fn() => @mysqli_execute($s5, [7, 8, 9]));
t('legacy res', fn() => $s5->get_result()->fetch_row());
// execute(array) replaces the bind_param binding (and its later executes use the array values)
$p1 = 'bound1'; $p2 = 'bound2'; $p3 = 'bound3';
$s5->bind_param('sss', $p1, $p2, $p3);
$s5->execute(['arr1', 'arr2', 'arr3']);
t('arr over bind', fn() => $s5->get_result()->fetch_row());
$p1 = 'changed';
$s5->execute();
t('after arr, plain execute', fn() => $s5->get_result()->fetch_row());
$s5->execute(['zero-param', 0, 0]);
$s5->get_result();
t('zero param array on no-param stmt', fn() => $s0->execute([]));
t('one param array on no-param stmt', fn() => $s0->execute([1]));
// $s0's result is unread: the connection is busy until it is fetched, stored or freed
t('prepare while busy', fn() => [$m->prepare('SELECT 2'), $m->errno, $m->error]);
t('query while busy', fn() => [$m->query('SELECT 2'), $m->errno, $m->error]);
t('other stmt while busy', fn() => [$s5->execute(['a', 'b', 'c']), $s5->errno, $s5->error, $m->errno]);
t('free busy', fn() => [$s0->free_result(), sst($s0), $m->errno]);
t('prepare after free', fn() => $m->prepare('SELECT 2') instanceof mysqli_stmt);

// connection-level effects of a statement
$m->query('DELETE FROM b9');
$s6 = $m->prepare('INSERT INTO b9 (s) VALUES (?), (?)');
$v1 = 'a'; $v2 = 'b';
$s6->bind_param('ss', $v1, $v2);
$s6->execute();
echo 'insert 2: ', sst($s6), ' conn ar=', json_encode($m->affected_rows), ' id_match=', json_encode($m->insert_id === $s6->insert_id), ' fc=', $m->field_count, "\n";
$u = $m->prepare('UPDATE b9 SET s = ? WHERE s = ?');
$n1 = 'c'; $n2 = 'a';
$u->bind_param('ss', $n1, $n2);
$u->execute();
echo 'update: ', sst($u), ' conn ar=', json_encode($m->affected_rows), ' info=', json_encode($m->info), "\n";
$u->execute();
echo 'update again: ', sst($u), ' conn ar=', json_encode($m->affected_rows), "\n";
$bu = $m->prepare('INSERT INTO b9 (u) VALUES (?)');
$big = '18446744073709551615';
$bu->bind_param('s', $big);
$bu->execute();
$big = '18446744073709551616';
t('out of range u', fn() => [$bu->execute(), sst($bu)]);
echo rows($m, 'SELECT s, u FROM b9 ORDER BY id'), "\n";
