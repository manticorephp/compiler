<?php
// \Fiber — stackful coroutines. Oracle = Zend Fiber (php 8.1+). The switch is an
// fcontext primitive emitted as `module asm`; this class is the thin PHP layer
// over the __mir_fiber_* intrinsics. Value passing (suspend<->resume) rides the
// object's fields, not the asm. DEMAND-GATED (Main.php): compiled only when a
// program mentions Fiber, so the fiber-free compiler build never emits the asm.
//
// The process-global bump arena (cur + a mark stack) and the exception try-slot
// stack are shared, so a fiber that suspends mid-scope / mid-try would desync
// main's ⇒ heap corruption / aliased jmp_buf. Each fiber runs on its OWN arena +
// jmp stack: every jump is bracketed by save(leaving)/load(entering) of the 8
// context globals (5 arena + jmp_base/depth/thrown). A fresh ctx makes the arena
// lazily build itself. Nesting works: the "resumer" ctx is the CURRENTLY-running
// fiber's own ctx (or main's if none), captured per resume in $resumerCtx.
//
// State: 0=NEW 1=RUNNING 2=SUSPENDED 3=TERMINATED.

class FiberError extends \Error
{
}

// Thrown into a fiber that is still suspended when it is destroyed, so its
// `finally` blocks unwind before the stack is reclaimed.
class FiberExit extends \Exception
{
}

class Fiber
{
    private $callable;
    /** @var array */
    private array $args = [];
    private int $fctx = 0;        // this fiber's own suspended context
    private int $resumer = 0;     // fctx to jump back to on suspend/finish
    private int $resumerCtx = 0;  // the resumer's 64B save area (main's or an outer fiber's)
    private int $stackBase = 0;
    private int $saveCtx = 0;    // this fiber's private arena + jmp save area (64B)
    private int $state = 0;
    private bool $started = false;
    private mixed $valueIn = null;   // resume($v) -> returned by suspend()
    private mixed $valueOut = null;  // suspend($v) -> returned by resume()/start()
    private mixed $ret = null;       // the callback's return value
    // ⚠ These two MUST be \Throwable-typed, never `mixed`. A `mixed` property
    // holds the object NaN-boxed (a CELL); `throw $cell` then reaches a
    // `catch (SomeClass $e)` whose class-id test dereferences the boxed word as
    // an object header → SIGSEGV. `catch (\Throwable)` hides it (a catch-all
    // needs no deref), which is why the plain fiber_throw test never saw it.
    private ?\Throwable $pendingEx = null; // uncaught throwable from the callback, re-raised in the resumer
    private ?\Throwable $injectEx = null;  // throw()-injected throwable, raised at the suspend point
    // The length of THIS fiber's stack, appended LAST on purpose: a new field
    // ahead of an existing one moves every offset behind it. alloc, the stack top
    // and both free paths used to spell the same literal separately; the size is a
    // knob now ({@see stackSize()}), and a free that disagrees with its alloc
    // munmaps a range it does not own — or pools a stack under the wrong length.
    private int $stackLen = 0;

    public function __construct(callable $callback)
    {
        $this->callable = $callback;
    }

