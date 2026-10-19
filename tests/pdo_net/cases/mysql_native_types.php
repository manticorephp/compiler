<?php
ini_set("memory_limit", "-1");
$db = new PDO(getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
// the whole matrix goes through COM_STMT_PREPARE / EXECUTE: ints come back as int, floats as float, DECIMAL as string
function q(PDO $db, string $sql, array $p = []): PDOStatement { $s = $db->prepare($sql); $s->execute($p); return $s; }
function metas(PDOStatement $s): void { for ($i = 0; $i < $s->columnCount(); $i++) { echo json_encode($s->getColumnMeta($i)), "\n"; } }
function dump(array $rows): void { foreach ($rows as $r) { echo json_encode($r), "\n"; var_dump($r); } }
$db->exec('create temporary table t (id int auto_increment primary key, a tinyint, b smallint, c mediumint, d bigint, e bigint unsigned, f float, g double, h decimal(10,2), i varchar(20), j text, k blob, l date, m datetime, n timestamp null, o time, p year, q json, r enum(\'x\',\'y\'), s set(\'a\',\'b\'), t bit(8), u binary(3), v char(3), w tinyint(1), x int unsigned, y varchar(5) unique, z int, key kz (z))');
$db->exec("insert into t (a,b,c,d,e,f,g,h,i,j,k,l,m,n,o,p,q,r,s,t,u,v,w,x,y,z) values
 (1,2,3,4,18446744073709551615,1.5,2.25,3.10,'s','txt','bl','2020-01-02','2020-01-02 03:04:05','2020-01-02 03:04:05','12:13:14',2020,'{\"a\":1}','x','a,b',b'101','ab','cd',1,4294967295,'u1',7),
 (null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null),
 (-1,-2,-3,-9223372036854775808,9223372036854775807,-0.1,1e300,-99999999.99,'','','', '1000-01-01','9999-12-31 23:59:59',null,'-838:59:59',1901,'[1,2]','y','',255,'','',0,0,'u2',0)");
$s = q($db, 'select * from t order by id');
metas($s);
dump($s->fetchAll(PDO::FETCH_NUM));
// filtered with a bound value, same columns
$s = q($db, 'select * from t where id > ? order by id', [1]);
echo count($s->fetchAll(PDO::FETCH_ASSOC)), "\n";
$s = q($db, 'select a, d, e, x from t where id = ?', [3]);
$r = $s->fetch(PDO::FETCH_ASSOC); var_dump($r);
$s = q($db, 'select e from t where id = ?', [1]);
var_dump($s->fetchColumn());

$db->exec('create temporary table f (a float, c float(7,3), d double(5,2), g bit(64), h bit(1), t time(3), dt datetime(6), i bigint, ts timestamp(3) null, tm time(6), y2 year, dd decimal(30,10), db double, fl float)');
$db->exec("insert into f values (0.1, 1.5, 3.14, b'1111111111111111111111111111111111111111111111111111111111111111', b'1', '-838:59:58.123', '2020-01-01 01:02:03.456789', -9223372036854775808, '2020-06-07 08:09:10.123', '-00:00:01.5', 2155, 12345678901234567890.1234567891, 1.0E-10, 16777216),
 (3.14159265, 123.456, 99.9, 0, 0, '00:00:00', '1000-01-01 00:00:00', 9223372036854775807, null, '00:00:00.000001', 0, -0.5, 123456789.123456789, 3.40282e38),
 (1e10, 0, 0, 1, 1, '838:59:59', '2020-12-31 23:59:59.5', 0, '2020-01-01 00:00:00', '23:59:59.999999', 1901, 0, 1e15, -1.5e-5),
 (0, 0, 0, 0, 0, '01:02:03.5', '2020-01-01 00:00:00.000001', 1, '2020-01-01 00:00:00.5', '-838:59:59.000000', 2000, 99999999999999999999.9999999999, 1e22, 0.30000001192092896)");
$s = q($db, 'select * from f');
metas($s);
dump($s->fetchAll(PDO::FETCH_NUM));
$s = q($db, 'select ts, dt, tm from f where i > ?', [-1]);
dump($s->fetchAll(PDO::FETCH_NUM));

// NULL of each type and zero dates
$db->exec("set sql_mode = 'ALLOW_INVALID_DATES'");
$db->exec('create temporary table z (d date, dt datetime, ts datetime(3), t time)');
$db->exec("insert into z values ('0000-00-00', '0000-00-00 00:00:00', '0000-00-00 00:00:00.000', '00:00:00'), ('2020-00-00', '2020-02-30 00:00:00', '0000-01-01 00:00:00.5', '-00:00:00.5'), (null, null, null, null)");
$s = q($db, 'select * from z');
metas($s);
dump($s->fetchAll(PDO::FETCH_NUM));
$db->exec("set sql_mode = default");

// computed columns
$s = q($db, "select 1 as a, 2.5 as b, 'x' as c, null as d, 1e0 as e, 10 / 3 as f, cast(9223372036854775808 as unsigned) as g, -9223372036854775808 as h, cast(1.5 as char) as i, now() > 0 as j, 0x41 as k, 'é' as m, '' as n, json_object('a', 1) as o, point(1, 2) is null as p, cast(1 as signed) as q, cast(-1 as unsigned) as r, 1.5e0 + ? as s, abs(?) as u", [2, -3]);
metas($s);
var_dump($s->fetch(PDO::FETCH_NUM));
$s = q($db, 'select x.id as aid, x.id, x.a + 1 as calc from t as x where x.id = ?', [1]);
metas($s);
var_dump($s->getColumnMeta(3));
$s = q($db, 'select 1 as a, 2 as a');
var_dump($s->fetch(PDO::FETCH_ASSOC));
$s = q($db, 'select 1, 2.5, null, 3, ?', ['v']);
var_dump($s->fetch(PDO::FETCH_NUM));
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
$s = q($db, 'select 1, 2.5, null, 3, cast(1.5e0 as double), cast(3 as unsigned)');
var_dump($s->fetch(PDO::FETCH_NUM));
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
// a large value
$s = q($db, 'select repeat(\'x\', 3000000) a, repeat(\'é\', 20000) b, repeat(\'z\', 70000) c');
$r = $s->fetch(PDO::FETCH_NUM);
echo strlen($r[0]), ' ', strlen($r[1]), ' ', md5($r[1]), ' ', strlen($r[2]), "\n";
// every integer width, signed and unsigned
$db->exec('create temporary table w (a tinyint, b tinyint unsigned, c smallint, d smallint unsigned, e mediumint, f mediumint unsigned, g int, h int unsigned, i bigint, j bigint unsigned)');
$db->exec('insert into w values (-128,0,-32768,0,-8388608,0,-2147483648,0,-9223372036854775808,0), (127,255,32767,65535,8388607,16777215,2147483647,4294967295,9223372036854775807,18446744073709551615), (null,null,null,null,null,null,null,null,null,null)');
$s = q($db, 'select * from w');
metas($s);
dump($s->fetchAll(PDO::FETCH_NUM));
// binary strings and 0 bytes
$s = q($db, "select ?, ?, binary 'a\0b', unhex('00ff')", ["a\0b", "\xf0\x9f\x98\x80"]);
foreach ($s->fetch(PDO::FETCH_NUM) as $v) { echo bin2hex($v), "\n"; }
// doubles through the wire in both directions (bound as DOUBLE, read back as DOUBLE) and floats through FLOAT columns
$st = $db->prepare('select ?, ? + 0e0, -?');
foreach ([0.1, 1e22, -1.5e-300, 123456789.123456789, 2.2250738585072014e-308, 1.7976931348623157e308, 1e-310, 4.9e-320, 5e-324, -0.0, 0.0, 1.0, 3.0e10, 9007199254740993.0, 1.1102230246251565e-16, 2.5, -2.5e-5] as $d) {
    $st->bindValue(1, $d, PDO::PARAM_INT);
    $st->bindValue(2, $d, PDO::PARAM_INT);
    $st->bindValue(3, $d, PDO::PARAM_INT);
    try { $st->execute(); $r = $st->fetch(PDO::FETCH_NUM); echo json_encode($r), ' '; var_dump($r[0] === $d); }
    catch (PDOException $e) { echo 'EXC ', $e->getMessage(), "\n"; }
}
$db->exec('create temporary table fl (a float, b float(8,2), c float unsigned, d double)');
$ins = $db->prepare('insert into fl values (?, ?, ?, ?)');
foreach ([[1.1, 2.675, 3.3, 1.1], [16777217, 99999.99, 0.000001234, 1e-5], [-3.4e38, -1.5, 1e-38, 1e308], [0.5, 1234.5678, 7.0, 7.7]] as $p) { $ins->execute($p); }
$s = $db->prepare('select * from fl');
$s->execute();
foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) { var_dump($r); }
echo "== a value above max_allowed_packet\n";
$big = $db->prepare('select length(?)');
$v = str_repeat('x', 70 * 1024 * 1024);
// the server answers ERR 1153 and closes mid-packet: whether the client's write fails first (2006) or it reads the ERR (1153)
// is a kernel race php loses on macOS and wins on Linux, so either answer is graded as the one outcome
try { $big->execute([$v]); echo $big->fetchColumn(), "\n"; } catch (PDOException $e) {
    $m = $e->getMessage();
    echo get_class($e), ' ', str_contains($m, 'max_allowed_packet') || str_contains($m, 'gone away') ? 'refused, connection closed' : preg_replace('/\d+/', 'N', $m), "\n";
}
$v = null;
