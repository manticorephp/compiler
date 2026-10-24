<?php

// Buffer\ — a byte buffer with a read cursor, and the reader/writer pair over it.
// DEMAND-GATED (Main.php): only a program that mentions `Buffer\` carries it.
// `Http\` implies it — the request parser is written on ByteBuffer.
//
// The shape is the one `\Resource::$rbuf`/`$rpos` already proves in the stream
// layer: bytes in a plain string, a cursor for what has been consumed, and
// threshold compaction. Both halves are load-bearing.
//
//   - The bytes are a `public string` appended with `.=`, which is the in-place
//     amortized `__mir_str_append` path. A buffer that concatenated afresh per
//     append would be quadratic, which is the whole cost this class exists to
//     avoid.
//   - The cursor is not an optimisation either: without it every consume is a
//     `substr($buf, $n)` — a full copy of the remainder — so reading one response
//     in chunks would be quadratic from the other end.
//
// Sentinels, never unions: `indexOf` answers -1 and `byteAt` answers -1 rather
// than `false`. A `int|false` return is a CELL at every call site, and the HTTP
// parser compares these against arithmetic on every request.

namespace Buffer {

/**
 * A growable byte string with a read cursor.
 *
 * Append bytes at the end with append(); consume them from the front with
 * read(), skip() and friends. Offsets and lengths are in bytes and always
 * relative to the cursor (the first unconsumed byte). The buffer never throws:
 * a read past the end is short, and a lookup that finds nothing answers -1.
 * It is built for incremental parsers, where a partial message is left in place
 * and finished when more bytes arrive.
 *
 * ```php
 * $b = new Buffer\ByteBuffer();
 * $b->append("line one\r\nline two");
 * $end = $b->indexOf("\r\n");
 * $line = $b->read($end);
 * $b->skip(2);
 * ```
 *
 * This is not `Manticore\Ds\ByteBuffer`, which is a fixed-size native buffer.
 *
 * @see Reader
 * @see Writer
 * @example doc_buffer_bytebuffer
 */
final class ByteBuffer
{
    /**
     * The raw bytes, including the part already consumed. Normally read it
     * through view() or peek() instead; this is the storage the cursor
     * {@see $pos} indexes into.
     */
    // Raw bytes, consumed prefix included. PUBLIC and a plain `string` on
    // purpose: `$b->buf .= $s` must reach the in-place append path, and a
    // setter would put a second reference on the buffer and force every append
    // to copy.
    public string $buf = '';

    /**
     * Offset into {@see $buf} of the first unconsumed byte. Everything before
     * it has been consumed and is dropped lazily by compact().
     */
    // Bytes of {@see $buf} already consumed. Everything before it is dead but
    // not yet copied out — {@see compact} does that, and only when it pays.
    public int $pos = 0;

    /**
     * Ceiling on the number of unconsumed bytes; 0 (the default) means no
     * limit. append() refuses a write that would cross it.
     */
    // Ceiling on UNCONSUMED bytes; 0 means unbounded. {@see append} refuses to
    // cross it, which is how a server bounds a client that never stops
    // sending: the refusal is a 431/413, not an allocation.
    public int $cap = 0;

    // Compact once the dead prefix reaches this, the threshold the stream
    // layer's `__mc_buf_compact` already uses. Compacting eagerly would copy
    // the live remainder on every read; never compacting would hold the whole
    // connection's history.
    private const COMPACT_AT = 8192;

    /**
     * Create an empty buffer.
     *
     * @param int $cap  limit on unconsumed bytes, or 0 for no limit
     */
    public function __construct(int $cap = 0)
    {
        $this->cap = $cap;
    }

    /**
     * The number of unconsumed bytes.
     *
     * @return int  the byte count
     */
    public function length(): int
    {
        return \strlen($this->buf) - $this->pos;
    }

    /**
     * Whether there are no unconsumed bytes.
     *
     * @return bool  true when length() is 0
     */
    public function isEmpty(): bool
    {
        return \strlen($this->buf) <= $this->pos;
    }

