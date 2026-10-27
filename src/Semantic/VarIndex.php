<?php

namespace Semantic;

use Analyze\AstWalk;
use Analyze\Units;
use Parser\Ast\ArrowFn;
use Parser\Ast\CallExpr;
use Parser\Ast\Closure;
use Parser\Ast\Program;
use Parser\Ast\StringLiteral;
use Parser\Ast\Variable;

/**
 * Variable bindings of one file. A binding is one variable of one scope (`{main}`, a function, a method,
 * a closure or an arrow function); every `$name` of the file maps to a binding id.
 *
 * - a closure `use ($x)` shares the OUTER binding (by value or by reference alike), so the use-list entry
 *   and the inner uses rename together with the outer variable;
 * - an arrow function reads an outer name through the enclosing scope's binding unless it is one of its
 *   parameters (over-inclusive for a name the arrow only assigns, which PHP keeps local);
 * - `global $g` shares the file's `{main}` binding of `g`; `{main}` variables and `global` links are listed as
 *   uncertain sites (`global-scope`) of the other files by the Workspace, never guessed;
 * - `$this` and the superglobals are special: never renameable. `$this` of a closure follows its parent
 *   (a `static` closure opens its own); a superglobal is one binding for the whole file;
 * - a scope holding `compact` / `extract` / `get_defined_vars` / `func_get_args` / `eval` / a variable-variable
 *   (the parser keeps it as a nameless Variable) lists the call as an uncertain site (`dynamic-variable`) of
 *   every binding visible in it, and `include` / `require` as `include-scope`; `compact('a')` with literal
 *   names lists that literal for `a` alone;
 * - a variable inside heredoc text has no name-precise span: it is listed as an uncertain site
 *   (`heredoc-interpolation`) of its binding, never as a location.
 *
 * Site ranges cover `$name`, end exclusive. Built once per file version.
 */
final class VarIndex
{
    private const SUPER = [
        '_GET' => true, '_POST' => true, '_COOKIE' => true, '_FILES' => true, '_SERVER' => true,
        '_ENV' => true, '_REQUEST' => true, '_SESSION' => true, 'GLOBALS' => true,
    ];

    private const DYNAMIC_CALLS = [
        'extract' => true, 'get_defined_vars' => true, 'func_get_args' => true, 'func_get_arg' => true, 'eval' => true,
    ];

    private string $file = '';

    // scopes (parallel arrays)
    /** @var string[] main | fn | closure | arrow */
    private array $scKind = [];
    /** @var int[] enclosing scope id, -1 for a root */
    private array $scParent = [];
    /** @var bool[] */
    private array $scStatic = [];
    /** @var array<int, array<string, int>> scope => variable name => binding id */
    private array $scMap = [];
    /** @var array<int, UncertainSite[]> scope => scope-wide dynamic sites */
    private array $scDyn = [];

    // bindings (parallel arrays)
    /** @var string[] */
    private array $bName = [];
    /** @var bool[] */
    private array $bSpecial = [];
    /** @var bool[] a `{main}` variable or one linked by `global` */
    private array $bGlobal = [];
    /** @var array<int, int[]> binding => scopes it is visible in */
    private array $bScopes = [];
    /** @var array<int, int[]> binding => site ids */
    private array $bSites = [];
    /** @var array<int, UncertainSite[]> */
    private array $bUncertain = [];
    /** @var array<string, int> */
    private array $specialIds = [];

    // sites (parallel arrays)
    /** @var int[] */
    private array $sLine = [];
    /** @var int[] */
    private array $sCol = [];
    /** @var int[] */
    private array $sEndLine = [];
    /** @var int[] */
    private array $sEndCol = [];
    /** @var int[] */
    private array $sBind = [];
    /** @var bool[] */
    private array $sDecl = [];
    /** @var array<int, int[]> line => site ids starting or ending on it */
    private array $byLine = [];

    public static function build(Program $p, string $file): VarIndex
    {
        $vi = new VarIndex();
        $vi->file = $file;
        $vi->run($p);
        return $vi;
    }

