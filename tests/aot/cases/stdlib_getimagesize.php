<?php

// getimagesize / getimagesizefromstring. These are ext/standard, not GD: php
// answers them with no image library loaded, because every one is a HEADER
// read. Each blob below is built byte by byte so the case carries no fixtures.

function png(int $w, int $h, int $depth): string
{
    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', $w) . pack('N', $h)
         . chr($depth) . "\x02\x00\x00\x00" . pack('N', 0);
}
function gif(string $sig, int $w, int $h, int $flags): string
{
    return $sig . pack('v', $w) . pack('v', $h) . chr($flags) . "\x00\x00";
}
function jpeg(int $w, int $h, int $prec, int $comps, string $marker): string
{
    $sof = "\xff" . chr($comps === 0 ? 0xC0 : 0xC0) . pack('n', 8 + 3 * $comps) . chr($prec)
         . pack('n', $h) . pack('n', $w) . chr($comps) . str_repeat("\x01\x11\x00", $comps);
    return "\xff\xd8" . $marker . $sof . "\xff\xd9";
}
function app(int $n, string $body): string
{
    return "\xff" . chr(0xE0 + $n) . pack('n', strlen($body) + 2) . $body;
}
function bmpInfo(int $w, int $h, int $bits): string
{
    return 'BM' . pack('V', 100) . pack('v', 0) . pack('v', 0) . pack('V', 54)
         . pack('V', 40) . pack('V', $w) . pack('V', $h) . pack('v', 1) . pack('v', $bits)
         . str_repeat("\x00", 24);
}
function ico(array $entries): string
{
    $b = pack('v', 0) . pack('v', 1) . pack('v', count($entries));
    foreach ($entries as $e) {
        $b .= chr($e[0]) . chr($e[1]) . "\x00\x00" . pack('v', 1) . pack('v', $e[2])
            . pack('V', 40) . pack('V', 100);
    }
    return $b . str_repeat("\x00", 200);
}
function tiff(bool $le, int $w, int $h): string
{
    if ($le) {
        $ifd = pack('v', 2)
             . pack('v', 256) . pack('v', 3) . pack('V', 1) . pack('V', $w)
             . pack('v', 257) . pack('v', 3) . pack('V', 1) . pack('V', $h)
             . pack('V', 0);
        return "II\x2a\x00" . pack('V', 8) . $ifd;
    }
    $ifd = pack('n', 2)
         . pack('n', 256) . pack('n', 3) . pack('N', 1) . pack('n', $w) . pack('n', 0)
         . pack('n', 257) . pack('n', 3) . pack('N', 1) . pack('n', $h) . pack('n', 0)
         . pack('N', 0);
    return "MM\x00\x2a" . pack('N', 8) . $ifd;
}

function webp(string $chunk): string
{
    return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk . str_repeat("\x00", 8);
}
function vp8(int $w, int $h): string
{
    return 'VP8 ' . pack('V', 20) . "\x00\x00\x00" . "\x9d\x01\x2a"
         . pack('v', $w) . pack('v', $h) . str_repeat("\x00", 8);
}
function vp8l(int $w, int $h): string
{
    $b = ($w - 1) | (($h - 1) << 14);
    return 'VP8L' . pack('V', 12) . "\x2f" . pack('V', $b) . str_repeat("\x00", 8);
}
function vp8x(int $w, int $h): string
{
    return 'VP8X' . pack('V', 10) . "\x00\x00\x00\x00"
         . substr(pack('V', $w - 1), 0, 3) . substr(pack('V', $h - 1), 0, 3);
}

// Every format, through the in-memory entry point.
print_r(getimagesizefromstring(png(10, 5, 8)));
print_r(getimagesizefromstring(png(1, 1, 16)));
print_r(getimagesizefromstring(gif('GIF87a', 10, 5, 0x80)));
print_r(getimagesizefromstring(gif('GIF89a', 640, 480, 0xF7)));
print_r(getimagesizefromstring(jpeg(10, 5, 8, 3, '')));
print_r(getimagesizefromstring(jpeg(1920, 1080, 8, 1, '')));
print_r(getimagesizefromstring(bmpInfo(10, 5, 24)));
print_r(getimagesizefromstring(ico([[16, 16, 8], [32, 32, 32]])));
print_r(getimagesizefromstring(ico([[16, 16, 8], [48, 48, 8]])));
print_r(getimagesizefromstring(ico([[0, 0, 8]])));
print_r(getimagesizefromstring(tiff(true, 10, 5)));
print_r(getimagesizefromstring(tiff(false, 7, 9)));
print_r(getimagesizefromstring(webp(vp8(10, 5))));
print_r(getimagesizefromstring(webp(vp8l(100, 200))));
print_r(getimagesizefromstring(webp(vp8x(1000, 2000))));
var_dump(getimagesizefromstring(webp('XXXX' . pack('V', 4) . 'abcd')));

// A JPEG whose APP segments come back through the by-ref parameter.
$j = jpeg(4, 4, 8, 3, app(0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00") . app(13, 'photoshop'));
$size = getimagesizefromstring($j, $info);
echo $size[0], 'x', $size[1], ' apps=', implode(',', array_keys($info)), ' app13=', $info['APP13'], "\n";

// A marker with no payload, and a SOF that never comes.
var_dump(getimagesizefromstring("\xff\xd8\xff\xd9"));
var_dump(getimagesizefromstring('not an image at all'));
var_dump(getimagesizefromstring(''));
var_dump(getimagesizefromstring("\x89PNG\r\n\x1a\n"));

// The file entry point, and a file that is not there.
$p = tempnam(sys_get_temp_dir(), 'img');
file_put_contents($p, png(24, 42, 8));
$r = getimagesize($p);
echo $r[0], 'x', $r[1], ' ', $r['mime'], ' ', $r[3], "\n";
unlink($p);
var_dump(getimagesize($p));

echo image_type_to_mime_type(IMAGETYPE_WEBP), ' ', image_type_to_mime_type(IMAGETYPE_ICO), "\n";
echo image_type_to_extension(IMAGETYPE_JPEG), ' ', image_type_to_extension(IMAGETYPE_JPEG, false), "\n";
var_dump(image_type_to_extension(IMAGETYPE_UNKNOWN));
echo IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, ' ', IMAGETYPE_BMP, ' ', IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM, ' ', IMAGETYPE_ICO, ' ', IMAGETYPE_WEBP, "\n";
