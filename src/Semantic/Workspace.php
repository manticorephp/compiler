<?php

namespace Semantic;

use Analyze\Analyzer;
use Analyze\Diagnostic;
use Analyze\Index;
use Analyze\ParseDiagnostics;
use Analyze\ParsedFile;
use Analyze\Report;

/**
 * Incremental analysis of a set of files: text in, diagnostics and name queries out.
 *
 * Library files are labelled `lib:prelude/<name>.php` (see LibrarySymbols); that scheme is
 * reserved, and open/update/close/remove throw InvalidArgumentException for such a URI. A
 * `lib:` label is not a path on disk: the host maps it to the prelude directory itself.
 *
 * Project membership: open/update add a file, close drops its text and AST but keeps its
 * Index and NameIndex entries, remove forgets the file. A file with syntax errors keeps the
 * AST the tolerant parser recovered and contributes its recovered declarations to the Index
 * (a declaration it lost stays until the file parses clean or is removed). Its diagnostics are
 * the analyzer output minus the findings a broken tree would make up, plus its parse.error ones.
 * Diagnostics are computed on each call from the current Index, so a change in one file is
 * seen by every dependent without invalidation bookkeeping.
 */
final class Workspace
{
    private Index $index;
    private NameIndex $names;

    /** @var array<string, ParsedFile> uri => recovered AST of every open file, broken ones included */
    private array $asts = [];

    /** @var array<string, Diagnostic[]> uri => parse errors of a file whose text does not parse (kept after close) */
    private array $parseErrors = [];

    public function __construct(private LibrarySymbols $lib, private bool $checkUndefined = true)
    {
        if (count($lib->preludeFiles()) === 0) {
            throw new \RuntimeException('Workspace: the prelude is empty (installed binary without its lib/ and prelude?)');
        }
        $this->index = new Index();
        $this->names = new NameIndex();
        foreach ($lib->preludeFiles() as $pf) { $this->index->replaceFile($pf); }
        $this->index->addExternFunctions($lib->stdlibFunctionNames());
    }

    public function open(string $uri, string $text): void
    {
        self::assertUserUri($uri);
        $r = \Parser\Parser::parseTolerant($text, $uri);
        $pf = ParsedFile::fromParse($uri, $text, $r);
        if ($pf->hasErrors) {
            $this->parseErrors[$uri] = ParseDiagnostics::of($uri, $r);
        } else {
            unset($this->parseErrors[$uri]);
        }
        $this->asts[$uri] = $pf;
        $this->index->replaceFile($pf);
        $this->names->replaceFile($uri, $r->program);
    }

    private static function assertUserUri(string $uri): void
    {
        if (\str_starts_with($uri, LibrarySymbols::LABEL_PREFIX)) {
            throw new \InvalidArgumentException('Workspace: the "lib:" URI scheme is reserved for library files: ' . $uri);
        }
    }

    public function update(string $uri, string $text): void
    {
        $this->open($uri, $text);
    }

    public function close(string $uri): void
    {
        self::assertUserUri($uri);
        unset($this->asts[$uri]);
    }

    public function remove(string $uri): void
    {
        self::assertUserUri($uri);
        unset($this->asts[$uri], $this->parseErrors[$uri]);
        $this->index->removeFile($uri);
        $this->names->removeFile($uri);
    }

    /** Files holding a live AST, one with syntax errors included; a closed file holds none. */
    public function openFileCount(): int
    {
        return count($this->asts);
    }

    /**
     * Sorted. A closed file has no AST and reports only the parse errors it had when closed.
     * In a file with syntax errors, findings that depend on the broken region are dropped: undefined
     * class / function when a use, namespace or declaration statement failed, and arg.count / arg.type /
     * return.* whose range holds a parse error or error expression.
     *
     * @return Diagnostic[]
     */
    public function diagnostics(string $uri): array
    {
        $pf = $this->asts[$uri] ?? null;
        if ($pf === null) { return $this->parseErrors[$uri] ?? []; }
        $analyzer = new Analyzer();
        /** @var Diagnostic[] $out */
        $out = [];
        foreach ($analyzer->runFile($pf, $this->index, $this->checkUndefined) as $d) {
            if (!$pf->hasErrors || !self::madeUpByBreak($pf, $d)) { $out[] = $d; }
        }
        foreach ($this->parseErrors[$uri] ?? [] as $d) { $out[] = $d; }
        return Report::sortDiags($out);
    }

    private static function madeUpByBreak(ParsedFile $pf, Diagnostic $d): bool
    {
        if ($pf->brokenPreamble && ($d->code === 'undefined.class' || $d->code === 'undefined.function')) { return true; }
        $ranged = $d->code === 'arg.count' || $d->code === 'arg.type' || \str_starts_with($d->code, 'return.');
        return $ranged && $pf->hitsRange($d->startLine, $d->startCol, $d->endLine, $d->endCol);
    }

    /**
     * Class and function names only: the Index records no global constants, so a constant
     * resolves to null. The span is the declaration's start; the end equals the start.
     * An unqualified function call in a namespace falls back to the global function when
     * no namespaced one is declared, as PHP does.
     */
    public function definitionOf(string $uri, int $line, int $col): ?Location
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o === null || $o->kind === 'constant' || $o->kind === 'namespace') { return null; }
        $kind = $o->kind === 'class' || $o->kind === 'function' ? $o->kind : null;
        $name = $o->resolvedLower;
        $file = $this->index->declaringFile($name, $kind);
        if ($file === null && $o->globalFallback) {
            $name = self::bareName($name);
            $file = $this->index->declaringFile($name, $kind);
        }
        $span = $this->index->declSpan($name, $kind);
        if ($file === null || $span === null) { return null; }
        return new Location($file, $span->startLine(), $span->startColumn(), $span->startLine(), $span->startColumn());
    }

    /**
     * Use sites of the resolved name, `use` imports included. A global-fallback name that
     * no namespaced declaration answers for counts as the global name, so a global call
     * and an unqualified namespaced call to the same function find each other. P2b gap:
     * the declaration site is not included, because declaration names are not NameRefs.
     *
     * @return Location[]
     */
    public function referencesTo(string $uri, int $line, int $col): array
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o === null) { return []; }
        $want = $this->targets($o);
        $out = [];
        foreach ($this->names->allOccurrences() as $r) {
            if (!self::kindsMatch($o->kind, $r->kind)) { continue; }
            foreach ($this->targets($r) as $t => $_) {
                if (isset($want[$t])) {
                    $out[] = new Location($r->file, $r->line, $r->col, $r->endLine, $r->endCol);
                    break;
                }
            }
        }
        return $out;
    }

    /** @return array<string, bool> the lowercased names an occurrence may denote */
    private function targets(NameOccurrence $o): array
    {
        $t = [$o->resolvedLower => true];
        if ($o->globalFallback && $this->index->declaringFile($o->resolvedLower, $o->kind) === null) {
            $t[self::bareName($o->resolvedLower)] = true;
        }
        return $t;
    }

    private static function bareName(string $lower): string
    {
        $bs = \strrpos($lower, '\\');
        return $bs === false ? $lower : \substr($lower, $bs + 1);
    }

    private static function kindsMatch(string $a, string $b): bool
    {
        if ($a === $b) { return true; }
        $named = ['class' => true, 'function' => true, 'constant' => true];
        return ($a === 'use' && isset($named[$b])) || ($b === 'use' && isset($named[$a]));
    }
    /** @return UncertainSite[] */
    public function uncertainSitesFor(string $uri, int $line, int $col): array
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        return $o === null ? [] : $this->names->uncertainSites($o->resolvedLower);
    }
}
