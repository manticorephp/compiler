<?php

namespace Compile\Runtime;

/**
 * Exception runtime of the error-return model (docs/design/error-return-exceptions.md).
 *
 * One process-wide slot `@__mc_exc` holds the Throwable in flight; every call
 * that may throw is followed by a test of it ({@see \Compile\Mir\EhLowerIr},
 * which also defines the slot in every module). The emitters still write
 * `invoke` / `landingpad` / `resume`; that pass lowers them.
 *
 * - `@__mc_throw(obj)` puts the Throwable (+1, owned by the slot) in the slot.
 * - `@__mc_eh_catch(obj)` is the first call of every PHP catch pad: the pad has
 *   taken the object out of the slot; it moves into `@__mir_thrown`.
 * - `@__mc_uncaught(obj)` is where `@main`'s propagate block ends: every frame
 *   above ran its cleanups on the way back, as php destroys the unwound locals
 *   before the fatal; it runs the uncaught-exception fatal `@main` installed in
 *   `@__mc_uncaught_fn`.
 *
 * The catch-class dispatch stays in the pad ({@see \Compile\Mir\Passes\EmitLlvmExceptions}).
 */
final class ExceptionRuntime
{
    /** Module-level IR: the fatal's hook and the helpers. */
    public static function ir(): string
    {
        $o = "@__mc_uncaught_fn = linkonce_odr global ptr null\n";
        $o .= "define void @__mc_throw(ptr %obj) {\nentry:\n";
        $o .= "  store ptr %obj, ptr @__mc_exc\n";
        $o .= "  ret void\n}\n";

        $o .= "define void @__mc_uncaught(ptr %obj) cold noinline {\nentry:\n";
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

        $o .= "define ptr @__mc_eh_catch(ptr %obj) {\nentry:\n";
        $o .= "  store ptr %obj, ptr @__mir_thrown\n";
        $o .= "  ret ptr %obj\n}\n";
        return $o;
    }

    /**
     * The landing pad of a PHP catch region: the pad label, the `landingpad`
     * and the Throwable moved into `@__mir_thrown`. The caller continues the
     * block with its dispatch / pending-finally bookkeeping.
     */
    public static function catchPad(string $label, string $lpReg, string $exReg, string $objReg): string
    {
        return $label . ":\n"
            . '  ' . $lpReg . " = landingpad { ptr, i32 } catch ptr null\n"
            . '  ' . $exReg . ' = extractvalue { ptr, i32 } ' . $lpReg . ", 0\n"
            . '  ' . $objReg . ' = call ptr @__mc_eh_catch(ptr ' . $exReg . ")\n";
    }
}
