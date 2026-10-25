<?php
// Binary-protocol value typing (prepared statements and execute_query): native ints/floats/strings, BIGINT UNSIGNED
// past PHP_INT_MAX, DECIMAL, BIT, YEAR, ZEROFILL, date/time forms with fractions, NULL, through bind_result (unbuffered
// and stored), get_result (every fetch mode) and execute_query; MYSQLI_OPT_INT_AND_FLOAT_NATIVE changes nothing here;
// result_metadata() / fetch_fields() and max_length with MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH; bound parameters round-trip.
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
mysqli_report(MYSQLI_REPORT_OFF);
$setup = [
    'CREATE TEMPORARY TABLE ty9 (id INT PRIMARY KEY, ti TINYINT, tu TINYINT UNSIGNED, si SMALLINT, su SMALLINT UNSIGNED, mi MEDIUMINT, mu MEDIUMINT UNSIGNED, i INT, iu INT UNSIGNED, bi BIGINT, bu BIGINT UNSIGNED,
      dc DECIMAL(10,3), dn DECIMAL(30,10), fl FLOAT, db DOUBLE, fd FLOAT(7,2), b BIT(10), b1 BIT(1), b64 BIT(64), d DATE, t TIME, t3 TIME(3), dt DATETIME, dt6 DATETIME(6), ts TIMESTAMP NULL, yr YEAR,
      e ENUM(\'a\',\'b\'), s SET(\'x\',\'y\'), c CHAR(3), vc VARCHAR(20), bn BINARY(2), bl BLOB, js JSON, zf INT(5) ZEROFILL, zb BIGINT(25) ZEROFILL, zt TINYINT(3) ZEROFILL)',
    "INSERT INTO ty9 VALUES (1, -128, 255, -32768, 65535, -8388608, 16777215, -2147483648, 4294967295, -9223372036854775808, 18446744073709551615,
      12.345, 12345678901234567890.1234567890, 1.5, 2.25, 3.14, b'1010101010', b'1', b'1111111111111111111111111111111111111111111111111111111111111111',
      '2024-02-29', '-838:59:59', '12:34:56.789', '2024-02-29 12:00:01', '2024-02-29 12:00:01.000042', '2024-01-01 00:00:00', 2024, 'b', 'x,y', 'ab', 'héllo', 'ab', 'blob', '{\"a\": 1}', 42, 7, 5)",
    "INSERT INTO ty9 (id) VALUES (2)",
    "INSERT INTO ty9 (id, ti, tu, si, mi, i, iu, bi, bu, dc, fl, db, fd, b, yr, vc, t, d, dt) VALUES (3, 127, 0, 32767, 8388607, 2147483647, 0, 9223372036854775807, 9223372036854775808, -0.001, 1e30, -1.7976931348623157e308, 0.01, b'0', 0, '', '00:00:00', '0000-00-00', '0000-00-00 00:00:00')",
    "INSERT INTO ty9 (id, bu, db, fl, b64, t, yr) VALUES (4, 9223372036854775807, 1e-300, 16777217, b'0111111111111111111111111111111111111111111111111111111111111111', '100:00:00.5', 1901)",
    "INSERT INTO ty9 (id, fl, db, fd, dc, zf, zb) VALUES (5, 3.4e38, 0.1, -0.5, 0.000, 0, 18446744073709551615), (6, 1.2e-38, 1e22, 99999.99, 9999999.999, 99999, 0)",
];
foreach ([false, true] as $native) {
    echo $native ? "== INT_AND_FLOAT_NATIVE on\n" : "== default\n";
    $m = conn($native);
    @$m->query("SET SESSION sql_mode = ''");
    foreach ($setup as $q) { if (!$m->query($q)) { echo 'ASSERTION FAILED: setup: ', $m->error, "\n"; } }
    $s = $m->prepare('SELECT * FROM ty9 ORDER BY id');
    $s->execute();
    $r = $s->get_result();
    show('row1 assoc', $r->fetch_assoc());
    show('row2 num', $r->fetch_row());
    show('row3 both', $r->fetch_array());
    show('row4 obj', json_encode($r->fetch_object()));
    show('rest', $r->fetch_all(MYSQLI_ASSOC));
    $r->data_seek(0);
    show('col bu', [$r->fetch_column(10), $r->fetch_column(10), $r->fetch_column(10), $r->fetch_column(10)]);
    if (!$native) {
        echo "-- bind_result, unbuffered\n";
        $s = $m->prepare('SELECT id, bu, b64, yr, zf, zb, zt, fl, db, fd, dc, b, t, t3, dt6, d, js FROM ty9 ORDER BY id');
        $s->execute();
        $s->bind_result($c0, $c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10, $c11, $c12, $c13, $c14, $c15, $c16);
        while ($s->fetch()) { var_dump([$c0, $c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10, $c11, $c12, $c13, $c14, $c15, $c16]); }
        echo "-- bind_result, stored\n";
        $s->execute();
        $s->store_result();
        $s->data_seek(3);
        $s->fetch();
        var_dump([$c0, $c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10, $c11, $c12, $c13, $c14, $c15, $c16]);
        $s->free_result();
        echo "-- execute_query\n";
        show('eq', $m->execute_query('SELECT bu, fl, yr, b, t3 FROM ty9 WHERE id = ?', [1])->fetch_row());
        show('expressions', $m->execute_query('SELECT 1 a, 1.5 b, 1e0 c, \'x\' d, NULL e, 18446744073709551615 f, -1 g, 0.1+0.2 h, 9007199254740993 i, CAST(1 AS DECIMAL(5,2)) j, 1 = 1 k, 7 / 2 l, 7 DIV 2 m')->fetch_assoc());
    }
    $m->close();
}

