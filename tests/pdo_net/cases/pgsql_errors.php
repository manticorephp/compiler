<?php
set_error_handler(function ($n, $s) { if (!(error_reporting() & $n)) { return false; } echo "WARN: $s\n"; return true; });
function dsn(): string { return (string) getenv('PDO_PG_DSN'); }
function pdo(int $mode): PDO { return new PDO(dsn(), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => $mode]); }

$qs = [
    "insert into t values (1,'y',null)", 'select * from nope', 'selec 1', "insert into t values (null,'y',null)",
    "insert into t values (2,'bad',null)", "insert into t values (3,'y',99)", 'select nocol from t', "select 'x'::int",
    'select 1/0', 'drop table nope', 'select 99999999999::int4', 'select nofun()', 'create table t(a int)',
    "set transaction_isolation to 'bogus'", 'create extension nope', "select 'a' ~ '('", 'select * from t where a = any(1)',
    'create database d', 'fetch from nocur', 'execute nostmt', "do 'begin raise exception ''boom''; end' language plpgsql",
    "do 'begin raise exception using errcode = ''40001''; end' language plpgsql",
    "do 'begin raise exception using errcode = ''ZZZZZ''; end' language plpgsql",
];
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING, PDO::ERRMODE_EXCEPTION] as $mode) {
    echo "== errmode $mode\n";
    $db = pdo($mode);
    $db->exec("create temp table t(a int not null primary key, b text check (b <> 'bad'), c int references t(a))");
    $db->exec("insert into t values (1,'x',null)");
    foreach ($qs as $q) {
        try {
            $r = $db->exec($q);
            echo substr(str_replace("\n", ' ', $q), 0, 40), ' => ', var_export($r, true), ' ', json_encode($db->errorInfo()), ' ', json_encode($db->errorCode()), "\n";
        } catch (PDOException $e) {
            echo substr(str_replace("\n", ' ', $q), 0, 40), ' => ', json_encode($e->getMessage()), ' ', json_encode($e->errorInfo), "\n";
        }
    }
    // statement-level and query()-level failures
    $s = $db->prepare('select ?::int');
    try { var_dump($s->execute(['x'])); echo json_encode($s->errorInfo()), $s->errorCode(), "\n"; } catch (PDOException $e) { echo json_encode($e->getMessage()), "\n"; }
    try { var_dump($s->execute([1, 2])); } catch (PDOException $e) { echo json_encode($e->getMessage()), "\n"; }
    try { var_dump($db->query('select 1/0')); } catch (PDOException $e) { echo json_encode($e->getMessage()), "\n"; }
    $u = $db->prepare("insert into t values (:a, 'q', null)");
    $u->execute([':a' => 70]);
    try { var_dump($u->execute([':a' => 70])); echo json_encode($u->errorInfo()), "\n"; } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
    var_dump($db->exec('select 1'), json_encode($db->errorInfo()));
}
echo "== unique violation, exception form\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
$db->exec('create temp table u(a int primary key)');
$db->exec('insert into u values (1)');
try { $db->exec('insert into u values (1)'); } catch (PDOException $e) { echo $e->getMessage(), "\n", $e->errorInfo[0], ' ', $e->errorInfo[1], "\n"; }
echo "== sqlstate descriptions\n";
foreach (['00000', '01000', '0A000', '22001', '22003', '22007', '22008', '22P02', '23000', '23001', '23514', '24000', '25P01', '25P02', '26000', '28000', '2BP01', '34000', '3D000', '3F000', '40001', '40P01', '42501', '42602', '42704', '42710', '42723', '42P04', '42P05', '42P07', '42S02', '53100', '53200', '54000', '55006', '55P03', '57014', '57P01', '58030', 'F0000', 'HY000', 'HYT00', 'IM001', 'P0001', 'XX000', 'YY000', '08P01', '99999'] as $c) {
    try { $db->exec("do 'begin raise exception using errcode = ''$c''; end' language plpgsql"); echo "$c ok\n"; }
    catch (PDOException $e) { echo $c, ' ', preg_replace('/: 7 ERROR.*$/s', '', $e->getMessage()), "\n"; }
}
// a query() that threw must not leave its name behind: the next warning names its own method
$db = pdo(PDO::ERRMODE_EXCEPTION);
try { $db->query('selec 1'); } catch (PDOException $e) { echo "threw\n"; }
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
$db->exec('selec 2');
$ps = $db->prepare('selec 3');
$ps->execute();
