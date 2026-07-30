<?php

/**
 * proc_open / proc_close / proc_get_status / proc_terminate.
 *
 * TODO(proc-open): a real implementation needs process-launch primitives the
 * runtime does not expose yet — pipe(2), posix_spawn(3) (+ file_actions for the
 * stdio dup2s), waitpid(2), kill(2) — plus fdopen(3) to wrap each child pipe fd
 * as a \Resource so the f* / stream_* family reads it unchanged. Until then a
 * launch reports failure (php's "could not start a process"), which is enough
 * for symfony/console to LOAD (its `function_exists('proc_open')` module guard
 * passes) and for any command that does not actually spawn a subprocess to run.
 * A command that DOES will get a RuntimeException at start(), matching php with
 * proc_open disabled rather than a silent miscompile.
 */

use Manticore\Attr\RefOut;

/**
 * @param array<int, mixed> $descriptor_spec
 * @param array<int, mixed> $pipes
 * @param array<string, string>|null $env
 * @param array<string, mixed>|null $options
 */
function proc_open(
    string $command,
    array $descriptor_spec,
    #[RefOut] array &$pipes = [],
    ?string $cwd = null,
    ?array $env = null,
    ?array $options = null,
): mixed {
    $pipes = [];
    return false;
}

function proc_close(mixed $process): int
{
    return -1;
}

/** @return array<string, mixed> */
function proc_get_status(mixed $process): array
{
    return [
        'command' => '',
        'pid' => 0,
        'running' => false,
        'signaled' => false,
        'stopped' => false,
        'exitcode' => -1,
        'termsig' => 0,
        'stopsig' => 0,
    ];
}

function proc_terminate(mixed $process, int $signal = 15): bool
{
    return false;
}

function proc_nice(int $priority): bool
{
    return false;
}
