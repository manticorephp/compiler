<?php

// Buffer\Reader and Buffer\Writer over a connected socketpair — offline, no
// listener race, no port. MANTICORE-ONLY: php has no Buffer\ namespace, so it
// fatals on the first line below before printing anything.
//
// The write end is CLOSED after each payload, so an empty fread is a genuine
// EOF rather than a would-block. That distinction is the Reader's whole
// end-of-input signal.

$b = new Buffer\ByteBuffer();

// --- a bounded reader, and the surplus it leaves behind -----------------
// This is the keep-alive property: a Reader limited to the body's length
// over-reads into the SHARED buffer, and the bytes past its budget are still
// there for whoever reads next. Without it a pipelined request is lost.
$p = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
fwrite($p[0], "HELLO-BODYnext-request-here");
fclose($p[0]);

$r = new Buffer\Reader($p[1], $b, 10);
echo $r->remaining(), " ", $r->eof() ? "E" : "-", "\n";
echo "[", $r->read(4), "] rem=", $r->remaining(), " read=", $r->bytesRead(), "\n";
echo "[", $r->read(100), "] rem=", $r->remaining(), " ", $r->eof() ? "E" : "-", "\n";
echo "[", $r->read(5), "]\n";                       // budget spent: empty, not an error
echo "surplus=[", $b->view(), "]\n";                // the next request, still buffered

// A second Reader over the same buffer picks up exactly there.
$r2 = new Buffer\Reader($p[1], $b, -1);
echo "[", $r2->read(4), "] [", $r2->read(100), "]\n";
echo $r2->eof() ? "E" : "-", " read=", $r2->bytesRead(), "\n";
fclose($p[1]);

// --- readAll under and over its cap -------------------------------------
$b2 = new Buffer\ByteBuffer();
$q = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
fwrite($q[0], "0123456789");
fclose($q[0]);
$r3 = new Buffer\Reader($q[1], $b2, 10);
echo "[", $r3->readAll(64), "] over=", $r3->over ? "1" : "0", "\n";
fclose($q[1]);

$b3 = new Buffer\ByteBuffer();
$s = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
fwrite($s[0], str_repeat("z", 5000));
fclose($s[0]);
$r4 = new Buffer\Reader($s[1], $b3, 5000);
echo "[", $r4->readAll(100), "] over=", $r4->over ? "1" : "0", "\n";
fclose($s[1]);

// --- discard drains what a handler ignored ------------------------------
$b4 = new Buffer\ByteBuffer();
$t = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
fwrite($t[0], str_repeat("d", 3000));
fclose($t[0]);
$r5 = new Buffer\Reader($t[1], $b4, 3000);
echo $r5->read(10) === str_repeat("d", 10) ? "1" : "0";
echo " gone=", $r5->discard(), " ", $r5->eof() ? "E" : "-", "\n";
fclose($t[1]);

// --- an unbounded reader stops at EOF, not at a limit -------------------
$b5 = new Buffer\ByteBuffer();
$u = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
fwrite($u[0], "till-the-end");
fclose($u[0]);
$r6 = new Buffer\Reader($u[1], $b5, -1);
echo $r6->remaining(), " [", $r6->readAll(1024), "] ", $r6->eof() ? "E" : "-", "\n";
fclose($u[1]);

// --- Writer: queue, threshold, explicit flush, vectored -----------------
$w = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$out = new Buffer\Writer($w[0], 16);
$out->write("abc");
echo "pending=", $out->pending(), " written=", $out->bytesWritten(), "\n";
$out->write("de");
echo "pending=", $out->pending(), " written=", $out->bytesWritten(), "\n";
$out->write("fghijklmnopq");                        // crosses 16: auto-flush
echo "pending=", $out->pending(), " written=", $out->bytesWritten(), "\n";
echo "[", fread($w[1], 17), "]\n";

$out->write("tail");
$out->flush();
echo "pending=", $out->pending(), " written=", $out->bytesWritten(), "\n";
echo "[", fread($w[1], 4), "]\n";

// writev flushes what is queued FIRST, then hands the vector to one writev(2).
$out->write("Q");
$n = $out->writev(["HEAD\r\n", "BODY"]);
echo "n=", $n, " pending=", $out->pending(), " written=", $out->bytesWritten(), "\n";
echo "[", str_replace("\r\n", "|", fread($w[1], 11)), "]\n";

// An empty write is a no-op, and flushing an empty queue writes nothing.
$out->write("");
$out->flush();
echo "written=", $out->bytesWritten(), "\n";
fclose($w[0]);
fclose($w[1]);

echo "done\n";
