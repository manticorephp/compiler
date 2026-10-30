<?php

namespace Analyze;

use Parser\Ast\ClassDecl;
use Parser\Ast\CallExpr;
use Parser\Ast\ClassStmt;
use Parser\Ast\ExpressionStmt;
use Parser\Ast\StringLiteral;
use Parser\Ast\FunctionDecl;
use Parser\Ast\FunctionStmt;
use Parser\Ast\NamespaceStmt;

/**
 * Project-wide symbol table: every user-declared function and class/interface/
 * trait/enum across the analyzed file set, keyed by lowercased FQN. Rules query
 * it to resolve a call site to a signature.
 *
 * It carries USER declarations only; compiler builtins (the codegen primitives
 * and the stdlib) are out of scope here — a rule that needs to know whether an
 * unresolved name is a builtin must not guess from this table (a miss is not
 * proof of "undefined"). Existence checks that need the full builtin universe
 * belong to the MIR-assisted family.
 */
final class Index
{
    /** @var array<string, FnInfo> lower FQN → function */
    public array $functions = [];

    /** @var array<string, ClassInfo> lower FQN → class */
    public array $classes = [];

    /** @var array<string, bool> lower name → true; stdlib functions from the .o.sig */
    public array $externFunctions = [];

    /** @param string[] $names lowercased stdlib function names */
    public function addExternFunctions(array $names): void
    {
        foreach ($names as $n) { $this->externFunctions[$n] = true; }
    }

    /** Is this a known stdlib function (from the loaded .o.sig)? */
    public function hasExternFunction(string $lowerName): bool
    {
        return isset($this->externFunctions[$lowerName]);
    }

    /** @var array<string, array<string, FnInfo>> path → lower FQN → function it declared */
    private array $fileFns = [];

    /** @var array<string, array<string, ClassInfo>> path → lower FQN → class it declared */
    private array $fileClasses = [];

    /** @var array<string, array<string, \Parser\Ast\Span>> path → lower FQN → class declaration start */
    private array $fileSpans = [];

    /** @var array<string, array<string, \Parser\Ast\Span>> path → lower FQN → function declaration start */
    private array $fileFnSpans = [];

    /** @var array<string, string[]> lower FQN → declaring paths, winner first */
    private array $fnDeclarers = [];

    /** @var array<string, string[]> lower FQN → declaring paths, winner first */
    private array $classDeclarers = [];

    /** @var array<string, string> lower FQN → path of the winning function declarer */
    private array $fnWinner = [];

    /** @var array<string, string> */
    private array $classWinner = [];

    /** @var array<string, array<string, bool>> lower target FQN → lower FQNs of classes naming it as parent, interface or trait */
    private array $dependents = [];

    /** @var array<string, FnInfo> scratch for the file being collected */
    private array $curFns = [];

    /** @var array<string, ClassInfo> */
    private array $curClasses = [];

    /** @var array<string, \Parser\Ast\Span> */
    private array $curSpans = [];

    /** @var array<string, \Parser\Ast\Span> */
    private array $curFnSpans = [];

    /** @var array<string, \Parser\Ast\Span> constant key → name literal of the winning declarer */
    private array $consts = [];

    /** @var array<string, array<string, \Parser\Ast\Span>> path → constant key → name literal */
    private array $fileConsts = [];

    /** @var array<string, string[]> constant key → declaring paths, winner first */
    private array $constDeclarers = [];

    /** @var array<string, string> */
    private array $constWinner = [];

    /** @var array<string, \Parser\Ast\Span> */
    private array $curConsts = [];

    /** @var array<string, bool> kind . path . NUL . key → the declaration is kept from before the file broke, not present in its tree */
    private array $sticky = [];

    private string $curNs = '';

    private ?ParsedFile $curFile = null;

    /** @param ParsedFile[] $files */
    public static function build(array $files): Index
    {
        $idx = new Index();
        foreach ($files as $pf) {
            $idx->replaceFile($pf);
        }
        return $idx;
    }

