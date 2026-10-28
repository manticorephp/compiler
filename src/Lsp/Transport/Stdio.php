<?php

namespace Lsp\Transport;

use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;

class Stdio
{
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
            if (strncasecmp($line, 'Content-Length:', 15) === 0) {
                $len = (int) trim(substr($line, 15));
            }
        }
        if ($len < 0) {
            throw new RpcError(RpcError::PARSE, 'Missing Content-Length header');
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
                fwrite($out, self::encode(Dispatcher::error(null, $e->rpcCode, $e->getMessage())));
                fflush($out);
                continue;
            }
            if ($msg === null) {
                return;
            }
            $resp = $d->handle($msg);
            if ($resp !== null) {
                fwrite($out, self::encode($resp));
                fflush($out);
            }
        }
    }
}
