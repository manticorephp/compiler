<?php

namespace Compile\Mir;

/**
 * Declared shape of one method, kept alongside {@see ClassDef::$methodNames}.
 *
 * `methodNames` is `array<string, true>` — it answers "does this exist" and
 * nothing else, which is why `is_callable('C::m')` cannot tell a static method
 * from an instance one (see EmitLlvmBuiltins::emitIsCallable). This carries the
 * declaration the AST already had and lowering used to drop.
 *
 * A typed object rather than a nested assoc: an assoc-of-assoc erases to
 * KIND_UNKNOWN under the self-host and a missing key reads back as `false`, so
 * every consumer would have to re-guard. Fields are never null for the same
 * reason — absent reads as `''`.
 */
final class MethodMeta
{
    /**
     * @param ParamMeta[] $params  in declaration order
     * @param string[]    $attributes  attribute names, verbatim as written
     */
    public function __construct(
        public string $name,
        public string $visibility = 'public',
        public bool $isStatic = false,
        public bool $isAbstract = false,
        public bool $isFinal = false,
        /** The return hint AS WRITTEN — `self` stays `self`. PHP resolves it
         *  (`ReflectionMethod::getReturnType()` on `static function make(): self`
         *  in class Dog reports `Dog`), so a ReflectionNamedType consumer must
         *  resolve self/static/parent against {@see $declaringClass}. Kept raw
         *  here because only the declaration knows it was written relatively. */
        public string $returnType = '',
        public array $params = [],
        public array $attributes = [],
        /** The class that actually declared it — a trait mix-in or an inherited
         *  method names its origin, not the using/inheriting class. */
        public string $declaringClass = '',
    ) {}

    /** Params without a default — what an arity check must supply. */
    public function requiredParams(): int
    {
        $n = 0;
        foreach ($this->params as $p) {
            if ($p->hasDefault || $p->variadic) { break; }
            $n = $n + 1;
        }
        return $n;
    }
}
