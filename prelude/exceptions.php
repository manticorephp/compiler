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

/**
 * The shape read's throw: a docblock `array{…}` claimed field `$where` holds
 * a `$expected`, the buffer's word says otherwise. Called from the IR the
 * shape check emits ({@see \Compile\Mir\Passes\EmitLlvmArrays}), never from
 * PHP source — it lives here because this file is linked into every module.
 */
function __mir_shape_type_error(mixed $v, string $where, string $expected): void
{
    throw new TypeError($where . ' must be of type ' . $expected . ', ' . get_debug_type($v) . ' given');
}

/**
 * A string offset that arrived as a CELL or a STRING → the byte offset php
 * uses. Called from the IR ({@see \Compile\Mir\Passes\EmitLlvmArrays::
 * coerceStrOffset}), never from PHP source. An integer string (surrounding
 * whitespace allowed) is that int; an integer followed by other bytes (`"1x"`)
 * is its leading int, as php reads it; a float form, an overflow or no leading
 * integer at all is php's TypeError. A non-string casts (null 0, bool, float
 * truncates).
 */
function __mir_str_offset(mixed $k): int
{
    if (!\is_string($k)) { return (int)$k; }
    if (__mir_str_offset_form($k) === 2) {
        throw new TypeError('Cannot access offset of type string on string');
    }
    return (int)$k;
}

/**
 * `isset($s[$k])`'s key: the offset, or PHP_INT_MIN — out of range for every
 * string — when php's isset answers false whatever the length: any string
 * that is not a plain integer string.
 */
function __mir_str_offset_isset_key(mixed $k): int
{
    if (!\is_string($k)) { return (int)$k; }
    if (__mir_str_offset_form($k) !== 0) { return \PHP_INT_MIN; }
    return (int)$k;
}

/**
 * php's `is_numeric_string_ex` verdict on a string offset: 0 = an integer
 * string, 1 = an integer followed by other bytes, 2 = anything else (a float
 * form such as `1.`, `.5`, `1e5`, an int overflow, no leading integer).
 */
function __mir_str_offset_form(string $k): int
{
    $n = \strlen($k);
    $i = 0;
    while ($i < $n && __mir_str_offset_ws($k[$i])) { $i++; }
    $neg = false;
    if ($i < $n && ($k[$i] === '+' || $k[$i] === '-')) { $neg = $k[$i] === '-'; $i++; }
    while ($i < $n && $k[$i] === '0') { $i++; }
    $start = $i;
    while ($i < $n && __mir_str_offset_digit($k[$i])) { $i++; }
    $digits = $i - $start;
    $hadZero = $start > 0 && $k[$start - 1] === '0';
    if ($digits === 0 && !$hadZero) { return 2; }
    if ($digits > 19) { return 2; }
    if ($digits === 19) {
        $cmp = \strcmp(\substr($k, $start, 19), '9223372036854775808');
        if ($cmp > 0 || ($cmp === 0 && !$neg)) { return 2; }
    }
    if ($i < $n && $k[$i] === '.') { return 2; }
    if ($i < $n && ($k[$i] === 'e' || $k[$i] === 'E')) {
        $j = $i + 1;
        if ($j < $n && ($k[$j] === '+' || $k[$j] === '-')) { $j++; }
        if ($j < $n && __mir_str_offset_digit($k[$j])) { return 2; }
    }
    while ($i < $n && __mir_str_offset_ws($k[$i])) { $i++; }
    return $i === $n ? 0 : 1;
}

function __mir_str_offset_ws(string $c): bool
{
    $o = \ord($c);
    return $o === 32 || ($o >= 9 && $o <= 13);
}

function __mir_str_offset_digit(string $c): bool
{
    $o = \ord($c);
    return $o >= 48 && $o <= 57;
}

class ArgumentCountError extends TypeError {}
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
