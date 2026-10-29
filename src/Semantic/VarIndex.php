<?php

namespace Semantic;

use Analyze\AstWalk;
use Analyze\DeclScopes;
use Parser\Ast\ArrayAccess;
use Parser\Ast\AttributeNode;
use Parser\Ast\Param;
use Parser\Ast\ArrayLit;
use Parser\Ast\ArrowFn;
use Parser\Ast\Assign;
use Parser\Ast\CallExpr;
use Parser\Ast\Closure;
use Parser\Ast\DynamicStaticProp;
use Parser\Ast\ClassStmt;
use Parser\Ast\CompoundAssign;
use Parser\Ast\DoWhileStmt;
use Parser\Ast\Expr;
use Parser\Ast\ForStmt;
use Parser\Ast\ForeachStmt;
use Parser\Ast\FunctionStmt;
use Parser\Ast\IncDec;
use Parser\Ast\Program;
use Parser\Ast\RefAssign;
use Parser\Ast\Span;
use Parser\Ast\StringLiteral;
use Parser\Ast\SwitchStmt;
use Parser\Ast\Variable;
use Parser\Ast\WhileStmt;

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
 * - an arrow function that WRITES a name (`fn() => $x = 5`) keeps a local copy: its uses of that name are a separate
 *   binding, and both it and the outer one list the first write as `arrow-assignment` (reads before the write see the outer value);
 * - scopes are discovered here (not via Analyze\Units, which feeds the analyzer rules): functions, methods and property hooks
 *   at any nesting depth (inside function bodies, conditionals, namespaces, loops, try), and closures inside them. A set hook
 *   binds `$value` (or its named parameter). A parameter that still falls in no scope is listed as `unscoped-declaration`.
 * - cross-file `global-scope` sites come from the open files' VarIndexes and the name index of every file (a closed file's
 *   `{main}` variables are NOT known, only its `global` statements).
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
    /** @var array<int, ?Span> */
    private array $scSpan = [];
    /** @var array<int, \Parser\Ast\Stmt[]> */
    private array $scStmts = [];
    /** @var array<int, ?Expr> arrow function / hook expression body */
    private array $scExpr = [];
    /** @var array<int, ?Expr> the Closure / ArrowFn of a closure scope */
    private array $scNode = [];
    /** @var array<int, Variable[]> */
    private array $scVars = [];
    /** @var array<int, StringLiteral[]> literal names of compact() calls */
    private array $scCompact = [];
    /** @var array<int, UncertainSite> set hook scope => its explicit parameter's (unlocatable) site */
    private array $scHookSite = [];
    /** @var array<int, string> set-hook value parameter name */
    private array $scHookParam = [];
    /** @var array<int, array<string, string>> arrow scope => name it writes => "line:col" of the first write */
    private array $scArrowWrites = [];
    /** @var int[] scope span index, sorted by start: start, end, scope id, enclosing index (-1 none) */
    private array $ssStart = [];
    /** @var int[] */
    private array $ssEnd = [];
    /** @var int[] */
    private array $ssId = [];
    /** @var int[] */
    private array $ssParent = [];
    /** @var UncertainSite[] parameters that sit in no function / method / closure range */
    private array $unscoped = [];

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
        $this->newScope('main', null, $p->statements, null, null, -1, false);
        $this->process(0);
        // declarations (params, catch, static, global) by position
        $this->indexScopes();
        foreach ($p->names as $n) {
            if ($n->kind !== 'variable' || ($n->role !== 'param' && $n->role !== 'catch' && $n->role !== 'global' && $n->role !== 'static')) { continue; }
            $sp = $n->span;
            $sid = $this->scopeAt($sp->startLine(), $sp->startColumn());
            $name = \ltrim($n->written, '$');
            $el = $sp->endLine() === 0 ? $sp->startLine() : $sp->endLine();
            $ec = $sp->endLine() === 0 ? $sp->startColumn() + \strlen($n->written) : $sp->endColumn();
            if ($n->role === 'param' && $sid === 0) {
                $this->unscoped[] = new UncertainSite($this->file, $sp->startLine(), $sp->startColumn(), 'unscoped-declaration');
            }
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
        // a property hook's value parameter has no name entry: bind it in the hook's own scope
        foreach ($this->scHookParam as $sid => $hp) {
            if ($hp !== '' && !isset($this->scMap[$sid][$hp])) {
                $b = $this->fresh($hp, false);
                if (isset($this->scHookSite[$sid])) { $this->bUncertain[$b][] = $this->scHookSite[$sid]; }
                $this->scMap[$sid][$hp] = $b;
                $this->addScope($b, $sid);
            }
        }
        // closure `use` lists
        foreach ($this->scNode as $i => $node) {
            if (!($node instanceof Closure)) { continue; }
            foreach ($node->uses as $cu) {
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
        // uses
        foreach ($this->scKind as $i => $_) {
            foreach ($this->scVars[$i] as $v) {
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
            foreach ($this->scCompact[$i] as $lit) {
                if (\preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/', $lit->value) !== 1) { continue; }
                $b = $this->bind($i, $lit->value);
                $this->bUncertain[$b][] = new UncertainSite($this->file, $lit->span->startLine(), $lit->span->startColumn(), 'dynamic-variable');
            }
        }
    }

    private function newScope(string $kind, ?Span $span, array $stmts, ?Expr $exprBody, ?Expr $node, int $parent, bool $static): int
    {
        $id = \count($this->scKind);
        $this->scKind[] = $kind;
        $this->scParent[] = $parent;
        $this->scStatic[] = $static;
        $this->scMap[$id] = [];
        $this->scDyn[$id] = [];
        $this->scSpan[] = $span;
        $this->scStmts[] = $stmts;
        $this->scExpr[] = $exprBody;
        $this->scNode[] = $node;
        $this->scVars[$id] = [];
        $this->scCompact[$id] = [];
        $this->scHookParam[$id] = '';
        $this->scArrowWrites[$id] = [];
        return $id;
    }

    /** Collects what scope `$sid` holds directly, then opens a scope for every closure, function, method and hook it contains. */
    private function process(int $sid): void
    {
        $w = new AstWalk(true);
        if ($this->scExpr[$sid] !== null) { $w->exprTree($this->scExpr[$sid]); } else { $w->stmts($this->scStmts[$sid]); }
        $list = $w->exprs;
        // AstWalk (shared with the compiler) leaves the name expression of `A::$$n` unvisited
        for ($i = 0; $i < count($list); $i++) {
            $e = $list[$i];
            if ($e instanceof DynamicStaticProp) {
                $sub = new AstWalk(true);
                $sub->exprTree($e->nameExpr);
                foreach ($sub->exprs as $x) { $list[] = $x; }
            } elseif ($e instanceof Variable) {
                $this->scVars[$sid][] = $e;
            } elseif ($e instanceof Closure) {
                $this->scanParams($e->params);
                $this->process($this->newScope('closure', $e->span, $e->body->statements, null, $e, $sid, $e->isStatic));
            } elseif ($e instanceof ArrowFn) {
                $this->scanParams($e->params);
                $this->process($this->newScope('arrow', $e->span, [], $e->body, $e, $sid, $e->isStatic));
            } elseif ($e instanceof CallExpr) {
                $this->noteCall($sid, $e);
            } elseif ($this->scKind[$sid] === 'arrow') {
                $this->noteWrite($sid, $e);
            }
        }
        $this->discover($this->scStmts[$sid]);
    }

    /** Opens scopes for the functions, classes (methods, property hooks) declared in a statement list, at any nesting depth short of another body. */
    private function discover(array $stmts): void
    {
        foreach ($stmts as $s) {
            if ($s instanceof FunctionStmt) {
                $this->scanParams($s->decl->params);
                $this->scanAttrs($s->decl->attributes);
                if ($s->decl->body !== null) { $this->process($this->newScope('fn', $s->decl->span, $s->decl->body->statements, null, null, -1, false)); }
            } elseif ($s instanceof ClassStmt) {
                $this->scanAttrs($s->decl->attributes);
                foreach ($s->decl->consts as $k) { $this->scanExpr($k->value); $this->scanAttrs($k->attributes); }
                foreach ($s->decl->cases as $k) { $this->scanExpr($k->value); $this->scanAttrs($k->attributes); }
                foreach ($s->decl->methods as $m) {
                    $this->scanParams($m->params);
                    $this->scanAttrs($m->attributes);
                    if ($m->body !== null) { $this->process($this->newScope('fn', $m->span, $m->body->statements, null, null, -1, false)); }
                }
                foreach ($s->decl->properties as $prop) {
                    $this->scanExpr($prop->default);
                    $this->scanAttrs($prop->attributes);
                    foreach ($prop->hooks as $h) {
                        $id = $this->newScope('fn', $h->span, $h->blockBody === null ? [] : $h->blockBody->statements, $h->exprBody, null, -1, false);
                        if ($h->kind === 'set') {
                            $this->scHookParam[$id] = $h->paramName ?? 'value';
                            if ($h->paramName !== null) {
                                // an explicit hook parameter has no name entry: list the hook as the site to rename by hand
                                $hs = $h->span ?? $prop->span;
                                $this->scHookSite[$id] = new UncertainSite($this->file, $hs->startLine(), $hs->startColumn(), 'hook-parameter');
                            }
                        }
                        $this->process($id);
                    }
                }
            } else {
                foreach (DeclScopes::nested($s) as $body) { $this->discover($body); }
                if ($s instanceof WhileStmt) { $this->discover($s->body->statements); }
                elseif ($s instanceof DoWhileStmt) { $this->discover($s->body->statements); }
                elseif ($s instanceof ForStmt) { $this->discover($s->body->statements); }
                elseif ($s instanceof ForeachStmt) { $this->discover($s->body->statements); }
                elseif ($s instanceof SwitchStmt) { foreach ($s->cases as $arm) { $this->discover($arm->body); } }
            }
        }
    }

    /** Opens parentless scopes for the closures / arrow functions inside a constant-like expression (default, const, case, attribute argument). */
    private function scanExpr(?Expr $e): void
    {
        if ($e === null) { return; }
        $w = new AstWalk(true);
        $w->exprTree($e);
        foreach ($w->exprs as $x) {
            if ($x instanceof Closure) {
                $this->scanParams($x->params);
                $this->process($this->newScope('closure', $x->span, $x->body->statements, null, $x, -1, $x->isStatic));
            } elseif ($x instanceof ArrowFn) {
                $this->scanParams($x->params);
                $this->process($this->newScope('arrow', $x->span, [], $x->body, $x, -1, $x->isStatic));
            }
        }
    }

    /** @param Param[] $params */
    private function scanParams(array $params): void
    {
        foreach ($params as $p) { $this->scanExpr($p->default); $this->scanAttrs($p->attributes); }
    }

    /** @param AttributeNode[] $attrs */
    private function scanAttrs(array $attrs): void
    {
        foreach ($attrs as $a) {
            foreach ($a->args as $x) { $this->scanExpr($x); }
        }
    }

    /** Names an arrow function writes (PHP keeps them local to the arrow): `$x = ..`, `$x op= ..`, `$x++`, `$x =& ..`, `[$x] = ..`, `$x[0] = ..`. */
    private function noteWrite(int $sid, Expr $e): void
    {
        if ($e instanceof Assign || $e instanceof CompoundAssign || $e instanceof RefAssign) { $this->noteTarget($sid, $e->target); }
        elseif ($e instanceof IncDec) { $this->noteTarget($sid, $e->operand); }
    }

    private function noteTarget(int $sid, Expr $t): void
    {
        if ($t instanceof Variable) {
            if ($t->name !== '' && !isset($this->scArrowWrites[$sid][$t->name])) {
                $this->scArrowWrites[$sid][$t->name] = $t->span->startLine() . ':' . $t->span->startColumn();
            }
        } elseif ($t instanceof ArrayAccess) {
            $this->noteTarget($sid, $t->array);
        } elseif ($t instanceof ArrayLit) {
            foreach ($t->elements as $el) { $this->noteTarget($sid, $el->value); }
        }
    }

    private function noteCall(int $sid, CallExpr $c): void
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
                if ($a instanceof StringLiteral) { $this->scCompact[$sid][] = $a; } else { $whole = true; }
            }
            if ($whole || \count($c->args) === 0) {
                $this->scDyn[$sid][] = new UncertainSite($this->file, $sp->startLine(), $sp->startColumn(), 'dynamic-variable');
            }
        }
    }

    /** Spans sorted by start, each with its enclosing span, so scopeAt is a binary search plus a walk up the nesting. */
    private function indexScopes(): void
    {
        $ord = [];
        foreach ($this->scSpan as $i => $s) {
            if ($s === null || $s->endLine() === 0) { continue; }
            $ord[] = [self::pos($s->startLine(), $s->startColumn()), $i];
        }
        \usort($ord, fn(array $x, array $y): int => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
        $stack = [];
        foreach ($ord as $k => [$start, $id]) {
            $s = $this->scSpan[$id];
            while ($stack !== [] && $this->ssEnd[$stack[\count($stack) - 1]] <= $start) { \array_pop($stack); }
            $this->ssStart[] = $start;
            $this->ssId[] = $id;
            $this->ssEnd[] = self::pos($s->endLine(), $s->endColumn());
            $this->ssParent[] = $stack === [] ? -1 : $stack[\count($stack) - 1];
            $stack[] = $k;
        }
    }

    private static function pos(int $line, int $col): int
    {
        return $line * 4294967296 + $col;
    }

    /** The innermost function / method / hook / closure range holding the point, else `{main}` (0). */
    private function scopeAt(int $line, int $col): int
    {
        $p = self::pos($line, $col);
        $lo = 0;
        $hi = \count($this->ssStart) - 1;
        $k = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->ssStart[$mid] <= $p) { $k = $mid; $lo = $mid + 1; } else { $hi = $mid - 1; }
        }
        while ($k >= 0 && $this->ssEnd[$k] <= $p) { $k = $this->ssParent[$k]; }
        return $k < 0 ? 0 : $this->ssId[$k];
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
        } elseif ($this->scKind[$sid] === 'arrow' && $this->scParent[$sid] >= 0 && isset($this->scArrowWrites[$sid][$name])) {
            // written inside the arrow: a local copy, whatever the outer variable holds (reads before the write see the outer value)
            $b = $this->fresh($name, false);
            $pos = \explode(':', $this->scArrowWrites[$sid][$name]);
            $site = new UncertainSite($this->file, (int)$pos[0], (int)$pos[1], 'arrow-assignment');
            $this->bUncertain[$b][] = $site;
            $outer = $this->bind($this->scParent[$sid], $name);
            $this->bUncertain[$outer][] = $site;
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

    /**
     * Variable names (without `$`) with a site before the point in the scope holding it; an arrow function also sees its
     * enclosing scopes. Superglobals are left out.
     *
     * @return string[]
     */
    public function namesBefore(int $line, int $col): array
    {
        $sid = $this->scopeAt($line, $col);
        $out = [];
        while ($sid >= 0) {
            foreach ($this->scMap[$sid] ?? [] as $name => $b) {
                if ($this->bSpecial[$b]) { continue; }
                foreach ($this->bSites[$b] as $id) {
                    if ($this->sLine[$id] < $line || ($this->sLine[$id] === $line && $this->sCol[$id] < $col)) { $out[(string)$name] = true; break; }
                }
            }
            $sid = $this->scKind[$sid] === 'arrow' ? $this->scParent[$sid] : -1;
        }
        return \array_map('strval', \array_keys($out));
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
        foreach ($this->unscoped as $s) { $seen[$s->reason . ':' . $s->line . ':' . $s->col] = $s; }
        foreach ($this->bScopes[$b] as $sid) {
            foreach ($this->scDyn[$sid] as $s) { $seen[$s->reason . ':' . $s->line . ':' . $s->col] = $s; }
        }
        return \array_values($seen);
    }
}
