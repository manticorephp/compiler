<?php
// Compiled natively; usage: client <mode> <port> [mysql]. Prints a stable summary of the failure.
// No php oracle (the fake server targets our driver's checks): expected output is hand-written in hostile.expected.
$mode = $argv[1];
$my = ($argv[3] ?? '') === 'mysql';
$dsn = ($my ? 'mysql' : 'pgsql') . ':host=127.0.0.1;port=' . $argv[2] . ';dbname=d';
$fds = fn () => count(scandir('/dev/fd'));
// the first socket lazily opens the runtime's poller fd, which stays for the process: warm it up first
$w = stream_socket_server('tcp://127.0.0.1:0');
$wc = stream_socket_client('tcp://' . stream_socket_get_name($w, false));
fclose($wc); fclose($w);
$before = $fds();
$t = microtime(true);
try {
    $db = new PDO($dsn, 'u', 'pw', [PDO::ATTR_TIMEOUT => in_array($mode, ['drip', 'big_slow'], true) ? 1 : 3]);
    if ($my) {
        $q = $db->query('select 1');   // not chained (#299)
        $r = $q->fetch(PDO::FETCH_NUM);
        $q = null;
        if ($mode === 'big_slow') {
            echo $mode, ': ok ', strlen($r[0]), ' ', microtime(true) - $t > 2.0 ? 'longer than 2 x timeout' : 'TOO FAST', "\n";
        } elseif (in_array($mode, ['int_max', 'int_min', 'uint_max', 'bit_max', 'float_plus'], true)) {
            echo $mode, ': ok ', json_encode($r[0]), "\n";
        }
    } elseif ($mode === 'big_slow') {
        $q = $db->query('select 1');   // not chained (#299)
        $rows = $q->fetchAll(PDO::FETCH_NUM);
        $q = null;
        echo $mode, ': ok ', strlen($rows[0][0]), ' ', microtime(true) - $t > 2.0 ? 'longer than 2 x timeout' : 'TOO FAST', "\n";
    } elseif ($mode === 'oid_miss') {
        $p = $db->prepare('select 1');
        $p->execute();
        $a = $p->getColumnMeta(0)['native_type'];
        $b = $p->getColumnMeta(0)['native_type'];
        echo $mode, ': ', json_encode($a), ' ', json_encode($b), "\n";
    } elseif ($mode === 'oid_flood') {
        $p = $db->prepare('select 1');
        $p->execute();
        $first = $p->getColumnMeta(0)['native_type'];
        for ($i = 1; $i < 5000; $i++) { $p->getColumnMeta($i); }
        echo $mode, ': ', $p->getColumnMeta(0)['native_type'] === $first ? 'unbounded' : 'bounded', "\n";
    } elseif (in_array($mode, ['paramdesc_truncated', 'paramdesc_mismatch', 'datarow_overrun'], true)) {
        $p = $db->prepare('select ?::text as a');   // not chained: #299 leaks a chained union-typed temporary
        $p->execute(['x']);
    } elseif (in_array($mode, ['bytea_odd', 'bytea_nonhex', 'bytea_escape', 'bytea_escape_bad', 'int_garbage', 'int_max', 'int_min', 'int_overflow', 'int_underflow', 'float_lead', 'float_trail', 'float_minus', 'float_plus'], true)) {
        $p = $db->prepare('select 1');
        $p->execute();
        $r = $p->fetch(PDO::FETCH_NUM);
        echo $mode, ': ok ', is_resource($r[0]) ? bin2hex(stream_get_contents($r[0])) : json_encode($r[0]), "\n";
    } else {
        $db->query('select 1')->fetchAll();
    }
    if (!in_array($mode, ['bytea_escape', 'int_max', 'int_min', 'float_plus', 'oid_flood', 'big_slow', 'oid_miss', 'uint_max', 'bit_max'], true)) { echo "$mode: NO ERROR\n"; }
} catch (PDOException $e) {
    $m = (string) $e->errorInfo[2];
    $i = strpos($m, 'failed: ');
    echo $mode, ': ', $e->errorInfo[0], ' ', $e->errorInfo[1] ?? '-', ' ', $i === false ? $m : substr($m, $i + 8), "\n";
    // a server-side error leaves the connection usable; a protocol error closes it
    // (a named statement, not a chained temporary: #299 leaks the temporary and with it the connection)
    if ($my && in_array($mode, ['int_garbage', 'int_empty', 'int_overflow', 'int_underflow', 'uint_over', 'float_lead', 'float_trail', 'float_minus', 'float_plus', 'bit_long', 'err_mid_rows'], true)) {
        $s = $db->query('select 1'); echo 'reuse: ', $s->fetchColumn(), "\n"; $s = null;
    }
    if (!$my && in_array($mode, ['paramdesc_mismatch', 'ext_copy_in', 'ext_copy_out', 'bytea_odd', 'bytea_nonhex', 'bytea_escape_bad', 'int_garbage', 'int_overflow', 'int_underflow', 'float_lead', 'float_trail', 'float_minus'], true)) { $s = $db->query('select 1'); echo 'reuse: ', $s->fetchColumn(), "\n"; $s = null; }
}
$e = null; $p = null; $s = null; $q = null; $db = null;
echo microtime(true) - $t < ($mode === 'big_slow' ? 10.0 : 3.0) ? "fast\n" : "SLOW\n";
echo $fds() === $before ? "no fd leak\n" : "FD LEAK\n";
