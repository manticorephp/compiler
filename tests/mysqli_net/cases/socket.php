<?php
// Host `localhost` is the unix socket: the $socket argument, else ini mysqli.default_socket. Needs MYSQLI_SOCKET (a
// path that reaches the server); without it the case prints one line on both sides and proves nothing.
$s = (string) getenv('MYSQLI_SOCKET');
if ($s === '') {
    echo "no MYSQLI_SOCKET\n";
    exit(0);
}
$u = getenv('MYSQLI_USER'); $p = getenv('MYSQLI_PASS'); $d = getenv('MYSQLI_DB');
function show(string $label, mysqli $m): void
{
    echo $label, ': ', $m->host_info, ' ', mysqli_get_host_info($m), ' proto=', $m->protocol_version, ' version_ok=',
        var_export($m->server_version > 50000, true), ' ping=', var_export(@$m->ping(), true), ' ',
        'select_db=', var_export($m->select_db(getenv('MYSQLI_DB')), true), "\n";
}
try {
    show('socket arg', new mysqli('localhost', $u, $p, $d, 0, $s));
    show('LOCALHOST', new mysqli('LOCALHOST', $u, $p, $d, null, $s));
    show('p:localhost', mysqli_connect('p:localhost', $u, $p, $d, 0, $s));
    $m = mysqli_init();
    var_dump($m->real_connect('localhost', $u, $p, $d, 0, $s));
    show('real_connect', $m);
    // the compiled-in default differs per build (Zend: /tmp/mysql.sock here, /var/run/mysqld/mysqld.sock on Debian); only the round trip is pinned
var_dump(is_string(ini_set('mysqli.default_socket', $s)), ini_get('mysqli.default_socket') === $s);
    show('ini default_socket', new mysqli('localhost', $u, $p, $d));
    show('null host', new mysqli(null, $u, $p, $d));
} catch (mysqli_sql_exception $e) {
    echo get_class($e), ' ', $e->getCode(), ' ', $e->getMessage(), "\n";
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = @new mysqli('localhost', $u, $p, $d, 0, $s . '.missing');
var_dump($m->connect_errno, $m->connect_error);
