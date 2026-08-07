<?php

/**
 * The built-in Throwable hierarchy — injected into EVERY program (see Main.php
 * gating / LowerFromAst::$exceptionsSrc) and lowered like any user class, so
 * `throw` / `catch` / `getMessage()` resolve through the normal class machinery.
 *
 * Each Throwable carries the message/code/previous, the thrown location
 * (`line`/`file`) and the captured call stack (`traceNames`/`traceLines`, filled
 * at `new` by EmitLlvm when the program queries a trace).
 *
 * `__mir_bt_frames` turns that captured stack into PHP-shaped frames. It comes
 * from `backtrace.php` for a program that queries a trace, and from the
 * `backtrace_stub.php` one-liner for one that does not — the assoc-frame builder
 * is heavy, and a program that never calls getTrace() should not carry it.
 */

/**
 * php's Throwable DECLARES the accessor set, and code typed against the
 * interface (`function (\Throwable $e) { $e->getMessage(); }` — every
 * set_exception_handler) resolves through it. An empty marker made that an
 * "unknown method" error.
 */
interface Throwable
{
    public function getMessage(): string;
    public function getCode(): int;
    public function getPrevious(): ?Throwable;
    public function getFile(): string;
    public function getLine(): int;
    public function getTrace(): array;
    public function getTraceAsString(): string;
}

class Exception implements Throwable
{
    public string $message;
    public int $code;
    public ?Throwable $previous;
    public int $line = 0;
    public string $file = "";
    /** @var string[] */ public array $traceNames = [];
    /** @var int[] */ public array $traceLines = [];

    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        $this->message = $message;
        $this->code = $code;
        $this->previous = $previous;
    }

    public function getMessage(): string { return $this->message; }
    public function getCode(): int { return $this->code; }
    public function getPrevious(): ?Throwable { return $this->previous; }
    public function getLine(): int { return $this->line; }
    public function getFile(): string { return $this->file; }
    public function getTrace(): array { return __mir_bt_frames($this->traceNames, $this->traceLines, $this->file); }

    public function getTraceAsString(): string
    {
        $s = "";
        $n = \count($this->traceNames);
        $i = 0;
        while ($i < $n) {
            $s = $s . "#" . $i . " " . $this->file . "(" . $this->traceLines[$i] . "): " . $this->traceNames[$i] . "()\n";
            $i = $i + 1;
        }
        return $s . "#" . $n . " {main}";
    }
}

class Error implements Throwable
{
    public string $message;
    public int $code;
    public ?Throwable $previous;
    public int $line = 0;
    public string $file = "";
    /** @var string[] */ public array $traceNames = [];
    /** @var int[] */ public array $traceLines = [];

    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        $this->message = $message;
        $this->code = $code;
        $this->previous = $previous;
    }

    public function getMessage(): string { return $this->message; }
    public function getCode(): int { return $this->code; }
    public function getPrevious(): ?Throwable { return $this->previous; }
    public function getLine(): int { return $this->line; }
    public function getFile(): string { return $this->file; }
    public function getTrace(): array { return __mir_bt_frames($this->traceNames, $this->traceLines, $this->file); }

    public function getTraceAsString(): string
    {
        $s = "";
        $n = \count($this->traceNames);
        $i = 0;
        while ($i < $n) {
            $s = $s . "#" . $i . " " . $this->file . "(" . $this->traceLines[$i] . "): " . $this->traceNames[$i] . "()\n";
            $i = $i + 1;
        }
        return $s . "#" . $n . " {main}";
    }
}

class RuntimeException extends Exception {}
class LogicException extends Exception {}
class InvalidArgumentException extends LogicException {}
class OutOfRangeException extends LogicException {}
class TypeError extends Error {}
class ValueError extends Error {}
class AssertionError extends Error {}

// The rest of SPL's exception tree, with php's exact parentage — `catch
// (LogicException)` has to catch a BadMethodCallException, and `catch
// (RuntimeException)` an OutOfBoundsException, or a handler silently stops
// handling. Every symfony and doctrine package throws from this set:
// BadMethodCallException from an unimplemented interface method,
// OutOfBoundsException from a container miss, UnexpectedValueException from a
// failed assertion about a value's shape.
//
// Declared here rather than in src/Runtime/Stdlib because the stdlib `.sig`
// carries FUNCTIONS ONLY — a class declared there is never registered by a user
// program, so `instanceof` and `catch` would read false in user code while the
// stdlib's own throw sites saw it. Same reason Resource lives in the prelude.
class BadFunctionCallException extends LogicException {}
class BadMethodCallException extends BadFunctionCallException {}
class DomainException extends LogicException {}
class LengthException extends LogicException {}
class OutOfBoundsException extends RuntimeException {}
class OverflowException extends RuntimeException {}
class RangeException extends RuntimeException {}
class UnderflowException extends RuntimeException {}
class UnexpectedValueException extends RuntimeException {}

// json_encode/json_decode with JSON_THROW_ON_ERROR. Extends Exception, not
// RuntimeException — php's own hierarchy, and code that catches
// RuntimeException around a json call must NOT swallow it.
class JsonException extends Exception {}

/**
 * `assert($cond, $description)` — php CLI ships zend.assertions=1, so the
 * assertion is EVALUATED and a falsy result throws AssertionError. (The
 * `zend.assertions=-1` production mode, where the call compiles away entirely,
 * has no equivalent here: there is no php.ini.) A string description becomes
 * the message; a Throwable description is thrown as-is, exactly as php does.
 */
function assert(mixed $assertion, mixed $description = null): bool
{
    if ($assertion) { return true; }
    if ($description instanceof Throwable) { throw $description; }
    if (is_string($description) && $description !== '') { throw new AssertionError($description); }
    throw new AssertionError('assert(false)');
}
