<?php

// ext/zlib — DEFLATE (RFC 1951) and its two containers, zlib (RFC 1950) and
// gzip (RFC 1952), in pure PHP. No libz, no FFI: a compiled binary that reads a
// gzip-encoded HTTP response should not need a shared object on the host to do
// it, and DEFLATE is small enough to own.
//
// ⚠ THE COMPRESSED BYTES ARE NOT php's, AND CANNOT BE. DEFLATE is a FORMAT, not
// a function: zlib's exact output is the product of its lazy-match heuristics
// and its own Huffman tree construction, and two conforming encoders disagree
// on every input while both being right. The contract that IS held, and is
// tested both ways in stdlib_zlib: php inflates everything this produces, and
// this inflates everything php produces, at every level. Anything that compares
// gzdeflate() output byte for byte against the interpreter is asserting
// something php does not promise either.
//
// The encoder emits FIXED Huffman blocks (RFC 1951 §3.2.6). That is complete and
// correct; it is not as small as zlib, which builds a per-block tree — roughly a
// third larger on text, several times larger on a long run. Dynamic Huffman
// ENCODING is the named follow-up; dynamic DECODING is already here, because a
// decoder has no choice about it.

// ── the bit reader: one string, one bit cursor ──────────────────────────
// DEFLATE packs everything but Huffman codes least-significant-bit first, so
// the reader keeps a small buffer of already-read bits and refills it a byte at
// a time. State is a 4-slot array [input, bytePos, bitBuf, bitCnt] passed by
// reference — one allocation for the whole stream.

function __mc_zl_bits(array &$st, int $need): int
{
    $buf = $st[2];
    $cnt = $st[3];
    while ($cnt < $need) {
        if ($st[1] >= \strlen($st[0])) { return -1; }
        $buf = $buf | (\ord($st[0][$st[1]]) << $cnt);
        $st[1] = $st[1] + 1;
        $cnt = $cnt + 8;
    }
    $st[2] = $buf >> $need;
    $st[3] = $cnt - $need;
    return $buf & ((1 << $need) - 1);
}

// A canonical Huffman table is two flat arrays: how many codes of each bit
// length, and the symbols in canonical order. Decoding walks the lengths one
// bit at a time (puff.c's method) — no lookup table to build, and the code
// being assembled is compared against the first code of each length.
function __mc_zl_construct(array $lengths, int $n): array
{
    $count = [];
    for ($i = 0; $i <= 15; $i++) { $count[$i] = 0; }
    for ($s = 0; $s < $n; $s++) { $count[$lengths[$s]] = $count[$lengths[$s]] + 1; }
    $offs = [];
    $offs[1] = 0;
    for ($len = 1; $len < 15; $len++) { $offs[$len + 1] = $offs[$len] + $count[$len]; }
    $symbol = [];
    for ($s = 0; $s < $n; $s++) {
        if ($lengths[$s] !== 0) {
            $symbol[$offs[$lengths[$s]]] = $s;
            $offs[$lengths[$s]] = $offs[$lengths[$s]] + 1;
        }
    }
    return [$count, $symbol];
}

/**
 * A symbol; -1 when the input runs out mid-code, -2 when no code matches — an
 * incomplete table, judged as soon as no longer code is left, as zlib does.
 */
function __mc_zl_decode(array &$st, array $h): int
{
    $code = 0;
    $first = 0;
    $index = 0;
    $count = $h[0];
    $symbol = $h[1];
    $total = \count($symbol);
    for ($len = 1; $len <= 15; $len++) {
        $b = \__mc_zl_bits($st, 1);
        if ($b < 0) { return -1; }
        $code = $code | $b;
        $c = $count[$len];
        if ($code - $first < $c) { return $symbol[$index + ($code - $first)]; }
        $index = $index + $c;
        if ($index >= $total) { return -2; }
        $first = ($first + $c) << 1;
        $code = $code << 1;
    }
    return -2;
}

function __mc_zl_fixed_tables(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $ll = [];
    for ($s = 0; $s < 144; $s++) { $ll[$s] = 8; }
    for ($s = 144; $s < 256; $s++) { $ll[$s] = 9; }
    for ($s = 256; $s < 280; $s++) { $ll[$s] = 7; }
    for ($s = 280; $s < 288; $s++) { $ll[$s] = 8; }
    $dl = [];
    for ($s = 0; $s < 30; $s++) { $dl[$s] = 5; }
    $cache = [\__mc_zl_construct($ll, 288), \__mc_zl_construct($dl, 30)];
    return $cache;
}

/**
 * An incremental inflate stream (php's `inflate_init` result). Decoding resumes
 * at the last complete unit — a symbol, a slice of a stored block, a block
 * header, a container field — so a stream fed in any chunking answers what zlib
 * answers: every symbol whose bits have all arrived. $in holds the bytes of the
 * unit still incomplete (zlib has already counted them as read), $bitBuf and
 * $bitCnt the unread bits of the byte before them, $hist the last 2^window
 * bytes of output: as far as a back-reference may reach.
 *
 * $phase: 0 container header, 1 block boundary, 2 inside a stored block, 3
 * inside a Huffman block, 4 trailer, 5 stream end (reset by the next call), 6
 * corrupt, 7 waiting for a dictionary it will never get.
 */
final class InflateContext
{
    public int $encoding = -15;
    public int $window = 15;
    public string $dict = '';
    public int $status = 0;
    public int $readLen = 0;
    public int $phase = 1;
    public string $in = '';
    public int $bitBuf = 0;
    public int $bitCnt = 0;
    public string $hist = '';
    public string $out = '';
    public int $check = 0;
    public int $total = 0;
    public bool $last = false;
    public int $stored = 0;
    /** @var array<int,int> */
    public array $lcount = [];
    /** @var array<int,int> */
    public array $lsym = [];
    /** @var array<int,int> */
    public array $dcount = [];
    /** @var array<int,int> */
    public array $dsym = [];
}

/** A fresh stream on the same settings, as zlib's inflateReset leaves it: a raw dictionary served the first stream only. */
function __mc_zl_inflate_reset(InflateContext $c): void
{
    $c->phase = $c->encoding === -15 ? 1 : 0;
    $c->status = 0;
    $c->readLen = 0;
    $c->in = '';
    $c->bitBuf = 0;
    $c->bitCnt = 0;
    $c->hist = '';
    $c->out = '';
    $c->check = $c->encoding === 15 ? 1 : 0;
    $c->total = 0;
    $c->last = false;
    $c->stored = 0;
    $c->lcount = [];
    $c->lsym = [];
    $c->dcount = [];
    $c->dsym = [];
}

/**
 * zlib's table check (inflate_table): an over-subscribed code is corrupt, and
 * so is an incomplete one, except a lone one-bit code of the literal or
 * distance alphabet. No codes at all is accepted: decoding one is the error.
 *
 * @param array<int,int> $count
 */
function __mc_zl_table_ok(array $count, bool $codeLengths): bool
{
    $max = 15;
    while ($max > 0 && $count[$max] === 0) { $max = $max - 1; }
    if ($max === 0) { return true; }
    $left = 1;
    for ($len = 1; $len <= 15; $len++) {
        $left = ($left << 1) - $count[$len];
        if ($left < 0) { return false; }
    }

    return $left === 0 || (!$codeLengths && $max === 1);
}

/**
 * The container header at the start of $in: its length once all of it is in, 0
 * while it is still arriving, or minus the bytes zlib had read when it judged
 * the header bad — the magic, the method and the flags are judged as soon as
 * their own bytes are in.
 */
