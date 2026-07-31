// Output buffering — the `ob_*` family.
// DEMAND-GATED (Main.php): only a program that calls one of these carries it.
//
// The split with the codegen runtime is forced, not stylistic. The BYTES live
// in C ({@see EmitLlvmRuntime::obApiRuntime}) because `echo` inside the prebuilt
// lib/manticore_stdlib.o — readfile(), fpassthru(), vprintf() — was lowered to a
// direct call to @__mir_out_write at stdlib-build time, and only a shared
// linkonce_odr global can be seen from both objects. The HANDLERS live here
// because they are callables, and a callable does not survive the stdlib.o
// boundary (the same reason __McErrors is a prelude class).
//
// The two halves share nothing but an integer depth: __mir_ob_level() is the
// single source of truth, and every function below pairs its C op with the
// matching array op inside one body, so no other code can observe a skew.
//
// Not implemented, deliberately: $chunk_size auto-flush. That would need the
// funnel to call back into PHP, and the funnel's body has to be renderable from
// codegen alone — a direct call present in the user module and absent in
// stdlib.o is exactly the ODR violation that once gave __mir_rc_release two
// bodies and freed objects at refcount 5. The argument is accepted, stored, and
// reported by ob_get_status(); it just never fires.

class __McOb
{
    /** @var array<int,mixed> handler per level, innermost last; null = none */
    public static array $handlers = [];

    /** @var array<int,mixed> chunk_size per level (accepted, never fires) */
    public static array $chunks = [];

    /** @var array<int,mixed> flags per level */
    public static array $flags = [];

    /** @var array<int,mixed> handler name per level, for ob_list_handlers() */
    public static array $names = [];

    /**
     * @var array<int,mixed> per level: has this buffer's START been consumed?
     *
     * php ORs PHP_OUTPUT_HANDLER_START (1) into the phase of the first handler
     * call for a buffer — so a first ob_end_flush() sees 9 (START|FINAL) and one
     * after an earlier ob_flush() sees 8. ob_clean() consumes START too, without
     * ever calling the handler. All three verified against the oracle.
     */
    public static array $started = [];

    /** @var array<int,array<int,string>> parked buffer CONTENTS, innermost
     *  first, by task id. {@see __mc_ob_ctx_switch} */
    public static array $savedBufs = [];

    /** @var array<int,array<int,mixed>> parked $handlers, by task id */
    public static array $savedHandlers = [];

    /** @var array<int,array<int,mixed>> parked $chunks, by task id */
    public static array $savedChunks = [];

    /** @var array<int,array<int,mixed>> parked $flags, by task id */
    public static array $savedFlags = [];

    /** @var array<int,array<int,mixed>> parked $names, by task id */
    public static array $savedNames = [];

    /** @var array<int,array<int,mixed>> parked $started, by task id */
    public static array $savedStarted = [];

    /** The reset value for a metadata column. A property rather than a `[]`
     *  literal for the reason {@see \__McSapi::$empty} documents: an empty
     *  literal types its element `unknown`, and the appends that follow would
     *  then write raw values under readers that decode cells.
     *  @var array<int,mixed> */
    public static array<int, mixed> $emptyMeta = [];

    /** @var array<int,string> the reset value for a parked-contents list */
    public static array<int, string> $emptyBufs = [];
}

/**
 * Park the calling flow's whole output-buffer stack and restore $to's.
 *
 * `@__mir_ob_depth` and `@__mir_ob_stack` are PROCESS-global linkonce_odr
 * (EmitLlvmRuntime), not per-fiber — they have to be, because `echo` inside the
 * prebuilt stdlib.o was lowered against them at stdlib-build time. Two
 * concurrent request handlers with open buffers therefore share one stack, and
 * fiber A's `echo` lands in fiber B's buffer. Everything needed to keep them
 * apart is already a PHP-callable builtin, so this is plain PHP and costs
 * codegen nothing.
 *
 * `__mir_ob_take` is a MOVE — the level is emptied and we own the string — so
 * parking cannot alias a buffer that is still live. Restoring re-pushes
 * OUTERMOST first and writes each contents back into the level just pushed,
 * which is what `__mir_out_write_str` targets.
 *
 * Called from `__mc_sapi_ctx_switch`, guarded by function_exists, so a program
 * that never buffers never links it.
 */
