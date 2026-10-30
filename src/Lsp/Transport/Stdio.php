<?php

namespace Lsp\Transport;

use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;

class Stdio
{
    private const MAX_BODY = 67108864;

    /** @var callable */
    private $shouldStop;

    public function __construct(callable $shouldStop)
    {
        $this->shouldStop = $shouldStop;
    }

    public static function encode(string $body): string
    {
        return 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
    }

    /**
     * Next message body, or null on EOF (also mid-header / mid-body).
     * A header block without Content-Length throws RpcError(PARSE); the block is consumed,
     * and a Content-Length line anywhere before the blank line still counts, so a retry resyncs.
     */
    public static function readMessage($in): ?string
    {
        $len = -1;
        $bad = false;
        $lines = 0;
        while (true) {
            $line = fgets($in);
            if ($line === false) {
                return null;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($lines === 0) {
                    continue;
                }
                break;
            }
            $lines++;
            $p = stripos($line, 'content-length:');
            if ($p !== false) {
                $v = trim(substr($line, $p + 15));
                if ($v !== '' && strlen($v) <= 10 && ctype_digit($v) && (int) $v <= self::MAX_BODY) {
                    $len = (int) $v;
                    $bad = false;
                } else {
                    $len = -1;
                    $bad = true;
                }
            }
        }
        if ($len < 0) {
            throw new RpcError(RpcError::PARSE, $bad ? 'Invalid Content-Length' : 'Missing Content-Length header');
        }
        $body = '';
        while (strlen($body) < $len) {
            $chunk = fread($in, $len - strlen($body));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $body .= $chunk;
        }
        return $body;
    }

    public function run(Dispatcher $d, $in = STDIN, $out = STDOUT): void
    {
        while (!($this->shouldStop)()) {
            try {
                $msg = self::readMessage($in);
            } catch (RpcError $e) {
                if (!self::write($out, self::encode(Dispatcher::error(null, $e->rpcCode, $e->getMessage())))) {
                    return;
                }
                continue;
            }
            if ($msg === null) {
                return;
            }
            $resp = $d->handle($msg);
            if ($resp !== null) {
                if (!self::write($out, self::encode($resp))) {
                    return;
                }
            }
        }
    }

    /** False when the peer closed the pipe. */
    public static function write($out, string $data): bool
    {
        $off = 0;
        $n = strlen($data);
        while ($off < $n) {
            $w = fwrite($out, substr($data, $off));
            if ($w === false || $w === 0) {
                return false;
            }
            $off += $w;
        }
        fflush($out);
        return true;
    }
}
