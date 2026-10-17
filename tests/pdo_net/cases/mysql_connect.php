<?php
// A server that does not know a user/password pair asks caching_sha2_password clients for RSA full authentication before
// it answers 1045. The driver reaches openssl_public_encrypt only when the PROGRAM links an openssl_* function (see
// __McPdoMyWire::fullAuth), so this never-taken call is what makes the bad-credential cases below byte-identical to php.
if (getenv('PDO_NEVER_SET')) { openssl_public_encrypt('', $never, ''); }
$base = getenv('PDO_MY_DSN');
$u = getenv('PDO_USER') ?: 'u';
$p = getenv('PDO_PASS') ?: 'pw';
$db = new PDO($base, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo $db->getAttribute(PDO::ATTR_DRIVER_NAME), "\n";
$s = $db->query('select 1');
var_dump($s->fetchColumn());
$s = null;
var_dump($db->getAttribute(PDO::ATTR_SERVER_VERSION) === $db->query('select version()')->fetchColumn());
var_dump($db->getAttribute(PDO::ATTR_CONNECTION_STATUS));
$s = $db->query('select @@character_set_client c, @@character_set_connection cc, @@character_set_results r, @@collation_connection k');
var_dump($s->fetch(PDO::FETCH_ASSOC));
$s = $db->query('select database() d, current_user() c, @@autocommit a');
var_dump($s->fetch(PDO::FETCH_ASSOC));
$s = null;
var_dump(in_array('mysql', PDO::getAvailableDrivers(), true));

$latin = new PDO($base . ';charset=latin1', $u, $p);
$s = $latin->query('select @@character_set_client c, @@character_set_results r');
var_dump($s->fetch(PDO::FETCH_ASSOC));
$s = null;
// pdo_mysql has no user= / password= DSN keys (unlike pdo_pgsql): they are ignored, the constructor arguments rule
$both = new PDO($base . ';user=nobody;password=wrong', $u, $p);
$s = $both->query('select 2');
var_dump($s->fetchColumn());
$s = null;

$try = function (string $dsn, ?string $user, ?string $pass) {
    try { new PDO($dsn, $user, $pass); echo "ok\n"; }
    catch (PDOException $e) {
        // the client address in the access-denied text is the host's, identical for both runs
        echo json_encode($e->getMessage()), ' ', $e->getCode(), ' ', json_encode($e->errorInfo), "\n";
    }
};
$try($base, $u, 'bad');
$try($base, 'nouser', $p);
$try($base, $u, null);
$try(preg_replace('/dbname=[^;]*/', 'dbname=nope', $base), $u, $p);
$try(preg_replace('/;dbname=[^;]*/', '', $base), $u, $p);
$try(preg_replace('/port=\d+/', 'port=33099', $base), $u, $p);
$try($base . ';charset=nosuchset', $u, $p);

// connect-time options, by number (PDO::MYSQL_ATTR_* is deprecated since php 8.5): 1004 FOUND_ROWS, 1002 INIT_COMMAND, 1012 MULTI_STATEMENTS
$opt = function (array $o) use ($base, $u, $p) {
    $d = new PDO($base, $u, $p, $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $d->exec('create temporary table t (a int)');
    $d->exec('insert into t values (1), (2)');
    echo 'update ', $d->exec('update t set a = a'), "\n";
    try { echo 'multi ', $d->exec('insert into t values (3); insert into t values (4)'), "\n"; }
    catch (PDOException $e) { echo 'multi failed ', $e->errorInfo[0], ' ', $e->errorInfo[1], "\n"; }
    $s = $d->query('select count(*), @a from t');
    echo json_encode($s->fetch(PDO::FETCH_NUM)), "\n";
    $s = null;
};
$opt([]);
$opt([1004 => true]);
$opt([1012 => false]);
$opt([1002 => 'SET @a = 42']);
$opt([PDO::ATTR_TIMEOUT => 5]);
try { new PDO($base, $u, $p, [1002 => 'selec 1']); echo "init ok\n"; }
catch (PDOException $e) { echo json_encode($e->getMessage()), "\n"; }