    public function start(mixed ...$args): mixed
    {
        if ($this->started) {
            throw new \FiberError("Cannot start a fiber that has already been started");
        }
        $this->args = $args;
        $this->started = true;
        $this->state = 1;
        $this->saveCtx = \__mir_fiber_ctx_new();
        if ($this->saveCtx === 0) {
            $this->started = false;
            $this->state = 0;
            throw new \FiberError("Fiber context allocation failed");
        }
        $len = self::stackSize();
        $base = \__mir_fiber_stack_alloc($len);
        // 0, never MAP_FAILED: out of address space, over RLIMIT_AS or at
        // vm.max_map_count used to come back as -1 and get a context built on
        // it — a SIGSEGV with nothing to read. Zend raises FiberError when it
        // cannot allocate a fiber, so this is the oracle-shaped answer.
        if ($base === 0) {
            $this->started = false;
            $this->state = 0;
            \__mir_fiber_ctx_free($this->saveCtx);
            $this->saveCtx = 0;
            throw new \FiberError("Fiber stack allocation failed (" . (string)$len
                . " bytes)" . self::stackFailureHint());
        }
        $this->stackBase = $base;
        $this->stackLen = $len;
        $this->fctx = \__mir_fiber_make($base + $len, $this);
        $prev = \__mir_fiber_current();
        $this->resumerCtx = \__mir_fiber_has_current() ? $prev->saveCtx : \__mir_fiber_main_ctx();
        \__mir_fiber_set_current($this);
        \__mir_fiber_guard_install();
        \__mir_fiber_guard_set($base);
        \__mir_fiber_ctx_save($this->resumerCtx);
        \__mir_fiber_ctx_load($this->saveCtx);
        $r = \__mir_fiber_jump($this->fctx);
        $this->fctx = $r;
        \__mir_fiber_set_current($prev);
        \__mir_fiber_guard_set($prev === null ? 0 : $prev->stackBase);
        if ($this->pendingEx !== null) {
            $e = $this->pendingEx;
            $this->pendingEx = null;
            throw $e;
        }
        return $this->valueOut;
    }

    // The fcontext trampoline lands here (via __mc_fiber_run) the first time the
    // fiber is entered. Runs the callback to completion, then jumps back for good.
    public function __mcRun(int $resumer): void
    {
        $this->resumer = $resumer;
        $cb = $this->callable;
        $a = $this->args;
        // Catch an uncaught throwable at the FIBER boundary and hand it back to
        // the resumer as a normal return — never let it longjmp across the stack
        // switch (that bypasses the arena/context restore ⇒ main runs on the
        // fiber's arena ⇒ corruption). start()/resume() re-raise it in-context.
        try {
            $this->ret = $cb(...$a);
        } catch (\Throwable $e) {
            $this->pendingEx = $e;
        }
        $this->state = 3;
        $this->valueOut = null;   // a terminated fiber's resume() yields null, not the last suspend value
        \__mir_fiber_ctx_save($this->saveCtx);
        \__mir_fiber_ctx_load($this->resumerCtx);
        \__mir_fiber_jump($this->resumer);
    }

    public function __mcSuspend(mixed $value): mixed
    {
        $this->valueOut = $value;
        $this->state = 2;
        \__mir_fiber_ctx_save($this->saveCtx);
        \__mir_fiber_ctx_load($this->resumerCtx);
        $nr = \__mir_fiber_jump($this->resumer);
        $this->resumer = $nr;
        $this->state = 1;
        if ($this->injectEx !== null) {
            $e = $this->injectEx;
            $this->injectEx = null;
            throw $e;   // Fiber::throw() raises at the suspension point
        }
        return $this->valueIn;
    }

    public function resume(mixed $value = null): mixed
    {
        if ($this->state !== 2) {
            throw new \FiberError("Cannot resume a fiber that is not suspended");
        }
        $this->valueIn = $value;
        $this->state = 1;
        $prev = \__mir_fiber_current();
        $this->resumerCtx = \__mir_fiber_has_current() ? $prev->saveCtx : \__mir_fiber_main_ctx();
        \__mir_fiber_set_current($this);
        \__mir_fiber_guard_set($this->stackBase);
        \__mir_fiber_ctx_save($this->resumerCtx);
        \__mir_fiber_ctx_load($this->saveCtx);
        $r = \__mir_fiber_jump($this->fctx);
        $this->fctx = $r;
        \__mir_fiber_set_current($prev);
        \__mir_fiber_guard_set($prev === null ? 0 : $prev->stackBase);
        if ($this->pendingEx !== null) {
            $e = $this->pendingEx;
            $this->pendingEx = null;
            throw $e;
        }
        return $this->valueOut;
    }

