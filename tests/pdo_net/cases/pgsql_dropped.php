<?php
set_error_handler(function ($n, $s) { if (!(error_reporting() & $n)) { return false; } echo "WARN: $s\n"; return true; });
function pdo(int $mode): PDO { return new PDO((string) getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => $mode]); }
function t(string $label, callable $f): void {
    try { $r = var_export($f(), true); echo $label, ': ', $r, "\n"; } catch (PDOException $e) { echo $label, ': PDOException ', $e->getMessage(), ' ', json_encode($e->errorInfo), "\n"; }
}
/** Waits (bounded) until the terminated backend is really gone, so the next call sees a dead socket. */
function gone(PDO $k, int $pid): void {
    for ($i = 0; $i < 150; $i++) {
        $st = $k->query("select count(*) from pg_stat_activity where pid = $pid");
        $n = (int) $st->fetchColumn();
        if ($n === 0) { return; }
        usleep(20000);
    }
}
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING, PDO::ERRMODE_EXCEPTION] as $mode) {
    echo "== errmode $mode\n";
    $db = pdo($mode);
    $k = pdo(PDO::ERRMODE_EXCEPTION);
    $ps = $db->query('select pg_backend_pid()');
    $pid = (int) $ps->fetchColumn();
    $ps = null;
    $st = $db->prepare('select 1 as a');
    $st->execute();
    $st->fetchAll();
    $k->exec("select pg_terminate_backend($pid)");
    gone($k, $pid);
    t('exec', fn () => $db->exec('select 1'));
    t('errorInfo', fn () => $db->errorInfo());
    t('errorCode', fn () => $db->errorCode());
    t('exec again', fn () => $db->exec('select 1'));
    t('query', fn () => $db->query('select 1'));
    t('prepare', fn () => $db->prepare('select 1') === false ? 'false' : 'stmt');
    t('execute old', fn () => $st->execute());
    t('stmt errorInfo', fn () => $st->errorInfo());
    t('begin', fn () => $db->beginTransaction());
    t('inTransaction', fn () => $db->inTransaction());
    t('commit', fn () => $db->commit());
    t('rollBack', fn () => $db->rollBack());
    t('quote', fn () => $db->quote("a'b"));
    t('status', fn () => $db->getAttribute(PDO::ATTR_CONNECTION_STATUS));
    t('version', fn () => $db->getAttribute(PDO::ATTR_SERVER_VERSION) !== '');
    t('info', fn () => preg_replace('/PID: \d+/', 'PID: N', $db->getAttribute(PDO::ATTR_SERVER_INFO)));
    t('lastInsertId', fn () => $db->lastInsertId());
    $st = null;
    $db = null;
}
echo "== killed inside a transaction\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
$k = pdo(PDO::ERRMODE_EXCEPTION);
$ps = $db->query('select pg_backend_pid()');
$pid = (int) $ps->fetchColumn();
$ps = null;
$db->beginTransaction();
$k->exec("select pg_terminate_backend($pid)");
gone($k, $pid);
t('exec', fn () => $db->exec('select 1'));
t('inTransaction', fn () => $db->inTransaction());
t('rollBack', fn () => $db->rollBack());
echo "== killed while a statement is mid-fetch\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
$ps = $db->query('select pg_backend_pid()');
$pid = (int) $ps->fetchColumn();
$ps = null;
$s = $db->query('select g from generate_series(1,3) g');
$k->exec("select pg_terminate_backend($pid)");
gone($k, $pid);
t('fetchAll', fn () => $s->fetchAll(PDO::FETCH_COLUMN));
t('next query', fn () => $db->exec('select 1'));
