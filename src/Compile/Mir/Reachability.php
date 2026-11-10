<?php
namespace Compile\Mir;

/**
 * Which functions the program can run, by rapid type analysis over the lowered MIR.
 *
 * Roots are `__main`, every lazy include unit (`__mc_unit_*`) and the global
 * initialisers ({@see Module::$globalDefaults}). From a reached body: a direct
 * call reaches its callee, a closure literal its body, a string literal naming a
 * function that function (string callables); `new C` instantiates C and its
 * ancestors, and a method call demands its NAME — every instantiated class's
 * method of that name is reached, now or when a later `new` instantiates one.
 * A `new $cls`, a call by a runtime method name or a callable array with a
 * runtime name makes the analysis assume the worst for that axis.
 *
 * It is an OVER-approximation: a function reported unreachable is one no path
 * from a root can call; anything it cannot see through counts as reached.
 * Prelude bodies are walked when reached (they call back into user code) but
 * are never roots and never reported.
 */
final class Reachability
{
    /** A reached body contains `new $cls`: every class counts as instantiated. */
    public bool $dynamicNew = false;

    /** @var array<string, bool> */
    private array $fnNames = [];
    /** @var array<string, string> lowercase function name => its symbol */
    private array $fnLc = [];
    /** @var array<string, bool> prelude bodies and declare-only externs: walked when reached, never reported */
    private array $hiddenFns = [];
    /** @var array<string, ClassDef> lowercase class name => its def */
    private array $classLc = [];
    /** @var array<string, array<string, string>> lowercase class => lowercase method => the method's own symbol */
    private array $methodsOf = [];
    /** @var array<string, array<string, string>> lowercase class => lowercase method => declared spelling */
    private array $methodSpelling = [];
    /** @var array<string, array<string, bool>> lowercase method => lowercase classes declaring a body for it */
    private array $declaringClasses = [];

    /** @var array<string, string> reached symbol => the symbol it was reached from ('' = a root) */
    private array $parent = [];
    /** @var string[] */
    private array $queue = [];
    /** @var array<string, bool> */
    private array $instantiated = [];
    /** @var array<string, bool> lowercase method names some reached site may dispatch on */
    private array $demanded = [];
    /** @var array<string, bool> method names demanded on EVERY class (unresolved `static::m()`) */
    private array $demandedAll = [];
    /** Every method of every instantiated class is reached (a call by a runtime method name). */
    private bool $allMethods = false;
    /** @var array<string, bool> lowercase classes named by a reached static call */
    private array $staticCalled = [];
    /** @var array<string, string[]> method symbol => its late-static-binding copies for instantiated subclasses */
    private array $lsbOf = [];

    /** @var array<string, array<string, bool>> lowercase class => functions statically referencing it (`C::K`, `C::$p`) */
    private array $classRefs = [];
    /** @var array<string, bool>|null lowercase class / interface names in use, null until first asked */
    private ?array $usedClasses = null;
    /** @var array<string, string[]> interface => every interface it extends */
    private array $ifaceAncestors = [];
    /** @var array<string, bool> lowercase interface names */
    private array $ifaceLc = [];
    /** @var array<string, bool> lowercase trait names */
    private array $traitLc = [];

    /** Methods the runtime calls without a call site naming them, beyond every `__*` magic method. */
    private const IMPLICIT = ['current' => true, 'key' => true, 'next' => true, 'rewind' => true, 'valid' => true,
        'getiterator' => true, 'offsetget' => true, 'offsetset' => true, 'offsetexists' => true, 'offsetunset' => true,
        'count' => true, 'jsonserialize' => true, 'serialize' => true, 'unserialize' => true];

    private function __construct() {}

