<?php

namespace Lsp;

use Semantic\LibrarySymbols;
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

    /**
     * Source of a `lib:prelude/<name>` label; tests replace it.
     *
     * @var (callable(string): ?string)|null
     */
    public static $libText = null;

    /**
     * Where a `lib:` location opens: the prelude file itself when the compiler finds one (a
     * prelude stub keeps each declaration on its line there), else the library stub text
     * written to a cache file once. Null when neither exists.
     *
     * @return string[]|null [uri, text]
     */
    private static function libTarget(string $label): ?array
    {
        $name = \substr($label, \strlen('lib:prelude/'));
        $isPrelude = \str_starts_with($label, 'lib:prelude/') && $name !== '' && !\str_contains($name, '/');
        if (self::$libText !== null) {
            $text = $isPrelude ? (self::$libText)($name) : null;
            $uri = $text === null ? null : self::libUri($label, $text);
            return $uri === null || $text === null ? null : [$uri, $text];
        }
        if ($isPrelude) {
            $path = \Manticore\find_prelude_path($name);
            $disk = $path === '' ? false : @\file_get_contents($path);
            if (\is_string($disk)) {
                $real = \realpath($path);
                return [Uri::fromPath($real === false ? $path : $real), $disk];
            }
        }
        $text = LibrarySymbols::sectionSource(\substr($label, \strlen(LibrarySymbols::LABEL_PREFIX)));
        $uri = $text === null ? null : self::libUri($label, $text);
        return $uri === null || $text === null ? null : [$uri, $text];
    }

    /** A lib: label as a readable file: the text goes to a cache file once, and the file URI answers. */
    private static function libUri(string $label, string $text): ?string
    {
        $dir = \sys_get_temp_dir() . '/manticore-lsp-lib';
        $path = $dir . '/' . \str_replace('/', '_', \substr($label, \strlen(LibrarySymbols::LABEL_PREFIX)));
        if (@\file_get_contents($path) !== $text) {
            if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) { return null; }
            if (@\file_put_contents($path, $text) === false) { return null; }
        }
        return Uri::fromPath($path);
    }

    /**
     * @param bool $lib navigation only: a `lib:` location opens the prelude file (or a cached copy of its stub; never for edits)
     * @return array<string, mixed>|null null for unreadable targets, and for `lib:` labels unless $lib
     */
    public function toLsp(Location $l, bool $lib = false): ?array
    {
        $uri = $l->file;
        if (\str_starts_with($l->file, 'lib:')) {
            if (!$lib) { return null; }
            $t = self::libTarget($l->file);
            if ($t === null) { return null; }
            $uri = $t[0];
            $text = $t[1];
        } else {
            $text = $this->text($l->file);
        }
        if ($text === null) { return null; }
        $a = Position::lineText($text, $l->line);
        $b = $l->endLine === $l->line ? $a : Position::lineText($text, $l->endLine);
        return [
            'uri' => $uri,
            'range' => [
                'start' => ['line' => $l->line - 1, 'character' => Position::toLsp($a, $l->col)],
                'end' => ['line' => $l->endLine - 1, 'character' => Position::toLsp($b, $l->endCol)],
            ],
        ];
    }
}
