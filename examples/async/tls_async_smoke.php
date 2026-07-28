<?php

// NETWORK-DEPENDENT smoke test (deliberately not in tests/aot — the offline suite
// cannot stand up a TLS peer). This is the regression check for the FFI C-`int`
// return sign-extension bug: SSL_read's WANT_READ (-1) used to arrive as
// 4294967295, so __mc_stream_fill treated it as a byte count and memmove'd 4 GB.
// It only fired under a scheduler, because a blocking fd never makes SSL_read
// return -1.
//
//   bin/manticore compile examples/async/tls_async_smoke.php -o /tmp/tls_smoke && /tmp/tls_smoke
//
// Run it a handful of times: the pre-fix crash rate was roughly 50%, and 100%
// once the response was consumed.

use function Async\async;
use function Async\spawn;

$t0 = microtime(true);

// Two DIFFERENT hosts, so name resolution overlaps too (async DNS).
$lens = async(function (): string {
    $a = spawn(function (): int {
        $d = @file_get_contents('https://example.com/');
        return $d === false ? -1 : strlen($d);
    });
    $b = spawn(function (): int {
        $d = @file_get_contents('https://www.iana.org/');
        return $d === false ? -1 : strlen($d);
    });
    return (string)$a->await() . '/' . (string)$b->await();
});
$par = microtime(true) - $t0;

$t1 = microtime(true);
$s1 = @file_get_contents('https://example.com/');
$s2 = @file_get_contents('https://www.iana.org/');
$seq = microtime(true) - $t1;

echo "async  lengths: ", $lens, "\n";
echo "sync   lengths: ", ($s1 === false ? -1 : strlen($s1)), "/", ($s2 === false ? -1 : strlen($s2)), "\n";
printf("parallel %.2fs vs sequential %.2fs\n", $par, $seq);
echo "overlapped: ", ($par < $seq * 0.75 ? "yes" : "no"), "\n";

// dns_get_record rides the same netpoller-parked UDP exchange.
$recs = async(function (): int {
    $x = spawn(function (): int { $r = dns_get_record('example.com', DNS_A); return $r === false ? -1 : count($r); });
    $y = spawn(function (): int { $r = dns_get_record('www.iana.org', DNS_A); return $r === false ? -1 : count($r); });
    return $x->await() + $y->await();
});
echo "dns records: ", $recs, "\n";
