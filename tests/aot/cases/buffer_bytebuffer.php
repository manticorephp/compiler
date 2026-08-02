<?php

// Buffer\ByteBuffer — the byte buffer the HTTP parser reads through.
// MANTICORE-ONLY: php has no Buffer\ namespace, so it fatals on the first line
// below before printing anything and difftest classifies this as PHP-SKIP.

$b = new Buffer\ByteBuffer();
echo $b->length(), $b->isEmpty() ? "E" : "-", "\n";

// --- append / peek / read / cursor --------------------------------------
$b->append("hello world");
echo $b->length(), " [", $b->peek(5), "] [", $b->peek(100), "]\n";
echo "[", $b->read(6), "] len=", $b->length(), " pos=", $b->pos, "\n";
echo "[", $b->peek(5), "] len=", $b->length(), "\n";

// peek does not advance, read does; a short read is short, not an error
echo "[", $b->read(50), "] len=", $b->length(), " ", $b->isEmpty() ? "E" : "-", "\n";
echo "[", $b->read(3), "] [", $b->peek(3), "]\n";

// --- indexOf is RELATIVE to the cursor ----------------------------------
$c = new Buffer\ByteBuffer();
$c->append("GET / HTTP/1.1\r\nHost: a\r\n\r\nBODY");
echo $c->indexOf("\r\n\r\n"), " ", $c->indexOf("Host"), " ", $c->indexOf("nope"), "\n";
$c->skip(16);                       // past the request line
echo $c->indexOf("\r\n\r\n"), " ", $c->indexOf("Host"), "\n";
echo $c->indexOf("\r\n", 4), "\n";  // resume a scan from a relative offset

// --- view / byteAt ------------------------------------------------------
echo "[", $c->view(), "]\n";
echo $c->byteAt(0), " ", $c->byteAt(1), " ", $c->byteAt(9999), " ", $c->byteAt(-1), "\n";
echo "[", $c->view(), "] len=", $c->length(), "\n";   // view consumed nothing

// --- readAll / clear ----------------------------------------------------
echo "[", str_replace("\r\n", "|", $c->readAll()), "] ", $c->isEmpty() ? "E" : "-", "\n";
$c->append("again");
$c->clear();
echo $c->length(), $c->isEmpty() ? "E" : "-", "\n";

// --- cap: a refused append leaves the buffer untouched ------------------
$d = new Buffer\ByteBuffer(10);
echo $d->append("12345") ? "1" : "0";
echo $d->append("67890") ? "1" : "0";
echo $d->append("x") ? "1" : "0";
echo " len=", $d->length(), " [", $d->view(), "]\n";
// consuming frees room again — the cap is on UNCONSUMED bytes
$d->read(10);
echo $d->append("fresh") ? "1" : "0", " len=", $d->length(), "\n";

// --- compaction: the cursor resets once the dead prefix pays for itself --
$e = new Buffer\ByteBuffer();
$e->append(str_repeat("a", 9000));
$e->read(4000);
echo "pos=", $e->pos, " len=", $e->length(), "\n";   // under 8192: cursor kept
$e->read(4200);
echo "pos=", $e->pos, " len=", $e->length(), "\n";   // over 8192: compacted
$e->read(9999);
echo "pos=", $e->pos, " len=", $e->length(), " ", $e->isEmpty() ? "E" : "-", "\n";

// --- binary safety: NUL bytes survive every path ------------------------
$f = new Buffer\ByteBuffer();
$f->append("a\0b\0c");
echo $f->length(), " ", $f->byteAt(1), " ", strlen($f->read(3)), " ", strlen($f->readAll()), "\n";

// --- growth stays linear -------------------------------------------------
// 20k appends of 64B into the SAME buffer. This is the shape that was
// quadratic while a string property had no in-place append path: it went
// through a fresh concat per call. If it regresses, this case does not fail —
// it hangs — which is the point of keeping the count high enough to matter.
$g = new Buffer\ByteBuffer();
$chunk = str_repeat("x", 64);
for ($i = 0; $i < 20000; $i++) {
    $g->append($chunk);
}
echo $g->length(), "\n";

echo "done\n";
