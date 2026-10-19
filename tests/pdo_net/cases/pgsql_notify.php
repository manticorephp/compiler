<?php
// LISTEN/NOTIFY: getNotify()/getPid() against php's pdo_pgsql (php: Pdo\Pgsql via PDO::connect).
error_reporting(E_ALL & ~E_DEPRECATED);
function pdo(): PDO { return PDO::connect((string) getenv("PDO_PG_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
function show(string $label, mixed $r, array $pids): void
{
    if (is_array($r) && isset($r['pid'])) { $r['pid'] = $pids[$r['pid']] ?? 'other'; }
    if (is_array($r) && isset($r[1])) { $r[1] = $pids[$r[1]] ?? 'other'; }
    echo $label, ' ', json_encode($r), "\n";
}
$a = pdo();
$b = pdo();
$pids = [$a->getPid() => 'A', $b->getPid() => 'B'];
echo 'pids ', $a->getPid() > 0 ? 'ok' : 'bad', ' ', $a->getPid() !== $b->getPid() ? 'distinct' : 'same', "\n";

$a->exec('LISTEN mc_t9_one');
$a->exec('LISTEN mc_t9_two');
$b->exec("NOTIFY mc_t9_one, 'x'");
show('assoc', $a->getNotify(PDO::FETCH_ASSOC, 2000), $pids);
$b->exec('NOTIFY mc_t9_two');
show('num-nopayload', $a->getNotify(PDO::FETCH_NUM, 2000), $pids);
$b->exec("NOTIFY mc_t9_two, 'both'");
show('both-default', $a->getNotify(PDO::FETCH_DEFAULT, 2000), $pids);
$b->exec("NOTIFY mc_t9_one, 'both2'");
show('both-explicit', $a->getNotify(PDO::FETCH_BOTH, 2000), $pids);

// nothing pending: false, after waiting about the timeout (0 = only look)
$t = microtime(true);
var_dump($a->getNotify(PDO::FETCH_ASSOC, 200));
$w = microtime(true) - $t;
echo $w >= 0.15 && $w < 1.5 ? "timeout waited\n" : "timeout BAD $w\n";
$t = microtime(true);
var_dump($a->getNotify());
echo microtime(true) - $t < 0.1 ? "poll immediate\n" : "poll SLOW\n";

// already in the socket: timeout 0 still finds it
$b->exec("NOTIFY mc_t9_one, 'inbuf'");
usleep(200000);
show('timeout0', $a->getNotify(PDO::FETCH_ASSOC), $pids);

// a notification that arrives before a query's own response is queued, not lost, and the result is intact
$b->exec("NOTIFY mc_t9_one, 'q1'");
$b->exec("NOTIFY mc_t9_two, 'q2'");
usleep(200000);
echo 'query result ', $a->query('select 41 + 1')->fetchColumn(), "\n";
$st = $a->prepare('select ?::text');
$st->execute(['prepared']);
echo 'prepared result ', $st->fetchColumn(), "\n";
show('queued1', $a->getNotify(PDO::FETCH_ASSOC), $pids);
show('queued2', $a->getNotify(PDO::FETCH_ASSOC), $pids);
var_dump($a->getNotify(PDO::FETCH_ASSOC));

// own notification (inside a transaction it is delivered at commit)
$a->beginTransaction();
$a->exec("NOTIFY mc_t9_one, 'self'");
var_dump($a->getNotify(PDO::FETCH_ASSOC, 50));
$a->commit();
show('self', $a->getNotify(PDO::FETCH_ASSOC, 2000), $pids);

// a big and a multibyte payload
$big = str_repeat('é', 3900);
$b->exec("NOTIFY mc_t9_one, '$big'");
$r = $a->getNotify(PDO::FETCH_ASSOC, 2000);
echo 'big ', $r['payload'] === $big ? 'same' : 'DIFFERENT', ' ', strlen($r['payload']), "\n";

// argument checks
foreach ([[PDO::FETCH_OBJ, 0], [PDO::FETCH_ASSOC, -1]] as [$m, $ms]) {
    try { $a->getNotify($m, $ms); echo "no error\n"; } catch (ValueError $e) { echo 'ValueError', "\n"; }
}

// the aliases, and a connection that is not pgsql
$c = new PDO((string) getenv("PDO_PG_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$c->exec('LISTEN mc_t9_one');
$b->exec("NOTIFY mc_t9_one, 'alias'");
show('alias', $c->pgsqlGetNotify(PDO::FETCH_ASSOC, 2000), [$c->pgsqlGetPid() => 'C', $b->getPid() => 'B']);
$l = new PDO('sqlite::memory:');
foreach (['pgsqlGetPid', 'pgsqlGetNotify'] as $m) {
    try { $l->$m(); echo "no error\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
// a closed peer: getNotify reports it instead of waiting for ever
$d = pdo();
$d->exec('LISTEN mc_t9_one');
$dp = $d->getPid();
$b->exec("SELECT pg_terminate_backend($dp)");
usleep(200000);
try { $d->getNotify(PDO::FETCH_ASSOC, 1000); echo "no error\n"; } catch (PDOException $e) { echo 'PDOException ', $e->errorInfo[0], "\n"; }
