<?php

namespace Lsp\Handlers;

use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;
use Lsp\LocationMap;
use Lsp\Position;
use Semantic\Location;
use Semantic\Workspace;

/** hover, definition, references. */
final class NavigationHandlers
{
    private LocationMap $map;
    /** @var callable */
    private $docs;

    /** @param callable $docs returns array<string, string> uri => open text */
    public function __construct(private Workspace $ws, callable $docs)
    {
        $this->docs = $docs;
        $this->map = new LocationMap($docs);
    }

    public function register(Dispatcher $d): void
    {
        $d->on('textDocument/hover', function (array $p): mixed {
            $at = $this->at($p);
            if ($at === null) { return null; }
            $h = $this->ws->hover($at[0], $at[1], $at[2]);
            if ($h === null) { return null; }
            $range = $this->map->toLsp($h->range);
            if ($range === null) { return null; }
            return [
                'contents' => ['kind' => 'markdown', 'value' => "```php\n" . $h->text . "\n```"],
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
            $sites = $this->ws->referencesTo($at[0], $at[1], $at[2]);
            $ctx = $p['context'] ?? null;
            $incl = !is_array($ctx) || ($ctx['includeDeclaration'] ?? true) !== false;
            if (!$incl) {
                $decl = $this->ws->definitionOf($at[0], $at[1], $at[2]);
                if ($decl !== null) {
                    $sites = array_values(array_filter($sites, fn(Location $s): bool => !self::same($s, $decl)));
                }
            }
            return $this->locations($sites);
        });
    }

    private static function same(Location $a, Location $b): bool
    {
        return $a->file === $b->file && $a->line === $b->line && $a->col === $b->col
            && $a->endLine === $b->endLine && $a->endCol === $b->endCol;
    }

    /**
     * @param array<string, mixed> $p
     * @return array{0: string, 1: int, 2: int}|null core coordinates; null when the doc is not open
     */
    private function at(array $p): ?array
    {
        $uri = $p['textDocument']['uri'] ?? null;
        $pos = $p['position'] ?? null;
        if (!is_string($uri) || !is_array($pos) || !is_int($pos['line'] ?? null) || !is_int($pos['character'] ?? null)) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'textDocument.uri and position required');
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
        $out = [];
        foreach ($locs as $l) {
            $m = $this->map->toLsp($l);
            if ($m !== null) { $out[] = $m; }
        }
        return $out === [] ? null : $out;
    }
}
