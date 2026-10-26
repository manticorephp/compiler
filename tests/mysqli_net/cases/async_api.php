<?php
// Zend's async API: query(MYSQLI_ASYNC) sends and returns true, poll() rewrites read/error/reject (identity, order, keys
// dropped, duplicates kept), reap_async_query() answers the stored / used result, an OK, or false + errno; the argument
// errors of poll (negative timeouts, no arrays, a non-mysqli element, a closed or unconnected link).
mysqli_report(MYSQLI_REPORT_OFF);
function c(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function t(string $l, callable $f): void
{
    try { $v = json_encode($f()); echo $l, ': ', $v, "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), "\n"; }
}
function st(mysqli $m): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ' info=' . json_encode($m->info) . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno;
}
$a = c();
$b = c();
$d = c();
/** @var array<string,mysqli> $all */
$all = ['a' => $a, 'b' => $b, 'd' => $d];
function ids(?array $x): string
{
    global $all;
    if ($x === null) { return 'null'; }
    $o = [];
    foreach ($x as $k => $v) { $o[] = $k . '=' . (string) array_search($v, $all, true); }
    return '[' . implode(',', $o) . ']';
}
function poll(?array $r, ?array $e, int $s, int $us = 0, bool $method = false): void
{
    $rej = ['stale'];
    try {
        $n = $method ? @mysqli::poll($r, $e, $rej, $s, $us) : @mysqli_poll($r, $e, $rej, $s, $us);
        echo '  poll ', json_encode($n), ' r=', ids($r), ' e=', ids($e), ' rej=', ids($rej), "\n";
    } catch (Throwable $t) {
        echo '  poll ! ', get_class($t), ': ', $t->getMessage(), ' r=', ids($r === null ? null : array_filter($r, 'is_object')), ' rej=', json_encode($rej), "\n";
    }
}

echo "-- dispatch on two links, poll until both reaped\n";
$a->query('SELECT 1');
t('send a', fn() => $a->query('SELECT SLEEP(0.4), 1 AS x', MYSQLI_ASYNC));
echo '  a ', st($a), "\n";
t('send b', fn() => mysqli_query($b, "SELECT 2 AS y, 'two' AS z", MYSQLI_ASYNC));
$left = ['a' => $a, 'b' => $b];
$rounds = 0;
while (count($left) > 0 && $rounds < 50) {
    $rounds++;
    $r = array_values($left);
    $e = $r;
    $rej = [];
    $n = mysqli_poll($r, $e, $rej, 2);
    echo '  ready ', $n, ' r=', ids($r), ' e=', ids($e), ' rej=', ids($rej), "\n";
    foreach ($r as $m) {
        $name = (string) array_search($m, $all, true);
        $res = $name === 'a' ? $m->reap_async_query() : mysqli_reap_async_query($m);
        echo '  reap ', $name, ': ', json_encode($res->fetch_all(MYSQLI_ASSOC)), ' ', st($m), "\n";
        unset($left[$name]);
    }
}

echo "-- timeouts, reject, keys, duplicates\n";
poll([$a, $b], [$a], 0);
$a->query('SELECT SLEEP(0.3)', MYSQLI_ASYNC);
$d->query('SELECT 3', MYSQLI_ASYNC);
usleep(100000);
poll([$a], null, 0, 20000);
poll(['x' => $b, 'y' => $d, 'z' => $a, 7 => $d], null, 1);
poll([$b, $b], [$b], 0, 0, true);
poll([$a, $b], [$d, $a], 1, 500000);
t('reap a', fn() => $a->reap_async_query()->fetch_row());
t('reap d', fn() => $d->reap_async_query()->fetch_row());
poll([$a, $d], null, 0);
poll([], [], 0);
poll([], null, 0);
poll(null, [], 0);

echo "-- busy but not async: polled, not rejected\n";
$a->real_query('SELECT 4');
poll([$a, $b], null, 0);
$a->store_result();
$u = $a->query('SELECT 5', MYSQLI_USE_RESULT);
poll([$a], null, 0, 10000);
$u->free();

echo "-- argument errors\n";
poll(null, null, 0);
poll([$a], null, -1);
poll([$a], null, 0, -1, true);
poll([1], null, 0);
poll([$a, 2.5], null, 0, 0, true);
poll([$a, $a, null], null, 0);
poll([$a, $a, $a, $a, $a, []], null, 0);
poll([$a, $a, $a, $a, $a, $a, new stdClass()], null, 0);
poll([$a], [$a, 'x'], 0);
poll([true], null, 0);
poll([false], null, 0);
poll([fn() => 1], null, 0);
$i = mysqli_init();
poll([$i, 7], null, 0);
poll([$a], [$i], 0);
$z = c();
$z->close();
poll([$z, $i], null, 0);
class MyLink extends mysqli {}
$s = new MyLink(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
$s->query('SELECT 6', MYSQLI_ASYNC);
$r = [$s]; $e = null; $rej = [];
echo '  subclass ', mysqli_poll($r, $e, $rej, 2), ' ', get_class($r[0]), "\n";
t('reap sub', fn() => $s->reap_async_query()->fetch_row());
$s->close();
poll([$s], null, 0, 0, true);

echo "-- reap answers\n";
t('async DO', fn() => $a->query('DO 1', MYSQLI_ASYNC));
t('reap DO', fn() => $a->reap_async_query());
echo '  ', st($a), "\n";
$a->query('CREATE TEMPORARY TABLE ta (id INT AUTO_INCREMENT PRIMARY KEY, v INT)');
t('async INSERT', fn() => $a->query('INSERT INTO ta (v) VALUES (1),(2),(3)', MYSQLI_ASYNC));
t('reap INSERT', fn() => $a->reap_async_query());
echo '  ', st($a), "\n";
t('async UPDATE', fn() => $a->query('UPDATE ta SET v = v + 1 WHERE id > 1', MYSQLI_ASYNC));
t('reap UPDATE', fn() => $a->reap_async_query());
echo '  ', st($a), "\n";
t('async USE', fn() => $a->query('SELECT id, v FROM ta ORDER BY id', MYSQLI_ASYNC | MYSQLI_USE_RESULT));
$res = $a->reap_async_query();
t('used class', fn() => get_class($res));
t('used row', fn() => $res->fetch_row());
t('query while used', fn() => $a->query('SELECT 1'));
t('used rest', fn() => $res->fetch_all());
t('used num_rows', fn() => $res->num_rows);
$res->free();
t('async STORE', fn() => $a->query("SELECT 'a' AS s, 2 AS n, 2.5e0 AS f, NULL AS z", MYSQLI_ASYNC | MYSQLI_STORE_RESULT));
$a->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
t('native set before reap', fn() => $a->reap_async_query()->fetch_assoc());
$a->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, false);
t('async CALL-free multi', fn() => $a->query('SELECT 1; SELECT 2', MYSQLI_ASYNC));
t('reap multi', fn() => $a->reap_async_query());
echo '  ', st($a), "\n";
t('after error, async ok', fn() => $a->query('SELECT 7', MYSQLI_ASYNC));
echo '  ', st($a), "\n";
t('reap 7', fn() => $a->reap_async_query()->fetch_row());
t('bad mode', fn() => $a->query('SELECT 1', MYSQLI_ASYNC | 4));
t('empty query', fn() => mysqli_query($a, '', MYSQLI_ASYNC));
echo "-- loop of async cycles\n";
function cycles(mysqli $a, int $from, int $to): int
{
    $sum = 0;
    $pad = str_repeat('p', 4096);
    for ($k = $from; $k < $to; $k++) {
        $a->query("SELECT $k AS k, '$pad' AS pad", MYSQLI_ASYNC);
        $r = [$a]; $e = null; $rej = [];
        mysqli_poll($r, $e, $rej, 5);
        $sum += (int) $a->reap_async_query()->fetch_row()[0];
        $a->query('SELECT nope', MYSQLI_ASYNC);
        $a->reap_async_query();
    }
    return $sum;
}
$sum = cycles($a, 0, 200);
$m0 = memory_get_peak_usage();
$sum += cycles($a, 200, 2000);
$mb = (memory_get_peak_usage() - $m0) >> 20;
echo '  sum ', $sum, ' ', $mb < 4 ? 'flat' : "LEAK ($mb MB)", "\n";
