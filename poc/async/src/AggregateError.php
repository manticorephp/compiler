<?php

namespace Async;

/**
 * Bundles several errors raised by sibling tasks into one throwable. Raised by
 * {@see awaitAll()} (collect-all: every failure is reported) and {@see awaitAny()}
 * (Promise.any: only when every task failed). The `previous` slot points at the
 * first error so a plain `getPrevious()` still gives one representative cause;
 * {@see errors()} exposes the full map keyed by the failing task's input position.
 */
final class AggregateError extends \RuntimeException
{
    /** @param array<int|string, \Throwable> $errors keyed by input position */
    public function __construct(string $message, private array $errors)
    {
        $first = null;
        foreach ($errors as $e) { $first = $e; break; }
        parent::__construct($message, 0, $first);
    }

    /** @return array<int|string, \Throwable> */
    public function errors(): array
    {
        return $this->errors;
    }
}