    /**
     * Append bytes at the end.
     *
     * When the write would push the number of unconsumed bytes past {@see $cap},
     * nothing is appended and the answer is false; the caller decides what a
     * full buffer means (a server answers 431 or 413, for instance).
     *
     * @param string $s  the bytes to add
     * @return bool  true when appended, false when refused for exceeding the cap
     */
    // Append bytes. Answers false when the write would push the UNCONSUMED size
    // past {@see $cap}, in which case nothing is appended — the caller decides
    // whether that is a 431 (head too large) or a 413 (body too large).
    public function append(string $s): bool
    {
        if ($this->cap > 0 && $this->length() + \strlen($s) > $this->cap) {
            return false;
        }
        $this->buf .= $s;
        return true;
    }

    /**
     * Look at the next bytes without consuming them.
     *
     * @param int $n  how many bytes; zero or negative gives ''
     * @return string  up to `$n` bytes, fewer when the buffer holds fewer
     */
    // The next $n unconsumed bytes without advancing; short at end of buffer.
    public function peek(int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        return \substr($this->buf, $this->pos, $n);
    }

    /**
     * Consume and return the next bytes.
     *
     * @param int $n  how many bytes; zero or negative gives '' and consumes nothing
     * @return string  up to `$n` bytes, fewer when the buffer holds fewer
     */
    // The next $n bytes, advancing past them; short at end of buffer.
    public function read(int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $s = \substr($this->buf, $this->pos, $n);
        $this->pos += \strlen($s);
        $this->compact();
        return $s;
    }

    /**
     * Consume and return everything unconsumed, leaving the buffer empty.
     *
     * @return string  all unconsumed bytes, possibly ''
     */
    // Everything unconsumed, leaving the buffer empty.
    public function readAll(): string
    {
        $s = \substr($this->buf, $this->pos);
        $this->clear();
        return $s;
    }

    /**
     * Consume bytes without returning them. Skipping more than the buffer
     * holds stops at the end; zero or negative does nothing.
     *
     * @param int $n  how many bytes to drop
     */
    // Advance past $n bytes without copying them out.
    public function skip(int $n): void
    {
        if ($n <= 0) {
            return;
        }
        $this->pos += $n;
        $len = \strlen($this->buf);
        if ($this->pos > $len) {
            $this->pos = $len;
        }
        $this->compact();
    }

    /**
     * Find a byte string in the unconsumed bytes.
     *
     * Both the answer and `$from` are relative to the cursor, so a parser that
     * has already scanned a prefix can resume after it instead of rescanning.
     *
     * @param string $needle  the bytes to look for
     * @param int $from  where to start, relative to the cursor; negative counts as 0
     * @return int  offset of the first match relative to the cursor, or -1 when there is none
     */
    // Offset of $needle in the unconsumed bytes, RELATIVE to the cursor, or -1.
    //
    // $from is relative too, so a parser that has already scanned a prefix can
    // resume from it instead of rescanning — which is what keeps the head search
    // linear across the reads a head arrives in.
    public function indexOf(string $needle, int $from = 0): int
    {
        if ($from < 0) {
            $from = 0;
        }
        $p = \strpos($this->buf, $needle, $this->pos + $from);
        if ($p === false) {
            return -1;
        }
        return $p - $this->pos;
    }

    /**
     * The value of one unconsumed byte.
     *
     * @param int $i  offset relative to the cursor
     * @return int  the byte, 0 to 255, or -1 when `$i` is negative or past the end
     */
    // The unconsumed byte at relative offset $i, or -1 when out of range.
    public function byteAt(int $i): int
    {
        if ($i < 0) {
            return -1;
        }
        $abs = $this->pos + $i;
        if ($abs >= \strlen($this->buf)) {
            return -1;
        }
        return \ord($this->buf[$abs]);
    }

    /**
     * All unconsumed bytes as a string, without consuming them.
     *
     * Scan it, then tell the buffer how much to consume with skip() or read();
     * a partial message costs no copy that way.
     *
     * @return string  the unconsumed bytes
     */
    // The unconsumed bytes, without advancing and without resetting.
    //
    // The parser's window: it scans this and then tells the buffer how much to
    // consume, so a partial frame costs no copy at all.
    public function view(): string
    {
        if ($this->pos === 0) {
            return $this->buf;
        }
        return \substr($this->buf, $this->pos);
    }

