<?php

// getimagesize and friends. These live in ext/standard, NOT in GD — php answers
// them with no image library loaded at all, because every one of them is a
// HEADER read. That is why they are here and the `image*` drawing family is not:
// nothing below opens a pixel, and none of it needs a dependency.
//
// Formats: PNG, JPEG, GIF, BMP, WEBP (all three chunk kinds), ICO and TIFF.
// Anything else answers false, which is also php's answer for a blob it cannot
// place. AVIF is the deliberate omission — its size lives inside an ISO-BMFF
// box tree, which is a parser rather than a header read.

/** A big-endian unsigned 16-bit word at $off. */
function __mc_img_be16(string $d, int $off): int
{
    return (\ord($d[$off]) << 8) | \ord($d[$off + 1]);
}

/** A little-endian unsigned 16-bit word at $off. */
function __mc_img_le16(string $d, int $off): int
{
    return \ord($d[$off]) | (\ord($d[$off + 1]) << 8);
}

/** A big-endian unsigned 32-bit word at $off. */
function __mc_img_be32(string $d, int $off): int
{
    return (\ord($d[$off]) << 24) | (\ord($d[$off + 1]) << 16)
         | (\ord($d[$off + 2]) << 8) | \ord($d[$off + 3]);
}

/** A little-endian unsigned 32-bit word at $off. */
function __mc_img_le32(string $d, int $off): int
{
    return \ord($d[$off]) | (\ord($d[$off + 1]) << 8)
         | (\ord($d[$off + 2]) << 16) | (\ord($d[$off + 3]) << 24);
}

/**
 * The one probe every entry point shares: `[width, height, IMAGETYPE_*, bits,
 * channels]`, or `[]` when the blob is not a picture. `bits` and `channels` are
 * -1 when the format does not carry them — php OMITS the key entirely in that
 * case (a TIFF has neither, a BMP has bits but no channels), and the callers
 * below reproduce that rather than filling in a zero.
 *
 * @return int[]
 */
function __mc_img_info(string $d): array
{
    $n = \strlen($d);

    // PNG — the IHDR is always the first chunk, at a fixed offset.
    if ($n >= 25 && \substr($d, 0, 8) === "\x89PNG\r\n\x1a\n" && \substr($d, 12, 4) === 'IHDR') {
        return [\__mc_img_be32($d, 16), \__mc_img_be32($d, 20), 3, \ord($d[24]), -1];
    }

    // GIF — the logical screen descriptor. `bits` is the global colour table
    // depth, which is the low three bits of the packed field plus one.
    if ($n >= 11 && (\substr($d, 0, 6) === 'GIF87a' || \substr($d, 0, 6) === 'GIF89a')) {
        return [\__mc_img_le16($d, 6), \__mc_img_le16($d, 8), 1, (\ord($d[10]) & 0x07) + 1, 3];
    }

    if ($n >= 4 && \ord($d[0]) === 0xFF && \ord($d[1]) === 0xD8) {
        return \__mc_img_jpeg($d);
    }

    // BMP — two header shapes. The CORE header is 12 bytes with unsigned 16-bit
    // dimensions; anything larger is an INFO header with signed 32-bit ones, and
    // a negative height means top-down rows, not a negative picture.
    if ($n >= 26 && \substr($d, 0, 2) === 'BM') {
        $hdr = \__mc_img_le32($d, 14);
        if ($hdr === 12) {
            return [\__mc_img_le16($d, 18), \__mc_img_le16($d, 20), 6, \__mc_img_le16($d, 24), -1];
        }
        if ($hdr >= 40 && $n >= 30) {
            $w = \__mc_img_le32($d, 18);
            $h = \__mc_img_le32($d, 22);
            if ($w > 0x7FFFFFFF) { $w = $w - 0x100000000; }
            if ($h > 0x7FFFFFFF) { $h = $h - 0x100000000; }
            return [\abs($w), \abs($h), 6, \__mc_img_le16($d, 28), -1];
        }
        return [];
    }

    if ($n >= 16 && \substr($d, 0, 4) === 'RIFF' && \substr($d, 8, 4) === 'WEBP') {
        return \__mc_img_webp($d);
    }

    // ICO — a directory, not one picture. php reports the BEST entry.
    if ($n >= 6 && \substr($d, 0, 4) === "\x00\x00\x01\x00") {
        return \__mc_img_ico($d);
    }

    if ($n >= 8 && (\substr($d, 0, 4) === "II\x2a\x00" || \substr($d, 0, 4) === "MM\x00\x2a")) {
        return \__mc_img_tiff($d);
    }

    return [];
}