    /** Drop the file's previous contribution, then add the new one. The file keeps its place in each FQN's declarer order. */
    public function replaceFile(ParsedFile $pf): void
    {
        $path = $pf->path;
        /** @var array<string, int> $fnPos */
        $fnPos = [];
        /** @var array<string, int> $classPos */
        $classPos = [];
        foreach ($this->fileFns[$path] ?? [] as $l => $_) { $fnPos[$l] = \array_search($path, $this->fnDeclarers[$l], true); }
        foreach ($this->fileClasses[$path] ?? [] as $l => $_) { $classPos[$l] = \array_search($path, $this->classDeclarers[$l], true); }
        $oldFns = $this->fileFns[$path] ?? [];
        $oldClasses = $this->fileClasses[$path] ?? [];
        $oldSpans = $this->fileSpans[$path] ?? [];
        $oldFnSpans = $this->fileFnSpans[$path] ?? [];
        /** @var array<string, int> $constPos */
        $constPos = [];
        foreach ($this->fileConsts[$path] ?? [] as $k => $_) { $constPos[$k] = \array_search($path, $this->constDeclarers[$k], true); }
        $oldConsts = $this->fileConsts[$path] ?? [];
        $this->removeFile($path);
        $this->curFns = [];
        $this->curClasses = [];
        $this->curSpans = [];
        $this->curFnSpans = [];
        $this->curConsts = [];
        $this->curNs = '';
        $this->curFile = $pf;
        $this->collectStmts($pf->program->statements);
        $this->curFile = null;
        if ($pf->hasErrors) {
            // Sticky: a declaration the recovered tree lost stays until the file is removed or parses clean.
            foreach ($oldFns as $l => $fi) {
                if (isset($this->curFns[$l])) { continue; }
                $this->curFns[$l] = $fi;
                $this->curFnSpans[$l] = $oldFnSpans[$l];
                $this->sticky['f' . $path . "\0" . $l] = true;
            }
            foreach ($oldClasses as $l => $ci) {
                if (isset($this->curClasses[$l])) { continue; }
                $this->curClasses[$l] = $ci;
                $this->curSpans[$l] = $oldSpans[$l];
                $this->sticky['c' . $path . "\0" . $l] = true;
            }
            foreach ($oldConsts as $k => $sp) {
                if (isset($this->curConsts[$k])) { continue; }
                $this->curConsts[$k] = $sp;
                $this->sticky['k' . $path . "\0" . $k] = true;
            }
        }
        $this->fileFns[$path] = $this->curFns;
        $this->fileClasses[$path] = $this->curClasses;
        $this->fileSpans[$path] = $this->curSpans;
        $this->fileFnSpans[$path] = $this->curFnSpans;
        $this->fileConsts[$path] = $this->curConsts;
        foreach ($this->curFns as $l => $fi) {
            $this->fnDeclarers[$l] ??= [];
            $at = \min($fnPos[$l] ?? \PHP_INT_MAX, \count($this->fnDeclarers[$l]));
            \array_splice($this->fnDeclarers[$l], $at, 0, [$path]);
            $this->electFn($l);
        }
        foreach ($this->curClasses as $l => $ci) {
            $this->classDeclarers[$l] ??= [];
            $at = \min($classPos[$l] ?? \PHP_INT_MAX, \count($this->classDeclarers[$l]));
            \array_splice($this->classDeclarers[$l], $at, 0, [$path]);
            $this->electClass($l);
        }
        foreach ($this->curConsts as $k => $sp) {
            $this->constDeclarers[$k] ??= [];
            $at = \min($constPos[$k] ?? \PHP_INT_MAX, \count($this->constDeclarers[$k]));
            \array_splice($this->constDeclarers[$k], $at, 0, [$path]);
            $this->electConst($k);
        }
        $this->curFns = [];
        $this->curClasses = [];
        $this->curSpans = [];
        $this->curFnSpans = [];
        $this->curConsts = [];
    }

