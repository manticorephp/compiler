<?php

// CURLOPT_WRITEFUNCTION with a real PHP closure.
//
// fn_to_ptr() needs a STRING LITERAL function name, so a Closure can never be
// the pointer libcurl holds. It holds a fixed trampoline instead, and the
// void* alongside it (CURLOPT_WRITEDATA) is the handle id — which is how the
// trampoline finds this closure again. Everything below is the observable half
// of that: chunk accounting, the return-length contract, and what happens when
// a callback lies about how much it wrote or throws outright.

$tmp = \sys_get_temp_dir() . '/mc_curl_wcb.txt';
$line = "0123456789abcdef0123456789abcdef\n";        // 33 bytes
\file_put_contents($tmp, \str_repeat($line, 4096));  // 135168 bytes
$want = \strlen(\str_repeat($line, 4096));

// 1. A closure that accepts everything. It must see the whole body, in order,
//    and its second argument is a real PHP string — libcurl's buffer carries no
//    refcount header, so the trampoline has to copy before PHP ever sees it.
$seen = '';
$calls = 0;
$ch = \curl_init('file://' . $tmp);
\curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($h, $data) use (&$seen, &$calls) {
    $seen = $seen . $data;
    $calls = $calls + 1;
    return \strlen($data);
});
$rc = \curl_exec($ch);
echo "exec:     ", $rc === true ? 'true' : \var_export($rc, true), "\n";
echo "errno:    ", \curl_errno($ch), "\n";
echo "length:   ", \strlen($seen) === $want ? 'match' : ('MISMATCH ' . \strlen($seen)), "\n";
echo "ordered:  ", \strpos($seen, $line) === 0 ? 'yes' : 'no', "\n";
echo "chunked:  ", $calls > 1 ? 'yes' : 'no', "\n";
echo "tail:     ", \substr($seen, -17, 16), "\n";

// 2. A callback that returns a SHORT count. libcurl reads that as "the sink
//    could not take it" and aborts with CURLE_WRITE_ERROR (23).
$ch2 = \curl_init('file://' . $tmp);
\curl_setopt($ch2, CURLOPT_WRITEFUNCTION, function ($h, $data) {
    return \strlen($data) - 1;
});
$rc2 = \curl_exec($ch2);
echo "short:    ", \var_export($rc2, true), " errno=", \curl_errno($ch2), "\n";

// 3. A callback that THROWS. It cannot throw through libcurl's frames — the
//    longjmp would land above them and leave the transfer half-unwound — so the
//    Throwable is parked, the transfer is failed, and curl_exec rethrows it here.
$ch3 = \curl_init('file://' . $tmp);
\curl_setopt($ch3, CURLOPT_WRITEFUNCTION, function ($h, $data) {
    throw new \RuntimeException('callback said no');
});
try {
    \curl_exec($ch3);
    echo "throw:    NO THROW\n";
} catch (\RuntimeException $e) {
    echo "throw:    ", $e->getMessage(), "\n";
}

// 4. RETURNTRANSFER and a WRITEFUNCTION together: php's callback WINS, and
//    curl_exec answers the empty string because nothing reached the buffer.
$got = '';
$ch4 = \curl_init('file://' . $tmp);
\curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch4, CURLOPT_WRITEFUNCTION, function ($h, $data) use (&$got) {
    $got = $got . $data;
    return \strlen($data);
});
$r4 = \curl_exec($ch4);
echo "both:     ", \var_export($r4, true), " cb_len=", \strlen($got) === $want ? 'match' : 'MISMATCH', "\n";

\unlink($tmp);
