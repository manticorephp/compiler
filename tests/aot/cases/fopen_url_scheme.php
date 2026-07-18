<?php

// Offline, deterministic, difftest-parity: fopen() on an http(s):// URL is a WIRED
// scheme (routes to the HTTP client, false when it cannot connect), not a filename.
// The real read — fopen a URL, fgets/fread/feof the body byte-identical to php — was
// validated manually against example.com (559 bytes, http and https); a live peer is
// needed for that. NOTE: per the exceptions-not-warnings rule, would-warn cases
// (fseek/rewind on this non-seekable stream) are NOT asserted for php parity.
var_dump(@fopen('https://127.0.0.1:1/', 'r'));   // closed -> false
var_dump(@fopen('http://127.0.0.1:1/', 'r'));    // closed -> false
var_dump(@fopen('gopher://127.0.0.1:1/', 'r'));  // unknown scheme -> false
echo "done\n";