function __mc_ob_ctx_switch(int $from, int $to): void
{
    if ($from === $to) {
        return;
    }
    if (\__mir_ob_level() > 0) {
        $bufs = __McOb::$emptyBufs;
        while (true) {
            $l = \__mir_ob_level();
            if ($l === 0) {
                break;
            }
            $bufs[] = \__mir_ob_take($l);
            \__mir_ob_pop();
        }
        __McOb::$savedBufs[$from] = $bufs;
        __McOb::$savedHandlers[$from] = __McOb::$handlers;
        __McOb::$savedChunks[$from] = __McOb::$chunks;
        __McOb::$savedFlags[$from] = __McOb::$flags;
        __McOb::$savedNames[$from] = __McOb::$names;
        __McOb::$savedStarted[$from] = __McOb::$started;
        __McOb::$handlers = __McOb::$emptyMeta;
        __McOb::$chunks = __McOb::$emptyMeta;
        __McOb::$flags = __McOb::$emptyMeta;
        __McOb::$names = __McOb::$emptyMeta;
        __McOb::$started = __McOb::$emptyMeta;
    }
    if (!isset(__McOb::$savedBufs[$to])) {
        return;
    }
    $bufs = __McOb::$savedBufs[$to];
    __McOb::$handlers = __McOb::$savedHandlers[$to];
    __McOb::$chunks = __McOb::$savedChunks[$to];
    __McOb::$flags = __McOb::$savedFlags[$to];
    __McOb::$names = __McOb::$savedNames[$to];
    __McOb::$started = __McOb::$savedStarted[$to];
    unset(__McOb::$savedBufs[$to]);
    unset(__McOb::$savedHandlers[$to]);
    unset(__McOb::$savedChunks[$to]);
    unset(__McOb::$savedFlags[$to]);
    unset(__McOb::$savedNames[$to]);
    unset(__McOb::$savedStarted[$to]);
    // $bufs is innermost-first; push in the other order so each level is
    // recreated under the one that enclosed it.
    $n = \count($bufs);
    for ($i = $n - 1; $i >= 0; $i = $i - 1) {
        \__mir_ob_push();
        \__mir_out_write_str($bufs[$i]);
    }
}

/** php's name for a handler, as ob_list_handlers()/ob_get_status() report it. */
function __mc_ob_handler_name(mixed $cb): string
{
    if ($cb === null) { return "default output handler"; }
    if (\is_string($cb)) { return $cb; }
    if (\is_array($cb)) {
        $o = $cb[0];
        $m = $cb[1];
        if (\is_string($o)) { return $o . "::" . $m; }
        return \get_class($o) . "::" . $m;
    }
    return "Closure::__invoke";
}

/**
 * Invoke a handler with php's ($buffer, $phase) argument list. A `[$obj,
 * 'method']` array callable cannot be invoked as a value, so the shapes are
 * split here — the same split {@see __mc_call_error_handler} makes.
 *
 * php leaves the buffer untouched when a handler returns false.
 *
 * ⚠ Output a handler produces itself is DISCARDED, which is php's rule and not
 * an obvious one — it is neither forwarded downstream nor folded back into the
 * buffer being handled. The flag is cleared in a `finally` because a handler
 * that throws would otherwise leave the process mute for good.
 */
function __mc_ob_call(mixed $cb, string $buf, int $phase): string
{
    $r = false;
    \__mir_ob_incb(1);
    try {
        if (\is_array($cb)) {
            $o = $cb[0];
            $m = $cb[1];
            $r = $o->$m($buf, $phase);
        } else {
            $r = $cb($buf, $phase);
        }
    } finally {
        \__mir_ob_incb(0);
    }
    if ($r === false) { return $buf; }
    return (string)$r;
}

/** Drop the metadata row for the level that was just popped. */
function __mc_ob_drop_meta(): void
{
    \array_pop(__McOb::$handlers);
    \array_pop(__McOb::$chunks);
    \array_pop(__McOb::$flags);
    \array_pop(__McOb::$names);
    \array_pop(__McOb::$started);
}

/** The phase for a handler call on level $l, consuming that level's START bit. */
function __mc_ob_phase(int $l, int $kind): int
{
    $p = $kind;
    if (!__McOb::$started[$l - 1]) { $p = $p + 1; }
    __McOb::$started[$l - 1] = true;
    return $p;
}

function ob_start(mixed $callback = null, int $chunk_size = 0, int $flags = 112): bool
{
    if (\__mir_ob_push() === 0) { return false; }
    __McOb::$handlers[] = $callback;
    __McOb::$chunks[] = $chunk_size;
    __McOb::$flags[] = $flags;
    __McOb::$names[] = \__mc_ob_handler_name($callback);
    __McOb::$started[] = false;
    return true;
}

function ob_get_level(): int
{
    return \__mir_ob_level();
}

function ob_get_length(): int|false
{
    $l = \__mir_ob_level();
    if ($l === 0) { return false; }
    return \__mir_ob_len($l);
}

function ob_get_contents(): string|false
{
    $l = \__mir_ob_level();
    if ($l === 0) { return false; }
    return \__mir_ob_peek($l);
}

function ob_clean(): bool
{
    $l = \__mir_ob_level();
    if ($l === 0) { return false; }
    \__mir_ob_clean($l);
    // No handler call, but the START bit is consumed anyway — php's rule, and
    // the reason a flush after an ob_clean() reports 8 rather than 9.
    __McOb::$started[$l - 1] = true;
    return true;
}

function ob_get_clean(): string|false
{
    $l = \__mir_ob_level();
    if ($l === 0) { return false; }
    $s = \__mir_ob_take($l);
    \__mir_ob_pop();
    \__mc_ob_drop_meta();
    return $s;
}