    public function removeFile(string $path): void
    {
        if (!isset($this->fileFns[$path])) { return; }
        foreach ($this->fileFns[$path] as $l => $_) {
            $q = [];
            foreach ($this->fnDeclarers[$l] as $p) { if ($p !== $path) { $q[] = $p; } }
            if ($q === []) {
                unset($this->fnDeclarers[$l], $this->functions[$l], $this->fnWinner[$l]);
            } else {
                $this->fnDeclarers[$l] = $q;
                $this->electFn($l);
            }
        }
        foreach ($this->fileClasses[$path] as $l => $_) {
            $q = [];
            foreach ($this->classDeclarers[$l] as $p) { if ($p !== $path) { $q[] = $p; } }
            if ($q === []) {
                unset($this->classDeclarers[$l]);
                $this->setClass($l, null, '');
            } else {
                $this->classDeclarers[$l] = $q;
                $this->electClass($l);
            }
        }
        foreach ($this->fileConsts[$path] ?? [] as $k => $_) {
            $q = [];
            foreach ($this->constDeclarers[$k] as $p) { if ($p !== $path) { $q[] = $p; } }
            if ($q === []) {
                unset($this->constDeclarers[$k], $this->consts[$k], $this->constWinner[$k]);
            } else {
                $this->constDeclarers[$k] = $q;
                $this->electConst($k);
            }
        }
        foreach ($this->fileFns[$path] as $l => $_) { unset($this->sticky['f' . $path . "\0" . $l]); }
        foreach ($this->fileClasses[$path] as $l => $_) { unset($this->sticky['c' . $path . "\0" . $l]); }
        foreach ($this->fileConsts[$path] ?? [] as $k => $_) { unset($this->sticky['k' . $path . "\0" . $k]); }
        unset($this->fileFns[$path], $this->fileClasses[$path], $this->fileSpans[$path], $this->fileFnSpans[$path], $this->fileConsts[$path]);
    }

    /**
     * The first declarer in order whose declaration is live; a kept (sticky) one only when every declarer is.
     *
     * @param string[] $declarers
     */
    private function liveFirst(array $declarers, string $kind, string $key): string
    {
        foreach ($declarers as $p) {
            if (!isset($this->sticky[$kind . $p . "\0" . $key])) { return $p; }
        }
        return $declarers[0];
    }

    private function electFn(string $l): void
    {
        $w = $this->liveFirst($this->fnDeclarers[$l], 'f', $l);
        $this->fnWinner[$l] = $w;
        $this->functions[$l] = $this->fileFns[$w][$l];
    }

    private function electClass(string $l): void
    {
        $w = $this->liveFirst($this->classDeclarers[$l], 'c', $l);
        $this->setClass($l, $this->fileClasses[$w][$l], $w);
    }

    private function electConst(string $k): void
    {
        $w = $this->liveFirst($this->constDeclarers[$k], 'k', $k);
        $this->constWinner[$k] = $w;
        $this->consts[$k] = $this->fileConsts[$w][$k];
    }

    /**
     * Path of the file whose declaration of this class or function currently wins.
     * $kind 'class' or 'function' restricts the lookup; null tries the class first.
     */
    public function declaringFile(string $lowerFqn, ?string $kind = null): ?string
    {
        if ($kind === 'class') { return $this->classWinner[$lowerFqn] ?? null; }
        if ($kind === 'function') { return $this->fnWinner[$lowerFqn] ?? null; }
        return $this->classWinner[$lowerFqn] ?? $this->fnWinner[$lowerFqn] ?? null;
    }

    /** Start of the winning declaration; with no $kind a class shadows a function of the same name. */
    public function declSpan(string $lowerFqn, ?string $kind = null): ?\Parser\Ast\Span
    {
        if ($kind !== 'function' && isset($this->classWinner[$lowerFqn])) {
            return $this->fileSpans[$this->classWinner[$lowerFqn]][$lowerFqn] ?? null;
        }
        if ($kind !== 'class' && isset($this->fnWinner[$lowerFqn])) {
            return $this->fileFnSpans[$this->fnWinner[$lowerFqn]][$lowerFqn] ?? null;
        }
        return null;
    }

    /** Constants are case-sensitive; only the namespace part is folded. */
    public static function constKey(string $name): string
    {
        $n = \ltrim($name, '\\');
        $bs = \strrpos($n, '\\');
        return $bs === false ? $n : \strtolower(\substr($n, 0, $bs)) . \substr($n, $bs);
    }