    private function run(Program $p): void
    {
        $units = new Units();
        $units->collect($p->statements, $p->useAliases);
        /** @var array<int, int> $scopeOfNode spl_object_id of a Closure / ArrowFn => scope */
        $scopeOfNode = [];
        foreach ($units->units as $i => $u) {
            $this->scKind[$i] = $u->label === '{main}' ? 'main' : ($u->label === '{closure}' ? ($u->exprBody !== null ? 'arrow' : 'closure') : 'fn');
            $this->scParent[$i] = -1;
            $this->scStatic[$i] = false;
            $this->scMap[$i] = [];
            $this->scDyn[$i] = [];
            if ($u->node !== null) {
                $scopeOfNode[\spl_object_id($u->node)] = $i;
                $this->scStatic[$i] = ($u->node instanceof Closure || $u->node instanceof ArrowFn) && $u->node->isStatic;
            }
        }
        // pass 1: what each scope holds directly
        /** @var array<int, Variable[]> $vars */
        $vars = [];
        /** @var array<int, array<int, StringLiteral>> $compact scope => literal names of compact() */
        $compact = [];
        foreach ($units->units as $i => $u) {
            $vars[$i] = [];
            $compact[$i] = [];
            $w = new AstWalk(true);
            if ($u->exprBody !== null) { $w->exprTree($u->exprBody); } else { $w->stmts($u->stmts); }
            foreach ($w->exprs as $e) {
                if ($e instanceof Variable) {
                    $vars[$i][] = $e;
                } elseif ($e instanceof Closure || $e instanceof ArrowFn) {
                    $c = $scopeOfNode[\spl_object_id($e)] ?? -1;
                    if ($c >= 0) { $this->scParent[$c] = $i; }
                } elseif ($e instanceof CallExpr) {
                    $this->noteCall($i, $e, $compact);
                }
            }
        }
        // pass 2: declarations (params, catch, static, global) by position
        foreach ($p->names as $n) {
            if ($n->kind !== 'variable' || ($n->role !== 'param' && $n->role !== 'catch' && $n->role !== 'global' && $n->role !== 'static')) { continue; }
            $sp = $n->span;
            $sid = $this->scopeAt($units, $sp->startLine(), $sp->startColumn());
            $name = \ltrim($n->written, '$');
            $el = $sp->endLine() === 0 ? $sp->startLine() : $sp->endLine();
            $ec = $sp->endLine() === 0 ? $sp->startColumn() + \strlen($n->written) : $sp->endColumn();
            if ($n->role === 'global') {
                $b = $this->bind(0, $name);
                $this->scMap[$sid][$name] = $b;
                $this->addScope($b, $sid);
                $this->bGlobal[$b] = true;
            } elseif (isset($this->scMap[$sid][$name])) {
                $b = $this->scMap[$sid][$name];
            } else {
                $b = $this->fresh($name, false);
                $this->scMap[$sid][$name] = $b;
                $this->addScope($b, $sid);
            }
            $this->addSite($b, $sp->startLine(), $sp->startColumn(), $el, $ec, true);
        }
        // pass 3: closure `use` lists
        foreach ($units->units as $i => $u) {
            if (!($u->node instanceof Closure)) { continue; }
            foreach ($u->node->uses as $cu) {
                $outer = $this->scParent[$i];
                $b = $outer >= 0 ? $this->bind($outer, $cu->name) : $this->fresh($cu->name, false);
                if (!isset($this->scMap[$i][$cu->name])) {
                    $this->scMap[$i][$cu->name] = $b;
                    $this->addScope($b, $i);
                }
                $sp = $cu->span;
                if ($sp === null || $sp->endLine() === 0) { continue; }
                $this->addSite($b, $sp->startLine(), $sp->endColumn() - \strlen($cu->name) - 1, $sp->endLine(), $sp->endColumn(), false);
            }
        }
        // pass 4: uses
        foreach ($units->units as $i => $u) {
            foreach ($vars[$i] as $v) {
                if ($v->name === '__mc_destructure_skip') { continue; }
                $sp = $v->span;
                if ($v->name === '') {
                    $this->scDyn[$i][] = new UncertainSite($this->file, $sp->startLine(), $sp->startColumn(), 'dynamic-variable');
                    continue;
                }
                $b = $this->bind($i, $v->name);
                $len = \strlen($v->name) + 1;
                if ($sp->endLine() === 0) {
                    $this->addSite($b, $sp->startLine(), $sp->startColumn(), $sp->startLine(), $sp->startColumn() + $len, false);
                } elseif ($sp->endLine() === $sp->startLine() && $sp->endColumn() - $sp->startColumn() === $len) {
                    $this->addSite($b, $sp->startLine(), $sp->startColumn(), $sp->endLine(), $sp->endColumn(), false);
                } else {
                    $this->bUncertain[$b][] = new UncertainSite($this->file, $sp->startLine(), $sp->startColumn(), 'heredoc-interpolation');
                }
            }
            foreach ($compact[$i] as $lit) {
                if (\preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/', $lit->value) !== 1) { continue; }
                $b = $this->bind($i, $lit->value);
                $this->bUncertain[$b][] = new UncertainSite($this->file, $lit->span->startLine(), $lit->span->startColumn(), 'dynamic-variable');
            }
        }
    }

    /** @param array<int, array<int, StringLiteral>> $compact */
    private function noteCall(int $sid, CallExpr $c, array &$compact): void
    {
        $name = \strtolower(\ltrim($c->function, '\\'));
        $sp = $c->span;
        if ($name === '__mc_require_value') {
            $this->scDyn[$sid][] = new UncertainSite($this->file, $sp->startLine(), $sp->startColumn(), 'include-scope');
        } elseif (isset(self::DYNAMIC_CALLS[$name])) {
            $this->scDyn[$sid][] = new UncertainSite($this->file, $sp->startLine(), $sp->startColumn(), 'dynamic-variable');
        } elseif ($name === 'compact') {
            $whole = false;
            foreach ($c->args as $a) {
                if ($a instanceof StringLiteral) { $compact[$sid][] = $a; } else { $whole = true; }
            }
            if ($whole || \count($c->args) === 0) {
                $this->scDyn[$sid][] = new UncertainSite($this->file, $sp->startLine(), $sp->startColumn(), 'dynamic-variable');
            }
        }
    }

    /** The innermost function / method / closure range holding the point, else `{main}` (0). */
    private function scopeAt(Units $units, int $line, int $col): int
    {
        $best = 0;
        $bl = 0;
        $bc = 0;
        foreach ($units->units as $i => $u) {
            $s = $u->span;
            if ($s === null || $s->endLine() === 0) { continue; }
            if ($s->startLine() > $line || ($s->startLine() === $line && $s->startColumn() > $col)) { continue; }
            if ($s->endLine() < $line || ($s->endLine() === $line && $s->endColumn() <= $col)) { continue; }
            if ($s->startLine() > $bl || ($s->startLine() === $bl && $s->startColumn() >= $bc)) {
                $best = $i;
                $bl = $s->startLine();
                $bc = $s->startColumn();
            }
        }
        return $best;
    }

    private function fresh(string $name, bool $special): int
    {
        $b = \count($this->bName);
        $this->bName[] = $name;
        $this->bSpecial[] = $special;
        $this->bGlobal[] = false;
        $this->bScopes[$b] = [];
        $this->bSites[$b] = [];
        $this->bUncertain[$b] = [];
        return $b;
    }

    private function addScope(int $b, int $sid): void
    {
        if (!\in_array($sid, $this->bScopes[$b], true)) { $this->bScopes[$b][] = $sid; }
    }

    private function bind(int $sid, string $name): int
    {
        if (isset($this->scMap[$sid][$name])) { return $this->scMap[$sid][$name]; }
        if (isset(self::SUPER[$name])) {
            if (!isset($this->specialIds[$name])) { $this->specialIds[$name] = $this->fresh($name, true); }
            $b = $this->specialIds[$name];
        } elseif ($name === 'this') {
            $par = $this->scParent[$sid];
            $b = ($par >= 0 && !$this->scStatic[$sid] && ($this->scKind[$sid] === 'closure' || $this->scKind[$sid] === 'arrow'))
                ? $this->bind($par, 'this') : $this->fresh($name, true);
        } elseif ($this->scKind[$sid] === 'arrow' && $this->scParent[$sid] >= 0) {
            $b = $this->bind($this->scParent[$sid], $name);
        } else {
            $b = $this->fresh($name, false);
            if ($sid === 0) { $this->bGlobal[$b] = true; }
        }
        $this->scMap[$sid][$name] = $b;
        $this->addScope($b, $sid);
        return $b;
    }

    private function addSite(int $b, int $line, int $col, int $endLine, int $endCol, bool $decl): void
    {
        $id = \count($this->sLine);
        $this->sLine[] = $line;
        $this->sCol[] = $col;
        $this->sEndLine[] = $endLine;
        $this->sEndCol[] = $endCol;
        $this->sBind[] = $b;
        $this->sDecl[] = $decl;
        $this->bSites[$b][] = $id;
        $this->byLine[$line][] = $id;
        if ($endLine !== $line) { $this->byLine[$endLine][] = $id; }
    }

    /** @return int site id covering the point (end touching counts, as for names), -1 when none */
    private function siteAt(int $line, int $col, bool $declOnly): int
    {
        $touch = -1;
        foreach ($this->byLine[$line] ?? [] as $id) {
            if ($declOnly && !$this->sDecl[$id]) { continue; }
            if ($this->sLine[$id] > $line || ($this->sLine[$id] === $line && $this->sCol[$id] > $col)) { continue; }
            if ($this->sEndLine[$id] < $line || ($this->sEndLine[$id] === $line && $this->sEndCol[$id] < $col)) { continue; }
            if ($this->sEndLine[$id] === $line && $this->sEndCol[$id] === $col) { $touch = $id; continue; }
            return $id;
        }
        return $touch;
    }

    /** Binding of the variable whose `$name` covers the point, -1 when none. */
    public function bindingAt(int $line, int $col): int
    {
        $s = $this->siteAt($line, $col, false);
        return $s < 0 ? -1 : $this->sBind[$s];
    }

    /** Binding whose DECLARATION (parameter, catch, global, static) covers the point, -1 when none. */
    public function declAt(int $line, int $col): int
    {
        $s = $this->siteAt($line, $col, true);
        return $s < 0 ? -1 : $this->sBind[$s];
    }

    /** The `{main}` binding of a name, -1 when the file never mentions it at top level or through `global`. */
    public function mainBinding(string $name): int
    {
        return $this->scMap[0][$name] ?? -1;
    }

    public function nameOf(int $b): string
    {
        return $this->bName[$b];
    }

    public function isSpecial(int $b): bool
    {
        return $this->bSpecial[$b];
    }

    /** A `{main}` variable, or one reached by a `global` statement. */
    public function isGlobalScoped(int $b): bool
    {
        return $this->bGlobal[$b];
    }

    /** @return int[] site ids in source order */
    private function ordered(int $b): array
    {
        $ids = $this->bSites[$b];
        \usort($ids, function (int $x, int $y): int {
            if ($this->sLine[$x] !== $this->sLine[$y]) { return $this->sLine[$x] <=> $this->sLine[$y]; }
            return $this->sCol[$x] <=> $this->sCol[$y];
        });
        return $ids;
    }

    private function loc(int $id): Location
    {
        return new Location($this->file, $this->sLine[$id], $this->sCol[$id], $this->sEndLine[$id], $this->sEndCol[$id]);
    }

    /** @return Location[] declarations and uses, in source order */
    public function sitesOf(int $b): array
    {
        $out = [];
        foreach ($this->ordered($b) as $id) { $out[] = $this->loc($id); }
        return $out;
    }

    /** The first declaration (parameter, catch, global, static), else the first site; null for a special variable. */
    public function definitionOf(int $b): ?Location
    {
        if ($this->bSpecial[$b]) { return null; }
        $first = null;
        foreach ($this->ordered($b) as $id) {
            if ($this->sDecl[$id]) { return $this->loc($id); }
            if ($first === null) { $first = $id; }
        }
        return $first === null ? null : $this->loc($first);
    }

    /** @return Location[] the declaration sites of the binding */
    public function declSitesOf(int $b): array
    {
        $out = [];
        foreach ($this->ordered($b) as $id) {
            if ($this->sDecl[$id]) { $out[] = $this->loc($id); }
        }
        return $out;
    }

    /** @return UncertainSite[] heredoc text, compact() literals and the dynamic / include sites of every scope the binding is visible in */
    public function uncertainOf(int $b): array
    {
        /** @var array<string, UncertainSite> $seen */
        $seen = [];
        foreach ($this->bUncertain[$b] as $s) { $seen[$s->reason . ':' . $s->line . ':' . $s->col] = $s; }
        foreach ($this->bScopes[$b] as $sid) {
            foreach ($this->scDyn[$sid] as $s) { $seen[$s->reason . ':' . $s->line . ':' . $s->col] = $s; }
        }
        return \array_values($seen);
    }
}
