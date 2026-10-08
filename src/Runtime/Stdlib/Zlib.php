<?php

// ext/zlib over the host libz (binding: Runtime/Zlib.php). Every function
// follows php's ext/zlib/zlib.c step for step — same deflateInit2 arguments
// (one-shot encode uses memLevel 9), same output-buffer rounds, same lazy
// reset — so the bytes, the max_length cut-off and the statuses are php's.
// Where php warns and answers false, this answers false.
//
// The gzip header's OS byte is libz's build-time OS_CODE: 3 on Linux, 19 on
// macOS — host-dependent in php too.

// ── z_stream, LP64 (identical on glibc, musl and Darwin) ────────────────
// next_in@0 avail_in@8(u32) total_in@16 next_out@24 avail_out@32(u32) total_out@40.

/** A zeroed z_stream, as its address; 0 when calloc fails. */
function __mc_zl_new(): int
{
    $z = \Runtime\Libc\calloc(112, 1);
    if ($z === null) { return 0; }

    return \ptr_to_int($z);
}

function __mc_zl_free(int $z): void
{
    \Runtime\Libc\free(\int_to_ptr($z));
}

/** $data in a C buffer with one NUL past the end (php feeds inflate in_len + 1). */
function __mc_zl_in(string $data): \Ffi\Ptr
{
    $n = \strlen($data);
    $p = \Runtime\Libc\calloc($n + 1, 1);
    \Runtime\Zlib\copyIn($p, $data, $n);

    return $p;
}

function __mc_zl_set_in(int $z, \Ffi\Ptr $p, int $n): void
{
    $s = \int_to_ptr($z);
    \poke_i64($s, 0, \ptr_to_int($p));
    \poke_i32($s, 8, $n);
}

function __mc_zl_set_out(int $z, \Ffi\Ptr $p, int $off, int $n): void
{
    $s = \int_to_ptr($z);
    \poke_i64($s, 24, \ptr_to_int($p) + $off);
    \poke_i32($s, 32, $n);
}

function __mc_zl_avail_in(int $z): int
{
    return \peek_u32(\int_to_ptr($z), 8);
}

function __mc_zl_avail_out(int $z): int
{
    return \peek_u32(\int_to_ptr($z), 32);
}

/** php_zlib_encode: one deflate(Z_FINISH) at memLevel 9. */
function __mc_zl_encode(string $data, int $level, int $encoding): string|false
{
    $z = \__mc_zl_new();
    if ($z === 0) { return false; }
    if (\Runtime\Zlib\deflateInit2($z, $level, 8, $encoding, 9, 0, \Runtime\Zlib\version(), 112) !== 0) {
        \__mc_zl_free($z);
        return false;
    }
    $n = \strlen($data);
    // deflateBound is short for level 0 on zlib 1.2.13 (Debian): deflate(Z_FINISH) answers Z_OK, not Z_STREAM_END,
    // for 0..2 bytes. php sizes the buffer 1.015 * n + 24 (PHP_ZLIB_BUFFER_SIZE_GUESS); take the larger of the two.
    $cap = \Runtime\Zlib\deflateBound($z, $n);
    $guess = (int) ($n * 1.015) + 24;
    if ($guess > $cap) { $cap = $guess; }
    $in = \__mc_zl_in($data);
    $out = \Runtime\Libc\malloc($cap);
    \__mc_zl_set_in($z, $in, $n);
    \__mc_zl_set_out($z, $out, 0, $cap);
    $st = \Runtime\Zlib\deflate($z, 4);
    $r = $st === 1 ? \str_from_buffer($out, $cap - \__mc_zl_avail_out($z)) : false;
    \Runtime\Zlib\deflateEnd($z);
    \Runtime\Libc\free($out);
    \Runtime\Libc\free($in);
    \__mc_zl_free($z);

    return $r;
}

/**
 * php_zlib_decode + php_zlib_inflate_rounds. The buffer starts at the input
 * length (or max_length when smaller) and grows by an eighth per round; a
 * max_length the output reaches is Z_MEM_ERROR. Encoding 47 (zlib or gzip,
 * sniffed by libz) retries as raw DEFLATE on a data error.
 */
