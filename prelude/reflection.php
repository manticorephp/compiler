<?php

/**
 * ReflectionClass — the Zend-shaped surface over the compiler's rtype metadata.
 *
 * This file lives OUTSIDE `src/` on purpose (like `spl_arrays.php`): `src/` is
 * compiled into the compiler binary and `src/Runtime` into stdlib.o, so a class
 * here would be double-defined. The compiler READS this at compile time and
 * parses it as guest source. It is also why reflection can be a CLASS at all —
 * the `.sig` has no schema for one, so a stdlib class is invisible to user code.
 *
 * Injected only when a program MENTIONS a Reflection* name (see Main.php's
 * PreludeDemand gate), the same way ArrayIterator is: a program that never
 * reflects must not carry any of this.
 *
 * ── Why this is generic, data-walking code ──
 * Every method here reads a metadata BLOCK the compiler emitted; none of them
 * enumerates a class table. That is not a style choice. A prelude body is
 * emitted `linkonce_odr` and coalesced to ONE copy across every separately
 * linked object, so a body built from the module-local class table would be a
 * different body in each object under a single symbol — the linker keeps one,
 * and it is wrong for the other. (`__mir_dump_object` on main is exactly that
 * bug, latent only because one module emits it.) So: the compiler emits DATA
 * per class, and this walks it — Go's split, where `reflect` is ordinary code
 * and `rtype` is compiler-emitted data.
 *
 * ── ODR discipline ──
 * Params and returns are `string` / `int` / `bool` / `object` only — never an
 * element-typed array. InferScans still refines prelude BY-VALUE params
 * (InferScans.php:576-583, an acknowledged hazard), so an element-typed
 * `array` param or return would let two modules compile two different bodies
 * under one symbol. Keep it that way until that is fixed at the root.
 *
 * The handle is a raw rmeta address held as an int — the `Ffi\Ptr::$address`
 * idiom. It points at immortal rodata, so nothing retains, releases or drops it.
 */

/**
 * Every registered name whose flags match `$want` under `$mask`.
 *
 * Walks the index table's slots (empty ones read 0). The registry is the runtime
 * CLASS TABLE, so this is where get_declared_* get their answer — there is no
 * other source: an interface has no ClassDef to enumerate at compile time.
 *
 * SORTED, unlike php. Two divergences are unavoidable and one is worth
 * controlling:
 *  - php's list also carries ~200 INTERNAL classes; ours carries the prelude's
 *    plus the program's. The sets simply differ, so exact parity is unreachable
 *    (the same conclusion the resource epic reached about resource ids).
 *  - our order is the index's hash order, and global_ctors order across
 *    separately-linked objects is unspecified anyway. php's is declaration
 *    order. Since we cannot match it, sorting at least makes OUR answer
 *    deterministic instead of arbitrary — a caller can diff two runs.
 * A test must therefore filter to its own classes and sort; it must never print
 * this list raw.
 *
 * @return string[]
 */
function __mc_declared(int $mask, int $want): array
{
    /** @var string[] $out */
    $out = [];
    $cap = __mc_refl_cap();
    for ($i = 0; $i < $cap; $i = $i + 1) {
        $h = __mc_refl_slot($i);
        if ($h === 0) { continue; }
        if ((__mc_refl_flags($h) & $mask) !== $want) { continue; }
        $out[] = __mc_refl_name($h);
    }
    // Insertion sort, rather than calling \sort(). A prelude file must not
    // depend on another INDEPENDENTLY GATED one: array_fns.php (which defines
    // sort) is included only when the USER program calls one of its functions,
    // but this file is lowered wholesale the moment anything mentions
    // ReflectionClass — so `\sort()` here emitted a call to a symbol that was
    // not there, and an undefined symbol does not fail this link, it stubs to
    // `return 0`. Forcing array_fns in would drag all of it into every
    // reflecting program instead. The list is one entry per class; n² is fine.
    $n = \count($out);
    for ($i = 1; $i < $n; $i = $i + 1) {
        $v = $out[$i];
        $j = $i - 1;
        while ($j >= 0 && $out[$j] > $v) {
            $out[$j + 1] = $out[$j];
            $j = $j - 1;
        }
        $out[$j + 1] = $v;
    }
    return $out;
}

