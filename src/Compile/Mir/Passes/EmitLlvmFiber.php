<?php

namespace Compile\Mir\Passes;

/**
 * `\Fiber` — stackful coroutines. The switch primitive is a boost.context-style
 * fcontext, emitted as an arch-branched `module asm` block; the rest of Fiber is
 * a normal prelude PHP class ({@see prelude/fiber.php}) calling a few intrinsics:
 *   __mir_fiber_make(top, fiberObj) : int   -- build a context on a fresh stack
 *   __mir_fiber_jump(fctx)          : int   -- switch; returns the resumer's fctx
 *   __mir_fiber_current()           : Fiber -- the running fiber (or null ptr)
 *   __mir_fiber_set_current(fiber)  : void
 *   __mir_fiber_stack_alloc(size)   : int   -- returns the stack base
 *   __mir_fiber_stack_free(base)    : void
 *
 * Own i64-only switch ABI (single i64 return, no boost transfer_t struct-return):
 *   void* mc_fiber_jump(void* to_fctx);                        -> from_fctx
 *   void* mc_fiber_make(void* sp_top, void* entry, void* arg); -> fctx
 * The trampoline invokes entry as `entry(arg, resumer_fctx)`; entry is the
 * compiled `__mc_fiber_run(\Fiber $f, int $resumer)` (its i64,i64 ABI matches
 * x0/x1 on arm64, rdi/rsi on x86_64). Value passing (suspend<->resume) rides the
 * `\Fiber` object's fields, NOT the asm.
 *
 * Emitted only when `needsFibers` (the demand-gated `__mc_fiber_run` is present).
 * Host == target (native compile, no `-target` on the clang line), so the asm is
 * picked by {@see \Manticore\host_arch()} / {@see \Manticore\is_darwin()} — safe
 * because this block never emits during the compiler's own (fiber-free) build.
 */
trait EmitLlvmFiber
{
    /** Module-level fiber runtime: current-fiber global, switch declares, and the
     *  arch-branched fcontext `module asm`. Emitted in the preamble under needsFibers. */
    private function fiberRuntime(): string
    {
        $out  = "@__mir_current_fiber = linkonce_odr global ptr null\n";
        // Per-context save area (64B): the 5 arena globals (head/cur/marks/sp/mcap)
        // + the 3 exception globals (jmp_base/jmp_depth/thrown). Both the bump
        // arena+mark-stack and the try-slot jmp stack are process-global, so a
        // fiber suspending mid-scope / mid-try would desync main's ⇒ heap
        // corruption / aliased jmp_buf. Each fiber runs on its OWN arena + jmp
        // stack; main's state lives here. {@see prelude/fiber.php} brackets every
        // jump with save/load.
        $out .= "@__mir_fiber_main_ctx = linkonce_odr global [64 x i8] zeroinitializer\n";
        $out .= "declare i64 @mc_fiber_make(i64, i64, i64)\n";
        $out .= "declare i64 @mc_fiber_jump(i64)\n";
        foreach ($this->fiberAsmLines() as $line) {
            $out .= 'module asm "' . $line . '"' . "\n";
        }
        return $out;
    }

    /** The fcontext asm for the host arch/format, one line per element.
     *  @return list<string> */
    private function fiberAsmLines(): array
    {
        $arch = \Manticore\host_arch();
        $u = \Manticore\is_darwin() ? '_' : '';   // Mach-O prepends a leading underscore
        if ($arch === 'arm64') { return $this->fiberAsmArm64($u); }
        return $this->fiberAsmX86_64($u);
    }

    /** @return list<string> */
    private function fiberAsmArm64(string $u): array
    {
        return [
            '.text',
            '.p2align 2',
            '.globl ' . $u . 'mc_fiber_jump',
            $u . 'mc_fiber_jump:',
            'stp x19, x20, [sp, #-0xa0]!',
            'stp x21, x22, [sp, #0x10]',
            'stp x23, x24, [sp, #0x20]',
            'stp x25, x26, [sp, #0x30]',
            'stp x27, x28, [sp, #0x40]',
            'stp x29, x30, [sp, #0x50]',
            'stp d8, d9, [sp, #0x60]',
            'stp d10, d11, [sp, #0x70]',
            'stp d12, d13, [sp, #0x80]',
            'stp d14, d15, [sp, #0x90]',
            'mov x9, sp',
            'mov sp, x0',
            'ldp x19, x20, [sp, #0x00]',
            'ldp x21, x22, [sp, #0x10]',
            'ldp x23, x24, [sp, #0x20]',
            'ldp x25, x26, [sp, #0x30]',
            'ldp x27, x28, [sp, #0x40]',
            'ldp x29, x30, [sp, #0x50]',
            'ldp d8, d9, [sp, #0x60]',
            'ldp d10, d11, [sp, #0x70]',
            'ldp d12, d13, [sp, #0x80]',
            'ldp d14, d15, [sp, #0x90]',
            'add sp, sp, #0xa0',
            'mov x0, x9',
            'ret',
            '.globl ' . $u . 'mc_fiber_make',
            $u . 'mc_fiber_make:',
            'and x0, x0, #0xfffffffffffffff0',
            'sub x0, x0, #0xa0',
            'str x1, [x0, #0x00]',
            'str x2, [x0, #0x08]',
            'adrp x3, ' . $u . 'mc_fiber_trampoline@PAGE',
            'add x3, x3, ' . $u . 'mc_fiber_trampoline@PAGEOFF',
            'str x3, [x0, #0x58]',
            'mov x4, #0',
            'str x4, [x0, #0x50]',
            'ret',
            $u . 'mc_fiber_trampoline:',
            'mov x1, x0',
            'mov x0, x20',
            'blr x19',
            'brk #0',
        ];
    }