function __mc_zl_inflate_head(InflateContext $c, string $in): int
{
    $n = \strlen($in);
    if ($n < 2) { return 0; }
    if ($c->encoding === 15) {
        $cmf = \ord($in[0]);
        $flg = \ord($in[1]);
        if ((($cmf << 8) | $flg) % 31 !== 0 || ($cmf & 0x0F) !== 8 || ($cmf >> 4) + 8 > $c->window) { return -2; }
        if (($flg & 0x20) === 0) { return 2; }

        return $n < 6 ? 0 : 6;
    }
    if (\ord($in[0]) !== 0x1F || \ord($in[1]) !== 0x8B) { return -2; }
    if ($n < 4) { return 0; }
    $flg = \ord($in[3]);
    if (\ord($in[2]) !== 8 || ($flg & 0xE0) !== 0) { return -4; }
    $p = 10;
    if ($n < $p) { return 0; }
    if (($flg & 0x04) !== 0) {
        if ($n < $p + 2) { return 0; }
        $p = $p + 2 + (\ord($in[$p]) | (\ord($in[$p + 1]) << 8));
        if ($n < $p) { return 0; }
    }
    if (($flg & 0x08) !== 0) {
        while ($p < $n && \ord($in[$p]) !== 0) { $p = $p + 1; }
        if ($p >= $n) { return 0; }
        $p = $p + 1;
    }
    if (($flg & 0x10) !== 0) {
        while ($p < $n && \ord($in[$p]) !== 0) { $p = $p + 1; }
        if ($p >= $n) { return 0; }
        $p = $p + 1;
    }
    if (($flg & 0x02) !== 0) {
        if ($n < $p + 2) { return 0; }
        $hc = \__mc_zl_crc32_update(0, \substr($in, 0, $p)) & 0xFFFF;
        if ($hc !== (\ord($in[$p]) | (\ord($in[$p + 1]) << 8))) { return -($p + 2); }
        $p = $p + 2;
    }

    return $p;
}

/**
 * Decode $data after whatever the last call left incomplete. Answers 1 at the
 * end of the stream, 0 when the input ran out (or a ZLIB_BLOCK call reached a
 * block boundary), 2 when a zlib stream wants a dictionary it was not given or
 * does not match, -3 on corrupt data — and a back-reference further than
 * 2^window, or than the output so far, is corrupt data. $c->out is the output
 * of complete units; $c->readLen follows zlib's total_in.
 *
 * $cap0 is php's first output buffer: ZLIB_BLOCK stops at a block boundary
 * unless that output exactly fills a buffer, when php asks zlib again and the
 * next block runs too. $maxLen caps a one-shot decode (0: no cap).
 */
