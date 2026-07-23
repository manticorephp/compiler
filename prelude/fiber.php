// \Fiber — stackful coroutines. Oracle = Zend Fiber (php 8.1+). The switch is an
// fcontext primitive emitted as `module asm`; this class is the thin PHP layer
// over the __mir_fiber_* intrinsics. Value passing (suspend<->resume) rides the
// object's fields, not the asm. DEMAND-GATED (Main.php): compiled only when a
// program mentions Fiber, so the fiber-free compiler build never emits the asm.
//
// The process-global bump arena (cur + a mark stack) is shared, so a fiber that
// suspends mid-scope would desync main's mark stack ⇒ heap corruption. Each fiber
// runs on its OWN arena: every jump is bracketed by save(leaving)/load(entering)
// of the five arena globals. A fresh (zeroed) ctx makes the arena lazily build
// itself the first time the fiber runs. MVP: the resumer is always the main
// context (nested fiber-resumes-fiber not yet handled).
//
// State: 0=NEW 1=RUNNING 2=SUSPENDED 3=TERMINATED.

class Fiber
{
    private $callable;
    /** @var array */
    private array $args = [];
    private int $fctx = 0;        // this fiber's own suspended context
    private int $resumer = 0;     // context to jump back to on suspend/finish
    private int $stackBase = 0;
    private int $arenaCtx = 0;    // this fiber's private arena save area
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
        $this->args = $args;
        $this->started = true;
        $this->state = 1;
        $this->arenaCtx = \__mir_fiber_arena_new();
        $base = \__mir_fiber_stack_alloc(8388608);
        $this->stackBase = $base;
        $this->fctx = \__mir_fiber_make($base + 8388608, $this);
        $prev = \__mir_fiber_current();
        \__mir_fiber_set_current($this);
        \__mir_fiber_arena_save(\__mir_fiber_main_ctx());
        \__mir_fiber_arena_load($this->arenaCtx);
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
        \__mir_fiber_arena_save($this->arenaCtx);
        \__mir_fiber_arena_load(\__mir_fiber_main_ctx());
        \__mir_fiber_jump($this->resumer);
    }

    public function __mcSuspend(mixed $value): mixed
    {
        $this->valueOut = $value;
        $this->state = 2;
        \__mir_fiber_arena_save($this->arenaCtx);
        \__mir_fiber_arena_load(\__mir_fiber_main_ctx());
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
        $this->valueIn = $value;
        $this->state = 1;
        $prev = \__mir_fiber_current();
        \__mir_fiber_set_current($this);
        \__mir_fiber_arena_save(\__mir_fiber_main_ctx());
        \__mir_fiber_arena_load($this->arenaCtx);
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
        $this->injectEx = $exception;
        $this->state = 1;
        $prev = \__mir_fiber_current();
        \__mir_fiber_set_current($this);
        \__mir_fiber_arena_save(\__mir_fiber_main_ctx());
        \__mir_fiber_arena_load($this->arenaCtx);
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

    public function getReturn(): mixed { return $this->ret; }
    public function isStarted(): bool { return $this->started; }
    public function isRunning(): bool { return $this->state === 1; }
    public function isSuspended(): bool { return $this->state === 2; }
    public function isTerminated(): bool { return $this->state === 3; }

    public static function suspend(mixed $value = null): mixed
    {
        $cur = \__mir_fiber_current();
        return $cur->__mcSuspend($value);
    }

    public static function getCurrent(): ?Fiber
    {
        return \__mir_fiber_current();
    }
}

// The fcontext entry symbol (@manticore___mc_fiber_run): its (i64,i64) ABI is
// exactly what the asm trampoline calls — entry(fiberObj, resumer_fctx).
function __mc_fiber_run(\Fiber $f, int $resumer): void
{
    $f->__mcRun($resumer);
}
