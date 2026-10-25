<?php
// Text-protocol value typing: strings by default, native int/float with MYSQLI_OPT_INT_AND_FLOAT_NATIVE, max_length after
// store_result, BIGINT UNSIGNED, DECIMAL/FLOAT/DOUBLE/BIT/YEAR/date forms, NULLs, every fetch mode.
function conn(bool $native = false): mysqli
{
    $m = mysqli_init();
    if ($native) { $m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1); }
    $m->real_connect(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
    return $m;
}
function show(string $l, mixed $v): void
{
    echo $l, ': ';
    var_dump($v);
}
function t(string $l, callable $f): void
{
    try { show($l, $f()); } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), "\n"; }
}
$setup = [
    'CREATE TEMPORARY TABLE ty7 (id INT PRIMARY KEY, ti TINYINT, tu TINYINT UNSIGNED, si SMALLINT, mi MEDIUMINT, i INT, iu INT UNSIGNED, bi BIGINT, bu BIGINT UNSIGNED,
      dc DECIMAL(10,3), dn DECIMAL(30,10), fl FLOAT, db DOUBLE, fd FLOAT(7,2), b BIT(10), b1 BIT(1), b64 BIT(64), d DATE, t TIME, dt DATETIME, ts TIMESTAMP NULL, yr YEAR,
      e ENUM(\'a\',\'b\'), s SET(\'x\',\'y\'), c CHAR(3), vc VARCHAR(20), bn BINARY(2), bl BLOB, js JSON, zf INT(5) ZEROFILL)',
    "INSERT INTO ty7 VALUES (1, -128, 255, -32768, -8388608, -2147483648, 4294967295, -9223372036854775808, 18446744073709551615,
      12.345, 12345678901234567890.1234567890, 1.5, 2.25, 3.14, b'1010101010', b'1', b'1111111111111111111111111111111111111111111111111111111111111111',
      '2024-02-29', '-12:34:56', '2024-02-29 12:00:01', '2024-01-01 00:00:00', 2024, 'b', 'x,y', 'ab', 'héllo', 'ab', 'blob', '{\"a\": 1}', 42)",
    "INSERT INTO ty7 (id) VALUES (2)",
    "INSERT INTO ty7 (id, ti, tu, si, mi, i, iu, bi, bu, dc, fl, db, fd, b, yr, vc) VALUES (3, 127, 0, 32767, 8388607, 2147483647, 0, 9223372036854775807, 9223372036854775808, -0.001, 1e30, -1.7976931348623157e308, 0.01, b'0', 0, '')",
    "INSERT INTO ty7 (id, bu, db, fl) VALUES (4, 9223372036854775809, 1e-300, 16777217)",
    "INSERT INTO ty7 (id, fl, db, fd, dc, zf) VALUES (5, 3.4e38, 0.1, -0.5, 0.000, 0), (6, 1.2e-38, 1e22, 99999.99, 9999999.999, 99999)",
];
foreach ([false, true] as $native) {
    echo $native ? "== native\n" : "== strings\n";
    $m = conn($native);
    foreach ($setup as $q) { $m->query($q); }
    $r = $m->query('SELECT * FROM ty7 ORDER BY id');
    show('row1 assoc', $r->fetch_assoc());
    show('row2 num', $r->fetch_row());
    show('row3 both', $r->fetch_array());
    show('row4 obj', json_encode($r->fetch_object()));
    $r->data_seek(0);
    show('all', $r->fetch_all(MYSQLI_ASSOC));
    $r->data_seek(0);
    t('col 0', fn() => $r->fetch_column(0));
    t('col bu', fn() => $r->fetch_column(8));
    $r->data_seek(0);
    foreach ($r as $i => $row) { echo 'iter ', $i, ' id=', var_export($row['id'], true), ' bu=', var_export($row['bu'], true), "\n"; }
    show('expressions', $m->query('SELECT 1 a, 1.5 b, 1e0 c, \'x\' d, NULL e, 18446744073709551615 f, -1 g, 0.1+0.2 h, 9007199254740993 i, CAST(1 AS DECIMAL(5,2)) j, 1 = 1 k, 7 / 2 l, 7 DIV 2 m, UNIX_TIMESTAMP(\'2024-01-01 00:00:00\') n, CURDATE() - CURDATE() o')->fetch_assoc());
    show('count/sum', $m->query('SELECT COUNT(*) c, SUM(i) s, AVG(ti) a, MAX(db) mx, MIN(bu) mn FROM ty7 WHERE id < 4')->fetch_assoc());
    $m->close();
}
echo "== max_length\n";
$m = conn();
foreach ($setup as $q) { $m->query($q); }
$r = $m->query('SELECT id, vc, bl, dc, b FROM ty7 ORDER BY id');
show('after query', array_map(fn($f) => $f->max_length, $r->fetch_fields()));
$m->real_query('SELECT id, vc, bl, dc, b FROM ty7 ORDER BY id');
$r = $m->store_result();
show('after store_result', array_map(fn($f) => $f->max_length, $r->fetch_fields()));
$m->real_query('SELECT id, vc, bl, dc, b FROM ty7 ORDER BY id');
$r = $m->use_result();
show('use_result', array_map(fn($f) => $f->max_length, $r->fetch_fields()));
$r->free();
$m->close();
echo "== modes\n";
$m = conn(true);
foreach ($setup as $q) { $m->query($q); }
show('option', $m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 0));
show('now strings', $m->query('SELECT id, db FROM ty7 WHERE id = 1')->fetch_assoc());
$m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
show('native again, same conn', $m->query('SELECT id, db FROM ty7 WHERE id = 1')->fetch_assoc());
$m->real_query('SELECT id, db FROM ty7 WHERE id = 1');
show('use_result native', $m->use_result()->fetch_assoc());
echo "== lengths\n";
foreach ([false, true] as $native) {
    $m = conn($native);
    $m->query('CREATE TEMPORARY TABLE ln7 (bt BIT(20), i INT, f FLOAT, s VARCHAR(9), y YEAR)');
    $m->query("INSERT INTO ln7 VALUES (b'1000000000000000', 123456, 1.5, 'héllo', 2024)");
    $r = $m->query('SELECT * FROM ln7');
    $r->fetch_row();
    show($native ? 'native lengths' : 'string lengths', $r->lengths);
}
echo "== option flipped between query and fetch\n";
foreach ([false, true] as $first) {
    $m = conn($first);
    $m->query('CREATE TEMPORARY TABLE fl7 (bt BIT(20), i INT, f FLOAT)');
    $m->query("INSERT INTO fl7 VALUES (b'1000000000000000', 5, 1.5), (b'11', 6, 2.5)");
    $r = $m->query('SELECT * FROM fl7');
    $m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, $first ? 0 : 1);
    show('flipped after query, row 1', $r->fetch_row());
    $r->data_seek(1);
    show('flipped after query, row 2', $r->fetch_assoc());
    $m->real_query('SELECT * FROM fl7');
    $r = $m->use_result();
    $m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, $first ? 1 : 0);
    show('flipped after use_result', $r->fetch_row());
    $r->free();
}