function __mc_zl_inflate_run(InflateContext $c, string $data, int $flush, int $cap0, int $maxLen): int
{
    $lbase = [3,4,5,6,7,8,9,10,11,13,15,17,19,23,27,31,35,43,51,59,67,83,99,115,131,163,195,227,258];
    $lext  = [0,0,0,0,0,0,0,0,1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5,0];
    $dbase = [1,2,3,4,5,7,9,13,17,25,33,49,65,97,129,193,257,385,513,769,1025,1537,2049,3073,4097,6145,8193,12289,16385,24577];
    $dext  = [0,0,0,0,1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10,11,11,12,12,13,13];
    $order = [16,17,18,0,8,7,9,6,10,5,11,4,12,3,13,2,14,1,15];

    $carry = \strlen($c->in);
    $in = $carry === 0 ? $data : $c->in . $data;
    $n = \strlen($in);
    $st = [$in, 0, $c->bitBuf, $c->bitCnt];
    $wsize = 1 << $c->window;
    $wrap = $c->encoding !== -15;
    // The output is written through string offsets into a buffer that grows by
    // doubling: a per-byte concatenation reallocates, and inflate is all
    // per-byte writes. The history sits in front, so a distance is an offset.
    $olen0 = \strlen($c->hist);
    $cap = $olen0 + 1024;
    $out = $c->hist . \str_repeat("\0", 1024);
    $olen = $olen0;
    $ck = $c->check;
    $ckAt = $olen0;
    $phase = $c->phase;
    $last = $c->last;
    $stored = $c->stored;
    $lh = [$c->lcount, $c->lsym];
    $dh = [$c->dcount, $c->dsym];
    // The last complete unit: where a call that runs out of input resumes.
    $kPos = 0;
    $kBuf = $st[2];
    $kCnt = $st[3];
    $kOut = $olen;
    $kPhase = $phase;
    $kLast = $last;
    $kStored = $stored;
    $lost = 0;
    $dictAt = -1;
    $ret = 0;
    $stop = false;

    while (true) {
        if ($phase === 0) {
            $h = \__mc_zl_inflate_head($c, $in);
            if ($h === 0) { break; }
            if ($h < 0) { $st[1] = -$h; $ret = -3; break; }
            $st[1] = $h;
            if ($c->encoding === 15 && $h === 6) {
                // zlib answers Z_NEED_DICT without counting what this call read.
                $lost = $h > $carry ? $h - $carry : 0;
                $dictAt = $h;
                $d = $c->dict;
                $c->dict = '';
                if ($d === '' || \__mc_zl_adler32_update(1, $d) !== \__mc_zl_rd_be32($in, 2)) { $ret = 2; break; }
                if (\strlen($d) > $wsize) { $d = \substr($d, -$wsize); }
                $olen0 = \strlen($d);
                $cap = $olen0 + 1024;
                $out = $d . \str_repeat("\0", 1024);
                $olen = $olen0;
                $ckAt = $olen0;
            }
            $phase = 1;
            $kPos = $st[1]; $kBuf = 0; $kCnt = 0; $kOut = $olen; $kPhase = 1; $kLast = false; $kStored = 0;
            if ($flush === 5) { $stop = true; break; }
            continue;
        }

        if ($phase === 1) {
            if ($last) {
                $st[2] = 0;
                $st[3] = 0;
                $phase = 4;
                continue;
            }
            $bf = \__mc_zl_bits($st, 1);
            if ($bf < 0) { break; }
            $type = \__mc_zl_bits($st, 2);
            if ($type < 0) { break; }
            $last = $bf === 1;
            if ($type === 0) {
                // A stored block restarts on a byte boundary; LEN and its ones
                // complement follow, and disagreement means the stream is corrupt.
                $st[2] = 0;
                $st[3] = 0;
                if ($st[1] + 4 > $n) { break; }
                $len = \ord($in[$st[1]]) | (\ord($in[$st[1] + 1]) << 8);
                $nlen = \ord($in[$st[1] + 2]) | (\ord($in[$st[1] + 3]) << 8);
                $st[1] = $st[1] + 4;
                if ($len !== ($nlen ^ 0xFFFF)) { $ret = -3; break; }
                $stored = $len;
                $phase = 2;
            } elseif ($type === 1) {
                $t = \__mc_zl_fixed_tables();
                $lh = $t[0];
                $dh = $t[1];
                $phase = 3;
            } elseif ($type === 2) {
                $hlit = \__mc_zl_bits($st, 5);
                if ($hlit < 0) { break; }
                $hdist = \__mc_zl_bits($st, 5);
                if ($hdist < 0) { break; }
                $hclen = \__mc_zl_bits($st, 4);
                if ($hclen < 0) { break; }
                $hlit = $hlit + 257;
                $hdist = $hdist + 1;
                $hclen = $hclen + 4;
                if ($hlit > 286 || $hdist > 30) { $ret = -3; break; }
                $cl = [];
                for ($i = 0; $i < 19; $i++) { $cl[$i] = 0; }
                $short = false;
                for ($i = 0; $i < $hclen; $i++) {
                    $v = \__mc_zl_bits($st, 3);
                    if ($v < 0) { $short = true; break; }
                    $cl[$order[$i]] = $v;
                }
                if ($short) { break; }
                $ch = \__mc_zl_construct($cl, 19);
                if (!\__mc_zl_table_ok($ch[0], true)) { $ret = -3; break; }
                // The literal/length and distance lengths are themselves
                // Huffman-coded, with three repeat symbols: 16 repeats the
                // PREVIOUS length, 17 and 18 run zeros.
                $lens = [];
                $i = 0;
                $want = $hlit + $hdist;
                while ($i < $want) {
                    $sym = \__mc_zl_decode($st, $ch);
                    if ($sym === -1) { $short = true; break; }
                    // Only an EMPTY code-length code gets here (an incomplete
                    // one was refused above), and zlib reads each of its
                    // missing codes as one bit of length 0.
                    if ($sym === -2) { $sym = 0; }
                    if ($sym < 16) {
                        $lens[$i] = $sym;
                        $i = $i + 1;
                        continue;
                    }
                    $rep = 0;
                    if ($sym === 16) {
                        if ($i === 0) { $ret = -3; break; }
                        $rep = $lens[$i - 1];
                        $r = \__mc_zl_bits($st, 2);
                        if ($r < 0) { $short = true; break; }
                        $r = $r + 3;
                    } elseif ($sym === 17) {
                        $r = \__mc_zl_bits($st, 3);
                        if ($r < 0) { $short = true; break; }
                        $r = $r + 3;
                    } else {
                        $r = \__mc_zl_bits($st, 7);
                        if ($r < 0) { $short = true; break; }
                        $r = $r + 11;
                    }
                    if ($i + $r > $want) { $ret = -3; break; }
                    while ($r > 0) { $lens[$i] = $rep; $i = $i + 1; $r = $r - 1; }
                }
                if ($short || $ret < 0) { break; }
                if ($lens[256] === 0) { $ret = -3; break; }
                $llens = [];
                for ($k = 0; $k < $hlit; $k++) { $llens[$k] = $lens[$k]; }
                $dlens = [];
                for ($k = 0; $k < $hdist; $k++) { $dlens[$k] = $lens[$hlit + $k]; }
                $lh = \__mc_zl_construct($llens, $hlit);
                $dh = \__mc_zl_construct($dlens, $hdist);
                if (!\__mc_zl_table_ok($lh[0], false) || !\__mc_zl_table_ok($dh[0], false)) { $ret = -3; break; }
                $phase = 3;
            } else {
                $ret = -3;
                break;
            }
            $kPos = $st[1]; $kBuf = $st[2]; $kCnt = $st[3]; $kOut = $olen;
            $kPhase = $phase; $kLast = $last; $kStored = $stored;
            continue;
        }

        if ($phase === 2) {
            $k = $n - $st[1];
            if ($k > $stored) { $k = $stored; }
            while ($olen + $k > $cap) {
                $out = $out . \str_repeat("\0", $cap);
                $cap = $cap * 2;
            }
            $p = $st[1];
            for ($j = 0; $j < $k; $j++) { $out[$olen + $j] = $in[$p + $j]; }
            $olen = $olen + $k;
            $st[1] = $p + $k;
            $stored = $stored - $k;
            if ($maxLen > 0 && $olen - $olen0 > $maxLen) { $ret = -3; break; }
            $kPos = $st[1]; $kOut = $olen; $kStored = $stored;
            if ($stored > 0) { break; }
        } elseif ($phase === 3) {
            while (true) {
                $sym = \__mc_zl_decode($st, $lh);
                if ($sym === -1) { break 2; }
                if ($sym < 0) { $ret = -3; break 2; }
                if ($sym === 256) { break; }
                if ($sym < 256) {
                    if ($olen >= $cap) {
                        $out = $out . \str_repeat("\0", $cap);
                        $cap = $cap * 2;
                    }
                    $out[$olen] = \chr($sym);
                    $olen = $olen + 1;
                } else {
                    $sym = $sym - 257;
                    if ($sym >= 29) { $ret = -3; break 2; }
                    $e = \__mc_zl_bits($st, $lext[$sym]);
                    if ($e < 0) { break 2; }
                    $len = $lbase[$sym] + $e;
                    $dsym = \__mc_zl_decode($st, $dh);
                    if ($dsym === -1) { break 2; }
                    if ($dsym < 0 || $dsym >= 30) { $ret = -3; break 2; }
                    $e = \__mc_zl_bits($st, $dext[$dsym]);
                    if ($e < 0) { break 2; }
                    $dist = $dbase[$dsym] + $e;
                    if ($dist > $olen || $dist > $wsize) { $ret = -3; break 2; }
                    // An overlapping copy is legal and common (a run is coded as
                    // distance 1): read as we write, byte at a time.
                    while ($olen + $len > $cap) {
                        $out = $out . \str_repeat("\0", $cap);
                        $cap = $cap * 2;
                    }
                    $src = $olen - $dist;
                    for ($k = 0; $k < $len; $k++) {
                        $out[$olen + $k] = $out[$src + $k];
                    }
                    $olen = $olen + $len;
                }
                if ($maxLen > 0 && $olen - $olen0 > $maxLen) { $ret = -3; break 2; }
                $kPos = $st[1]; $kBuf = $st[2]; $kCnt = $st[3]; $kOut = $olen;
            }
        } elseif ($phase === 4) {
            if (!$wrap) { $phase = 5; $ret = 1; break; }
            if ($olen > $ckAt) {
                $slice = \substr($out, $ckAt, $olen - $ckAt);
                $ck = $c->encoding === 15 ? \__mc_zl_adler32_update($ck, $slice) : \__mc_zl_crc32_update($ck, $slice);
                $ckAt = $olen;
            }
            $p = $st[1];
            if ($p + 4 > $n) { break; }
            if ($c->encoding === 15) {
                $st[1] = $p + 4;
                if (\__mc_zl_rd_be32($in, $p) !== $ck) { $ret = -3; break; }
            } else {
                if (\__mc_zl_rd_le32($in, $p) !== $ck) { $st[1] = $p + 4; $ret = -3; break; }
                if ($p + 8 > $n) { break; }
                $st[1] = $p + 8;
                if (\__mc_zl_rd_le32($in, $p + 4) !== (($c->total + $olen - $olen0) & 0xFFFFFFFF)) { $ret = -3; break; }
            }
            $phase = 5;
            $ret = 1;
            break;
        } else {
            $ret = -3;
            break;
        }

        // A block ended: the next unit starts at a block boundary, where
        // ZLIB_BLOCK hands back what it has.
        $phase = 1;
        $kPos = $st[1]; $kBuf = $st[2]; $kCnt = $st[3]; $kOut = $olen;
        $kPhase = 1; $kLast = $last; $kStored = 0;
        if ($flush === 5) {
            $made = $olen - $olen0;
            if ($made < $cap0 || ($made - $cap0) % 8192 !== 0) { $stop = true; break; }
        }
    }

    if ($ret === 2) {
        $c->phase = 7;
        $c->in = '';
        $c->out = '';
        return 2;
    }
    $pos = $ret === 0 && !$stop ? $n : $st[1];
    $c->readLen = $c->readLen + ($pos > $carry ? $pos - $carry : 0) - $lost;
    if ($ret === -3) {
        $c->phase = 6;
        $c->in = '';
        $c->out = '';
        return -3;
    }
    if ($ret === 1) {
        $kOut = $olen;
        $c->phase = 5;
        $c->in = '';
    } else {
        $c->phase = $kPhase;
        $c->last = $kLast;
        $c->stored = $kStored;
        $c->bitBuf = $kBuf;
        $c->bitCnt = $kCnt;
        $c->in = $stop ? '' : \substr($in, $kPos);
        if ($kPhase === 3) {
            $c->lcount = $lh[0];
            $c->lsym = $lh[1];
            $c->dcount = $dh[0];
            $c->dsym = $dh[1];
        }
        if ($wrap && $kOut > $ckAt) {
            $slice = \substr($out, $ckAt, $kOut - $ckAt);
            $ck = $c->encoding === 15 ? \__mc_zl_adler32_update($ck, $slice) : \__mc_zl_crc32_update($ck, $slice);
        }
        $h0 = $kOut > $wsize ? $kOut - $wsize : 0;
        $c->hist = \substr($out, $h0, $kOut - $h0);
    }
    $c->check = $ck;
    $c->total = $c->total + $kOut - $olen0;
    $c->out = \substr($out, $olen0, $kOut - $olen0);
    // php hands zlib the dictionary and calls inflate() again: when that second
    // call reads and writes nothing, zlib answers Z_BUF_ERROR.
    if ($ret === 0 && $dictAt >= 0 && $kOut === $olen0 && ($stop || $n === $dictAt)) { return -5; }

    return $ret;
}

