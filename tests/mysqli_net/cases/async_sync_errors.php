<?php
// Async state rules: every command while an async reply is pending is "Commands out of sync" (texts, counters, report
// modes); reap_async_query() with nothing pending answers false and leaves (rethrows under STRICT) the last error; a
// server error at reap time per report mode; close / destructor / reconnect with a reply pending; the other fiber-free
// commands (escape, more_results) keep working.
function c(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function t(string $l, callable $f): void
{
    try { $v = $f(); echo $l, ': ', is_object($v) ? get_class($v) : json_encode($v), "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), '|', $e instanceof mysqli_sql_exception ? $e->getSqlState() : '', "\n"; }
}
function st(mysqli $m): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno . ' state=' . $m->sqlstate . ' err=' . $m->error . ' list=' . count($m->error_list);
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = c();
$db = getenv('MYSQLI_DB');
$cmds = [
    'query' => fn($m) => $m->query('SELECT 1'),
    'query async' => fn($m) => $m->query('SELECT 1', MYSQLI_ASYNC),
    'real_query' => fn($m) => $m->real_query('SELECT 1'),
    'multi_query' => fn($m) => $m->multi_query('SELECT 1; SELECT 2'),
    'prepare' => fn($m) => $m->prepare('SELECT 1'),
    'execute_query' => fn($m) => $m->execute_query('SELECT ?', [1]),
    'select_db' => fn($m) => $m->select_db($db),
    'set_charset' => fn($m) => $m->set_charset('utf8mb4'),
    'begin_transaction' => fn($m) => $m->begin_transaction(),
    'commit' => fn($m) => $m->commit(),
    'rollback' => fn($m) => $m->rollback(),
    'autocommit' => fn($m) => $m->autocommit(true),
    'savepoint' => fn($m) => $m->savepoint('s'),
    'stat' => fn($m) => $m->stat(),
    'store_result' => fn($m) => $m->store_result(),
    'use_result' => fn($m) => $m->use_result(),
    'next_result' => fn($m) => $m->next_result(),
    'more_results' => fn($m) => $m->more_results(),
    'get_warnings' => fn($m) => $m->get_warnings(),
    'escape' => fn($m) => $m->real_escape_string("a'b"),
    'stmt_init+prepare' => fn($m) => $m->stmt_init()->prepare('SELECT 1'),
];
foreach ($cmds as $n => $f) {
    $m->query('SELECT SLEEP(0.01), 1', MYSQLI_ASYNC);
    t($n, fn() => $f($m));
    echo '   ', st($m), "\n";
    t('   reap', fn() => ($r = $m->reap_async_query()) instanceof mysqli_result ? $r->fetch_row() : $r);
    echo '   ', st($m), "\n";
}

echo "-- multi_query refused: the multi-statements switch is refused too (only when it is off)\n";
$m->real_query('SELECT 1');
t('mq pend', fn() => $m->multi_query('SELECT 1; SELECT 2'));
echo '   ', st($m), "\n";
$m->store_result();
t('mq on', fn() => $m->multi_query('SELECT 1; SELECT 2'));
$m->store_result();
t('mq on, chain pending', fn() => $m->multi_query('SELECT 3'));
echo '   ', st($m), "\n";
while ($m->more_results()) { $m->next_result(); $m->store_result(); }
t('mq after', fn() => $m->multi_query('SELECT 5; SELECT 6'));
while ($m->more_results()) { $m->next_result(); $m->store_result(); }

echo "-- reap with nothing pending\n";
$f = c();
t('fresh', fn() => @$f->reap_async_query());
echo '   ', st($f), "\n";
$f->query('SELECT nope');
t('stale OFF', fn() => @$f->reap_async_query());
echo '   ', st($f), "\n";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
t('stale STRICT', fn() => @$f->reap_async_query());
t('stale STRICT proc', fn() => @mysqli_reap_async_query($f));
$f->query('SELECT 1');
t('clean STRICT', fn() => @$f->reap_async_query());
mysqli_report(MYSQLI_REPORT_OFF);
t('init', fn() => mysqli_init()->reap_async_query());

echo "-- out of sync per report mode\n";
foreach (['OFF' => MYSQLI_REPORT_OFF, 'ERROR' => MYSQLI_REPORT_ERROR, 'STRICT' => MYSQLI_REPORT_STRICT, 'ERROR|STRICT' => MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT] as $mn => $mode) {
    mysqli_report($mode);
    t("$mn send", fn() => $m->query('SELECT 1', MYSQLI_ASYNC));
    t("$mn second", fn() => @$m->query('SELECT 2'));
    t("$mn second async", fn() => @mysqli_query($m, 'SELECT 2', MYSQLI_ASYNC));
    t("$mn reap", fn() => @$m->reap_async_query()->fetch_row());
    t("$mn bad send", fn() => @$m->query('SELECT nope', MYSQLI_ASYNC));
    echo '   ', st($m), "\n";
    t("$mn bad reap", fn() => @$m->reap_async_query());
    echo '   ', st($m), "\n";
    t("$mn reap again", fn() => @$m->reap_async_query());
    t("$mn dup key", function () use ($m) {
        @$m->query('CREATE TEMPORARY TABLE IF NOT EXISTS tk (id INT PRIMARY KEY)');
        @$m->query('DELETE FROM tk');
        @$m->query('INSERT INTO tk VALUES (1)');
        $m->query('INSERT INTO tk VALUES (1)', MYSQLI_ASYNC);
        return @$m->reap_async_query();
    });
    echo '   ', st($m), "\n";
}
mysqli_report(MYSQLI_REPORT_OFF);

echo "-- close / destructor / reconnect with a reply pending\n";
$p = c();
$tid = $p->thread_id;
t('send', fn() => $p->query('SELECT SLEEP(0.3)', MYSQLI_ASYNC));
t('close', fn() => $p->close());
t('reap after close', fn() => $p->reap_async_query());
t('poll after close', function () use ($p) { $r = [$p]; $e = null; $rej = []; return mysqli_poll($r, $e, $rej, 0); });
$q = c();
t('send q', fn() => $q->query('SELECT SLEEP(0.3)', MYSQLI_ASYNC));
$q = null;
$w = c();
t('fresh link works', fn() => $w->query('SELECT 8')->fetch_row());
$x = c();
t('send x', fn() => $x->query('SELECT 9', MYSQLI_ASYNC));
t('real_connect over pending', fn() => $x->real_connect(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT')));
t('reap after reconnect', fn() => @$x->reap_async_query());
t('query after reconnect', fn() => $x->query('SELECT 10')->fetch_row());
$y = c();
t('send y', fn() => $y->query('SELECT 11', MYSQLI_ASYNC | MYSQLI_USE_RESULT));
$res = $y->reap_async_query();
t('close under used result', fn() => $y->close());
t('fetch after close', fn() => $res->fetch_row());
