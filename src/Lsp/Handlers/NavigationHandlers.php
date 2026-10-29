<?php

namespace Lsp\Handlers;

use Lsp\ClosedFiles;
use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;
use Lsp\LocationMap;
use Lsp\MemberQuery;
use Lsp\Position;
use Lsp\Uri;
use Semantic\Location;
use Semantic\Workspace;

/** hover, definition, references. */
final class NavigationHandlers
{
    /** @var callable */
    private $docs;

    /** @param callable $docs returns array<string, string> uri => open text */
    public function __construct(private Workspace $ws, callable $docs, private ClosedFiles $closed)
    {
        $this->docs = $docs;
    }

    public function register(Dispatcher $d): void
    {
        $d->on('textDocument/hover', function (array $p): mixed {
            $at = $this->at($p);
            if ($at === null) { return null; }
            $h = $this->ws->hover($at[0], $at[1], $at[2]);
            if ($h === null) { return null; }
            $range = (new LocationMap($this->docs))->toLsp($h->range);
            if ($range === null) { return null; }
            return [
                'contents' => ['kind' => 'markdown', 'value' => "```php\n" . $h->text . "\n```" . ($h->detail === '' ? '' : "\n\n" . $h->detail)],
                'range' => $range['range'],
            ];
        });
        $d->on('textDocument/definition', function (array $p): mixed {
            $at = $this->at($p);
            if ($at === null) { return null; }
            return $this->locations($this->ws->definitionsOf($at[0], $at[1], $at[2]));
        });
        $d->on('textDocument/references', function (array $p): mixed {
            $at = $this->at($p);
            if ($at === null) { return null; }
            $sites = $this->references($at[0], $at[1], $at[2]);
            $ctx = $p['context'] ?? null;
            $incl = !is_array($ctx) || ($ctx['includeDeclaration'] ?? true) !== false;
            if (!$incl) {
                $decl = $this->ws->definitionOf($at[0], $at[1], $at[2]);
                if ($decl !== null) {
                    $sites = array_values(array_filter($sites, fn(Location $s): bool => !self::sameStart($s, $decl)));
                }
            }
            return $this->locations($sites);
        });
    }

    /**
     * A member's use sites need receiver typing, so for a member the scanned-but-closed workspace files are opened
     * from disk for this query only (ClosedFiles::with; skipped past ClosedFiles::MAX files, then only open docs and
     * the name index answer). Classes, functions, constants and variables are answered from the name index.
     *
     * @return Location[]
     */
    private function references(string $uri, int $line, int $col): array
    {
        /** @var array<string, string> $open */
        $open = ($this->docs)();
        /** @var Location[] $r */
        $r = (new MemberQuery($this->ws, $this->closed))->run($uri, $line, $col, Position::lineText($open[$uri] ?? '', $line), fn(): array => $this->ws->referencesTo($uri, $line, $col));
        return $r;
    }

    private static function sameStart(Location $a, Location $b): bool
    {
        return $a->file === $b->file && $a->line === $b->line && $a->col === $b->col;
    }

    /**
     * @param array<string, mixed> $p
     * @return array{0: string, 1: int, 2: int}|null core coordinates; null when the doc is not open
     */
    private function at(array $p): ?array
    {
        $uri = $p['textDocument']['uri'] ?? null;
        $uri = \is_string($uri) ? Uri::normalize($uri) : null;
        $pos = $p['position'] ?? null;
        if (!is_string($uri) || !is_array($pos) || !is_int($pos['line'] ?? null) || !is_int($pos['character'] ?? null)
            || $pos['line'] < 0 || $pos['character'] < 0) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'textDocument.uri and a non-negative position required');
        }
        /** @var array<string, string> $open */
        $open = ($this->docs)();
        $text = $open[$uri] ?? null;
        if ($text === null) { return null; }
        $line = $pos['line'] + 1;
        return [$uri, $line, Position::toCore(Position::lineText($text, $line), $pos['character'])];
    }

    /**
     * @param Location[] $locs
     * @return array<int, array<string, mixed>>|null
     */
    private function locations(array $locs): ?array
    {
        $map = new LocationMap($this->docs);
        $out = [];
        foreach ($locs as $l) {
            $m = $map->toLsp($l);
            if ($m !== null) { $out[] = $m; }
        }
        return $out === [] ? null : $out;
    }
}