    /**
     * @param array<string, array<string, bool>> $staticClassRefs lowercase class => fns reading its constants /
     *   static properties (recorded by lowering, since a constant is inlined and leaves no trace in MIR)
     */
    public static function compute(Module $m, array $staticClassRefs = []): self
    {
        $r = new self();
        $r->classRefs = $staticClassRefs;
        foreach ($m->functions as $fn) {
            $r->fnNames[$fn->name] = true;
            $r->fnLc[\strtolower($fn->name)] = $fn->name;
            if ($fn->isPrelude || $fn->isExtern) { $r->hiddenFns[$fn->name] = true; }
        }
        foreach ($m->classes as $cd) {
            $lc = \strtolower(\ltrim($cd->name, '\\'));
            $r->classLc[$lc] = $cd;
            $own = [];
            $spell = [];
            foreach ($cd->methodNames as $mn => $_) {
                $sym = $cd->name . '__' . $mn;
                if (!isset($r->fnNames[$sym])) { continue; }
                $lm = \strtolower($mn);
                $own[$lm] = $sym;
                $spell[$lm] = $mn;
                $r->declaringClasses[$lm][$lc] = true;
            }
            $r->methodsOf[$lc] = $own;
            $r->methodSpelling[$lc] = $spell;
        }
        foreach ($m->interfaceNames as $in => $_) { $r->ifaceLc[\strtolower(\ltrim($in, '\\'))] = true; }
        foreach ($m->interfaceAncestors as $in => $list) { $r->ifaceAncestors[\strtolower(\ltrim($in, '\\'))] = $list; }
        foreach ($m->traitNames as $tn => $_) { $r->traitLc[\strtolower(\ltrim($tn, '\\'))] = true; }

        if ($m->callableArrayAnyName) { $r->allMethods = true; }
        // An enum case is a constant, never a `new`: count every user enum with methods as instantiated.
        foreach ($m->enums as $en => $_) {
            $lc = \strtolower(\ltrim($en, '\\'));
            if (isset($r->classLc[$lc]) && !$r->classLc[$lc]->isPreludeClass) { $r->instantiate($lc, ''); }
        }
        foreach ($m->functions as $fn) {
            if ($fn->isPrelude) { continue; }
            if ($fn->name === '__main' || \str_starts_with($fn->name, '__mc_unit_')) { $r->enqueue($fn->name, ''); }
        }
        foreach ($m->globalDefaults as $i => $g) {
            if ($m->globalIsPrelude[$i] ?? false) { continue; }
            $r->walk($g, '');
        }
        /** @var array<string, FunctionDef> $fns */
        $fns = [];
        foreach ($m->functions as $fn) { $fns[$fn->name] = $fn; }
        for ($i = 0; $i < \count($r->queue); $i = $i + 1) {
            $sym = $r->queue[$i];
            foreach (($r->lsbOf[$sym] ?? []) as $copy) { $r->enqueue($copy, $sym); }
            $fd = $fns[$sym] ?? null;
            if ($fd === null) { continue; }
            $r->walk($fd->body, $sym);
        }
        return $r;
    }

    /** Can `$fnSymbol` run? `@class:<FQN>` asks {@see classUsed}; a name that is no module function counts as reached. */
    public function reaches(string $fnSymbol): bool
    {
        if (\str_starts_with($fnSymbol, '@class:')) { return $this->classUsed(\substr($fnSymbol, 7)); }
        if (!isset($this->fnNames[$fnSymbol])) { return true; }
        return isset($this->parent[$fnSymbol]);
    }

    /**
     * The shortest call path from a root to `$fnSymbol`, nearest first (the symbol itself, its caller, …).
     * @return string[]
     */
    public function chain(string $fnSymbol, int $max = 4): array
    {
        $out = [];
        $cur = $fnSymbol;
        while (\count($out) < $max && isset($this->parent[$cur])) {
            $out[] = $cur;
            $cur = $this->parent[$cur];
            if ($cur === '') { break; }
        }
        return $out;
    }

