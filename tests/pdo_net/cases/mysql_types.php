<?php
$db = new PDO(getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('create temporary table t (id int auto_increment primary key, a tinyint, b smallint, c mediumint, d bigint, e bigint unsigned, f float, g double, h decimal(10,2), i varchar(20), j text, k blob, l date, m datetime, n timestamp null, o time, p year, q json, r enum(\'x\',\'y\'), s set(\'a\',\'b\'), t bit(8), u binary(3), v char(3), w tinyint(1), x int unsigned, y varchar(5) unique, z int, key kz (z))');
$db->exec("insert into t (a,b,c,d,e,f,g,h,i,j,k,l,m,n,o,p,q,r,s,t,u,v,w,x,y,z) values
 (1,2,3,4,18446744073709551615,1.5,2.25,3.10,'s','txt','bl','2020-01-02','2020-01-02 03:04:05','2020-01-02 03:04:05','12:13:14',2020,'{\"a\":1}','x','a,b',b'101','ab','cd',1,4294967295,'u1',7),
 (null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null),
 (-1,-2,-3,-9223372036854775808,9223372036854775807,-0.1,1e300,-99999999.99,'','','', '1000-01-01','9999-12-31 23:59:59',null,'-838:59:59',1901,'[1,2]','y','',255,'','',0,0,'u2',0)");
$s = $db->query('select * from t order by id');
for ($i = 0; $i < $s->columnCount(); $i++) { echo json_encode($s->getColumnMeta($i)), "\n"; }
foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) { echo json_encode($r), "\n"; var_dump($r[5], $r[6], $r[15], $r[19], $r[3]); }
$s = null;
$db->exec('create temporary table f (a float, c float(7,3), d double(5,2), g bit(64), h bit(1), t time(3), dt datetime(6), i bigint)');
$db->exec("insert into f values (0.1, 1.5, 3.14, b'1111111111111111111111111111111111111111111111111111111111111111', b'1', '-838:59:58.123', '2020-01-01 01:02:03.456789', -9223372036854775808), (3.14159265, 123.456, 99.9, 0, 0, '00:00:00', '1000-01-01 00:00:00', 9223372036854775807)");
$s = $db->query('select * from f');
for ($i = 0; $i < $s->columnCount(); $i++) { echo json_encode($s->getColumnMeta($i)), "\n"; }
foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) { var_dump($r); }
$s = $db->query("select 1 as a, 2.5 as b, 'x' as c, null as d, 1e0 as e, 10 / 3 as f, cast(9223372036854775808 as unsigned) as g, -9223372036854775808 as h, cast(1.5 as char) as i, now() > 0 as j, 0x41 as k, @x := 5 as l, 'é' as m, '' as n, json_object('a', 1) as o, point(1, 2) is null as p");
for ($i = 0; $i < $s->columnCount(); $i++) { echo json_encode($s->getColumnMeta($i)), "\n"; }
var_dump($s->fetch(PDO::FETCH_NUM));
$s = $db->query('select x.id as aid, x.id, x.a + 1 as calc from t as x where x.id = 1');
for ($i = 0; $i < $s->columnCount(); $i++) { echo json_encode($s->getColumnMeta($i)), "\n"; }
var_dump($s->getColumnMeta(3));
$s = $db->query('select 1 as a, 2 as a');
var_dump($s->fetch(PDO::FETCH_ASSOC));
// stringified fetches
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
$s = $db->query('select 1, 2.5, null, 3');
var_dump($s->fetch(PDO::FETCH_NUM));
// a large value
$s = $db->query('select repeat(\'x\', 3000000) a, repeat(\'é\', 20000) b');
$r = $s->fetch(PDO::FETCH_NUM);
echo strlen($r[0]), ' ', strlen($r[1]), ' ', md5($r[1]), "\n";
