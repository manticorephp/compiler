<?php
namespace Compile\Mir;

/**
 * Which functions the program can run, by rapid type analysis over the lowered MIR.
 *
 * Roots are `__main`, every `__mc_*` / `__mir_*` body lowering synthesized (include
 * units, attribute factories, reflection trampolines: the emitter calls them) and
 * the global initialisers ({@see Module::$globalDefaults}). From a reached body: a
 * direct call reaches its callee, a closure literal its body, a string literal
 * naming a function that function (string callables); `new C` instantiates C and
 * its ancestors, and a method call demands its NAME — every instantiated class's
 * method of that name is reached, now or when a later `new` instantiates one.
 *
 * Two levels: {@see reaches} follows those explicit edges only; {@see maybeReaches}
 * also assumes a call by a runtime function name, a `new $cls`, a call by a runtime
 * method name or a runtime class + method reach everything they could.
 * Prelude bodies are walked when reached (they call back into user code) but
 * are never roots and never reported.
 */
final class Reachability
{
    /** A reached body contains `new $cls` (information only: it widens the DYNAMIC level, never the static one). */
    public bool $dynamicNew = false;

    /**
     * Two levels. STATIC: reached from a root through explicit edges only. DYNAMIC: additionally reached
     * once the widenings a reached body asks for fire (runtime function name, `new $x`, `$o->$m()`,
     * runtime class + method). While the static level is computed a widening is only recorded in
     * {@see $pending}; then the snapshot is taken and the widenings run.
     */
    private bool $widen = false;
    /** @var array<string, bool> widenings asked for before {@see $widen}: fns | methods | static | new */
    private array $pending = [];
    private int $qi = 0;
    /** @var array<string, string> the static level of {@see $parent} */
    private array $staticParent = [];
    /** @var array<string, bool> */
    private array $staticInstantiated = [];
    /** @var array<string, bool> */
    private array $staticStaticCalled = [];
    /** @var array<string, bool>|null */
    private ?array $maybeUsedClasses = null;
    private bool $newDone = false;
    /** @var array<string, bool> classes `new`ed themselves (their late-static copies are noted once) */
    private array $leafDone = [];

