<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * Which classes need reflection metadata — the opt-in gate.
 *
 * Reflection data is not free: every class carries an rmeta block, a name
 * string, method/property tables and a startup constructor that links it into
 * the registry. A program that reflects two classes should pay for two, not for
 * every class it happens to declare. Go makes the same trade (its linker drops
 * the type data nothing reflects on).
 *
 * ── The rule ──
 * This is an OPTIMIZATION and must never change an answer. So it fails OPEN:
 * anything this pass cannot resolve statically turns on {@see $all}, and every
 * class gets metadata exactly as before. Being wrong here must cost binary size,
 * never correctness. That is why the escape cases below are deliberately blunt.
 *
 * ── Why the closure pulls parents ──
 * `getParentClass()` resolves the parent BY NAME through the runtime registry,
 * and `isSubclassOf()` walks that chain. If `Dog` were reflectable but `Animal`
 * were not, `find("Animal")` would return 0 and `getParentClass()` would answer
 * `false` at a class that plainly has a parent — a wrong answer produced by an
 * optimization. So a root drags its whole ancestry in.
 *
 * ── Where this must run ──
 * At the USER's call sites, never inside `prelude/reflection.php`. There, the
 * constructor's argument is a PARAMETER, so a literal never appears and every
 * program would escape to {@see $all} — the gate would never fire once.
 */
final class ReflectAnalysis
{
    public const NAME = 'reflect-analysis';

    /** Every class needs metadata: something reflected on a name this pass
     *  could not resolve. */
    public bool $all = false;

    /** @var array<string, bool> class names that need metadata */
    public array $names = [];

    public function name(): string { return self::NAME; }

    /** @return string[] */
    public function requires(): array { return []; }

    public function run(Module $module): void
    {
        foreach ($module->functions as $fn) {
            // A prelude body is the reflection implementation itself; its
            // `__mc_refl_find($name)` is a parameter by construction and would
            // escape every program to `all`. The demand comes from user code.
            if ($fn->isPrelude) { continue; }
            $this->scan($fn->body);
        }
        if ($this->all) { return; }
        $this->close($module);
    }

    /** Is `$name` reflectable? Always true once anything escaped. */
    public function wants(string $name): bool
    {
        if ($this->all) { return true; }
        return isset($this->names[$name]);
    }

    /**
     * Fields are read through an `instanceof`-narrowed local, never off the
     * base `Node`. A base-Node field read resolves the WRONG OFFSET under the
     * native self-build — Node declares none of these — and is invisible under
     * Zend, which resolves by name. Same family as the `emitDynMethodCall`
     * callee bug.
     */
    private function scan(Node $n): void
    {
        if ($n instanceof \Compile\Mir\NewObj) {
            $cls = \ltrim($n->class, '\\');
            if ($cls === 'ReflectionClass' || $cls === 'ReflectionObject') {
                $args = $n->args;
                if (\count($args) === 1) {
                    $this->fromArg($args[0]);
                } else {
                    // No argument to reason about — assume the worst.
                    $this->all = true;
                }
            }
            // ReflectionMethod / ReflectionProperty take (class-or-object,
            // member): the CLASS whose metadata they read is the FIRST argument,
            // exactly like ReflectionClass's operand.
            if ($cls === 'ReflectionMethod' || $cls === 'ReflectionProperty') {
                if (\count($n->args) >= 1) {
                    $this->fromArg($n->args[0]);
                } else {
                    $this->all = true;
                }
            }
        }
        if ($n instanceof \Compile\Mir\Call) {
            $fname = \ltrim($n->function, '\\');
            if ($fname === '__mc_refl_find' && \count($n->args) >= 1) {
                $this->fromArg($n->args[0]);
            }
            if ($fname === '__mc_refl_of' && \count($n->args) >= 1) {
                $this->fromReceiver($n->args[0]);
            }
            // `class_exists($runtimeName)` and friends are answered from the
            // registry, so a non-literal name means EVERY class must be in it.
            // Without this the gate would not shrink the binary, it would
            // manufacture a wrong answer — the one thing it must never do.
            // Enumerating the class table means EVERY class must be in it.
            if ($fname === 'get_declared_classes' || $fname === 'get_declared_interfaces'
                || $fname === 'get_declared_traits') {
                $this->all = true;
            }
            if ($fname === 'class_exists' || $fname === 'interface_exists'
                || $fname === 'trait_exists' || $fname === 'enum_exists') {
                if (\count($n->args) >= 1 && !($n->args[0] instanceof \Compile\Mir\StringConst)) {
                    $this->all = true;
                }
            }
        }
        foreach (Walk::children($n) as $c) {
            $this->scan($c);
        }
    }

    /** A `new ReflectionClass(<arg>)` / `__mc_refl_find(<arg>)` operand. */
    private function fromArg(Node $a): void
    {
        if ($a instanceof \Compile\Mir\StringConst) {
            $this->names[\ltrim($a->value, '\\')] = true;
            return;
        }
        if ($a->type->kind === Type::KIND_OBJ) {
            $this->fromReceiver($a);
            return;
        }
        // A computed name. Nothing static to resolve, so everything stays
        // reflectable — the case the registry exists for.
        $this->all = true;
    }

    /** An OBJECT operand: its class, and — since the value may hold any
     *  subclass at runtime — every descendant of it. */
    private function fromReceiver(Node $a): void
    {
        $cls = $a->type->class ?? '';
        if ($cls === '') { $this->all = true; return; }
        $this->names[\ltrim($cls, '\\')] = true;
        $this->descendants = true;
    }

    /** Set when a receiver's runtime class may be a subclass of its static
     *  type, so {@see close} must pull descendants too. */
    private bool $descendants = false;

    /**
     * Grow the root set to what reflection can actually reach: ancestors
     * (getParentClass / isSubclassOf walk them by name), and descendants of any
     * object-typed root (the value may hold a subclass).
     */
    private function close(Module $module): void
    {
        foreach ($module->classes as $name => $cd) {
            if (!isset($this->names[$name])) { continue; }
            $p = $cd->parent;
            while ($p !== '' && isset($module->classes[$p])) {
                if (isset($this->names[$p])) { break; }
                $this->names[$p] = true;
                $p = $module->classes[$p]->parent;
            }
        }
        if (!$this->descendants) { return; }
        // A root reached through an object: any subclass could be the runtime
        // class, so each needs its own block. Repeat until nothing new appears —
        // a descendant's own ancestry is already covered above.
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($module->classes as $name => $cd) {
                if (isset($this->names[$name])) { continue; }
                $p = $cd->parent;
                while ($p !== '') {
                    if (isset($this->names[$p])) {
                        $this->names[$name] = true;
                        $changed = true;
                        break;
                    }
                    $pc = $module->classes[$p] ?? null;
                    if ($pc === null) { break; }
                    $p = $pc->parent;
                }
            }
        }
    }
}
