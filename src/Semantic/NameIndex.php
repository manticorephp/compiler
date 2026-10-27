<?php

namespace Semantic;

use Analyze\AstWalk;
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
        foreach ($program->names as $n) {
            $s = $n->span;
            $endLine = $s->endLine();
            $endCol = $s->endColumn();
            if ($endLine === 0) {
                $endLine = $s->startLine();
                $endCol = $s->startColumn() + strlen($n->written);
            }
            $list[] = new NameOccurrence(
                $path, $n->kind, strtolower(ltrim($n->resolved, '\\')),
                $s->startLine(), $s->startColumn(), $endLine, $endCol, $n->globalFallback,
                $n->role === 'decl', $n->role === 'decl' ? '' : $n->role, ltrim($n->resolved, '\\'),
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