    /** Name literal of the winning declaration of a global constant (`const X` or `define('X')`), by FQN, exact case in the name part. */
    public function declaredConst(string $name): ?\Parser\Ast\Span
    {
        return $this->consts[self::constKey($name)] ?? null;
    }

    public function constDeclaringFile(string $name): ?string
    {
        return $this->constWinner[self::constKey($name)] ?? null;
    }

    /** FQN key of the constant whose declaring name literal in $path contains the position, or null. */
    public function constAt(string $path, int $line, int $col): ?string
    {
        foreach ($this->fileConsts[$path] ?? [] as $k => $sp) {
            if ($sp->startLine() !== $line || $col < $sp->startColumn() || $col > $sp->endColumn()) { continue; }
            return $k;
        }
        return null;
    }

    /** @return string[] lower FQNs of classes that directly extend, implement or use the target */
    public function dependentsOf(string $lowerClassFqn): array
    {
        return \array_keys($this->dependents[$lowerClassFqn] ?? []);
    }

    /** @return string[] lower FQNs of every direct and transitive implementer / subclass */
    public function implementersOf(string $lowerIface): array
    {
        /** @var array<string, bool> $seen */
        $seen = [];
        $queue = [$lowerIface];
        for ($i = 0; $i < \count($queue); $i++) {
            foreach ($this->dependents[$queue[$i]] ?? [] as $d => $_) {
                if (isset($seen[$d])) { continue; }
                $seen[$d] = true;
                $queue[] = $d;
            }
        }
        unset($seen[$lowerIface]);
        return \array_keys($seen);
    }

    private function setClass(string $lower, ?ClassInfo $ci, string $path): void
    {
        $old = $this->classes[$lower] ?? null;
        if ($old !== null) {
            foreach ($this->edges($old) as $t) {
                unset($this->dependents[$t][$lower]);
                if ($this->dependents[$t] === []) { unset($this->dependents[$t]); }
            }
        }
        if ($ci === null) {
            unset($this->classes[$lower], $this->classWinner[$lower]);
            return;
        }
        $this->classes[$lower] = $ci;
        $this->classWinner[$lower] = $path;
        foreach ($this->edges($ci) as $t) { $this->dependents[$t][$lower] = true; }
    }

    /** @return string[] lower FQNs of the parents, interfaces and traits of a class */
    private function edges(ClassInfo $ci): array
    {
        $out = [];
        foreach ([$ci->parents, $ci->interfaces, $ci->traits] as $names) {
            foreach ($names as $n) { $out[] = \strtolower(\ltrim($n, '\\')); }
        }
        return $out;
    }

    /**
     * @param \Parser\Ast\Stmt[] $stmts
     *
     * Declaration names arrive already namespace-qualified: the parser folds
     * the active namespace into `FunctionDecl::$name` / `ClassDecl::$name` so
     * they match what call sites resolve to. So we key on the name verbatim and
     * only descend block-form `namespace { … }` bodies for their nested decls.
     */
    private function collectStmts(array $stmts): void
    {
        foreach ($stmts as $s) {
            if ($s instanceof FunctionStmt) {
                $this->addFunction($s->decl);
            } elseif ($s instanceof ClassStmt) {
                $this->addClass($s->decl);
            } elseif ($s instanceof NamespaceStmt) {
                if ($s->body === null) {
                    $this->curNs = $s->name;
                } else {
                    $outer = $this->curNs;
                    $this->curNs = $s->name;
                    $this->collectStmts($s->body->statements);
                    $this->curNs = $outer;
                }
            } elseif ($s instanceof ExpressionStmt && $s->expr instanceof CallExpr) {
                $this->addConst($s->expr);
            } else {
                // Namespaces, and the conditional forms a polyfill declares
                // inside — see {@see DeclScopes} for why. This is the symbol
                // universe the closed-world undefined-* rules resolve against,
                // so a declaration missed here reads as an undefined symbol.
                foreach (DeclScopes::nested($s) as $body) { $this->collectStmts($body); }
            }
        }
    }

