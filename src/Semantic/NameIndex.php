<?php

namespace Semantic;

use Analyze\AstWalk;
use Analyze\DeclScopes;
use Parser\Ast\ClassStmt;
use Parser\Ast\FunctionStmt;
use Parser\Ast\DynamicStaticAccess;
use Parser\Ast\DynamicStaticCall;
use Parser\Ast\DynamicStaticMethodCall;
use Parser\Ast\DynProp;
use Parser\Ast\NamedArg;
use Parser\Ast\NewDynExpr;
use Parser\Ast\Program;
use Parser\Ast\StringLiteral;

/**
 * Per-file name occurrences plus the sites a name-based rename cannot be sure
 * about. Docblock sites (reason 'docblock') are not collected yet.
 */
final class NameIndex
{
    private const COL = 1048576;

    /** @var array<string, NameOccurrence[]> file => occurrences */
    private array $occ = [];

    /** @var array<string, array<string, UncertainSite[]>> file => lowercased literal => sites */
    private array $literals = [];

    /** @var array<string, array<string, UncertainSite[]>> file => lowercased argument name => named-argument sites */
    private array $named = [];

    /** @var array<string, UncertainSite[]> file => dynamic-member sites */
    private array $dynamic = [];

    public function replaceFile(string $path, Program $program, string $text): void
    {
        $this->removeFile($path);
        $list = [];
        $classes = self::classRanges($program->statements);
        $next = 0;
        /** @var int[] $open indexes into $classes of the classes containing the current name, innermost last */
        $open = [];
        foreach ($program->names as $n) {
            $s = $n->span;
            $endLine = $s->endLine();
            $endCol = $s->endColumn();
            if ($endLine === 0) {
                $endLine = $s->startLine();
                $endCol = $s->startColumn() + strlen($n->written);
            }
            $at = $s->startLine() * self::COL + $s->startColumn();
            while ($next < count($classes) && $classes[$next][0] <= $at) { $open[] = $next; $next++; }
            while ($open !== [] && $classes[$open[count($open) - 1]][1] <= $at) { array_pop($open); }
            $owner = $open === [] ? '' : $classes[$open[count($open) - 1]][2];
            $list[] = new NameOccurrence(
                $path, $n->kind, strtolower(ltrim($n->resolved, '\\')),
                $s->startLine(), $s->startColumn(), $endLine, $endCol, $n->globalFallback,
                $n->role === 'decl', $n->role === 'decl' ? '' : $n->role, ltrim($n->resolved, '\\'),
                ($n->role === 'decl' && $n->kind === 'member') ? $owner : '',
            );
        }
        $this->occ[$path] = $list;

        $w = new AstWalk();
        $w->stmts($program->statements);
        $lits = [];
        $dyn = [];
        /** @var array<string, UncertainSite[]> $named lowercased argument name => sites */
        $named = [];
        foreach ($w->exprs as $e) {
            if ($e instanceof StringLiteral) {
                if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*(::[A-Za-z_][A-Za-z0-9_]*)?\z/', $e->value) === 1) {
                    $reason = str_contains($e->value, '::') ? 'callable-string' : 'string-class-name';
                    $site = new UncertainSite($path, $e->span->startLine(), $e->span->startColumn(), $reason);
                    $full = strtolower(ltrim($e->value, '\\'));
                    $lits[$full][] = $site;
                    if ($reason === 'callable-string') { $lits[substr($full, 0, strpos($full, '::'))][] = $site; }
                }
            } elseif ($e instanceof NamedArg) {
                $named[strtolower($e->name)][] = new UncertainSite($path, $e->span->startLine(), $e->span->startColumn(), 'named-argument');
            } elseif (
                $e instanceof DynProp || $e instanceof NewDynExpr || $e instanceof DynamicStaticAccess
                || $e instanceof DynamicStaticCall || $e instanceof DynamicStaticMethodCall
            ) {
                $dyn[] = new UncertainSite($path, $e->span->startLine(), $e->span->startColumn(), 'dynamic-member');
            }
        }
        $this->literals[$path] = $lits;
        foreach (self::heredocSites($path, $text) as $s) { $dyn[] = $s; }
        foreach (self::attributeNamedArgs($path, $text) as $nm => $sites) {
            foreach ($sites as $s) { $named[$nm][] = $s; }
        }
        $this->named[$path] = $named;
        $this->dynamic[$path] = $dyn;
    }

    /**
     * Heredocs with interpolation: their variable and member uses carry no name entries, so each is a site
     * every rename must list. Found by text (a lookalike in a comment or string only adds a harmless site).
     *
     * @return UncertainSite[]
     */
    private static function heredocSites(string $path, string $text): array
    {
        $out = [];
        if ($text === '' || !str_contains($text, '<<<')) { return $out; }
        $n = preg_match_all('/<<<[ \t]*("?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n(.*?)\n[ \t]*\2(?![A-Za-z0-9_])/s', $text, $m, PREG_OFFSET_CAPTURE);
        if ($n === false) { return [new UncertainSite($path, 1, 1, 'heredoc-scan-failed')]; }
        for ($i = 0; $i < $n; $i++) {
            if (preg_match('/\\$[A-Za-z_{]|\{\\$/', $m[3][$i][0]) !== 1) { continue; }
            $out[] = self::siteAt($path, $text, $m[0][$i][1], 'heredoc-interpolation');
        }
        return $out;
    }

    private static function siteAt(string $path, string $text, int $off, string $reason): UncertainSite
    {
        $before = substr($text, 0, $off);
        $nl = strrpos($before, "\n");
        return new UncertainSite($path, 1 + substr_count($before, "\n"), $off - ($nl === false ? -1 : $nl), $reason);
    }

    /**
     * Named arguments inside attributes (`#[Foo(bar: 1)]`): attribute arguments are not in the expression walk, so
     * they are found by text. Over-inclusive on purpose (a ternary inside an attribute adds a harmless site).
     * Reason `named-argument`.
     *
     * @return array<string, UncertainSite[]> lowercased argument name => sites
     */
    private static function attributeNamedArgs(string $path, string $text): array
    {
        $out = [];
        $len = strlen($text);
        $at = 0;
        while (($at = strpos($text, '#[', $at)) !== false) {
            $depth = 0;
            $j = $at + 1;
            while ($j < $len) {
                $ch = $text[$j];
                if ($ch === '[') { $depth++; } elseif ($ch === ']') { $depth--; if ($depth === 0) { break; } }
                $j++;
            }
            $seg = substr($text, $at, $j - $at);
            if (preg_match_all('/(?<![A-Za-z0-9_$:\\\\])([A-Za-z_][A-Za-z0-9_]*)[ \t\r\n]*:(?!:)/', $seg, $m, PREG_OFFSET_CAPTURE) !== false) {
                foreach ($m[1] as $hit) { $out[strtolower($hit[0])][] = self::siteAt($path, $text, $at + $hit[1], 'named-argument'); }
            }
            $at = $at + 2;
        }
        return $out;
    }

    /**
     * Every class-like declaration with a range (hoisted anonymous classes included), by start, as
     * [start key, end key, fqn]; the fqn is Index's class key. Nested named classes are found in namespace,
     * conditional and function bodies.
     *
     * @param \Parser\Ast\Stmt[] $stmts
     * @return array<int, array{0: int, 1: int, 2: string}>
     */
    private static function classRanges(array $stmts): array
    {
        $out = [];
        self::collectClassRanges($stmts, $out);
        usort($out, fn($a, $b) => $a[0] <=> $b[0]);
        return $out;
    }

    /**
     * @param \Parser\Ast\Stmt[] $stmts
     * @param array<int, array{0: int, 1: int, 2: string}> $out
     */
    private static function collectClassRanges(array $stmts, array &$out): void
    {
        foreach ($stmts as $s) {
            if ($s instanceof ClassStmt) {
                $sp = $s->decl->span;
                if ($sp->endLine() > 0 && $s->decl->name !== '') {
                    $out[] = [$sp->startLine() * self::COL + $sp->startColumn(), $sp->endLine() * self::COL + $sp->endColumn(), $s->decl->name];
                }
            } elseif ($s instanceof FunctionStmt) {
                if ($s->decl->body !== null) { self::collectClassRanges($s->decl->body->statements, $out); }
            } else {
                foreach (DeclScopes::nested($s) as $body) { self::collectClassRanges($body, $out); }
            }
        }
    }

    public function removeFile(string $path): void
    {
        unset($this->occ[$path], $this->literals[$path], $this->dynamic[$path], $this->named[$path]);
    }

    /**
     * Uses and declaration names alike; a `use` import is matched by kind 'use', not by its role.
     *
     * @return NameOccurrence[]
     */
    public function occurrences(string $kind, string $resolvedLower): array
    {
        $out = [];
        foreach ($this->occ as $list) {
            foreach ($list as $o) {
                if ($o->kind === $kind && $o->resolvedLower === $resolvedLower) { $out[] = $o; }
            }
        }
        return $out;
    }

    /** @return NameOccurrence[] the declaration-name entries only */
    public function declarations(string $kind, string $resolvedLower): array
    {
        $out = [];
        foreach ($this->occurrences($kind, $resolvedLower) as $o) {
            if ($o->isDecl) { $out[] = $o; }
        }
        return $out;
    }

    /** @return NameOccurrence[] */
    public function allOccurrences(): array
    {
        $out = [];
        foreach ($this->occ as $list) {
            foreach ($list as $o) { $out[] = $o; }
        }
        return $out;
    }

    /** Start and end columns are both inclusive. A member entry wins over any other entry covering the point (a promoted constructor parameter is a variable AND a property). */
    public function occurrenceAt(string $path, int $line, int $col): ?NameOccurrence
    {
        $hit = null;
        foreach ($this->occ[$path] ?? [] as $o) {
            if ($line < $o->line || $line > $o->endLine) { continue; }
            if ($line === $o->line && $col < $o->col) { continue; }
            if ($line === $o->endLine && $col > $o->endCol) { continue; }
            if ($o->kind === 'member') { return $o; }
            if ($hit === null) { $hit = $o; }
        }
        return $hit;
    }

    /** @return UncertainSite[] */
    public function uncertainSites(string $resolvedLower): array
    {
        $key = strtolower(ltrim($resolvedLower, '\\'));
        $out = [];
        foreach ($this->literals as $byValue) {
            foreach ($byValue[$key] ?? [] as $s) { $out[] = $s; }
        }
        foreach ($this->dynamic as $sites) {
            foreach ($sites as $s) { $out[] = $s; }
        }
        foreach ($this->named as $byName) {
            foreach ($byName[$key] ?? [] as $s) { $out[] = $s; }
        }
        return $out;
    }
}
