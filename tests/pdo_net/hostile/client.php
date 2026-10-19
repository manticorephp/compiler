<?php
// Compiled natively; usage: client <mode> <port> [mysql]. Prints a stable summary of the failure.
// No php oracle (the fake server targets our driver's checks): expected output is hand-written in hostile.expected.
$mode = $argv[1];
// us_<mode>: the same fake server mode served to an UNBUFFERED client (MYSQL_ATTR_USE_BUFFERED_QUERY = false) that fetches to the end
$tag = str_starts_with($mode, 'us_') ? 'us_' : (str_starts_with($mode, 'fb_') ? 'fb_' : '');   // fb_<mode>: a pgsql client with PGSQL_ATTR_FETCH_BATCH = 2
$mode = substr($mode, strlen($tag));
$my = ($argv[3] ?? '') === 'mysql';
$dsn = ($my ? 'mysql' : 'pgsql') . ':host=127.0.0.1;port=' . $argv[2] . ';dbname=d' . ($my ? '' : (str_starts_with($mode, 'ssl_') ? ';sslmode=require' . ($mode === 'ssl_tls12' ? ';ssl_min_protocol_version=TLSv1.3' : '') : ';sslmode=disable'));
$fds = fn () => count(scandir('/dev/fd'));
// the first socket lazily opens the runtime's poller fd, which stays for the process: warm it up first
$w = stream_socket_server('tcp://127.0.0.1:0');
$wc = stream_socket_client('tcp://' . stream_socket_get_name($w, false));
fclose($wc); fclose($w);
$before = $fds();
$t = microtime(true);
$np = false;
try {
    // idle_slow: ATTR_TIMEOUT unset (the default must not bound a statement that is still running); *_explicit / stall_mid: set to 1
    $opts = $mode === 'idle_slow' || $mode === 'idle_between' || $mode === 'drain_idle_default' || $mode === 'batch_close_idle_default' || $mode === 'np_idle_slow' || $mode === 'copy_slow_commit' ? [] : [PDO::ATTR_TIMEOUT => in_array($mode, ['drip', 'big_slow', 'idle_explicit', 'stall_mid', 'kill_stall', 'drain_slow', 'batch_stall_mid', 'batch_close_slow', 'np_idle_explicit', 'np_stall_mid', 'ssl_silent', 'ssl_drip'], true) ? 1 : 3];
    if ($my && str_starts_with($mode, 'ssl_')) { $opts += [1008 => '/nonexistent', 1013 => false]; }   // MYSQL_ATTR_SSL_CA, MYSQL_ATTR_SSL_VERIFY_SERVER_CERT: TLS wanted, nothing verified
    if ($tag === 'us_') { $opts += [1000 => false]; }
    if ($tag === 'fb_') { $opts += [1300 => 2]; }
    $np = $my && str_starts_with($mode, 'np_');
    if ($np) { $opts += [PDO::ATTR_EMULATE_PREPARES => false]; }
    $db = new PDO($dsn, 'u', 'pw', $opts);
    if ($np) {
        // binary protocol: prepare + execute + fetch, then (np_close_once) release the statement and ask the server how many closes it saw
        $p = $db->prepare('select ?');
        $p->execute(['x']);
        $r = $p->fetch(PDO::FETCH_NUM);
        if ($tag === 'us_') { while ($p->fetch(PDO::FETCH_NUM) !== false) { } }
        echo $tag, $mode, ': ok ', var_export($r[0], true), "\n";
        if ($mode === 'np_idle_slow') { echo $tag, $mode, ': ', microtime(true) - $t > 5.0 ? 'waited' : 'TOO FAST', "\n"; }
        if ($mode === 'np_close_once') {
            $p = null;
            $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
            $q = $db->query('select 1');
            echo $tag, $mode, ': closes seen ', $q->fetchColumn(), "\n";
            $q = null;
        }
    } elseif ($my) {
        $q = $db->query('select 1');   // not chained (#299)
        if ($mode === 'drain_bad') { $q->fetch(); $q->closeCursor(); $q = null; echo $tag, $mode, ": closed\n"; $s = $db->query('select 1'); echo 'reuse: ', $s->fetchColumn(), "\n"; $s = null; }
        elseif ($mode === 'drain_bad_next') { $q->fetch(); $q->closeCursor(); echo $tag, $mode, ": closed\n"; }
        elseif ($mode === 'drain_idle_default') { $q->fetch(); $q->closeCursor(); $q = null; echo $tag, $mode, ": closed\n"; $q = $db->query('select 2'); $q->fetchAll(); }
        elseif ($mode === 'drain_slow') { $q->fetch(); $q->closeCursor(); $q = null; echo $tag, $mode, ": closed\n"; $q = $db->query('select 2'); $q->fetchAll(); }
        elseif ($mode === 'drain_dtor') { $q->fetch(); $q = null; $q = $db->query('select 2'); $q->fetchAll(); }
        elseif ($mode === 'more_after_eof' || $mode === 'more_bad_header') { while ($q->fetch() !== false) { } echo $tag, $mode, ": rows done\n"; $q->nextRowset(); }
        else {
            $r = $q->fetch(PDO::FETCH_NUM);
            if ($tag === 'us_') { while ($q->fetch(PDO::FETCH_NUM) !== false) { } }
        }
        $q = null;
        if ($mode === 'big_slow') {
            echo $tag, $mode, ': ok ', strlen($r[0]), ' ', microtime(true) - $t > 2.0 ? 'longer than 2 x timeout' : 'TOO FAST', "\n";
        } elseif ($mode === 'idle_slow' || $mode === 'idle_between') {
            echo $tag, $mode, ": ok ", microtime(true) - $t > ($mode === 'idle_between' ? 31.0 : 5.0) ? 'waited' : 'TOO FAST', "\n";
        } elseif (in_array($mode, ['int_max', 'int_min', 'uint_max', 'bit_max', 'float_plus'], true)) {
            echo $tag, $mode, ': ok ', json_encode($r[0]), "\n";
        }
    } elseif ($mode === 'big_slow') {
        $q = $db->query('select 1');   // not chained (#299)
        $rows = $q->fetchAll(PDO::FETCH_NUM);
        $q = null;
        echo $tag, $mode, ': ok ', strlen($rows[0][0]), ' ', microtime(true) - $t > 2.0 ? 'longer than 2 x timeout' : 'TOO FAST', "\n";
    } elseif (str_starts_with($mode, 'batch_')) {
        $p = $db->prepare('select 1');
        $p->execute();
        if ($mode === 'batch_close_slow' || $mode === 'batch_close_idle_default') { $p->fetch(); $p->closeCursor(); echo $tag, $mode, ": closed\n"; }
        else {
            $out = [];
            while (($r = $p->fetch(PDO::FETCH_NUM)) !== false) { $out[] = $r[0]; }
            echo $tag, $mode, ': ok ', implode(',', $out), "\n";
        }
    } elseif ($mode === 'oid_miss') {
        $p = $db->prepare('select 1');
        $p->execute();
        $a = $p->getColumnMeta(0)['native_type'];
        $b = $p->getColumnMeta(0)['native_type'];
        echo $tag, $mode, ': ', json_encode($a), ' ', json_encode($b), "\n";
    } elseif ($mode === 'oid_flood') {
        $p = $db->prepare('select 1');
        $p->execute();
        $first = $p->getColumnMeta(0)['native_type'];
        for ($i = 1; $i < 5000; $i++) { $p->getColumnMeta($i); }
        echo $tag, $mode, ': ', $p->getColumnMeta(0)['native_type'] === $first ? 'unbounded' : 'bounded', "\n";
    } elseif (in_array($mode, ['paramdesc_truncated', 'paramdesc_mismatch', 'datarow_overrun'], true)) {
        $p = $db->prepare('select ?::text as a');   // not chained: #299 leaks a chained union-typed temporary
        $p->execute(['x']);
    } elseif (in_array($mode, ['bytea_odd', 'bytea_nonhex', 'bytea_escape', 'bytea_escape_bad', 'int_garbage', 'int_max', 'int_min', 'int_overflow', 'int_underflow', 'float_lead', 'float_trail', 'float_minus', 'float_plus'], true)) {
        $p = $db->prepare('select 1');
        $p->execute();
        $r = $p->fetch(PDO::FETCH_NUM);
        echo $tag, $mode, ': ok ', is_resource($r[0]) ? bin2hex(stream_get_contents($r[0])) : json_encode($r[0]), "\n";
    } elseif (in_array($mode, ['notify_trunc', 'notify_unterminated', 'notify_over_cap', 'notify_big_d'], true)) {
        $db->getNotify(PDO::FETCH_ASSOC, 3000);
    } elseif ($mode === 'notify_flood' || $mode === 'notify_bytes') {
        $q = $db->query('select 1');
        $k = 0;
        while ($db->getNotify(PDO::FETCH_NUM) !== false) { $k++; }
        echo $tag, $mode, ': ok ', $k, "\n";
    } elseif ($mode === 'copy_slow_commit') {
        $r = $db->copyFromArray('t', ['1', '2']);
        echo $tag, $mode, ': ', $r ? 'ok' : 'false', "\n";
    } elseif ($mode === 'copy_out_cap' || $mode === 'copy_d_outside') {
        $db->copyToArray('t');
    } elseif ($mode === 'copy_in_err') {
        $db->copyFromArray('t', ['1', '2']);
    } elseif ($mode === 'idle_slow' || $mode === 'idle_between') {
        $q = $db->query('select 1');
        $q->fetchAll();
        $q = null;
        echo $tag, $mode, ': ok ', microtime(true) - $t > ($mode === 'idle_between' ? 31.0 : 5.0) ? 'waited' : 'TOO FAST', "\n";
    } else {
        $db->query('select 1')->fetchAll();
    }
    if (!in_array($mode, ['np_ok', 'batch_ok', 'batch_close_slow', 'copy_slow_commit', 'batch_close_idle_default', 'notify_flood', 'notify_bytes', 'np_close_once', 'np_idle_slow', 'np_float_odd', 'np_nan', 'bytea_escape', 'int_max', 'int_min', 'float_plus', 'oid_flood', 'big_slow', 'oid_miss', 'uint_max', 'bit_max', 'idle_slow', 'idle_between'], true)) { echo "$tag$mode: NO ERROR\n"; }
} catch (PDOException $e) {
    $m = (string) $e->errorInfo[2];
    $i = strpos($m, 'failed: ');
    echo $tag, $mode, ': ', $e->errorInfo[0], ' ', $e->errorInfo[1] ?? '-', ' ', $i === false ? $m : substr($m, $i + 8), "\n";
    // a server-side error leaves the connection usable; a protocol error closes it
    // (a named statement, not a chained temporary: #299 leaks the temporary and with it the connection)
    if ($np && in_array($mode, ['np_err_prepare', 'np_err_exec'], true)) {
        $p2 = $mode === 'np_err_exec' ? $p : $db->prepare('select ?');
        $p2->execute(['y']);
        echo 'reuse: ', $p2->fetchColumn(), "\n";
    }
    if ($my && in_array($mode, ['int_garbage', 'int_empty', 'int_overflow', 'int_underflow', 'uint_over', 'float_lead', 'float_trail', 'float_minus', 'float_plus', 'bit_long', 'err_mid_rows'], true)) {
        $q = null;
        $s = $db->query('select 1'); echo 'reuse: ', $s->fetchColumn(), "\n"; $s = null;
    }
    if (!$my && in_array($mode, ['batch_err_second', 'paramdesc_mismatch', 'copy_in_err', 'ext_copy_in', 'ext_copy_out', 'bytea_odd', 'bytea_nonhex', 'bytea_escape_bad', 'int_garbage', 'int_overflow', 'int_underflow', 'float_lead', 'float_trail', 'float_minus'], true)) { $s = $db->query('select 1'); echo 'reuse: ', $s->fetchColumn(), "\n"; $s = null; }
}
$e = null; $p = null; $p2 = null; $s = null; $q = null; $db = null;
echo microtime(true) - $t < ($mode === 'copy_slow_commit' ? 64.0 + 60.0 : ($mode === 'idle_between' ? 45.0 : ($mode === 'drain_idle_default' || $mode === 'batch_close_idle_default' ? 9.0 : ($mode === 'big_slow' || $mode === 'idle_slow' || $mode === 'np_idle_slow' ? 15.0 : ($mode === 'idle_explicit' || $mode === 'stall_mid' || $mode === 'kill_stall' || $mode === 'drain_slow' || $mode === 'batch_stall_mid' || $mode === 'batch_close_slow' || $mode === 'np_idle_explicit' || $mode === 'np_stall_mid' ? 5.0 : 3.0))))) ? "fast\n" : "SLOW\n";
echo $fds() === $before ? "no fd leak\n" : "FD LEAK\n";