    /**
     * `const X = …;` is lowered by the parser to `define("X", …)`; its name literal is anchored
     * at the call itself (a written `define('X')` literal sits inside the parens), which is the
     * only way to tell them apart, and only the lowered form is qualified by the active namespace.
     */
    private function addConst(CallExpr $c): void
    {
        $bs = \strrpos($c->function, '\\');
        $fn = $bs === false ? $c->function : \substr($c->function, $bs + 1);
        if (\strtolower($fn) !== 'define' || \count($c->args) < 2 || !($c->args[0] instanceof StringLiteral)) { return; }
        $lit = $c->args[0];
        $lowered = $lit->span->line === $c->span->line && $lit->span->column === $c->span->column;
        $name = \ltrim($lit->value, '\\');
        if ($name === '') { return; }
        if ($lowered && $this->curNs !== '') { $name = $this->curNs . '\\' . $name; }
        $key = self::constKey($name);
        // PHP coerces integer-like array keys to int; such a name would make the map mixed-keyed.
        if ((string)(int)$key === $key) { return; }
        if (isset($this->curConsts[$key])) { return; }
        // #502: keep this if/assign form until BOOTSTRAP_VERSION names a release containing the fix
        $span = $lit->span;
        if (!$lowered) { $span = self::unquoted($lit); }
        $this->curConsts[$key] = $span;
    }

    /**
     * The literal's range without its quotes, so a rename replaces just the name. An escaped
     * literal (source width != value + 2 quotes) keeps the whole literal range.
     */
    private static function unquoted(StringLiteral $lit): \Parser\Ast\Span
    {
        $sp = $lit->span;
        $l = $sp->startLine();
        $c = $sp->startColumn();
        if ($sp->endLine() !== $l || $sp->endColumn() - $c !== \strlen($lit->value) + 2) { return $sp; }
        return new \Parser\Ast\RangedSpan($l, $c + 1, $sp->file, $l, $sp->endColumn() - 1);
    }

    private function addFunction(FunctionDecl $d): void
    {
        if ($d->name === '') { return; }
        $l = \strtolower($d->name);
        $this->curFns[$l] = new FnInfo($d->name, $d->params, $d->returnType);
        $this->curFnSpans[$l] = $d->span;
    }

    private function addClass(ClassDecl $d): void
    {
        $fqn = $d->name;
        if ($fqn === '') { return; }
        /** @var array<string, MethodInfo> $methods */
        $methods = [];
        foreach ($d->methods as $m) {
            $methods[\strtolower($m->name)] = new MethodInfo(
                $m->name, $m->params, $m->isStatic, $m->visibility, $m->returnType, $fqn
            );
        }
        /** @var array<string, bool> $consts */
        $consts = [];
        foreach ($d->consts as $c) { $consts[$c->name] = true; }
        foreach ($d->cases as $case) { $consts[$case->name] = true; }
        /** @var array<string, ?string> $propTypes */
        $propTypes = [];
        $partial = \count($d->errorMembers) > 0;
        foreach ($d->properties as $prop) {
            // A recovered `public int ;` is a phantom property named `$`.
            if ($prop->name === '' || $prop->name === '$') { $partial = true; continue; }
            $propTypes[$prop->name] = $prop->typeHint;
        }
        $sp = $d->span;
        if ($this->curFile !== null && $this->curFile->hitsRange($sp->startLine(), $sp->startColumn(), $sp->endLine(), $sp->endColumn())) { $partial = true; }
        // Constructor property promotion also declares properties.
        foreach ($d->methods as $m) {
            if (\strtolower($m->name) !== '__construct') { continue; }
            foreach ($m->params as $mp) {
                if ($mp->promoted !== '') { $propTypes[$mp->name] = $mp->typeHint; }
            }
        }
        $l = \strtolower($fqn);
        $this->curSpans[$l] = $d->span;
        $this->curClasses[$l] = new ClassInfo(
            $fqn, $d->kind, $d->extends, $d->implements, $d->uses, $methods, $consts, $d->isAbstract, $propTypes, $partial
        );
    }

