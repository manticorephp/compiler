<?php

// PHP's reserved (built-in) attributes.
//
// These live in the prelude rather than src/Runtime for two reasons:
//   - the stdlib `.o.sig` carries FUNCTIONS ONLY, so a class declared under
//     src/Runtime is invisible to a user program;
//   - the prelude is read as TEXT and parsed by Manticore's own parser, so
//     `class Attribute {}` here can never collide with Zend's built-in during
//     the cold-seed build (Zend never `require`s this file).
//
// The SEMANTICS (target validation, #[Override], #[Deprecated], #[NoDiscard])
// are enforced by the compiler from Compile\BuiltinAttributes — these
// declarations exist so reflection works: getAttributes()->newInstance().
// Keep the flags in the two places in agreement.
//
// Constant values verified against php 8.5.8. TARGET_CONSTANT (64) is new in
// 8.5, which is why TARGET_ALL is 127 and IS_REPEATABLE moved up to 128.

#[Attribute(Attribute::TARGET_CLASS)]
final class Attribute
{
    const TARGET_CLASS          = 1;
    const TARGET_FUNCTION       = 2;
    const TARGET_METHOD         = 4;
    const TARGET_PROPERTY       = 8;
    const TARGET_CLASS_CONSTANT = 16;
    const TARGET_PARAMETER      = 32;
    const TARGET_CONSTANT       = 64;
    const TARGET_ALL            = 127;
    const IS_REPEATABLE         = 128;

    // Default spelled as the literal, not `self::TARGET_ALL`: a class-constant
    // parameter default is not lowered on this branch.
    public function __construct(public int $flags = 127) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final class AllowDynamicProperties
{
    public function __construct() {}
}

#[Attribute(Attribute::TARGET_METHOD)]
final class ReturnTypeWillChange
{
    public function __construct() {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class SensitiveParameter
{
    public function __construct() {}
}

// Not an attribute: the stand-in a #[SensitiveParameter] value is replaced with
// in a stack trace. Manticore's traces carry no argument values at all
// (prelude/backtrace.php), so nothing leaks today and the attribute itself is
// inert — but the class is real and usable.
final class SensitiveParameterValue
{
    public function __construct(private readonly mixed $value) {}

    public function getValue(): mixed { return $this->value; }

    /** @return array<string,mixed> */
    public function __debugInfo(): array { return []; }
}

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
final class Override
{
    public function __construct() {}
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT | Attribute::TARGET_CONSTANT)]
final class Deprecated
{
    public function __construct(
        public readonly ?string $message = null,
        public readonly ?string $since = null,
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final class NoDiscard
{
    public function __construct(public readonly ?string $message = null) {}
}

// Defers this site's target/repeat validation from compile time to
// ReflectionAttribute::newInstance(). Declares no constructor, exactly as Zend.
#[Attribute(Attribute::TARGET_ALL)]
final class DelayedTargetValidation
{
}