function __mc_zl_decode(string $data, int $maxLen, int $encoding): string|false
{
    $n = \strlen($data);
    if ($n === 0) { return false; }
    $z = \__mc_zl_new();
    if ($z === 0) { return false; }
    $in = \__mc_zl_in($data);
    $r = false;
    while (\Runtime\Zlib\inflateInit2($z, $encoding, \Runtime\Zlib\version(), 112) === 0) {
        \__mc_zl_set_in($z, $in, $n + 1);
        $size = $maxLen > 0 && $maxLen < $n + 1 ? $maxLen : $n + 1;
        $buf = \Runtime\Libc\malloc($size);
        $used = 0;
        $round = 0;
        do {
            if ($maxLen > 0 && $maxLen <= $used) {
                $st = -4;
            } else {
                $buf = \Runtime\Libc\realloc($buf, $size);
                $free = $size - $used;
                \__mc_zl_set_out($z, $buf, $used, $free);
                $st = \Runtime\Zlib\inflate($z, 0);
                $used = $used + $free - \__mc_zl_avail_out($z);
                $size = $size + ($size >> 3) + 1;
            }
            $round = $round + 1;
        } while (($st === -5 || ($st === 0 && \__mc_zl_avail_in($z) !== 0)) && $round < 100);
        \Runtime\Zlib\inflateEnd($z);
        if ($st === 1) { $r = \str_from_buffer($buf, $used); }
        \Runtime\Libc\free($buf);
        if (($st === 0 || $st === -3) && $encoding === 47) {
            $encoding = -15;
            continue;
        }
        break;
    }
    \Runtime\Libc\free($in);
    \__mc_zl_free($z);

    return $r;
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

/** php's ValueError for a negative $max_length, shared by the decoders. */
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

    return \__mc_zl_encode($data, $level, $encoding);
}

/** @return string|false */
function gzcompress(string $data, int $level = -1, int $encoding = 15)
{
    \__mc_zl_check('gzcompress', $level, $encoding);

    return \__mc_zl_encode($data, $level, $encoding);
}

/** @return string|false */
function gzencode(string $data, int $level = -1, int $encoding = 31)
{
    \__mc_zl_check('gzencode', $level, $encoding);

    return \__mc_zl_encode($data, $level, $encoding);
}

/** @return string|false */
function gzinflate(string $data, int $max_length = 0)
{
    \__mc_zl_check_max('gzinflate', $max_length);

    return \__mc_zl_decode($data, $max_length, -15);
}

/** @return string|false */
function gzuncompress(string $data, int $max_length = 0)
{
    \__mc_zl_check_max('gzuncompress', $max_length);

    return \__mc_zl_decode($data, $max_length, 15);
}

/** @return string|false */
function gzdecode(string $data, int $max_length = 0)
{
    \__mc_zl_check_max('gzdecode', $max_length);

    return \__mc_zl_decode($data, $max_length, 31);
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

    return \__mc_zl_encode($data, $level, $encoding);
}

/** @return string|false */
function zlib_decode(string $data, int $max_length = 0)
{
    \__mc_zl_check_max('zlib_decode', $max_length);

    return \__mc_zl_decode($data, $max_length, 47);
}

// ── the incremental API ────────────────────────────────────────────────
// The flush modes are numbers for the same reason as the encodings above:
// 0 NO_FLUSH, 1 PARTIAL, 2 SYNC, 3 FULL, 4 FINISH, 5 BLOCK.

/** php's `deflate_init` result: one live z_stream. */
#[\Manticore\Attr\Uncomparable]
final class DeflateContext
{
    public int $z = 0;

    public function __destruct()
    {
        if ($this->z !== 0) {
            \Runtime\Zlib\deflateEnd($this->z);
            \__mc_zl_free($this->z);
        }
    }
}

/** php's `inflate_init` result: one live z_stream, the dictionary a zlib header may ask for, the last status. */
#[\Manticore\Attr\Uncomparable]
final class InflateContext
{
    public int $z = 0;
    public string $dict = '';
    public bool $hasDict = false;
    public int $status = 0;

    public function __destruct()
    {
        if ($this->z !== 0) {
            \Runtime\Zlib\inflateEnd($this->z);
            \__mc_zl_free($this->z);
        }
    }
}

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

/** php folds the window into windowBits: raw -window, zlib window, gzip 16 + window. */
function __mc_zl_window_bits(int $encoding, int $window): int
{
    return $encoding < 0 ? $encoding + 15 - $window : $encoding - (15 - $window);
}