    public function throw(\Throwable $exception): mixed
    {
        if ($this->state !== 2) {
            throw new \FiberError("Cannot resume a fiber that is not suspended");
        }
        $this->injectEx = $exception;
        $this->state = 1;
        $prev = \__mir_fiber_current();
        $this->resumerCtx = \__mir_fiber_has_current() ? $prev->saveCtx : \__mir_fiber_main_ctx();
        \__mir_fiber_set_current($this);
        \__mir_fiber_guard_set($this->stackBase);
        \__mir_fiber_ctx_save($this->resumerCtx);
        \__mir_fiber_ctx_load($this->saveCtx);
        $r = \__mir_fiber_jump($this->fctx);
        $this->fctx = $r;
        \__mir_fiber_set_current($prev);
        \__mir_fiber_guard_set($prev === null ? 0 : $prev->stackBase);
        if ($this->pendingEx !== null) {
            $e = $this->pendingEx;
            $this->pendingEx = null;
            throw $e;
        }
        return $this->valueOut;
    }

    public function getReturn(): mixed
    {
        if ($this->state !== 3) {
            throw new \FiberError("Cannot get fiber return value: The fiber has not returned");
        }
        return $this->ret;
    }
    public function isStarted(): bool { return $this->started; }
    public function isRunning(): bool { return $this->state === 1; }
    public function isSuspended(): bool { return $this->state === 2; }
    public function isTerminated(): bool { return $this->state === 3; }

    public static function suspend(mixed $value = null): mixed
    {
        if (!\__mir_fiber_has_current()) {
            throw new \FiberError("Cannot suspend outside of a fiber");
        }
        $cur = \__mir_fiber_current();
        return $cur->__mcSuspend($value);
    }

    public static function getCurrent(): ?Fiber
    {
        return \__mir_fiber_current();
    }

    /**
     * Bytes of stack per fiber, resolved ONCE and cached. Zend's equivalent is the
     * `fiber.stack_size` ini; we have no ini, so it is the environment plus
     * {@see setStackSize()} — SUPERSET, {@see docs/superset.md}.
     *
     * A stack is virtual address space, not memory: pages are touched lazily and the
     * mapping is pooled and reclaimed on termination. What it costs is VA and TWO
     * MAPPINGS per live fiber — the stack, and the guard page the mprotect splits
     * off it — and the mapping count is the real ceiling: Linux `vm.max_map_count`
     * defaults to 65530, which is ~32 000 concurrent tasks NO MATTER what this
     * returns. Stack size buys address space and resident memory, not tasks;
     * MANTICORE_FIBER_GUARD=0 is what buys tasks. {@see tools/fiber_ceiling.php}.
     */
    private static int $stackBytes = 0;

    /** Bytes, resolved from MANTICORE_FIBER_STACK on first use. */
    public static function stackSize(): int
    {
        if (self::$stackBytes !== 0) {
            return self::$stackBytes;
        }
        // getenv is `string|false` (a cell) — unbox before comparing.
        $env = \getenv('MANTICORE_FIBER_STACK');
        // 1 MiB, MEASURED (tools/fiber_ceiling.php, 40 000 concurrent tasks on
        // Linux arm64): 8 MiB costs 6.55 GiB of RSS, 1 MiB costs 0.65 GiB, and 512
        // and 256 KiB cost exactly the same 0.65 GiB. The curve is flat below 1 MiB
        // and 10x worse above it, so this is the knee — smaller buys only address
        // space, larger buys nothing but resident memory. Darwin shows no such
        // step, which is why a macOS-only measurement would have kept 8 MiB.
        $want = 1048576;
        if ($env !== false && $env !== '') {
            $n = (int)(string)$env;
            if ($n > 0) { $want = $n; }
        }
        self::$stackBytes = self::clampStack($want);
        return self::$stackBytes;
    }

    /**
     * Set the per-fiber stack size for fibers created AFTER this call. Stacks
     * already pooled under the previous size are never reused for a different one
     * (the pool records each mapping's length), so mixing sizes in one process is
     * safe — it only costs an mmap where a pooled stack would have done.
     */
    public static function setStackSize(int $bytes): void
    {
        self::$stackBytes = self::clampStack($bytes);
    }

