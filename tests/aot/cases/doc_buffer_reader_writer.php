<?php
// Buffer\Writer batches small writes; Buffer\Reader reads a bounded body and
// leaves whatever follows it in the shared buffer.

use Buffer\ByteBuffer;
use Buffer\Reader;
use Buffer\Writer;

$p = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

$w = new Writer($p[0]);
$w->write("HELLO-");
$w->write("BODY");
echo "queued: ", $w->pending(), ", on the wire: ", $w->bytesWritten(), "\n";
$w->writev(["next-", "request"]);   // flushes the queue, then one writev
echo "queued: ", $w->pending(), ", on the wire: ", $w->bytesWritten(), "\n";
fclose($p[0]);                      // the peer is done: reads now see EOF

// A Reader with a 10-byte budget; the buffer is shared with the next reader.
$buf = new ByteBuffer();
$r = new Reader($p[1], $buf, 10);
echo "remaining: ", $r->remaining(), "\n";
echo "read: ", $r->read(4), "\n";
echo "read: ", $r->read(100), "\n";
echo "budget spent: ", var_export($r->eof(), true), ", read '", $r->read(5), "'\n";
echo "left in buffer: ", $buf->view(), "\n";

// readAll() refuses more than its cap: '' and ->over, no exception.
$buf2 = new ByteBuffer();
$q = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
fwrite($q[0], str_repeat("z", 100));
fclose($q[0]);
$r2 = new Reader($q[1], $buf2);
$all = $r2->readAll(50);
echo "readAll(50): ", strlen($all), " bytes, over=", var_export($r2->over, true), "\n";
fclose($p[1]);
fclose($q[1]);
