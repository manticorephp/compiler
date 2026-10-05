<?php

namespace Compile\Runtime;

use Compile\MemoryAbi;

/**
 * Zero-cost exception runtime: a PHP `throw` is an Itanium-ABI unwind.
 *
 * - `@__mc_throw(obj)` wraps the Throwable in an exception object
 *   ({@see MemoryAbi::EXC_SIZE}: `_Unwind_Exception` header + payload) and
 *   calls `_Unwind_RaiseException`. It returns only when no frame catches
 *   (two-phase: the search phase found no handler, so nothing was unwound),
 *   and then runs the uncaught-exception fatal that `@main` installed in
 *   `@__mc_uncaught_fn`.
 * - `@__mc_personality` is the personality of every function with a landing
 *   pad. It reads the LSDA LLVM emits for `invoke`/`landingpad` (call-site
 *   table only — the type table is never consulted): a call site whose action
 *   is non-zero is a PHP catch pad (`catch ptr @__mc_typeinfo`) and handles
 *   only {@see MemoryAbi::EXC_CLASS}; action zero is a cleanup pad and runs for
 *   any exception. Own personality, not the C++ one, so no binary links a C++
 *   runtime; libgcc's `__gcc_personality_v0` cannot catch.
 * - `@__mc_eh_catch(ex)` is the first call of every PHP catch pad: it moves the
 *   payload into `@__mir_thrown` (which owns the +1 the throw handed over,
 *   exactly as before) and frees the exception object.
 *
 * The catch-class dispatch stays in the pad ({@see EmitLlvmExceptions}): the
 * personality only says "a PHP handler is here".
 */