    /**
     * Why a stack mapping failed, in whatever words the host can be asked for.
     * "Allocation failed" alone is true and useless: the ceiling here is almost
     * never memory, it is `vm.max_map_count`. Every fiber costs TWO mappings — the
     * stack, plus the mprotect that splits its guard page off — so a stock 65530
     * stops a process at ~32 000 concurrent tasks whatever the stack size is, and
     * that is a sysctl away from being a non-problem. Linux only: Darwin has no
     * /proc and no equivalent cap to read, so it gets the bare message.
     */
    private static function stackFailureHint(): string
    {
        $cap = @\file_get_contents('/proc/sys/vm/max_map_count');
        if ($cap === false) { return ''; }
        $maps = @\file_get_contents('/proc/self/maps');
        if ($maps === false) { return ''; }
        $limit = (int)\trim((string)$cap);
        $used = \substr_count((string)$maps, "\n");
        $hint = ' — ' . (string)$used . ' of ' . (string)$limit . ' mappings in use';
        if ($limit > 0 && $used * 10 >= $limit * 9) {
            $hint = $hint . '; this is vm.max_map_count, not memory. Raise the sysctl,'
                . ' or set MANTICORE_FIBER_GUARD=0 to spend one mapping per fiber'
                . ' instead of two and lose the named stack-overflow report';
        }
        return $hint;
    }

    /** Round up to a 16 KiB multiple (the Apple-silicon page) and keep the guard
     *  page from eating the stack: the low 16 KiB is PROT_NONE, so anything near it
     *  is a stack that faults before it is useful. Unchanged when the guard is off
     *  (MANTICORE_FIBER_GUARD=0) — the low pages are then usable stack, and the
     *  rounding costs nothing worth a second code path. */
    private static function clampStack(int $bytes): int
    {
        if ($bytes < 65536) { $bytes = 65536; }
        $rem = $bytes % 16384;
        if ($rem !== 0) { $bytes = $bytes + (16384 - $rem); }
        return $bytes;
    }

    /**
     * Free a TERMINATED fiber's stack + ctx NOW (the stack is pooled for reuse),
     * instead of waiting for the deferred __destruct. A scheduler that runs many
     * short-lived fibers (one per connection) calls this on completion so their
     * stacks are reclaimed+pooled promptly rather than accumulating (a 16k-fiber
     * churn otherwise held ~400MB). Idempotent: __destruct's guards skip the
     * already-nulled fields, and getReturn() still works (the value is a field,
     * not on the stack).
     */
    public function reclaim(): void
    {
        if ($this->state !== 3) {
            return;                       // only a terminated fiber is safe to free
        }
        if ($this->stackBase !== 0) {
            \__mir_fiber_stack_free($this->stackBase, $this->stackLen);
            $this->stackBase = 0;
        }
        if ($this->saveCtx !== 0) {
            \__mir_fiber_ctx_free($this->saveCtx);
            $this->saveCtx = 0;
        }
    }

    public function __destruct()
    {
        // A fiber left SUSPENDED at destruction must unwind its stack so `finally`
        // blocks run: inject a FiberExit at the suspend point and let it propagate
        // to termination, then swallow it (it must not escape the destructor).
        if ($this->state === 2) {
            $this->injectEx = new \FiberExit("");
            $this->state = 1;
            $prev = \__mir_fiber_current();
            $this->resumerCtx = \__mir_fiber_has_current() ? $prev->saveCtx : \__mir_fiber_main_ctx();
            \__mir_fiber_set_current($this);
            \__mir_fiber_guard_set($this->stackBase);
            \__mir_fiber_ctx_save($this->resumerCtx);
            \__mir_fiber_ctx_load($this->saveCtx);
            $r = \__mir_fiber_jump($this->fctx);
            $this->fctx = $r;
            \__mir_fiber_set_current($prev);
            \__mir_fiber_guard_set($prev === null ? 0 : $prev->stackBase);
            $this->pendingEx = null;   // the FiberExit terminated it; do not re-raise
        }
        if ($this->stackBase !== 0) {
            \__mir_fiber_stack_free($this->stackBase, $this->stackLen);
            $this->stackBase = 0;
        }
        if ($this->saveCtx !== 0) {
            \__mir_fiber_ctx_free($this->saveCtx);
            $this->saveCtx = 0;
        }
    }
}

// The fcontext entry symbol (@manticore___mc_fiber_run): its (i64,i64) ABI is
// exactly what the asm trampoline calls — entry(fiberObj, resumer_fctx).
function __mc_fiber_run(\Fiber $f, int $resumer): void
{
    $f->__mcRun($resumer);
}
