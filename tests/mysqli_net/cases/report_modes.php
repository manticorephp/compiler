<?php
// Review Focus 1: wrong password, refused port, unknown database (and a failing INIT_COMMAND, a failing select_db /
// set_charset / options on a live connection) under every report mode. A connect error throws iff STRICT is set; a
// command error throws iff ERROR and STRICT are both set; otherwise false + errno/error/sqlstate, no warning (the @
// silences php's, which Manticore never emits).
$h = getenv('MYSQLI_HOST'); $u = getenv('MYSQLI_USER'); $p = getenv('MYSQLI_PASS'); $d = getenv('MYSQLI_DB'); $port = (int) getenv('MYSQLI_PORT');
$refused = 1; // nothing listens on tcp/1
function exc(Throwable $e): string
{
    return get_class($e) . ' code=' . $e->getCode() . ' state=' . ($e instanceof mysqli_sql_exception ? $e->getSqlState() : '-') . ' msg=' . $e->getMessage();
}
function props(mysqli $m): string
{
    $o = [];
    foreach (['connect_errno', 'connect_error', 'errno', 'error', 'sqlstate', 'error_list'] as $k) {
        try { $o[] = $k . '=' . json_encode($m->$k); } catch (Error $e) { $o[] = $k . '!' . $e->getMessage(); }
    }
    return implode(' ', $o);
}
$cases = [
    'badpw' => [$h, $u, 'wrong-password', $d, $port],
    'refused' => [$h, $u, $p, $d, $refused],
    'nodb' => [$h, $u, $p, 'no_such_db', $port],
];
$modes = ['default' => null, 'OFF' => MYSQLI_REPORT_OFF, 'ERROR' => MYSQLI_REPORT_ERROR, 'STRICT' => MYSQLI_REPORT_STRICT,
    'ERROR|STRICT' => MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, 'INDEX' => MYSQLI_REPORT_INDEX, 'ALL' => MYSQLI_REPORT_ALL,
    'ERROR|INDEX' => MYSQLI_REPORT_ERROR | MYSQLI_REPORT_INDEX];
foreach ($modes as $name => $mode) {
    if ($mode !== null) { var_dump(mysqli_report($mode)); }
    $drv = new mysqli_driver();
    echo "== $name report_mode=", $drv->report_mode, "\n";
    foreach ($cases as $k => $a) {
        echo "-- $k\n";
        try {
            $m = @new mysqli(...$a);
            echo 'new: object ', props($m), "\n";
        } catch (Throwable $e) { echo 'new: ', exc($e), "\n"; }
        echo '   connect_errno()=', mysqli_connect_errno(), ' connect_error()=', json_encode(mysqli_connect_error()), "\n";
        try { $r = @mysqli_connect(...$a); echo 'mysqli_connect: ', var_export($r, true), "\n"; } catch (Throwable $e) { echo 'mysqli_connect: ', exc($e), "\n"; }
        $m = mysqli_init();
        try { $r = @$m->real_connect(...$a); echo 'real_connect: ', var_export($r, true), ' ', props($m), "\n"; } catch (Throwable $e) { echo 'real_connect: ', exc($e), ' ', props($m), "\n"; }
        try { $r = @mysqli_real_connect(mysqli_init(), ...$a); echo 'mysqli_real_connect: ', var_export($r, true), "\n"; } catch (Throwable $e) { echo 'mysqli_real_connect: ', exc($e), "\n"; }
    }
    echo "-- init_command\n";
    $m = mysqli_init();
    $m->options(MYSQLI_INIT_COMMAND, 'SELEC 1');
    try { $r = @$m->real_connect($h, $u, $p, $d, $port); echo 'real_connect: ', var_export($r, true), ' ', props($m), "\n"; } catch (Throwable $e) { echo 'real_connect: ', exc($e), "\n"; }
    echo "-- commands\n";
    $m = new mysqli($h, $u, $p, $d, $port);
    foreach (['select_db' => fn() => $m->select_db('no_such_db'), 'mysqli_select_db' => fn() => mysqli_select_db($m, 'no_such_db'),
        'set_charset' => fn() => $m->set_charset('no_such_cs'), 'set_charset server' => fn() => $m->set_charset('ucs2'),
        'options charset' => fn() => $m->options(MYSQLI_SET_CHARSET_NAME, 'no_such_cs'), 'select_db ok' => fn() => $m->select_db($d)] as $k => $f) {
        try { $r = @$f(); echo $k, ': ', var_export($r, true), "\n"; } catch (Throwable $e) { echo $k, ': ', exc($e), "\n"; }
        echo '   ', props($m), "\n";
    }
    var_dump(mysqli_errno($m), mysqli_error($m), mysqli_sqlstate($m), count(mysqli_error_list($m)));
}
// mysqli_report() and mysqli_driver::$report_mode are one value, any int is kept
$drv = new mysqli_driver();
$drv->report_mode = MYSQLI_REPORT_OFF;
var_dump((new mysqli_driver())->report_mode, mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_INDEX), $drv->report_mode, mysqli_report(1000), $drv->report_mode, mysqli_report(-1), $drv->report_mode);
$drv->report_mode = MYSQLI_REPORT_ERROR;
try { var_dump(@$m->select_db('no_such_db'), $m->errno); } catch (Throwable $e) { echo exc($e), "\n"; }
$drv->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;
try { var_dump($m->select_db('no_such_db')); } catch (Throwable $e) { echo exc($e), "\n"; }
