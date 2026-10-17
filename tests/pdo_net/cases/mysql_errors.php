<?php
set_error_handler(function ($n, $s) { if (!(error_reporting() & $n)) { return false; } echo "WARN: $s\n"; return true; });
function dsn(): string { return (string) getenv('PDO_MY_DSN'); }
function pdo(int $mode): PDO { return new PDO(dsn(), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => $mode]); }
function show(string $q, string $tail): string { return substr(str_replace("\n", ' ', $q), 0, 44) . ' => ' . $tail . "\n"; }

// real tables for the foreign-key cases (a temporary table cannot carry a foreign key); dropped again at the end
$cleanup = function (PDO $db): void { $db->exec('drop table if exists mc_err_c'); $db->exec('drop table if exists mc_err_p'); };
$boot = pdo(PDO::ERRMODE_EXCEPTION);
$cleanup($boot);

$qs = [
    "insert into t values (1,'y',null)", 'select * from nope', 'selec 1', "insert into t values (null,'y',null)",
    "insert into t values (2,'toolongvalue',null)", "insert into t values (3,'y',99)", 'select nocol from t', 'select 1/0',
    'insert into t values (300000000000,"y",null)', 'drop table nope', 'create temporary table t(a int)', 'delete from t where a = 99',
    "insert into t values (4,'y',null); insert into t values (4,'y',null)", 'use nodb', 'set @@nosuch=1', 'call nope()',
    'select nofun()', "insert into t(a) values ('abc')", 'lock tables nope read', "update t set c = 5 where a in (1, 3)",
    "insert into mc_err_c values (1, 5)", "delete from mc_err_p where id = 1", "insert into mc_err_c values (1, 1)",
    'alter table nope add x int', 'create table mc_err_dup (a int, a int)', 'drop database nodb2', 'kill 99999999',
    'set autocommit=2', 'select @@nosuchvar', "insert into t values (7,'x',null) on duplicate key update b=values(b)",
    "insert into t values (8,'x',null),(8,'y',null)", 'update t set a = a', 'replace into t values (1,"z",null)',
    "create table mc_err_p (id int)", 'select * from t where', 'show tables like', 'grant all on *.* to nobody',
    'create user u', 'drop user nope', 'handler nope open', 'xa end 0x00', 'load data infile "/nope" into table t',
    'savepoint a', 'rollback to savepoint nope', 'release savepoint nope', 'prepare s from "selec"', 'execute nostmt',
    'deallocate prepare nostmt', 'do 1/0', 'do sleep(0)', 'optimize table nope', 'analyze table nope', 'truncate nope',
    'rename table nope to nope2', 'alter table t drop column nocol', 'create index i on nope (a)', 'select 1 from dual where',
];
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING, PDO::ERRMODE_EXCEPTION] as $mode) {
    echo "== errmode $mode\n";
    $db = pdo($mode);
    $db->exec("set sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO'");
    $db->exec("create temporary table t(a int not null primary key, b varchar(5), c int, unique key (c))");
    $cleanup($db);
    $db->exec('create table mc_err_p (id int primary key) engine=innodb');
    $db->exec('create table mc_err_c (id int, pid int, foreign key (pid) references mc_err_p (id)) engine=innodb');
    $db->exec("insert into t values (1,'x',null)");
    $db->exec('insert into mc_err_p values (1)');
    foreach ($qs as $q) {
        try {
            if (preg_match('/^(select|show|execute|do |optimize|analyze)/i', $q) === 1) {
                $s = $db->query($q);
                $r = $s === false ? false : 'rows ' . count($s->fetchAll());
            } else {
                $r = $db->exec($q);
            }
            echo show($q, var_export($r, true) . ' ' . json_encode($db->errorInfo()) . ' ' . json_encode($db->errorCode()));
        } catch (PDOException $e) {
            echo show($q, json_encode($e->getMessage()) . ' ' . json_encode($e->errorInfo));
        }
    }
    // statement-level and query()-level failures
    $s = $db->prepare('insert into t values (?, ?, ?)');
    try { var_dump($s->execute([15, 'x', null])); var_dump($s->execute([15, 'x', null])); echo json_encode($s->errorInfo()), $s->errorCode(), "\n"; } catch (PDOException $e) { echo json_encode($e->getMessage()), "\n"; }
    try { var_dump($s->execute([16, 'x'])); } catch (PDOException $e) { echo json_encode($e->getMessage()), "\n"; }
    try { $r = $db->query('select 1/0'); echo 'query: ', $r === false ? 'false' : 'stmt', "\n"; } catch (PDOException $e) { echo json_encode($e->getMessage()), "\n"; }
    $r = null;
    try { $r = $db->query('select * from nope'); echo 'query nope: ', var_export($r, true), "\n"; } catch (PDOException $e) { echo json_encode($e->getMessage()), "\n"; }
    $u = $db->prepare("insert into t values (:a, 'q', null)");
    $u->execute([':a' => 70]);
    try { var_dump($u->execute([':a' => 70])); echo json_encode($u->errorInfo()), "\n"; } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
    $cleanup($db);
}
echo "== exception form\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
$db->exec('create temporary table u(a int primary key)');
$db->exec('insert into u values (1)');
try { $db->exec('insert into u values (1)'); } catch (PDOException $e) { echo $e->getMessage(), "\n", $e->errorInfo[0], ' ', $e->errorInfo[1], ' ', $e->errorInfo[2], "\n"; }
echo "== a failed multi-statement\n";
try { $db->exec('insert into u values (2); insert into u values (1); insert into u values (3)'); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
$s = $db->query('select count(*) from u');
echo 'rows after partial multi: ', $s->fetchColumn(), "\n";
echo "== a failed rowset\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
$s = $db->query('select 1; select * from nope; select 3');
echo json_encode($s->fetchAll(PDO::FETCH_NUM)), "\n";
try { $s->nextRowset(); echo "rowset 2 ok\n"; } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
$s = null;
echo "== lock wait timeout, and a deadlock as the server reports it\n";
$a = pdo(PDO::ERRMODE_EXCEPTION); $b = pdo(PDO::ERRMODE_EXCEPTION);
$a->exec('drop table if exists mc_err_dl'); $a->exec('create table mc_err_dl (id int primary key, v int) engine=innodb');
$a->exec('insert into mc_err_dl values (1,0),(2,0)');
$a->beginTransaction(); $b->beginTransaction();
$a->exec('update mc_err_dl set v = 1 where id = 1'); $b->exec('update mc_err_dl set v = 1 where id = 2');
$b->exec('set session innodb_lock_wait_timeout = 1');
try { $b->exec('update mc_err_dl set v = 2 where id = 1'); } catch (PDOException $e) { echo $e->getMessage(), ' ', json_encode($e->errorInfo), "\n"; }
// two connections cannot block each other inside one thread, so the deadlock error is raised with SIGNAL
try { $b->exec("signal sqlstate '40001' set mysql_errno = 1213, message_text = 'Deadlock found when trying to get lock; try restarting transaction'"); } catch (PDOException $e) { echo $e->getMessage(), ' ', json_encode($e->errorInfo), "\n"; }
$a->rollBack(); $b->rollBack();
$a->exec('drop table mc_err_dl');
echo "== sqlstate descriptions\n";
foreach (['00000', '01000', '0A000', '22001', '22003', '22007', '22008', '22012', '22P02', '23000', '23001', '24000', '25000', '26000', '28000', '2BP01', '34000', '3D000', '3F000', '40001', '42000', '42S01', '42S02', '42S12', '42S21', '42S22', '44000', '53100', '54000', '55006', '57014', '58030', 'HY000', 'HY001', 'HY009', 'HY093', 'HYT00', 'IM001', '70100', '08S01', '99999', 'XX000'] as $c) {
    echo $c, ' ', __sqlstate($c), "\n";
}
function __sqlstate(string $c): string {
    $db = pdo(PDO::ERRMODE_EXCEPTION);
    try { $db->exec("signal sqlstate '$c' set message_text = 'm'"); return 'ok'; }
    catch (PDOException $e) { return preg_replace('/: \d+ m$/', '', $e->getMessage()) . ' | ' . json_encode($e->errorInfo[1]); }
}
echo "== a query() that threw must not leave its name behind\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
try { $db->query('selec 1'); } catch (PDOException $e) { echo "threw\n"; }
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
$db->exec('selec 2');
$ps = $db->prepare('selec 3');
$ps->execute();
echo "== connect failures (the same shape in every error mode)\n";
$base = dsn();
$cf = function (string $label, string $d, string $user, ?string $pass, int $mode) {
    try { new PDO($d, $user, $pass, [PDO::ATTR_ERRMODE => $mode]); echo "$label: ok\n"; }
    catch (PDOException $e) { echo $label . ': ' . json_encode($e->getMessage()) . ' ' . $e->getCode() . ' ' . json_encode($e->errorInfo) . "\n"; }
};
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_EXCEPTION] as $mode) {
    $cf('refused', preg_replace('/port=\d+/', 'port=33099', $base), 'u', 'pw', $mode);
    $cf('unresolvable', 'mysql:host=nonexistent.invalid;dbname=d', 'u', 'pw', $mode);
    $cf('unknown database', preg_replace('/dbname=[^;]*/', 'dbname=nope', $base), 'u', 'pw', $mode);
    $cf('bad password', $base, 'u', 'bad', $mode);
    $cf('unknown user', $base, 'nouser', 'pw', $mode);
    $cf('unknown database (root)', preg_replace('/dbname=[^;]*/', 'dbname=nope', $base), 'root', 'pw', $mode);
}
