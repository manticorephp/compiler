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
 * A leading `\` is not part of the recorded name. Hand-rolled because the
 * PRELUDE MUST NOT CALL THE STDLIB: this file is compiled into every module,
 * including ones built with no lib/ beside them, and there `\ltrim` does not
 * error — it silently degrades to whatever global `ltrim` is in scope, or to an
 * undefined symbol that stops the compile of ANY program. `substr`/`strlen` are
 * codegen builtins and always present; `ltrim`/`strrpos` are stdlib and are not.
 * See the prelude/resource.php fix (eec7e94) — the suite, the fixpoint and
 * difftest all stay GREEN through this; only selfhost_stability catches it.
 */
function __mc_refl_unqualify(string $s): string
{
    if ($s === "") { return $s; }
    if (\substr($s, 0, 1) !== "\\") { return $s; }
    return \substr($s, 1);
}

/** Index of the last `\`, or -1. The stdlib's strrpos is unavailable here. */
function __mc_refl_last_sep(string $s): int
{
    $i = \strlen($s) - 1;
    while ($i >= 0) {
        if (\substr($s, $i, 1) === "\\") { return $i; }
        $i = $i - 1;
    }
    return -1;
}

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

/**
 * Build ReflectionAttribute[] off an attribute table (`$base` = its first entry,
 * `$n` = the count), optionally filtered to `$filter`. Shared by every
 * getAttributes(). A leading `\` on the filter is not part of the name.
 *
 * @return ReflectionAttribute[]
 */
function __mc_refl_attrs_of(int $base, int $n, ?string $filter): array
{
    /** @var ReflectionAttribute[] $out */
    $out = [];
    $want = $filter === null ? "" : __mc_refl_unqualify($filter);
    $i = 0;
    while ($i < $n) {
        $nm = __mc_refl_attr_name($base, $i);
        if ($want === "" || $nm === $want) {
            $out[] = new ReflectionAttribute(
                $nm, __mc_refl_attr_args($base, $i), __mc_refl_attr_new($base, $i));
        }
        $i = $i + 1;
    }
    return $out;
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
            $n = __mc_refl_unqualify($objectOrClass);
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
        $p = __mc_refl_last_sep($this->name);
        if ($p < 0) { return $this->name; }
        return \substr($this->name, $p + 1);
    }

    /** The namespace, or "" for a global class. */
    public function getNamespaceName(): string
    {
        $p = __mc_refl_last_sep($this->name);
        if ($p < 0) { return ""; }
        return \substr($this->name, 0, $p);
    }

    public function inNamespace(): bool
    {
        return __mc_refl_last_sep($this->name) >= 0;
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
        $want = __mc_refl_unqualify($name);
        $p = __mc_refl_parent($this->h);
        while ($p !== 0) {
            if (__mc_refl_name($p) === $want) { return true; }
            $p = __mc_refl_parent($p);
        }
        return false;
    }

    /**
     * A new instance, constructor arguments forwarded. Uses the class's
     * constructor trampoline (works even with no user `__construct`); throws for
     * an abstract class / interface, matching php.
     */
    public function newInstance(mixed ...$args): object
    {
        $t = __mc_refl_ctor($this->h);
        if ($t === 0) {
            throw new ReflectionException("Cannot instantiate " . $this->name);
        }
        return __mc_refl_invoke($t, 0, $args);
    }

    /**
     * A new instance, constructor arguments from an array. The `mixed[]` hint
     * boxes a concrete-element array to the `vec[cell]` the ctor trampoline
     * expects.
     *
     * @param mixed[] $args
     */
    public function newInstanceArgs(array $args = []): object
    {
        $t = __mc_refl_ctor($this->h);
        if ($t === 0) {
            throw new ReflectionException("Cannot instantiate " . $this->name);
        }
        return __mc_refl_invoke($t, 0, $args);
    }

    /** The constructor as a ReflectionMethod, or null when none is declared. */
    public function getConstructor(): ReflectionMethod|null
    {
        if (__mc_refl_member($this->h, "__construct", 1) === 0) { return null; }
        return new ReflectionMethod($this->name, "__construct");
    }

    /** A method by name; throws when the class has no such method. */
    public function getMethod(string $name): ReflectionMethod
    {
        if (__mc_refl_member($this->h, $name, 1) === 0) {
            throw new ReflectionException("Method " . $name . " does not exist");
        }
        return new ReflectionMethod($this->name, $name);
    }

    /**
     * Every method as a ReflectionMethod, in the method table's order (own →
     * trait → inherited — what php's getMethods() reports).
     * @return ReflectionMethod[]
     */
    public function getMethods(): array
    {
        $n = __mc_refl_nmethods($this->h);
        $base = __mc_refl_methods_base($this->h);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $out[] = new ReflectionMethod($this->name, __mc_refl_row_name($base, $i));
            $i = $i + 1;
        }
        return $out;
    }

    /**
     * Every declared property as a ReflectionProperty, in the property table's
     * order (inherited first, then own — what the metadata carries).
     * @return ReflectionProperty[]
     */
    public function getProperties(): array
    {
        $n = __mc_refl_nprops($this->h);
        $base = __mc_refl_props_base($this->h);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $out[] = new ReflectionProperty($this->name, __mc_refl_row_name($base, $i));
            $i = $i + 1;
        }
        return $out;
    }

    /** A property by name; throws when the class has no such property. */
    public function getProperty(string $name): ReflectionProperty
    {
        if (__mc_refl_member($this->h, $name, 0) === 0) {
            throw new ReflectionException("Property " . $name . " does not exist");
        }
        return new ReflectionProperty($this->name, $name);
    }

    /**
     * The class's attributes, optionally filtered to `$name`.
     * @return ReflectionAttribute[]
     */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return __mc_refl_attrs_of(
            __mc_refl_class_attrs($this->h),
            __mc_refl_class_nattrs($this->h), $name);
    }

    /**
     * Every constant as `name => value`, including inherited ones. Built by the
     * compiler-synthesized factory, which references each constant by its
     * qualified name so the value resolves exactly as user code would see it.
     * @return array<string, mixed>
     */
    public function getConstants(): array
    {
        $fn = __mc_refl_consts_fn($this->h);
        if ($fn === 0) { return []; }
        return __mc_refl_call0($fn);
    }

    /** One constant's value, or false when there is no such constant. */
    public function getConstant(string $name): mixed
    {
        $c = $this->getConstants();
        return $c[$name] ?? false;
    }

    public function hasConstant(string $name): bool
    {
        $c = $this->getConstants();
        return isset($c[$name]);
    }

    /**
     * Every constant as a ReflectionClassConstant.
     * @return ReflectionClassConstant[]
     */
    public function getReflectionConstants(): array
    {
        $out = [];
        foreach ($this->getConstants() as $name => $v) {
            $out[] = new ReflectionClassConstant($this->name, $name);
        }
        return $out;
    }

    public function getReflectionConstant(string $name): ReflectionClassConstant|false
    {
        if (!$this->hasConstant($name)) { return false; }
        return new ReflectionClassConstant($this->name, $name);
    }

    /**
     * The transitive names of every interface the class implements.
     * @return string[]
     */
    public function getInterfaceNames(): array
    {
        $fn = __mc_refl_ifaces_fn($this->h);
        if ($fn === 0) { return []; }
        return __mc_refl_call0($fn);
    }

    public function implementsInterface(string $name): bool
    {
        $want = __mc_refl_unqualify($name);
        foreach ($this->getInterfaceNames() as $i) {
            if (__mc_refl_unqualify($i) === $want) { return true; }
        }
        return false;
    }

    /** The rmeta address. Internal — the id of a class, for identity checks. */
    public function __handle(): int
    {
        return $this->h;
    }
}

/**
 * ReflectionObject — a ReflectionClass over an INSTANCE. Every query is
 * inherited. Declared AFTER ReflectionClass on purpose: property inheritance is
 * resolved at lowering only when the parent is already in the class table, so a
 * subclass must follow its parent in the file (else it inherits NO slots, its
 * instance is under-allocated, and the ctor writes overflow the heap). php's
 * constructor is object-only; this inherits object|string — harmlessly wider.
 */
class ReflectionObject extends ReflectionClass
{
}

/**
 * A method handle. Ф2: `invoke()` calls the method's uniform trampoline
 * indirectly. The `mixed ...$args` form packs a `vec[cell]` the trampoline
 * unboxes per parameter; `invokeArgs(array)` (an already-built array) waits on
 * call-site element boxing (Ф2b).
 */
class ReflectionMethod
{
    public string $name = "";
    public string $class = "";

    /** The owning class's rmeta handle, the method's trampoline (0 = not
     *  invokable), and its method-row address (for the parameter table). Raw
     *  addresses carried as ints — nothing retains them. */
    private int $h = 0;
    private int $tramp = 0;
    private int $row = 0;

    /** `new ReflectionMethod('Class', 'method')` or `(…, $obj, 'method')`. */
    public function __construct(object|string $objectOrClass, string $method)
    {
        if (\is_string($objectOrClass)) {
            $cls = __mc_refl_unqualify($objectOrClass);
            $h = __mc_refl_find($cls);
        } else {
            $h = __mc_refl_of($objectOrClass);
            $cls = __mc_refl_name($h);
        }
        if ($h === 0) {
            throw new ReflectionException("Class does not exist");
        }
        if (__mc_refl_member($h, $method, 1) === 0) {
            throw new ReflectionException("Method " . $method . " does not exist");
        }
        $this->h = $h;
        $this->class = $cls;
        $this->name = $method;
        $this->tramp = __mc_refl_tramp($h, $method);
        $this->row = __mc_refl_mrow($h, $method);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The method's parameters, in declaration order.
     * @return ReflectionParameter[]
     */
    public function getParameters(): array
    {
        $base = __mc_refl_row_params($this->row);
        $n = __mc_refl_row_nparams($this->row);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $out[] = new ReflectionParameter($base, $i);
            $i = $i + 1;
        }
        return $out;
    }

    public function getNumberOfParameters(): int
    {
        return __mc_refl_row_nparams($this->row);
    }

    /** Required = the low byte of the packed arity word. */
    public function getNumberOfRequiredParameters(): int
    {
        return __mc_refl_row_arity($this->row) & 255;
    }

    /**
     * Call the method. `$object` is the receiver for an instance method and is
     * ignored for a static one (php passes null there). A non-invokable method
     * (abstract / by-ref / variadic parameter — no trampoline) throws.
     */
    public function invoke(?object $object, mixed ...$args): mixed
    {
        if ($this->tramp === 0) {
            throw new ReflectionException("Method " . $this->name . " is not invokable");
        }
        return __mc_refl_invoke($this->tramp, $object, $args);
    }

    /**
     * Like invoke(), but the arguments arrive as an array. The `mixed[]` hint
     * makes the caller box a concrete-element array to `vec[cell]` (the repr the
     * trampoline's args param requires) — a plain `array` would pass raw slots.
     *
     * @param mixed[] $args
     */
    public function invokeArgs(?object $object, array $args): mixed
    {
        if ($this->tramp === 0) {
            throw new ReflectionException("Method " . $this->name . " is not invokable");
        }
        return __mc_refl_invoke($this->tramp, $object, $args);
    }

    private function flags(): int
    {
        return __mc_refl_member($this->h, $this->name, 1) - 1;
    }

    public function isStatic(): bool
    {
        return ($this->flags() & 4) !== 0;
    }

    public function isAbstract(): bool
    {
        return ($this->flags() & 8) !== 0;
    }

    public function isFinal(): bool
    {
        return ($this->flags() & 16) !== 0;
    }

    public function isPublic(): bool
    {
        return ($this->flags() & 3) === 0;
    }

    public function isPrivate(): bool
    {
        return ($this->flags() & 3) === 2;
    }

    public function isProtected(): bool
    {
        return ($this->flags() & 3) === 1;
    }

    /** php's modifier bitmask (IS_PUBLIC / … / IS_STATIC / IS_ABSTRACT / IS_FINAL)
     *  — a different encoding from the internal member-flags word. */
    public function getModifiers(): int
    {
        $f = $this->flags();
        $m = 0;
        $v = $f & 3;
        if ($v === 0) { $m = $m | 1; }
        if ($v === 1) { $m = $m | 2; }
        if ($v === 2) { $m = $m | 4; }
        if (($f & 4) !== 0)  { $m = $m | 16; }
        if (($f & 8) !== 0)  { $m = $m | 64; }
        if (($f & 16) !== 0) { $m = $m | 32; }
        return $m;
    }

    /** The class this method was reached through. */
    public function getDeclaringClass(): ReflectionClass
    {
        return new ReflectionClass($this->class);
    }

    public function hasReturnType(): bool
    {
        return __mc_refl_row_rettype($this->row) !== "";
    }

    /**
     * The declared return type as a ReflectionNamedType, or null when none. A
     * leading `?` is nullability; `self`/`static` resolve to the reached class
     * (best-effort — `parent` is left as written).
     */
    public function getReturnType(): ReflectionNamedType|null
    {
        $t = __mc_refl_row_rettype($this->row);
        if ($t === "") { return null; }
        $nullable = false;
        if (\substr($t, 0, 1) === "?") { $nullable = true; $t = \substr($t, 1); }
        $lc = \strtolower($t);
        if ($lc === "self" || $lc === "static") { $t = $this->class; }
        return new ReflectionNamedType($t, $nullable);
    }

    /**
     * The method's attributes, optionally filtered to `$name`.
     * @return ReflectionAttribute[]
     */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return __mc_refl_attrs_of(
            __mc_refl_row_attrs($this->row),
            __mc_refl_row_nattrs($this->row), $name);
    }
}

/**
 * A property handle. Tier-3: metadata (visibility / static / readonly / type)
 * off the class's property table, plus live getValue/setValue through the
 * synthesized `__mc_pget_/pset_` accessors (an object read/write lowered
 * normally, so a typed slot boxes/unboxes correctly — the same indirect-call
 * shape as ReflectionMethod::invoke).
 */
class ReflectionProperty
{
    public const IS_STATIC = 16;
    public const IS_READONLY = 128;
    public const IS_PUBLIC = 1;
    public const IS_PROTECTED = 2;
    public const IS_PRIVATE = 4;

    public string $name = "";
    public string $class = "";

    /** Owning class rmeta handle; the property row; the `{type,getter,setter}`
     *  extra base; the member flags; the accessor pointers. Raw addresses as
     *  ints — immortal rodata / code, nothing retains them. */
    private int $h = 0;
    private int $row = 0;
    private int $extra = 0;
    private int $flags = 0;
    private int $getter = 0;
    private int $setter = 0;

    /** `new ReflectionProperty('Class', 'prop')` or `(…, $obj, 'prop')`. */
    public function __construct(object|string $objectOrClass, string $property)
    {
        if (\is_string($objectOrClass)) {
            $cls = __mc_refl_unqualify($objectOrClass);
            $h = __mc_refl_find($cls);
        } else {
            $h = __mc_refl_of($objectOrClass);
            $cls = __mc_refl_name($h);
        }
        if ($h === 0) {
            throw new ReflectionException("Class does not exist");
        }
        $fl = __mc_refl_member($h, $property, 0);
        if ($fl === 0) {
            throw new ReflectionException("Property " . $property . " does not exist");
        }
        $this->h = $h;
        $this->class = $cls;
        $this->name = $property;
        $this->flags = $fl - 1;
        $this->row = __mc_refl_prow($h, $property);
        $this->extra = __mc_refl_row_params($this->row);
        $this->getter = __mc_refl_prop_getter($this->extra);
        $this->setter = __mc_refl_prop_setter($this->extra);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function hasType(): bool
    {
        return __mc_refl_prow_type($this->extra) !== "";
    }

    /** The declared type as a ReflectionNamedType, or null when untyped. A
     *  leading `?` is nullability, not part of the name. */
    public function getType(): ReflectionNamedType|null
    {
        $t = __mc_refl_prow_type($this->extra);
        if ($t === "") { return null; }
        $nullable = false;
        if (\substr($t, 0, 1) === "?") { $nullable = true; $t = \substr($t, 1); }
        return new ReflectionNamedType($t, $nullable);
    }

    public function isPublic(): bool
    {
        return ($this->flags & 3) === 0;
    }

    public function isProtected(): bool
    {
        return ($this->flags & 3) === 1;
    }

    public function isPrivate(): bool
    {
        return ($this->flags & 3) === 2;
    }

    public function isStatic(): bool
    {
        return ($this->flags & 4) !== 0;
    }

    public function isReadonly(): bool
    {
        return ($this->flags & 32) !== 0;
    }

    /** php's modifier bitmask (IS_PUBLIC / … / IS_STATIC / IS_READONLY) — a
     *  different encoding from the internal member-flags word. */
    public function getModifiers(): int
    {
        $m = 0;
        $v = $this->flags & 3;
        if ($v === 0) { $m = $m | 1; }
        if ($v === 1) { $m = $m | 2; }
        if ($v === 2) { $m = $m | 4; }
        if (($this->flags & 4) !== 0) { $m = $m | 16; }
        if (($this->flags & 32) !== 0) { $m = $m | 128; }
        return $m;
    }

    public function getDeclaringClass(): ReflectionClass
    {
        return new ReflectionClass($this->class);
    }

    /**
     * The property's value on `$object` (null / omitted for a static property,
     * whose accessor ignores the receiver). Reads through the synthesized
     * getter, so a typed slot comes back boxed.
     */
    public function getValue(?object $object = null): mixed
    {
        if ($this->getter === 0) {
            throw new ReflectionException("Cannot read property " . $this->name);
        }
        return __mc_refl_call1($this->getter, $object);
    }

    /**
     * Set the property on `$object`. For a static property the receiver is
     * ignored — pass null. A readonly property outside its declaring scope is a
     * fatal error, as php's own setValue reports.
     */
    public function setValue(?object $object, mixed $value): void
    {
        if ($this->setter === 0) {
            throw new ReflectionException("Cannot write property " . $this->name);
        }
        __mc_refl_prop_set($this->setter, $object, $value);
    }

    /**
     * The property's attributes, optionally filtered to `$name`.
     * @return ReflectionAttribute[]
     */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return __mc_refl_attrs_of(
            __mc_refl_row_attrs($this->row),
            __mc_refl_row_nattrs($this->row), $name);
    }
}

/**
 * One parameter of a method. Reads a `{ name, type, flags }` entry off the
 * method's parameter table by index — the raw base address + position carried
 * as ints. Ф2d: the shape a DI container walks to autowire a constructor.
 */
class ReflectionParameter
{
    public string $name = "";

    private int $base = 0;
    private int $pos = 0;
    private int $flags = 0;

    public function __construct(int $base, int $pos)
    {
        $this->base = $base;
        $this->pos = $pos;
        $this->name = __mc_refl_param_name($base, $pos);
        $this->flags = __mc_refl_param_flags($base, $pos);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): int
    {
        return $this->pos;
    }

    public function hasType(): bool
    {
        return ($this->flags & 16) !== 0;
    }

    /** The declared type as a ReflectionNamedType, or null when untyped. */
    public function getType(): ReflectionNamedType|null
    {
        if (($this->flags & 16) === 0) { return null; }
        $t = __mc_refl_param_type($this->base, $this->pos);
        return new ReflectionNamedType($t, ($this->flags & 2) !== 0);
    }

    public function isOptional(): bool
    {
        return ($this->flags & 1) !== 0;
    }

    public function isDefaultValueAvailable(): bool
    {
        return ($this->flags & 1) !== 0;
    }

    public function isVariadic(): bool
    {
        return ($this->flags & 4) !== 0;
    }

    public function isPromoted(): bool
    {
        return ($this->flags & 8) !== 0;
    }

    public function allowsNull(): bool
    {
        return ($this->flags & 2) !== 0;
    }
}

/**
 * A single named type (`int`, `?App\Foo`). Unions/intersections are Ф3. The
 * name is stored WITHOUT a leading `?`; nullability is a separate flag.
 */
class ReflectionNamedType
{
    private string $name = "";
    private bool $nullable = false;

    public function __construct(string $name, bool $nullable)
    {
        $this->name = $name;
        $this->nullable = $nullable;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function allowsNull(): bool
    {
        return $this->nullable;
    }

    /** A built-in (scalar / pseudo) type rather than a class name. */
    public function isBuiltin(): bool
    {
        $n = \strtolower($this->name);
        if ($n === "int" || $n === "float" || $n === "string" || $n === "bool") { return true; }
        if ($n === "array" || $n === "object" || $n === "callable" || $n === "iterable") { return true; }
        if ($n === "mixed" || $n === "void" || $n === "null" || $n === "never") { return true; }
        if ($n === "false" || $n === "true" || $n === "self" || $n === "static" || $n === "parent") { return true; }
        return false;
    }

    public function __toString(): string
    {
        $n = \strtolower($this->name);
        if ($this->nullable && $n !== "mixed" && $n !== "null") {
            return "?" . $this->name;
        }
        return $this->name;
    }
}

/**
 * One attribute occurrence (Ф4). getName is the attribute-class name;
 * getArguments and newInstance call the compiler-synthesized factories that
 * reconstruct the argument array / a fresh instance — the arguments were lowered
 * as ordinary expressions, so arrays / enum cases / constants all come back.
 */
class ReflectionAttribute
{
    private string $name = "";
    private int $argsFn = 0;
    private int $newFn = 0;

    public function __construct(string $name, int $argsFn, int $newFn)
    {
        $this->name = $name;
        $this->argsFn = $argsFn;
        $this->newFn = $newFn;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** The attribute's arguments: positional (int keys) then named (string keys).
     *  @return array */
    public function getArguments(): array
    {
        if ($this->argsFn === 0) { return []; }
        return __mc_refl_call0($this->argsFn);
    }

    /** A fresh instance of the attribute class, its arguments applied. */
    public function newInstance(): object
    {
        if ($this->newFn === 0) {
            throw new ReflectionException("Attribute " . $this->name . " is not instantiable");
        }
        return __mc_refl_call0($this->newFn);
    }
}

/**
 * A free function (Ф5). Reads a metadata ROW the compiler emitted per function a
 * `new ReflectionFunction('f')` names literally, resolved through a name→row
 * registry (the function twin of the class registry). invoke() calls the
 * function's uniform trampoline — the static-method invoke shape with the
 * receiver ignored.
 */
class ReflectionFunction
{
    public string $name = "";

    private int $row = 0;
    private int $tramp = 0;

    public function __construct(string $name)
    {
        $n = __mc_refl_unqualify($name);
        $h = __mc_refl_fn_find($n);
        if ($h === 0) {
            throw new ReflectionException("Function " . $n . "() does not exist");
        }
        $this->row = $h;
        $this->name = $n;
        $this->tramp = __mc_refl_row_tramp($h);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getShortName(): string
    {
        $p = __mc_refl_last_sep($this->name);
        if ($p < 0) { return $this->name; }
        return \substr($this->name, $p + 1);
    }

    public function getNamespaceName(): string
    {
        $p = __mc_refl_last_sep($this->name);
        if ($p < 0) { return ""; }
        return \substr($this->name, 0, $p);
    }

    public function inNamespace(): bool
    {
        return __mc_refl_last_sep($this->name) >= 0;
    }

    /** @return ReflectionParameter[] */
    public function getParameters(): array
    {
        $base = __mc_refl_row_params($this->row);
        $n = __mc_refl_row_nparams($this->row);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $out[] = new ReflectionParameter($base, $i);
            $i = $i + 1;
        }
        return $out;
    }

    public function getNumberOfParameters(): int
    {
        return __mc_refl_row_nparams($this->row);
    }

    public function getNumberOfRequiredParameters(): int
    {
        return __mc_refl_row_arity($this->row) & 255;
    }

    public function hasReturnType(): bool
    {
        return __mc_refl_row_rettype($this->row) !== "";
    }

    public function getReturnType(): ReflectionNamedType|null
    {
        $t = __mc_refl_row_rettype($this->row);
        if ($t === "") { return null; }
        $nullable = false;
        if (\substr($t, 0, 1) === "?") { $nullable = true; $t = \substr($t, 1); }
        return new ReflectionNamedType($t, $nullable);
    }

    public function invoke(mixed ...$args): mixed
    {
        if ($this->tramp === 0) {
            throw new ReflectionException("Function " . $this->name . " is not invokable");
        }
        return __mc_refl_invoke($this->tramp, 0, $args);
    }

    /** @param mixed[] $args */
    public function invokeArgs(array $args): mixed
    {
        if ($this->tramp === 0) {
            throw new ReflectionException("Function " . $this->name . " is not invokable");
        }
        return __mc_refl_invoke($this->tramp, 0, $args);
    }
}

/**
 * One class constant. getName / getValue / getDeclaringClass are exact; the
 * value comes from the same class-constants factory getConstants() reads.
 * Visibility is not captured in the metadata yet, so every constant reports
 * public (a known gap, not a silent wrong answer for the common getValue path).
 */
class ReflectionClassConstant
{
    public string $name = "";
    public string $class = "";

    public function __construct(object|string $class, string $constant)
    {
        $rc = new ReflectionClass($class);
        $this->class = $rc->getName();
        $this->name = $constant;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The constant's value, fetched fresh each call. NOT cached in a property:
     * an array-valued constant assigned to a property from a local `getConstants()`
     * result is freed when that local drops (an array-element-ownership hazard);
     * returning it directly keeps it retained for the caller.
     */
    public function getValue(): mixed
    {
        $consts = (new ReflectionClass($this->class))->getConstants();
        return $consts[$this->name] ?? null;
    }

    public function getDeclaringClass(): ReflectionClass
    {
        return new ReflectionClass($this->class);
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function isProtected(): bool
    {
        return false;
    }

    public function isPrivate(): bool
    {
        return false;
    }

    public function isFinal(): bool
    {
        return false;
    }

    public function getModifiers(): int
    {
        return 1;
    }
}