function __mc_zl_inflate_raw(string $data, int $maxLen): string|false
{
    $c = new InflateContext();
    if (\__mc_zl_inflate_run($c, $data, 4, 0, $maxLen) !== 1) { return false; }

    return $c->out;
}

// ── the encoder ─────────────────────────────────────────────────────────
// LZ77 over a hash chain, then fixed Huffman. NOT byte-identical to zlib's
// output and it cannot be: DEFLATE is a FORMAT, not a function, and zlib's
// exact byte stream is the product of its lazy-match heuristics and its own
// tree building. What is guaranteed is the contract that matters — php inflates
// every stream this produces, and this inflates every stream php produces.

/** Reverse the low $len bits of $code: Huffman codes go out most-significant bit first. */
function __mc_zl_rev(int $code, int $len): int
{
    $r = 0;
    for ($i = 0; $i < $len; $i++) {
        $r = ($r << 1) | (($code >> $i) & 1);
    }
    return $r;
}

/** The fixed literal/length alphabet: [code, bitlength] per symbol, php's table 3.2.2. */
function __mc_zl_fixed_lit(int $sym): array
{
    if ($sym < 144) { return [0x30 + $sym, 8]; }
    if ($sym < 256) { return [0x190 + $sym - 144, 9]; }
    if ($sym < 280) { return [$sym - 256, 7]; }
    return [0xC0 + $sym - 280, 8];
}

/**
 * An incremental DEFLATE stream (php's `deflate_init` result). The LZ77 history
 * outlives a call — that is context takeover — so positions are ABSOLUTE: $win
 * holds the last bytes seen and $base is the absolute offset of $win[0]. $head
 * and $prev entries below $base are stale and ignored on read.
 */
final class DeflateContext
{
    public int $encoding = -15;
    public int $level = -1;
    public int $window = 15;
    public int $strategy = 0;
    public int $maxChain = 128;
    public bool $headerDone = false;
    public bool $finished = false;
    public int $check = 0;
    public int $total = 0;
    public string $dict = '';
    public int $dictId = 0;
    public string $win = '';
    public int $base = 0;
    /** @var array<int,int> */
    public array $head = [];
    /** @var array<int,int> */
    public array $prev = [];
    public int $hashed = 0;
    public string $pending = '';
    public int $bb = 0;
    public int $bc = 0;
    public string $out = '';
}

function __mc_zl_put(DeflateContext $c, int $bits, int $n): void
{
    $c->bb = $c->bb | ($bits << $c->bc);
    $c->bc = $c->bc + $n;
    while ($c->bc >= 8) {
        $c->out = $c->out . \chr($c->bb & 0xFF);
        $c->bb = $c->bb >> 8;
        $c->bc = $c->bc - 8;
    }
}

function __mc_zl_align(DeflateContext $c): void
{
    if ($c->bc > 0) { $c->out = $c->out . \chr($c->bb & 0xFF); }
    $c->bb = 0;
    $c->bc = 0;
}

function __mc_zl_chain(DeflateContext $c, string $w, int $i, int $abs): void
{
    $h = ((\ord($w[$i]) << 10) ^ (\ord($w[$i + 1]) << 5) ^ \ord($w[$i + 2])) & 0x7FFF;
    $c->prev[$abs] = $c->head[$h] ?? -1;
    $c->head[$h] = $abs;
}

/**
 * One fixed-Huffman block over $data, matched against everything still in the
 * window. A single call over a whole input is exactly the one-shot encoder's
 * bit sequence. The block's bits stay in the accumulator: the caller decides
 * whether the stream ends, flushes to a byte boundary, or just continues.
 */
