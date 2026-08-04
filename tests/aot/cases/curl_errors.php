<?php

// ext/curl's error surface, against real php's.
//
// The rule the project follows everywhere else applies here too: where php
// WARNS we throw — but where php returns false with NO diagnostic, so do we.
// curl_exec on a handle with no URL is exactly that case, and getting it wrong
// in either direction is a parity bug.

$ch = \curl_init();
$rc = \curl_exec($ch);
echo "no url:   ", \var_export($rc, true), "\n";
echo "errno:    ", \curl_errno($ch), "\n";
echo "error:    ", \curl_error($ch), "\n";

echo "strerr 0: ", \var_export(\curl_strerror(0), true), "\n";
echo "strerr 3: ", \var_export(\curl_strerror(3), true), "\n";
echo "strerr 23:", \var_export(\curl_strerror(23), true), "\n";
echo "strerr 42:", \var_export(\curl_strerror(42), true), "\n";

// An option number libcurl has never heard of. php 8 throws ValueError here
// rather than warning, which is the behaviour we match.
try {
    \curl_setopt($ch, 999999, 1);
    echo "bad opt:  NO THROW\n";
} catch (\ValueError $e) {
    echo "bad opt:  ValueError: ", $e->getMessage(), "\n";
}

// A missing local file: libcurl's own message, not ours.
$missing = \sys_get_temp_dir() . '/mc_curl_does_not_exist_9d3f.txt';
\curl_setopt($ch, CURLOPT_URL, 'file://' . $missing);
\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$r2 = \curl_exec($ch);
echo "missing:  ", \var_export($r2, true), " errno=", \curl_errno($ch), "\n";

// A protocol nothing supports.
\curl_setopt($ch, CURLOPT_URL, 'nosuchscheme://example.invalid/');
$r3 = \curl_exec($ch);
echo "scheme:   ", \var_export($r3, true), " errno=", \curl_errno($ch), "\n";

unset($ch);
