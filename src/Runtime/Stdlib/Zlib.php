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

function __mc_zl_decode(array &$st, array $h): int
{
    $code = 0;
    $first = 0;
    $index = 0;
    $count = $h[0];
    $symbol = $h[1];
    for ($len = 1; $len <= 15; $len++) {
        $b = \__mc_zl_bits($st, 1);
        if ($b < 0) { return -1; }
        $code = $code | $b;
        $c = $count[$len];
        if ($code - $first < $c) { return $symbol[$index + ($code - $first)]; }
        $index = $index + $c;
        $first = ($first + $c) << 1;
        $code = $code << 1;
    }
    return -1;
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

function __mc_zl_inflate_raw(string $data, int $maxLen): string|false
{
    $lbase = [3,4,5,6,7,8,9,10,11,13,15,17,19,23,27,31,35,43,51,59,67,83,99,115,131,163,195,227,258];
    $lext  = [0,0,0,0,0,0,0,0,1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5,0];
    $dbase = [1,2,3,4,5,7,9,13,17,25,33,49,65,97,129,193,257,385,513,769,1025,1537,2049,3073,4097,6145,8193,12289,16385,24577];
    $dext  = [0,0,0,0,1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10,11,11,12,12,13,13];
    $order = [16,17,18,0,8,7,9,6,10,5,11,4,12,3,13,2,14,1,15];

    $st = [$data, 0, 0, 0];
    // The output grows by doubling and is written through string offsets: a
    // per-byte concatenation reallocates, and inflate is all per-byte writes.
    $cap = 1024;
    $out = \str_repeat("\0", $cap);
    $olen = 0;
    $n = \strlen($data);

    while (true) {
        $last = \__mc_zl_bits($st, 1);
        if ($last < 0) { return false; }
        $type = \__mc_zl_bits($st, 2);
        if ($type < 0) { return false; }

        if ($type === 0) {
            // A stored block restarts on a byte boundary; LEN and its ones
            // complement follow, and disagreement means the stream is corrupt.
            $st[2] = 0;
            $st[3] = 0;
            if ($st[1] + 4 > $n) { return false; }
            $len = \ord($data[$st[1]]) | (\ord($data[$st[1] + 1]) << 8);
            $nlen = \ord($data[$st[1] + 2]) | (\ord($data[$st[1] + 3]) << 8);
            $st[1] = $st[1] + 4;
            if ($len !== ($nlen ^ 0xFFFF)) { return false; }
            if ($st[1] + $len > $n) { return false; }
            while ($olen + $len > $cap) {
                $out = $out . \str_repeat("\0", $cap);
                $cap = $cap * 2;
            }
            for ($k = 0; $k < $len; $k++) { $out[$olen + $k] = $data[$st[1] + $k]; }
            $olen = $olen + $len;
            $st[1] = $st[1] + $len;
        } elseif ($type === 1 || $type === 2) {
            if ($type === 1) {
                $t = \__mc_zl_fixed_tables();
                $lh = $t[0];
                $dh = $t[1];
            } else {
                $hlit = \__mc_zl_bits($st, 5);
                $hdist = \__mc_zl_bits($st, 5);
                $hclen = \__mc_zl_bits($st, 4);
                if ($hclen < 0) { return false; }
                $hlit = $hlit + 257;
                $hdist = $hdist + 1;
                $hclen = $hclen + 4;
                if ($hlit > 286 || $hdist > 30) { return false; }
                $cl = [];
                for ($i = 0; $i < 19; $i++) { $cl[$i] = 0; }
                for ($i = 0; $i < $hclen; $i++) {
                    $v = \__mc_zl_bits($st, 3);
                    if ($v < 0) { return false; }
                    $cl[$order[$i]] = $v;
                }
                $ch = \__mc_zl_construct($cl, 19);
                // The literal/length and distance lengths are themselves
                // Huffman-coded, with three repeat symbols: 16 repeats the
                // PREVIOUS length, 17 and 18 run zeros.
                $lens = [];
                $i = 0;
                $want = $hlit + $hdist;
                while ($i < $want) {
                    $sym = \__mc_zl_decode($st, $ch);
                    if ($sym < 0) { return false; }
                    if ($sym < 16) {
                        $lens[$i] = $sym;
                        $i = $i + 1;
                    } elseif ($sym === 16) {
                        if ($i === 0) { return false; }
                        $prev = $lens[$i - 1];
                        $r = \__mc_zl_bits($st, 2);
                        if ($r < 0) { return false; }
                        $r = $r + 3;
                        while ($r > 0 && $i < $want) { $lens[$i] = $prev; $i = $i + 1; $r = $r - 1; }
                    } else {
                        $r = $sym === 17 ? \__mc_zl_bits($st, 3) + 3 : \__mc_zl_bits($st, 7) + 11;
                        while ($r > 0 && $i < $want) { $lens[$i] = 0; $i = $i + 1; $r = $r - 1; }
                    }
                }
                $llens = [];
                for ($k = 0; $k < $hlit; $k++) { $llens[$k] = $lens[$k]; }
                $dlens = [];
                for ($k = 0; $k < $hdist; $k++) { $dlens[$k] = $lens[$hlit + $k]; }
                $lh = \__mc_zl_construct($llens, $hlit);
                $dh = \__mc_zl_construct($dlens, $hdist);
            }

            while (true) {
                $sym = \__mc_zl_decode($st, $lh);
                if ($sym < 0) { return false; }
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
                    if ($sym >= 29) { return false; }
                    $e = \__mc_zl_bits($st, $lext[$sym]);
                    if ($e < 0) { return false; }
                    $len = $lbase[$sym] + $e;
                    $dsym = \__mc_zl_decode($st, $dh);
                    if ($dsym < 0 || $dsym >= 30) { return false; }
                    $e = \__mc_zl_bits($st, $dext[$dsym]);
                    if ($e < 0) { return false; }
                    $dist = $dbase[$dsym] + $e;
                    if ($dist > $olen) { return false; }
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
                if ($maxLen > 0 && $olen > $maxLen) { return false; }
            }
        } else {
            return false;
        }
        if ($maxLen > 0 && $olen > $maxLen) { return false; }
        if ($last === 1) { break; }
    }

    return \substr($out, 0, $olen);
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

function __mc_zl_deflate_raw(string $data, int $level): string
{
    $n = \strlen($data);
    if ($level === 0) { return \__mc_zl_stored($data); }

    $lbase = [3,4,5,6,7,8,9,10,11,13,15,17,19,23,27,31,35,43,51,59,67,83,99,115,131,163,195,227,258];
    $lext  = [0,0,0,0,0,0,0,0,1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5,0];
    $dbase = [1,2,3,4,5,7,9,13,17,25,33,49,65,97,129,193,257,385,513,769,1025,1537,2049,3073,4097,6145,8193,12289,16385,24577];
    $dext  = [0,0,0,0,1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10,11,11,12,12,13,13];

    // How far down a hash chain to look. This is the whole meaning of $level
    // here: more candidates, better matches, more time.
    $maxChain = 128;
    if ($level < 0) { $maxChain = 128; }
    elseif ($level <= 3) { $maxChain = 16; }
    elseif ($level <= 6) { $maxChain = 128; }
    else { $maxChain = 1024; }

    $cap = 1024;
    $out = \str_repeat("\0", $cap);
    $olen = 0;
    $bb = 0;
    $bc = 0;

    // BFINAL=1, BTYPE=01 (fixed Huffman): one block for the whole input.
    $bb = $bb | (1 << $bc); $bc = $bc + 1;
    $bb = $bb | (1 << $bc); $bc = $bc + 2;

    $head = [];
    $prev = [];
    $pos = 0;
    while ($pos < $n) {
        $len = 0;
        $dist = 0;
        if ($pos + 2 < $n) {
            $h = ((\ord($data[$pos]) << 10) ^ (\ord($data[$pos + 1]) << 5) ^ \ord($data[$pos + 2])) & 0x7FFF;
            $cand = $head[$h] ?? -1;
            $chain = $maxChain;
            $max = $n - $pos;
            if ($max > 258) { $max = 258; }
            while ($cand >= 0 && $chain > 0 && $pos - $cand <= 32768) {
                // Only a candidate that beats the best so far is worth measuring
                // past its first bytes, so check the deciding byte first. $len <
                // $max is what keeps that probe inside the string: $pos + $len is
                // one past the current best, and the best can already reach the end.
                if ($len === 0 || ($len < $max && $data[$cand + $len] === $data[$pos + $len])) {
                    $l = 0;
                    while ($l < $max && $data[$cand + $l] === $data[$pos + $l]) { $l = $l + 1; }
                    if ($l >= 3 && $l > $len) {
                        $len = $l;
                        $dist = $pos - $cand;
                        if ($l === 258) { break; }
                    }
                }
                $cand = $prev[$cand] ?? -1;
                $chain = $chain - 1;
            }
            $prev[$pos] = $head[$h] ?? -1;
            $head[$h] = $pos;
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
                if ($p + 2 < $n) {
                    $h = ((\ord($data[$p]) << 10) ^ (\ord($data[$p + 1]) << 5) ^ \ord($data[$p + 2])) & 0x7FFF;
                    $prev[$p] = $head[$h] ?? -1;
                    $head[$h] = $p;
                }
            }
            $pos = $pos + $len;
        } else {
            $lc = \__mc_zl_fixed_lit(\ord($data[$pos]));
            $bb = $bb | (\__mc_zl_rev($lc[0], $lc[1]) << $bc); $bc = $bc + $lc[1];
            $pos = $pos + 1;
        }
        while ($bc >= 8) {
            if ($olen >= $cap) { $out = $out . \str_repeat("\0", $cap); $cap = $cap * 2; }
            $out[$olen] = \chr($bb & 0xFF); $olen = $olen + 1; $bb = $bb >> 8; $bc = $bc - 8;
        }
    }

    // End of block, then flush the partial byte.
    $ec = \__mc_zl_fixed_lit(256);
    $bb = $bb | (\__mc_zl_rev($ec[0], $ec[1]) << $bc); $bc = $bc + $ec[1];
    while ($bc > 0) {
        if ($olen >= $cap) { $out = $out . \str_repeat("\0", $cap); $cap = $cap * 2; }
        $out[$olen] = \chr($bb & 0xFF); $olen = $olen + 1; $bb = $bb >> 8; $bc = $bc - 8;
    }

    $z = \substr($out, 0, $olen);
    // Incompressible input: a stored block is smaller than a Huffman-coded one,
    // and zlib makes the same choice.
    $s = \__mc_zl_stored($data);
    if (\strlen($s) < \strlen($z)) { return $s; }

    return $z;
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
    $a = 1;
    $b = 0;
    $n = \strlen($data);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $a = ($a + \ord($data[$i])) % 65521;
        $b = ($b + $a) % 65521;
    }

    return ($b << 16) | $a;
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
