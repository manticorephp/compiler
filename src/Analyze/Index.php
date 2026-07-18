<?php

namespace Analyze;

use Parser\Ast\ClassDecl;
use Parser\Ast\ClassStmt;
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

    /** @param ParsedFile[] $files */
    public static function build(array $files): Index
    {
        $idx = new Index();
        foreach ($files as $pf) {
            $idx->collectStmts($pf->program->statements);
        }
        return $idx;
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
            } elseif ($s instanceof NamespaceStmt && $s->body !== null) {
                $this->collectStmts($s->body->statements);
            }
        }
    }

    private function addFunction(FunctionDecl $d): void
    {
        $this->functions[\strtolower($d->name)] = new FnInfo($d->name, $d->params, $d->returnType);
    }

    private function addClass(ClassDecl $d): void
    {
        $fqn = $d->name;
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
        foreach ($d->properties as $prop) { $propTypes[$prop->name] = $prop->typeHint; }
        // Constructor property promotion also declares properties.
        foreach ($d->methods as $m) {
            if (\strtolower($m->name) !== '__construct') { continue; }
            foreach ($m->params as $mp) {
                if ($mp->promoted !== '') { $propTypes[$mp->name] = $mp->typeHint; }
            }
        }
        $this->classes[\strtolower($fqn)] = new ClassInfo(
            $fqn, $d->kind, $d->extends, $d->implements, $d->uses, $methods, $consts, $d->isAbstract, $propTypes
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
        if ($ci === null) { return false; }
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

    public function hierarchyKnown(string $classFqn, int $depth): bool
    {
        if ($depth > 50) { return true; }
        $ci = $this->findClass($classFqn);
        if ($ci === null) { return false; }
        foreach ($ci->parents as $p) {
            if (!$this->hierarchyKnown($p, $depth + 1)) { return false; }
        }
        foreach ($ci->traits as $t) {
            if (!$this->hierarchyKnown($t, $depth + 1)) { return false; }
        }
        return true;
    }
}
