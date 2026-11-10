<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\ParsedArgs;

/**
 * `manticore cache` — where the object cache lives and how big it is;
 * `manticore cache clear` — empty it.
 *
 * Only the cache's own files are touched (`<sha1>.o` and their in-flight
 * `.tmp` copies, at the top and in `prog/`, and the analyzer's `<sha1>.json`
 * prelude indexes and cached link flags beside it): `MANTICORE_OBJ_CACHE_DIR` may point
 * anywhere, and clearing must never reach a file the cache did not write.
 */
final class CacheCommand extends Command
{
    public function name(): string { return 'cache'; }

    public function summary(): string { return 'Show the compiled-object cache, or clear it'; }

    public function usage(): string { return '[clear]'; }

    public function run(ParsedArgs $args): int
    {
        $action = $args->positional[0] ?? 'show';
        if ($action !== 'show' && $action !== 'clear') {
            \Manticore\dprint('cache: unknown action \'' . $action . '\' (expected: clear)');
            return 64;
        }
        $dir = \Manticore\obj_cache_dir();
        $files = 0;
        $bytes = 0;
        $indexes = 0;
        // Objects at the top and in prog/; the analyzer's prelude indexes beside
        // the object directory ({@see \Analyze\PreludeIndex::path}).
        /** @var array<int, string[]> */
        $sets = [
            [$dir, '/^[0-9a-f]{40}\.o(\.tmp[0-9_]*)?$/'],
            [$dir . '/prog', '/^[0-9a-f]{40}\.o(\.tmp[0-9_]*)?$/'],
            [\dirname($dir) . '/analyze', '/^[0-9a-f]{40}\.json(\.tmp[0-9_]*)?$/'],
            [\dirname($dir) . '/link', '/^[0-9a-f]{40}\.txt(\.tmp[0-9_]*)?$/'],
        ];
        foreach ($sets as $k => $set) {
            foreach ($this->cacheFiles($set[0], $set[1]) as $f) {
                if ($k >= 2) { $indexes++; } else { $files++; }
                $sz = \filesize($f);
                $bytes += $sz === false ? 0 : $sz;
                if ($action === 'clear') {
                    \unlink($f);
                }
            }
        }
        $what = (string)$files . ' objects, ' . (string)$indexes . ' indexes';
        $size = $this->human($bytes);
        if ($action === 'clear') {
            \Manticore\puts('cleared ' . $what . ' (' . $size . ') from ' . $dir);
            return 0;
        }
        \Manticore\puts($dir . ': ' . $what . ', ' . $size);
        return 0;
    }

    /** @return string[] */
    private function cacheFiles(string $dir, string $pattern): array
    {
        if (!\is_dir($dir)) {
            return [];
        }
        $ents = \scandir($dir);
        if ($ents === false) {
            return [];
        }
        $out = [];
        foreach ($ents as $e) {
            if (\preg_match($pattern, (string)$e) === 1) {
                $out[] = $dir . '/' . (string)$e;
            }
        }
        return $out;
    }

    private function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return (string)\round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return (string)\round($bytes / 1024, 1) . ' KB';
        }
        return (string)$bytes . ' B';
    }
}