/**
 * JPEG: walk the marker chain to the first frame header. Only SOF carries the
 * size, and which SOF it is says nothing about the dimensions — C4 (Huffman
 * tables), C8 (reserved) and CC (arithmetic conditioning) share the range and
 * are NOT frame headers, which is the whole subtlety of this loop.
 *
 * @return int[]
 */
function __mc_img_jpeg(string $d): array
{
    $n = \strlen($d);
    $p = 2;
    while ($p + 3 < $n) {
        if (\ord($d[$p]) !== 0xFF) {
            $p = $p + 1;
            continue;
        }
        $m = \ord($d[$p + 1]);
        if ($m === 0xFF) { $p = $p + 1; continue; }          // fill bytes
        if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD9)) {     // no payload
            $p = $p + 2;
            continue;
        }
        $len = \__mc_img_be16($d, $p + 2);
        if ($len < 2) { return []; }
        $isSof = ($m >= 0xC0 && $m <= 0xCF) && $m !== 0xC4 && $m !== 0xC8 && $m !== 0xCC;
        if ($isSof) {
            if ($p + 9 >= $n) { return []; }
            return [\__mc_img_be16($d, $p + 7), \__mc_img_be16($d, $p + 5), 2,
                    \ord($d[$p + 4]), \ord($d[$p + 9])];
        }
        if ($m === 0xDA) { return []; }                      // scan data: no SOF
        $p = $p + 2 + $len;
    }

    return [];
}

/**
 * WEBP: three chunk kinds carry the size three different ways. VP8 is the lossy
 * keyframe (a 3-byte sync code then 14-bit fields), VP8L the lossless bitstream
 * (28 bits holding both dimensions MINUS ONE), VP8X the extended header (two
 * 24-bit canvas sizes, also minus one).
 *
 * @return int[]
 */
function __mc_img_webp(string $d): array
{
    $n = \strlen($d);
    $c = \substr($d, 12, 4);
    if ($c === 'VP8 ' && $n >= 30) {
        if (\ord($d[23]) !== 0x9D || \ord($d[24]) !== 0x01 || \ord($d[25]) !== 0x2A) { return []; }
        return [\__mc_img_le16($d, 26) & 0x3FFF, \__mc_img_le16($d, 28) & 0x3FFF, 18, 8, -1];
    }
    if ($c === 'VP8L' && $n >= 25) {
        if (\ord($d[20]) !== 0x2F) { return []; }
        $b = \__mc_img_le32($d, 21);
        return [($b & 0x3FFF) + 1, (($b >> 14) & 0x3FFF) + 1, 18, 8, -1];
    }
    if ($c === 'VP8X' && $n >= 30) {
        $w = \ord($d[24]) | (\ord($d[25]) << 8) | (\ord($d[26]) << 16);
        $h = \ord($d[27]) | (\ord($d[28]) << 8) | (\ord($d[29]) << 16);
        return [$w + 1, $h + 1, 18, 8, -1];
    }

    return [];
}

/**
 * ICO: php reports the entry with the most BITS, and among equals the widest;
 * a full tie keeps the first. A zero in the width or height byte means 256 —
 * the field is one byte and 256 is the format's maximum, so it wrapped.
 *
 * @return int[]
 */
function __mc_img_ico(string $d): array
{
    $n = \strlen($d);
    $count = \__mc_img_le16($d, 4);
    if ($count < 1) { return []; }
    $bw = 0;
    $bh = 0;
    $bb = -1;
    for ($i = 0; $i < $count; $i = $i + 1) {
        $e = 6 + $i * 16;
        if ($e + 16 > $n) { break; }
        $w = \ord($d[$e]);
        $h = \ord($d[$e + 1]);
        if ($w === 0) { $w = 256; }
        if ($h === 0) { $h = 256; }
        $bits = \__mc_img_le16($d, $e + 6);
        if ($bits > $bb || ($bits === $bb && $w > $bw)) {
            $bw = $w;
            $bh = $h;
            $bb = $bits;
        }
    }
    if ($bb < 0) { return []; }

    return [$bw, $bh, 17, $bb, -1];
}

