<?php
// mysqli_result::fetch_field / fetch_fields / fetch_field_direct / field_seek: every property, flag decoding, aliases, joins.
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function t(string $l, callable $f): void
{
    try { $v = $f(); echo $l, ': ', json_encode($v), "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), "\n"; }
}
function flags(int $f): string
{
    $names = ['NOT_NULL' => MYSQLI_NOT_NULL_FLAG, 'PRI_KEY' => MYSQLI_PRI_KEY_FLAG, 'UNIQUE_KEY' => MYSQLI_UNIQUE_KEY_FLAG,
        'MULTIPLE_KEY' => MYSQLI_MULTIPLE_KEY_FLAG, 'BLOB' => MYSQLI_BLOB_FLAG, 'UNSIGNED' => MYSQLI_UNSIGNED_FLAG,
        'ZEROFILL' => MYSQLI_ZEROFILL_FLAG, 'BINARY' => MYSQLI_BINARY_FLAG, 'ENUM' => MYSQLI_ENUM_FLAG,
        'AUTO_INCREMENT' => MYSQLI_AUTO_INCREMENT_FLAG, 'TIMESTAMP' => MYSQLI_TIMESTAMP_FLAG, 'SET' => MYSQLI_SET_FLAG,
        'NO_DEFAULT_VALUE' => MYSQLI_NO_DEFAULT_VALUE_FLAG, 'ON_UPDATE_NOW' => MYSQLI_ON_UPDATE_NOW_FLAG,
        'PART_KEY' => MYSQLI_PART_KEY_FLAG, 'NUM' => MYSQLI_NUM_FLAG, 'GROUP' => MYSQLI_GROUP_FLAG];
    $o = [];
    foreach ($names as $n => $v) { if (($f & $v) !== 0) { $o[] = $n; } }
    return implode('|', $o);
}
function dump(string $l, object|false $f): void
{
    if ($f === false) { echo $l, ": false\n"; return; }
    echo $l, ': ', json_encode($f), ' flags=', flags($f->flags), "\n";
}
$m = conn();
$m->query('CREATE TEMPORARY TABLE f7 (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ti TINYINT NOT NULL DEFAULT 1, si SMALLINT, mi MEDIUMINT UNSIGNED ZEROFILL, i INT, bi BIGINT UNSIGNED,
  dc DECIMAL(10,3), dcu DECIMAL(5,0) UNSIGNED, fl FLOAT, db DOUBLE, fl2 FLOAT(7,2), b BIT(10), bt BIT(1),
  d DATE, t TIME, t3 TIME(3), dt DATETIME, dt6 DATETIME(6), ts TIMESTAMP NULL, yr YEAR,
  e ENUM(\'a\',\'b\'), s SET(\'x\',\'y\'), c CHAR(5), vc VARCHAR(100), vcu VARCHAR(10) CHARACTER SET utf8mb4,
  bin BINARY(4), vb VARBINARY(8), tt TINYTEXT, tx TEXT, mt MEDIUMTEXT, lt LONGTEXT, tb TINYBLOB, bl BLOB, mb MEDIUMBLOB, lb LONGBLOB,
  js JSON, gm GEOMETRY, u INT UNIQUE, k INT, KEY kk (k))');
$m->query("INSERT INTO f7 (id, ti, si, mi, i, bi, dc, dcu, fl, db, fl2, b, bt, d, t, t3, dt, dt6, ts, yr, e, s, c, vc, vcu, bin, vb, tt, tx, mt, lt, tb, bl, mb, lb, js, u, k)
  VALUES (1, 1, 2, 3, 4, 18446744073709551615, 12.345, 99999, 1.5, 2.25, 3.14, b'1010101010', b'1', '2024-02-29', '-12:34:56', '01:02:03.456', '2024-02-29 12:00:01', '2024-02-29 12:00:01.123456', '2024-01-01 00:00:00', 2024,
  'b', 'x,y', 'ab', 'héllo', 'wörld', 'ab', 'cd', 'tt', 'text', 'medium', 'long', 'tb', 'blob', 'mb', 'lb', '{\"a\":1}', 5, 6)");
$r = $m->query('SELECT * FROM f7');
$n = 0;
while (($f = $r->fetch_field()) !== false) { dump('col' . $n++, $f); }
t('after end', fn() => $r->fetch_field());
t('current_field', fn() => $r->current_field);
$r->field_seek(2);
t('current after seek', fn() => $r->current_field);
dump('after seek', $r->fetch_field());
t('current after fetch', fn() => $r->current_field);
t('direct 0', fn() => $r->fetch_field_direct(0)->name);
t('direct 5', fn() => $r->fetch_field_direct(5)->name);
t('direct -1', fn() => $r->fetch_field_direct(-1));
t('direct 999', fn() => $r->fetch_field_direct(999));
t('direct leaves current', fn() => $r->current_field);
t('fields count', fn() => count($r->fetch_fields()));
t('fields classes', fn() => get_class($r->fetch_fields()[0]));
t('fields first', fn() => $r->fetch_fields()[0]);
t('fields reset current', fn() => $r->current_field);
$r->fetch_row();
$f = $r->fetch_fields();
t('max_length stored', fn() => array_map(fn($x) => $x->max_length, $f));
t('max_length fetch_field', fn() => $r->fetch_field_direct(7)->max_length);
$r->free();
t('freed fetch_field', fn() => $r->fetch_field());
t('freed fetch_fields', fn() => $r->fetch_fields());

echo "-- aliases, expressions, joins\n";
$m->query('CREATE TEMPORARY TABLE g7 (id INT, v VARCHAR(5))');
$m->query("INSERT INTO g7 VALUES (1, 'q')");
$r = $m->query('SELECT f7.id AS fid, g7.id gid, g7.v, f7.vc AS "a b", 1+1, NULL, \'lit\' AS lit, COUNT(*) AS cnt, MAX(f7.dc), CONCAT(f7.c, g7.v) cc, f7.ti + 0, CAST(5 AS UNSIGNED), CAST(5 AS CHAR), 1.5, 1e0, UNIX_TIMESTAMP(), NOW(), DATE(NOW()) dd FROM f7, g7 GROUP BY f7.id, g7.id, g7.v, f7.vc, f7.dc, f7.c, f7.ti');
foreach ($r->fetch_fields() as $i => $f) { dump('x' . $i, $f); }
echo "-- procedural\n";
$r = mysqli_query($m, 'SELECT id, vc FROM f7');
dump('proc', mysqli_fetch_field($r));
dump('proc direct', mysqli_fetch_field_direct($r, 1));
t('proc fields', fn() => array_map(fn($f) => $f->name, mysqli_fetch_fields($r)));
mysqli_field_seek($r, 1);
t('proc tell', fn() => mysqli_field_tell($r));
t('proc direct bad', fn() => mysqli_fetch_field_direct($r, 9));
echo "-- unbuffered\n";
$r = $m->query('SELECT id, vc FROM f7', MYSQLI_USE_RESULT);
dump('use', $r->fetch_field());
$r->fetch_row();
t('use max_length', fn() => $r->fetch_field_direct(1)->max_length);
$r->close();
echo "-- charsets\n";
$m->set_charset('latin1');
$r = $m->query('SELECT vc, vcu, bin FROM f7');
foreach ($r->fetch_fields() as $i => $f) { echo 'cs', $i, ' ', $f->charsetnr, ' ', $f->length, "\n"; }
$m->set_charset('utf8mb4');
$r = $m->query('SELECT vc, vcu, bin FROM f7');
foreach ($r->fetch_fields() as $i => $f) { echo 'cs', $i, ' ', $f->charsetnr, ' ', $f->length, "\n"; }
echo "-- constants\n";
foreach (['MYSQLI_TYPE_DECIMAL', 'MYSQLI_TYPE_TINY', 'MYSQLI_TYPE_SHORT', 'MYSQLI_TYPE_LONG', 'MYSQLI_TYPE_FLOAT', 'MYSQLI_TYPE_DOUBLE', 'MYSQLI_TYPE_NULL', 'MYSQLI_TYPE_TIMESTAMP', 'MYSQLI_TYPE_LONGLONG', 'MYSQLI_TYPE_INT24', 'MYSQLI_TYPE_DATE', 'MYSQLI_TYPE_TIME', 'MYSQLI_TYPE_DATETIME', 'MYSQLI_TYPE_YEAR', 'MYSQLI_TYPE_NEWDATE', 'MYSQLI_TYPE_BIT', 'MYSQLI_TYPE_JSON', 'MYSQLI_TYPE_NEWDECIMAL', 'MYSQLI_TYPE_ENUM', 'MYSQLI_TYPE_SET', 'MYSQLI_TYPE_TINY_BLOB', 'MYSQLI_TYPE_MEDIUM_BLOB', 'MYSQLI_TYPE_LONG_BLOB', 'MYSQLI_TYPE_BLOB', 'MYSQLI_TYPE_VAR_STRING', 'MYSQLI_TYPE_STRING', 'MYSQLI_TYPE_GEOMETRY', 'MYSQLI_TYPE_CHAR'] as $c) {
    echo $c, '=', constant($c), "\n";
}
