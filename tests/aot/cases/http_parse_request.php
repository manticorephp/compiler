<?php

// MANTICORE-ONLY: php has no Buffer\ByteBuffer or Http\Parser, so php dies on
// the very first statement that could produce output — before any — and difftest
// PHP-SKIPs the file. The expected output is written BY HAND.
//
// The request parser tested without a socket: the parser reads a ByteBuffer, so
// a canned byte string IS a connection as far as it is concerned. That is the
// whole reason Parser takes the buffer rather than the stream — every framing
// decision, every refusal and the split-read resumption are reachable offline
// and deterministically. The transport under it is covered by net_tcp_loopback.

function run(string $raw, int $maxHdrBytes = 16384, int $maxHdrCount = 100, int $maxBody = 8388608): void
{
    $b = new \Buffer\ByteBuffer();
    $b->append($raw);
    $p = new \Http\Parser($b, '10.0.0.7', false, $maxHdrBytes, $maxHdrCount, $maxBody);
    $r = $p->parse();
    if ($r === \Http\Parser::NEED) {
        echo "need\n";
        return;
    }
    if ($r !== \Http\Parser::READY) {
        echo "code=", $r, "\n";
        return;
    }
    $q = $p->request();
    if ($q === null) {
        echo "null\n";
        return;
    }
    echo $q->method, " |", $q->path, "| q=", $q->queryString, " v=", $q->version,
        " host=", $q->headers->get('host'), " ct=", $q->contentType(),
        " len=", strlen($q->body()), " body=", $q->body(), "\n";
}

// --- ordinary requests -------------------------------------------------------
run("GET / HTTP/1.1\r\nHost: a.example\r\n\r\n");
run("GET /a/./b/../c?x=1&y=%20z HTTP/1.1\r\nHost: a.example\r\n\r\n");
run("GET /a/b/../../../etc/passwd HTTP/1.1\r\nHost: a.example\r\n\r\n");
run("HEAD /x/ HTTP/1.0\r\n\r\n");
run("POST /p HTTP/1.1\r\nHost: a.example\r\nContent-Type: text/plain; charset=utf-8\r\n"
    . "Content-Length: 5\r\n\r\nhello");
run("OPTIONS * HTTP/1.1\r\nHost: a.example\r\n\r\n");
run("GET http://origin.example/abs?k=v HTTP/1.1\r\nHost: proxy.example\r\n\r\n");

// --- header handling ---------------------------------------------------------
// obs-fold: the continuation joins its predecessor with one SP.
run("GET / HTTP/1.1\r\nHost: a.example\r\nX-Long: one\r\n  two\r\n\r\n");
// a repeated field is comma-joined for lookup...
$b = new \Buffer\ByteBuffer();
$b->append("GET / HTTP/1.1\r\nHost: a.example\r\nX-Tag: a\r\nX-Tag: b\r\n\r\n");
$p = new \Http\Parser($b);
$p->parse();
$q = $p->request();
echo "x-tag=", $q->header('X-Tag'), " count=", $q->headers->count(),
    " keepalive=", $q->isKeepAlive() ? 'y' : 'n',
    " method=", $q->methodEnum()->name, " safe=", $q->methodEnum()->isSafe() ? 'y' : 'n', "\n";
// ...and the wire lines keep both.
foreach ($q->headers->lines() as $line) {
    echo "  line: ", $line, "\n";
}

// --- query and cookie accessors ---------------------------------------------
$b = new \Buffer\ByteBuffer();
$b->append("GET /s?a=1&b=hello+world&c HTTP/1.1\r\nHost: a.example\r\n"
    . "Cookie: sid=abc%20d; theme=dark\r\nConnection: close\r\n\r\n");
$p = new \Http\Parser($b);
$p->parse();
$q = $p->request();
echo "a=", $q->query('a'), " b=", $q->query('b'), " c=[", $q->query('c'), "]",
    " miss=", $q->query('zz', 'DEF'), " n=", count($q->queries()), "\n";
echo "sid=", $q->cookie('sid'), " theme=", $q->cookie('theme'),
    " keepalive=", $q->isKeepAlive() ? 'y' : 'n', " target=", $q->target, "\n";

// --- chunked -----------------------------------------------------------------
run("POST /c HTTP/1.1\r\nHost: a.example\r\nTransfer-Encoding: chunked\r\n\r\n"
    . "5\r\nhello\r\n6;ext=1\r\n world\r\n0\r\n\r\n");
run("POST /c HTTP/1.1\r\nHost: a.example\r\nTransfer-Encoding: chunked\r\n\r\n"
    . "3\r\nabc\r\n0\r\nX-Trailer: t\r\n\r\n");