/**
 * TIFF: the size lives in IFD tags 256 and 257, so this is the one format here
 * that needs a directory walk. Both byte orders, and both the SHORT and LONG
 * value types the tags are allowed to use. php reports NO `bits` for a TIFF.
 *
 * @return int[]
 */
function __mc_img_tiff(string $d): array
{
    $n = \strlen($d);
    $le = $d[0] === 'I';
    $ifd = $le ? \__mc_img_le32($d, 4) : \__mc_img_be32($d, 4);
    if ($ifd + 2 > $n) { return []; }
    $entries = $le ? \__mc_img_le16($d, $ifd) : \__mc_img_be16($d, $ifd);
    $w = -1;
    $h = -1;
    for ($i = 0; $i < $entries; $i = $i + 1) {
        $e = $ifd + 2 + $i * 12;
        if ($e + 12 > $n) { break; }
        $tag = $le ? \__mc_img_le16($d, $e) : \__mc_img_be16($d, $e);
        if ($tag !== 256 && $tag !== 257) { continue; }
        $type = $le ? \__mc_img_le16($d, $e + 2) : \__mc_img_be16($d, $e + 2);
        // The value sits inline in the last four bytes; a SHORT occupies the
        // FIRST two of them, which is where a big-endian file differs.
        if ($type === 3) {
            $v = $le ? \__mc_img_le16($d, $e + 8) : \__mc_img_be16($d, $e + 8);
        } else {
            $v = $le ? \__mc_img_le32($d, $e + 8) : \__mc_img_be32($d, $e + 8);
        }
        if ($tag === 256) { $w = $v; } else { $h = $v; }
    }
    if ($w < 0 || $h < 0) { return []; }

    return [$w, $h, $le ? 7 : 8, -1, -1];
}

/** The mime type php reports for an IMAGETYPE_* constant. */
function image_type_to_mime_type(int $image_type): string
{
    if ($image_type === 1) { return 'image/gif'; }
    if ($image_type === 2) { return 'image/jpeg'; }
    if ($image_type === 3) { return 'image/png'; }
    if ($image_type === 4) { return 'application/x-shockwave-flash'; }
    if ($image_type === 5) { return 'image/psd'; }
    if ($image_type === 6) { return 'image/bmp'; }
    if ($image_type === 7 || $image_type === 8) { return 'image/tiff'; }
    if ($image_type === 9 || $image_type === 10 || $image_type === 11) { return 'application/octet-stream'; }
    if ($image_type === 12) { return 'image/jb2'; }
    if ($image_type === 13) { return 'application/x-shockwave-flash'; }
    if ($image_type === 14) { return 'image/iff'; }
    if ($image_type === 15) { return 'image/vnd.wap.wbmp'; }
    if ($image_type === 16) { return 'image/xbm'; }
    if ($image_type === 17) { return 'image/vnd.microsoft.icon'; }
    if ($image_type === 18) { return 'image/webp'; }
    if ($image_type === 19) { return 'image/avif'; }

    return 'application/octet-stream';
}

/**
 * The canonical file extension for an IMAGETYPE_*, with the dot unless asked
 * otherwise. False for a constant that has none.
 *
 * @return string|false
 */