/**
 * The options php checks, in php's order — level, memory, window, strategy,
 * dictionary, and only then the encoding. libz refuses window 8 for raw and
 * gzip deflate; php answers false.
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
    $z = \__mc_zl_new();
    if ($z === 0) { return false; }
    $bits = \__mc_zl_window_bits($encoding, $window);
    if (\Runtime\Zlib\deflateInit2($z, $level, 8, $bits, $memory, $strategy, \Runtime\Zlib\version(), 112) !== 0) {
        \__mc_zl_free($z);
        return false;
    }
    if ($dict !== '') {
        \Runtime\Zlib\deflateSetDictionary($z, $dict, \strlen($dict));
    }
    $c = new DeflateContext();
    $c->z = $z;

    return $c;
}

/** Empty data outside FINISH is a no-op in php: nothing buffered is flushed, no marker is written. */
function deflate_add(DeflateContext $context, string $data, int $flush_mode = 2): string|false
{
    if ($flush_mode < 0 || $flush_mode > 5) {
        throw new \ValueError('deflate_add(): Argument #3 ($flush_mode) must be one of ZLIB_NO_FLUSH, '
            . 'ZLIB_PARTIAL_FLUSH, ZLIB_SYNC_FLUSH, ZLIB_FULL_FLUSH, ZLIB_BLOCK, or ZLIB_FINISH');
    }
    if ($data === '' && $flush_mode !== 4) { return ''; }
    $z = $context->z;
    $n = \strlen($data);
    $cap = (int) ($n * 1.015) + 23;
    if ($cap < 64) { $cap = 64; }
    $in = \__mc_zl_in($data);
    $out = \Runtime\Libc\malloc($cap);
    \__mc_zl_set_in($z, $in, $n);
    \__mc_zl_set_out($z, $out, 0, $cap);
    $used = 0;
    do {
        if (\__mc_zl_avail_out($z) === 0) {
            $out = \Runtime\Libc\realloc($out, $cap * 2);
            \__mc_zl_set_out($z, $out, $used, $cap);
            $cap = $cap * 2;
        }
        $st = \Runtime\Zlib\deflate($z, $flush_mode);
        $used = $cap - \__mc_zl_avail_out($z);
    } while ($st === 0 && \__mc_zl_avail_out($z) === 0);
    $r = false;
    if ($st === 0 || $st === 1) {
        $r = \str_from_buffer($out, $used);
        if ($st === 1) { \Runtime\Zlib\deflateReset($z); }
    }
    \Runtime\Libc\free($out);
    \Runtime\Libc\free($in);

    return $r;
}

/**
 * php's window check comes first, the encoding second, the dictionary last. A
 * raw stream takes its dictionary at once, and only at window 15 (php compares
 * the window-adjusted windowBits against ZLIB_ENCODING_RAW); a zlib stream
 * hands it over when its header asks (Z_NEED_DICT).
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
    $z = \__mc_zl_new();
    if ($z === 0) { return false; }
    $bits = \__mc_zl_window_bits($encoding, $window);
    if (\Runtime\Zlib\inflateInit2($z, $bits, \Runtime\Zlib\version(), 112) !== 0) {
        \__mc_zl_free($z);
        return false;
    }
    $c = new InflateContext();
    $c->z = $z;
    $c->dict = $dict;
    $c->hasDict = $dict !== '';
    if ($bits === -15 && $c->hasDict) {
        \Runtime\Zlib\inflateSetDictionary($z, $dict, \strlen($dict));
    }

    return $c;
}

/**
 * One inflate_add(), as php runs it: a finished stream is reset lazily on the
 * next call (so status and read length stay readable), the output grows by
 * 8 KiB, and Z_NEED_DICT is answered from the dictionary inflate_init got.
 */
function inflate_add(InflateContext $context, string $data, int $flush_mode = 2): string|false
{
    if ($flush_mode < 0 || $flush_mode > 5) {
        throw new \ValueError('inflate_add(): Argument #3 ($flush_mode) must be one of ZLIB_NO_FLUSH, '
            . 'ZLIB_PARTIAL_FLUSH, ZLIB_SYNC_FLUSH, ZLIB_FULL_FLUSH, ZLIB_BLOCK, or ZLIB_FINISH');
    }
    $z = $context->z;
    if ($context->status === 1) {
        $context->status = 0;
        \Runtime\Zlib\inflateReset($z);
    }
    if ($data === '' && $flush_mode !== 4) { return ''; }
    $n = \strlen($data);
    $cap = $n > 8192 ? $n : 8192;
    $in = \__mc_zl_in($data);
    $out = \Runtime\Libc\malloc($cap);
    \__mc_zl_set_in($z, $in, $n);
    \__mc_zl_set_out($z, $out, 0, $cap);
    $r = false;
    while (true) {
        $st = \Runtime\Zlib\inflate($z, $flush_mode);
        $used = $cap - \__mc_zl_avail_out($z);
        $context->status = $st;
        $grow = ($st === 0 && \__mc_zl_avail_out($z) === 0)
            || ($st === -5 && $flush_mode === 4 && \__mc_zl_avail_out($z) === 0);
        if ($grow) {
            $out = \Runtime\Libc\realloc($out, $cap + 8192);
            \__mc_zl_set_out($z, $out, $used, 8192);
            $cap = $cap + 8192;
            continue;
        }
        if ($st === 0 || $st === 1 || $st === -5) {
            $r = \str_from_buffer($out, $used);
            break;
        }
        if ($st === 2 && $context->hasDict) {
            $context->hasDict = false;
            if (\Runtime\Zlib\inflateSetDictionary($z, $context->dict, \strlen($context->dict)) === 0) {
                continue;
            }
        }
        break;
    }
    \Runtime\Libc\free($out);
    \Runtime\Libc\free($in);

    return $r;
}

function inflate_get_status(InflateContext $context): int
{
    return $context->status;
}

function inflate_get_read_len(InflateContext $context): int
{
    return \peek_i64(\int_to_ptr($context->z), 16);
}
