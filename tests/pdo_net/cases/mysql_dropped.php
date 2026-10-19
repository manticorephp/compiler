<?php
set_error_handler(function ($n, $s) { if (!(error_reporting() & $n)) { return false; } echo "WARN: $s\n"; return true; });
function pdo(int $mode): PDO { return new PDO((string) getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => $mode]); }
function t(string $label, callable $f): void {
    try { $r = var_export($f(), true); echo $label . ': ' . $r . "\n"; } catch (PDOException $e) { echo $label . ': PDOException ' . $e->getMessage() . ' ' . json_encode($e->errorInfo) . "\n"; }
}
/** Kills the connection from a second one and waits (bounded) until the server has really dropped it. */
function kill(PDO $k, int $id): void {
    $k->exec('kill ' . $id);
    for ($i = 0; $i < 150; $i++) {
        $st = $k->query('select count(*) from information_schema.processlist where id = ' . $id);
        $n = (int) $st->fetchColumn();
        if ($n === 0) { return; }
        usleep(20000);
    }
}
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING, PDO::ERRMODE_EXCEPTION] as $mode) {
    echo "== errmode $mode\n";
    $db = pdo($mode);
    $k = pdo(PDO::ERRMODE_EXCEPTION);
    $ps = $db->query('select connection_id()');
    $id = (int) $ps->fetchColumn();
    $ps = null;
    $st = $db->prepare('select 1 as a');
    $st->execute();
    $st->fetchAll();
    kill($k, $id);
    t('exec', fn () => $db->exec('set @a = 1'));
    t('errorInfo', fn () => $db->errorInfo());
    t('errorCode', fn () => $db->errorCode());
    t('exec again', fn () => $db->exec('set @a = 1'));
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
    t('info', fn () => $db->getAttribute(PDO::ATTR_SERVER_INFO));
    t('lastInsertId', fn () => $db->lastInsertId());
    t('autocommit set', fn () => $db->setAttribute(PDO::ATTR_AUTOCOMMIT, false));
    $st = null;
    $db = null;
}
echo "== killed inside a transaction\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
$k = pdo(PDO::ERRMODE_EXCEPTION);
$ps = $db->query('select connection_id()');
$id = (int) $ps->fetchColumn();
$ps = null;
$db->beginTransaction();
kill($k, $id);
t('exec', fn () => $db->exec('set @a = 1'));
t('inTransaction', fn () => $db->inTransaction());
t('rollBack', fn () => $db->rollBack());
t('commit', fn () => $db->commit());
echo "== killed while a statement holds a buffered result\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
$ps = $db->query('select connection_id()');
$id = (int) $ps->fetchColumn();
$ps = null;
$s = $db->query('select 1 union select 2 union select 3');
$first = $s->fetch(PDO::FETCH_NUM);
kill($k, $id);
t('fetchAll', fn () => $s->fetchAll(PDO::FETCH_COLUMN));
t('next query', fn () => $db->exec('set @a = 1'));
echo "== the connection kills itself while a query runs\n";
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_EXCEPTION] as $mode) {
    $db = pdo($mode);
    $ps = $db->query('select connection_id()');
    $id = (int) $ps->fetchColumn();
    $ps = null;
    t('kill self', fn () => $db->exec('kill ' . $id));
    t('errorInfo', fn () => $db->errorInfo());
    t('next', fn () => $db->exec('set @a = 1'));
    t('next errorInfo', fn () => $db->errorInfo());
    t('inTransaction', fn () => $db->inTransaction());
}
echo "== wait_timeout expires on an idle connection\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
$k = pdo(PDO::ERRMODE_EXCEPTION);
$ps = $db->query('select connection_id()');
$id = (int) $ps->fetchColumn();
$ps = null;
$db->exec('set session wait_timeout = 1');
for ($i = 0; $i < 150; $i++) {
    $st = $k->query('select count(*) from information_schema.processlist where id = ' . $id);
    if ((int) $st->fetchColumn() === 0) { break; }
    usleep(50000);
}
t('exec', fn () => $db->exec('set @a = 1'));
t('exec again', fn () => $db->exec('set @a = 1'));
echo "== statement outlives the connection object\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
$s = $db->query('select 1 union select 2');
$db = null;
t('fetchAll', fn () => $s->fetchAll(PDO::FETCH_COLUMN));
echo "== fd count stable over repeated kill cycles\n";
$before = count(glob('/dev/fd/*'));
for ($j = 0; $j < 5; $j++) {
    $d = pdo(PDO::ERRMODE_SILENT);
    $ps = $d->query('select connection_id()');
    $i2 = (int) $ps->fetchColumn();
    $ps = null;
    kill($k, $i2);
    $d->exec('set @a = 1');
    $d->exec('set @a = 1');
    $d = null;
}
$after = count(glob('/dev/fd/*'));
echo $after <= $before ? "no fd leak\n" : "fd leak $before -> $after\n";
