<?php
// The exact SQL text mysqlnd sends for autocommit / begin_transaction / commit / rollback / savepoint / release_savepoint,
// read back from the server's general log (a root connection turns it on for the length of the case and restores it).
// The case refuses to run when the log is already on; a log stuck on by a killed run is reset with SET GLOBAL general_log=0.
error_reporting(0);
mysqli_report(MYSQLI_REPORT_OFF);
$root = new mysqli(getenv('MYSQLI_HOST'), 'root', getenv('MYSQLI_ROOT_PASS') ?: 'pw', getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
if ($root->connect_errno) { echo "ASSERTION FAILED: no root: ", $root->connect_error, "\n"; exit(1); }
$old = $root->query('SELECT @@global.general_log, @@global.log_output')->fetch_row();
if ((int) $old[0] === 1) { echo "ASSERTION FAILED: general_log is already on (stuck or in use); reset it with SET GLOBAL general_log=0\n"; exit(1); }
register_shutdown_function(function () use ($old) {
    $r = new mysqli(getenv('MYSQLI_HOST'), 'root', getenv('MYSQLI_ROOT_PASS') ?: 'pw', getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
    $r->query("SET GLOBAL log_output='" . $old[1] . "'");
    $r->query('SET GLOBAL general_log=' . (int) $old[0]);
});
$root->query("SET GLOBAL log_output='TABLE'");
$root->query('SET GLOBAL general_log=1');
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function sent(mysqli $root, mysqli $m): string
{
    $id = $m->thread_id;
    $q = $root->query("SELECT argument FROM mysql.general_log WHERE thread_id=$id AND command_type='Query'");
    $o = [];
    while ($r = $q->fetch_row()) { $o[] = $r[0]; }
    return json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
function run(mysqli $root, string $label, callable $f): void
{
    $root->query('TRUNCATE mysql.general_log');
    $m = conn();
    try {
        $r = $f($m);
        echo str_pad($label, 34), json_encode($r), ' ', sent($root, $m), "\n";
    } catch (Throwable $e) {
        echo str_pad($label, 34), get_class($e), ': ', $e->getMessage(), ' ', sent($root, $m), "\n";
    }
    $m->close();
}
try {
    run($root, 'autocommit(true)', fn($m) => $m->autocommit(true));
    run($root, 'autocommit(false)', fn($m) => $m->autocommit(false));
    foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 16, 4294967295, PHP_INT_MAX, -1] as $f) {
        run($root, "begin_transaction($f)", fn($m) => $m->begin_transaction($f));
    }
    foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 255, -1] as $f) {
        run($root, "commit($f)", fn($m) => $m->commit($f));
        run($root, "rollback($f)", fn($m) => $m->rollback($f));
    }
    $names = ['abc', '', 'a b', 'a/*b*/c', 'a`b', '***', 'é', 'a-b_c=d\\e', 'a.b', "a\0b", "\0", '0', 'a,b', str_repeat('x', 300), "tab\there", "nl\nx", 'Z_9-='];
    foreach ($names as $n) {
        $j = json_encode(strlen($n) > 20 ? substr($n, 0, 8) . '...' : $n);
        run($root, "begin_transaction(0,$j)", fn($m) => $m->begin_transaction(0, $n === '' ? null : $n));
        run($root, "begin_transaction(4,$j)", fn($m) => $m->begin_transaction(MYSQLI_TRANS_START_READ_ONLY, $n === '' ? null : $n));
        run($root, "commit(0,$j)", fn($m) => $m->commit(0, $n));
        run($root, "rollback(5,$j)", fn($m) => $m->rollback(MYSQLI_TRANS_COR_AND_CHAIN | MYSQLI_TRANS_COR_RELEASE, $n));
        run($root, "savepoint($j)", fn($m) => $m->savepoint($n));
        run($root, "release_savepoint($j)", fn($m) => $m->release_savepoint($n));
    }
    run($root, 'mysqli_begin_transaction', fn($m) => mysqli_begin_transaction($m, 6, 'p'));
    run($root, 'mysqli_commit', fn($m) => mysqli_commit($m, 1, 'p'));
    run($root, 'mysqli_rollback', fn($m) => mysqli_rollback($m, 2, 'p'));
    run($root, 'mysqli_savepoint', fn($m) => mysqli_savepoint($m, 'p'));
    run($root, 'mysqli_release_savepoint', fn($m) => mysqli_release_savepoint($m, 'p'));
    run($root, 'mysqli_autocommit', fn($m) => mysqli_autocommit($m, false));
} finally {
    $root->query("SET GLOBAL log_output='" . $old[1] . "'");
    $root->query('SET GLOBAL general_log=' . (int) $old[0]);
}
echo "done\n";
