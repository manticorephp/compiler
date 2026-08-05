<?php

// ext/curl, the easy API over the FILE protocol — no network, no server, and
// every assertion byte-identical to real php's ext/curl.
//
// file:// exercises the whole client path bar the socket: curl_init, the option
// table, the write trampoline that copies libcurl's buffer into a PHP string,
// CURLOPT_RETURNTRANSFER, the error surface and the escape helpers.

$tmp = \sys_get_temp_dir() . '/mc_curl_basic.txt';
\file_put_contents($tmp, "hello curl\nsecond line\n");

$ch = \curl_init('file://' . $tmp);
\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$body = \curl_exec($ch);

echo "errno:    ", \curl_errno($ch), "\n";
echo "error:    '", \curl_error($ch), "'\n";
echo "is_string:", \is_string($body) ? 'yes' : 'no', "\n";
echo "body:     ", $body;

// RETURNTRANSFER off: the body goes to stdout, and curl_exec answers true.
$ch2 = \curl_init('file://' . $tmp);
$rc = \curl_exec($ch2);
echo "direct:   ", $rc === true ? 'true' : \var_export($rc, true), "\n";
// No curl_close(): php 8.5 deprecates it and it has had no effect since php
// 8.0 — the handle is an object and dies with its last reference. Dropping the
// variable is the spelling that means the same thing in both runtimes.
unset($ch2);

// setopt_array over the same handle, then a second transfer on it. A reused
// handle must reset its accumulated body, not append to the first one.
\curl_setopt_array($ch, [
    CURLOPT_URL => 'file://' . $tmp,
    CURLOPT_RETURNTRANSFER => true,
]);
$again = \curl_exec($ch);
echo "reuse:    ", \strlen($again) === \strlen($body) ? 'same length' : 'GREW', "\n";

// curl_reset drops every option — including, on our side, the four trampolines
// and the error buffer, which is why it has to reinstall them.
\curl_reset($ch);
\curl_setopt($ch, CURLOPT_URL, 'file://' . $tmp);
\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$afterReset = \curl_exec($ch);
echo "reset:    ", $afterReset === $body ? 'same body' : 'DIFFERENT', "\n";

echo "escape:   ", \curl_escape($ch, 'a b&c=d/e'), "\n";
echo "unescape: ", \curl_unescape($ch, 'a%20b%26c%3Dd%2Fe'), "\n";

unset($ch);
\unlink($tmp);
