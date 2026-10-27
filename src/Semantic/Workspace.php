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
 * Project membership: open/update add a file, close drops its text and AST but keeps its
 * Index and NameIndex entries, remove forgets the file. A file with syntax errors reports
 * only its parse.error diagnostics and contributes nothing to the Index while it has them.
 * Diagnostics are computed on each call from the current Index, so a change in one file is
 * seen by every dependent without invalidation bookkeeping.
 */
final class Workspace
{
    private Index $index;
    private NameIndex $names;

    /** @var array<string, ParsedFile> uri => AST of every open file */
    private array $asts = [];

    /** @var array<string, Diagnostic[]> uri => parse errors of a file whose text does not parse */
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
        $r = \Parser\Parser::parseTolerant($text, $uri);
        if (count($r->errors) > 0) {
            $this->parseErrors[$uri] = ParseDiagnostics::of($uri, $r);
            $this->index->removeFile($uri);
            $this->names->removeFile($uri);
            $this->asts[$uri] = new ParsedFile($uri, $r->program);
            return;
        }
        unset($this->parseErrors[$uri]);
        $pf = new ParsedFile($uri, $r->program);
        $this->asts[$uri] = $pf;
        $this->index->replaceFile($pf);
        $this->names->replaceFile($uri, $r->program);
    }

    public function update(string $uri, string $text): void
    {
        $this->open($uri, $text);
    }

    public function close(string $uri): void
    {
        unset($this->asts[$uri]);
    }

    public function remove(string $uri): void
    {
        unset($this->asts[$uri], $this->parseErrors[$uri]);
        $this->index->removeFile($uri);
        $this->names->removeFile($uri);
    }

    public function openFileCount(): int
    {
        return count($this->asts);
    }

    /**
     * Sorted. A closed file has no AST and reports only the parse errors it had when closed.
     *
     * @return Diagnostic[]
     */
    public function diagnostics(string $uri): array
    {
        if (isset($this->parseErrors[$uri])) { return $this->parseErrors[$uri]; }
        if (!isset($this->asts[$uri])) { return []; }
        $analyzer = new Analyzer();
        return Report::sortDiags($analyzer->runFile($this->asts[$uri], $this->index, $this->checkUndefined));
    }

    /**
     * Class and function names only: the Index records no global constants, so a constant
     * resolves to null. The span is the declaration's start; the end equals the start.
     */
    public function definitionOf(string $uri, int $line, int $col): ?Location
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o === null) { return null; }
        $file = $this->index->declaringFile($o->resolvedLower);
        $span = $this->index->declSpan($o->resolvedLower);
        if ($file === null || $span === null) { return null; }
        return new Location($file, $span->startLine(), $span->startColumn(), $span->startLine(), $span->startColumn());
    }

    /**
     * Use sites of the resolved name only. P2b gap: the declaration site is not included,
     * because declaration names are not NameRefs.
     *
     * @return Location[]
     */
    public function referencesTo(string $uri, int $line, int $col): array
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o === null) { return []; }
        $out = [];
        foreach ($this->names->occurrences($o->kind, $o->resolvedLower) as $r) {
            $out[] = new Location($r->file, $r->line, $r->col, $r->endLine, $r->endCol);
        }
        return $out;
    }

    /** @return UncertainSite[] */
    public function uncertainSitesFor(string $uri, int $line, int $col): array
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        return $o === null ? [] : $this->names->uncertainSites($o->resolvedLower);
    }
}
