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
    private mixed $pendingEx = null; // uncaught throwable from the callback, re-raised in the resumer
    private mixed $injectEx = null;  // throw()-injected throwable, raised at the suspend point

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
        $base = \__mir_fiber_stack_alloc(8388608);
        $this->stackBase = $base;
        $this->fctx = \__mir_fiber_make($base + 8388608, $this);
        $prev = \__mir_fiber_current();
        $this->resumerCtx = \__mir_fiber_has_current() ? $prev->saveCtx : \__mir_fiber_main_ctx();
        \__mir_fiber_set_current($this);
        \__mir_fiber_ctx_save($this->resumerCtx);
        \__mir_fiber_ctx_load($this->saveCtx);
        $r = \__mir_fiber_jump($this->fctx);
        $this->fctx = $r;
        \__mir_fiber_set_current($prev);
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
        \__mir_fiber_ctx_save($this->resumerCtx);
        \__mir_fiber_ctx_load($this->saveCtx);
        $r = \__mir_fiber_jump($this->fctx);
        $this->fctx = $r;
        \__mir_fiber_set_current($prev);
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
        \__mir_fiber_ctx_save($this->resumerCtx);
        \__mir_fiber_ctx_load($this->saveCtx);
        $r = \__mir_fiber_jump($this->fctx);
        $this->fctx = $r;
        \__mir_fiber_set_current($prev);
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
            \__mir_fiber_stack_free($this->stackBase, 8388608);
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
            \__mir_fiber_ctx_save($this->resumerCtx);
            \__mir_fiber_ctx_load($this->saveCtx);
            $r = \__mir_fiber_jump($this->fctx);
            $this->fctx = $r;
            \__mir_fiber_set_current($prev);
            $this->pendingEx = null;   // the FiberExit terminated it; do not re-raise
        }
        if ($this->stackBase !== 0) {
            \__mir_fiber_stack_free($this->stackBase, 8388608);
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
