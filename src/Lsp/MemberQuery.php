<?php

namespace Lsp;

use Semantic\Workspace;

/** Runs a workspace query; for a MEMBER under the cursor the closed files that mention its name are opened for the duration. */
final class MemberQuery
{
    public function __construct(private Workspace $ws, private ClosedFiles $closed)
    {
    }

    public function isMember(string $uri, int $line, int $col): bool
    {
        return $this->ws->symbolKindAt($uri, $line, $col) === 'member';
    }

    public function run(string $uri, int $line, int $col, string $lineText, callable $fn): mixed
    {
        return $this->isMember($uri, $line, $col) ? $this->closed->with($fn, self::wordAt($lineText, $col)) : $fn();
    }

    /** The identifier under 1-based byte column $col, without a `$`. */
    public static function wordAt(string $lineText, int $col): string
    {
        $i = \min($col - 1, \strlen($lineText));
        $isId = static fn(string $c): bool => \ctype_alnum($c) || $c === '_' || \ord($c) >= 0x80;
        $a = $i;
        while ($a > 0 && $isId($lineText[$a - 1])) { $a--; }
        $b = $i;
        while ($b < \strlen($lineText) && $isId($lineText[$b])) { $b++; }
        return \substr($lineText, $a, $b - $a);
    }
}
