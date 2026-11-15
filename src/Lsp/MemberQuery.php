<?php

namespace Lsp;

use Semantic\Workspace;

/** Runs a workspace query; for a MEMBER under the cursor the closed files with its use sites are opened one at a time. */
final class MemberQuery
{
    public function __construct(private Workspace $ws, private ClosedFiles $closed)
    {
    }

    public function isMember(string $uri, int $line, int $col): bool
    {
        return $this->ws->symbolKindAt($uri, $line, $col) === 'member';
    }

    public function run(string $uri, int $line, int $col, callable $fn): mixed
    {
        return $this->isMember($uri, $line, $col) ? $this->closed->with($fn) : $fn();
    }
}
