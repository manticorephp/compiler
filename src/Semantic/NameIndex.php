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

    /** @var array<string, UncertainSite[]> file => dynamic-member sites */
    private array $dynamic = [];

    public function replaceFile(string $path, Program $program): void
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
        foreach ($w->exprs as $e) {
            if ($e instanceof StringLiteral) {
                if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*(::[A-Za-z_][A-Za-z0-9_]*)?\z/', $e->value) === 1) {
                    $reason = str_contains($e->value, '::') ? 'callable-string' : 'string-class-name';
                    $site = new UncertainSite($path, $e->span->startLine(), $e->span->startColumn(), $reason);
                    $full = strtolower(ltrim($e->value, '\\'));
                    $lits[$full][] = $site;
                    if ($reason === 'callable-string') { $lits[substr($full, 0, strpos($full, '::'))][] = $site; }
                }
            } elseif (
                $e instanceof DynProp || $e instanceof NewDynExpr || $e instanceof DynamicStaticAccess
                || $e instanceof DynamicStaticCall || $e instanceof DynamicStaticMethodCall
            ) {
                $dyn[] = new UncertainSite($path, $e->span->startLine(), $e->span->startColumn(), 'dynamic-member');
            }
        }
        $this->literals[$path] = $lits;
        $this->dynamic[$path] = $dyn;
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
        unset($this->occ[$path], $this->literals[$path], $this->dynamic[$path]);
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

    /** Start and end columns are both inclusive. NameRefs never overlap, so the first hit is the only one. */
    public function occurrenceAt(string $path, int $line, int $col): ?NameOccurrence
    {
        foreach ($this->occ[$path] ?? [] as $o) {
            if ($line < $o->line || $line > $o->endLine) { continue; }
            if ($line === $o->line && $col < $o->col) { continue; }
            if ($line === $o->endLine && $col > $o->endCol) { continue; }
            return $o;
        }
        return null;
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
        return $out;
    }
}
