<?php

declare(strict_types=1);

namespace Compile\Mir;

/**
 * Append-only compiler emission sink.
 *
 * It deliberately owns only compiler-side LLVM text. Target strings, COW, and
 * runtime allocator ABI are not involved. Small functions stay in an ordinary
 * chunk list; once the bounded threshold is crossed, the chunks are flushed to
 * one raw FILE* and subsequent fragments bypass the large PHP string entirely.
 * `finish()` returns either the in-memory text or a private file marker consumed
 * by EmitLlvm's staged path.
 */
final class FunctionTextSink
{
    private string $path;
    private int $threshold;
    private int $bytes = 0;
    /** @var string[] */
    private array $chunks = [];
    private $fp = null;
    private bool $finished = false;

    public function __construct(string $path, int $threshold = 262144)
    {
        $this->path = $path;
        $this->threshold = $threshold;
    }

    public function write(string $chunk): void
    {
        if ($this->finished) {
            throw new \RuntimeException('FunctionTextSink: write after finish');
        }
        $n = \strlen($chunk);
        $this->bytes += $n;
        if ($this->fp === null && $this->path !== ''
            && ($this->bytes >= $this->threshold)) {
            if (!\Manticore\write_file($this->path, '')) {
                throw new \RuntimeException('FunctionTextSink: cannot create ' . $this->path);
            }
            $this->fp = \Manticore\fopen($this->path, 'ab');
            if ($this->fp === null) {
                throw new \RuntimeException('FunctionTextSink: cannot open ' . $this->path);
            }
            foreach ($this->chunks as $old) {
                $on = \strlen($old);
                if (\Manticore\fwrite($old, 1, $on, $this->fp) !== $on) {
                    throw new \RuntimeException('FunctionTextSink: initial flush failed');
                }
            }
            unset($this->chunks);
            $this->chunks = [];
        }
        if ($this->fp !== null) {
            if (\Manticore\fwrite($chunk, 1, $n, $this->fp) !== $n) {
                throw new \RuntimeException('FunctionTextSink: write failed');
            }
            return;
        }
        $this->chunks[] = $chunk;
    }

    public function finish(): string
    {
        if ($this->finished) {
            throw new \RuntimeException('FunctionTextSink: finish twice');
        }
        $this->finished = true;
        if ($this->fp === null) {
            $out = \implode('', $this->chunks);
            unset($this->chunks);
            $this->chunks = [];
            return $out;
        }
        \Manticore\fclose($this->fp);
        $this->fp = null;
        return "\x1fMANTICORE_FUNCTION_FILE\n" . $this->path . "\n" . (string)$this->bytes;
    }
}
