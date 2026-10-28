<?php

namespace Lsp;

use Semantic\Workspace;

/**
 * Scanned-but-closed workspace files. A member's use sites need receiver typing, which needs an AST, which closed
 * files do not keep: `with` opens them all from disk for one query and closes them again.
 * ponytail: more than MAX closed files are skipped (the query answers from open docs plus the name index only); raise or make lazy when real projects hit it.
 */
final class ClosedFiles
{
    public const MAX = 5000;
    /** @var array<string, bool> */
    private array $scanned = [];
    /** @var callable */
    private $docs;

    /** @param callable $docs returns array<string, string> uri => open text */
    public function __construct(private Workspace $ws, callable $docs)
    {
        $this->docs = $docs;
    }

    public function add(string $uri): void
    {
        $this->scanned[$uri] = true;
    }

    public function with(callable $fn): mixed
    {
        /** @var array<string, string> $open */
        $open = ($this->docs)();
        /** @var string[] $todo */
        $todo = [];
        foreach ($this->scanned as $uri => $_) {
            if (!isset($open[$uri])) { $todo[] = $uri; }
        }
        if (\count($todo) > self::MAX) { return $fn(); }
        /** @var string[] $opened */
        $opened = [];
        try {
            foreach ($todo as $uri) {
                $path = Uri::toPath($uri);
                if ($path === null || !\is_file($path)) { continue; }
                $text = @\file_get_contents($path);
                if ($text === false) { continue; }
                try {
                    $this->ws->open($uri, $text);
                    $opened[] = $uri;
                } catch (\Throwable $e) {
                    continue;
                }
            }
            return $fn();
        } finally {
            foreach ($opened as $uri) { $this->ws->close($uri); }
        }
    }
}
