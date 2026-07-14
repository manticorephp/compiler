<?php

/**
 * The `__mir_bt_frames` a program gets when it never queries a trace: the bare
 * captured names, no assoc frames. Same name and shape as the real builder in
 * `backtrace.php`, so `Throwable::getTrace()` in `exceptions.php` needs no
 * variant of its own — exactly one of the two files is ever injected.
 *
 * Nothing captures the stack in this mode either (EmitLlvm only pushes frames
 * when the module needs a backtrace), so `$names` is empty.
 *
 * @param string[] $names
 * @param int[] $lines
 * @return string[]
 */
function __mir_bt_frames(array $names, array $lines, string $file): array
{
    return $names;
}
