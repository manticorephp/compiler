<?php
// Http\WebSocket — RFC 6455 over Http\Server (upgrade) and as a client.
// DEMAND-GATED on the `WebSocket` qualifier; rides Http\ (header parsing, the
// takeover hook) and Buffer\ByteBuffer. Superset feature: no Zend oracle — see
// docs/websocket.md and tests/aot/cases/ws_*.

namespace Http\WebSocket {

final class Opcode
{
    public const CONT = 0;
    public const TEXT = 1;
    public const BINARY = 2;
    public const CLOSE = 8;
    public const PING = 9;
    public const PONG = 10;
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
}

/** Sec-WebSocket-Accept for a client's Sec-WebSocket-Key (RFC 6455 §4.2.2). */
function acceptKey(string $key): string
{
    return \base64_encode(\sha1($key . Opcode::GUID, true));
}

/** XOR $data with the 4-byte $mask repeated — one string op, never a byte loop. */
function applyMask(string $data, string $mask): string
{
    $n = \strlen($data);
    if ($n === 0) {
        return '';
    }
    return $data ^ \substr(\str_repeat($mask, ($n >> 2) + 1), 0, $n);
}

/** One frame. `$mask === ''` sends it unmasked (server → client). */
function encodeFrame(int $opcode, string $payload, bool $fin, bool $rsv1, string $mask): string
{
    $n = \strlen($payload);
    $b0 = ($fin ? 0x80 : 0) | ($rsv1 ? 0x40 : 0) | $opcode;
    $mb = $mask === '' ? 0 : 0x80;
    if ($n < 126) {
        $head = \chr($b0) . \chr($mb | $n);
    } elseif ($n < 65536) {
        $head = \chr($b0) . \chr($mb | 126) . \chr($n >> 8) . \chr($n & 0xFF);
    } else {
        $head = \chr($b0) . \chr($mb | 127);
        for ($s = 56; $s >= 0; $s -= 8) {
            $head .= \chr(($n >> $s) & 0xFF);
        }
    }
    if ($mask === '') {
        return $head . $payload;
    }
    return $head . $mask . applyMask($payload, $mask);
}

/**
 * A close code a peer may put on the wire (§7.4): the defined 1000–1003 and
 * 1007–1014, and the registered/private 3000–4999. 1004–1006 and 1015 are
 * reserved for local reporting and never sent.
 */
function closeCodeOk(int $code): bool
{
    return ($code >= 1000 && $code <= 1003) || ($code >= 1007 && $code <= 1014)
        || ($code >= 3000 && $code <= 4999);
}

function closeCodeSendable(int $code): bool
{
    return closeCodeOk($code);
}

/** PCRE rejects invalid UTF-8 under /u, including overlongs and surrogates. */
function utf8Ok(string $s): bool
{
    return $s === '' || \preg_match('//u', $s) === 1;
}

/**
 * Frames out of a ByteBuffer, incrementally. parse() answers NEED (not enough
 * bytes yet), FRAME (the public fields hold it, bytes consumed), or the close
 * code for a protocol error the header alone proves (1002, 1009). The payload
 * length is checked BEFORE the payload is waited for, so a peer cannot make us
 * buffer an oversized frame.
 */
final class FrameParser
{
    public const NEED = 0;
    public const FRAME = 1;

    public bool $fin = false;
    public bool $rsv1 = false;
    public int $opcode = 0;
    public string $payload = '';

    public function __construct(
        private \Buffer\ByteBuffer $buf,
        private bool $expectMasked,
        private int $maxFrame,
        public bool $allowRsv1 = false,
    ) {}

    public function parse(): int
    {
        $b = $this->buf;
        $have = $b->length();
        if ($have < 2) {
            return self::NEED;
        }
        $b0 = $b->byteAt(0);
        $b1 = $b->byteAt(1);
        $fin = ($b0 & 0x80) !== 0;
        $rsv1 = ($b0 & 0x40) !== 0;
        $op = $b0 & 0x0F;
        if (($b0 & 0x30) !== 0 || ($rsv1 && !$this->allowRsv1)) {
            return 1002;
        }
        if (($op > 2 && $op < 8) || $op > 10) {
            return 1002;
        }
        $masked = ($b1 & 0x80) !== 0;
        if ($masked !== $this->expectMasked) {
            return 1002;
        }
        $len = $b1 & 0x7F;
        if ($op >= 8 && (!$fin || $len > 125 || $rsv1)) {
            return 1002;
        }
        $pos = 2;
        if ($len === 126) {
            if ($have < 4) {
                return self::NEED;
            }
            $len = ($b->byteAt(2) << 8) | $b->byteAt(3);
            $pos = 4;
        } elseif ($len === 127) {
            if ($have < 10) {
                return self::NEED;
            }
            if (($b->byteAt(2) & 0x80) !== 0) {
                return 1002;
            }
            $len = 0;
            for ($i = 2; $i < 10; $i++) {
                $len = ($len << 8) | $b->byteAt($i);
            }
            $pos = 10;
        }
        if ($len > $this->maxFrame) {
            return 1009;
        }
        $mlen = $masked ? 4 : 0;
        if ($have < $pos + $mlen + $len) {
            return self::NEED;
        }
        $b->skip($pos);
        $mask = $masked ? $b->read(4) : '';
        $p = $len > 0 ? $b->read($len) : '';
        $this->fin = $fin;
        $this->rsv1 = $rsv1;
        $this->opcode = $op;
        $this->payload = $masked ? applyMask($p, $mask) : $p;
        return self::FRAME;
    }
}

}
