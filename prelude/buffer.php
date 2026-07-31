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

final class ByteBuffer
{
    /** Raw bytes, consumed prefix included. PUBLIC and a plain `string` on
     *  purpose: `$b->buf .= $s` must reach the in-place append path, and a
     *  setter would put a second reference on the buffer and force every append
     *  to copy. */
    public string $buf = '';

    /** Bytes of {@see $buf} already consumed. Everything before it is dead but
     *  not yet copied out — {@see compact} does that, and only when it pays. */
    public int $pos = 0;

    /** Ceiling on UNCONSUMED bytes; 0 means unbounded. {@see append} refuses to
     *  cross it, which is how a server bounds a client that never stops
     *  sending: the refusal is a 431/413, not an allocation. */
    public int $cap = 0;

    /** Compact once the dead prefix reaches this, the threshold the stream
     *  layer's `__mc_buf_compact` already uses. Compacting eagerly would copy
     *  the live remainder on every read; never compacting would hold the whole
     *  connection's history. */
    private const COMPACT_AT = 8192;

    public function __construct(int $cap = 0)
    {
        $this->cap = $cap;
    }

    /** Unconsumed byte count. */
    public function length(): int
    {
        return \strlen($this->buf) - $this->pos;
    }

    public function isEmpty(): bool
    {
        return \strlen($this->buf) <= $this->pos;
    }

    /**
     * Append bytes. Answers false when the write would push the UNCONSUMED size
     * past {@see $cap}, in which case nothing is appended — the caller decides
     * whether that is a 431 (head too large) or a 413 (body too large).
     */
    public function append(string $s): bool
    {
        if ($this->cap > 0 && $this->length() + \strlen($s) > $this->cap) {
            return false;
        }
        $this->buf .= $s;
        return true;
    }

    /** The next $n unconsumed bytes without advancing; short at end of buffer. */
    public function peek(int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        return \substr($this->buf, $this->pos, $n);
    }

    /** The next $n bytes, advancing past them; short at end of buffer. */
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

    /** Everything unconsumed, leaving the buffer empty. */
    public function readAll(): string
    {
        $s = \substr($this->buf, $this->pos);
        $this->clear();
        return $s;
    }

    /** Advance past $n bytes without copying them out. */
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
     * Offset of $needle in the unconsumed bytes, RELATIVE to the cursor, or -1.
     *
     * $from is relative too, so a parser that has already scanned a prefix can
     * resume from it instead of rescanning — which is what keeps the head search
     * linear across the reads a head arrives in.
     */
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

    /** The unconsumed byte at relative offset $i, or -1 when out of range. */
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
     * The unconsumed bytes, without advancing and without resetting.
     *
     * The parser's window: it scans this and then tells the buffer how much to
     * consume, so a partial frame costs no copy at all.
     */
    public function view(): string
    {
        if ($this->pos === 0) {
            return $this->buf;
        }
        return \substr($this->buf, $this->pos);
    }

    /**
     * Drop the consumed prefix once it is big enough to be worth the copy.
     *
     * A fully-drained buffer resets to empty, which also drops the accumulated
     * capacity — the common case between two keep-alive requests.
     */
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

    public function clear(): void
    {
        $this->buf = '';
        $this->pos = 0;
    }
}


/**
 * A bounded read over a stream, buffered through a {@see ByteBuffer}.
 *
 * The buffer is SHARED with whoever created it — a keep-alive connection reads
 * one request body through a Reader and the next request's head is already in
 * that same buffer, so the extra bytes a read pulled in are not lost. That is
 * the whole reason the buffer is a constructor argument rather than a private
 * field.
 *
 * A limit of -1 reads until the peer closes; any other value is a byte budget
 * ({@see remaining}) and the Reader reports EOF once it is spent, whatever the
 * socket does afterwards.
 *
 * Deliberately knows nothing about `Transfer-Encoding`. Chunked framing is an
 * HTTP concern and `Buffer\` is parsed BEFORE `Http\` — putting it here would
 * invert the dependency. `Http\` composes this instead.
 */
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

    /** Set once {@see readAll} refused a body for exceeding its cap. Public
     *  rather than thrown: the caller is a server choosing between 413 and 200,
     *  and an exception is the wrong shape for a routine client mistake. */
    public bool $over = false;

    public function __construct(\Resource $src, ByteBuffer $buf, int $limit = -1)
    {
        $this->src = $src;
        $this->buf = $buf;
        $this->limit = $limit;
    }

    /** Bytes still owed by the limit, or -1 when reading until EOF. */
    public function remaining(): int
    {
        if ($this->limit < 0) {
            return -1;
        }
        $left = $this->limit - $this->consumed;
        return $left > 0 ? $left : 0;
    }

    /**
     * Whether nothing more will come: the budget is spent, the peer has closed,
     * or the shared buffer is full and cannot take another byte.
     *
     * The overflow arm keeps `eof()` and `read()` telling the same story — a
     * reader that answers `''` forever while claiming not to be at EOF is how a
     * drain loop turns into a spin.
     */
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

    public function bytesRead(): int
    {
        return $this->consumed;
    }

    /**
     * Up to $max bytes. `''` means exhausted — budget spent or peer closed —
     * and is not an error; a SHORT read is ordinary and means only that this is
     * what had arrived.
     */
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
     * The rest of the body as one string, refusing to buffer more than $cap.
     *
     * Answers `''` on overflow with {@see overflowed} set, rather than throwing:
     * the caller is a server deciding between a 413 and a 200, and an exception
     * on a routine client mistake is the wrong shape for that.
     */
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
     * Consume and drop whatever is left, answering how many bytes went.
     *
     * Not decoration: a handler that ignored the request body leaves it on the
     * wire, and the connection cannot be reused for the next keep-alive request
     * until it is off. The Server calls this before deciding to keep going.
     */
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
 * `write()` accumulates and flushes on a threshold, so a response assembled
 * from many small pieces costs a few `write(2)` calls instead of one per piece.
 * The accumulation is `$this->pending .= $s`, which is the in-place amortized
 * append — a buffered writer that concatenated afresh per write would be
 * quadratic, and would lose to writing straight to the socket.
 */
final class Writer
{
    private \Resource $dst;
    private string $pending = '';
    private int $written = 0;
    private int $flushAt;

    public function __construct(\Resource $dst, int $flushAt = 65536)
    {
        $this->dst = $dst;
        $this->flushAt = $flushAt;
    }

    /** Queue bytes, flushing once the queue reaches the threshold. */
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
     * Write $parts as ONE `writev(2)`, after flushing anything queued.
     *
     * The array form of `fwrite` is a Manticore superset: it hands the vector
     * to the kernel instead of concatenating in userspace, which is what keeps
     * a head-plus-body response to a single syscall.
     *
     * @param array<int,string> $parts
     */
    public function writev(array<int, string> $parts): int
    {
        $this->flush();
        $n = \fwrite($this->dst, $parts);
        $this->written += $n;
        return $n;
    }

    /** Push whatever is queued to the stream. */
    public function flush(): void
    {
        if ($this->pending === '') {
            return;
        }
        $n = \fwrite($this->dst, $this->pending);
        $this->written += $n;
        $this->pending = '';
    }

    /** Bytes handed to the stream so far, queued bytes NOT counted. */
    public function bytesWritten(): int
    {
        return $this->written;
    }

    /** Bytes queued but not yet written. */
    public function pending(): int
    {
        return \strlen($this->pending);
    }
}

}
