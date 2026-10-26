<?php
// Manticore-only (no php oracle: Async\ is a superset); expectation lives in the sibling .expected file.
// Every blocking mysqli call inside an Async task parks the fiber: two tasks on two connections overlap their
// SELECT SLEEP(0.3) (connect, query, prepare/execute, use_result fetch, poll, reap), and a ticker task keeps running.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function c(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
/** @param callable(int):string $work */
function overlap(string $label, callable $work): void
{
    async(function () use ($label, $work) {
        $done = false;
        $ticks = 0;
        $ticker = spawn(function () use (&$done, &$ticks) {
            while (!$done) { delay(0.02); $ticks++; }
            return 0;
        });
        $t0 = microtime(true);
        $res = awaitAll(spawn($work, 1), spawn($work, 2));
        $ms = (microtime(true) - $t0) * 1000;
        $done = true;
        $ticker->await();
        echo $label, ': ', implode(' ', $res), ' ', $ms < 500 ? 'overlapped' : 'serial (' . (int) $ms . ' ms)', ' ', $ticks >= 5 ? 'loop-alive' : 'loop-blocked (' . $ticks . ')', "\n";
    });
}

overlap('query', function (int $i): string {
    $m = c();
    $r = $m->query("SELECT SLEEP(0.3), $i AS i");
    return 'q' . $r->fetch_row()[1];
});
overlap('use_result', function (int $i): string {
    $m = c();
    $r = $m->query("SELECT $i AS i UNION ALL SELECT SLEEP(0.3) + $i", MYSQLI_USE_RESULT);
    $o = [];
    while (($row = $r->fetch_row()) !== null) { $o[] = $row[0]; }
    return 'u' . implode('/', $o);
});
overlap('prepared', function (int $i): string {
    $m = c();
    $s = $m->prepare('SELECT SLEEP(?), ? AS i');
    $sl = 0.3;
    $s->bind_param('di', $sl, $i);
    $s->execute();
    $s->bind_result($z, $v);
    $s->fetch();
    return 'p' . $v;
});
overlap('async api', function (int $i): string {
    $m = c();
    $m->query("SELECT SLEEP(0.3), $i AS i", MYSQLI_ASYNC);
    $r = [$m]; $e = []; $rej = [];
    $n = mysqli_poll($r, $e, $rej, 0);
    $polls = 1;
    while ($n === 0) {
        $r = [$m]; $e = []; $rej = [];
        $n = mysqli_poll($r, $e, $rej, 1);
        $polls++;
    }
    $res = $m->reap_async_query();
    return 'a' . $res->fetch_row()[1] . ($polls >= 2 ? '' : '(ready at once?)');
});
overlap('multi', function (int $i): string {
    $m = c();
    $m->multi_query("DO SLEEP(0.15); SELECT SLEEP(0.15), $i");
    $o = '';
    do {
        $r = $m->store_result();
        if ($r instanceof mysqli_result) { $o .= $r->fetch_row()[1]; }
    } while ($m->next_result());
    return 'm' . $o;
});
async(function () {
    $m = c();
    $t = spawn(function () use ($m) {
        $m->query('SELECT SLEEP(0.2)', MYSQLI_ASYNC);
        delay(0.4);
        return $m->reap_async_query()->fetch_row()[0];
    });
    delay(0.05);
    try {
        $m->reap_async_query();
        echo "reap from another fiber: no error\n";
    } catch (mysqli_sql_exception $e) {
        echo 'reap from another fiber: ', $e->getCode(), ' ', $e->getMessage(), "\n";
    }
    echo 'owner reaps: ', $t->await(), "\n";
});
