<?php

// ext/curl against a real HTTP server on the loopback.
//
// The responder is DELIBERATELY plain PHP — stream_socket_server, fread, fwrite
// and nothing else — so php and the native binary run the identical server and
// the comparison is a real parity check rather than a hand-written expectation.
//
// ⚠ The server must be its OWN PROCESS. curl_easy_perform() blocks the calling
// thread outright, so a server sharing this process (the way
// http_loopback_get.php can, because both halves are cooperative Async tasks)
// would never reach accept() and the request would time out.
//
// ⚠ The listener is bound in the PARENT, BEFORE the fork. The backlog then holds
// the parent's connect() even if the child has not reached accept() yet, which
// removes the "did the child come up in time" race entirely — strictly better
// than sleeping.
//
// The port is scanned and NEVER printed; `@` matters because php warns to STDOUT
// on a failed bind, and that would poison the expected output.

$port = 0;
$listener = false;
for ($p = 49500; $p < 49580; $p = $p + 1) {
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

/** Answer $count requests with a plain-text echo of what was received. */
function mc_serve($listener, int $count): void
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
        $path = \count($bits) > 1 ? $bits[1] : '';
        $clen = 0;
        $ua = '';
        $xtest = '';
        $ctype = '';
        foreach ($lines as $k => $l) {
            if ($k === 0) { continue; }
            $p2 = \strpos($l, ':');
            if ($p2 === false) { continue; }
            $name = \strtolower(\substr($l, 0, $p2));
            $val = \trim(\substr($l, $p2 + 1));
            if ($name === 'content-length') { $clen = (int) $val; }
            if ($name === 'user-agent') { $ua = $val; }
            if ($name === 'x-test') { $xtest = $val; }
            if ($name === 'content-type') { $ctype = $val; }
        }
        while (\strlen($body) < $clen) {
            $chunk = \fread($c, $clen - \strlen($body));
            if ($chunk === false || $chunk === '') { break; }
            $body = $body . $chunk;
        }

        $payload = "method=" . $method . "\npath=" . $path . "\nua=" . $ua
                 . "\nx-test=" . $xtest . "\nctype=" . $ctype . "\nbody=" . $body . "\n";
        // Fixed headers only — a Date: would differ on every run and there is
        // nothing to normalise it against.
        $resp = "HTTP/1.1 200 OK\r\n"
              . "Content-Type: text/plain\r\n"
              . "X-Fixed: yes\r\n"
              . "Content-Length: " . \strlen($payload) . "\r\n"
              . "Connection: close\r\n\r\n" . $payload;
        \fwrite($c, $resp);
        \fclose($c);
    }
}

$pid = \pcntl_fork();
if ($pid === 0) {
    \mc_serve($listener, 4);
    exit(0);
}
\fclose($listener);

$base = 'http://127.0.0.1:' . $port;

// 1. A plain GET with a user agent and one custom request header.
$ch = \curl_init($base . '/one');
\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch, CURLOPT_USERAGENT, 'manticore-curl/1.0');
\curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Test: alpha']);
$body = \curl_exec($ch);
echo "-- get --\n", $body;
echo "errno:  ", \curl_errno($ch), "\n";

// 2. CURLOPT_HEADER folds the response headers into the body. libcurl only
//    routes them to the write callback when NO header callback is installed —
//    and ours always is, since it carries a user's CURLOPT_HEADERFUNCTION — so
//    this is ext/curl's own work, not libcurl's.
$ch2 = \curl_init($base . '/two');
\curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch2, CURLOPT_HEADER, true);
$withHead = \curl_exec($ch2);
echo "-- header --\n";
echo "status:  ", \substr($withHead, 0, 15), "\n";
echo "hasfix:  ", \strpos($withHead, "X-Fixed: yes") !== false ? 'yes' : 'no', "\n";
echo "hasbody: ", \strpos($withHead, "method=GET") !== false ? 'yes' : 'no', "\n";

// 3. CURLOPT_HEADERFUNCTION sees each header line, in order, terminator included.
//
// ⚠ The `@var string[]` is not decoration. A bare `array` local whose only
// stores happen inside a CLOSURE keeps element type `unknown` at the read site
// while the closure body stores a concrete `string` raw — the two ends disagree
// about the repr and `echo $hdrs[0]` prints the address. Annotating the element
// type is the documented remedy, and the compiler asks for it by name elsewhere.
// See tests/aot/cases/array_erased_elem_repr_gap.php.
/** @var string[] $hdrs */
$hdrs = [];
$ch3 = \curl_init($base . '/three');
\curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch3, CURLOPT_HEADERFUNCTION, function ($h, $line) use (&$hdrs) {
    $hdrs[] = \rtrim($line, "\r\n");
    return \strlen($line);
});
$b3 = \curl_exec($ch3);
echo "-- headerfunction --\n";
echo "count>3: ", \count($hdrs) > 3 ? 'yes' : 'no', "\n";
echo "first:   ", $hdrs[0], "\n";
echo "blank:   ", $hdrs[\count($hdrs) - 1] === '' ? 'yes' : 'no', "\n";
echo "inbody:  ", \strpos($b3, 'X-Fixed') === false ? 'no' : 'yes', "\n";

// 4. CURLOPT_NOBODY issues a HEAD: headers arrive, the body does not.
$ch4 = \curl_init($base . '/four');
\curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch4, CURLOPT_NOBODY, true);
$b4 = \curl_exec($ch4);
echo "-- nobody --\n";
echo "empty:   ", $b4 === '' ? 'yes' : 'no', "\n";
echo "errno:   ", \curl_errno($ch4), "\n";

$status = 0;
\pcntl_waitpid($pid, $status);
