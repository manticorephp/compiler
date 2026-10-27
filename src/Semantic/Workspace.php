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
