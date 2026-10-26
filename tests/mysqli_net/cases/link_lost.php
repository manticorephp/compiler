<?php
// A result or statement that outlives its link: close() and the object's destruction (unset) both end the link, so an
// unbuffered fetch is out of sync (thrown under STRICT, false otherwise), a buffered result keeps its rows, a statement
// finds the server gone; dropping a link closes its connection.
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function t(string $l, callable $f): void
{
    try { $v = json_encode($f()); echo $l, ': ', $v, "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), "\n"; }
}
foreach ([MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, MYSQLI_REPORT_OFF] as $mode) {
    mysqli_report($mode);
    echo "== mode $mode\n";
    $m = conn();
    $u = $m->query('SELECT 1 UNION SELECT 2', MYSQLI_USE_RESULT);
    t('first', fn() => $u->fetch_row());
    $m->close();
    foreach (['fetch_row', 'fetch_assoc', 'fetch_array', 'fetch_object', 'fetch_all'] as $f) {
        t("closed $f", fn() => $u->$f());
    }
    t('closed mysqli_fetch_row', fn() => mysqli_fetch_row($u));
    $m = conn();
    $u = $m->query('SELECT 1 UNION SELECT 2', MYSQLI_USE_RESULT);
    unset($m);
    t('unset fetch_row', fn() => $u->fetch_row());
    t('unset fetch_row again', fn() => $u->fetch_row());
    $m = conn();
    $b = $m->query('SELECT 1 UNION SELECT 2');
    unset($m);
    t('unset buffered', fn() => [$b->fetch_row(), $b->fetch_row(), $b->fetch_row()]);
    $m = conn();
    $s = $m->prepare('SELECT ?');
    unset($m);
    $x = 1;
    t('unset stmt', function () use ($s, $x) { $s->bind_param('i', $x); return $s->execute(); });
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mon = conn();
$before = (int) $mon->query("SHOW STATUS LIKE 'Threads_connected'")->fetch_row()[1];
for ($i = 0; $i < 60; $i++) {
    $m = conn();
    $m->query('SELECT 1')->fetch_row();
    $u = $m->query('SELECT 1', MYSQLI_USE_RESULT);
    unset($m, $u);
}
usleep(200000);
$after = (int) $mon->query("SHOW STATUS LIKE 'Threads_connected'")->fetch_row()[1];
echo 'dropped links closed: ', json_encode($after - $before < 10), "\n";
