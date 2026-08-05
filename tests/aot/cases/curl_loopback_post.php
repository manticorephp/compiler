<?php

// POST, CUSTOMREQUEST, slist headers and CURLOPT_READFUNCTION uploads, against
// the same plain-PHP loopback responder as curl_loopback_get.
//
// The interesting one is CURLOPT_POSTFIELDS: it is the ONE string option
// libcurl does not copy, so forwarding a PHP string's address would hand C a
// pointer that stops being valid the moment the refcount drops. ext/curl routes
// it through POSTFIELDSIZE_LARGE + COPYPOSTFIELDS instead — size FIRST, or
// libcurl strlen()s the buffer and truncates any body containing a NUL.

$port = 0;
$listener = false;
for ($p = 49600; $p < 49680; $p = $p + 1) {
    $s = @\stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) {
        $listener = $s;
        $port = $p;
        break;
    }
}
if ($listener === false) {
    echo "no free port\n";
    return;
}

function mc_serve2($listener, int $count): void
{
    for ($i = 0; $i < $count; $i = $i + 1) {
        $c = @\stream_socket_accept($listener, 5);
        if ($c === false) { return; }
        $req = '';
        while (\strpos($req, "\r\n\r\n") === false) {
            $chunk = \fread($c, 4096);
            if ($chunk === false || $chunk === '') { break; }
            $req = $req . $chunk;
        }
        $cut = \strpos($req, "\r\n\r\n");
        if ($cut === false) { \fclose($c); continue; }
        $head = \substr($req, 0, $cut);
        $body = \substr($req, $cut + 4);
        $lines = \explode("\r\n", $head);
        $bits = \explode(' ', $lines[0]);
        $method = $bits[0];
        $clen = 0;
        $ctype = '';
        $chunked = false;
        foreach ($lines as $k => $l) {
            if ($k === 0) { continue; }
            $p2 = \strpos($l, ':');
            if ($p2 === false) { continue; }
            $name = \strtolower(\substr($l, 0, $p2));
            $val = \trim(\substr($l, $p2 + 1));
            if ($name === 'content-length') { $clen = (int) $val; }
            if ($name === 'content-type') { $ctype = $val; }
            if ($name === 'transfer-encoding' && \strtolower($val) === 'chunked') { $chunked = true; }
        }
        if ($chunked) {
            // Enough of the chunked codec to read what our own uploader sends.
            $acc = '';
            while (true) {
                while (\strpos($body, "\r\n") === false) {
                    $chunk = \fread($c, 4096);
                    if ($chunk === false || $chunk === '') { break; }
                    $body = $body . $chunk;
                }
                $nl = \strpos($body, "\r\n");
                if ($nl === false) { break; }
                $size = \intval(\substr($body, 0, $nl), 16);
                $body = \substr($body, $nl + 2);
                if ($size === 0) { break; }
                while (\strlen($body) < $size + 2) {
                    $chunk = \fread($c, ($size + 2) - \strlen($body));
                    if ($chunk === false || $chunk === '') { break; }
                    $body = $body . $chunk;
                }
                $acc = $acc . \substr($body, 0, $size);
                $body = \substr($body, $size + 2);
            }
            $body = $acc;
        } else {
            while (\strlen($body) < $clen) {
                $chunk = \fread($c, $clen - \strlen($body));
                if ($chunk === false || $chunk === '') { break; }
                $body = $body . $chunk;
            }
        }

        $payload = "method=" . $method . "\nctype=" . $ctype . "\nlen=" . \strlen($body)
                 . "\nbody=" . $body . "\n";
        $resp = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: "
              . \strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload;
        \fwrite($c, $resp);
        \fclose($c);
    }
}

$pid = \pcntl_fork();
if ($pid === 0) {
    \mc_serve2($listener, 4);
    exit(0);
}
\fclose($listener);

$base = 'http://127.0.0.1:' . $port;

// 1. POST with a urlencoded string body.
$ch = \curl_init($base . '/p1');
\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch, CURLOPT_POST, true);
\curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query(['a' => 1, 'b' => 'x y']));
echo "-- post --\n", \curl_exec($ch);

// 2. A JSON body with an explicit Content-Type, i.e. a slist header that has to
//    outlive the transfer and then be freed by us.
$json = '{"k":"v","n":[1,2,3]}';
$ch2 = \curl_init($base . '/p2');
\curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch2, CURLOPT_POST, true);
\curl_setopt($ch2, CURLOPT_POSTFIELDS, $json);
\curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Ignored: 1']);
echo "-- json --\n", \curl_exec($ch2);

// 3. CUSTOMREQUEST overrides the method without touching the body.
$ch3 = \curl_init($base . '/p3');
\curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch3, CURLOPT_CUSTOMREQUEST, 'PATCH');
\curl_setopt($ch3, CURLOPT_POSTFIELDS, 'patched=1');
echo "-- patch --\n", \curl_exec($ch3);

// 4. CURLOPT_UPLOAD + CURLOPT_READFUNCTION: the one trampoline that WRITES into
//    libcurl's buffer. The callback returns a string, we memcpy it in bounded by
//    what libcurl asked for, and 0 means EOF.
$parts = ['chunk-one|', 'chunk-two|', 'chunk-three'];
$ix = 0;
$ch4 = \curl_init($base . '/p4');
\curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch4, CURLOPT_UPLOAD, true);
\curl_setopt($ch4, CURLOPT_CUSTOMREQUEST, 'PUT');
\curl_setopt($ch4, CURLOPT_READFUNCTION, function ($h, $stream, $len) use ($parts, &$ix) {
    if ($ix >= \count($parts)) { return ''; }
    $s = $parts[$ix];
    $ix = $ix + 1;
    return $s;
});
echo "-- upload --\n", \curl_exec($ch4);

$status = 0;
\pcntl_waitpid($pid, $status);
