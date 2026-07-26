<?php

// Reserved-attribute violations. Every one of these is a compile fatal in Zend;
// under `analyze` they are collected rather than aborting the run.

class Base
{
    protected function present(): void {}
    protected string $kept = 'x';
}

final class Child extends Base
{
    #[\Override]
    protected function present(): void {}

    #[\Override]
    protected function absent(): void {}

    #[\Override]
    protected string $gone = 'y';
}

trait Mixin
{
    public function mixed_in(): void {}
}

class UsesTrait
{
    use Mixin;

    // A trait used by THIS class does not satisfy #[\Override].
    #[\Override]
    public function mixed_in(): void {}
}

#[\Override]
class BadTarget {}

#[\Deprecated]
class DeprecatedClass {}

#[\NoDiscard]
#[\NoDiscard]
function repeated(): int { return 1; }

enum Suit: string
{
    #[\Override]
    case Hearts = 'H';
}

class HasConst
{
    #[\Override]
    const NOPE = 1;
}

#[\Override]
const TOP_LEVEL = 7;

function withParam(#[\Override] int $x): int { return $x; }

// Suppressed: validation moves to ReflectionAttribute::newInstance().
#[\Override]
#[\DelayedTargetValidation]
class Delayed {}
