<?php

// Zend's incremental inflate. The hex literals are streams php's zlib wrote
// (dynamic Huffman, sync flushes); they are fed here in chunks of every size.
// Expected output is php's.

$t = str_repeat("The quick brown fox jumps over the lazy dog. ", 40) . implode(",", range(1, 400));
$streams = [
    ZLIB_ENCODING_RAW => hex2bin('0ac94855282ccd4cce56482aca2fcf5348cbaf50c82acd2d2856c82f4b2d5228014ae72456552aa4e4a7eb29848c6cc500000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1490490100410cc20ce5d1d2dbbfb19d1500843822488a66580e37dc71e181275e78e3832f7ec8d0cb08054a54a8d1a0454718e1c4ab0c2289229a186289238d7452e45b4cb2c826875cf228a39c1215d4032aaaa9a1963ada68a745079df4e36d7ae8a58f31c61931c12453ccbb33cc32c71aebacd860932db6d9f776d9e38c734e5c70c915d7dc704fc66fe3e9b0e7c3c4070000ffff1cd2b161030110c3b0855c1ce324bfff6686dcb382d4113924c7e4a01c95c372ba2f9b6e70931bddec8637bdf1f10b603ff3d5310c6214c318c740463294b1ecbd217438e319d0888634a6418d6a58fbdd623ab2a18d6d70a31bdef80638c2fd6d5a1de4288739ce818e74a8631decfef7011def80473ce4310f7ad4c31ef79e9de5797d000000ffff1cd3b101c3000cc3b09bc47648fe7fac70774f023dd3cff633feac3ff3cffe03300243b0e7aa72476118c66120466228c662304663efe577fd0990473ce2118f78c4231ef18847bb50ddf188473ce2118f78c4a3ebf982fe17edee9abea8afeacbfababeb079c4231e7d2e7d773ce2118f78c4231ef188473cfade8fb8e3118f78c4231ef188473c7e000000ffff14d4b911030110c3b09a4c8d9febbf31637365182a1ebd2f263b1ef188473ce2118f78c4231e7dae3a3b1ef188473ce2118f78c4231e7d2f4f3b1ef188473ce2118f78c4231efdae633b1ef188473ce2118f78c4231e3d17fc152f791ee3311ee3311ee3311ee3311e7bdd35d8f1188ff1188ff1188ff1188ff158f721763cc6633cc6633cfe000000ffff1cd4c10d830000c4b0959052a0ecbf183efef9594a3cda69b69abde69b8d6ebbd96f369c1d67cbe1118f78c4a3dfaea4e3118f78c4231ef188473ce2d1b97de978c4231ef188473ce2118f7874ed733a1ef188473ce2118f78c4231edd1ba28e473ce2118f78c4231ef18847ff9d53c7231ef188473ce2118f78c4a3678bdd638f170000ffff0300'),
    ZLIB_ENCODING_DEFLATE => hex2bin('78da0ac94855282ccd4cce56482aca2fcf5348cbaf50c82acd2d2856c82f4b2d5228014ae72456552aa4e4a7eb29848c6cc500000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1490490100410cc20ce5d1d2dbbfb19d1500843822488a66580e37dc71e181275e78e3832f7ec8d0cb08054a54a8d1a0454718e1c4ab0c2289229a186289238d7452e45b4cb2c826875cf228a39c1215d4032aaaa9a1963ada68a745079df4e36d7ae8a58f31c61931c12453ccbb33cc32c71aebacd860932db6d9f776d9e38c734e5c70c915d7dc704fc66fe3e9b0e7c3c4070000ffff1cd2b161030110c3b0855c1ce324bfff6686dcb382d4113924c7e4a01c95c372ba2f9b6e70931bddec8637bdf1f10b603ff3d5310c6214c318c740463294b1ecbd217438e319d0888634a6418d6a58fbdd623ab2a18d6d70a31bdef80638c2fd6d5a1de4288739ce818e74a8631decfef7011def80473ce4310f7ad4c31ef79e9de5797d000000ffff1cd3b101c3000cc3b09bc47648fe7fac70774f023dd3cff633feac3ff3cffe03300243b0e7aa72476118c66120466228c662304663efe577fd0990473ce2118f78c4231ef18847bb50ddf188473ce2118f78c4a3ebf982fe17edee9abea8afeacbfababeb079c4231e7d2e7d773ce2118f78c4231ef188473cfade8fb8e3118f78c4231ef188473c7e000000ffff14d4b911030110c3b09a4c8d9febbf31637365182a1ebd2f263b1ef188473ce2118f78c4231e7dae3a3b1ef188473ce2118f78c4231e7d2f4f3b1ef188473ce2118f78c4231efdae633b1ef188473ce2118f78c4231e3d17fc152f791ee3311ee3311ee3311ee3311e7bdd35d8f1188ff1188ff1188ff1188ff158f721763cc6633cc6633cfe000000ffff1cd4c10d830000c4b0959052a0ecbf183efef9594a3cda69b69abde69b8d6ebbd96f369c1d67cbe1118f78c4a3dfaea4e3118f78c4231ef188473ce2d1b97de978c4231ef188473ce2118f7874ed733a1ef188473ce2118f78c4231edd1ba28e473ce2118f78c4231ef18847ff9d53c7231ef188473ce2118f78c4a3678bdd638f170000ffff0300c675a806'),
    ZLIB_ENCODING_GZIP => hex2bin('1f8b08000000000002130ac94855282ccd4cce56482aca2fcf5348cbaf50c82acd2d2856c82f4b2d5228014ae72456552aa4e4a7eb29848c6cc500000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1a554cbc6200000000ffff1490490100410cc20ce5d1d2dbbfb19d1500843822488a66580e37dc71e181275e78e3832f7ec8d0cb08054a54a8d1a0454718e1c4ab0c2289229a186289238d7452e45b4cb2c826875cf228a39c1215d4032aaaa9a1963ada68a745079df4e36d7ae8a58f31c61931c12453ccbb33cc32c71aebacd860932db6d9f776d9e38c734e5c70c915d7dc704fc66fe3e9b0e7c3c4070000ffff1cd2b161030110c3b0855c1ce324bfff6686dcb382d4113924c7e4a01c95c372ba2f9b6e70931bddec8637bdf1f10b603ff3d5310c6214c318c740463294b1ecbd217438e319d0888634a6418d6a58fbdd623ab2a18d6d70a31bdef80638c2fd6d5a1de4288739ce818e74a8631decfef7011def80473ce4310f7ad4c31ef79e9de5797d000000ffff1cd3b101c3000cc3b09bc47648fe7fac70774f023dd3cff633feac3ff3cffe03300243b0e7aa72476118c66120466228c662304663efe577fd0990473ce2118f78c4231ef18847bb50ddf188473ce2118f78c4a3ebf982fe17edee9abea8afeacbfababeb079c4231e7d2e7d773ce2118f78c4231ef188473cfade8fb8e3118f78c4231ef188473c7e000000ffff14d4b911030110c3b09a4c8d9febbf31637365182a1ebd2f263b1ef188473ce2118f78c4231e7dae3a3b1ef188473ce2118f78c4231e7d2f4f3b1ef188473ce2118f78c4231efdae633b1ef188473ce2118f78c4231e3d17fc152f791ee3311ee3311ee3311ee3311e7bdd35d8f1188ff1188ff1188ff1188ff158f721763cc6633cc6633cfe000000ffff1cd4c10d830000c4b0959052a0ecbf183efef9594a3cda69b69abde69b8d6ebbd96f369c1d67cbe1118f78c4a3dfaea4e3118f78c4231ef188473ce2d1b97de978c4231ef188473ce2118f7874ed733a1ef188473ce2118f78c4231edd1ba28e473ce2118f78c4231ef18847ff9d53c7231ef188473ce2118f78c4a3678bdd638f170000ffff0300e24a71c5db0c0000'),
];

