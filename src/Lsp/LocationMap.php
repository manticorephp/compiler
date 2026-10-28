<?php

namespace Lsp;

use Semantic\Location;

/** One instance per request (it caches disk reads). Core Location (1-based line, byte column) -> LSP Location. Target text comes from the open docs, else from disk. */
final class LocationMap
{
    /** @var callable */
    private $docs;
    /** @var array<string, string|false> uri => disk text, one read per file */
    private array $disk = [];

    /** @param callable $docs returns array<string, string> uri => open text */
    public function __construct(callable $docs)
    {
        $this->docs = $docs;
    }

    /** @return array<string, mixed>|null null for `lib:` labels and unreadable targets */
    public function toLsp(Location $l): ?array
    {
        if (\str_starts_with($l->file, 'lib:')) { return null; }
        /** @var array<string, string> $open */
        $open = ($this->docs)();
        $text = $open[$l->file] ?? null;
        if ($text === null) {
            if (!isset($this->disk[$l->file])) {
                $path = Uri::toPath($l->file);
                $this->disk[$l->file] = $path !== null && \is_file($path) ? @\file_get_contents($path) : false;
            }
            $text = $this->disk[$l->file];
            if ($text === false) { return null; }
        }
        $a = Position::lineText($text, $l->line);
        $b = $l->endLine === $l->line ? $a : Position::lineText($text, $l->endLine);
        return [
            'uri' => $l->file,
            'range' => [
                'start' => ['line' => $l->line - 1, 'character' => Position::toLsp($a, $l->col)],
                'end' => ['line' => $l->endLine - 1, 'character' => Position::toLsp($b, $l->endCol)],
            ],
        ];
    }
}