    /**
     * Release memory held by already-consumed bytes.
     *
     * read() and skip() call this for you. A fully consumed buffer is reset to
     * empty; otherwise the consumed prefix is dropped once it reaches 8192
     * bytes. Calling it by hand is never required.
     */
    // Drop the consumed prefix once it is big enough to be worth the copy.
    //
    // A fully-drained buffer resets to empty, which also drops the accumulated
    // capacity — the common case between two keep-alive requests.
    public function compact(): void
    {
        if ($this->pos === 0) {
            return;
        }
        if ($this->pos >= \strlen($this->buf)) {
            $this->buf = '';
            $this->pos = 0;
            return;
        }
        if ($this->pos >= self::COMPACT_AT) {
            $this->buf = \substr($this->buf, $this->pos);
            $this->pos = 0;
        }
    }

    /**
     * Discard all bytes, consumed or not, and reset the cursor.
     */
    public function clear(): void
    {
        $this->buf = '';
        $this->pos = 0;
    }
}


/**
 * A bounded, buffered read from a stream.
 *
 * Reads go through a {@see ByteBuffer} that you supply and may share. Bytes
 * pulled from the stream beyond what a read asked for stay in that buffer, so
 * the next reader of the same buffer, for example the next request on a
 * keep-alive connection, finds them there.
 *
 * The limit given to the constructor is a byte budget. With a limit, the
 * Reader reports end of input once the budget is spent, whatever the stream
 * does afterwards; with the default of -1 it reads until the peer closes. A
 * read that finds nothing returns `''`: that means end of input, not an error,
 * and a short read only means that is what had arrived.
 *
 * ```php
 * $buf = new Buffer\ByteBuffer();
 * $r = new Buffer\Reader($stream, $buf, 10);
 * $body = $r->readAll(1024);
 * ```
 *
 * @see ByteBuffer
 * @see Writer
 * @example doc_buffer_reader_writer
 */
// A bounded read over a stream, buffered through a {@see ByteBuffer}.
//
// The buffer is SHARED with whoever created it — a keep-alive connection reads
// one request body through a Reader and the next request's head is already in
// that same buffer, so the extra bytes a read pulled in are not lost. That is
// the whole reason the buffer is a constructor argument rather than a private
// field.
//
// A limit of -1 reads until the peer closes; any other value is a byte budget
// ({@see remaining}) and the Reader reports EOF once it is spent, whatever the
// socket does afterwards.
//
// Deliberately knows nothing about `Transfer-Encoding`. Chunked framing is an
// HTTP concern and `Buffer\` is parsed BEFORE `Http\` — putting it here would
// invert the dependency. `Http\` composes this instead.
final class Reader
{
    /** How much to ask the stream for when the buffer runs dry. Larger than any
     *  single read the caller is likely to make, on purpose: over-reading is
     *  free (the surplus stays in the shared buffer) and it is one syscall
     *  instead of several. */
    private const PULL = 8192;

    private \Resource $src;
    private ByteBuffer $buf;
    private int $limit;
    private int $consumed = 0;
    private bool $closed = false;

    /**
     * True once a read could not complete because a cap was hit: readAll()
     * refused a body bigger than its cap, or the shared buffer was full. It is a
     * flag and not an exception so a server can choose between answering 413
     * and carrying on.
     */
    // Set once {@see readAll} refused a body for exceeding its cap. Public
    // rather than thrown: the caller is a server choosing between 413 and 200,
    // and an exception is the wrong shape for a routine client mistake.
    public bool $over = false;

    /**
     * Create a reader.
     *
     * @param \Resource $src  the stream to read from
     * @param ByteBuffer $buf  buffer to read through; pass the same one to successive readers of one stream, so surplus bytes are not lost
     * @param int $limit  byte budget, or -1 to read until the peer closes
     */
    public function __construct(\Resource $src, ByteBuffer $buf, int $limit = -1)
    {
        $this->src = $src;
        $this->buf = $buf;
        $this->limit = $limit;
    }

    /**
     * The unspent part of the byte budget.
     *
     * @return int  bytes left, or -1 when the reader has no limit
     */
    // Bytes still owed by the limit, or -1 when reading until EOF.
    public function remaining(): int
    {
        if ($this->limit < 0) {
            return -1;
        }
        $left = $this->limit - $this->consumed;
        return $left > 0 ? $left : 0;
    }

