<?php

// The HTTP/1.1 wire codec under Http\Server (prelude/http.php), called directly.
// MANTICORE-ONLY: php has no Http\ namespace, so it fatals here before printing
// anything and difftest classifies the case as PHP-SKIP. That is why the very
// first statement is one of those calls — anything echoed before it would turn
// the skip into a DIFF.

echo Http\reason(200), "|", Http\reason(431), "|", Http\reason(599), "|\n";

// --- tokens -------------------------------------------------------------
foreach (["GET", "X-Trace", "a b", "", "Content-Length:", "a\tb"] as $t) {
    echo Http\tokenOk($t) ? "1" : "0";
}
echo "\n";

// --- head boundary + line unfolding -------------------------------------
$head = "GET / HTTP/1.1\r\nHost: a\r\nX-Long: one\r\n\ttwo\r\nX-B: 2\r\n\r\nBODY";
echo Http\headEnd($head, 0), "\n";
$lines = Http\splitHead(substr($head, 0, Http\headEnd($head, 0)));
foreach ($lines as $l) {
    echo "[", $l, "]\n";
}
// a fold with nothing to fold into is malformed
echo count(Http\splitHead(" oops\r\nHost: a")), "\n";

// --- request line -------------------------------------------------------
foreach (["GET /a?b=1 HTTP/1.1", "POST / HTTP/1.0", "OPTIONS * HTTP/1.1",
          "GET /", "GET / HTTP/1.1 extra", "GE T / HTTP/1.1", "GET  / HTTP/1.1",
          "GET / SPDY/3"] as $rl) {
    $p = Http\reqLine($rl);
    if (count($p) === 0) {
        echo "BAD\n";
        continue;
    }
    echo $p[0], " ", $p[1], " v", $p[2], "\n";
}

// --- header lines -------------------------------------------------------
foreach (["Host: example.com", "X-Empty:", "Content-Length : 5", "novalue",
          ":leading", "X-Pad:   spaced   "] as $hl) {
    $p = Http\headerSplit($hl);
    if (count($p) === 0) {
        echo "BAD\n";
        continue;
    }
    echo "<", $p[0], ">=<", $p[1], ">\n";
}

// --- target split + path normalisation ----------------------------------
foreach (["/a/b?x=1&y=2", "/a/b", "/?", "*"] as $t) {
    $p = Http\splitPath($t);
    echo "<", $p[0], ">?<", $p[1], ">\n";
}
foreach (["/a/./b", "/a/../b", "/../../etc/passwd", "/a//b/", "/", "/a/b/..",
          "/%2e%2e/x", "/a%2Fb", "*"] as $p) {
    echo Http\normPath($p), "\n";
}

// --- query + cookies ----------------------------------------------------
$q = Http\parseQuery("a=1&b=hello+world&c&d=%2F&a=2");
foreach ($q as $k => $v) {
    echo $k, "=", $v, ";";
}
echo "\n", count(Http\parseQuery("")), "\n";

$c = Http\parseCookies("sid=abc; theme=dark; bad; e=a%20b");
foreach ($c as $k => $v) {
    echo $k, "=", $v, ";";
}
echo "\n";

// --- chunked framing ----------------------------------------------------
$body = "1a; ext=1\r\n" . str_repeat("x", 26) . "\r\n0\r\n\r\n";
echo Http\chunkHdr($body, 0), " ", Http\chunkSize($body, 0), "\n";
echo Http\chunkHdr("5", 0), " ", Http\chunkSize("5", 0), "\n";
echo Http\chunkHdr(str_repeat("0", 40), 0), "\n";
echo Http\chunkSize("ffffffffffffffff\r\n", 0), "\n";
echo Http\chunkSize("zz\r\n", 0), "\n";
echo str_replace("\r\n", "|", Http\chunkFrame("hey")), "\n";
echo str_replace("\r\n", "|", Http\chunkFrame("")), "\n";

// --- response rendering -------------------------------------------------
echo str_replace("\r\n", "|", Http\statusLine(204, "1.1")), "\n";
echo str_replace("\r\n", "|", Http\statusLine(599, "1.0")), "\n";
$hl = [];
$hl[] = "Content-Type: text/plain";
$hl[] = "Content-Length: 3";
echo str_replace("\r\n", "|", Http\renderLines($hl)), "\n";
echo Http\httpDate(0), "\n";
echo Http\httpDate(1735689600), "\n";

// --- framing decisions --------------------------------------------------
foreach ([["", "1.1"], ["close", "1.1"], ["keep-alive, Upgrade", "1.1"],
          ["", "1.0"], ["keep-alive", "1.0"], ["Close", "1.1"]] as $pair) {
    echo Http\connClose($pair[0], $pair[1]) ? "1" : "0";
}
echo "\n";

foreach (["chunked", "gzip, chunked", "chunked, gzip", "identity", ""] as $te) {
    echo Http\teIsChunked($te) ? "1" : "0";
}
echo "\n";

foreach (["5", "0", " 12 ", "5,5", "5,6", "-1", "1e3", "", "abc", "99999999999999999999"] as $v) {
    echo Http\contentLength($v), ";";
}
echo "\n";

echo "done\n";