    /**
     * Is class-level code of `$fqn` (its defaults, constants, hierarchy) ever evaluated: the class is
     * instantiated, statically referenced, or an ancestor / interface of such a class. A trait, or a
     * name that is no class or interface, counts as used.
     */
    public function classUsed(string $fqn): bool
    {
        $lc = \strtolower(\ltrim($fqn, '\\'));
        if ($this->dynamicNew) { return true; }
        if (!isset($this->classLc[$lc]) && !isset($this->ifaceLc[$lc])) { return true; }
        if ($this->usedClasses === null) { $this->usedClasses = $this->computeUsedClasses(); }
        return isset($this->usedClasses[$lc]);
    }

    /** `reach:` lines for every reached user function, then `class:` lines for every used user class. */
    public function dump(): string
    {
        $fns = [];
        foreach ($this->parent as $sym => $_) {
            if (!isset($this->hiddenFns[$sym])) { $fns[] = 'reach: ' . $sym; }
        }
        \sort($fns);
        $cls = [];
        foreach ($this->classLc as $lc => $cd) {
            if (!$cd->isPreludeClass && !$cd->isExternClass && $this->classUsed($lc)) { $cls[] = 'class: ' . \ltrim($cd->name, '\\'); }
        }
        \sort($cls);
        $out = '';
        foreach ($fns as $l) { $out .= $l . "\n"; }
        foreach ($cls as $l) { $out .= $l . "\n"; }
        return $out;
    }

    /** @return array<string, bool> */
    private function computeUsedClasses(): array
    {
        $used = [];
        foreach ($this->instantiated as $lc => $_) { $used[$lc] = true; }
        foreach ($this->staticCalled as $lc => $_) { $used[$lc] = true; }
        // A reference recorded inside another class's class-level code counts once THAT class is used.
        $changed = true;
        while ($changed) {
            $changed = false;
            $used = $this->closeOverAncestors($used);
            foreach ($this->classRefs as $lc => $fns) {
                if (isset($used[$lc])) { continue; }
                foreach ($fns as $fn => $_) {
                    $hit = \str_starts_with($fn, '@class:')
                        ? $this->refClassUsed(\strtolower(\ltrim(\substr($fn, 7), '\\')), $used)
                        : $this->reaches($fn);
                    if ($hit) { $used[$lc] = true; $changed = true; break; }
                }
            }
        }
        return $used;
    }

    /** @param array<string, bool> $used */
    private function refClassUsed(string $lc, array $used): bool
    {
        if (!isset($this->classLc[$lc]) && !isset($this->ifaceLc[$lc])) { return true; }
        return isset($used[$lc]);
    }

    /**
     * @param array<string, bool> $used
     * @return array<string, bool>
     */
    private function closeOverAncestors(array $used): array
    {
        $out = $used;
        foreach ($used as $lc => $_) {
            $cur = $lc;
            while ($cur !== '' && isset($this->classLc[$cur])) {
                $cd = $this->classLc[$cur];
                $out[$cur] = true;
                foreach ($cd->interfaces as $in) { $this->addIface(\strtolower(\ltrim($in, '\\')), $out); }
                $cur = \strtolower(\ltrim($cd->parent, '\\'));
            }
            if (isset($this->ifaceLc[$lc])) { $this->addIface($lc, $out); }
        }
        return $out;
    }

    /** @param array<string, bool> $out */
    private function addIface(string $lc, array &$out): void
    {
        $out[$lc] = true;
        foreach (($this->ifaceAncestors[$lc] ?? []) as $a) { $out[\strtolower(\ltrim($a, '\\'))] = true; }
    }

    private function enqueue(string $sym, string $from): void
    {
        if (!isset($this->fnNames[$sym]) || isset($this->parent[$sym])) { return; }
        $this->parent[$sym] = $from;
        $this->queue[] = $sym;
    }

