<?php
// @php-skip — see http_parse.php.
//
// The same head at 0/4/8/16/32 headers. The point is not the absolute number
// but the SLOPE: fixed cost per request against cost per header. That is what
// separates "the logic around the walk is expensive" from "each header costs
// an allocation" — and only the second one has ever moved a number here.

use Buffer\ByteBuffer;
use Http\Parser;

// Numeric arg = absolute count; a non-numeric one (the LEAK harness's dummy)
// scales the default.
$n = 50000 * $argc;
if ($argc > 1 && (int)$argv[1] > 0) {
    $n = (int)$argv[1];
}

$counts = [0, 4, 8, 16, 32];
$acc = 0;
foreach ($counts as $hc) {
    $wire = "GET /users/42?page=2 HTTP/1.1\r\nHost: example.com\r\n";
    for ($k = 0; $k < $hc; $k++) {
        $wire .= 'X-Field-' . $k . ': value-' . $k . "-padding-to-a-realistic-length\r\n";
    }
    $wire .= "\r\n";

    $t0 = microtime(true);
    for ($i = 0; $i < $n; $i++) {
        $buf = new ByteBuffer(0);
        $buf->append($wire);
        $p = new Parser($buf, '127.0.0.1');
        if ($p->parse() === Parser::READY) {
            $r = $p->request();
            if ($r !== null) {
                $acc += strlen($r->path);
            }
        }
    }
    $t1 = microtime(true);
    printf("headers=%-3d %8.0f ns/req\n", $hc, ($t1 - $t0) * 1000000000.0 / $n);
}
printf("n=%d acc=%d\n", $n, $acc);
