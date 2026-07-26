// pack / unpack — the binary string codec, over the format table at
// https://www.php.net/pack. DEMAND-GATED (Main.php).
//
// In the PRELUDE rather than the stdlib because `pack` is VARIADIC: the
// stdlib .sig carries no variadic-ness, so across that boundary the callee
// read its arguments from the wrong place and every result was garbage.
// unpack follows its twin — one home for one codec.

/**
 * php's unpack, over the format table at https://www.php.net/pack.
 *
 * Implemented: a A Z (NUL/space-padded and NUL-terminated strings), h H (hex,
 * low- and high-nibble-first), c C (8-bit), s S n v (16-bit), i I l L N V
 * (32-bit), q Q J P (64-bit), x (skip a byte), X (back up one byte), @ (seek to
 * an absolute position).
 *
 * NOT implemented, and deliberately so: the float family f g G d e E. Reading
 * them needs a bit-level reinterpretation of a double that this layer has no
 * primitive for, and inventing one would be worse than the honest `false`
 * an unsupported code returns. `i`/`I` are documented machine-dependent; this
 * target is LP64, so they are 32-bit little-endian like `l`/`L`.
 *
 * Keys follow php: an unnamed group yields 1-based INT keys, `<code><n><name>`
 * yields "<name>1".."<name>N", and a single named value is just "<name>".
 *
 * @return array<int|string,mixed>|false
 */
function unpack(string $format, string $string, int $offset = 0): mixed
{
    // The values are genuinely MIXED (int for the numeric codes, string for
    // a/A/Z/h/H), so the accumulator must hold CELLS. Left bare it would type
    // itself from the first store and the caller would read raw ints back
    // through a cell-shaped access — denormal floats.
    /** @var array<int|string,mixed> $out */
    $out = [];
    $pos = $offset;
    $slen = \strlen($string);
    $groups = \explode("/", $format);
    foreach ($groups as $g) {
        if ($g === "") { continue; }
        $code = $g[0];
        $rest = \substr($g, 1);
        // Split the repeat count (digits or `*`) from the trailing name.
        $cnt = "";
        $i = 0;
        $rn = \strlen($rest);
        while ($i < $rn && ($rest[$i] === "*" || \ctype_digit($rest[$i]))) {
            $cnt = $cnt . $rest[$i];
            $i = $i + 1;
        }
        $name = \substr($rest, $i);
        $star = $cnt === "*";
        // The position codes consume no data and produce no value.
        if ($code === "@") {
            $pos = $cnt === "" ? 0 : (int)$cnt;
            continue;
        }
        if ($code === "X") {
            $back = $star ? 1 : ($cnt === "" ? 1 : (int)$cnt);
            $pos = $pos - $back;
            if ($pos < 0) { return false; }
            continue;
        }
        if ($code === "x") {
            $skip = $star ? $slen - $pos : ($cnt === "" ? 1 : (int)$cnt);
            $pos = $pos + $skip;
            continue;
        }
        $size = __mc_unpack_size($code);
        if ($size === 0) { return false; }
        $repeat = 1;
        if ($star) {
            $repeat = $size > 0 ? \intdiv($slen - $pos, $size) : 1;
        } elseif ($cnt !== "") {
            $repeat = (int)$cnt;
        }
        if ($code === "a" || $code === "A" || $code === "Z" || $code === "H" || $code === "h") {
            // These consume `repeat` BYTES and yield ONE value.
            $take = $star ? $slen - $pos : $repeat;
            if ($take < 0) { $take = 0; }
            $raw = \substr($string, $pos, $take);
            $pos = $pos + $take;
            $val = $raw;
            if ($code === "A") { $val = \rtrim($raw, " \t\n\r\0\x0B"); }
            if ($code === "Z") {
                // Hand-rolled scan: a NUL NEEDLE is exactly the case a
                // C-string-shaped strpos cannot answer.
                $zn = \strlen($raw);
                $zi = 0;
                while ($zi < $zn) {
                    if (\ord($raw[$zi]) === 0) { break; }
                    $zi = $zi + 1;
                }
                $val = \substr($raw, 0, $zi);
            }
            // H is high-nibble-first (what bin2hex produces); h is
            // LOW-nibble-first, so each byte's two hex digits swap.
            if ($code === "H") { $val = \bin2hex($raw); }
            if ($code === "h") { $val = __mc_bin2hex_low($raw); }
            if ($name === "") { $out[1] = $val; } else { $out[$name] = $val; }
            continue;
        }
        $k = 0;
        while ($k < $repeat) {
            if ($pos + $size > $slen) { break; }
            $v = __mc_unpack_int($string, $pos, $code);
            $pos = $pos + $size;
            $k = $k + 1;
            // An unnamed group yields INT keys 1..N (a string "1" would not
            // answer $r[1]); a named one suffixes the index ONLY when the group
            // repeats: `Cfoo` is key "foo", `C2foo` is "foo1"/"foo2".
            if ($name === "") {
                $out[$k] = $v;
            } else {
                $out[$repeat === 1 ? $name : $name . (string)$k] = $v;
            }
        }
    }
    return $out;
}

/** bin2hex with each byte's nibbles swapped — the `h` code's ordering. */
function __mc_bin2hex_low(string $raw): string
{
    $hi = \bin2hex($raw);
    $out = "";
    $n = \strlen($hi);
    $i = 0;
    while ($i + 1 < $n) {
        $out = $out . $hi[$i + 1] . $hi[$i];
        $i = $i + 2;
    }
    return $out;
}