    /**
     * Whether nothing more will come.
     *
     * @return bool  true when the budget is spent, or the peer has closed and the buffer is drained, or the shared buffer overflowed and is drained
     */
    // Whether nothing more will come: the budget is spent, the peer has closed,
    // or the shared buffer is full and cannot take another byte.
    //
    // The overflow arm keeps `eof()` and `read()` telling the same story — a
    // reader that answers `''` forever while claiming not to be at EOF is how a
    // drain loop turns into a spin.
    public function eof(): bool
    {
        if ($this->limit >= 0 && $this->consumed >= $this->limit) {
            return true;
        }
        if ($this->over && $this->buf->isEmpty()) {
            return true;
        }
        return $this->closed && $this->buf->isEmpty();
    }

    /**
     * The total number of bytes returned by read() so far.
     *
     * @return int  the byte count
     */
    public function bytesRead(): int
    {
        return $this->consumed;
    }

    /**
     * Read up to a number of bytes, waiting for the stream when the buffer is
     * empty.
     *
     * @param int $max  most bytes to return; never more than the budget has left
     * @return string  the bytes; '' means end of input (budget spent or peer closed), not an error. A short result is ordinary and means only that this is what had arrived.
     */
    // Up to $max bytes. `''` means exhausted — budget spent or peer closed —
    // and is not an error; a SHORT read is ordinary and means only that this is
    // what had arrived.
    public function read(int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        $want = $max;
        if ($this->limit >= 0) {
            $left = $this->limit - $this->consumed;
            if ($left <= 0) {
                return '';
            }
            if ($want > $left) {
                $want = $left;
            }
        }
        if ($this->buf->length() < $want && !$this->closed) {
            $this->pull($want);
        }
        $s = $this->buf->read($want);
        $this->consumed += \strlen($s);
        return $s;
    }

    /**
     * Read the rest of the input as one string, refusing to collect more than a
     * cap.
     *
     * On overflow it returns '' and sets {@see $over}, instead of throwing.
     *
     * @param int $cap  most bytes to accept in total
     * @return string  everything up to end of input, or '' when it exceeded `$cap`
     */
    // The rest of the body as one string, refusing to buffer more than $cap.
    //
    // Answers `''` on overflow with {@see overflowed} set, rather than throwing:
    // the caller is a server deciding between a 413 and a 200, and an exception
    // on a routine client mistake is the wrong shape for that.
    public function readAll(int $cap): string
    {
        $out = '';
        while (true) {
            $s = $this->read(self::PULL);
            if ($s === '') {
                return $out;
            }
            if (\strlen($out) + \strlen($s) > $cap) {
                $this->over = true;
                return '';
            }
            $out .= $s;
        }
    }

    /**
     * Read to the end of the input and throw the bytes away.
     *
     * Use it to skip an unwanted body so the stream is positioned at whatever
     * follows.
     *
     * @return int  how many bytes were dropped
     */
    // Consume and drop whatever is left, answering how many bytes went.
    //
    // Not decoration: a handler that ignored the request body leaves it on the
    // wire, and the connection cannot be reused for the next keep-alive request
    // until it is off. The Server calls this before deciding to keep going.
    public function discard(): int
    {
        $gone = 0;
        while (true) {
            $s = $this->read(self::PULL);
            if ($s === '') {
                return $gone;
            }
            $gone += \strlen($s);
        }
    }

