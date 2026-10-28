<?php

namespace Lsp\Handlers;

use Lsp\ClosedFiles;
use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;
use Lsp\LocationMap;
use Lsp\Position;
use Semantic\Location;
use Semantic\Workspace;

/** prepareRename, rename. */
final class RenameHandlers
{
    /** @var callable */
    private $docs;
    /** @var callable */
    private $send;

    /**
     * @param callable $docs returns array<string, string> uri => open text
     * @param callable $send takes one JSON message string
     */
    public function __construct(private Workspace $ws, callable $docs, private ClosedFiles $closed, callable $send)
    {
        $this->docs = $docs;
        $this->send = $send;
    }

    public function register(Dispatcher $d): void
    {
        $d->on('textDocument/prepareRename', function (array $p): mixed {
            $at = $this->at($p);
            if ($at === null) { return null; }
            $set = $this->editSet($at);
            if ($set === null) { return null; }
            $hit = $this->containing($set, $at);
            if ($hit === null) { return null; }
            $loc = $this->nameRange($hit, $this->kind($at) === 'variable');
            $m = (new LocationMap($this->docs))->toLsp($loc);
            if ($m === null) { return null; }
            /** @var array<string, string> $open */
            $open = ($this->docs)();
            $line = Position::lineText($open[$at[0]] ?? '', $loc->line);
            return ['range' => $m['range'], 'placeholder' => \substr($line, $loc->col - 1, $loc->endCol - $loc->col)];
        });
        $d->on('textDocument/rename', function (array $p): mixed {
            $at = $this->at($p);
            $new = $p['newName'] ?? null;
            if (!\is_string($new)) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'newName required');
            }
            if ($at === null) { return null; }
            $isVar = $this->kind($at) === 'variable';
            if ($isVar && \str_starts_with($new, '$')) { $new = \substr($new, 1); }
            if (\preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $new) !== 1) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'newName is not a valid PHP identifier');
            }
            /** @var string[] $all */
            $all = $this->query($at, fn(): array => $this->ws->renameBlockers($at[0], $at[1], $at[2]));
            $blockers = \array_values(\array_filter($all, fn(string $b): bool => !self::isUncertain($b)));
            if ($blockers !== []) {
                $more = \count($blockers) > 5 ? ' (+' . (\count($blockers) - 5) . ' more)' : '';
                throw new RpcError(RpcError::INVALID_PARAMS, 'Cannot rename: ' . \implode(', ', \array_slice($blockers, 0, 5)) . $more);
            }
            $set = $this->editSet($at);
            if ($set === null) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'cannot edit library symbol or no renameable symbol here');
            }
            $map = new LocationMap($this->docs);
            /** @var array<string, array<int, array<string, mixed>>> $changes */
            $changes = [];
            foreach ($set as $l) {
                $m = $map->toLsp($this->nameRange($l, $isVar));
                if ($m === null) {
                    throw new RpcError(RpcError::INVALID_PARAMS, 'cannot edit library symbol: ' . $l->file);
                }
                $changes[(string)$m['uri']][] = ['range' => $m['range'], 'newText' => $new];
            }
            \ksort($changes);
            foreach ($changes as $u => $edits) {
                \usort($edits, fn(array $a, array $b): int => [$a['range']['start']['line'], $a['range']['start']['character']]
                    <=> [$b['range']['start']['line'], $b['range']['start']['character']]);
                $changes[$u] = $edits;
            }
            $this->warnUncertain($at);
            return ['changes' => (object)$changes];
        });
    }

    /** renameBlockers also lists every uncertain site as `reason:file:line:col`; those warn (warnUncertain) instead of blocking. */
    private static function isUncertain(string $blocker): bool
    {
        $i = \strpos($blocker, ':');
        return $i !== false && \in_array(\substr($blocker, 0, $i), ['string-class-name', 'dynamic-member', 'callable-string', 'docblock', 'unresolved-member'], true);
    }

    /** @param array{0: string, 1: int, 2: int} $at */
    private function warnUncertain(array $at): void
    {
        /** @var \Semantic\UncertainSite[] $sites */
        $sites = $this->query($at, fn(): array => $this->ws->uncertainSitesFor($at[0], $at[1], $at[2]));
        $items = [];
        foreach ($sites as $s) {
            if (\str_starts_with($s->file, 'lib:')) { continue; }
            $items[] = $s->file . ':' . $s->line . ' (' . $s->reason . ')';
        }
        if ($items === []) { return; }
        $msg = \json_encode([
            'jsonrpc' => '2.0',
            'method' => 'window/showMessage',
            'params' => ['type' => 2, 'message' => 'Rename left ' . \count($items) . ' uncertain site(s): ' . \implode(', ', \array_slice($items, 0, 10))],
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($msg !== false) { ($this->send)($msg); }
    }

    /** @param array{0: string, 1: int, 2: int} $at */
    private function kind(array $at): ?string
    {
        return $this->ws->symbolKindAt($at[0], $at[1], $at[2]);
    }

    /**
     * A member query needs the closed workspace files opened for its duration (ClosedFiles::with).
     *
     * @param array{0: string, 1: int, 2: int} $at
     */
    private function query(array $at, callable $fn): mixed
    {
        return $this->kind($at) === 'member' ? $this->closed->with($fn) : $fn();
    }

    /**
     * Declarations plus references, deduplicated; null when nothing is under the cursor or any site is a library one.
     *
     * @param array{0: string, 1: int, 2: int} $at
     * @return Location[]|null
     */
    private function editSet(array $at): ?array
    {
        /** @var Location[] $all */
        $all = $this->query($at, fn(): array => \array_merge(
            $this->ws->referencesTo($at[0], $at[1], $at[2]),
            $this->ws->definitionsOf($at[0], $at[1], $at[2]),
        ));
        $seen = [];
        $out = [];
        foreach ($all as $l) {
            if (\str_starts_with($l->file, 'lib:')) { return null; }
            $k = $l->file . ':' . $l->line . ':' . $l->col;
            if (isset($seen[$k])) { continue; }
            $seen[$k] = true;
            $out[] = $l;
        }
        return $out === [] ? null : $out;
    }

    /**
     * @param Location[] $set
     * @param array{0: string, 1: int, 2: int} $at
     */
    private function containing(array $set, array $at): ?Location
    {
        foreach ($set as $l) {
            if ($l->file === $at[0] && $l->line === $at[1] && $l->endLine === $at[1] && $at[2] >= $l->col && $at[2] <= $l->endCol) {
                return $l;
            }
        }
        return null;
    }

    private function nameRange(Location $l, bool $isVar): Location
    {
        return $isVar ? new Location($l->file, $l->line, $l->col + 1, $l->endLine, $l->endCol) : $l;
    }

    /**
     * @param array<string, mixed> $p
     * @return array{0: string, 1: int, 2: int}|null
     */
    private function at(array $p): ?array
    {
        $uri = $p['textDocument']['uri'] ?? null;
        $pos = $p['position'] ?? null;
        if (!\is_string($uri) || !\is_array($pos) || !\is_int($pos['line'] ?? null) || !\is_int($pos['character'] ?? null)
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
}