function __mc_zl_block(DeflateContext $c, string $data, bool $final): void
{
    $lbase = [3,4,5,6,7,8,9,10,11,13,15,17,19,23,27,31,35,43,51,59,67,83,99,115,131,163,195,227,258];
    $lext  = [0,0,0,0,0,0,0,0,1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5,0];
    $dbase = [1,2,3,4,5,7,9,13,17,25,33,49,65,97,129,193,257,385,513,769,1025,1537,2049,3073,4097,6145,8193,12289,16385,24577];
    $dext  = [0,0,0,0,1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10,11,11,12,12,13,13];

    $start = \strlen($c->win);
    $c->win = $c->win . $data;
    $w = $c->win;
    $n = \strlen($w);
    $base = $c->base;
    $maxChain = $c->maxChain;
    $wbits = $c->window < 9 ? 9 : $c->window;
    $maxDist = $wbits >= 15 ? 32768 : (1 << $wbits);

    // Positions the previous call could not hash (its last two bytes had no
    // successor yet, or a dictionary was just seeded) go into the chains first,
    // oldest first, so a match here can reach them.
    $hp = $c->hashed;
    if ($hp < $base) { $hp = $base; }
    while ($hp < $base + $start && $hp + 2 < $base + $n) {
        \__mc_zl_chain($c, $w, $hp - $base, $hp);
        $hp = $hp + 1;
    }

    $cap = 1024;
    $out = \str_repeat("\0", $cap);
    $olen = 0;
    $bb = $c->bb;
    $bc = $c->bc;

    // BFINAL, then BTYPE=01 (fixed Huffman).
    if ($final) { $bb = $bb | (1 << $bc); }
    $bc = $bc + 1;
    $bb = $bb | (1 << $bc); $bc = $bc + 2;

    $pos = $start;
    while ($pos < $n) {
        $len = 0;
        $dist = 0;
        if ($pos + 2 < $n) {
            $ap = $base + $pos;
            $h = ((\ord($w[$pos]) << 10) ^ (\ord($w[$pos + 1]) << 5) ^ \ord($w[$pos + 2])) & 0x7FFF;
            $cand = $c->head[$h] ?? -1;
            $chain = $maxChain;
            $max = $n - $pos;
            if ($max > 258) { $max = 258; }
            while ($cand >= $base && $chain > 0 && $ap - $cand <= $maxDist) {
                // Only a candidate that beats the best so far is worth measuring
                // past its first bytes, so check the deciding byte first. $len <
                // $max is what keeps that probe inside the string: $pos + $len is
                // one past the current best, and the best can already reach the end.
                $ci = $cand - $base;
                if ($len === 0 || ($len < $max && $w[$ci + $len] === $w[$pos + $len])) {
                    $l = 0;
                    while ($l < $max && $w[$ci + $l] === $w[$pos + $l]) { $l = $l + 1; }
                    if ($l >= 3 && $l > $len) {
                        $len = $l;
                        $dist = $ap - $cand;
                        if ($l === 258) { break; }
                    }
                }
                $cand = $c->prev[$cand] ?? -1;
                $chain = $chain - 1;
            }
            $c->prev[$ap] = $c->head[$h] ?? -1;
            $c->head[$h] = $ap;
        }

        if ($len >= 3) {
            $ls = 28;
            while ($ls > 0 && $lbase[$ls] > $len) { $ls = $ls - 1; }
            $lc = \__mc_zl_fixed_lit(257 + $ls);
            $bb = $bb | (\__mc_zl_rev($lc[0], $lc[1]) << $bc); $bc = $bc + $lc[1];
            while ($bc >= 8) {
                if ($olen >= $cap) { $out = $out . \str_repeat("\0", $cap); $cap = $cap * 2; }
                $out[$olen] = \chr($bb & 0xFF); $olen = $olen + 1; $bb = $bb >> 8; $bc = $bc - 8;
            }
            if ($lext[$ls] > 0) {
                $bb = $bb | (($len - $lbase[$ls]) << $bc); $bc = $bc + $lext[$ls];
            }
            $ds = 29;
            while ($ds > 0 && $dbase[$ds] > $dist) { $ds = $ds - 1; }
            $bb = $bb | (\__mc_zl_rev($ds, 5) << $bc); $bc = $bc + 5;
            if ($dext[$ds] > 0) {
                $bb = $bb | (($dist - $dbase[$ds]) << $bc); $bc = $bc + $dext[$ds];
            }
            // Every position inside the match still belongs in the chain, or the
            // next match cannot see it.
            for ($k = 1; $k < $len; $k++) {
                $p = $pos + $k;
                if ($p + 2 < $n) { \__mc_zl_chain($c, $w, $p, $base + $p); }
            }
            $pos = $pos + $len;
        } else {
            $lc = \__mc_zl_fixed_lit(\ord($w[$pos]));
            $bb = $bb | (\__mc_zl_rev($lc[0], $lc[1]) << $bc); $bc = $bc + $lc[1];
            $pos = $pos + 1;
        }
        while ($bc >= 8) {
            if ($olen >= $cap) { $out = $out . \str_repeat("\0", $cap); $cap = $cap * 2; }
            $out[$olen] = \chr($bb & 0xFF); $olen = $olen + 1; $bb = $bb >> 8; $bc = $bc - 8;
        }
    }

    $ec = \__mc_zl_fixed_lit(256);
    $bb = $bb | (\__mc_zl_rev($ec[0], $ec[1]) << $bc); $bc = $bc + $ec[1];
    while ($bc >= 8) {
        if ($olen >= $cap) { $out = $out . \str_repeat("\0", $cap); $cap = $cap * 2; }
        $out[$olen] = \chr($bb & 0xFF); $olen = $olen + 1; $bb = $bb >> 8; $bc = $bc - 8;
    }
    $c->out = $c->out . \substr($out, 0, $olen);
    $c->bb = $bb;
    $c->bc = $bc;

    $e = $base + $n - 2;
    $c->hashed = $hp > $e ? $hp : $e;
    if (!$final) { \__mc_zl_slide($c); }
}

/** Keep the window at 32 KiB of history once it passes 64: DEFLATE cannot reach further back. */
function __mc_zl_slide(DeflateContext $c): void
{
    $n = \strlen($c->win);
    if ($n <= 65536) { return; }
    $drop = $n - 32768;
    $nb = $c->base + $drop;
    for ($p = $c->base; $p < $nb; $p++) { unset($c->prev[$p]); }
    $c->win = \substr($c->win, $drop);
    $c->base = $nb;
}

/** $data as BTYPE=00 blocks of at most 65535 bytes; only the last may carry BFINAL. */
function __mc_zl_stored_block(DeflateContext $c, string $data, bool $final): void
{
    $n = \strlen($data);
    $pos = 0;
    do {
        $len = $n - $pos;
        if ($len > 65535) { $len = 65535; }
        \__mc_zl_put($c, ($final && $pos + $len >= $n) ? 1 : 0, 3);
        \__mc_zl_align($c);
        $c->out = $c->out . \chr($len & 0xFF) . \chr(($len >> 8) & 0xFF)
            . \chr((~$len) & 0xFF) . \chr(((~$len) >> 8) & 0xFF) . \substr($data, $pos, $len);
        $pos = $pos + $len;
    } while ($pos < $n);
}

/**
 * The container header, once per stream, byte for byte what zlib writes: CMF
 * carries the window, FLG the level class and FDICT, and FCHECK is added the
 * way deflate.c adds it (31 when the pair is already a multiple of 31). The
 * gzip OS byte is 3 (Unix), as in __mc_zl_wrap.
 */
function __mc_zl_header(DeflateContext $c): void
{
    if ($c->headerDone) { return; }
    $c->headerDone = true;
    $lv = $c->level < 0 ? 6 : $c->level;
    $plain = $c->strategy >= 2 || $lv < 2;
    if ($c->encoding === 15) {
        $wbits = $c->window < 9 ? 9 : $c->window;
        $cmf = (($wbits - 8) << 4) | 8;
        $flevel = $plain ? 0 : ($lv < 6 ? 1 : ($lv === 6 ? 2 : 3));
        $hdr = ($cmf << 8) | ($flevel << 6);
        if ($c->dict !== '') { $hdr = $hdr | 0x20; }
        $hdr = $hdr + 31 - ($hdr % 31);
        $c->out = $c->out . \chr($hdr >> 8) . \chr($hdr & 0xFF);
        if ($c->dict !== '') { $c->out = $c->out . \__mc_zl_be32($c->dictId); }
    } elseif ($c->encoding === 31) {
        $xfl = $lv === 9 ? 2 : ($plain ? 4 : 0);
        $c->out = $c->out . "\x1f\x8b\x08\x00\x00\x00\x00\x00" . \chr($xfl) . "\x03";
    }
}

/** A fresh stream on the same settings; the dictionary (not gzip's) is the first history. */
function __mc_zl_reset(DeflateContext $c): void
{
    $c->headerDone = false;
    $c->finished = false;
    $c->check = $c->encoding === 15 ? 1 : 0;
    $c->total = 0;
    $c->win = $c->encoding === 31 ? '' : $c->dict;
    $c->base = 0;
    $c->head = [];
    $c->prev = [];
    $c->hashed = 0;
    $c->pending = '';
    $c->bb = 0;
    $c->bc = 0;
}

/**
 * One deflate_add(). NO_FLUSH buffers up to 64 KiB before coding a block; a
 * SYNC or FULL flush closes the block and appends an empty stored one, whose
 * LEN/NLEN are the 00 00 ff ff a permessage-deflate peer strips; FULL also
 * forgets the history; PARTIAL appends an empty fixed block; BLOCK just closes
 * the block. FINISH ends the stream, and the next call starts a new one, as
 * php's does.
 */
