// Error / exception handlers, the shutdown queue, and `trigger_error`.
// DEMAND-GATED (Main.php): only a program that calls one of these carries it.
//
// This file is the one place where Manticore raises a Zend-shaped diagnostic
// instead of throwing. The project rule — a condition Zend warns about becomes
// an EXCEPTION here — governs diagnostics the runtime raises on its own. It
// cannot govern `trigger_error`, which is the program deliberately raising one:
// symfony/deprecation-contracts calls `@trigger_error(..., E_USER_DEPRECATED)`
// on every deprecation, and throwing there would break the application. So an
// EXPLICIT diagnostic behaves exactly as Zend's does — routed to the current
// handler, filtered by error_reporting(), silenced by `@`, and otherwise
// printed in php's own wording.
//
// The printed line matches php's CLI with display_errors=STDOUT and
// html_errors=Off — "\n<Level>: <msg> in <file> on line <N>\n" on fd 1 — the
// same shape (and the same stream) the #[Deprecated] diagnostics already use.
// A compiled binary has no error_log, so the second, stderr-side "PHP Warning:"
// line php's default log_errors emits has no counterpart here.
//
// Handlers are CALLABLES, which is why this lives in the prelude and not in
// src/Runtime/Stdlib: a callable cannot cross the stdlib.o boundary.

class __McErrors
{
    /** @var array<int,mixed> the set_error_handler stack, innermost last */
    public static array $errorHandlers = [];

    /** @var array<int,mixed> the set_exception_handler stack, innermost last */
    public static array $exceptionHandlers = [];

    /** @var array<int,mixed> registered shutdown callables, in registration order */
    public static array $shutdown = [];

    /** @var array<int,mixed> the args each shutdown callable was registered with */
    public static array $shutdownArgs = [];

    /** @var array<string,mixed> php's error_get_last() record, empty when none */
    public static array $lastError = [];

    /** Current error_reporting() mask. php's default is E_ALL. */
    public static int $reporting = 30719;

    /** True once the shutdown queue has run, so it can never run twice. */
    public static bool $shutdownRan = false;
}

/** php's wording for an error level, as it appears in the printed line. */
function __mc_err_label(int $level): string
{
    if ($level === 1 || $level === 16 || $level === 64 || $level === 256) { return "Fatal error"; }
    if ($level === 2 || $level === 32 || $level === 128 || $level === 512) { return "Warning"; }
    if ($level === 4) { return "Parse error"; }
    if ($level === 8192 || $level === 16384) { return "Deprecated"; }
    if ($level === 2048) { return "Strict Standards"; }
    if ($level === 4096) { return "Recoverable fatal error"; }
    return "Notice";
}

/**
 * Invoke any callable shape with the argument list an error handler takes.
 * A `[$obj, 'method']` array callable cannot be invoked as a value, so the
 * shapes are split here — the one place that needs it.
 */
function __mc_call_error_handler(mixed $cb, int $level, string $message, string $file, int $line): mixed
{
    if (\is_array($cb)) {
        $o = $cb[0];
        $m = $cb[1];
        return $o->$m($level, $message, $file, $line);
    }
    return $cb($level, $message, $file, $line);
}

/** Invoke any callable shape with a single Throwable argument. */
function __mc_call_exception_handler(mixed $cb, mixed $e): mixed
{
    if (\is_array($cb)) {
        $o = $cb[0];
        $m = $cb[1];
        return $o->$m($e);
    }
    return $cb($e);
}

/** Invoke an array callable with the args it was registered with. */
function __mc_call_shutdown_array(mixed $cb, array $args): mixed
{
    $o = $cb[0];
    $m = $cb[1];
    return $o->$m(...$args);
}

/** Invoke a non-array callable with the args it was registered with. */
function __mc_call_shutdown_function(mixed $cb, array $args): mixed
{
    return $cb(...$args);
}

/** Invoke any callable shape with the args it was registered with.
 *  @param mixed[] $args */
function __mc_call_shutdown(mixed $cb, array $args): mixed
{
    if (\is_array($cb)) {
        return __mc_call_shutdown_array($cb, $args);
    }
    return __mc_call_shutdown_function($cb, $args);
}

/**
 * Install an error handler; returns the one it replaces (null if none).
 * The `$levels` mask is accepted and ignored for filtering, matching how every
 * real caller uses it — symfony passes E_ALL and filters inside the handler.
 */
function set_error_handler(mixed $callback, int $error_levels = 30719): mixed
{
    $n = \count(__McErrors::$errorHandlers);
    $prev = $n > 0 ? __McErrors::$errorHandlers[$n - 1] : null;
    if ($callback === null) {
        // php's documented "restore the built-in handler" spelling: push a
        // marker so a later restore_error_handler() still pops one level.
        __McErrors::$errorHandlers[] = null;
        return $prev;
    }
    __McErrors::$errorHandlers[] = $callback;
    return $prev;
}

/** Pop one error handler. True even when the stack is empty, as php does. */
function restore_error_handler(): bool
{
    if (\count(__McErrors::$errorHandlers) > 0) {
        \array_pop(__McErrors::$errorHandlers);
    }
    return true;
}