final class UnwindRuntime
{
    /** Module-level IR: two globals and the helpers (declarations live in RuntimeFeatures). */
    public static function ir(): string
    {
        $cls = (string)MemoryAbi::EXC_CLASS;
        $size = (string)MemoryAbi::EXC_SIZE;
        $pay = (string)MemoryAbi::EXC_PAYLOAD_OFFSET;
        $o = "@__mc_typeinfo = linkonce_odr constant i8 0\n";
        $o .= "@__mc_uncaught_fn = linkonce_odr global ptr null\n";

        $o .= "define void @__mc_throw(ptr %obj) {\nentry:\n";
        $o .= "  %ex = call ptr @malloc(i64 " . $size . ")\n";
        $o .= "  store i64 " . $cls . ", ptr %ex\n";
        $o .= "  %c = getelementptr inbounds i8, ptr %ex, i64 8\n";
        $o .= "  store ptr null, ptr %c\n";
        $o .= "  %p1 = getelementptr inbounds i8, ptr %ex, i64 16\n";
        $o .= "  store i64 0, ptr %p1\n";
        $o .= "  %p2 = getelementptr inbounds i8, ptr %ex, i64 24\n";
        $o .= "  store i64 0, ptr %p2\n";
        $o .= "  %pl = getelementptr inbounds i8, ptr %ex, i64 " . $pay . "\n";
        $o .= "  store ptr %obj, ptr %pl\n";
        $o .= "  %rc = call i32 @_Unwind_RaiseException(ptr %ex)\n";
        $o .= "  call void @free(ptr %ex)\n";
        $o .= "  store ptr %obj, ptr @__mir_thrown\n";
        $o .= "  %h = load ptr, ptr @__mc_uncaught_fn\n";
        $o .= "  %hn = icmp eq ptr %h, null\n";
        $o .= "  br i1 %hn, label %none, label %fatal\n";
        $o .= "fatal:\n";
        $o .= "  call void %h()\n";
        $o .= "  br label %none\n";
        $o .= "none:\n";
        $o .= "  call void @abort()\n";
        $o .= "  unreachable\n}\n";

        $o .= "define ptr @__mc_eh_catch(ptr %ex) {\nentry:\n";
        $o .= "  %pl = getelementptr inbounds i8, ptr %ex, i64 " . $pay . "\n";
        $o .= "  %obj = load ptr, ptr %pl\n";
        $o .= "  call void @free(ptr %ex)\n";
        $o .= "  store ptr %obj, ptr @__mir_thrown\n";
        $o .= "  ret ptr %obj\n}\n";

        // ULEB128 at *%pp; advances *%pp past it.
        $o .= "define i64 @__mc_eh_uleb(ptr %pp) {\nentry:\n";
        $o .= "  %p0 = load ptr, ptr %pp\n";
        $o .= "  br label %loop\n";
        $o .= "loop:\n";
        $o .= "  %p = phi ptr [ %p0, %entry ], [ %pn, %loop ]\n";
        $o .= "  %acc = phi i64 [ 0, %entry ], [ %acc2, %loop ]\n";
        $o .= "  %sh = phi i64 [ 0, %entry ], [ %sh2, %loop ]\n";
        $o .= "  %b = load i8, ptr %p\n";
        $o .= "  %pn = getelementptr inbounds i8, ptr %p, i64 1\n";
        $o .= "  %b64 = zext i8 %b to i64\n";
        $o .= "  %lo = and i64 %b64, 127\n";
        $o .= "  %shc = and i64 %sh, 63\n";
        $o .= "  %v = shl i64 %lo, %shc\n";
        $o .= "  %acc2 = or i64 %acc, %v\n";
        $o .= "  %sh2 = add i64 %sh, 7\n";
        $o .= "  %more = icmp ugt i64 %b64, 127\n";
        $o .= "  br i1 %more, label %loop, label %done\n";
        $o .= "done:\n";
        $o .= "  store ptr %pn, ptr %pp\n";
        $o .= "  ret i64 %acc2\n}\n";

        // A DWARF-encoded call-site field at *%pp (format nibble only: the
        // fields are offsets, so no application bits apply).
        $o .= "define i64 @__mc_eh_enc(ptr %pp, i8 %enc) {\nentry:\n";
        $o .= "  %p = load ptr, ptr %pp\n";
        $o .= "  %f = and i8 %enc, 15\n";
        $o .= "  switch i8 %f, label %d8 [ i8 1, label %uleb i8 2, label %u2 i8 3, label %u4 i8 10, label %s2 i8 11, label %s4 ]\n";
        $o .= "uleb:\n";
        $o .= "  %ul = call i64 @__mc_eh_uleb(ptr %pp)\n";
        $o .= "  ret i64 %ul\n";
        $o .= "u2:\n";
        $o .= "  %u2v = load i16, ptr %p, align 1\n";
        $o .= "  %u2x = zext i16 %u2v to i64\n";
        $o .= "  %u2n = getelementptr inbounds i8, ptr %p, i64 2\n";
        $o .= "  store ptr %u2n, ptr %pp\n";
        $o .= "  ret i64 %u2x\n";
        $o .= "s2:\n";
        $o .= "  %s2v = load i16, ptr %p, align 1\n";
        $o .= "  %s2x = sext i16 %s2v to i64\n";
        $o .= "  %s2n = getelementptr inbounds i8, ptr %p, i64 2\n";
        $o .= "  store ptr %s2n, ptr %pp\n";
        $o .= "  ret i64 %s2x\n";
        $o .= "u4:\n";
        $o .= "  %u4v = load i32, ptr %p, align 1\n";
        $o .= "  %u4x = zext i32 %u4v to i64\n";
        $o .= "  %u4n = getelementptr inbounds i8, ptr %p, i64 4\n";
        $o .= "  store ptr %u4n, ptr %pp\n";
        $o .= "  ret i64 %u4x\n";
        $o .= "s4:\n";
        $o .= "  %s4v = load i32, ptr %p, align 1\n";
        $o .= "  %s4x = sext i32 %s4v to i64\n";
        $o .= "  %s4n = getelementptr inbounds i8, ptr %p, i64 4\n";
        $o .= "  store ptr %s4n, ptr %pp\n";
        $o .= "  ret i64 %s4x\n";
        $o .= "d8:\n";
        $o .= "  %d8v = load i64, ptr %p, align 1\n";
        $o .= "  %d8n = getelementptr inbounds i8, ptr %p, i64 8\n";
        $o .= "  store ptr %d8n, ptr %pp\n";
        $o .= "  ret i64 %d8v\n}\n";

        // _Unwind_Reason_Code: 6 HANDLER_FOUND, 7 INSTALL_CONTEXT, 8 CONTINUE_UNWIND.
        // _Unwind_Action bits: 1 SEARCH_PHASE, 2 CLEANUP_PHASE.
        // Landing-pad registers 0/1 are x0/x1 on arm64 and rax/rdx on x86_64.
        $o .= "define i32 @__mc_personality(i32 %ver, i32 %act, i64 %cls, ptr %ex, ptr %ctx) {\nentry:\n";
        $o .= "  %pp = alloca ptr\n";
        $o .= "  %ours = icmp eq i64 %cls, " . $cls . "\n";
        $o .= "  %lsda = call ptr @_Unwind_GetLanguageSpecificData(ptr %ctx)\n";
        $o .= "  %nol = icmp eq ptr %lsda, null\n";
        $o .= "  br i1 %nol, label %cont, label %hdr\n";
        $o .= "hdr:\n";
        $o .= "  %ip0 = call i64 @_Unwind_GetIP(ptr %ctx)\n";
        $o .= "  %ip = sub i64 %ip0, 1\n";
        $o .= "  %fs = call i64 @_Unwind_GetRegionStart(ptr %ctx)\n";
        $o .= "  %off = sub i64 %ip, %fs\n";
        $o .= "  %lpenc = load i8, ptr %lsda\n";
        $o .= "  %a1 = getelementptr inbounds i8, ptr %lsda, i64 1\n";
        $o .= "  store ptr %a1, ptr %pp\n";
        $o .= "  %lpomit = icmp eq i8 %lpenc, -1\n";
        $o .= "  br i1 %lpomit, label %tt, label %lpskip\n";
        $o .= "lpskip:\n";
        $o .= "  %lpv = call i64 @__mc_eh_enc(ptr %pp, i8 %lpenc)\n";
        $o .= "  br label %tt\n";
        $o .= "tt:\n";
        $o .= "  %q = load ptr, ptr %pp\n";
        $o .= "  %ttenc = load i8, ptr %q\n";
        $o .= "  %q1 = getelementptr inbounds i8, ptr %q, i64 1\n";
        $o .= "  store ptr %q1, ptr %pp\n";
        $o .= "  %ttomit = icmp eq i8 %ttenc, -1\n";
        $o .= "  br i1 %ttomit, label %cs, label %ttskip\n";
        $o .= "ttskip:\n";
        $o .= "  %ttoff = call i64 @__mc_eh_uleb(ptr %pp)\n";
        $o .= "  br label %cs\n";
        $o .= "cs:\n";
        $o .= "  %r = load ptr, ptr %pp\n";
        $o .= "  %csenc = load i8, ptr %r\n";
        $o .= "  %r1 = getelementptr inbounds i8, ptr %r, i64 1\n";
        $o .= "  store ptr %r1, ptr %pp\n";
        $o .= "  %cslen = call i64 @__mc_eh_uleb(ptr %pp)\n";
        $o .= "  %tb = load ptr, ptr %pp\n";
        $o .= "  %tend = getelementptr inbounds i8, ptr %tb, i64 %cslen\n";
        $o .= "  br label %scan\n";
        $o .= "scan:\n";
        $o .= "  %cur = load ptr, ptr %pp\n";
        $o .= "  %more = icmp ult ptr %cur, %tend\n";
        $o .= "  br i1 %more, label %rec, label %cont\n";
        $o .= "rec:\n";
        $o .= "  %st = call i64 @__mc_eh_enc(ptr %pp, i8 %csenc)\n";
        $o .= "  %ln = call i64 @__mc_eh_enc(ptr %pp, i8 %csenc)\n";
        $o .= "  %lp = call i64 @__mc_eh_enc(ptr %pp, i8 %csenc)\n";
        $o .= "  %ac = call i64 @__mc_eh_uleb(ptr %pp)\n";
        $o .= "  %before = icmp ult i64 %off, %st\n";
        $o .= "  br i1 %before, label %cont, label %chk\n";
        $o .= "chk:\n";
        $o .= "  %end = add i64 %st, %ln\n";
        $o .= "  %in = icmp ult i64 %off, %end\n";
        $o .= "  br i1 %in, label %found, label %scan\n";
        $o .= "found:\n";
        $o .= "  %nolp = icmp eq i64 %lp, 0\n";
        $o .= "  br i1 %nolp, label %cont, label %haslp\n";
        $o .= "haslp:\n";
        $o .= "  %iscatch = icmp ne i64 %ac, 0\n";
        $o .= "  %handler = and i1 %iscatch, %ours\n";
        $o .= "  %sbit = and i32 %act, 1\n";
        $o .= "  %search = icmp ne i32 %sbit, 0\n";
        $o .= "  br i1 %search, label %phase1, label %phase2\n";
        $o .= "phase1:\n";
        $o .= "  br i1 %handler, label %hfound, label %cont\n";
        $o .= "hfound:\n";
        $o .= "  ret i32 6\n";
        $o .= "phase2:\n";
        $o .= "  %cleanup = xor i1 %iscatch, true\n";
        $o .= "  %go = or i1 %handler, %cleanup\n";
        $o .= "  br i1 %go, label %install, label %cont\n";
        $o .= "install:\n";
        $o .= "  %exi = ptrtoint ptr %ex to i64\n";
        $o .= "  call void @_Unwind_SetGR(ptr %ctx, i32 0, i64 %exi)\n";
        $o .= "  %sel = zext i1 %iscatch to i64\n";
        $o .= "  call void @_Unwind_SetGR(ptr %ctx, i32 1, i64 %sel)\n";
        $o .= "  %tgt = add i64 %fs, %lp\n";
        $o .= "  call void @_Unwind_SetIP(ptr %ctx, i64 %tgt)\n";
        $o .= "  ret i32 7\n";
        $o .= "cont:\n";
        $o .= "  ret i32 8\n}\n";
        return $o;
    }

    /**
     * The landing pad of a PHP catch region: the pad label, the `landingpad`
     * and the payload move into `@__mir_thrown`. The caller continues the
     * block with its dispatch / pending-finally bookkeeping.
     */
    public static function catchPad(string $label, string $lpReg, string $exReg, string $objReg): string
    {
        return $label . ":\n"
            . '  ' . $lpReg . " = landingpad { ptr, i32 } catch ptr @__mc_typeinfo\n"
            . '  ' . $exReg . ' = extractvalue { ptr, i32 } ' . $lpReg . ", 0\n"
            . '  ' . $objReg . ' = call ptr @__mc_eh_catch(ptr ' . $exReg . ")\n";
    }

    /** The personality clause appended to a `define` that holds a landing pad. */
    public const PERSONALITY = ' personality ptr @__mc_personality';
}
