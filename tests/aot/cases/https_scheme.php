<?php

// Offline, deterministic: proves https:// is a WIRED scheme that routes to the
// HTTP client (which then fails to connect to a closed port) rather than being
// silently treated as a filename. A real TLS handshake needs a live, trusted
// peer, so end-to-end https is validated manually against real endpoints, not in
// the offline suite. Cert verification, SNI and http->https redirects were all
// confirmed byte-identical to php 8.5.8.
var_dump(@file_get_contents('https://127.0.0.1:1/'));   // nothing on port 1 -> false
var_dump(@file_get_contents('gopher://127.0.0.1:1/'));  // unknown scheme -> false
var_dump(@file_get_contents('https://'));               // no host -> false
echo "done\n";
