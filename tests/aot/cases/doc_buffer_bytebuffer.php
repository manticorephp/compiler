<?php
// Buffer\ByteBuffer: append bytes, consume them through a cursor.

use Buffer\ByteBuffer;

$b = new ByteBuffer();
$b->append("GET / HTTP/1.1\r\nHost: x\r\n\r\nBODY");

// indexOf() is relative to the cursor; -1 means "not there (yet)".
$end = $b->indexOf("\r\n\r\n");
echo "head ends at ", $end, "\n";

$head = $b->read($end);        // consume the head
$b->skip(4);                   // and the blank line, without copying it out
echo "head: ", json_encode($head), "\n";
echo "left: ", $b->length(), " bytes, next byte ", $b->byteAt(0), " (", chr($b->byteAt(0)), ")\n";
echo "byteAt past the end: ", $b->byteAt(100), "\n";

// peek() looks, read() consumes; both are short at the end of the buffer.
echo "peek: ", $b->peek(2), "\n";
echo "read: ", $b->read(100), "\n";
echo "empty: ", var_export($b->isEmpty(), true), "\n";

// A capped buffer refuses a write that would cross the cap, and appends nothing.
$c = new ByteBuffer(8);
var_dump($c->append("12345"));
var_dump($c->append("6789"));
echo "view: ", $c->view(), "\n";
echo "readAll: ", $c->readAll(), " then empty=", var_export($c->isEmpty(), true), "\n";
