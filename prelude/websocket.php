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

}