foreach ($streams as $enc => $z) {
    foreach ([1, 7, 64, 100000] as $chunk) {
        $ctx = inflate_init($enc);
        $out = '';
        foreach (str_split($z, $chunk) as $p) {
            $r = inflate_add($ctx, $p, ZLIB_SYNC_FLUSH);
            if ($r === false) { $out = false; break; }
            $out .= $r;
        }
        echo "enc=$enc chunk=$chunk ", $out === $t ? 'ok' : 'BAD', " status=", inflate_get_status($ctx),
            " read=", inflate_get_read_len($ctx), "\n";
    }
}

// Our own stream, decoded here and by php alike.
$d = deflate_init(ZLIB_ENCODING_GZIP);
$ours = '';
foreach (str_split($t, 500) as $p) { $ours .= deflate_add($d, $p, ZLIB_NO_FLUSH); }
$ours .= deflate_add($d, '', ZLIB_FINISH);
$i = inflate_init(ZLIB_ENCODING_GZIP);
echo inflate_add($i, $ours, ZLIB_FINISH) === $t ? "ours ok\n" : "ours BAD\n";

// Two sync-flushed messages sharing one window (the permessage-deflate shape).
$m1 = hex2bin('f248cdc9c907000000ffff');
$m2 = hex2bin('f2001100000000ffff');
$i = inflate_init(ZLIB_ENCODING_RAW);
echo inflate_add($i, $m1, ZLIB_SYNC_FLUSH), '|', inflate_add($i, $m2, ZLIB_SYNC_FLUSH), "\n";
echo "status=", inflate_get_status($i), "\n";