/** Install an uncaught-exception handler; returns the one it replaces. */
function set_exception_handler(mixed $callback): mixed
{
    $n = \count(__McErrors::$exceptionHandlers);
    $prev = $n > 0 ? __McErrors::$exceptionHandlers[$n - 1] : null;
    __McErrors::$exceptionHandlers[] = $callback;
    return $prev;
}

/** Pop one exception handler. */
function restore_exception_handler(): bool
{
    if (\count(__McErrors::$exceptionHandlers) > 0) {
        \array_pop(__McErrors::$exceptionHandlers);
    }
    return true;
}

/**
 * Queue a callable to run when the program ends — on a normal return, on
 * exit(), and after an uncaught exception, exactly as php does. The queue is
 * driven from an `atexit` trampoline the compiler registers in main(), so all
 * three paths are covered by construction.
 */
function register_shutdown_function(mixed $callback, mixed ...$args): bool
{
    // php's signature is (callable, mixed ...$args) and the extra arguments are
    // handed to the callback at shutdown. They used to be dropped on the floor,
    // so a callback that declared a parameter read an unbound slot and printed
    // whatever was there — `register_shutdown_function($f, 'arg')` produced
    // "shutdown 2 \x0co\xd8\xf1..." instead of "shutdown 2 arg".
    __McErrors::$shutdown[] = $callback;
    __McErrors::$shutdownArgs[] = $args;
    return true;
}

/** Drain the shutdown queue. Called from the compiler-emitted atexit hook. */
function __mc_run_shutdown(): void
{
    if (__McErrors::$shutdownRan) { return; }
    __McErrors::$shutdownRan = true;
    $n = \count(__McErrors::$shutdown);
    $i = 0;
    while ($i < $n) {
        $cb = __McErrors::$shutdown[$i];
        // Parallel arrays rather than a queue of [cb, args] pairs: a nested
        // array<int, mixed[]> is a known self-host miscompile hazard, and the
        // two are only ever appended together.
        $args = $i < \count(__McErrors::$shutdownArgs) ? __McErrors::$shutdownArgs[$i] : [];
        if ($cb !== null) { __mc_call_shutdown($cb, $args); }
        $i = $i + 1;
        // A shutdown function may register another; php runs those too.
        $n = \count(__McErrors::$shutdown);
    }
}

/**
 * Hand an uncaught Throwable to the user handler. Returns true when one ran —
 * the compiler's uncaught path then exits QUIETLY (255) instead of printing its
 * own "PHP Fatal error: Uncaught …", which is php's behaviour too.
 *
 * The handler stack is cleared first: a handler that throws must not re-enter
 * itself, and php likewise does not consult the handler for an exception thrown
 * inside it.
 */
function __mc_dispatch_uncaught(mixed $e): bool
{
    $n = \count(__McErrors::$exceptionHandlers);
    if ($n === 0) { return false; }
    $cb = __McErrors::$exceptionHandlers[$n - 1];
    __McErrors::$exceptionHandlers = [];
    if ($cb === null) { return false; }
    __mc_call_exception_handler($cb, $e);
    return true;
}

/** Read or set the error_reporting mask. */
function error_reporting(mixed $error_level = null): int
{
    $old = __McErrors::$reporting;
    if ($error_level !== null) {
        __McErrors::$reporting = (int)$error_level;
    }
    return $old;
}

/** php's error_get_last(): the last diagnostic raised, or null. */
function error_get_last(): ?array
{
    if (\count(__McErrors::$lastError) === 0) { return null; }
    return __McErrors::$lastError;
}

/**
 * The real `trigger_error`. `$file` / `$line` are filled in by the lowering
 * from the CALL SITE's span (a prelude function cannot see its caller's
 * position), and `$silenced` is 1 when the call was written `@trigger_error(…)`.
 *
 * Order matches Zend: record last-error, offer it to the handler, and only if
 * no handler consumed it apply error_reporting / `@` and print.
 */
function __mc_trigger_error(string $message, int $level, string $file, int $line, int $silenced): bool
{
    if ($level !== 256 && $level !== 512 && $level !== 1024 && $level !== 16384) {
        // php restricts trigger_error to the E_USER_* family.
        $level = 1024;
    }
    __McErrors::$lastError = [
        "type" => $level,
        "message" => $message,
        "file" => $file,
        "line" => $line,
    ];
    $n = \count(__McErrors::$errorHandlers);
    if ($n > 0) {
        $cb = __McErrors::$errorHandlers[$n - 1];
        if ($cb !== null) {
            // A handler runs even under `@` — php only zeroes error_reporting
            // for its duration, and symfony's handlers depend on being called.
            $r = __mc_call_error_handler($cb, $level, $message, $file, $line);
            if ($r !== false) {
                if ($level === 256) { exit(255); }
                return true;
            }
        }
    }
    if ($silenced === 0 && (__McErrors::$reporting & $level) !== 0) {
        echo "\n", __mc_err_label($level), ": ", $message, " in ", $file, " on line ", $line, "\n";
    }
    if ($level === 256) { exit(255); }
    return true;
}
