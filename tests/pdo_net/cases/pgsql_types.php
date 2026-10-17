<?php
$db = new PDO(getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function t(callable $f): void
{
    try { var_dump($f()); } catch (PDOException $e) { echo get_class($e), ' ', json_encode($e->getMessage()), ' ', json_encode($e->errorInfo), "\n"; }
}
function show(mixed $v): string { return is_resource($v) ? 'res:' . bin2hex(stream_get_contents($v)) : (is_int($v) ? 'int ' . $v : var_export($v, true)); }

// every OID php maps, plus the ones it leaves as strings
$st = $db->query("select 1::int2 a, 2::int4 b, 3::int8 c, true d, false e, 1.5::float4 f, 2.25::float8 g, '12.50'::numeric h,
  'txt'::text i, 'vc'::varchar(5) j, 'ch'::char(4) k, '2020-01-02'::date l, '2020-01-02 03:04:05'::timestamp m, '{\"a\": 1}'::json n,
  '{\"a\": 1}'::jsonb o, 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'::uuid p, null q, 7::oid r, '\\x00ff10'::bytea s, array[1,2] t");
foreach ($st->fetch(PDO::FETCH_NUM) as $i => $v) { echo $i, ' ', show($v), "\n"; }
$st = $db->query("select 'NaN'::float8 a, 'Infinity'::float8 b, '-Infinity'::float8 c, '-0'::float8 d, 'NaN'::float4 e, 9223372036854775807::int8 f, (-9223372036854775807 - 1)::int8 g, 1e100::float8 h, 0.1::float4 i, 123456789012345678::float8 j");
foreach ($st->fetch(PDO::FETCH_NUM) as $i => $v) { echo $i, ' ', is_float($v) ? (is_nan($v) ? 'NAN' : var_export($v, true) . ' ' . (1 / ($v ?: 1) < 0 ? 'neg' : 'pos')) : show($v), "\n"; }
var_dump(-0.0);

// round trips through bound parameters
$st = $db->prepare('select ?::text as v, ?::text is null as isnull, length(?::text) as len');
foreach ([null, '', "héllo 😀", '9223372036854775807', '-0.0', "it's \\ \"q\"", 'NaN'] as $v) {
    $st->execute([$v, $v, $v]); echo json_encode($st->fetch(PDO::FETCH_NUM)), "\n";
}
$st->execute(["a\0b", "a\0b", "a\0b"]); echo json_encode($st->fetch(PDO::FETCH_NUM)), "\n";
$st = $db->prepare('select ?::int8, ?::float8, ?::float8, ?::float8, ?::float8');
$st->execute(['9223372036854775807', '-0.0', 'NaN', 'Infinity', '-Infinity']);
foreach ($st->fetch(PDO::FETCH_NUM) as $i => $v) { echo $i, ' ', is_float($v) ? (is_nan($v) ? 'NAN' : var_export($v, true)) : show($v), "\n"; }
$st = $db->prepare('select ?::int8');
$st->bindValue(1, PHP_INT_MAX, PDO::PARAM_INT); $st->execute(); var_dump($st->fetchColumn());
$st->bindValue(1, PHP_INT_MIN, PDO::PARAM_INT); $st->execute(); var_dump($st->fetchColumn());

// bind types
$st = $db->prepare('select ?::text');
foreach ([[true, PDO::PARAM_BOOL], [false, PDO::PARAM_BOOL], [3, PDO::PARAM_BOOL], [0, PDO::PARAM_BOOL], ['0', PDO::PARAM_BOOL],
          [true, PDO::PARAM_STR], [false, PDO::PARAM_STR], [true, PDO::PARAM_INT], [false, PDO::PARAM_INT], [12, PDO::PARAM_STR],
          [1.5, PDO::PARAM_STR], [null, PDO::PARAM_STR], [null, PDO::PARAM_NULL], ['x', PDO::PARAM_NULL], ["5", PDO::PARAM_INT]] as [$v, $ty]) {
    $st->bindValue(1, $v, $ty); $st->execute(); echo json_encode($ty), ' ', var_export($v, true), ' => ', var_export($st->fetchColumn(), true), "\n";
}
$st = $db->prepare('select ?::bool'); $st->bindValue(1, true, PDO::PARAM_BOOL); $st->execute(); var_dump($st->fetchColumn());
$st->bindValue(1, false, PDO::PARAM_BOOL); $st->execute(); var_dump($st->fetchColumn());

// bytea round trip: a binary-safe parameter in, a stream out
$bin = '';
for ($i = 0; $i < 256; $i++) { $bin .= chr($i); }
$bin .= "\\x41 '\"";
$db->exec('create temp table b(id int, d bytea)');
$st = $db->prepare('insert into b values (?, ?)');
$fp = fopen('php://memory', 'w+'); fwrite($fp, $bin); rewind($fp);
$st->bindValue(1, 1, PDO::PARAM_INT); $st->bindValue(2, $fp, PDO::PARAM_LOB); $st->execute();
$st->bindValue(1, 2, PDO::PARAM_INT); $st->bindValue(2, $bin, PDO::PARAM_LOB); $st->execute();
$st->bindValue(1, 3, PDO::PARAM_INT); $st->bindValue(2, null, PDO::PARAM_LOB); $st->execute();
$st->bindValue(1, 4, PDO::PARAM_INT); $st->bindValue(2, '', PDO::PARAM_LOB); $st->execute();
$rows = $db->query('select id, d, length(d) as n, md5(d) as m from b order by id')->fetchAll(PDO::FETCH_NUM);
foreach ($rows as $r) { echo $r[0], ' ', $r[1] === null ? 'null' : (is_resource($r[1]) ? (stream_get_contents($r[1]) === ($r[0] === 4 ? '' : $bin) ? 'same' : 'DIFF') : 'str'), ' ', $r[2], ' ', $r[3], "\n"; }
$st = $db->prepare('select ?::bytea');
$st->bindValue(1, "a\0b"); $st->execute(); echo bin2hex(stream_get_contents($st->fetchColumn())), "\n";
t(function () use ($db) { $st = $db->prepare('select ?::text'); $st->bindValue(1, "a\xffb"); return $st->execute(); });

// getColumnMeta
$db->exec('create temp table m(a serial, b text, n numeric(10,2), f float8, y bytea, ok bool, d date, ts timestamp, v varchar(3), i2 int2)');
$st = $db->prepare('select * from m');
t(fn () => $st->getColumnMeta(0));
$st->execute();
for ($i = 0; $i < 10; $i++) { $m = $st->getColumnMeta($i); echo $m['pgsql:table_oid'] > 0 ? 'tbl ' : 'notbl ', json_encode(array_diff_key($m, ['pgsql:table_oid' => 1])), "\n"; }
t(fn () => $st->getColumnMeta(10));
$st = $db->prepare("select ?::text as a, 1 as b, now()::date, 'x'::varchar(3), count(*) from m");
$st->execute(['q']);
for ($i = 0; $i < 5; $i++) { $m = $st->getColumnMeta($i); unset($m['pgsql:table_oid']); echo json_encode($m), "\n"; }
var_dump(array_keys($st->getColumnMeta(0)));

// stringify
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
var_dump($db->query('select 1, true, false, 1.5::float8, null')->fetch(PDO::FETCH_NUM));