function ob_end_clean(): bool
{
    $l = \__mir_ob_level();
    if ($l === 0) { return false; }
    \__mir_ob_clean($l);
    \__mir_ob_pop();
    \__mc_ob_drop_meta();
    return true;
}

/**
 * Pop LAST, not first. The level stays open across the handler call because php
 * keeps it open: a handler that asks ob_get_level() sees ITS OWN level, not the
 * one below (verified against the oracle). Marking it in use is what lets the
 * handler's RESULT still travel downstream past a level that is technically
 * still on the stack. Phase 8 is PHP_OUTPUT_HANDLER_FINAL.
 */
function ob_end_flush(): bool
{
    $l = \__mir_ob_level();
    if ($l === 0) { return false; }
    $s = \__mir_ob_take($l);
    $cb = __McOb::$handlers[$l - 1];
    \__mir_ob_inuse($l, 1);
    if ($cb !== null) { $s = \__mc_ob_call($cb, $s, \__mc_ob_phase($l, 8)); }
    \__mir_out_write_str($s);
    \__mir_ob_inuse($l, 0);
    \__mir_ob_pop();
    \__mc_ob_drop_meta();
    return true;
}

/** Like ob_end_flush(), but answers the buffer's ORIGINAL contents. */
function ob_get_flush(): string|false
{
    $l = \__mir_ob_level();
    if ($l === 0) { return false; }
    $s = \__mir_ob_take($l);
    $cb = __McOb::$handlers[$l - 1];
    \__mir_ob_inuse($l, 1);
    $out = $s;
    if ($cb !== null) { $out = \__mc_ob_call($cb, $s, \__mc_ob_phase($l, 8)); }
    \__mir_out_write_str($out);
    \__mir_ob_inuse($l, 0);
    \__mir_ob_pop();
    \__mc_ob_drop_meta();
    return $s;
}

/**
 * The level SURVIVES here, so popping is not an option — instead the level is
 * marked in use, which makes the funnel's target walk skip past it and the
 * flushed bytes reach the ENCLOSING buffer (or stdout), never the level they
 * came from. Phase 4 is PHP_OUTPUT_HANDLER_FLUSH; whatever the handler echoes
 * on its own is dropped by {@see __mc_ob_call}, not routed anywhere.
 */
function ob_flush(): bool
{
    $l = \__mir_ob_level();
    if ($l === 0) { return false; }
    $s = \__mir_ob_take($l);
    $cb = __McOb::$handlers[$l - 1];
    \__mir_ob_inuse($l, 1);
    if ($cb !== null) { $s = \__mc_ob_call($cb, $s, \__mc_ob_phase($l, 4)); }
    \__mir_out_write_str($s);
    \__mir_ob_inuse($l, 0);
    return true;
}

function ob_implicit_flush(bool $enable = true): bool
{
    \__mir_ob_implicit($enable ? 1 : 0);
    return true;
}

/** @return array<int,string> outermost first, as php orders it */
function ob_list_handlers(): array
{
    $out = [];
    $n = \count(__McOb::$names);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $out[] = (string)__McOb::$names[$i];
    }
    return $out;
}

/**
 * @return array<string,mixed> one ob_get_status() row for 0-based level $i
 *
 * `buffer_size` is php's ALLOCATION granule, not the bytes in the buffer:
 * a default (chunk 0) buffer reports 16384, and any explicit chunk rounds up to
 * a multiple of 4096 — 100 becomes 4096, 8192 stays 8192. Only `buffer_used` is
 * the real length. `flags` carries bit 0 for a user handler, which is why the
 * default handler reports 112 and a named one 113.
 */
function __mc_ob_status_row(int $i): array
{
    $chunk = (int)__McOb::$chunks[$i];
    $size = 16384;
    if ($chunk > 0) {
        $size = (int)((($chunk + 4095) / 4096)) * 4096;
    }
    $isUser = __McOb::$handlers[$i] === null ? 0 : 1;
    return [
        "name" => (string)__McOb::$names[$i],
        "type" => $isUser,
        "flags" => (int)__McOb::$flags[$i] | $isUser,
        "level" => $i,
        "chunk_size" => $chunk,
        "buffer_size" => $size,
        "buffer_used" => \__mir_ob_len($i + 1),
    ];
}

/** @return array<mixed,mixed> */
function ob_get_status(bool $full_status = false): array
{
    $n = \count(__McOb::$names);
    if ($full_status) {
        $rows = [];
        for ($i = 0; $i < $n; $i = $i + 1) {
            $rows[] = \__mc_ob_status_row($i);
        }
        return $rows;
    }
    if ($n === 0) { return []; }
    return \__mc_ob_status_row($n - 1);
}

/**
 * php flushes every buffer still open at shutdown, handlers and all. Registered
 * with atexit BEFORE the shutdown-function queue so it runs AFTER it (atexit is
 * LIFO) — a register_shutdown_function() that echoes still lands in the open
 * buffer, which is php's order.
 */
function __mc_ob_shutdown(): int
{
    while (\__mir_ob_level() > 0) {
        \ob_end_flush();
    }
    return 0;
}
