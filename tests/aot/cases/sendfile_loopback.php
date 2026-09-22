<?php

// MANTICORE-ONLY: exercises the __mc_sendfile primitive directly.
$path = rtrim(sys_get_temp_dir(), '/') . '/mc_sendfile_' . getmypid();
$data = '';
for ($i = 0; $i < 20000; $i = $i + 1) {
    $data .= chr($i % 251);
}
file_put_contents($path, $data);

$port = 0;
$l = false;
for ($p = 49800; $p < 49860; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) {
        $l = $s;
        $port = $p;
        break;
    }
}
if ($l === false) {
    echo "no free port\n";
    return;
}

$c = fsockopen('127.0.0.1', $port);
$srv = stream_socket_accept($l, 1.0);
$in = fopen($path, 'rb');

$sent = 0;
$off = 100;
$want = 15000;
while ($sent < $want) {
    $n = __mc_sendfile($srv, $in, $off + $sent, $want - $sent);
    if ($n === -2) {
        usleep(1000);
        continue;
    }
    if ($n < 0) {
        echo 'error ', $n, "\n";
        break;
    }
    $sent = $sent + $n;
}
fclose($srv);
$got = '';
while (($chunk = fread($c, 8192)) !== '') {
    $got .= $chunk;
}
echo 'sent=', $sent, ' got=', strlen($got), ' match=', $got === substr($data, 100, 15000) ? 'yes' : 'no', "\n";
fclose($c);
fclose($in);
fclose($l);
unlink($path);
