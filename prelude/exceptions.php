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

interface Throwable {}

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
