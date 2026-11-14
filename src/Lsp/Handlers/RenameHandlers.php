<?php

namespace Lsp\Handlers;

use Lsp\ClosedFiles;
use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;
use Lsp\LocationMap;
use Lsp\Position;
use Semantic\Location;
use Semantic\UncertainSite;
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
            /** @var Location[]|null $set */
            $set = $this->query($at, fn(): ?array => $this->editSet($at));
            if ($set === null) { return null; }
            $hit = $this->containing($set, $at);
            if ($hit === null) { return null; }
            $map = new LocationMap($this->docs);
            $loc = $this->nameRange($hit, $map);
            $m = $map->toLsp($loc);
            $text = $map->text($loc->file);
            if ($m === null || $text === null) { return null; }
            $line = Position::lineText($text, $loc->line);
            return ['range' => $m['range'], 'placeholder' => \substr($line, $loc->col - 1, $loc->endCol - $loc->col)];
        });
        $d->on('textDocument/rename', function (array $p): mixed {
            $at = $this->at($p);
            $new = $p['newName'] ?? null;
            if (!\is_string($new)) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'newName required');
            }
            $bare = \str_starts_with($new, '$') ? \substr($new, 1) : $new;
            if (\preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $bare) !== 1) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'newName is not a valid PHP identifier');
            }
            if ($at === null) { return null; }
            /** @var array{0: string[], 1: Location[]|null, 2: UncertainSite[]} $g */
            $g = $this->query($at, fn(): array => [
                $this->ws->renameBlockers($at[0], $at[1], $at[2]),
                $this->editSet($at),
                $this->ws->uncertainSitesFor($at[0], $at[1], $at[2]),
            ]);
            [$all, $set, $uncertain] = $g;
            if ($this->closed->skipped() > 0 && $this->kind($at) === 'member') {
                throw new RpcError(RpcError::INVALID_PARAMS, 'rename incomplete: ' . $this->closed->skipped() . ' workspace files could not be searched');
            }
            $blockers = \array_values(\array_filter($all, fn(string $b): bool => !self::isUncertain($b)));
            if ($blockers !== []) {
                $more = \count($blockers) > 5 ? ' (+' . (\count($blockers) - 5) . ' more)' : '';
                throw new RpcError(RpcError::INVALID_PARAMS, 'Cannot rename: ' . \implode(', ', \array_slice($blockers, 0, 5)) . $more);
            }
            if ($set === null) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'cannot edit library symbol or no renameable symbol here');
            }
            $map = new LocationMap($this->docs);
            $sigil = false;
            /** @var array<string, array<int, array<string, mixed>>> $changes */
            $changes = [];
            foreach ($set as $l) {
                $r = $this->nameRange($l, $map);
                if ($r->col !== $l->col) { $sigil = true; }
                $m = $map->toLsp($r);
                if ($m === null) {
                    throw new RpcError(RpcError::INVALID_PARAMS, 'cannot edit library symbol: ' . $l->file);
                }
                $changes[(string)$m['uri']][] = ['range' => $m['range'], 'newText' => $bare];
            }
            if (!$sigil && $bare !== $new) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'newName is not a valid PHP identifier');
            }
            \ksort($changes);
            foreach ($changes as $u => $edits) {
                \usort($edits, fn(array $a, array $b): int => [$a['range']['start']['line'], $a['range']['start']['character']]
                    <=> [$b['range']['start']['line'], $b['range']['start']['character']]);
                $changes[$u] = $edits;
            }
            $this->warn($uncertain);
            return ['changes' => (object)$changes];
        });
    }

    /** renameBlockers also lists every uncertain site as `reason:file:line:col`; those warn instead of blocking. */
    private static function isUncertain(string $blocker): bool
    {
        $i = \strpos($blocker, ':');
        return $i !== false && \in_array(\substr($blocker, 0, $i), ['string-class-name', 'dynamic-member', 'callable-string', 'docblock', 'unresolved-member'], true);
    }

    /** @param UncertainSite[] $sites */
    private function warn(array $sites): void
    {
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
        $all = \array_merge(
            $this->ws->referencesTo($at[0], $at[1], $at[2]),
            $this->ws->definitionsOf($at[0], $at[1], $at[2]),
        );
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

    /** The edit range: a site whose source text starts with `$` (variable, property declaration, `A::$sp`) keeps the sigil. */
    private function nameRange(Location $l, LocationMap $map): Location
    {
        $text = $map->text($l->file);
        if ($text === null || $l->line !== $l->endLine) { return $l; }
        $line = Position::lineText($text, $l->line);
        return ($line[$l->col - 1] ?? '') === '$' ? new Location($l->file, $l->line, $l->col + 1, $l->endLine, $l->endCol) : $l;
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
