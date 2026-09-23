<?php

// MANTICORE-ONLY; expected output written by hand from RFC 6455.
// §1.3 accept key, §5.7 frame examples byte for byte, the three length forms,
// and a parser fed one byte at a time.

use Http\WebSocket as WS;

echo WS\acceptKey('dGhlIHNhbXBsZSBub25jZQ=='), "\n";

// §5.7
echo bin2hex(WS\encodeFrame(WS\Opcode::TEXT, 'Hello', true, false, '')), "\n";
echo bin2hex(WS\encodeFrame(WS\Opcode::TEXT, 'Hello', true, false, "\x37\xfa\x21\x3d")), "\n";
echo bin2hex(WS\encodeFrame(WS\Opcode::TEXT, 'Hel', false, false, '')), ' ',
     bin2hex(WS\encodeFrame(WS\Opcode::CONT, 'lo', true, false, '')), "\n";
echo bin2hex(WS\encodeFrame(WS\Opcode::PING, 'Hello', true, false, '')), "\n";
echo bin2hex(WS\encodeFrame(WS\Opcode::PONG, 'Hello', true, false, "\x37\xfa\x21\x3d")), "\n";
echo bin2hex(substr(WS\encodeFrame(WS\Opcode::BINARY, str_repeat("\0", 256), true, false, ''), 0, 4)), "\n";
echo bin2hex(substr(WS\encodeFrame(WS\Opcode::BINARY, str_repeat("\0", 65536), true, false, ''), 0, 10)), "\n";
echo bin2hex(substr(WS\encodeFrame(WS\Opcode::BINARY, 'x', true, true, ''), 0, 1)), "\n";

// Parse: every frame above, fed one byte at a time, both roles.
function feed(string $wire, bool $expectMasked, int $max = 1 << 20): string
{
    $b = new \Buffer\ByteBuffer();
    $p = new WS\FrameParser($b, $expectMasked, $max);
    $out = [];
    for ($i = 0; $i < strlen($wire); $i++) {
        $b->append($wire[$i]);
        while (($r = $p->parse()) !== WS\FrameParser::NEED) {
            if ($r !== WS\FrameParser::FRAME) { $out[] = 'err' . $r; return implode(' ', $out); }
            $out[] = $p->opcode . ($p->fin ? 'F' : '-') . ':' . (strlen($p->payload) > 16 ? strlen($p->payload) . 'B' : $p->payload);
        }
    }
    return implode(' ', $out);
}
$m = "\x37\xfa\x21\x3d";
echo feed(WS\encodeFrame(1, 'Hello', true, false, $m) . WS\encodeFrame(9, 'p', true, false, $m)
    . WS\encodeFrame(2, str_repeat('z', 300), true, false, $m) . WS\encodeFrame(2, str_repeat('y', 70000), true, false, $m), true), "\n";
echo feed(WS\encodeFrame(1, 'Hel', false, false, '') . WS\encodeFrame(0, 'lo', true, false, ''), false), "\n";

// Protocol errors the parser owns.
echo feed(WS\encodeFrame(1, 'x', true, false, ''), true), "\n";           // server got unmasked
echo feed(WS\encodeFrame(1, 'x', true, false, $m), false), "\n";          // client got masked
echo feed("\x83\x00", false), "\n";                                       // opcode 3
echo feed("\x09\x00", false), "\n";                                       // control not FIN
echo feed("\x89\x7e\x00\x7e" . str_repeat('a', 126), false), "\n";        // control > 125
echo feed("\xc1\x00", false), "\n";                                       // RSV1 not negotiated
echo feed("\xa1\x00", false), "\n";                                       // RSV2
echo feed(WS\encodeFrame(2, str_repeat('a', 100), true, false, ''), false, 64), "\n"; // > maxFrame
echo feed("\x82\x7f\x80\x00\x00\x00\x00\x00\x00\x00", false), "\n";       // 64-bit length MSB set

// Masking round trip over a long payload (the fast XOR path).
$data = str_repeat("0123456789abcdef", 4097) . 'xyz';
echo WS\applyMask(WS\applyMask($data, $m), $m) === $data ? "mask ok\n" : "mask BAD\n";
echo bin2hex(WS\applyMask('Hello', $m)), "\n";

// Close codes and UTF-8.
foreach ([999, 1000, 1001, 1003, 1004, 1005, 1006, 1007, 1011, 1012, 1014, 1015, 1016, 2999, 3000, 4999, 5000] as $c) {
    echo $c, WS\closeCodeOk($c) ? '+' : '-', ' ';
}
echo "\n";
echo WS\utf8Ok("h\xc3\xa9llo") ? 'y' : 'n', WS\utf8Ok("\xce\xba\xe1\xbd\xb9\xcf\x83\xce\xbc\xce\xb5") ? 'y' : 'n',
     WS\utf8Ok("\xc0\xaf") ? 'y' : 'n', WS\utf8Ok("\xed\xa0\x80") ? 'y' : 'n', WS\utf8Ok('') ? 'y' : 'n', "\n";