// RFC 7692 §7.2.3.1: "Hello" compressed, the 00 00 ff ff tail stripped then re-appended.
$i = inflate_init(ZLIB_ENCODING_RAW);
echo inflate_add($i, "\xf2\x48\xcd\xc9\xc9\x07\x00" . "\x00\x00\xff\xff"), "\n";
// §7.2.3.2: the second "Hello" of a shared window.
echo inflate_add($i, "\xf2\x00\x11\x00\x00" . "\x00\x00\xff\xff"), "\n";

// dictionary
$i = inflate_init(ZLIB_ENCODING_RAW, ['dictionary' => 'quick brown fox']);
echo inflate_add($i, hex2bin('2bc948552844150200'), ZLIB_FINISH), "\n";

// corrupt data: false, then status
$i = inflate_init(ZLIB_ENCODING_RAW);
$r = @inflate_add($i, "\xff\xff\xff\xff", ZLIB_SYNC_FLUSH);
echo var_export($r, true), " status=", inflate_get_status($i), "\n";

// truncated gzip then the rest
$i = inflate_init(ZLIB_ENCODING_GZIP);
$g = gzencode('abc');
echo var_export(inflate_add($i, substr($g, 0, 5)), true), "\n";
echo var_export(inflate_add($i, substr($g, 5)), true), " status=", inflate_get_status($i), "\n";