/** hex2bin with each byte's nibbles swapped — the `h` code's ordering. */
function __mc_hex2bin_low(string $hex): string
{
    $sw = "";
    $n = \strlen($hex);
    $i = 0;
    while ($i + 1 < $n) {
        $sw = $sw . $hex[$i + 1] . $hex[$i];
        $i = $i + 2;
    }
    return \hex2bin($sw);
}

/** Byte width of one unpack code, or 0 when the code is unsupported. */
function __mc_unpack_size(string $code): int
{
    if ($code === "a" || $code === "A" || $code === "Z" || $code === "H" || $code === "h") { return 1; }
    if ($code === "c" || $code === "C" || $code === "x") { return 1; }
    if ($code === "s" || $code === "S" || $code === "v" || $code === "n") { return 2; }
    if ($code === "l" || $code === "L" || $code === "V" || $code === "N" || $code === "i" || $code === "I") { return 4; }
    if ($code === "q" || $code === "Q" || $code === "J" || $code === "P") { return 8; }
    return 0;
}

/** One fixed-width integer at `$pos`, decoded per `$code`. */
function __mc_unpack_int(string $s, int $pos, string $code): int
{
    if ($code === "c") {
        $b = \ord($s[$pos]);
        return $b > 127 ? $b - 256 : $b;
    }
    if ($code === "C") { return \ord($s[$pos]); }
    // Big-endian codes: n (16), N (32), J (64).
    $big = $code === "n" || $code === "N" || $code === "J";
    $size = __mc_unpack_size($code);
    $v = 0;
    $i = 0;
    while ($i < $size) {
        $b = \ord($s[$pos + $i]);
        if ($big) {
            $v = ($v << 8) | $b;
        } else {
            $v = $v | ($b << (8 * $i));
        }
        $i = $i + 1;
    }
    // Signed narrow codes sign-extend; the unsigned ones (S/v/L/V/Q/P and the
    // big-endian n/N/J) do not.
    if ($code === "s" && $v > 32767) { return $v - 65536; }
    if (($code === "l" || $code === "i") && $v > 2147483647) { return $v - 4294967296; }
    return $v;
}

/**
 * php's pack — the inverse of {@see unpack}, over the same code set.
 *
 * `a`/`A`/`Z` pad (NUL / space / NUL) or truncate to the given width, `H`/`h`
 * take a hex string, `x` emits a NUL byte, and the integer codes write their
 * fixed width in the endianness the code names. `*` means "as many as the
 * arguments supply" for the integer codes and "the argument's own length" for
 * the string ones. Float codes are unimplemented here, exactly as in unpack.
 */
function pack(string $format, mixed ...$values): string
{
    $out = "";
    $vi = 0;
    $n = \strlen($format);
    $i = 0;
    while ($i < $n) {
        $code = $format[$i];
        $i = $i + 1;
        $cnt = "";
        while ($i < $n && ($format[$i] === "*" || \ctype_digit($format[$i]))) {
            $cnt = $cnt . $format[$i];
            $i = $i + 1;
        }
        $star = $cnt === "*";
        if ($code === "x") {
            $rep = $star ? 1 : ($cnt === "" ? 1 : (int)$cnt);
            $k = 0;
            while ($k < $rep) { $out = $out . "\x00"; $k = $k + 1; }
            continue;
        }
        if ($code === "a" || $code === "A" || $code === "Z") {
            $v = (string)($values[$vi] ?? "");
            $vi = $vi + 1;
            $width = $star ? \strlen($v) + ($code === "Z" ? 1 : 0)
                : ($cnt === "" ? 1 : (int)$cnt);
            $pad = $code === "A" ? " " : "\x00";
            if (\strlen($v) >= $width) {
                $out = $out . \substr($v, 0, $width);
            } else {
                $out = $out . $v . \str_repeat($pad, $width - \strlen($v));
            }
            continue;
        }
        if ($code === "H" || $code === "h") {
            $v = (string)($values[$vi] ?? "");
            $vi = $vi + 1;
            $width = $star ? \strlen($v) : ($cnt === "" ? 1 : (int)$cnt);
            $hex = \substr($v, 0, $width);
            if (\strlen($hex) % 2 === 1) { $hex = $hex . "0"; }
            $out = $out . ($code === "H" ? \hex2bin($hex) : __mc_hex2bin_low($hex));
            continue;
        }
        if ($code === "X") {
            $back = $star ? 1 : ($cnt === "" ? 1 : (int)$cnt);
            $keep = \strlen($out) - $back;
            $out = $keep > 0 ? \substr($out, 0, $keep) : "";
            continue;
        }
        if ($code === "@") {
            $want = $cnt === "" ? 0 : (int)$cnt;
            $have = \strlen($out);
            if ($want > $have) { $out = $out . \str_repeat("\x00", $want - $have); }
            elseif ($want < $have) { $out = \substr($out, 0, $want); }
            continue;
        }
        $size = __mc_unpack_size($code);
        if ($size === 0) { return ""; }
        $rep = 1;
        if ($star) {
            $rep = \count($values) - $vi;
        } elseif ($cnt !== "") {
            $rep = (int)$cnt;
        }
        $k = 0;
        while ($k < $rep) {
            $v = (int)($values[$vi] ?? 0);
            $vi = $vi + 1;
            $out = $out . __mc_pack_int($v, $code, $size);
            $k = $k + 1;
        }
    }
    return $out;
}

/** One integer as `$size` bytes in the endianness `$code` names. */
function __mc_pack_int(int $v, string $code, int $size): string
{
    $big = $code === "n" || $code === "N" || $code === "J";
    $s = "";
    $i = 0;
    while ($i < $size) {
        $shift = $big ? (8 * ($size - 1 - $i)) : (8 * $i);
        $s = $s . \chr(($v >> $shift) & 255);
        $i = $i + 1;
    }
    return $s;
}