    /**
     * Resolve a call-site function name to its signature. Tries the name as
     * written (already namespace-qualified by the parser), then the
     * global-namespace fallback PHP itself applies (`N\foo` → `foo`). null when
     * unknown — the caller must then treat it as "not a user function" and NOT
     * conclude it is undefined (it may be a builtin).
     */
    public function resolveFunction(string $name): ?FnInfo
    {
        $l = \strtolower(\ltrim($name, '\\'));
        if (isset($this->functions[$l])) { return $this->functions[$l]; }
        $bs = \strrpos($l, '\\');
        if ($bs !== false) {
            $base = \substr($l, $bs + 1);
            if (isset($this->functions[$base])) { return $this->functions[$base]; }
        }
        return null;
    }

    public function findClass(string $fqn): ?ClassInfo
    {
        $l = \strtolower(\ltrim($fqn, '\\'));
        return $this->classes[$l] ?? null;
    }

    /**
     * Resolve a method (or `__construct`) up the extends chain and through used
     * traits. null when no such method is declared anywhere reachable.
     */
    public function findMethod(string $classFqn, string $methodLower, int $depth): ?MethodInfo
    {
        if ($depth > 50) { return null; }
        $ci = $this->findClass($classFqn);
        if ($ci === null) { return null; }
        if (isset($ci->methods[$methodLower])) { return $ci->methods[$methodLower]; }
        foreach ($ci->parents as $p) {
            $r = $this->findMethod($p, $methodLower, $depth + 1);
            if ($r !== null) { return $r; }
        }
        foreach ($ci->traits as $t) {
            $r = $this->findMethod($t, $methodLower, $depth + 1);
            if ($r !== null) { return $r; }
        }
        return null;
    }

    /**
     * Declared type hint of a property, resolved up the extends chain and used
     * traits. Returns a wrapped result to distinguish "found, hint is null
     * (untyped property)" from "not found". null return = property unknown.
     */
    public function findPropertyType(string $classFqn, string $prop, int $depth): ?PropTypeResult
    {
        if ($depth > 50) { return null; }
        $ci = $this->findClass($classFqn);
        if ($ci === null) { return null; }
        if (\array_key_exists($prop, $ci->propTypes)) {
            return new PropTypeResult($ci->propTypes[$prop]);
        }
        foreach ($ci->parents as $p) {
            $r = $this->findPropertyType($p, $prop, $depth + 1);
            if ($r !== null) { return $r; }
        }
        foreach ($ci->traits as $t) {
            $r = $this->findPropertyType($t, $prop, $depth + 1);
            if ($r !== null) { return $r; }
        }
        return null;
    }

    /**
     * Is a class constant (or enum case) `$name` declared on $classFqn or any
     * ancestor? Constants inherit from parent classes, implemented interfaces AND
     * used traits, so all three are walked.
     */
    public function constExists(string $classFqn, string $name, int $depth): bool
    {
        if ($depth > 50) { return false; }
        $ci = $this->findClass($classFqn);
        if ($ci === null) { return false; }
        if (isset($ci->consts[$name])) { return true; }
        foreach ($ci->parents as $p) { if ($this->constExists($p, $name, $depth + 1)) { return true; } }
        foreach ($ci->interfaces as $i) { if ($this->constExists($i, $name, $depth + 1)) { return true; } }
        foreach ($ci->traits as $t) { if ($this->constExists($t, $name, $depth + 1)) { return true; } }
        return false;
    }

    /**
     * True when a class AND its full closure of parents, interfaces and traits
     * are all present in the index — the precondition for concluding a constant
     * is undefined (a const could be inherited from an unseen built-in base).
     */
    public function fullHierarchyKnown(string $classFqn, int $depth): bool
    {
        if ($depth > 50) { return true; }
        $ci = $this->findClass($classFqn);
        if ($ci === null || $ci->partial) { return false; }
        foreach ($ci->parents as $p) { if (!$this->fullHierarchyKnown($p, $depth + 1)) { return false; } }
        foreach ($ci->interfaces as $i) { if (!$this->fullHierarchyKnown($i, $depth + 1)) { return false; } }
        foreach ($ci->traits as $t) { if (!$this->fullHierarchyKnown($t, $depth + 1)) { return false; } }
        return true;
    }