    /** Mark `$lc` and its ancestors instantiated; reach every method of theirs the runtime or a demanded name can call. */
    private function instantiate(string $lc, string $from): void
    {
        $leaf = $lc;
        $cur = $lc;
        while ($cur !== '' && isset($this->classLc[$cur])) {
            $cd = $this->classLc[$cur];
            if ($cur !== $leaf) { $this->noteLsbCopies($cur, $leaf, $from); }
            if (!isset($this->instantiated[$cur])) {
                $this->instantiated[$cur] = true;
                $this->usedClasses = null;
                foreach ($this->methodsOf[$cur] as $lm => $sym) {
                    if ($this->allMethods || \str_starts_with($lm, '__') || isset(self::IMPLICIT[$lm])
                        || isset($this->demanded[$lm])) {
                        $this->enqueue($sym, $from);
                    }
                }
            }
            $cur = \strtolower(\ltrim($cd->parent, '\\'));
        }
    }

    /** `A__m__lsbC`: the copy of ancestor A's method specialised for subclass C — reached with `A__m`. */
    private function noteLsbCopies(string $anc, string $leaf, string $from): void
    {
        $ancName = $this->classLc[$anc]->name;
        $leafName = $this->classLc[$leaf]->name;
        foreach ($this->methodsOf[$anc] as $lm => $sym) {
            $copy = $ancName . '__' . $this->methodSpelling[$anc][$lm] . '__lsb' . $leafName;
            if (!isset($this->fnNames[$copy])) { continue; }
            $this->lsbOf[$sym][] = $copy;
            if (isset($this->parent[$sym])) { $this->enqueue($copy, $sym); }
        }
    }

    private function demand(string $method, string $from): void
    {
        $lm = \strtolower($method);
        if (isset($this->demanded[$lm])) { return; }
        $this->demanded[$lm] = true;
        foreach (($this->declaringClasses[$lm] ?? []) as $lc => $_) {
            if (isset($this->instantiated[$lc])) { $this->enqueue($this->methodsOf[$lc][$lm], $from); }
        }
    }

    private function demandEverywhere(string $method, string $from): void
    {
        $lm = \strtolower($method);
        if (isset($this->demandedAll[$lm])) { return; }
        $this->demandedAll[$lm] = true;
        foreach (($this->declaringClasses[$lm] ?? []) as $lc => $_) { $this->enqueue($this->methodsOf[$lc][$lm], $from); }
    }

    private function reachAllMethods(string $from): void
    {
        if ($this->allMethods) { return; }
        $this->allMethods = true;
        foreach ($this->instantiated as $lc => $_) {
            foreach ($this->methodsOf[$lc] as $sym) { $this->enqueue($sym, $from); }
        }
    }

    private function newDynamic(string $from): void
    {
        if ($this->dynamicNew) { return; }
        $this->dynamicNew = true;
        foreach ($this->classLc as $lc => $cd) {
            if (!$cd->isPreludeClass) { $this->instantiate($lc, $from); }
        }
    }

    /** `C::m()`: C's own `m` or the nearest ancestor's, plus that ancestor's late-static copy for C. */
    private function staticCall(string $class, string $method, string $from): void
    {
        $lc = \strtolower(\ltrim($class, '\\'));
        if ($lc === 'self' || $lc === 'static' || $lc === 'parent' || $lc === '') {
            $this->demandEverywhere($method, $from);
            return;
        }
        if (!isset($this->classLc[$lc])) { return; }
        if (!isset($this->staticCalled[$lc])) { $this->staticCalled[$lc] = true; $this->usedClasses = null; }
        $lm = \strtolower($method);
        $cur = $lc;
        while ($cur !== '' && isset($this->classLc[$cur])) {
            if (isset($this->methodsOf[$cur][$lm])) {
                $sym = $this->methodsOf[$cur][$lm];
                $this->enqueue($sym, $from);
                if ($cur !== $lc) {
                    $this->enqueue($this->classLc[$cur]->name . '__' . $this->methodSpelling[$cur][$lm]
                        . '__lsb' . $this->classLc[$lc]->name, $from);
                }
                return;
            }
            $cur = \strtolower(\ltrim($this->classLc[$cur]->parent, '\\'));
        }
        // No body found up the chain (`__callStatic`, an abstract or interface method): any class may answer.
        $this->demandEverywhere($method, $from);
    }

