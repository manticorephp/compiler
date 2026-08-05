<?php

// curl_multi over two concurrent file:// transfers, against real php's.
//
// The interesting part is curl_multi_info_read: it is the ONE libcurl struct
// this extension dereferences (CURLMsg — msg@0, easy_handle@8, data@16 on every
// LP64 target), and the read proves itself by looking the easy_handle up among
// the live handles rather than trusting the offsets.
//
// Completion ORDER is not deterministic, so the messages are sorted by the body
// they produced before anything is printed.

$a = \sys_get_temp_dir() . '/mc_curl_multi_a.txt';
$b = \sys_get_temp_dir() . '/mc_curl_multi_b.txt';
\file_put_contents($a, "alpha-body\n");
\file_put_contents($b, "beta-body-longer\n");

$mh = \curl_multi_init();

$ca = \curl_init('file://' . $a);
\curl_setopt($ca, CURLOPT_RETURNTRANSFER, true);
$cb = \curl_init('file://' . $b);
\curl_setopt($cb, CURLOPT_RETURNTRANSFER, true);

echo "add: ", \curl_multi_add_handle($mh, $ca), " ", \curl_multi_add_handle($mh, $cb), "\n";

// ⚠ $running is initialised BEFORE the call. It is an out-parameter, and passing
// an undefined variable by reference is a php spelling this compiler does not
// accept yet — it reads as a dangling local.
$running = 0;
$rc = 0;
do {
    $rc = \curl_multi_exec($mh, $running);
    if ($running > 0) { \curl_multi_select($mh, 0.2); }
} while ($running > 0 && $rc === CURLM_OK);

echo "exec rc: ", $rc, " running: ", $running, "\n";

/** @var string[] $done */
$done = [];
$queued = 0;
while (true) {
    $m = \curl_multi_info_read($mh, $queued);
    if ($m === false) { break; }
    $body = \curl_multi_getcontent($m['handle']);
    $done[] = 'msg=' . $m['msg'] . ' result=' . $m['result'] . ' len=' . \strlen($body);
}
\sort($done);
foreach ($done as $line) { echo "info: ", $line, "\n"; }

echo "content a: ", \curl_multi_getcontent($ca);
echo "content b: ", \curl_multi_getcontent($cb);
echo "errno:     ", \curl_multi_errno($mh), "\n";
echo "strerror:  ", \var_export(\curl_multi_strerror(0), true), "\n";
echo "CURLM_OK:  ", CURLM_OK, " CURLMSG_DONE: ", CURLMSG_DONE, "\n";

echo "remove:    ", \curl_multi_remove_handle($mh, $ca), " ",
     \curl_multi_remove_handle($mh, $cb), "\n";

\unlink($a);
\unlink($b);
