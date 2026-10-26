<?php
// The read-only properties of mysqli and mysqli_driver in every state (init, connected, closed, failed new, failed
// real_connect): the value or Zend's Error, a write's Error text, the procedural getters' errors, var_dump / print_r.
$h = getenv('MYSQLI_HOST'); $u = getenv('MYSQLI_USER'); $p = getenv('MYSQLI_PASS'); $d = getenv('MYSQLI_DB'); $port = (int) getenv('MYSQLI_PORT');
mysqli_report(MYSQLI_REPORT_OFF);
$props = ['affected_rows', 'client_info', 'client_version', 'connect_errno', 'connect_error', 'errno', 'error', 'error_list', 'field_count', 'host_info',
    'info', 'insert_id', 'protocol_version', 'server_info', 'server_version', 'sqlstate', 'thread_id', 'warning_count'];
function readAll(mysqli $m, array $props): void
{
    foreach ($props as $k) {
        try {
            $v = $m->$k;
            // values that differ per run or per server
            if ($k === 'thread_id') { $v = is_int($v) && $v > 0; }
            if ($k === 'server_info' || $k === 'server_version') { $v = gettype($v); }
            // MariaDB's authentication OK carries a per-connection certificate digest as its message
            if ($k === 'info' && is_string($v)) { $v = 'string of ' . strlen($v); }
            echo '  ', $k, ' = ', json_encode($v), ' (', get_debug_type($m->$k), ")\n";
        } catch (Error $e) { echo '  ', $k, ' ! ', get_class($e), ': ', $e->getMessage(), "\n"; }
    }
}
function calls(mysqli $m): void
{
    foreach (['mysqli_errno', 'mysqli_error', 'mysqli_sqlstate', 'mysqli_get_host_info', 'mysqli_thread_id', 'mysqli_affected_rows', 'mysqli_error_list',
        'mysqli_get_server_info', 'mysqli_character_set_name', 'mysqli_real_escape_string'] as $fn) {
        try {
            $v = $fn === 'mysqli_real_escape_string' ? $fn($m, "'") : $fn($m);
            echo '  ', $fn, ' ok ', get_debug_type($v), "\n";
        } catch (Error $e) { echo '  ', $fn, ' ! ', $e->getMessage(), "\n"; }
    }
    foreach (['options' => fn() => $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 1), 'ssl_set' => fn() => $m->ssl_set(null, null, null, null, null),
        'get_charset' => fn() => $m->get_charset(), 'ping' => fn() => @$m->ping(), 'select_db' => fn() => $m->select_db('d'), 'thread_safe' => fn() => $m->thread_safe()] as $k => $f) {
        try { echo '  ', $k, ' ok ', get_debug_type($f()), "\n"; } catch (Error $e) { echo '  ', $k, ' ! ', $e->getMessage(), "\n"; }
    }
}
function writes(mysqli $m, array $props): void
{
    foreach ($props as $k) {
        try { $m->$k = 1; echo "  write $k: no error\n"; } catch (Error $e) { echo "  write $k: ", get_class($e), ': ', $e->getMessage(), "\n"; }
    }
}
/** var_dump with the object handle masked: Manticore does not number handles like php (issue #457) */
function dump(object $o): void
{
    ob_start();
    var_dump($o);
    echo preg_replace('/^(object\(\w+\))#\d+/m', '$1#N', (string) ob_get_clean());
}
$states = [
    'init' => fn() => mysqli_init(),
    'new mysqli()' => fn() => new mysqli(),
    'connected' => fn() => new mysqli($h, $u, $p, $d, $port),
    'closed' => function () use ($h, $u, $p, $d, $port) { $m = new mysqli($h, $u, $p, $d, $port); $m->close(); return $m; },
    'failed new' => fn() => @new mysqli($h, $u, 'wrong-password', $d, $port),
    'failed real_connect' => function () use ($h, $u, $d, $port) { $m = mysqli_init(); @$m->real_connect($h, $u, 'wrong-password', $d, $port); return $m; },
    'init closed' => function () { $m = mysqli_init(); $m->close(); return $m; },
];
foreach ($states as $name => $mk) {
    echo "== $name\n";
    $m = $mk();
    readAll($m, $props);
    calls($mk());
    try { $mk()->close(); echo "  close ok\n"; } catch (Error $e) { echo '  close ! ', $e->getMessage(), "\n"; }
    writes($mk(), $props);
}
// connect_errno / connect_error are the process's last connect attempt, on every object
$ok = new mysqli($h, $u, $p, $d, $port);
$bad = @new mysqli($h, $u, 'wrong-password', $d, $port);
var_dump($ok->connect_errno === 1045, $ok->connect_error === $bad->connect_error, mysqli_connect_errno());
$ok2 = new mysqli($h, $u, $p, $d, $port);
var_dump($bad->connect_errno, $bad->connect_error, $ok->connect_errno, mysqli_connect_error());