    /** @return list<string> */
    private function fiberAsmX86_64(string $u): array
    {
        return [
            '.text',
            '.globl ' . $u . 'mc_fiber_jump',
            $u . 'mc_fiber_jump:',
            'push %rbp',
            'push %rbx',
            'push %r12',
            'push %r13',
            'push %r14',
            'push %r15',
            'movq %rsp, %rax',
            'movq %rdi, %rsp',
            'pop %r15',
            'pop %r14',
            'pop %r13',
            'pop %r12',
            'pop %rbx',
            'pop %rbp',
            'ret',
            '.globl ' . $u . 'mc_fiber_make',
            $u . 'mc_fiber_make:',
            'andq $-16, %rdi',
            'subq $0x38, %rdi',
            'leaq ' . $u . 'mc_fiber_trampoline(%rip), %rax',
            'movq %rax, 0x30(%rdi)',
            'movq %rsi, 0x18(%rdi)',
            'movq %rdx, 0x10(%rdi)',
            'movq %rdi, %rax',
            'ret',
            $u . 'mc_fiber_trampoline:',
            'movq %r13, %rdi',
            'movq %rax, %rsi',
            'call *%r12',
            'ud2',
        ];
    }

    /** __mir_fiber_jump(fctx) : int — the raw context switch. */
    private function biFiberJump(array $args): string
    {
        $this->rt->needsFibers = true;
        $out = $this->emitIntArg($args[0]);
        $fctx = $this->lastValue;
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 @mc_fiber_jump(i64 ' . $fctx . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** __mir_fiber_make(top, fiberObj) : int — context on a fresh stack whose
     *  first jump lands in @manticore___mc_fiber_run(fiberObj, resumer). */
    private function biFiberMake(array $args): string
    {
        $this->rt->needsFibers = true;
        $out = $this->emitIntArg($args[0]);
        $top = $this->lastValue;
        $out .= $this->emitIntArg($args[1]);   // the \Fiber object as an i64 ptr
        $obj = $this->lastValue;
        $entry = $this->ssa->allocReg();
        $out .= '  ' . $entry . ' = ptrtoint ptr @manticore___mc_fiber_run to i64' . "\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 @mc_fiber_make(i64 ' . $top . ', i64 ' . $entry
            . ', i64 ' . $obj . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** __mir_fiber_current() : Fiber — the running fiber (raw ptr, 0 = none). */
    private function biFiberCurrent(array $args): string
    {
        $this->rt->needsFibers = true;
        $p = $this->ssa->allocReg();
        $out = '  ' . $p . ' = load ptr, ptr @__mir_current_fiber' . "\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = ptrtoint ptr ' . $p . ' to i64' . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** __mir_fiber_set_current(fiber) : void */
    private function biFiberSetCurrent(array $args): string
    {
        $this->rt->needsFibers = true;
        $out = $this->emitIntArg($args[0]);
        $obj = $this->lastValue;
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $obj . ' to ptr' . "\n";
        $out .= '  store ptr ' . $p . ', ptr @__mir_current_fiber' . "\n";
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** __mir_fiber_stack_alloc(size) : int — malloc'd stack, returns the base. */
    private function biFiberStackAlloc(array $args): string
    {
        $this->rt->needsFibers = true;
        $out = $this->emitIntArg($args[0]);
        $sz = $this->lastValue;
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = call ptr @malloc(i64 ' . $sz . ")\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = ptrtoint ptr ' . $p . ' to i64' . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** __mir_fiber_stack_free(base) : void */
    private function biFiberStackFree(array $args): string
    {
        $this->rt->needsFibers = true;
        $out = $this->emitIntArg($args[0]);
        $base = $this->lastValue;
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $base . ' to ptr' . "\n";
        $out .= '  call void @free(ptr ' . $p . ")\n";
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** The saved globals, in ctx order: 5 arena + 3 exception. ptr | i64. */
    private const FIBER_CTX_GLOBALS = [
        ['@__mir_arena_head', 'ptr', 0],
        ['@__mir_arena_cur', 'ptr', 8],
        ['@__mir_arena_marks', 'ptr', 16],
        ['@__mir_arena_sp', 'i64', 24],
        ['@__mir_arena_mcap', 'i64', 32],
        ['@__mir_jmp_base', 'ptr', 40],
        ['@__mir_jmp_depth', 'i64', 48],
        ['@__mir_thrown', 'ptr', 56],
    ];

    /** __mir_fiber_ctx_new() : int — a fresh 64B save area for a new fiber. Arena
     *  fields stay zeroed (the arena lazily self-builds). The jmp stack is its OWN
     *  8KB buffer with depth 1 (slot 0 reserved, like main) so fiber tries never
     *  alias main's jmp_buf; thrown starts null. */
    private function biFiberCtxNew(array $args): string
    {
        $this->rt->needsFibers = true;
        $p = $this->ssa->allocReg();
        $out = '  ' . $p . ' = call ptr @calloc(i64 64, i64 1)' . "\n";
        $js = $this->ssa->allocReg();
        $out .= '  ' . $js . ' = call ptr @malloc(i64 8192)' . "\n";
        $jbslot = $this->ssa->allocReg();
        $out .= '  ' . $jbslot . ' = getelementptr i8, ptr ' . $p . ', i64 40' . "\n";
        $out .= '  store ptr ' . $js . ', ptr ' . $jbslot . "\n";
        $jdslot = $this->ssa->allocReg();
        $out .= '  ' . $jdslot . ' = getelementptr i8, ptr ' . $p . ', i64 48' . "\n";
        $out .= '  store i64 1, ptr ' . $jdslot . "\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = ptrtoint ptr ' . $p . ' to i64' . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** __mir_fiber_ctx_save(ctx) : void — snapshot the arena + exception globals into ctx. */
    private function biFiberCtxSave(array $args): string
    {
        $this->rt->needsFibers = true;
        $out = $this->emitIntArg($args[0]);
        $cp = $this->ssa->allocReg();
        $out .= '  ' . $cp . ' = inttoptr i64 ' . $this->lastValue . ' to ptr' . "\n";
        foreach (self::FIBER_CTX_GLOBALS as $g) {
            [$sym, $ty, $off] = $g;
            $v = $this->ssa->allocReg();
            $out .= '  ' . $v . ' = load ' . $ty . ', ptr ' . $sym . "\n";
            $slot = $this->ssa->allocReg();
            $out .= '  ' . $slot . ' = getelementptr i8, ptr ' . $cp . ', i64 ' . (string)$off . "\n";
            $out .= '  store ' . $ty . ' ' . $v . ', ptr ' . $slot . "\n";
        }
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** __mir_fiber_ctx_load(ctx) : void — restore the arena + exception globals from ctx. */
    private function biFiberCtxLoad(array $args): string
    {
        $this->rt->needsFibers = true;
        $out = $this->emitIntArg($args[0]);
        $cp = $this->ssa->allocReg();
        $out .= '  ' . $cp . ' = inttoptr i64 ' . $this->lastValue . ' to ptr' . "\n";
        foreach (self::FIBER_CTX_GLOBALS as $g) {
            [$sym, $ty, $off] = $g;
            $slot = $this->ssa->allocReg();
            $out .= '  ' . $slot . ' = getelementptr i8, ptr ' . $cp . ', i64 ' . (string)$off . "\n";
            $v = $this->ssa->allocReg();
            $out .= '  ' . $v . ' = load ' . $ty . ', ptr ' . $slot . "\n";
            $out .= '  store ' . $ty . ' ' . $v . ', ptr ' . $sym . "\n";
        }
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** __mir_fiber_has_current() : bool — is a fiber running? (global != null).
     *  An int-level null test, so it dodges the unsound `=== null` on an obj. */
    private function biFiberHasCurrent(array $args): string
    {
        $this->rt->needsFibers = true;
        $p = $this->ssa->allocReg();
        $out = '  ' . $p . ' = load ptr, ptr @__mir_current_fiber' . "\n";
        $b = $this->ssa->allocReg();
        $out .= '  ' . $b . ' = icmp ne ptr ' . $p . ', null' . "\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = zext i1 ' . $b . ' to i64' . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** __mir_fiber_main_ctx() : int — address of the root (main) arena save area. */
    private function biFiberMainCtx(array $args): string
    {
        $this->rt->needsFibers = true;
        $r = $this->ssa->allocReg();
        $out = '  ' . $r . ' = ptrtoint ptr @__mir_fiber_main_ctx to i64' . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }
}