// --- refusals ----------------------------------------------------------------
run("GET / HTTP/1.1\r\n\r\n");                                        // 400 no Host
run("GET\r\nHost: a.example\r\n\r\n");                                // 400 request line
run("GET / HTTP/2.0\r\nHost: a.example\r\n\r\n");                     // 505
run("GET / HTTP/1.1\r\nHost : a.example\r\n\r\n");                    // 400 space before colon
run("POST /p HTTP/1.1\r\nHost: a.example\r\n\r\n");                   // 411 no framing
run("POST /p HTTP/1.1\r\nHost: a.example\r\nTransfer-Encoding: chunked\r\n"
    . "Content-Length: 5\r\n\r\n");                                   // 400 TE+CL
run("POST /p HTTP/1.1\r\nHost: a.example\r\nTransfer-Encoding: gzip\r\n\r\n");  // 400 TE not chunked
run("POST /p HTTP/1.1\r\nHost: a.example\r\nContent-Length: 5,6\r\n\r\nhello"); // 400 disagreeing CL
run("POST /p HTTP/1.1\r\nHost: a.example\r\nContent-Length: 9\r\n\r\nbigbody99", 16384, 100, 4); // 413
run("POST /c HTTP/1.1\r\nHost: a.example\r\nTransfer-Encoding: chunked\r\n\r\n"
    . "4\r\nabcd\r\n4\r\nefgh\r\n0\r\n\r\n", 16384, 100, 6);          // 413 accumulated
run("CONNECT a.example:443 HTTP/1.1\r\nHost: a.example\r\n\r\n");     // 501

// header COUNT cap
$hdrs = '';
for ($i = 0; $i < 6; $i++) {
    $hdrs .= "X-H" . $i . ": v\r\n";
}
run("GET / HTTP/1.1\r\nHost: a.example\r\n" . $hdrs . "\r\n", 16384, 4);
// header BYTE cap
run("GET / HTTP/1.1\r\nHost: a.example\r\nX-Big: " . str_repeat('z', 200) . "\r\n\r\n", 64);

// --- incremental: a head split across three feeds ---------------------------
$b = new \Buffer\ByteBuffer();
$p = new \Http\Parser($b);
$b->append("GET /split HTTP/1.1\r\nHo");
echo "feed1=", $p->parse(), "\n";
$b->append("st: a.example\r\nContent-Length: 4\r\n\r");
echo "feed2=", $p->parse(), "\n";
$b->append("\nab");
echo "feed3=", $p->parse(), "\n";
$b->append("cd");
echo "feed4=", $p->parse(), "\n";
echo "split body=", $p->request()->body(), " path=", $p->request()->path, "\n";

// --- two requests already in ONE buffer (pipelining) ------------------------
$b = new \Buffer\ByteBuffer();
$b->append("GET /one HTTP/1.1\r\nHost: a.example\r\n\r\nGET /two HTTP/1.1\r\nHost: a.example\r\n\r\n");
$p = new \Http\Parser($b);
$p->parse();
echo "first=", $p->request()->path, "\n";
$p->reset();
echo "second-code=", $p->parse(), " path=", $p->request()->path,
    " left=", $b->length(), "\n";

// --- Status / Headers / Response ---------------------------------------------
echo \Http\Status::text(404), "|", \Http\Status::text(599), "|",
    \Http\Status::isRedirect(302) ? 'y' : 'n', "|",
    \Http\Status::hasBody(204) ? 'y' : 'n', "|",
    \Http\Status::hasBody(200) ? 'y' : 'n', "\n";

$h = new \Http\Headers();
$h->add('X-A', '1');
$h->add('X-A', '2');
$h->set('X-A', '3');
$h->add('Set-Cookie', 'a=1');
$h->add('Set-Cookie', 'b=2');
echo "x-a=", $h->get('X-A'), " n=", $h->count(), " int=", $h->int('X-A'),
    " missint=", $h->int('X-Z', -1), "\n";
echo str_replace("\r\n", "|", $h->render()), "\n";
$h->remove('set-cookie');
echo "after remove: ", str_replace("\r\n", "|", $h->render()), "\n";

$res = (new \Http\Response())->text('hi')->header('X-One', 'a')->addHeader('X-One', 'b')
    ->cookie('sid', 'v a l', 0, '/', '', false, true, 'Strict')->status(201)->close();
echo "status=", $res->status, " set=", $res->statusWasSet() ? 'y' : 'n',
    " close=", $res->wantsClose() ? 'y' : 'n',
    " streaming=", $res->isStreaming() ? 'y' : 'n', " body=", $res->getBody(), "\n";
echo str_replace("\r\n", "|", $res->headers->render()), "\n";
$res->write('!')->withoutHeader('X-One');
echo "body2=", $res->getBody(), " ", str_replace("\r\n", "|", $res->headers->render()), "\n";

echo "done\n";
