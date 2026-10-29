<?php

namespace Lsp;

use Semantic\Workspace;

/**
 * Scanned-but-closed workspace files. A member's use sites need receiver typing, which needs an AST, which closed
 * files do not keep: `with` opens the ones that can matter from disk for one query and closes them again.
 * ponytail: more than `max` files opened per query are skipped (the query answers from open docs plus the name index only); raise or make lazy when real projects hit it.
 */
final class ClosedFiles
{
    public const MAX = 5000;
    public const MAX_BYTES = 2097152;
    /** @var array<string, bool> */
    private array $scanned = [];
    /** @var array<string, bool> files the scan could not index (too big, unreadable, unparsable) */
    private array $unindexed = [];
    private int $skipped = 0;
    private bool $busy = false;
    /** @var callable */
    private $docs;

    /** @param callable $docs returns array<string, string> uri => open text */
    public function __construct(private Workspace $ws, callable $docs, private int $max = self::MAX)
    {
        $this->docs = $docs;
    }

    public function add(string $uri): void
    {
        $this->scanned[$uri] = true;
        unset($this->unindexed[$uri]);
    }

    /** A scanned file whose sites the index may be missing. */
    public function markUnindexed(string $uri): void
    {
        $this->unindexed[$uri] = true;
    }

    /** Files the scan could not index; any rename may have omitted sites in them. */
    public function unindexed(): int
    {
        return \count($this->unindexed);
    }

    /** Files the last `with` needed but could not open (over the cap, too big, unreadable, unparsable). */
    public function skipped(): int
    {
        return $this->skipped;
    }

    /** Drop a file from the index and the scanned set. */
    public function forget(string $uri): void
    {
        $this->ws->remove($uri);
        unset($this->scanned[$uri], $this->unindexed[$uri]);
    }

    /** Refresh the index entry of a closed file from disk; a file that is gone is forgotten. */
    public function reindex(string $uri): void
    {
        $path = Uri::toPath($uri);
        if ($path === null || !@\is_file($path)) {
            $this->forget($uri);
            return;
        }
        $size = @\filesize($path);
        $text = $size === false || $size > self::MAX_BYTES ? false : @\file_get_contents($path);
        if ($text === false) {
            $this->scanned[$uri] = true;
            $this->unindexed[$uri] = true;
            return;
        }
        try {
            $this->ws->open($uri, $text);
            $this->ws->close($uri);
            $this->add($uri);
        } catch (\Throwable $e) {
            $this->scanned[$uri] = true;
            $this->unindexed[$uri] = true;
        }
    }

    /**
     * Forget every given scanned file that is gone from disk; returns how many.
     *
     * @param string[] $uris
     */
    public function prune(array $uris): int
    {
        $n = 0;
        foreach ($uris as $uri) {
            if (!isset($this->scanned[$uri])) { continue; }
            $path = Uri::toPath($uri);
            if ($path === null || !@\is_file($path)) {
                $this->forget($uri);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Runs $fn with every closed file that mentions $needle (case-insensitive; '' = all) opened from disk.
     * A file that vanished is forgotten; one that is too big, unreadable or unparsable, or past the cap, counts toward skipped().
     */
    public function with(callable $fn, string $needle = ''): mixed
    {
        if ($this->busy) { throw new \LogicException('ClosedFiles::with is not re-entrant'); }
        $this->busy = true;
        $this->skipped = 0;
        /** @var string[] $opened */
        $opened = [];
        try {
            /** @var array<string, string> $open */
            $open = ($this->docs)();
            foreach (\array_keys($this->scanned) as $uri) {
                if (isset($open[$uri])) { continue; }
                $path = Uri::toPath($uri);
                if ($path === null || !@\is_file($path)) {
                    $this->forget($uri);
                    continue;
                }
                $size = @\filesize($path);
                $text = $size === false || $size > self::MAX_BYTES ? false : @\file_get_contents($path);
                if ($text === false) { $this->skipped++; continue; }
                if ($needle !== '' && \stripos($text, $needle) === false) { continue; }
                if (\count($opened) >= $this->max) { $this->skipped++; continue; }
                try {
                    $this->ws->open($uri, $text);
                    $opened[] = $uri;
                } catch (\Throwable $e) {
                    $this->skipped++;
                }
            }
            return $fn();
        } finally {
            foreach ($opened as $uri) { $this->ws->close($uri); }
            $this->busy = false;
        }
    }
}
