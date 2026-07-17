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
