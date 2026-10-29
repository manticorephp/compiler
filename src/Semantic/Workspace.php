<?php

namespace Semantic;

use Analyze\Analyzer;
use Analyze\Diagnostic;
use Analyze\Index;
use Analyze\MemberInfo;
use Analyze\ParseDiagnostics;
use Analyze\ParsedFile;
use Analyze\Report;
use Parser\Ast\ErrorExpr;
use Parser\Ast\Expr;

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
    private const BLOCKER_CAP = 50;

    private Index $index;
    private NameIndex $names;

    /** @var array<string, ParsedFile> uri => recovered AST of every open file, broken ones included */
    private array $asts = [];

    /** @var array<string, LineIndex> uri => line table of every open file */
    private array $lines = [];

    /** @var array<string, ExprAt> uri => position index of every open file */
    private array $exprAt = [];

    /** @var array<string, UnitLookup> uri => expression -> unit map, built on the first typed query; same lifetime as exprAt */
    private array $unitLookups = [];

    /** @var array<string, VarIndex> uri => variable bindings, built on the first variable query; same lifetime as exprAt */
    private array $varIndexes = [];

    /** @var array<string, array<string, MemberInfo[]|null>> uri => "line:col" => resolved member use site; cleared on ANY file change (a class elsewhere may have moved) */
    private array $siteCache = [];

    /** @var array<string, Diagnostic[]> uri => parse errors of a file whose text does not parse (kept after close) */
    private array $parseErrors = [];

    /** @var array<string, string> uri => open text (completion reads the line before the cursor) */
    private array $texts = [];

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
        $r = \Parser\Parser::parseTolerant($text, $uri, true, true, 2);
        $pf = ParsedFile::fromParse($uri, $text, $r);
        if ($pf->hasErrors) {
            $this->parseErrors[$uri] = ParseDiagnostics::of($uri, $r);
        } else {
            unset($this->parseErrors[$uri]);
        }
        unset($this->unitLookups[$uri], $this->varIndexes[$uri]);
        $this->siteCache = [];
        $this->asts[$uri] = $pf;
        $this->lines[$uri] = new LineIndex($text);
        $this->texts[$uri] = $text;
        $this->exprAt[$uri] = ExprAt::build($r->program);
        $this->index->replaceFile($pf);
        $this->names->replaceFile($uri, $r->program, $text);
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
        unset($this->asts[$uri], $this->lines[$uri], $this->exprAt[$uri], $this->unitLookups[$uri], $this->varIndexes[$uri], $this->texts[$uri]);
        unset($this->siteCache[$uri]);
    }

    public function remove(string $uri): void
    {
        self::assertUserUri($uri);
        unset($this->asts[$uri], $this->lines[$uri], $this->exprAt[$uri], $this->unitLookups[$uri], $this->varIndexes[$uri], $this->parseErrors[$uri], $this->texts[$uri]);
        $this->siteCache = [];
        $this->index->removeFile($uri);
        $this->names->removeFile($uri);
    }

    /** The innermost expression at (line 1-based, byte column 1-based) of an open file; null elsewhere. */
    public function nodeAt(string $uri, int $line, int $col): ?Expr
    {
        $ix = $this->exprAt[$uri] ?? null;
        return $ix === null ? null : $ix->innermost($line, $col);
    }

    /** Line table of an open file (offset <-> line/column); null when the file is not open. */
    public function lineIndex(string $uri): ?LineIndex
    {
        return $this->lines[$uri] ?? null;
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
     * Class and function names, and a declaration name under the cursor (an enum case included).
     * The range is the NAME token of the winning declaration; it falls back to the declaration
     * start (end equals start) when the declaring file holds no declaration entry for the name
     * (a library file, a member). A global constant (`const X`, `define('X')`) answers with its name literal, case-sensitively. An unqualified function call in a namespace falls back to the global
     * function when no namespaced one is declared, as PHP does.
     */
    public function definitionOf(string $uri, int $line, int $col): ?Location
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o === null || $o->kind === 'variable') {
            $vi = $this->varsAt($uri, $line, $col);
            if ($vi !== null) { return $vi->definitionOf($vi->bindingAt($line, $col)); }
            if ($o !== null) { return null; }
        }
        if ($o === null) {
            $k = $this->index->constAt($uri, $line, $col);
            return $k === null ? null : $this->constLocation($k);
        }
        if ($o->isDecl) { return new Location($o->file, $o->line, $o->col, $o->endLine, $o->endCol); }
        $kind = $o->denotes();
        if ($kind === 'constant') {
            $k = $this->constKeyOf($o);
            return $k === null ? null : $this->constLocation($k);
        }
        if ($kind === 'member') {
            $ms = $this->membersOf($o);
            return $ms === null || $ms === [] ? null : $this->memberLocation($ms[0]);
        }
        if ($kind !== 'class' && $kind !== 'function') { return null; }
        $name = $o->resolvedLower;
        $file = $this->index->declaringFile($name, $kind);
        if ($file === null && $o->globalFallback) {
            $name = self::bareName($name);
            $file = $this->index->declaringFile($name, $kind);
        }
        if ($file === null) { return null; }
        foreach ($this->names->declarations($kind, $name) as $d) {
            if ($d->file === $file) { return new Location($d->file, $d->line, $d->col, $d->endLine, $d->endCol); }
        }
        $span = $this->index->declSpan($name, $kind);
        if ($span === null) { return null; }
        return new Location($file, $span->startLine(), $span->startColumn(), $span->startLine(), $span->startColumn());
    }

    /** What the cursor names: 'member' | 'class' | 'function' | 'constant' | 'variable', null for nothing indexed. */
    public function symbolKindAt(string $uri, int $line, int $col): ?string
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o === null || $o->kind === 'variable') {
            if ($this->varsAt($uri, $line, $col) !== null) { return 'variable'; }
            if ($o !== null) { return 'variable'; }
            return $this->index->constAt($uri, $line, $col) === null ? null : 'constant';
        }
        return $o->denotes();
    }

    /**
     * Every declaration the symbol under the cursor can denote: all of a union receiver's members, else `definitionOf`.
     *
     * @return Location[]
     */
    public function definitionsOf(string $uri, int $line, int $col): array
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o !== null && $o->kind === 'member' && !$o->isDecl) {
            /** @var Location[] $out */
            $out = [];
            foreach ($this->membersOf($o) ?? [] as $m) {
                $l = $this->memberLocation($m);
                if ($l !== null) { $out[] = $l; }
            }
            return $out;
        }
        $d = $this->definitionOf($uri, $line, $col);
        return $d === null ? [] : [$d];
    }

    /**
     * Use sites and declaration names of the symbol, `use` imports included. An entry matches
     * by kind: a `use` import counts as its imported kind (class | function | constant), so
     * class Foo, function foo and constant FOO never collide. A global-fallback name that no
     * namespaced declaration answers for counts as the global name, so a global call and an
     * unqualified namespaced call to the same function find each other.
     * A variable (use, parameter, catch, global, static, closure `use` entry) answers with the sites of its binding (see VarIndex); a promoted
     * constructor parameter stays the PROPERTY (member wins) and its family also lists the constructor-local variable uses of the parameter.
     * A member (enum case, class constant, method, property) resolves through its receiver type: the whole override
     * family (Index::memberFamily: ancestors, descendants, interfaces, traits, siblings; private members and
     * constructors excepted) and every use site that resolves to one of them; a site whose receiver is unknown is
     * not listed (see uncertainSitesFor).
     *
     * @return Location[]
     */
    public function referencesTo(string $uri, int $line, int $col): array
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o === null || $o->kind === 'variable') {
            $vi = $this->varsAt($uri, $line, $col);
            if ($vi !== null) { return $vi->sitesOf($vi->bindingAt($line, $col)); }
            if ($o !== null) { return []; }
        }
        $ck = $o === null ? $this->index->constAt($uri, $line, $col) : ($o->denotes() === 'constant' ? $this->constKeyOf($o) : null);
        if ($ck !== null) { return $this->constReferences($ck); }
        if ($o === null) { return []; }
        $want = $this->targets($o);
        $kind = $o->denotes();
        if ($kind === 'member') { return $this->memberReferences($o); }
        $tail = $kind === 'constant' ? self::tail($o->resolved) : '';
        $out = [];
        foreach ($this->names->allOccurrences() as $r) {
            if ($r->denotes() !== $kind || ($kind === 'constant' && self::tail($r->resolved) !== $tail)) { continue; }
            foreach ($this->targets($r) as $t => $_) {
                if (isset($want[$t])) {
                    $out[] = new Location($r->file, $r->line, $r->col, $r->endLine, $r->endCol);
                    break;
                }
            }
        }
        return $out;
    }

    /** The declared constant a constant occurrence denotes: its resolved name, else the bare name under the global fallback. */
    private function constKeyOf(NameOccurrence $o): ?string
    {
        if ($o->resolved !== '' && $this->index->declaredConst($o->resolved) !== null) { return $o->resolved; }
        $bare = self::tail($o->resolved);
        if ($o->globalFallback && $bare !== '' && $this->index->declaredConst($bare) !== null) { return $bare; }
        return null;
    }

    private function constLocation(string $key): ?Location
    {
        $sp = $this->index->declaredConst($key);
        $file = $this->index->constDeclaringFile($key);
        if ($sp === null || $file === null) { return null; }
        return new Location($file, $sp->startLine(), $sp->startColumn(), $sp->endLine(), $sp->endColumn());
    }

    /** @return Location[] the declaration name literal, then every constant use and `use const` import that denotes it */
    private function constReferences(string $key): array
    {
        $decl = $this->constLocation($key);
        /** @var Location[] $out */
        $out = $decl === null ? [] : [$decl];
        foreach ($this->names->allOccurrences() as $r) {
            $rk = $this->constKeyOf($r);
            if ($r->denotes() !== 'constant' || $rk === null) { continue; }
            if (\strcmp(Index::constKey($rk), Index::constKey($key)) !== 0) { continue; }
            $out[] = new Location($r->file, $r->line, $r->col, $r->endLine, $r->endCol);
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

    /** Constant names are case-sensitive, their namespace part is not. */
    private static function tail(string $resolved): string
    {
        $bs = \strrpos($resolved, '\\');
        return $bs === false ? $resolved : \substr($resolved, $bs + 1);
    }

    private static function bareName(string $lower): string
    {
        $bs = \strrpos($lower, '\\');
        return $bs === false ? $lower : \substr($lower, $bs + 1);
    }

    /** @return UncertainSite[] */
    public function uncertainSitesFor(string $uri, int $line, int $col): array
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o === null || $o->kind === 'variable') {
            $vi = $this->varsAt($uri, $line, $col);
            if ($vi !== null) { return $this->variableUncertain($uri, $vi, $vi->bindingAt($line, $col)); }
            if ($o !== null) { return []; }
        }
        if ($o === null) { return []; }
        $out = $this->names->uncertainSites($o->resolvedLower);
        if ($o->kind !== 'member') { return $out; }
        foreach ($this->membersOf($o) ?? [] as $m) {
            foreach ($this->index->familyAdaptations($m) as $a) { $out[] = new UncertainSite($a[0], $a[1], $a[2], 'trait-adaptation'); }
        }
        $family = $this->familyOf($o);
        foreach ($this->names->allOccurrences() as $c) {
            if (!$this->sameMemberName($c, $o) || $c->isDecl || ($family !== '' && self::familyOfRole($c->role) !== $family)) { continue; }
            if ($this->resolveSite($c) === null) { $out[] = new UncertainSite($c->file, $c->line, $c->col, 'unresolved-receiver'); }
        }
        return $out;
    }
    /** The type of the innermost expression at the position; null in whitespace, inside an error expression, or when not inferable. */
    public function typeAt(string $uri, int $line, int $col): ?SourceType
    {
        $at = $this->exprAt[$uri] ?? null;
        $pf = $this->asts[$uri] ?? null;
        if ($at === null || $pf === null) { return null; }
        $chain = $at->chain($line, $col);
        if (\count($chain) === 0) { return null; }
        foreach ($chain as $n) { if ($n instanceof ErrorExpr) { return null; } }
        $node = $chain[\count($chain) - 1];
        $env = $this->unitsOf($uri, $pf)->envAt($node, $this->index, $uri);
        return (new ExprTyper($this->index, $uri))->typeOf($node, $env);
    }

    /**
     * Candidates at the cursor (the position just after the typed text), at most Completer::CAP, sorted. The context is read
     * from the line before the cursor: `->` / `?->` (members of the receiver's type, visible from the enclosing class),
     * `::` (static members, constants, cases), `$name` (variables bound earlier in the scope, `$this` in a class), else a
     * bare name (classes, functions, constants). A receiver on an earlier line, or of unknown type, answers nothing.
     * Keywords are not offered. Never throws.
     *
     * The receiver is typed on a scratch parse of the text with a placeholder member in place of the typed word, so a
     * file that is broken at the cursor (`$a->` and nothing after) still answers.
     *
     * @return Completion[]
     */
    public function completionsAt(string $uri, int $line, int $col): array
    {
        try {
            return $this->complete($uri, $line, $col);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return Completion[] */
    private function complete(string $uri, int $line, int $col): array
    {
        $text = $this->texts[$uri] ?? null;
        $li = $this->lines[$uri] ?? null;
        if ($text === null || $li === null) { return []; }
        $start = $li->offsetOf($line, 1);
        $off = $li->offsetOf($line, $col);
        if ($start === null || $off === null) { return []; }
        $before = \substr($text, $start, $off - $start);
        $comp = new Completer($this->index);
        if (\preg_match('/(\?->|->|::)[ \t]*(\$?\w*)$/', $before, $m, PREG_OFFSET_CAPTURE) === 1) {
            $static = $m[1][0] === '::';
            $prefix = $m[2][0];
            $head = \rtrim(\substr($before, 0, $m[0][1]));
            if ($head === '') { return []; }
            $pcol = $col - \strlen($prefix);
            $rest = (string)\preg_replace('/^\w+/', '', \substr($text, $off));
            $r = $this->scratchReceiver($uri, \substr($text, 0, $off - \strlen($prefix)) . '__c;' . $rest, $line, $pcol, $static);
            if ($r === null) { return []; }
            [$classes, $env, $withInstance] = $r;
            return $comp->members($classes, $env->thisClass, $static, $withInstance, $prefix);
        }
        if (\preg_match('/\$(\w*)$/', $before, $m, PREG_OFFSET_CAPTURE) === 1) {
            $prefix = $m[1][0];
            $vi = $this->varsOf($uri);
            $names = $vi === null ? [] : $vi->namesBefore($line, $col - \strlen($prefix) - 1);
            if ($this->insideClass($uri, $line, $col)) { $names[] = 'this'; }
            $out = [];
            foreach (\array_unique($names) as $n) {
                if ($prefix === '' || \stripos($n, $prefix) === 0) { $out[] = new Completion('$' . $n, 'variable'); }
            }
            \usort($out, fn(Completion $a, Completion $b): int => \strcmp($a->label, $b->label));
            return \array_slice($out, 0, Completer::CAP);
        }
        if (\preg_match('/(?<![\w$>:\\\\])(\\\\?[A-Za-z_][\w\\\\]*)$/', $before, $m) === 1) {
            return $comp->symbols($m[1]);
        }
        return [];
    }

    /**
     * Classes the receiver of the placeholder member `__c` at (line, col) may be, the environment there, and whether
     * instance members are reachable through a static reference (`self`, `static`, `parent`).
     *
     * @return array{0: string[], 1: TypeEnv, 2: bool}|null
     */
    private function scratchReceiver(string $uri, string $patched, int $line, int $col, bool $static): ?array
    {
        $r = \Parser\Parser::parseTolerant($patched, $uri, true, true, 2);
        $node = null;
        foreach (\array_reverse(ExprAt::build($r->program)->chain($line, $col)) as $n) {
            if (($n instanceof \Parser\Ast\PropertyAccess && $n->property === '__c')
                || ($n instanceof \Parser\Ast\StaticAccess && $n->name === '__c')) { $node = $n; break; }
        }
        if ($node === null) { return null; }
        $env = UnitLookup::build($r->program)->envAt($node, $this->index, $uri);
        $typer = new ExprTyper($this->index, $uri);
        if ($node instanceof \Parser\Ast\StaticAccess) {
            $c = $typer->classRef($node->class, $env);
            $low = \strtolower($node->class);
            return $c === null ? null : [[$c], $env, $low === 'self' || $low === 'static' || $low === 'parent'];
        }
        $classes = $typer->classesOf($typer->typeOf($node->object, $env), $env);
        return $classes === null ? null : [$classes, $env, false];
    }

    private function insideClass(string $uri, int $line, int $col): bool
    {
        $pf = $this->asts[$uri] ?? null;
        return $pf !== null && self::inClass($pf->program->statements, $line, $col);
    }

    /** @param \Parser\Ast\Stmt[] $stmts */
    private static function inClass(array $stmts, int $line, int $col): bool
    {
        $p = $line * 4294967296 + $col;
        foreach ($stmts as $st) {
            if ($st instanceof \Parser\Ast\NamespaceStmt && $st->body !== null && self::inClass($st->body->statements, $line, $col)) { return true; }
            if (!($st instanceof \Parser\Ast\ClassStmt)) { continue; }
            $sp = $st->decl->span;
            $end = $sp->endLine() === 0 ? PHP_INT_MAX : $sp->endLine() * 4294967296 + $sp->endColumn();
            if ($p >= $sp->startLine() * 4294967296 + $sp->startColumn() && $p <= $end) { return true; }
        }
        return false;
    }

    /** Declared signature of a member under the cursor (`Foo::bar(int $a): ?Baz`), else the type of the expression there. */
    public function hover(string $uri, int $line, int $col): ?HoverInfo
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o !== null && $o->kind === 'member') {
            $ms = $this->membersOf($o);
            if ($ms !== null && \count($ms) > 0) {
                $parts = [];
                foreach ($ms as $m) { $parts[] = $this->signature($m); }
                return new HoverInfo(\implode("\nor ", $parts), new Location($o->file, $o->line, $o->col, $o->endLine, $o->endCol));
            }
        }
        $t = $this->typeAt($uri, $line, $col);
        $n = $this->nodeAt($uri, $line, $col);
        if ($t === null || $n === null) { return null; }
        $sp = $n->span;
        return new HoverInfo(self::shown($t->toString()), new Location($uri, $sp->startLine(), $sp->startColumn(), $sp->endLine(), $sp->endColumn()));
    }

    /**
     * Reasons a rename of the symbol under the cursor cannot be done mechanically, empty when it can:
     * `unresolved-member`, `owner-unknown` (class nested in a closure / loop), `implicit-member`, `library-declaration`,
     * `trait-adaptation:<file>:<line>:<col>` for an `insteadof` / `as` rule naming the method in a class the family reaches,
     * one `named-argument:<file>:<line>:<col>` per named argument (call or attribute) spelled like the member (a promoted
     * property is also a parameter name), `parse-error-region:<file>` for every workspace file with syntax errors (an error region can hide uses; a variable rename: its own file),
     * `heredoc-scan-failed:<file>` when the heredoc scan gave up, and one `heredoc-interpolation:<file>:<line>:<col>` per heredoc in the workspace (they hold unindexed uses).
     *
     * @return string[]
     */
    public function renameBlockers(string $uri, int $line, int $col): array
    {
        $o = $this->names->occurrenceAt($uri, $line, $col);
        if ($o === null || $o->kind === 'variable') {
            $vi = $this->varsAt($uri, $line, $col);
            if ($vi !== null) { return $this->variableBlockers($uri, $vi, $vi->bindingAt($line, $col)); }
            if ($o !== null) { return []; }
        }
        if ($o === null) { return []; }
        $out = [];
        if ($o->kind === 'member') {
            $ms = $this->membersOf($o);
            if ($ms === null) { $out[] = $o->isDecl ? 'owner-unknown' : 'unresolved-member'; }
            foreach ($ms ?? [] as $m) {
                if ($m->implicit) { $out[] = 'implicit-member'; }
                if (\str_starts_with($m->file, LibrarySymbols::LABEL_PREFIX)) { $out[] = 'library-declaration'; }
                $d = $this->names->occurrenceAt($m->file, $m->line, $m->col);
                if ($d !== null && $d->isDecl && $d->owner === '') { $out[] = 'owner-unknown'; }
                foreach ($this->index->familyAdaptations($m) as $a) { $out[] = 'trait-adaptation:' . $a[0] . ':' . $a[1] . ':' . $a[2]; }
            }
        }
        foreach (\array_keys($this->parseErrors) as $bad) { $out[] = 'parse-error-region:' . $bad; }
        foreach ($this->names->uncertainSites('') as $s) {
            if ($s->reason === 'heredoc-interpolation') { $out[] = 'heredoc-interpolation:' . $s->file . ':' . $s->line . ':' . $s->col; }
            if ($s->reason === 'heredoc-scan-failed') { $out[] = 'heredoc-scan-failed:' . $s->file; }
        }
        foreach ($this->capped($this->uncertainSitesFor($uri, $line, $col), $out) as $b) { $out[] = $b; }
        return \array_values(\array_unique($out));
    }

    /**
     * One blocker `reason:file:line:col` per uncertain site, at most BLOCKER_CAP per reason, then `reason:…+N more`
     * (`unresolved-receiver` is reported as `unresolved-member`). Blockers already in `$have` are skipped.
     *
     * @param UncertainSite[] $sites
     * @param string[] $have
     * @return string[]
     */
    private function capped(array $sites, array $have): array
    {
        /** @var array<string, int> $count */
        $count = [];
        $out = [];
        foreach ($sites as $s) {
            $r = $s->reason === 'unresolved-receiver' ? 'unresolved-member' : $s->reason;
            $b = $r === 'heredoc-scan-failed' ? $r . ':' . $s->file : $r . ':' . $s->file . ':' . $s->line . ':' . $s->col;
            if (\in_array($b, $have, true)) { continue; }
            $count[$r] = ($count[$r] ?? 0) + 1;
            if ($count[$r] <= self::BLOCKER_CAP) { $out[] = $b; }
        }
        foreach ($count as $r => $n) {
            if ($n > self::BLOCKER_CAP) { $out[] = $r . ':…+' . ($n - self::BLOCKER_CAP) . ' more'; }
        }
        return $out;
    }

    /** The variable bindings of an open file, built on first use. */
    private function varsOf(string $uri): ?VarIndex
    {
        $pf = $this->asts[$uri] ?? null;
        if ($pf === null) { return null; }
        if (!isset($this->varIndexes[$uri])) { $this->varIndexes[$uri] = VarIndex::build($pf->program, $uri); }
        return $this->varIndexes[$uri];
    }

    /** The file's bindings when a variable (a use, or a parameter / catch / global / static declaration) covers the point. */
    private function varsAt(string $uri, int $line, int $col): ?VarIndex
    {
        $vi = $this->varsOf($uri);
        return $vi !== null && $vi->bindingAt($line, $col) >= 0 ? $vi : null;
    }

    /**
     * Uncertain sites of a variable: heredoc text, `compact('x')`, dynamic-variable and include scopes it is visible in,
     * and (`global-scope`) the `global` statements and `{main}` variables of the same name in OTHER files when the
     * variable is a `{main}` one or reached by `global`.
     *
     * @return UncertainSite[]
     */
    private function variableUncertain(string $uri, VarIndex $vi, int $b): array
    {
        $out = $vi->uncertainOf($b);
        foreach ($this->names->legacySites($uri) as $l) { $out[] = $l; }
        if (!$vi->isGlobalScoped($b) || $vi->isSpecial($b)) { return $out; }
        $name = $vi->nameOf($b);
        foreach ($this->names->allOccurrences() as $c) {
            if ($c->file !== $uri && $c->kind === 'variable' && $c->role === 'global' && $c->resolved === $name) {
                $out[] = new UncertainSite($c->file, $c->line, $c->col, 'global-scope');
            }
        }
        foreach ($this->asts as $other => $_) {
            if ($other === $uri) { continue; }
            foreach ($this->names->legacySites($other) as $l) { $out[] = $l; }
            $ov = $this->varsOf($other);
            $ob = $ov === null ? -1 : $ov->mainBinding($name);
            if ($ob < 0) { continue; }
            foreach ($ov->sitesOf($ob) as $l) { $out[] = new UncertainSite($l->file, $l->line, $l->col, 'global-scope'); }
        }
        /** @var array<string, UncertainSite> $seen */
        $seen = [];
        foreach ($out as $s) { $seen[$s->reason . ':' . $s->file . ':' . $s->line . ':' . $s->col] = $s; }
        return \array_values($seen);
    }

    /**
     * `special-variable` (`$this`, superglobals), `promoted-parameter` (the variable is a promoted constructor parameter:
     * rename it from the parameter, which renames the property too), and every uncertain site as `reason:file:line:col`.
     *
     * @return string[]
     */
    private function variableBlockers(string $uri, VarIndex $vi, int $b): array
    {
        $out = [];
        if (isset($this->parseErrors[$uri])) { $out[] = 'parse-error-region:' . $uri; }
        if ($vi->isSpecial($b)) { $out[] = 'special-variable'; }
        foreach ($vi->declSitesOf($b) as $d) {
            $m = $this->names->occurrenceAt($uri, $d->line, $d->col);
            if ($m !== null && $m->kind === 'member') { $out[] = 'promoted-parameter'; }
        }
        foreach ($this->variableUncertain($uri, $vi, $b) as $s) { $out[] = $s->reason . ':' . $s->file . ':' . $s->line . ':' . $s->col; }
        return \array_values(\array_unique($out));
    }

    private function unitsOf(string $uri, ParsedFile $pf): UnitLookup
    {
        if (!isset($this->unitLookups[$uri])) { $this->unitLookups[$uri] = UnitLookup::build($pf->program); }
        return $this->unitLookups[$uri];
    }

    /** @return MemberInfo[]|null the declarations a member occurrence denotes: a use site's resolved ones, a declaration site itself */
    private function membersOf(NameOccurrence $o): ?array
    {
        if ($o->kind !== 'member') { return null; }
        if (!$o->isDecl) { return $this->resolveSite($o); }
        if ($o->owner === '') { return null; }
        $ci = $this->index->findClass($o->owner);
        if ($ci === null) { return null; }
        foreach ($ci->members as $m) {
            if ($m->file === $o->file && $m->line === $o->line && $m->col === $o->col) { return [$m]; }
        }
        return null;
    }

    /** @return MemberInfo[]|null */
    private function resolveSite(NameOccurrence $o): ?array
    {
        $at = $this->exprAt[$o->file] ?? null;
        $pf = $this->asts[$o->file] ?? null;
        if ($at === null || $pf === null) { return null; }
        $k = $o->line . ':' . $o->col;
        if (isset($this->siteCache[$o->file]) && \array_key_exists($k, $this->siteCache[$o->file])) { return $this->siteCache[$o->file][$k]; }
        $r = (new MemberResolver($this->index))->resolve($o->file, $o, $at, $this->unitsOf($o->file, $pf));
        $this->siteCache[$o->file][$k] = $r;
        return $r;
    }

    private function memberLocation(MemberInfo $m): ?Location
    {
        if ($m->implicit || $m->line === 0) { return null; }
        $d = $this->names->occurrenceAt($m->file, $m->line, $m->col);
        if ($d !== null && $d->isDecl && $d->kind === 'member') { return new Location($d->file, $d->line, $d->col, $d->endLine, $d->endCol); }
        return new Location($m->file, $m->line, $m->col, $m->line, $m->col + \strlen($m->name));
    }

    /** @return Location[] */
    private function memberReferences(NameOccurrence $o): array
    {
        $ms = $this->membersOf($o);
        if ($ms === null) { return [new Location($o->file, $o->line, $o->col, $o->endLine, $o->endCol)]; }
        /** @var array<string, bool> $want */
        $want = [];
        /** @var array<string, Location> $out */
        $out = [];
        foreach ($ms as $m) {
            foreach ($this->index->memberFamily($m) as $fm) {
                $want[MemberResolver::key($fm)] = true;
                $l = $this->memberLocation($fm);
                if ($l !== null) { $out[self::locKey($l)] = $l; }
                // a promoted constructor parameter is also a variable: its uses in the constructor body belong to the family
                $vi = $fm->kind === 'prop' && $l !== null ? $this->varsOf($l->file) : null;
                $vb = $vi === null ? -1 : $vi->declAt($l->line, $l->col);
                if ($vi !== null && $vb >= 0) {
                    foreach ($vi->sitesOf($vb) as $vl) { $out[self::locKey($vl)] = $vl; }
                }
            }
        }
        $family = $this->familyOf($o);
        foreach ($this->names->allOccurrences() as $c) {
            if ($c->isDecl || !$this->sameMemberName($c, $o) || ($family !== '' && self::familyOfRole($c->role) !== $family)) { continue; }
            $rs = $this->resolveSite($c);
            if ($rs === null) { continue; }
            foreach ($rs as $m) {
                if (isset($want[MemberResolver::key($m)])) {
                    $l = new Location($c->file, $c->line, $c->col, $c->endLine, $c->endCol);
                    $out[self::locKey($l)] = $l;
                    break;
                }
            }
        }
        return \array_values($out);
    }

    private static function locKey(Location $l): string
    {
        return $l->file . ':' . $l->line . ':' . $l->col;
    }

    /** Same bare member name (methods compare case-insensitively). */
    private function sameMemberName(NameOccurrence $a, NameOccurrence $b): bool
    {
        if ($a->kind !== 'member') { return false; }
        $x = \ltrim($a->resolved, '$');
        $y = \ltrim($b->resolved, '$');
        return $x === $y || (\strtolower($x) === \strtolower($y) && ($a->role === 'method' || $b->role === 'method' || $b->role === ''));
    }

    /** method | prop | const of a member occurrence ('' when it cannot be told: a declaration whose class is unknown). */
    private function familyOf(NameOccurrence $o): string
    {
        if (!$o->isDecl) { return self::familyOfRole($o->role); }
        $ms = $this->membersOf($o);
        if ($ms === null || \count($ms) === 0) { return ''; }
        return $ms[0]->kind === 'case' ? 'const' : $ms[0]->kind;
    }

    private static function familyOfRole(string $role): string
    {
        return $role === 'sprop' ? 'prop' : $role;
    }

    /** The internal anonymous class name rendered the way PHP prints it. */
    private static function shown(string $s): string
    {
        return (string)\preg_replace('/__anon_class_[A-Za-z0-9_]+/', 'class@anonymous', $s);
    }

    private function signature(MemberInfo $m): string
    {
        $n = ($m->isStatic ? 'static ' : '') . self::shown($m->declClass) . '::';
        if ($m->kind === 'prop') { return $n . '$' . $m->name . ($m->typeHint !== null ? ': ' . $m->typeHint : ''); }
        if ($m->kind !== 'method') { return $n . $m->name . ($m->kind === 'const' && $m->typeHint !== null ? ': ' . $m->typeHint : ''); }
        $mi = $this->index->findMethod($m->declClass, \strtolower($m->name), 0);
        if ($mi === null) { return $n . $m->name; }
        $ps = [];
        foreach ($mi->params as $p) {
            $ps[] = ($p->typeHint !== null ? $p->typeHint . ' ' : '') . ($p->byRef ? '&' : '') . ($p->variadic ? '...' : '') . '$' . $p->name
                . ($p->default !== null ? ' = ' . self::defaultText($p->default) : '');
        }
        return $n . $m->name . '(' . \implode(', ', $ps) . ')' . ($mi->returnType !== null ? ': ' . $mi->returnType : '');
    }

    /**
     * Declarations of an open file as a tree: types with their members underneath, namespaces not wrapped.
     * Names come from the parser's declaration name table; a node's range is its AST span (the name range
     * when the span carries no end). Closures and nested functions are not listed.
     *
     * @return DocumentSymbol[] [] for a file that is not open
     */
    public function documentSymbols(string $uri): array
    {
        $pf = $this->asts[$uri] ?? null;
        if ($pf === null) { return []; }
        /** @var \Parser\Ast\NameRef[] $decls */
        $decls = [];
        foreach ($pf->program->names as $n) {
            if ($n->role === 'decl') { $decls[] = $n; }
        }
        return self::outline($uri, $pf->program->statements, $decls);
    }

    /**
     * @param \Parser\Ast\Stmt[] $stmts
     * @param \Parser\Ast\NameRef[] $decls
     * @return DocumentSymbol[]
     */
    private static function outline(string $uri, array $stmts, array $decls): array
    {
        $out = [];
        foreach ($stmts as $st) {
            if ($st instanceof \Parser\Ast\NamespaceStmt) {
                if ($st->body !== null) {
                    foreach (self::outline($uri, $st->body->statements, $decls) as $c) { $out[] = $c; }
                }
            } elseif ($st instanceof \Parser\Ast\FunctionStmt) {
                $s = self::symbol($uri, $st->decl->name, 'function', 'function', $st->decl->span, $decls, []);
                if ($s !== null) { $out[] = $s; }
            } elseif ($st instanceof \Parser\Ast\ClassStmt) {
                $c = $st->decl;
                /** @var DocumentSymbol[] $members */
                $members = [];
                foreach ($c->cases as $m) { self::push($members, self::symbol($uri, $m->name, 'member', 'case', $m->span ?? $c->span, $decls, [])); }
                foreach ($c->consts as $m) { self::push($members, self::symbol($uri, $m->name, 'member', 'constant', $m->span, $decls, [])); }
                foreach ($c->properties as $m) { self::push($members, self::symbol($uri, $m->name, 'member', 'property', $m->span, $decls, [])); }
                foreach ($c->methods as $m) { self::push($members, self::symbol($uri, $m->name, 'member', 'method', $m->span, $decls, [])); }
                usort($members, fn(DocumentSymbol $a, DocumentSymbol $b): int => [$a->range->line, $a->range->col] <=> [$b->range->line, $b->range->col]);
                $s = self::symbol($uri, $c->name, 'class', $c->kind, $c->span, $decls, $members);
                if ($s !== null) { $out[] = $s; }
            }
        }
        return $out;
    }

    /** @param DocumentSymbol[] $to */
    private static function push(array &$to, ?DocumentSymbol $s): void
    {
        if ($s !== null) { $to[] = $s; }
    }

    /**
     * @param \Parser\Ast\NameRef[] $decls
     * @param DocumentSymbol[] $children
     */
    private static function symbol(string $uri, string $name, string $nameKind, string $kind, \Parser\Ast\Span $sp, array $decls, array $children): ?DocumentSymbol
    {
        $bare = $nameKind === 'member' ? $name : (\strrchr($name, '\\') === false ? $name : \substr(\strrchr($name, '\\'), 1));
        $from = $sp->startLine() * 1048576 + $sp->startColumn();
        $to = $sp->endLine() === 0 ? PHP_INT_MAX : $sp->endLine() * 1048576 + $sp->endColumn();
        foreach ($decls as $n) {
            if ($n->kind !== $nameKind || ltrim($n->written, '$') !== $bare) { continue; }
            $at = $n->span->startLine() * 1048576 + $n->span->startColumn();
            if ($at < $from || $at > $to) { continue; }
            $nl = $n->span->endLine() === 0 ? $n->span->startLine() : $n->span->endLine();
            $nc = $n->span->endLine() === 0 ? $n->span->startColumn() + strlen($n->written) : $n->span->endColumn();
            $nameRange = new Location($uri, $n->span->startLine(), $n->span->startColumn(), $nl, $nc);
            $range = $sp->endLine() === 0
                ? $nameRange
                : new Location($uri, $sp->startLine(), $sp->startColumn(), $sp->endLine(), $sp->endColumn());
            return new DocumentSymbol($bare, $kind, $range, $nameRange, $children);
        }
        return null;
    }


    /** Source-ish text of a parameter default: literals as written, anything else as `…`. */
    private static function defaultText(Expr $e): string
    {
        if ($e instanceof \Parser\Ast\IntLiteral) { return (string)$e->value; }
        if ($e instanceof \Parser\Ast\StringLiteral) { return "'" . $e->value . "'"; }
        if ($e instanceof \Parser\Ast\BoolLiteral) { return $e->value ? 'true' : 'false'; }
        if ($e instanceof \Parser\Ast\NullLiteral) { return 'null'; }
        if ($e instanceof \Parser\Ast\Identifier) { return $e->name; }
        return '…';
    }
}
