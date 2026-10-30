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

    /** Open text, else disk text; null when unreadable. */
    public function text(string $uri): ?string
    {
        /** @var array<string, string> $open */
        $open = ($this->docs)();
        $text = $open[$uri] ?? null;
        if ($text !== null) { return $text; }
        if (!isset($this->disk[$uri])) {
            $path = Uri::toPath($uri);
            $this->disk[$uri] = $path !== null && \is_file($path) ? @\file_get_contents($path) : false;
        }
        return $this->disk[$uri] === false ? null : $this->disk[$uri];
    }

    /** @return array<string, mixed>|null null for `lib:` labels and unreadable targets */
    public function toLsp(Location $l): ?array
    {
        if (\str_starts_with($l->file, 'lib:')) { return null; }
        $text = $this->text($l->file);
        if ($text === null) { return null; }
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
