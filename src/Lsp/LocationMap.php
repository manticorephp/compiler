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

    /**
     * Source of a `lib:prelude/<name>` label; tests replace it.
     *
     * @var (callable(string): ?string)|null
     */
    public static $libText = null;

    /** Prelude text exactly as the library parse saw it (`<?php` re-added), or null. */
    private static function libText(string $label): ?string
    {
        $name = \substr($label, \strlen('lib:prelude/'));
        if (!\str_starts_with($label, 'lib:prelude/') || $name === '' || \str_contains($name, '/')) { return null; }
        if (self::$libText !== null) { return (self::$libText)($name); }
        if (!\function_exists('Manticore\\prelude_src_or_empty')) { return null; }
        $src = \Manticore\prelude_src_or_empty($name);
        return $src === '' ? null : "<?php\n" . $src;
    }

    /** A lib: label as a readable file: the text goes to a cache file once, and the file URI answers. */
    private static function libUri(string $label, string $text): ?string
    {
        $dir = \sys_get_temp_dir() . '/manticore-lsp-lib';
        $path = $dir . '/' . \substr($label, \strlen('lib:prelude/'));
        if (@\file_get_contents($path) !== $text) {
            if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) { return null; }
            if (@\file_put_contents($path, $text) === false) { return null; }
        }
        return Uri::fromPath($path);
    }

    /**
     * @param bool $lib navigation only: a `lib:` location opens as a cached copy of the prelude file (never for edits)
     * @return array<string, mixed>|null null for unreadable targets, and for `lib:` labels unless $lib
     */
    public function toLsp(Location $l, bool $lib = false): ?array
    {
        $uri = $l->file;
        if (\str_starts_with($l->file, 'lib:')) {
            if (!$lib) { return null; }
            $text = self::libText($l->file);
            $uri = $text === null ? null : self::libUri($l->file, $text);
            if ($uri === null) { return null; }
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