    /** A string literal: a function name (string callable) or `'C::m'`. */
    private function stringLiteral(string $s, string $from): void
    {
        if ($s === '') { return; }
        $c = $s[0];
        if (!(($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === '_' || $c === '\\')) { return; }
        $fn = $this->fnLc[\strtolower(\ltrim($s, '\\'))] ?? '';
        if ($fn !== '') { $this->enqueue($fn, $from); return; }
        $cc = \strpos($s, '::');
        if ($cc !== false && $cc > 0) { $this->staticCall(\substr($s, 0, $cc), \substr($s, $cc + 2), $from); }
    }

    private function isIdent(string $s): bool
    {
        if ($s === '') { return false; }
        $c = $s[0];
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === '_';
    }

    // Narrow before reading a field: the native build resolves a property by the DECLARED type's offset
    // ({@see DependencyIndex::asCall}).
    private function asCall(Node $n): Call { return $n; }
    private function asMethodCall(Node $n): MethodCall_ { return $n; }
    private function asStaticCall(Node $n): StaticCall_ { return $n; }
    private function asNewObj(Node $n): NewObj { return $n; }
    private function asClosure(Node $n): Closure_ { return $n; }
    private function asString(Node $n): StringConst { return $n; }
    private function asArrayLit(Node $n): ArrayLit { return $n; }
    private function asInvoke(Node $n): Invoke_ { return $n; }

    private function walk(Node $root, string $from): void
    {
        /** @var Node[] $stack */
        $stack = [$root];
        while (\count($stack) > 0) {
            $n = \array_pop($stack);
            $k = $n->kind;
            if ($k === Node::KIND_CALL) {
                $f = $this->asCall($n)->function;
                if (isset($this->fnNames[$f])) { $this->enqueue($f, $from); }
                else { $this->enqueue($this->fnLc[\strtolower($f)] ?? '', $from); }
            } elseif ($k === Node::KIND_STRING_CONST) {
                $this->stringLiteral($this->asString($n)->value, $from);
            } elseif ($k === Node::KIND_CLOSURE) {
                $this->enqueue('__closure_' . (string)$this->asClosure($n)->id, $from);
            } elseif ($k === Node::KIND_NEW_OBJ) {
                $this->instantiate(\strtolower(\ltrim($this->asNewObj($n)->class, '\\')), $from);
            } elseif ($k === Node::KIND_NEW_DYN_OBJ) {
                $this->newDynamic($from);
            } elseif ($k === Node::KIND_METHOD_CALL) {
                $this->demand($this->asMethodCall($n)->method, $from);
            } elseif ($k === Node::KIND_STATIC_CALL) {
                $sc = $this->asStaticCall($n);
                $this->staticCall($sc->class, $sc->method, $from);
                if ($sc->staticClass !== '' && \strtolower($sc->staticClass) !== \strtolower($sc->class)) {
                    $this->staticCall($sc->staticClass, $sc->method, $from);
                }
            } elseif ($k === Node::KIND_INVOKE) {
                // `$o->$name()` lowers to an invoke of a dynamic property read: any method may run.
                if ($this->asInvoke($n)->callee->kind === Node::KIND_DYN_PROP) { $this->reachAllMethods($from); }
            } elseif ($k === Node::KIND_ARRAY_LIT) {
                // `[$obj, 'm']` / `['C', 'm']`: a callable array demands its method name.
                $el = $this->asArrayLit($n)->elements;
                if (\count($el) === 2 && $el[0]->key === null && $el[1]->key === null
                    && $el[1]->value->kind === Node::KIND_STRING_CONST) {
                    $mn = $this->asString($el[1]->value)->value;
                    if ($this->isIdent($mn)) {
                        $this->demand($mn, $from);
                        if ($el[0]->value->kind === Node::KIND_STRING_CONST) {
                            $this->staticCall($this->asString($el[0]->value)->value, $mn, $from);
                        }
                    }
                }
            }
            foreach (Walk::children($n) as $c) { $stack[] = $c; }
        }
    }
}