// errors
foreach ([fn() => inflate_init(3), fn() => inflate_init(ZLIB_ENCODING_RAW, ['window' => 20]),
          fn() => inflate_add(inflate_init(ZLIB_ENCODING_RAW), 'x', 77)] as $f) {
    try { $f(); echo "no error\n"; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
// The edges zlib and php define between them. A read length at a corrupt
// container is where zlib judged the header or the trailer; one inside a
// block would depend on zlib's fast path, so none is printed.
// The streams are php's, as hex: the read lengths are positions in them.
$phpGzip = hex2bin('1f8b08000000000000134b4c4a0600c241243503000000');
$phpZlib = hex2bin('789c4b4c4a0600024d0127');

function show(string $label, InflateContext $ctx, string $data, int $flush): void
{
    $r = @inflate_add($ctx, $data, $flush);
    echo $label, ': ', var_export($r, true), ' status=', inflate_get_status($ctx),
        ' read=', inflate_get_read_len($ctx), "\n";
}

// Output is every symbol whose bits have arrived, not every finished block.
$g = $phpGzip;
$i = inflate_init(ZLIB_ENCODING_GZIP);
show('mid-block', $i, substr($g, 0, 12), ZLIB_FINISH);
show('rest', $i, substr($g, 12), ZLIB_FINISH);

// A finished stream resets on the next call; what followed it is dropped.
$i = inflate_init(ZLIB_ENCODING_GZIP);
show('trailing', $i, $g . 'zzz', ZLIB_SYNC_FLUSH);
show('next stream', $i, $g, ZLIB_SYNC_FLUSH);
show('empty', $i, '', ZLIB_SYNC_FLUSH);
show('empty finish', $i, '', ZLIB_FINISH);

// ZLIB_BLOCK stops at a block boundary and drops the rest of its input.
$z = hex2bin('cacf4b05000000ffff2a29cf07000000ffff2bc9284a4d0500'); // one|two|three, sync-flushed
$i = inflate_init(ZLIB_ENCODING_RAW);
show('block', $i, $z, ZLIB_BLOCK);
$i = inflate_init(ZLIB_ENCODING_RAW);
show('finish', $i, $z, ZLIB_FINISH);

// Output that exactly fills php's 8 KiB buffer: php asks zlib once more, and
// a call that reads nothing is Z_BUF_ERROR.
foreach ([8191, 8192, 8193] as $n) {
    $d = deflate_init(ZLIB_ENCODING_RAW);
    $z = deflate_add($d, str_repeat('a', $n), ZLIB_SYNC_FLUSH);
    $i = inflate_init(ZLIB_ENCODING_RAW);
    $r = inflate_add($i, $z, ZLIB_SYNC_FLUSH);
    echo "fill $n: ", strlen($r), ' status=', inflate_get_status($i), "\n";
}

// A zlib header asking for a preset dictionary (FDICT).
$z = hex2bin('78bb1a0b045dcb403015c06c003b200691'); // 'hello world hello' over dictionary 'hello world'
show('fdict none', inflate_init(ZLIB_ENCODING_DEFLATE), $z, ZLIB_SYNC_FLUSH);
$i = inflate_init(ZLIB_ENCODING_DEFLATE, ['dictionary' => 'hello worle']);
show('fdict wrong', $i, $z, ZLIB_SYNC_FLUSH);
show('fdict wrong again', $i, $z, ZLIB_SYNC_FLUSH);
$i = inflate_init(ZLIB_ENCODING_DEFLATE, ['dictionary' => 'hello world']);
show('fdict ok', $i, $z, ZLIB_SYNC_FLUSH);
show('fdict second stream', $i, $z, ZLIB_SYNC_FLUSH);
$i = inflate_init(ZLIB_ENCODING_DEFLATE, ['dictionary' => 'hello world']);
foreach (str_split($z) as $k => $b) { show("fdict byte $k", $i, $b, ZLIB_SYNC_FLUSH); }
show('dictionary unused', inflate_init(ZLIB_ENCODING_DEFLATE, ['dictionary' => 'xx']), $phpZlib, ZLIB_SYNC_FLUSH);

// A zlib header naming a window larger than the inflater's is corrupt.
$z = hex2bin('1895cb48cdc9c90700062c0215'); // 'hello', CMF window 9
foreach ([8, 9, 15] as $w) { show("zlib window9 into $w", inflate_init(ZLIB_ENCODING_DEFLATE, ['window' => $w]), $z, ZLIB_FINISH); }
show('gzip window 8', inflate_init(ZLIB_ENCODING_GZIP, ['window' => 8]), hex2bin('1f8b0800000000000013cb48cdc9c9070086a6103605000000'), ZLIB_FINISH);

// A corrupt container: bad magic, method, flags, checks.
$z = $phpZlib;
show('zlib bad check', inflate_init(ZLIB_ENCODING_DEFLATE), "\x78\x9d" . substr($z, 2), ZLIB_SYNC_FLUSH);
$b = $z; $b[strlen($b) - 1] = chr(ord($b[strlen($b) - 1]) ^ 1);
show('zlib bad adler', inflate_init(ZLIB_ENCODING_DEFLATE), $b, ZLIB_SYNC_FLUSH);
show('gzip bad magic', inflate_init(ZLIB_ENCODING_GZIP), "\x1f\x8c" . substr($g, 2), ZLIB_SYNC_FLUSH);
show('gzip bad flags', inflate_init(ZLIB_ENCODING_GZIP), "\x1f\x8b\x08\xe0" . substr($g, 4), ZLIB_SYNC_FLUSH);
$b = $g; $b[strlen($b) - 5] = chr(ord($b[strlen($b) - 5]) ^ 1);
$i = inflate_init(ZLIB_ENCODING_GZIP);
show('gzip bad crc', $i, $b, ZLIB_SYNC_FLUSH);
show('after corrupt', $i, $g, ZLIB_SYNC_FLUSH);
$b = $g; $b[strlen($b) - 1] = "\x01";
show('gzip bad length', inflate_init(ZLIB_ENCODING_GZIP), $b, ZLIB_SYNC_FLUSH);
