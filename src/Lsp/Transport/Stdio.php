<?php

namespace Lsp\Transport;

use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;

class Stdio
{
    private const MAX_BODY = 67108864;

    /** @var callable */
    private $shouldStop;
    private string $buf = '';
    private bool $eof = false;
    /** @var resource|null */
    private $in = null;
    /** @var array<int, string|RpcError> messages read ahead while a request was running */
    private array $queue = [];
    private ?Dispatcher $d = null;

    public function __construct(callable $shouldStop)
    {
        $this->shouldStop = $shouldStop;
    }

    public static function encode(string $body): string
    {
        return 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
    }

    /**
     * Next complete message from the buffer, or null when more input is needed.
     * A header block without Content-Length throws RpcError(PARSE); the block is consumed,
     * and a Content-Length line anywhere before the blank line still counts, so a retry resyncs.
     */
    private function take(): ?string
    {
        $pos = 0;
        $n = strlen($this->buf);
        $len = -1;
        $bad = false;
        $lines = 0;
        while (true) {
            $nl = strpos($this->buf, "\n", $pos);
            if ($nl === false) {
                return null;
            }
            $line = rtrim(substr($this->buf, $pos, $nl - $pos), "\r\n");
            $pos = $nl + 1;
            if ($line === '') {
                if ($lines === 0) {
                    $this->buf = substr($this->buf, $pos);
                    $pos = 0;
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
            $this->buf = substr($this->buf, $pos);
            throw new RpcError(RpcError::PARSE, $bad ? 'Invalid Content-Length' : 'Missing Content-Length header');
        }
        if ($n - $pos < $len) {
            return null;
        }
        $body = substr($this->buf, $pos, $len);
        $this->buf = substr($this->buf, $pos + $len);
        return $body;
    }

    /** Read what is available (or wait for it when $block); a stream stream_select cannot watch is read blocking. */
    private function fill(bool $block): void
    {
        if ($this->eof || $this->in === null) {
            return;
        }
        $r = [$this->in];
        $w = null;
        $e = null;
        try {
            $ready = @stream_select($r, $w, $e, $block ? null : 0);
        } catch (\Throwable $ex) {
            $ready = false;
        }
        if ($ready === 0) {
            return;
        }
        $this->readFrame();
    }

    /**
     * Read one whole message off the stream: header lines, then exactly Content-Length body bytes.
     * Whole frames, not chunks: a libc fread on a pipe waits for ALL requested bytes, php's returns what is there.
     * ponytail: a frame already pulled into the FILE* buffer is invisible to stream_select, so a cancel that
     * arrives in the same write as its request is seen only after the request finishes.
     */
    private function readFrame(): void
    {
        $raw = '';
        $len = -1;
        while (true) {
            $line = fgets($this->in);
            if ($line === false) {
                $this->eof = true;
                return;
            }
            $raw .= $line;
            $t = rtrim($line, "\r\n");
            if ($t === '') {
                break;
            }
            $p = stripos($t, 'content-length:');
            if ($p !== false) {
                $v = trim(substr($t, $p + 15));
                $len = ctype_digit($v) && strlen($v) <= 10 && (int) $v <= self::MAX_BODY ? (int) $v : -1;
            }
        }
        $body = '';
        while ($len > 0 && strlen($body) < $len) {
            $c = fread($this->in, $len - strlen($body));
            if ($c === false || $c === '') {
                $this->eof = true;
                break;
            }
            $body .= $c;
        }
        $this->buf .= $raw . $body;
    }

    /** Pull pending input while a request runs: cancellations take effect, everything else waits in the queue. */
    private function poll(): void
    {
        $this->fill(false);
        while (true) {
            try {
                $m = $this->take();
            } catch (RpcError $e) {
                $this->queue[] = $e;
                continue;
            }
            if ($m === null) {
                return;
            }
            if (strpos($m, '$/cancelRequest') !== false) {
                $j = json_decode($m, true);
                $id = is_array($j) && ($j['method'] ?? null) === '$/cancelRequest' ? ($j['params']['id'] ?? null) : null;
                if (is_int($id) || is_string($id) || is_float($id)) {
                    $this->d?->cancellation()->cancel((string) $id);
                    continue;
                }
            }
            $this->queue[] = $m;
        }
    }

    /** Next message: read-ahead first, then blocking input. Null on EOF (also mid-header / mid-body). */
    private function next(): string|RpcError|null
    {
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }
        while (true) {
            try {
                $m = $this->take();
            } catch (RpcError $e) {
                return $e;
            }
            if ($m !== null) {
                return $m;
            }
            if ($this->eof) {
                return null;
            }
            $this->fill(true);
        }
    }

    public function run(Dispatcher $d, $in = STDIN, $out = STDOUT): void
    {
        $this->in = $in;
        stream_set_read_buffer($in, 0);   // stdio read-ahead would hide queued frames from stream_select
        $this->d = $d;
        $d->cancellation()->setPoll(function (): void { $this->poll(); });
        while (!($this->shouldStop)()) {
            $msg = $this->next();
            if ($msg === null) {
                return;
            }
            if ($msg instanceof RpcError) {
                if (!self::write($out, self::encode(Dispatcher::error(null, $msg->rpcCode, $msg->getMessage())))) {
                    return;
                }
                continue;
            }
            $this->poll();
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