function __mc_zl_deflate_core(DeflateContext $c, string $data, int $flush): string
{
    $c->out = '';
    if ($c->finished) { \__mc_zl_reset($c); }
    \__mc_zl_header($c);
    if ($data !== '') {
        $c->total = $c->total + \strlen($data);
        if ($c->encoding === 31) { $c->check = \__mc_zl_crc32_update($c->check, $data); }
        elseif ($c->encoding === 15) { $c->check = \__mc_zl_adler32_update($c->check, $data); }
        $c->pending = $c->pending . $data;
    }
    $final = $flush === 4;
    if ($flush === 0 && \strlen($c->pending) < 65536) { return $c->out; }
    if ($c->pending !== '' || $final) {
        $take = $c->pending;
        $c->pending = '';
        if ($c->level === 0) { \__mc_zl_stored_block($c, $take, $final); }
        else { \__mc_zl_block($c, $take, $final); }
    }
    if ($final) {
        \__mc_zl_align($c);
        if ($c->encoding === 15) { $c->out = $c->out . \__mc_zl_be32($c->check); }
        elseif ($c->encoding === 31) {
            $c->out = $c->out . \__mc_zl_le32($c->check) . \__mc_zl_le32($c->total & 0xFFFFFFFF);
        }
        $c->finished = true;
    } elseif ($flush === 2 || $flush === 3) {
        \__mc_zl_put($c, 0, 3);
        \__mc_zl_align($c);
        $c->out = $c->out . "\x00\x00\xff\xff";
        if ($flush === 3) {
            $c->win = '';
            $c->base = 0;
            $c->head = [];
            $c->prev = [];
            $c->hashed = 0;
        }
    } elseif ($flush === 1) {
        \__mc_zl_put($c, 2, 3);
        \__mc_zl_put($c, 0, 7);
    }
    $r = $c->out;
    $c->out = '';

    return $r;
}

function __mc_zl_deflate_raw(string $data, int $level): string
{
    if ($level === 0) { return \__mc_zl_stored($data); }

    $c = new DeflateContext();
    $c->level = $level;
    $c->maxChain = \__mc_zl_max_chain($level);
    $z = \__mc_zl_deflate_core($c, $data, 4);
    // Incompressible input: a stored block is smaller than a Huffman-coded one,
    // and zlib makes the same choice.
    $s = \__mc_zl_stored($data);
    if (\strlen($s) < \strlen($z)) { return $s; }

    return $z;
}

/** How far down a hash chain to look: the whole meaning of $level here — more candidates, better matches, more time. */
function __mc_zl_max_chain(int $level): int
{
    if ($level < 0) { return 128; }
    if ($level <= 3) { return 16; }
    if ($level <= 6) { return 128; }

    return 1024;
}

/** The whole input as BTYPE=00 blocks — legal DEFLATE, and the only shape for level 0. */
function __mc_zl_stored(string $data): string
{
    $n = \strlen($data);
    $out = '';
    $pos = 0;
    if ($n === 0) {
        return "\x01\x00\x00\xff\xff";
    }
    while ($pos < $n) {
        $len = $n - $pos;
        if ($len > 65535) { $len = 65535; }
        $last = ($pos + $len >= $n) ? 1 : 0;
        $out = $out . \chr($last) . \chr($len & 0xFF) . \chr(($len >> 8) & 0xFF)
             . \chr((~$len) & 0xFF) . \chr(((~$len) >> 8) & 0xFF)
             . \substr($data, $pos, $len);
        $pos = $pos + $len;
    }
    return $out;
}

// ── the containers ──────────────────────────────────────────────────────

/** Adler-32 (RFC 1950 §9): the zlib container's integrity check. */
function __mc_zl_adler32(string $data): int
{
    return \__mc_zl_adler32_update(1, $data);
}

/**
 * A running Adler-32: feed it the previous value (1 to start). The two sums are
 * reduced once per 5552 bytes, zlib's NMAX — the most a 32-bit sum takes
 * without overflowing, and far inside a 64-bit one.
 */
function __mc_zl_adler32_update(int $adler, string $data): int
{
    $a = $adler & 0xFFFF;
    $b = ($adler >> 16) & 0xFFFF;
    $n = \strlen($data);
    $i = 0;
    while ($i < $n) {
        $end = $i + 5552;
        if ($end > $n) { $end = $n; }
        while ($i < $end) {
            $a = $a + \ord($data[$i]);
            $b = $b + $a;
            $i = $i + 1;
        }
        $a = $a % 65521;
        $b = $b % 65521;
    }

    return ($b << 16) | $a;
}

/** A running CRC-32 (crc32()'s polynomial): feed it the previous value (0 to start). */
function __mc_zl_crc32_update(int $crc, string $data): int
{
    static $t = [];
    if (\count($t) === 0) {
        for ($i = 0; $i < 256; $i = $i + 1) {
            $c = $i;
            for ($j = 0; $j < 8; $j = $j + 1) {
                $c = ($c & 1) !== 0 ? (($c >> 1) ^ 0xEDB88320) : ($c >> 1);
            }
            $t[$i] = $c;
        }
    }
    $crc = ($crc ^ 0xFFFFFFFF) & 0xFFFFFFFF;
    $n = \strlen($data);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $crc = $t[($crc ^ \ord($data[$i])) & 0xFF] ^ ($crc >> 8);
    }

    return $crc ^ 0xFFFFFFFF;
}

/** A 32-bit value, most significant byte first (the zlib checksum's order). */
function __mc_zl_be32(int $v): string
{
    return \chr(($v >> 24) & 0xFF) . \chr(($v >> 16) & 0xFF) . \chr(($v >> 8) & 0xFF) . \chr($v & 0xFF);
}

/** A 32-bit value, least significant byte first (the gzip trailer's order). */
function __mc_zl_le32(int $v): string
{
    return \chr($v & 0xFF) . \chr(($v >> 8) & 0xFF) . \chr(($v >> 16) & 0xFF) . \chr(($v >> 24) & 0xFF);
}

function __mc_zl_rd_le32(string $s, int $off): int
{
    return \ord($s[$off]) | (\ord($s[$off + 1]) << 8) | (\ord($s[$off + 2]) << 16) | (\ord($s[$off + 3]) << 24);
}

/**
 * php raises a ValueError for a level outside -1..9 and for an encoding that is
 * not one of the three. Same message, same argument numbers — a caller catching
 * ValueError sees what it expects.
 */
function __mc_zl_check(string $fn, int $level, int $encoding): void
{
    if ($level < -1 || $level > 9) {
        throw new \ValueError($fn . '(): Argument #2 ($level) must be between -1 and 9');
    }
    if ($encoding !== -15 && $encoding !== 15 && $encoding !== 31) {
        throw new \ValueError($fn . '(): Argument #3 ($encoding) must be one of ZLIB_ENCODING_RAW, '
            . 'ZLIB_ENCODING_GZIP, or ZLIB_ENCODING_DEFLATE');
    }
}

/**
 * Wrap a raw DEFLATE stream in the container $encoding names. -15 is raw, 15 is
 * zlib (a two-byte header whose FLG carries the level and makes the pair a
 * multiple of 31, plus a trailing Adler-32), 31 is gzip (a ten-byte header plus
 * a CRC-32 and the input length, both little-endian).
 *
 * The gzip header's OS byte is 3, Unix. php emits whatever zlib was built with —
 * 3 on Linux, 19 on this mac — so it is host-dependent in php and fixed here.
 */
function __mc_zl_wrap(string $body, string $data, int $level, int $encoding): string
{
    if ($encoding === -15) { return $body; }
    if ($encoding === 15) {
        // FLG's low 5 bits are the check value; bits 6-7 are the level hint.
        $flg = 0x9C;
        if ($level === 0 || $level === 1) { $flg = 0x01; }
        elseif ($level === 9) { $flg = 0xDA; }

        return \chr(0x78) . \chr($flg) . $body . \__mc_zl_be32(\__mc_zl_adler32($data));
    }
    $xfl = 0x00;
    if ($level === 0 || $level === 1) { $xfl = 0x04; }
    elseif ($level === 9) { $xfl = 0x02; }

    return "\x1f\x8b\x08\x00\x00\x00\x00\x00" . \chr($xfl) . \chr(0x03)
         . $body . \__mc_zl_le32(\crc32($data)) . \__mc_zl_le32(\strlen($data));
}