function image_type_to_extension(int $image_type, bool $include_dot = true)
{
    $e = '';
    if ($image_type === 1) { $e = 'gif'; }
    elseif ($image_type === 2) { $e = 'jpeg'; }
    elseif ($image_type === 3) { $e = 'png'; }
    elseif ($image_type === 4) { $e = 'swf'; }
    elseif ($image_type === 5) { $e = 'psd'; }
    elseif ($image_type === 6) { $e = 'bmp'; }
    elseif ($image_type === 7 || $image_type === 8) { $e = 'tiff'; }
    elseif ($image_type === 9) { $e = 'jpc'; }
    elseif ($image_type === 10) { $e = 'jp2'; }
    elseif ($image_type === 11) { $e = 'jpx'; }
    elseif ($image_type === 12) { $e = 'jb2'; }
    elseif ($image_type === 13) { $e = 'swc'; }
    elseif ($image_type === 14) { $e = 'iff'; }
    elseif ($image_type === 15) { $e = 'wbmp'; }
    elseif ($image_type === 16) { $e = 'xbm'; }
    elseif ($image_type === 17) { $e = 'ico'; }
    elseif ($image_type === 18) { $e = 'webp'; }
    elseif ($image_type === 19) { $e = 'avif'; }
    if ($e === '') { return false; }

    return $include_dot ? '.' . $e : $e;
}

/**
 * Shape the probe into php's array. The key ORDER is php's and is observable
 * through print_r: the four numeric slots, then bits, then channels, then mime
 * and the two 8.5 unit keys. A format that carries no bits or no channels omits
 * that key rather than reporting zero.
 *
 * @param int[] $info
 * @return array<int|string,mixed>
 */
function __mc_img_shape(array $info): array
{
    $out = [];
    $out[0] = $info[0];
    $out[1] = $info[1];
    $out[2] = $info[2];
    $out[3] = 'width="' . $info[0] . '" height="' . $info[1] . '"';
    if ($info[3] >= 0) { $out['bits'] = $info[3]; }
    if ($info[4] >= 0) { $out['channels'] = $info[4]; }
    $out['mime'] = \image_type_to_mime_type($info[2]);
    $out['width_unit'] = 'px';
    $out['height_unit'] = 'px';

    return $out;
}

/**
 * `getimagesizefromstring` — the same probe over a blob already in memory.
 * $image_info collects the JPEG APPn segments, which is what iptcparse and the
 * EXIF readers are handed; a format without them leaves it empty.
 *
 * @param array<string,string> $image_info
 * @return array<int|string,mixed>|false
 */
function getimagesizefromstring(string $string, #[\Manticore\Attr\RefOut] array &$image_info = [])
{
    $image_info = \__mc_img_app_markers($string);
    $info = \__mc_img_info($string);
    if (\count($info) === 0) { return false; }

    return \__mc_img_shape($info);
}

/**
 * `getimagesize` — the file form. php WARNS and returns false when the file
 * cannot be read; this build returns false silently, the documented
 * no-warnings divergence.
 *
 * @param array<string,string> $image_info
 * @return array<int|string,mixed>|false
 */
function getimagesize(string $filename, #[\Manticore\Attr\RefOut] array &$image_info = [])
{
    $image_info = [];
    if (!\is_file($filename)) { return false; }
    $d = \file_get_contents($filename);
    if ($d === false) { return false; }
    $image_info = \__mc_img_app_markers($d);
    $info = \__mc_img_info($d);
    if (\count($info) === 0) { return false; }

    return \__mc_img_shape($info);
}

/**
 * The JPEG APPn payloads, keyed "APP0".."APP15" as php keys them. Repeated
 * markers CONCATENATE, which is how a JPEG carries an EXIF or Photoshop block
 * too large for one segment.
 *
 * @return array<string,string>
 */
function __mc_img_app_markers(string $d): array
{
    /** @var array<string,string> $out */
    $out = [];
    $n = \strlen($d);
    if ($n < 4 || \ord($d[0]) !== 0xFF || \ord($d[1]) !== 0xD8) { return $out; }
    $p = 2;
    while ($p + 3 < $n) {
        if (\ord($d[$p]) !== 0xFF) { $p = $p + 1; continue; }
        $m = \ord($d[$p + 1]);
        if ($m === 0xFF) { $p = $p + 1; continue; }
        if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD9)) { $p = $p + 2; continue; }
        $len = \__mc_img_be16($d, $p + 2);
        if ($len < 2) { return $out; }
        if ($m >= 0xE0 && $m <= 0xEF) {
            $k = 'APP' . ($m - 0xE0);
            $body = \substr($d, $p + 4, $len - 2);
            $out[$k] = isset($out[$k]) ? $out[$k] . $body : $body;
        }
        if ($m === 0xDA) { return $out; }
        $p = $p + 2 + $len;
    }

    return $out;
}
