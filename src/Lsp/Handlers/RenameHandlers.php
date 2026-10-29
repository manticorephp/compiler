<?php

namespace Lsp\Handlers;

use Lsp\ClosedFiles;
use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;
use Lsp\LocationMap;
use Lsp\MemberQuery;
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
    /** @var callable */
    private $annotated;
    /** @var callable */
    private $versions;

    /**
     * @param callable $docs returns array<string, string> uri => open text
     * @param callable $send takes one JSON message string
     * @param callable $annotated returns bool: the client takes documentChanges with change annotations
     * @param callable $versions returns array<string, int> uri => version of an open document
     */
    public function __construct(private Workspace $ws, callable $docs, private ClosedFiles $closed, callable $send, ?callable $annotated = null, ?callable $versions = null)
    {
        $this->docs = $docs;
        $this->send = $send;
        $this->annotated = $annotated ?? static fn(): bool => false;
        $this->versions = $versions ?? static fn(): array => [];
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
            $map = new LocationMap($this->docs);
            $tail = $this->tailRange($hit, $map);
            $m = $tail === null ? null : $map->toLsp($tail);
            if ($tail === null || $m === null) { return null; }
            return ['range' => $m['range'], 'placeholder' => $this->tailText($tail, $map)];
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
            $gather = fn(): array => [
                $this->ws->renameBlockers($at[0], $at[1], $at[2]),
                $this->editSet($at),
                $this->ws->uncertainSitesFor($at[0], $at[1], $at[2]),
                $this->ws->memberKindsAt($at[0], $at[1], $at[2]),
            ];
            /** @var array{0: string[], 1: Location[]|null, 2: UncertainSite[], 3: string[]} $g */
            $g = $this->query($at, $gather);
            if ($g[1] !== null) {
                $files = [];
                foreach ($g[1] as $l) { $files[$l->file] = $l->file; }
                if ($this->closed->prune(\array_values($files)) > 0) {
                    /** @var array{0: string[], 1: Location[]|null, 2: UncertainSite[], 3: string[]} $g */
                    $g = $this->query($at, $gather);
                }
            }
            [$all, $set, $uncertain, $memberKinds] = $g;
            $member = $this->member($at);
            $missed = $this->closed->unindexed() + ($member ? $this->closed->skipped() : 0);
            if ($missed > 0) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'rename incomplete: ' . $missed . ' workspace files could not be searched');
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
            $old = $this->oldName($at, $set, $map);
            if ($old === null) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'no renameable symbol here');
            }
            $kind = $this->kind($at);
            // methods, classes and functions are case-insensitive in PHP; properties, class constants, enum cases, constants and variables are not
            $ci = $kind === 'member' ? $memberKinds === ['method'] : ($kind !== 'constant' && $kind !== 'variable');
            $sigil = false;
            /** @var array<string, array<int, array<string, mixed>>> $changes */
            $changes = [];
            /** @var array<string, bool> $certain uri:line:char of every certain edit */
            $certain = [];
            foreach ($set as $l) {
                if ($l->endLine === $l->line && $l->endCol <= $l->col) { continue; }
                $tail = $this->tailRange($l, $map);
                if ($tail === null) {
                    throw new RpcError(RpcError::INVALID_PARAMS, 'index out of date for ' . $l->file);
                }
                $txt = $this->tailText($tail, $map);
                if (($ci ? \strcasecmp($txt, $old) : \strcmp($txt, $old)) !== 0) {
                    if (!$this->isAlias($kind, $txt, $l, $map)) {
                        throw new RpcError(RpcError::INVALID_PARAMS, 'index out of date for ' . $l->file);
                    }
                    continue;
                }
                if ($this->hasSigil($l, $map)) { $sigil = true; }
                $m = $map->toLsp($tail);
                if ($m === null) {
                    throw new RpcError(RpcError::INVALID_PARAMS, 'cannot edit library symbol: ' . $l->file);
                }
                $changes[(string)$m['uri']][] = ['range' => $m['range'], 'newText' => $bare];
                $certain[$m['uri'] . ':' . $m['range']['start']['line'] . ':' . $m['range']['start']['character']] = true;
            }
            if (!$sigil && $bare !== $new) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'newName is not a valid PHP identifier');
            }
            $flagged = [];
            if (($this->annotated)()) {
                $flagged = $this->flagged($uncertain, $old, $bare, $ci, $kind === 'variable', $certain, $map);
                foreach ($flagged as $u => $edits) {
                    foreach ($edits as $e) { $changes[$u][] = $e; }
                }
            }
            \ksort($changes);
            foreach ($changes as $u => $edits) {
                \usort($edits, fn(array $a, array $b): int => [$a['range']['start']['line'], $a['range']['start']['character']]
                    <=> [$b['range']['start']['line'], $b['range']['start']['character']]);
                $changes[$u] = $edits;
            }
            $nFlagged = 0;
            foreach ($flagged as $edits) { $nFlagged += \count($edits); }
            $this->warn($uncertain, $nFlagged);
            if ($nFlagged === 0) { return ['changes' => (object)$changes]; }
            return $this->annotatedEdit($changes);
        });
    }

    private const LABELS = [
        'unresolved-receiver' => 'Uncertain site (receiver type unknown)',
        'dynamic-member' => 'Uncertain site (dynamic member)',
        'string-class-name' => 'Uncertain site (class name in a string)',
        'callable-string' => 'Uncertain site (callable string)',
        'docblock' => 'Uncertain site (docblock mention)',
        'heredoc-interpolation' => 'Uncertain site (heredoc interpolation)',
    ];

    /**
     * Uncertain sites whose source text spells the old name as a whole identifier become edits flagged for confirmation;
     * all others are dropped.
     *
     * @param UncertainSite[] $sites
     * @param array<string, bool> $certain
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function flagged(array $sites, string $old, string $bare, bool $ci, bool $isVar, array $certain, LocationMap $map): array
    {
        $out = [];
        foreach ($sites as $s) {
            if (\str_starts_with($s->file, 'lib:')) { continue; }
            $text = $map->text($s->file);
            if ($text === null) { continue; }
            $line = Position::lineText($text, $s->line);
            $i = $s->col - 1;
            if (($line[$i] ?? '') === '"' || ($line[$i] ?? '') === "'") { $i++; }
            if ($isVar && ($line[$i] ?? '') === '$') { $i++; }
            $len = \strlen($old);
            $run = $i;
            while ($run < \strlen($line) && (self::isIdByte($line[$run]) || $line[$run] === '\\')) { $run++; }
            $cut = \strrpos(\substr($line, $i, $run - $i), '\\');
            $prev = $i > 0 ? $line[$i - 1] : '';
            if ($cut !== false) { $i = $i + $cut + 1; $prev = ''; }
            $seg = \substr($line, $i, $len);
            if ($len === 0 || $run - $i !== $len || ($ci ? \strcasecmp($seg, $old) : \strcmp($seg, $old)) !== 0) { continue; }
            if ($prev !== '$' && self::isIdByte($prev)) { continue; }
            $m = $map->toLsp(new Location($s->file, $s->line, $i + 1, $s->line, $i + 1 + $len));
            if ($m === null) { continue; }
            $key = $m['uri'] . ':' . $m['range']['start']['line'] . ':' . $m['range']['start']['character'];
            if (isset($certain[$key])) { continue; }
            $certain[$key] = true;
            $out[(string)$m['uri']][] = ['range' => $m['range'], 'newText' => $bare, 'annotationId' => 'uncertain:' . $s->reason];
        }
        return $out;
    }

    private static function isIdByte(string $c): bool
    {
        return $c !== '' && (\ctype_alnum($c) || $c === '_' || \ord($c) >= 0x80);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $changes
     * @return array<string, mixed>
     */
    private function annotatedEdit(array $changes): array
    {
        /** @var array<string, int> $versions */
        $versions = ($this->versions)();
        $docs = [];
        $ann = [];
        foreach ($changes as $u => $edits) {
            foreach ($edits as $e) {
                if (isset($e['annotationId'])) {
                    $reason = \substr((string)$e['annotationId'], 10);
                    $ann[(string)$e['annotationId']] = ['label' => self::LABELS[$reason] ?? 'Uncertain site (' . $reason . ')', 'needsConfirmation' => true, 'description' => $reason];
                }
            }
            $docs[] = ['textDocument' => ['uri' => $u, 'version' => $versions[$u] ?? null], 'edits' => $edits];
        }
        \ksort($ann);
        return ['documentChanges' => $docs, 'changeAnnotations' => (object)$ann];
    }

    /** renameBlockers also lists every uncertain site as `reason:file:line:col`; those warn instead of blocking. */
    private static function isUncertain(string $blocker): bool
    {
        $i = \strpos($blocker, ':');
        return $i !== false && \in_array(\substr($blocker, 0, $i), ['string-class-name', 'dynamic-member', 'callable-string', 'docblock', 'unresolved-member'], true);
    }

    /** @param UncertainSite[] $sites */
    private function warn(array $sites, int $flagged = 0): void
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
            'params' => ['type' => 2, 'message' => ($flagged > 0
                ? 'Rename flagged ' . $flagged . ' of ' . \count($items) . ' uncertain site(s) for confirmation: '
                : 'Rename left ' . \count($items) . ' uncertain site(s): ') . \implode(', ', \array_slice($items, 0, 10))],
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($msg !== false) { ($this->send)($msg); }
    }

    /** @param array{0: string, 1: int, 2: int} $at */
    private function kind(array $at): ?string
    {
        return $this->ws->symbolKindAt($at[0], $at[1], $at[2]);
    }

    /**
     * A member query needs the closed workspace files that mention its name opened for its duration (ClosedFiles::with).
     *
     * @param array{0: string, 1: int, 2: int} $at
     */
    private function query(array $at, callable $fn): mixed
    {
        /** @var array<string, string> $open */
        $open = ($this->docs)();
        return (new MemberQuery($this->ws, $this->closed))->run($at[0], $at[1], $at[2], Position::lineText($open[$at[0]] ?? '', $at[1]), $fn);
    }

    /** @param array{0: string, 1: int, 2: int} $at */
    private function member(array $at): bool
    {
        return $this->kind($at) === 'member';
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

    /**
     * The edit range of a site: the last `\`-separated segment of the written name, without a `$` sigil.
     * Null for a multi-line or unreadable site, or one whose text no longer holds a name there.
     */
    private function tailRange(Location $l, LocationMap $map): ?Location
    {
        $slice = $this->slice($l, $map);
        if ($slice === null || $slice === '') { return null; }
        $off = $slice[0] === '$' ? 1 : 0;
        $bs = \strrpos($slice, '\\');
        if ($bs !== false) { $off = \max($off, $bs + 1); }
        if ($off >= \strlen($slice)) { return null; }
        return new Location($l->file, $l->line, $l->col + $off, $l->endLine, $l->endCol);
    }

    private function tailText(Location $l, LocationMap $map): string
    {
        return $this->slice($l, $map) ?? '';
    }

    private function slice(Location $l, LocationMap $map): ?string
    {
        if ($l->line !== $l->endLine || $l->endCol <= $l->col) { return null; }
        $text = $map->text($l->file);
        if ($text === null) { return null; }
        $line = Position::lineText($text, $l->line);
        return \strlen($line) >= $l->endCol - 1 ? \substr($line, $l->col - 1, $l->endCol - $l->col) : null;
    }

    private function hasSigil(Location $l, LocationMap $map): bool
    {
        return \str_starts_with($this->slice($l, $map) ?? '', '$');
    }

    /**
     * The symbol's own name: a class, function or constant takes it from its declaration (the cursor may sit on an alias),
     * anything else from the site under the cursor.
     *
     * @param Location[] $set
     * @param array{0: string, 1: int, 2: int} $at
     */
    private function oldName(array $at, array $set, LocationMap $map): ?string
    {
        $kind = $this->kind($at);
        if ($kind === 'class' || $kind === 'function' || $kind === 'constant') {
            $decl = $this->ws->definitionOf($at[0], $at[1], $at[2]);
            $t = $decl === null || \str_starts_with($decl->file, 'lib:') ? null : $this->tailRange($decl, $map);
            if ($t !== null) { return $this->tailText($t, $map); }
        }
        $hit = $this->containing($set, $at);
        $t = $hit === null ? null : $this->tailRange($hit, $map);
        return $t === null ? null : $this->tailText($t, $map);
    }

    /** A class / function site spelled with an import alias (`use A\B as C; new C()`) or self / static / parent: left alone. */
    private function isAlias(?string $kind, string $txt, Location $l, LocationMap $map): bool
    {
        if (\in_array(\strtolower($txt), ['self', 'static', 'parent'], true)) { return true; }
        if ($kind !== 'class' && $kind !== 'function') { return false; }
        $text = $map->text($l->file);
        return $text !== null && \Semantic\ImportAliases::has($text, $txt);
    }

    /**
     * @param array<string, mixed> $p
     * @return array{0: string, 1: int, 2: int}|null
     */
    private function at(array $p): ?array
    {
        $uri = $p['textDocument']['uri'] ?? null;
        $uri = \is_string($uri) ? \Lsp\Uri::normalize($uri) : null;
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