/**
 * The inverse: strip the container, inflate, and check what the container
 * promised. A failed check answers false, as php's does — a truncated or
 * corrupt stream must not come back as a short string that looks like data.
 *
 * @return string|false
 */
function __mc_zl_unwrap(string $data, int $maxLen, int $encoding)
{
    $n = \strlen($data);
    if ($encoding === -15) { return \__mc_zl_inflate_raw($data, $maxLen); }

    if ($encoding === 15) {
        if ($n < 6) { return false; }
        $cmf = \ord($data[0]);
        $flg = \ord($data[1]);
        // CM must be 8 (deflate), the pair must be a multiple of 31, and FDICT
        // (bit 5) means a preset dictionary this build does not carry.
        if (($cmf & 0x0F) !== 8 || (($cmf << 8) | $flg) % 31 !== 0 || ($flg & 0x20) !== 0) {
            return false;
        }
        $out = \__mc_zl_inflate_raw(\substr($data, 2, $n - 6), $maxLen);
        if ($out === false) { return false; }
        if (\__mc_zl_adler32($out) !== \__mc_zl_rd_be32($data, $n - 4)) { return false; }

        return $out;
    }

    // gzip: a fixed ten bytes, then whatever the FLG bits added.
    if ($n < 18) { return false; }
    if (\ord($data[0]) !== 0x1F || \ord($data[1]) !== 0x8B || \ord($data[2]) !== 0x08) { return false; }
    $flg = \ord($data[3]);
    $p = 10;
    if (($flg & 0x04) !== 0) {          // FEXTRA: a two-byte length then that many bytes
        if ($p + 2 > $n) { return false; }
        $p = $p + 2 + (\ord($data[$p]) | (\ord($data[$p + 1]) << 8));
    }
    if (($flg & 0x08) !== 0) {          // FNAME, NUL-terminated
        while ($p < $n && \ord($data[$p]) !== 0) { $p = $p + 1; }
        $p = $p + 1;
    }
    if (($flg & 0x10) !== 0) {          // FCOMMENT, NUL-terminated
        while ($p < $n && \ord($data[$p]) !== 0) { $p = $p + 1; }
        $p = $p + 1;
    }
    if (($flg & 0x02) !== 0) { $p = $p + 2; }   // FHCRC
    if ($p + 8 > $n) { return false; }
    $out = \__mc_zl_inflate_raw(\substr($data, $p, $n - $p - 8), $maxLen);
    if ($out === false) { return false; }
    if (\crc32($out) !== \__mc_zl_rd_le32($data, $n - 8)) { return false; }
    if (\strlen($out) !== \__mc_zl_rd_le32($data, $n - 4)) { return false; }

    return $out;
}

function __mc_zl_rd_be32(string $s, int $off): int
{
    return (\ord($s[$off]) << 24) | (\ord($s[$off + 1]) << 16) | (\ord($s[$off + 2]) << 8) | \ord($s[$off + 3]);
}

/** php's ValueError for a negative $max_length, shared by the three decoders. */
function __mc_zl_check_max(string $fn, int $maxLen): void
{
    if ($maxLen < 0) {
        throw new \ValueError($fn . '(): Argument #2 ($max_length) must be greater than or equal to 0');
    }
}

// ── the php.net surface ─────────────────────────────────────────────────
// The default encodings are spelled as their numbers, not as ZLIB_ENCODING_*:
// the compiler that builds this stdlib is one generation behind and has never
// heard of the constant. -15 raw, 15 zlib, 31 gzip.

/** @return string|false */
function gzdeflate(string $data, int $level = -1, int $encoding = -15)
{
    \__mc_zl_check('gzdeflate', $level, $encoding);

    return \__mc_zl_wrap(\__mc_zl_deflate_raw($data, $level), $data, $level, $encoding);
}

/** @return string|false */
function gzcompress(string $data, int $level = -1, int $encoding = 15)
{
    \__mc_zl_check('gzcompress', $level, $encoding);

    return \__mc_zl_wrap(\__mc_zl_deflate_raw($data, $level), $data, $level, $encoding);
}

/** @return string|false */
function gzencode(string $data, int $level = -1, int $encoding = 31)
{
    \__mc_zl_check('gzencode', $level, $encoding);

    return \__mc_zl_wrap(\__mc_zl_deflate_raw($data, $level), $data, $level, $encoding);
}

/** @return string|false */
function gzinflate(string $data, int $max_length = 0)
{
    \__mc_zl_check_max('gzinflate', $max_length);

    return \__mc_zl_unwrap($data, $max_length, -15);
}

/** @return string|false */
function gzuncompress(string $data, int $max_length = 0)
{
    \__mc_zl_check_max('gzuncompress', $max_length);

    return \__mc_zl_unwrap($data, $max_length, 15);
}

/** @return string|false */
function gzdecode(string $data, int $max_length = 0)
{
    \__mc_zl_check_max('gzdecode', $max_length);

    return \__mc_zl_unwrap($data, $max_length, 31);
}

/** @return string|false */
function zlib_encode(string $data, int $encoding, int $level = -1)
{
    if ($level < -1 || $level > 9) {
        throw new \ValueError('zlib_encode(): Argument #3 ($level) must be between -1 and 9');
    }
    if ($encoding !== -15 && $encoding !== 15 && $encoding !== 31) {
        throw new \ValueError('zlib_encode(): Argument #2 ($encoding) must be one of ZLIB_ENCODING_RAW, '
            . 'ZLIB_ENCODING_GZIP, or ZLIB_ENCODING_DEFLATE');
    }

    return \__mc_zl_wrap(\__mc_zl_deflate_raw($data, $level), $data, $level, $encoding);
}

/**
 * `zlib_decode` with no encoding sniffs the container from its first bytes, as
 * php does: gzip's magic is unmistakable, and a zlib header is CM=8 with a
 * header word divisible by 31. Anything else is treated as raw DEFLATE.
 *
 * @return string|false
 */
function zlib_decode(string $data, int $max_length = 0)
{
    \__mc_zl_check_max('zlib_decode', $max_length);
    $n = \strlen($data);
    if ($n >= 2 && \ord($data[0]) === 0x1F && \ord($data[1]) === 0x8B) {
        return \__mc_zl_unwrap($data, $max_length, 31);
    }
    if ($n >= 2 && (\ord($data[0]) & 0x0F) === 8
        && ((\ord($data[0]) << 8) | \ord($data[1])) % 31 === 0) {
        return \__mc_zl_unwrap($data, $max_length, 15);
    }

    return \__mc_zl_unwrap($data, $max_length, -15);
}

// ── the incremental API ────────────────────────────────────────────────
// The flush modes are numbers for the same reason as the encodings above:
// 0 NO_FLUSH, 1 PARTIAL, 2 SYNC, 3 FULL, 4 FINISH, 5 BLOCK.

/** php's name for a value's type in a TypeError: `null`, `true`, `false`, else get_debug_type(). */
function __mc_zl_type_name(mixed $v): string
{
    if ($v === null) { return 'null'; }
    if ($v === true) { return 'true'; }
    if ($v === false) { return 'false'; }

    return \get_debug_type($v);
}

/**
 * The `dictionary` option as php joins it: a string as is, an array as its
 * entries each followed by NUL.
 *
 * @param array<string,mixed> $options
 */