    /** Does the class (or an ancestor) define `__call` / `__callStatic`? */
    public function hasMagicCall(string $classFqn): bool
    {
        return $this->findMethod($classFqn, '__call', 0) !== null
            || $this->findMethod($classFqn, '__callstatic', 0) !== null;
    }

    /**
     * True when the class and its entire extends/trait closure are all present
     * in the index. A rule uses this before concluding "no constructor ⇒ takes
     * zero args": if an ancestor is unknown (e.g. a builtin base class), the
     * real constructor might live there and the conclusion would be a false
     * positive.
     */
    /**
     * Is $sub the same as, or a descendant of, $sup (through `extends` /
     * `implements`)? Permissive: returns true when either class is outside the
     * index (a builtin/base we can't see), so an object-argument mismatch is
     * only ever reported when BOTH classes are fully known and unrelated.
     */
    public function isSubtype(string $sub, string $sup): bool
    {
        $s = \strtolower(\ltrim($sub, '\\'));
        $t = \strtolower(\ltrim($sup, '\\'));
        if ($s === $t) { return true; }
        if (!isset($this->classes[$s]) || !isset($this->classes[$t])) { return true; }
        return $this->reaches($s, $t, 0);
    }

    private function reaches(string $sub, string $supLower, int $depth): bool
    {
        if ($depth > 50) { return true; }
        $ci = $this->classes[$sub] ?? null;
        if ($ci === null) {
            // An unknown ancestor — can't disprove the relationship.
            return true;
        }
        // A class declaring `__toString` implements Stringable implicitly, as
        // php has it; a trait may be what declares it.
        if ($supLower === 'stringable' && $ci->kind !== 'interface') {
            if (isset($ci->methods['__tostring'])) { return true; }
            foreach ($ci->traits as $t) {
                $ti = $this->classes[\strtolower(\ltrim($t, '\\'))] ?? null;
                if ($ti === null || isset($ti->methods['__tostring'])) { return true; }
            }
        }
        foreach ($ci->parents as $p) {
            $pl = \strtolower(\ltrim($p, '\\'));
            if ($pl === $supLower) { return true; }
            if ($this->reaches($pl, $supLower, $depth + 1)) { return true; }
        }
        foreach ($ci->interfaces as $iface) {
            $il = \strtolower(\ltrim($iface, '\\'));
            if ($il === $supLower) { return true; }
            if ($this->reaches($il, $supLower, $depth + 1)) { return true; }
        }
        return false;
    }

    /** Whether any known class implementing `$iface` declares `$lowerMethod`. */
    public function implementerHasMethod(string $iface, string $lowerMethod): bool
    {
        $il = \strtolower(\ltrim($iface, '\\'));
        // reaches() is permissive: a class under an unseen ancestor, or any
        // __toString class for Stringable, reaches everything / Stringable.
        $cands = $this->implementersOf($il);
        foreach ($this->dependents as $target => $_) {
            if (isset($this->classes[$target])) { continue; }
            foreach ($this->implementersOf($target) as $c) { $cands[] = $c; }
            foreach ($this->dependents[$target] as $c => $__) { $cands[] = $c; }
        }
        if ($il === 'stringable') { $cands = \array_keys($this->classes); }
        foreach ($cands as $lc) {
            $ci = $this->classes[$lc] ?? null;
            if ($ci === null || $ci->kind === 'interface' || $lc === $il) { continue; }
            if (!$this->reaches($lc, $il, 0)) { continue; }
            if ($this->findMethod($ci->fqn, $lowerMethod, 0) !== null) { return true; }
        }
        return false;
    }

    public function hierarchyKnown(string $classFqn, int $depth): bool
    {
        if ($depth > 50) { return true; }
        $ci = $this->findClass($classFqn);
        if ($ci === null || $ci->partial) { return false; }
        foreach ($ci->parents as $p) {
            if (!$this->hierarchyKnown($p, $depth + 1)) { return false; }
        }
        foreach ($ci->traits as $t) {
            if (!$this->hierarchyKnown($t, $depth + 1)) { return false; }
        }
        return true;
    }
}