/** @return string[] */
function get_declared_classes(): array
{
    // A class is anything registered that is neither an interface nor a trait —
    // enums included, exactly as php has it (class_exists('E') is true).
    return __mc_declared(4 | 16, 0);
}

/** @return string[] */
function get_declared_interfaces(): array
{
    return __mc_declared(4, 4);
}

/** @return string[] */
function get_declared_traits(): array
{
    return __mc_declared(16, 16);
}

class ReflectionException extends Exception {}

class ReflectionClass
{
    /** PHP exposes the class name as a public property, not just getName(). */
    public string $name = "";

    /** rmeta address. 0 is unreachable: the constructor throws instead. */
    private int $h = 0;

    /**
     * `new ReflectionClass('Foo')` or `new ReflectionClass($obj)`.
     *
     * A string goes through the runtime registry; an object reads its own
     * descriptor, which needs no lookup at all.
     */
    public function __construct(object|string $objectOrClass)
    {
        if (\is_string($objectOrClass)) {
            // A leading `\` is not part of the name the compiler recorded.
            $n = \ltrim($objectOrClass, "\\");
            $h = __mc_refl_find($n);
            if ($h === 0) {
                throw new ReflectionException("Class \"" . $n . "\" does not exist");
            }
        } else {
            $h = __mc_refl_of($objectOrClass);
            if ($h === 0) {
                throw new ReflectionException("Class does not exist");
            }
        }
        $this->h = $h;
        $this->name = __mc_refl_name($h);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** The name without its namespace. */
    public function getShortName(): string
    {
        $p = \strrpos($this->name, "\\");
        if ($p === false) { return $this->name; }
        return \substr($this->name, $p + 1);
    }

    /** The namespace, or "" for a global class. */
    public function getNamespaceName(): string
    {
        $p = \strrpos($this->name, "\\");
        if ($p === false) { return ""; }
        return \substr($this->name, 0, $p);
    }

    public function inNamespace(): bool
    {
        return \strrpos($this->name, "\\") !== false;
    }

    public function isFinal(): bool
    {
        return (__mc_refl_flags($this->h) & 1) !== 0;
    }

    public function isAbstract(): bool
    {
        return (__mc_refl_flags($this->h) & 2) !== 0;
    }

    public function isInterface(): bool
    {
        return (__mc_refl_flags($this->h) & 4) !== 0;
    }

    public function isEnum(): bool
    {
        return (__mc_refl_flags($this->h) & 8) !== 0;
    }

    /** Instantiable = a concrete class. Interfaces and abstracts are not. */
    public function isInstantiable(): bool
    {
        $f = __mc_refl_flags($this->h);
        return ($f & 2) === 0 && ($f & 4) === 0;
    }

    public function hasMethod(string $name): bool
    {
        return __mc_refl_member($this->h, $name, 1) !== 0;
    }

    public function hasProperty(string $name): bool
    {
        return __mc_refl_member($this->h, $name, 0) !== 0;
    }

    /**
     * The parent as a ReflectionClass, or false at the root — php's return
     * shape, which is why this is not `?ReflectionClass`.
     */
    public function getParentClass(): ReflectionClass|false
    {
        $p = __mc_refl_parent($this->h);
        if ($p === 0) { return false; }
        return new ReflectionClass(__mc_refl_name($p));
    }

    /** Does this class extend `$name`, at any depth? */
    public function isSubclassOf(string $name): bool
    {
        $want = \ltrim($name, "\\");
        $p = __mc_refl_parent($this->h);
        while ($p !== 0) {
            if (__mc_refl_name($p) === $want) { return true; }
            $p = __mc_refl_parent($p);
        }
        return false;
    }

    /** The rmeta address. Internal — the id of a class, for identity checks. */
    public function __handle(): int
    {
        return $this->h;
    }
}