function __mc_zl_dict_option(string $fn, array $options): string
{
    if (!\array_key_exists('dictionary', $options)) { return ''; }
    $d = $options['dictionary'];
    if (\is_string($d)) { return $d; }
    if (!\is_array($d)) {
        throw new \TypeError($fn . '(): Argument #2 ($options) must be of type zero-terminated string or array, '
            . \__mc_zl_type_name($d) . ' given');
    }
    $dict = '';
    foreach ($d as $entry) {
        $s = (string) $entry;
        if ($s === '') {
            throw new \ValueError($fn . '(): Argument #2 ($options) must not contain empty strings');
        }
        if (\strpos($s, "\0") !== false) {
            throw new \ValueError($fn . '(): Argument #2 ($options) must not contain strings with null bytes');
        }
        $dict = $dict . $s . "\0";
    }

    return $dict;
}

/**
 * The options php checks, in php's order — level, memory, window, strategy,
 * dictionary, and only then the encoding. A dictionary array is its entries
 * each followed by NUL, as php joins it; a gzip stream ignores a dictionary,
 * as zlib does. Window 8: zlib's raw and gzip deflate refuse it and php answers
 * false; its zlib container quietly takes 9.
 *
 * @param array<string,mixed> $options
 */
function deflate_init(int $encoding, array $options = []): DeflateContext|false
{
    $level = \array_key_exists('level', $options) ? (int) $options['level'] : -1;
    if ($level < -1 || $level > 9) {
        throw new \ValueError('deflate_init(): "level" option must be between -1 and 9');
    }
    $memory = \array_key_exists('memory', $options) ? (int) $options['memory'] : 8;
    if ($memory < 1 || $memory > 9) {
        throw new \ValueError('deflate_init(): "memory" option must be between 1 and 9');
    }
    $window = \array_key_exists('window', $options) ? (int) $options['window'] : 15;
    if ($window < 8 || $window > 15) {
        throw new \ValueError('deflate_init(): "window" option must be between 8 and 15');
    }
    $strategy = \array_key_exists('strategy', $options) ? (int) $options['strategy'] : 0;
    if ($strategy < 0 || $strategy > 4) {
        throw new \ValueError('deflate_init(): "strategy" option must be one of ZLIB_FILTERED, '
            . 'ZLIB_HUFFMAN_ONLY, ZLIB_RLE, ZLIB_FIXED, or ZLIB_DEFAULT_STRATEGY');
    }
    $dict = \__mc_zl_dict_option('deflate_init', $options);
    if ($encoding !== -15 && $encoding !== 15 && $encoding !== 31) {
        throw new \ValueError('deflate_init(): Argument #1 ($encoding) must be one of ZLIB_ENCODING_RAW, '
            . 'ZLIB_ENCODING_GZIP, or ZLIB_ENCODING_DEFLATE');
    }
    if ($window === 8 && $encoding !== 15) { return false; }

    $c = new DeflateContext();
    $c->encoding = $encoding;
    $c->level = $level;
    $c->window = $window < 9 ? 9 : $window;
    $c->strategy = $strategy;
    $c->maxChain = $strategy === 2 ? 0 : \__mc_zl_max_chain($level);
    if ($encoding !== 31) {
        $c->dict = $dict;
        $c->dictId = $dict === '' ? 0 : \__mc_zl_adler32_update(1, $dict);
    }
    \__mc_zl_reset($c);

    return $c;
}

/** Empty data outside FINISH is a no-op in php: nothing buffered is flushed, no marker is written. */
function deflate_add(DeflateContext $context, string $data, int $flush_mode = 2): string
{
    if ($flush_mode < 0 || $flush_mode > 5) {
        throw new \ValueError('deflate_add(): Argument #3 ($flush_mode) must be one of ZLIB_NO_FLUSH, '
            . 'ZLIB_PARTIAL_FLUSH, ZLIB_SYNC_FLUSH, ZLIB_FULL_FLUSH, ZLIB_BLOCK, or ZLIB_FINISH');
    }
    if ($data === '' && $flush_mode !== 4) { return ''; }

    return \__mc_zl_deflate_core($context, $data, $flush_mode);
}

/**
 * php's window check comes first, the encoding second, the dictionary last. A
 * raw stream takes its dictionary as history at once; a zlib stream keeps it
 * until its header asks for it (FDICT) and checks it against the Adler-32 the
 * header carries; gzip has no dictionary and ignores one. Window 8 is accepted
 * for all three; a zlib header naming a larger window than this is corrupt data.
 *
 * @param array<string,mixed> $options
 */
function inflate_init(int $encoding, array $options = []): InflateContext|false
{
    $window = \array_key_exists('window', $options) ? (int) $options['window'] : 15;
    if ($window < 8 || $window > 15) {
        throw new \ValueError('zlib window size (logarithm) (' . $window . ') must be within 8..15');
    }
    if ($encoding !== -15 && $encoding !== 15 && $encoding !== 31) {
        throw new \ValueError('Encoding mode must be ZLIB_ENCODING_RAW, ZLIB_ENCODING_GZIP or ZLIB_ENCODING_DEFLATE');
    }
    $dict = \__mc_zl_dict_option('inflate_init', $options);

    $c = new InflateContext();
    $c->encoding = $encoding;
    $c->window = $window;
    \__mc_zl_inflate_reset($c);
    if ($encoding === 15) { $c->dict = $dict; }
    // php hands a raw stream its dictionary only at window 15: it compares the
    // window-adjusted zlib argument against ZLIB_ENCODING_RAW, so any other
    // window drops the dictionary on the floor.
    if ($encoding === -15 && $window === 15 && $dict !== '') {
        $c->hist = \strlen($dict) > 32768 ? \substr($dict, -32768) : $dict;
    }

    return $c;
}

/**
 * One inflate_add(). A finished stream is reset lazily, on the next call, so
 * its status and read length stay readable until then; input after the end of
 * a stream is dropped, as is input after the block boundary where ZLIB_BLOCK
 * stops. Corrupt data answers false, and so does a zlib stream whose
 * dictionary is missing or wrong (status ZLIB_NEED_DICT).
 */
function inflate_add(InflateContext $context, string $data, int $flush_mode = 2): string|false
{
    if ($flush_mode < 0 || $flush_mode > 5) {
        throw new \ValueError('inflate_add(): Argument #3 ($flush_mode) must be one of ZLIB_NO_FLUSH, '
            . 'ZLIB_PARTIAL_FLUSH, ZLIB_SYNC_FLUSH, ZLIB_FULL_FLUSH, ZLIB_BLOCK, or ZLIB_FINISH');
    }
    if ($context->phase === 5) { \__mc_zl_inflate_reset($context); }
    if ($data === '' && $flush_mode !== 4) { return ''; }
    if ($context->phase === 6) {
        $context->status = -3;
        return false;
    }
    if ($context->phase === 7) {
        $context->status = 2;
        return false;
    }
    $len = \strlen($data);
    $cap0 = $len > 8192 ? $len : 8192;
    $r = \__mc_zl_inflate_run($context, $data, $flush_mode, $cap0, 0);
    if ($r === -5) {
        $context->status = -5;
        return $context->out;
    }
    if ($r < 0 || $r === 2) {
        $context->status = $r;
        return false;
    }
    if ($r === 1) {
        $context->status = 1;
        return $context->out;
    }
    // zlib answers Z_BUF_ERROR when a call makes no progress. php meets that
    // under FINISH, and when the output exactly filled its buffer: it grows the
    // buffer and asks again, with nothing left to read.
    $made = \strlen($context->out);
    $full = $made >= $cap0 && ($made - $cap0) % 8192 === 0;
    $context->status = $flush_mode === 4 || $full ? -5 : 0;

    return $context->out;
}

function inflate_get_status(InflateContext $context): int
{
    return $context->status;
}

function inflate_get_read_len(InflateContext $context): int
{
    return $context->readLen;
}
