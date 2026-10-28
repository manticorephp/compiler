<?php

namespace Lsp;

final class WorkspaceScan
{
    /**
     * Sorted `*.php` paths under $root. Skips `.git`, `node_modules` and symlinked directories (no loops).
     * ponytail: no .gitignore handling, vendor IS indexed; add an exclude setting when a real project is too slow.
     *
     * @return string[]
     */
    public static function phpFiles(string $root): array
    {
        /** @var string[] $out */
        $out = [];
        self::walk(\rtrim($root, '/'), $out);
        \sort($out);
        return $out;
    }

    /** @param string[] $out */
    private static function walk(string $dir, array &$out): void
    {
        $names = @\scandir($dir);
        if ($names === false) { return; }
        foreach ($names as $n) {
            if ($n === '.' || $n === '..' || $n === '.git' || $n === 'node_modules') { continue; }
            $p = $dir . '/' . $n;
            if (\is_link($p)) {
                if (\is_file($p) && \str_ends_with($n, '.php')) { $out[] = $p; }
            } elseif (\is_dir($p)) {
                self::walk($p, $out);
            } elseif (\str_ends_with($n, '.php')) {
                $out[] = $p;
            }
        }
    }
}