echo "== parameters round-trip\n";
$m = conn();
$s = $m->prepare('SELECT ?, ?, ?, ?, ? + 0, ? * 1.0');
$pi = PHP_INT_MAX; $pd = 0.1; $ps = 'x'; $pb = null; $pn = -5; $pf = 1e100;
$s->bind_param('idsiid', $pi, $pd, $ps, $pb, $pn, $pf);
$s->execute();
$r = $s->get_result();
show('values', $r->fetch_row());
show('types', array_map(fn($f) => [$f->type, $f->flags, $f->length, $f->decimals, $f->charsetnr], $r->fetch_fields()));

echo "== metadata\n";
$s = $m->prepare('SELECT id, a, n FROM (SELECT 1 id, \'abc\' a, 2.5 n UNION SELECT 22, \'de\', 10.25) z ORDER BY id');
$meta = $s->result_metadata();
show('class', [get_class($meta), $meta->num_rows, $meta->field_count, $meta->type, $meta->current_field, $meta->lengths]);
show('fields before execute', array_map(fn($f) => [$f->name, $f->type, $f->length, $f->max_length, $f->decimals, $f->flags, $f->table], $meta->fetch_fields()));
show('fetch_row on metadata', $meta->fetch_row());
show('fetch_field', (array) $meta->fetch_field());
$meta->free();
$s->execute();
$s->store_result();
show('max_length default', array_map(fn($f) => $f->max_length, $s->result_metadata()->fetch_fields()));
show('attr default', $s->attr_get(MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH));
show('attr set', $s->attr_set(MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH, 1));
show('attr get', $s->attr_get(MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH));
$s->execute();
$s->store_result();
show('max_length after UPDATE_MAX_LENGTH', array_map(fn($f) => $f->max_length, $s->result_metadata()->fetch_fields()));
$s->execute();
show('get_result fields', array_map(fn($f) => $f->max_length, $s->get_result()->fetch_fields()));
show('meta field_count', [$s->field_count, mysqli_stmt_field_count($s), $s->param_count, mysqli_stmt_param_count($s)]);
$s2 = $m->prepare('SELECT ? AS p');
show('SELECT ? meta before', array_map(fn($f) => [$f->type, $f->length, $f->charsetnr], $s2->result_metadata()->fetch_fields()));
$p = 12;
$s2->bind_param('i', $p);
$s2->execute();
show('SELECT ? meta after', array_map(fn($f) => [$f->type, $f->length, $f->charsetnr], $s2->result_metadata()->fetch_fields()));
$s2->free_result();
$md = mysqli_stmt_result_metadata($s2);
show('p result_metadata after free', $md === false ? false : mysqli_fetch_field_direct($md, 0)->name);
$s3 = $m->prepare('SELECT 5 AS five');
show('p result_metadata', mysqli_fetch_field_direct(mysqli_stmt_result_metadata($s3), 0)->name);
show('no result metadata', $m->prepare('DO 1')->result_metadata());