    /**
     * One refill. Pulls at least $want but asks for {@see PULL}: the surplus
     * belongs to the shared buffer, where the next request's head is exactly
     * what it turns out to be.
     *
     * ⚠ The ask is clamped to the buffer's REMAINING CAPACITY, and that is not
     * tidiness. `ByteBuffer::append` refuses a write that would breach the cap,
     * and `fread` has already taken those bytes off the socket — so appending
     * blindly and ignoring the answer loses wire data silently, which on a
     * shared (capped) buffer is a corrupted next request rather than an error
     * anyone would see. With no room at all the read is an overflow, not an EOF:
     * reporting `closed` there would tell the caller the peer hung up.
     */
    private function pull(int $want): void
    {
        $ask = $want > self::PULL ? $want : self::PULL;
        $cap = $this->buf->cap;
        if ($cap > 0) {
            $room = $cap - $this->buf->length();
            if ($room <= 0) {
                $this->over = true;
                return;
            }
            if ($ask > $room) {
                $ask = $room;
            }
        }
        $s = \fread($this->src, $ask);
        if ($s === '') {
            $this->closed = true;
            return;
        }
        $this->buf->append($s);
    }
}

/**
 * Buffered writes to a stream, with a vectored form.
 *
 * write() queues bytes and sends them once the queue reaches a threshold, so
 * output assembled from many small pieces costs a few system calls instead of
 * one per piece. Call flush() at the end: bytes still queued are not written
 * for you.
 *
 * ```php
 * $w = new Buffer\Writer($stream);
 * $w->write("HTTP/1.1 200 OK\r\n\r\n");
 * $w->write($body);
 * $w->flush();
 * ```
 *
 * @see Reader
 * @see ByteBuffer
 * @example doc_buffer_reader_writer
 */
// Buffered writes to a stream, with a vectored form.
//
// `write()` accumulates and flushes on a threshold, so a response assembled
// from many small pieces costs a few `write(2)` calls instead of one per piece.
// The accumulation is `$this->pending .= $s`, which is the in-place amortized
// append — a buffered writer that concatenated afresh per write would be
// quadratic, and would lose to writing straight to the socket.
final class Writer
{
    private \Resource $dst;
    private string $pending = '';
    private int $written = 0;
    private int $flushAt;

    /**
     * Create a writer.
     *
     * @param \Resource $dst  the stream to write to
     * @param int $flushAt  queue size in bytes at which write() sends automatically (default 65536)
     */
    public function __construct(\Resource $dst, int $flushAt = 65536)
    {
        $this->dst = $dst;
        $this->flushAt = $flushAt;
    }

    /**
     * Queue bytes, sending the whole queue once it reaches the flush threshold.
     * An empty string does nothing.
     *
     * @param string $s  the bytes to write
     */
    // Queue bytes, flushing once the queue reaches the threshold.
    public function write(string $s): void
    {
        if ($s === '') {
            return;
        }
        $this->pending .= $s;
        if (\strlen($this->pending) >= $this->flushAt) {
            $this->flush();
        }
    }

    /**
     * Send several strings in one vectored write.
     *
     * Anything already queued is flushed first. The pieces go to the stream
     * without being joined in userspace, which keeps a head-plus-body
     * response to a single system call.
     *
     * @param array<int,string> $parts  the strings to write, in order
     * @return int  the number of bytes the stream accepted
     */
    // Write $parts as ONE `writev(2)`, after flushing anything queued.
    //
    // The array form of `fwrite` is a Manticore superset: it hands the vector
    // to the kernel instead of concatenating in userspace, which is what keeps
    // a head-plus-body response to a single syscall.
    public function writev(array<int, string> $parts): int
    {
        $this->flush();
        $n = \fwrite($this->dst, $parts);
        $this->written += $n;
        return $n;
    }

    /**
     * Send everything queued to the stream. Does nothing when the queue is empty.
     */
    // Push whatever is queued to the stream.
    public function flush(): void
    {
        if ($this->pending === '') {
            return;
        }
        // As a one-part vector: the literal hands `fwrite` an OWNED copy of
        // the reference, and its array arm copies again before it can park,
        // so the slot below may release the old buffer while a write from
        // another task is still parked on it. Same bytes on the wire.
        $n = \fwrite($this->dst, [$this->pending]);
        $this->written += $n;
        $this->pending = '';
    }

    /**
     * The number of bytes handed to the stream so far. Bytes still queued are
     * not counted.
     *
     * @return int  the byte count
     */
    // Bytes handed to the stream so far, queued bytes NOT counted.
    public function bytesWritten(): int
    {
        return $this->written;
    }

    /**
     * The number of bytes queued and not yet sent.
     *
     * @return int  the byte count
     */
    // Bytes queued but not yet written.
    public function pending(): int
    {
        return \strlen($this->pending);
    }
}

}