    /** @var array<string, FunctionDef> */
    private array $fnDefs = [];
    private bool $hasUnits = false;
    /** @var array<string, LocalValueSets> per function, built on the first dynamic target met in it */
    private array $localSets = [];

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
    /** @var array<string, bool> method names whose STATIC bodies are demanded on every class ({@see demandStatic}) */
    private array $demandedStatic = [];
    /** @var array<string, bool> `lowercase class|method` pairs demanded across that class's hierarchy */
    private array $demandedIn = [];
    /** @var array<string, string> method symbol => lowercase class declaring it */
    private array $classOfFn = [];
    /** Every method of every instantiated class is reached (a call by a runtime method name). */
    private bool $allMethods = false;
    /** A reached call by a runtime function name: every nameable function is reached. */
    private bool $nameableFns = false;
    /** Every static method of every class is reached (a runtime class + method name). */
    private bool $allStatic = false;
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
    /** @var array<string, string> class name as reflection factories mangle it => lowercase class */
    private array $classByMangled = [];

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
            if (\str_starts_with($fn->name, '__mc_unit_')) { $r->hasUnits = true; }
        }
        foreach ($m->classes as $cd) {
            $lc = \strtolower(\ltrim($cd->name, '\\'));
            $r->classLc[$lc] = $cd;
            $r->classByMangled[\str_replace('\\', '_', \ltrim($cd->name, '\\'))] = $lc;
            $own = [];
            $spell = [];
            foreach ($cd->methodNames as $mn => $_) {
                $sym = $cd->name . '__' . $mn;
                if (!isset($r->fnNames[$sym])) { continue; }
                $lm = \strtolower($mn);
                $own[$lm] = $sym;
                $spell[$lm] = $mn;
                $r->declaringClasses[$lm][$lc] = true;
                $r->classOfFn[$sym] = $lc;
            }
            $r->methodsOf[$lc] = $own;
            $r->methodSpelling[$lc] = $spell;
        }
        foreach ($m->interfaceNames as $in => $_) { $r->ifaceLc[\strtolower(\ltrim($in, '\\'))] = true; }
        foreach ($m->interfaceAncestors as $in => $list) { $r->ifaceAncestors[\strtolower(\ltrim($in, '\\'))] = $list; }
        foreach ($m->traitNames as $tn => $_) { $r->traitLc[\strtolower(\ltrim($tn, '\\'))] = true; }

        /** @var string[] $dynRoots */
        $dynRoots = [];
        if ($m->callableArrayAnyName) { $r->reachAllMethods(''); $r->reachAllStatic(''); }
        // An enum case is a constant, never a `new`: count every user enum with methods as instantiated.
        foreach ($m->enums as $en => $_) {
            $lc = \strtolower(\ltrim($en, '\\'));
            if (isset($r->classLc[$lc]) && !$r->classLc[$lc]->isPreludeClass) { $r->instantiate($lc, ''); }
        }
        foreach ($m->functions as $fn) {
            if ($fn->isPrelude) { continue; }
            // `__mc_*` / `__mir_*` bodies synthesized by lowering are called by the emitter, not by a MIR call.
            // Include units and the reflection tables (attribute factories, parameter defaults, constant
            // tables, property getters / setters) are static roots; the dispatchers that exist for dynamic
            // calls (static-callable arms, function / reflection trampolines, …) root the dynamic level only.
            $n = $fn->name;
            if (self::isStaticRoot($n)) {
                $r->enqueue($n, '');
            } elseif (\str_starts_with($n, '__mc_') || \str_starts_with($n, '__mir_')) {
                $dynRoots[] = $n;
            }
        }
        foreach ($m->globalDefaults as $i => $g) {
            if ($m->globalIsPrelude[$i] ?? false) { continue; }
            $r->walk($g, '');
        }
        /** @var array<string, FunctionDef> $fns */
        $fns = [];
        foreach ($m->functions as $fn) { $fns[$fn->name] = $fn; }
        $r->fnDefs = $fns;
        $r->drain($fns);
        $r->staticParent = $r->parent;
        $r->staticInstantiated = $r->instantiated;
        $r->staticStaticCalled = $r->staticCalled;
        $r->widen = true;
        foreach ($dynRoots as $dr) { $r->enqueue($dr, ''); }
        if (isset($r->pending['fns'])) { $r->reachNameableFns(''); }
        if (isset($r->pending['methods'])) { $r->reachAllMethods(''); }
        if (isset($r->pending['static'])) { $r->reachAllStatic(''); }
        if (isset($r->pending['new'])) { $r->newDynamic(''); }
        $r->drain($fns);
        $r->usedClasses = null;
        return $r;
    }

    /** @param array<string, FunctionDef> $fns */
    private function drain(array $fns): void
    {
        while ($this->qi < \count($this->queue)) {
            $sym = $this->queue[$this->qi];
            $this->qi = $this->qi + 1;
            foreach (($this->lsbOf[$sym] ?? []) as $copy) { $this->enqueue($copy, $sym); }
            $fd = $fns[$sym] ?? null;
            if ($fd === null) { continue; }
            $this->walk($fd->body, $sym);
        }
    }

    /** Does an explicit call path from a root reach `$fnSymbol`? `@class:<FQN>` asks {@see classUsed}; a name that is no module function counts as reached. */
    public function reaches(string $fnSymbol): bool
    {
        if (\str_starts_with($fnSymbol, '@class:')) { return $this->classUsed(\substr($fnSymbol, 7)); }
        if (!isset($this->fnNames[$fnSymbol])) { return true; }
        $owner = $this->factoryOwner($fnSymbol);
        if ($owner !== '') { return $this->classUsed($owner); }
        return isset($this->staticParent[$fnSymbol]);
    }

    /** Can `$fnSymbol` run once dynamic calls are assumed to reach whatever they could? */
    public function maybeReaches(string $fnSymbol): bool
    {
        if (\str_starts_with($fnSymbol, '@class:')) { return $this->classMaybeUsed(\substr($fnSymbol, 7)); }
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
        while (\count($out) < $max && isset($this->staticParent[$cur])) {
            $out[] = $cur;
            $cur = $this->staticParent[$cur];
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
        if (!isset($this->classLc[$lc]) && !isset($this->ifaceLc[$lc])) { return true; }
        if ($this->usedClasses === null) { $this->usedClasses = $this->computeUsedClasses(false); }
        return isset($this->usedClasses[$lc]);
    }

    /** {@see classUsed} at the dynamic level: a `new $x` anywhere reached makes every class maybe-used. */
    public function classMaybeUsed(string $fqn): bool
    {
        $lc = \strtolower(\ltrim($fqn, '\\'));
        if ($this->dynamicNew) { return true; }
        if (!isset($this->classLc[$lc]) && !isset($this->ifaceLc[$lc])) { return true; }
        if ($this->maybeUsedClasses === null) { $this->maybeUsedClasses = $this->computeUsedClasses(true); }
        return isset($this->maybeUsedClasses[$lc]);
    }

    /** `reach:` lines for every reached user function, then `class:` lines for every used user class. */
    public function dump(): string
    {
        $fns = [];
        foreach ($this->parent as $sym => $_) {
            if (isset($this->hiddenFns[$sym])) { continue; }
            if ($this->isSynthesized($sym)) { continue; }
            $fns[] = (isset($this->staticParent[$sym]) ? 'reach: ' : 'reach?: ') . $sym;
        }
        \sort($fns);
        $cls = [];
        foreach ($this->classLc as $lc => $cd) {
            if ($cd->isPreludeClass || $cd->isExternClass) { continue; }
            if ($this->classUsed($lc)) { $cls[] = 'class: ' . \ltrim($cd->name, '\\'); }
            elseif ($this->classMaybeUsed($lc)) { $cls[] = 'class?: ' . \ltrim($cd->name, '\\'); }
        }
        \sort($cls);
        $out = '';
        foreach ($fns as $l) { $out .= $l . "\n"; }
        foreach ($cls as $l) { $out .= $l . "\n"; }
        return $out;
    }

    /** @return array<string, bool> */
    private function computeUsedClasses(bool $maybe): array
    {
        $used = [];
        foreach (($maybe ? $this->instantiated : $this->staticInstantiated) as $lc => $_) { $used[$lc] = true; }
        foreach (($maybe ? $this->staticCalled : $this->staticStaticCalled) as $lc => $_) { $used[$lc] = true; }
        // A reference recorded inside another class's class-level code counts once THAT class is used.
        $changed = true;
        while ($changed) {
            $changed = false;
            $used = $this->closeOverAncestors($used);
            foreach ($this->classRefs as $lc => $fns) {
                if (isset($used[$lc])) { continue; }
                foreach ($fns as $fn => $_) {
                    $owner = $maybe ? '' : $this->factoryOwner($fn);
                    $hit = \str_starts_with($fn, '@class:')
                        ? $this->refClassUsed(\strtolower(\ltrim(\substr($fn, 7), '\\')), $used)
                        : ($owner !== '' ? $this->refClassUsed($owner, $used)
                            : ($maybe ? $this->maybeReaches($fn) : $this->reaches($fn)));
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
        if (isset($this->leafDone[$lc])) { return; }
        $this->leafDone[$lc] = true;
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
            if ($this->classLc[$anc]->isPreludeClass) { $this->hiddenFns[$copy] = true; }
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

    /**
     * A callable array whose receiver may be a runtime CLASS name: any class's STATIC `m`. An instance `m`
     * runs only on a live object (or forwarded `$this`, an instance of a subclass), which {@see demand} covers.
     */
    private function demandStatic(string $method, string $from): void
    {
        $lm = \strtolower($method);
        if (isset($this->demandedStatic[$lm]) || isset($this->demandedAll[$lm])) { return; }
        $this->demandedStatic[$lm] = true;
        foreach (($this->declaringClasses[$lm] ?? []) as $lc => $_) {
            $mm = $this->classLc[$lc]->methodMeta[$this->methodSpelling[$lc][$lm]] ?? null;
            if ($mm === null || $mm->isStatic) { $this->enqueue($this->methodsOf[$lc][$lm], $from); }
        }
    }

    /** `m` of every class that is `$lc`, an ancestor of it or a descendant of it. */
    private function demandInHierarchy(string $lc, string $method, string $from): void
    {
        $lm = \strtolower($method);
        $key = $lc . '|' . $lm;
        if (isset($this->demandedAll[$lm]) || isset($this->demandedIn[$key])) { return; }
        $this->demandedIn[$key] = true;
        foreach (($this->declaringClasses[$lm] ?? []) as $c => $_) {
            if ($this->isAncestorOf((string)$c, $lc) || $this->isAncestorOf($lc, (string)$c)) {
                $this->enqueue($this->methodsOf[$c][$lm], $from);
            }
        }
    }

    /** Is `$anc` the class `$lc` or one of its parents? */
    private function isAncestorOf(string $anc, string $lc): bool
    {
        $cur = $lc;
        while ($cur !== '' && isset($this->classLc[$cur])) {
            if ($cur === $anc) { return true; }
            $cur = \strtolower(\ltrim($this->classLc[$cur]->parent, '\\'));
        }
        return false;
    }

    private function reachAllMethods(string $from): void
    {
        if (!$this->widen) { $this->pending['methods'] = true; return; }
        if ($this->allMethods) { return; }
        $this->allMethods = true;
        foreach ($this->instantiated as $lc => $_) {
            foreach ($this->methodsOf[$lc] as $sym) { $this->enqueue($sym, $from); }
        }
    }

    /** A `__mc_*` / `__mir_*` body that exists for dynamic calls, not a reflection table or an include unit. */
    private function isDynamicHelper(string $n): bool
    {
        if (!\str_starts_with($n, '__mc_') && !\str_starts_with($n, '__mir_')) { return false; }
        return !self::isStaticRoot($n);
    }

    /** Runtime machinery lowering synthesized: never reported (an include unit is user code). */
    private function isSynthesized(string $n): bool
    {
        return (\str_starts_with($n, '__mc_') && !\str_starts_with($n, '__mc_unit_')) || \str_starts_with($n, '__mir_');
    }

    private static function isStaticRoot(string $n): bool
    {
        return $n === '__main' || \str_starts_with($n, '__mc_unit_') || \str_starts_with($n, '__mc_attr_')
            || \str_starts_with($n, '__mc_pdef_') || \str_starts_with($n, '__mc_consts_')
            || \str_starts_with($n, '__mc_pget_') || \str_starts_with($n, '__mc_pset_');
    }

    /**
     * The lowercase class a per-class reflection factory belongs to (`__mc_consts_<C>`, `__mc_pdef_<C>__…`,
     * `__mc_attr_{args,new}_<C>__…`, `__mc_p{get,set}_<C>__…`), '' for any other symbol. Such a body runs only
     * once its class is in use (named in code, or reflected on by a runtime name — the dynamic level).
     */
    private function factoryOwner(string $fn): string
    {
        if (!\str_starts_with($fn, '__mc_')) { return ''; }
        if (\str_starts_with($fn, '__mc_consts_')) { return $this->classByMangled[\substr($fn, 12)] ?? ''; }
        $rest = '';
        foreach (['__mc_pdef_', '__mc_attr_args_', '__mc_attr_new_', '__mc_pget_', '__mc_pset_'] as $p) {
            if (\str_starts_with($fn, $p)) { $rest = \substr($fn, \strlen($p)); break; }
        }
        $at = \strpos($rest, '__');
        while ($at !== false) {
            $lc = $this->classByMangled[\substr($rest, 0, $at)] ?? '';
            if ($lc !== '') { return $lc; }
            $at = \strpos($rest, '__', $at + 1);
        }
        return '';
    }

    private function reachAllStatic(string $from): void
    {
        if (!$this->widen) { $this->pending['static'] = true; return; }
        if ($this->allStatic) { return; }
        $this->allStatic = true;
        foreach ($this->classLc as $lc => $cd) {
            foreach ($cd->methodMeta as $mn => $mm) {
                if (!$mm->isStatic) { continue; }
                $sym = $this->methodsOf[$lc][\strtolower((string)$mn)] ?? '';
                if ($sym !== '') { $this->enqueue($sym, $from); }
            }
        }
    }

    private function reachNameableFns(string $from): void
    {
        if (!$this->widen) { $this->pending['fns'] = true; return; }
        if ($this->nameableFns) { return; }
        $this->nameableFns = true;
        foreach ($this->fnNames as $sym => $_) {
            if (\strpos($sym, '__') === false && !\str_contains($sym, '$mono$')) { $this->enqueue($sym, $from); }
        }
    }

    private function newDynamic(string $from): void
    {
        $this->dynamicNew = true;
        if (!$this->widen) { $this->pending['new'] = true; return; }
        if ($this->newDone) { return; }
        $this->newDone = true;
        foreach ($this->classLc as $lc => $cd) {
            if (!$cd->isPreludeClass) { $this->instantiate($lc, $from); }
        }
    }

    /** `C::m()`: C's own `m` or the nearest ancestor's, plus that ancestor's late-static copy for C. */
    private function staticCall(string $class, string $method, string $from): void
    {
        $lc = \strtolower(\ltrim($class, '\\'));
        if ($lc === 'self' || $lc === 'static' || $lc === 'parent' || $lc === '') {
            // Called from a method: `static::m()` resolves in a subclass of that method's class, `self::` /
            // `parent::` in the class or an ancestor. From a closure or a function, any class may answer.
            $own = $lc === '' ? '' : ($this->classOfFn[$from] ?? '');
            if ($own === '') { $this->demandEverywhere($method, $from); }
            else { $this->demandInHierarchy($own, $method, $from); }
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
                    $copy = $this->classLc[$cur]->name . '__' . $this->methodSpelling[$cur][$lm] . '__lsb' . $this->classLc[$lc]->name;
                    if ($this->classLc[$cur]->isPreludeClass) { $this->hiddenFns[$copy] = true; }
                    $this->enqueue($copy, $from);
                }
                return;
            }
            $cur = \strtolower(\ltrim($this->classLc[$cur]->parent, '\\'));
        }
        // No body found up the chain (`__callStatic`, an abstract method reached through a subclass).
        $this->demandInHierarchy($lc, $method, $from);
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

    /** The strings `$e` can hold, from the locals of function `$from`; null = unknown. @return array<string, bool>|null */
    private function valueSetOf(Node $e, string $from): ?array
    {
        $k = $e->kind;
        if ($k !== Node::KIND_LOAD_LOCAL && $k !== Node::KIND_TERNARY && $k !== Node::KIND_MATCH && $k !== Node::KIND_CONCAT) { return null; }
        if (!isset($this->localSets[$from])) {
            if (!isset($this->fnDefs[$from])) { return null; }
            $this->localSets[$from] = LocalValueSets::of($this->fnDefs[$from], $this->fnDefs, $this->hasUnits);
        }
        return $this->localSets[$from]->valueSet($e);
    }

    /** Lowercase names of the user classes `$e` can name; null unless every string is a known class. @return string[]|null */
    private function classTargets(Node $e, string $from): ?array
    {
        $set = $this->valueSetOf($e, $from);
        if ($set === null) { return null; }
        $out = [];
        foreach ($set as $s => $_) {
            $lc = \strtolower(\ltrim((string)$s, '\\'));
            if (!isset($this->classLc[$lc])) { return null; }
            $out[] = $lc;
        }
        return $out;
    }

    /** Method names `$e` can hold; null unless every string is an identifier. @return string[]|null */
    private function nameTargets(Node $e, string $from): ?array
    {
        $set = $this->valueSetOf($e, $from);
        if ($set === null) { return null; }
        $out = [];
        foreach ($set as $s => $_) {
            if (!$this->isIdent((string)$s)) { return null; }
            $out[] = (string)$s;
        }
        return $out;
    }

    /** Function names / `'C::m'` strings `$e` can hold; null unless every one resolves to a module function or a known class. @return string[]|null */
    private function callableTargets(Node $e, string $from): ?array
    {
        $set = $this->valueSetOf($e, $from);
        if ($set === null) { return null; }
        $out = [];
        foreach ($set as $s => $_) {
            $str = (string)$s;
            if (isset($this->fnLc[\strtolower(\ltrim($str, '\\'))])) { $out[] = $str; continue; }
            $cc = \strpos($str, '::');
            if ($cc === false || $cc === 0 || !isset($this->classLc[\strtolower(\ltrim(\substr($str, 0, $cc), '\\'))])
                || !$this->isIdent(\substr($str, $cc + 2))) { return null; }
            $out[] = $str;
        }
        return $out;
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
    private function asNewDyn(Node $n): NewDynObj { return $n; }
    private function asDynProp(Node $n): DynProp_ { return $n; }

    private function walk(Node $root, string $from): void
    {
        /** @var Node[] $stack */
        $stack = [$root];
        while (\count($stack) > 0) {
            $n = \array_pop($stack);
            $k = $n->kind;
            if ($k === Node::KIND_CALL) {
                $f = $this->asCall($n)->function;
                if (!isset($this->fnNames[$f])) { $f = $this->fnLc[\strtolower($f)] ?? ''; }
                // A synthesized dispatcher (`__mc_uninit_by_name`, …) is a dynamic edge: the dynamic roots reach it.
                if ($this->widen || !$this->isDynamicHelper($f)) { $this->enqueue($f, $from); }
            } elseif ($k === Node::KIND_STRING_CONST) {
                $this->stringLiteral($this->asString($n)->value, $from);
            } elseif ($k === Node::KIND_CLOSURE) {
                $cl = '__closure_' . (string)$this->asClosure($n)->id;
                if (isset($this->hiddenFns[$from]) || $this->isSynthesized($from)) { $this->hiddenFns[$cl] = true; }
                $this->enqueue($cl, $from);
            } elseif ($k === Node::KIND_NEW_OBJ) {
                $this->instantiate(\strtolower(\ltrim($this->asNewObj($n)->class, '\\')), $from);
            } elseif ($k === Node::KIND_NEW_DYN_OBJ) {
                $cls = $this->classTargets($this->asNewDyn($n)->classExpr, $from);
                if ($cls === null) { $this->newDynamic($from); }
                else { foreach ($cls as $lc) { $this->instantiate($lc, $from); } }
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
                // Any other non-closure callee may be a runtime function NAME: the emitter dispatches it to every
                // function whose symbol has no `__` ({@see EmitLlvmCalls::dynfNameable}).
                // A callable array with a runtime method name (`$c::$m()`, `[$x, $name]`) may name any
                // instance method of a live object or any static method of any class.
                $callee = $this->asInvoke($n)->callee;
                $ck = $callee->kind;
                // A name or class that is a local holding only literals ({@see LocalValueSets}) is a static edge to those.
                if ($ck === Node::KIND_DYN_PROP) {
                    $names = $this->nameTargets($this->asDynProp($callee)->name, $from);
                    if ($names === null) { $this->reachAllMethods($from); }
                    else { foreach ($names as $nm) { $this->demand($nm, $from); } }
                } elseif ($ck === Node::KIND_ARRAY_LIT) {
                    $el = $this->asArrayLit($callee)->elements;
                    if (\count($el) !== 2 || $el[1]->value->kind !== Node::KIND_STRING_CONST) {
                        $names = \count($el) === 2 && $el[0]->key === null && $el[1]->key === null
                            ? $this->nameTargets($el[1]->value, $from) : null;
                        if ($names === null) {
                            $this->reachAllMethods($from);
                            $this->reachAllStatic($from);
                        } else {
                            $cls = $this->classTargets($el[0]->value, $from);
                            foreach ($names as $nm) {
                                $this->demand($nm, $from);
                                if ($cls === null) { $this->demandStatic($nm, $from); }
                                else { foreach ($cls as $lc) { $this->staticCall($lc, $nm, $from); } }
                            }
                        }
                    }
                } elseif ($ck !== Node::KIND_CLOSURE && $ck !== Node::KIND_STRING_CONST) {
                    $fnsNamed = $this->callableTargets($callee, $from);
                    if ($fnsNamed === null) { $this->reachNameableFns($from); }
                    else { foreach ($fnsNamed as $s) { $this->stringLiteral($s, $from); } }
                }
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
                        } else {
                            // The receiver may be a runtime CLASS name: a static `m` of any class.
                            $cls = $this->classTargets($el[0]->value, $from);
                            if ($cls === null) { $this->demandStatic($mn, $from); }
                            else { foreach ($cls as $lc) { $this->staticCall($lc, $mn, $from); } }
                        }
                    }
                }
            }
            foreach (Walk::children($n) as $c) { $stack[] = $c; }
        }
    }
}
