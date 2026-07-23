<?php

namespace Async;

/**
 * The ambient async context: the current scope, plus cooperative cancellation.
 * A spike keeps it thin — deadlines and scoped values come later. Cancellation is
 * COOPERATIVE (our fibers are not preemptible): a task observes it only at a
 * suspend point via {@see throwIfCancelled()}.
 */
final class Context
{
    /** The innermost open scope, or null outside {@see run()}. */
    public static function currentScope(): ?TaskGroup
    {
        return Scheduler::instance()->currentGroup();
    }

    /** True once the current scope (or an ancestor) has been cancelled. */
    public static function isCancelled(): bool
    {
        $group = self::currentScope();
        while ($group !== null) {
            if ($group->cancelled) { return true; }
            $group = $group->parent;
        }
        return false;
    }

    /** Cancellation checkpoint — call at loop heads in long tasks. */
    public static function throwIfCancelled(): void
    {
        if (self::isCancelled()) {
            throw new CancelledException('task cancelled');
        }
    }
}

final class CancelledException extends \RuntimeException {}