// receivers of every static shape read and refuse writes alike: a union return (mysqli_connect, mysqli_init), an
// untyped property, a mixed parameter, an array element, a subclass
class Holder { public $link; public mixed $any; }
function viaMixed(mixed $m): string { return $m->host_info . ' ' . $m->errno . ' ' . json_encode($m->error_list) . ' ' . $m->sqlstate; }
$c = mysqli_connect($h, $u, $p, $d, $port);
$i = mysqli_init();
$hold = new Holder();
$hold->link = $c;
$hold->any = mysqli_init();
echo $c->host_info, ' ', $c->thread_id > 0 ? 'tid' : 'no tid', ' ', $c->protocol_version, ' ', $c->sqlstate, ' ', $i->errno, ' ', $hold->link->host_info, ' ', $hold->any->client_version > 0 ? 'cv' : 'no cv', "\n";
$arr = [$c];
echo viaMixed($c), ' ', $arr[0]->insert_id, "\n";
foreach ([fn() => $i->host_info, fn() => $hold->any->sqlstate] as $f) {
    try { $f(); echo "no error\n"; } catch (Error $e) { echo $e->getMessage(), "\n"; }
}
foreach ([fn() => $c->errno = 1, fn() => $i->insert_id = 2, fn() => $hold->link->error = 'x', fn() => $hold->any->thread_id = 3, fn() => $arr[0]->info = 'i'] as $f) {
    try { $f(); echo "no error\n"; } catch (Error $e) { echo $e->getMessage(), "\n"; }
}
echo $c->errno, ' ', $c->host_info, "\n";

class MyLink extends mysqli {}
$x = new MyLink($h, $u, $p, $d, $port);
try { $x->errno = 5; } catch (Error $e) { echo $e->getMessage(), "\n"; }
echo $x->host_info, ' ', $x->errno, "\n";

$drv = new mysqli_driver();
foreach (['client_info', 'client_version', 'report_mode'] as $k) { var_dump($drv->$k === (new mysqli_driver())->$k); }
var_dump($drv->client_info === mysqli_get_client_info(), $drv->client_version === mysqli_get_client_version(), $drv->report_mode, @$drv->driver_version);
foreach (['client_info', 'client_version', 'driver_version'] as $k) {
    try { $drv->$k = 1; echo "write $k: no error\n"; } catch (Error $e) { echo "write $k: ", $e->getMessage(), "\n"; }
}
$drv->report_mode = 5;
var_dump($drv->report_mode, (new mysqli_driver())->report_mode);
mysqli_report(MYSQLI_REPORT_OFF);
dump($drv);
print_r($drv);
echo "\n";

// dumps: Zend lists the readable properties only (best effort in Manticore; values that vary are masked)
$m = mysqli_init();
dump($m);
$m->close();
print_r($m);
echo "\n";
$m = new mysqli($h, $u, $p, $d, $port);
$s = print_r($m, true);
echo preg_replace(['/\[thread_id\] => \d+/', '/\[server_info\] => .*/', '/\[server_version\] => \d+/', '/\[info\] => .*/'], ['[thread_id] => N', '[server_info] => S', '[server_version] => V', '[info] => I'], $s), "\n";
