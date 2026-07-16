<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\Block;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\BoolConst;
use Compile\Mir\MethodCall_;
use Compile\Mir\NewObj;
use Compile\Mir\Clone_;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StaticCall_;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RuntimeFeatures;
use Compile\Mir\StringPool;
use Compile\Mir\SsaBuilder;
use Compile\Mir\GeneratorContext;
use Compile\Mir\ControlFlow;
use Compile\Mir\FunctionEmitFrame;
use Compile\Mir\FunctionSignatures;
use Compile\Mir\ArenaContext;
use Compile\Mir\LocalSlots;
use Compile\Mir\RuntimeLibrary;
use Compile\Mir\EmitVisitor;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\MemoryOp_;
use Compile\Mir\Yield_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Pass;
use Compile\Mir\Return_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\While_;
use Compile\Runtime\BareHost;
use Compile\Runtime\UnifiedArrayRuntime;
use Codegen\Llvm\Module as LlvmModule;

/**
 * Value emission: arithmetic, comparison, casts, concatenation, and the
 * box / unbox / coercion machinery that moves a value between its raw form and a
 * tagged cell.
 *
 * A trait on the one {@see EmitLlvm} host — the split is by concern, so a reader
 * opens the file for the thing they are looking at instead of scrolling one
 * 8k-line class. State stays on the host and its collaborators.
 */
trait EmitLlvmExpr
{
    /**
     * String runtime for `.`:
     *   __mir_int_to_str(i64) -> ptr   — decimal text via snprintf
     *   __mir_concat(ptr,ptr) -> ptr   — strlen+malloc+memcpy×2 (+NUL)
     * Keys/strings are NUL-terminated C strings, matching echo (%s)
     * and the assoc strcmp keys.
     */
    /**
     * NaN-boxing helpers (subset). 48-bit payload, 4-bit tag at bit 48,
     * NaN header 0xFFF0000000000000. TAG_INT=1. Mirrors the AST
     * backend's TaggedValues so box/unbox/tag round-trip identically.
     */
    /**
     * `__manticore_box_int` / `__manticore_unbox_int` — int↔cell boxing. An int
     * in [-2^47, 2^47) fits the 48-bit payload (tag INT=1); a WIDER int is
     * heap-boxed (malloc 8, store the full i64) and tagged BIGINT=5 (tagBits
     * 0xFFF5.. = -3096224743817216), so a 64-bit int survives a cell round-trip.
     * The 8-byte cell is immortal (ints carry no rc) — a bounded leak for the
     * rare large-int-in-cell case. Emitted under a broad gate (boxIntRuntime is
     * called by the render helpers too — they call unbox_int for the int arm).
     */
    private function boxIntRuntime(): string
    {
        $out  = "\ndefine i64 @__manticore_box_int(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %s = shl i64 %v, 16\n";
        $out .= "  %se = ashr i64 %s, 16\n";
        $out .= "  %fits = icmp eq i64 %se, %v\n";
        $out .= "  br i1 %fits, label %inl, label %heap\n";
        $out .= "inl:\n";
        $out .= "  %m = and i64 %v, 281474976710655\n";
        $out .= "  %b = or i64 %m, -4222124650659840\n";
        $out .= "  ret i64 %b\n";
        $out .= "heap:\n";
        $out .= "  %p = call ptr @malloc(i64 8)\n";
        $out .= "  store i64 %v, ptr %p\n";
        $out .= "  %pi = ptrtoint ptr %p to i64\n";
        $out .= "  %pm = and i64 %pi, 281474976710655\n";
        $out .= "  %pb = or i64 %pm, -3096224743817216\n";
        $out .= "  ret i64 %pb\n";
        $out .= "}\n";
        $out .= "define i64 @__manticore_unbox_int(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %sh = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %sh, 15\n";
        $out .= "  %big = icmp eq i64 %nib, 5\n";
        $out .= "  br i1 %big, label %fromheap, label %inl\n";
        $out .= "fromheap:\n";
        $out .= "  %pm = and i64 %v, 281474976710655\n";
        $out .= "  %hp = inttoptr i64 %pm to ptr\n";
        $out .= "  %hv = load i64, ptr %hp\n";
        $out .= "  ret i64 %hv\n";
        $out .= "inl:\n";
        $out .= "  %s = shl i64 %v, 16\n";
        $out .= "  %r = ashr i64 %s, 16\n";
        $out .= "  ret i64 %r\n";
        $out .= "}\n";
        return $out;
    }

    private function taggedRuntime(): string
    {
        // PAYLOAD_MASK = 0xFFFFFFFFFFFF = 281474976710655
        // tagBits(int) = (1<<48)|0xFFF0000000000000 = -4222124650659840
        // box_int/unbox_int live in boxIntRuntime() (broader gate).
        $out = '';
        // __manticore_tag: a tagged cell has header bits > 0xFFF0000000000000
        // (int=0xFFF1 … object=0xFFF8); anything else (a finite double, ±Inf,
        // canonical NaN) is a raw double → synthetic FLOAT tag 6.
        $out .= "define i64 @__manticore_tag(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %s = lshr i64 %v, 48\n";
        $out .= "  %nibr = and i64 %s, 15\n";
        // BIGINT (nibble 5, a heap-boxed int) is an INT for every tag consumer.
        $out .= "  %is5 = icmp eq i64 %nibr, 5\n";
        $out .= "  %nib = select i1 %is5, i64 1, i64 %nibr\n";
        $out .= "  %t = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  ret i64 %t\n";
        $out .= "}\n";
        // box_bool: (v & 1) | tagBits(BOOL=2)
        $out .= "define i64 @__manticore_box_bool(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %m = and i64 %v, 1\n";
        $out .= "  %b = or i64 %m, -3940649673949184\n";
        $out .= "  ret i64 %b\n";
        $out .= "}\n";
        // box_null: pure header + tag(NULL=3)
        $out .= "define i64 @__manticore_box_null() {\n";
        $out .= "entry:\n";
        $out .= "  ret i64 -3659174697238528\n";
        $out .= "}\n";
        // box_ptr: (ptrtoint(p) & PAYLOAD_MASK) | tagBits(PTR=4). A 0 pointer
        // can only be a `?string` null (a real string ptr is never 0) → box as
        // NULL so var_dump / echo / json_encode of a null `?T` don't deref 0.
        $out .= "define i64 @__manticore_box_ptr(ptr %p) {\n";
        $out .= "entry:\n";
        $out .= "  %i = ptrtoint ptr %p to i64\n";
        $out .= "  %nz = icmp eq i64 %i, 0\n";
        $out .= "  br i1 %nz, label %nul, label %box\n";
        $out .= "nul:\n";
        $out .= "  ret i64 -3659174697238528\n";
        $out .= "box:\n";
        $out .= "  %m = and i64 %i, 281474976710655\n";
        $out .= "  %b = or i64 %m, -3377699720527872\n";
        $out .= "  ret i64 %b\n";
        $out .= "}\n";
        // box_float: canonical NaN-boxing — a real double IS its own 64 bits
        // (lossless), stored raw. A NaN is canonicalized to the quiet-NaN
        // 0x7FF8000000000000 so it can never collide with a tagged cell (whose
        // header is 0xFFF1..0xFFF8, all > 0xFFF0000000000000). Tag dispatch
        // (see __manticore_tag) treats any i64 NOT in the tagged range as a
        // raw double → synthetic FLOAT tag 6.
        $out .= "define i64 @__manticore_box_float(double %f) {\n";
        $out .= "entry:\n";
        $out .= "  %i = bitcast double %f to i64\n";
        $out .= "  %isnan = fcmp uno double %f, %f\n";
        $out .= "  %b = select i1 %isnan, i64 9221120237041090560, i64 %i\n";
        $out .= "  ret i64 %b\n";
        $out .= "}\n";
        // box_array: (ptrtoint(p) & PAYLOAD_MASK) | tagBits(ARRAY=7). 0 → NULL
        // (a `?array` null; a real array ptr is never 0).
        $out .= "define i64 @__manticore_box_array(ptr %p) {\n";
        $out .= "entry:\n";
        $out .= "  %i = ptrtoint ptr %p to i64\n";
        $out .= "  %nz = icmp eq i64 %i, 0\n";
        $out .= "  br i1 %nz, label %nul, label %box\n";
        $out .= "nul:\n";
        $out .= "  ret i64 -3659174697238528\n";
        $out .= "box:\n";
        $out .= "  %m = and i64 %i, 281474976710655\n";
        $out .= "  %b = or i64 %m, -2533274790395904\n";
        $out .= "  ret i64 %b\n";
        $out .= "}\n";
        // box_object: (ptrtoint(p) & PAYLOAD_MASK) | tagBits(OBJECT=8). 0 → NULL
        // (a `?Obj` null; a real object ptr is never 0).
        $out .= "define i64 @__manticore_box_object(ptr %p) {\n";
        $out .= "entry:\n";
        $out .= "  %i = ptrtoint ptr %p to i64\n";
        $out .= "  %nz = icmp eq i64 %i, 0\n";
        $out .= "  br i1 %nz, label %nul, label %box\n";
        $out .= "nul:\n";
        $out .= "  ret i64 -3659174697238528\n";
        $out .= "box:\n";
        $out .= "  %m = and i64 %i, 281474976710655\n";
        $out .= "  %b = or i64 %m, -2251799813685248\n";
        $out .= "  ret i64 %b\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * Emit IR computing the 4-bit cell tag of $v into a fresh SSA reg, with
     * canonical-NaN-boxing semantics: an i64 NOT in the tagged range
     * (> 0xFFF0000000000000) is a raw double → synthetic FLOAT tag 6. Mirrors
     * {@see taggedRuntime}'s `__manticore_tag` for the inline cell-tag checks
     * (instanceof / `=== null` / `=== false` / string-compare) so a raw-double
     * cell is never misread as a tagged value of a colliding nibble. The
     * computed tag SSA reg is left in {@see $cellTagReg} (no by-ref out-param —
     * that pattern miscompiles under self-host).
     */
    private function cellTagIr(string $v): string
    {
        $istag = $this->ssa->allocReg();
        $ts = $this->ssa->allocReg();
        $nib = $this->ssa->allocReg();
        $tag = $this->ssa->allocReg();
        $this->cellTagReg = $tag;
        $nibr = $this->ssa->allocReg();
        $is5 = $this->ssa->allocReg();
        return '  ' . $istag . ' = icmp ugt i64 ' . $v . ", -4503599627370496\n"
            . '  ' . $ts . ' = lshr i64 ' . $v . ", 48\n"
            . '  ' . $nibr . ' = and i64 ' . $ts . ", 15\n"
            . '  ' . $is5 . ' = icmp eq i64 ' . $nibr . ", 5\n"
            . '  ' . $nib . ' = select i1 ' . $is5 . ', i64 1, i64 ' . $nibr . "\n"
            . '  ' . $tag . ' = select i1 ' . $istag . ', i64 ' . $nib . ", i64 6\n";
    }

    /**
     * Runtime dispatch for a `mixed`/cell array key (e.g. ArrayAccess
     * `offsetGet/Set` with a `mixed $key`). A PHP array key is int OR string,
     * decided at runtime; the static get/set/isset/unset helpers are typed, so
     * branch on the cell tag (PTR=4 → string key, else int) and route to the
     * matching typed helper. Tag/unbox math is inlined so these never depend on
     * the `needsTagged`-gated box helpers — only the always-emitted array
     * runtime. Keys carry no rc here (offset interning is the array's concern).
     */
    private function cellKeyRuntime(): string
    {
        // PAYLOAD_MASK = 281474976710655; PTR/string tag = 4.
        $out  = "\ndefine ptr @__mir_array_set_cell(ptr %arr, i64 %k, i64 %val) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %k, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %k, 48\n  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  %isstr = icmp eq i64 %tag, 4\n";
        $out .= "  br i1 %isstr, label %s, label %i\n";
        $out .= "s:\n  %pp = and i64 %k, 281474976710655\n  %kp = inttoptr i64 %pp to ptr\n";
        $out .= "  %r1 = call ptr @__mir_array_set_str(ptr %arr, ptr %kp, i64 %val, i64 0, i64 0)\n  ret ptr %r1\n";
        $out .= "i:\n  %sh = shl i64 %k, 16\n  %ki = ashr i64 %sh, 16\n";
        $out .= "  %r2 = call ptr @__mir_array_set_int(ptr %arr, i64 %ki, i64 %val)\n  ret ptr %r2\n}\n";

        $out .= "define i64 @__mir_array_get_cell(ptr %arr, i64 %k) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %k, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %k, 48\n  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  %isstr = icmp eq i64 %tag, 4\n";
        $out .= "  br i1 %isstr, label %s, label %i\n";
        $out .= "s:\n  %pp = and i64 %k, 281474976710655\n  %kp = inttoptr i64 %pp to ptr\n";
        $out .= "  %r1 = call i64 @__mir_array_get_str(ptr %arr, ptr %kp, i64 0, i64 0)\n  ret i64 %r1\n";
        $out .= "i:\n  %sh = shl i64 %k, 16\n  %ki = ashr i64 %sh, 16\n";
        $out .= "  %r2 = call i64 @__mir_array_get_int(ptr %arr, i64 %ki)\n  ret i64 %r2\n}\n";

        $out .= "define i64 @__mir_array_isset_cell(ptr %arr, i64 %k) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %k, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %k, 48\n  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  %isstr = icmp eq i64 %tag, 4\n";
        $out .= "  br i1 %isstr, label %s, label %i\n";
        $out .= "s:\n  %pp = and i64 %k, 281474976710655\n  %kp = inttoptr i64 %pp to ptr\n";
        $out .= "  %r1 = call i64 @__mir_array_isset_str(ptr %arr, ptr %kp, i64 0, i64 0)\n  ret i64 %r1\n";
        $out .= "i:\n  %sh = shl i64 %k, 16\n  %ki = ashr i64 %sh, 16\n";
        $out .= "  %r2 = call i64 @__mir_array_isset_int(ptr %arr, i64 %ki)\n  ret i64 %r2\n}\n";

        $out .= "define void @__mir_array_unset_cell(ptr %arr, i64 %k) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %k, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %k, 48\n  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  %isstr = icmp eq i64 %tag, 4\n";
        $out .= "  br i1 %isstr, label %s, label %i\n";
        $out .= "s:\n  %pp = and i64 %k, 281474976710655\n  %kp = inttoptr i64 %pp to ptr\n";
        $out .= "  call void @__mir_array_unset_str(ptr %arr, ptr %kp)\n  ret void\n";
        $out .= "i:\n  %sh = shl i64 %k, 16\n  %ki = ashr i64 %sh, 16\n";
        $out .= "  call void @__mir_array_unset_int(ptr %arr, i64 %ki)\n  ret void\n}\n";
        return $out;
    }

    /**
     * `echo` of a NaN-boxed cell — dispatch on the 4-bit tag and print
     * with PHP echo semantics: int decimal, float %g, true → "1",
     * false / null → nothing, ptr (string) → %s.
     */
    private function taggedEchoRuntime(): string
    {
        $out  = "\n@.tagstr.true = private unnamed_addr constant [2 x i8] c\"1\\00\", align 1\n";
        $out .= "@.tagstr.array = private unnamed_addr constant [6 x i8] c\"Array\\00\", align 1\n";
        $out .= "define void @__manticore_echo_tagged(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  switch i64 %tag, label %asint [\n";
        $out .= "    i64 2, label %asbool\n";
        $out .= "    i64 3, label %asnull\n";
        $out .= "    i64 4, label %asptr\n";
        $out .= "    i64 6, label %asfloat\n";
        $out .= "    i64 7, label %asarray\n";
        $out .= "  ]\n";
        $out .= "asarray:\n";
        $out .= "  call i32 (ptr, ...) @printf(ptr @.fmt.s, ptr @.tagstr.array)\n";
        $out .= "  ret void\n";
        $out .= "asint:\n";
        $out .= "  %i = call i64 @__manticore_unbox_int(i64 %v)\n";
        $out .= "  call i32 (ptr, ...) @printf(ptr @.fmt.d, i64 %i)\n";
        $out .= "  ret void\n";
        $out .= "asbool:\n";
        $out .= "  %bb = and i64 %v, 1\n";
        $out .= "  %istrue = icmp ne i64 %bb, 0\n";
        $out .= "  br i1 %istrue, label %bt, label %bdone\n";
        $out .= "bt:\n";
        $out .= "  call i32 (ptr, ...) @printf(ptr @.fmt.s, ptr @.tagstr.true)\n";
        $out .= "  ret void\n";
        $out .= "bdone:\n";
        $out .= "  ret void\n";
        $out .= "asnull:\n";
        $out .= "  ret void\n";
        $out .= "asptr:\n";
        $out .= "  %pp = and i64 %v, 281474976710655\n";
        $out .= "  %p = inttoptr i64 %pp to ptr\n";
        $out .= "  call i32 (ptr, ...) @printf(ptr @.fmt.s, ptr %p)\n";
        $out .= "  ret void\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        // PHP-formatted (echo scientific form is "1.0E+20", not C's "1e+20").
        $out .= "  %ffs = call ptr @__mir_float_to_str(double %fd)\n";
        $out .= "  call i32 (ptr, ...) @printf(ptr @.fmt.s, ptr %ffs)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %ffs)\n";
        $out .= "  ret void\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `(string)` of a NaN-boxed cell → a fresh NUL-terminated string.
     * int → decimal, float → %.14g, true → "1", false/null → "", ptr →
     * the string itself, array → "Array".
     */
    private function taggedToStrRuntime(): string
    {
        $this->rt->needsIntStr = true;
        $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
        $this->libcExtra['malloc'] = 'declare ptr @malloc(i64)';
        // Headered so a (string)-cast result (which may be one of these
        // literals or a heap int/float string) is safe to retain/release.
        $out  = "\n" . $this->strGlobalDef('@.ts.one', '1');
        $out .= $this->strGlobalDef('@.ts.empty', '');
        $out .= $this->strGlobalDef('@.ts.array', 'Array');
        $out .= "define ptr @__manticore_tagged_to_str(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  switch i64 %tag, label %asint [\n";
        $out .= "    i64 2, label %asbool\n";
        $out .= "    i64 3, label %asnull\n";
        $out .= "    i64 4, label %asptr\n";
        $out .= "    i64 6, label %asfloat\n";
        $out .= "    i64 7, label %asarray\n";
        $out .= "  ]\n";
        $out .= "asint:\n";
        $out .= "  %i = call i64 @__manticore_unbox_int(i64 %v)\n";
        $out .= "  %is = call ptr @__mir_int_to_str(i64 %i)\n";
        $out .= "  ret ptr %is\n";
        $out .= "asbool:\n";
        $out .= "  %bb = and i64 %v, 1\n";
        $out .= "  %istrue = icmp ne i64 %bb, 0\n";
        $out .= "  %bsel = select i1 %istrue, ptr " . $this->strSymBytes('@.ts.one')
              . ", ptr " . $this->strSymBytes('@.ts.empty') . "\n";
        $out .= "  ret ptr %bsel\n";
        $out .= "asnull:\n";
        $out .= "  ret ptr " . $this->strSymBytes('@.ts.empty') . "\n";
        $out .= "asptr:\n";
        $out .= "  %pp = and i64 %v, 281474976710655\n";
        $out .= "  %pptr = inttoptr i64 %pp to ptr\n";
        $out .= "  ret ptr %pptr\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        // PHP `(string)` scientific form is "1.0E+20", not C's "1e+20".
        $out .= "  %fbuf = call ptr @__mir_float_to_str(double %fd)\n";
        $out .= "  ret ptr %fbuf\n";
        $out .= "asarray:\n";
        $out .= "  ret ptr " . $this->strSymBytes('@.ts.array') . "\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `(int)$cell` — convert a NaN-boxed value to i64 by tag: int → the payload
     * int, bool → 0/1, null → 0, string → strtol(base 10), float → truncate,
     * array → 1 if non-empty else 0 (PHP semantics). Objects don't reach here
     * (PHP forbids the cast). Mirrors {@see taggedToStrRuntime}.
     */
    private function taggedToIntRuntime(): string
    {
        $this->rt->needsStrtol = true;
        $out  = "\ndefine i64 @__manticore_tagged_to_int(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  switch i64 %tag, label %asint [\n";
        $out .= "    i64 2, label %asbool\n";
        $out .= "    i64 3, label %asnull\n";
        $out .= "    i64 4, label %asstr\n";
        $out .= "    i64 6, label %asfloat\n";
        $out .= "    i64 7, label %asarray\n";
        $out .= "  ]\n";
        $out .= "asint:\n";
        $out .= "  %i = call i64 @__manticore_unbox_int(i64 %v)\n";
        $out .= "  ret i64 %i\n";
        $out .= "asbool:\n";
        $out .= "  %bb = and i64 %v, 1\n";
        $out .= "  ret i64 %bb\n";
        $out .= "asnull:\n";
        $out .= "  ret i64 0\n";
        $out .= "asstr:\n";
        $out .= "  %sp = and i64 %v, 281474976710655\n";
        $out .= "  %sptr = inttoptr i64 %sp to ptr\n";
        $out .= "  %sv = call i64 @strtol(ptr %sptr, ptr null, i32 10)\n";
        $out .= "  ret i64 %sv\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        $out .= "  %fi = fptosi double %fd to i64\n";
        $out .= "  ret i64 %fi\n";
        $out .= "asarray:\n";
        $out .= "  %ap = and i64 %v, 281474976710655\n";
        $out .= "  %aptr = inttoptr i64 %ap to ptr\n";
        $out .= "  %alen = load i64, ptr %aptr\n";
        $out .= "  %ane = icmp ne i64 %alen, 0\n";
        $out .= "  %az = zext i1 %ane to i64\n";
        $out .= "  ret i64 %az\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * NaN-boxed cell → double (numeric context for float arithmetic / `/`).
     * int → sitofp, bool → 0/1, null → 0.0, string → strtod, float → its bits,
     * array → non-empty?1:0. Mirrors {@see taggedToIntRuntime} but yields a
     * double so `$x / 2` and float arithmetic over a cell operand are exact
     * instead of bitcasting the tagged bits.
     */
    private function taggedToFloatRuntime(): string
    {
        $out  = "\ndefine double @__manticore_tagged_to_double(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  switch i64 %tag, label %asint [\n";
        $out .= "    i64 2, label %asbool\n";
        $out .= "    i64 3, label %asnull\n";
        $out .= "    i64 4, label %asstr\n";
        $out .= "    i64 6, label %asfloat\n";
        $out .= "    i64 7, label %asarray\n";
        $out .= "  ]\n";
        $out .= "asint:\n";
        $out .= "  %i = call i64 @__manticore_unbox_int(i64 %v)\n";
        $out .= "  %id = sitofp i64 %i to double\n";
        $out .= "  ret double %id\n";
        $out .= "asbool:\n";
        $out .= "  %bb = and i64 %v, 1\n";
        $out .= "  %bd = sitofp i64 %bb to double\n";
        $out .= "  ret double %bd\n";
        $out .= "asnull:\n";
        $out .= "  ret double 0.0\n";
        $out .= "asstr:\n";
        $out .= "  %sp = and i64 %v, 281474976710655\n";
        $out .= "  %sptr = inttoptr i64 %sp to ptr\n";
        $out .= "  %sv = call double @strtod(ptr %sptr, ptr null)\n";
        $out .= "  ret double %sv\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        $out .= "  ret double %fd\n";
        $out .= "asarray:\n";
        $out .= "  %ap = and i64 %v, 281474976710655\n";
        $out .= "  %aptr = inttoptr i64 %ap to ptr\n";
        $out .= "  %alen = load i64, ptr %aptr\n";
        $out .= "  %ane = icmp ne i64 %alen, 0\n";
        $out .= "  %az = uitofp i1 %ane to double\n";
        $out .= "  ret double %az\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `__manticore_tagged_compare(a, b) -> i64` (-1 / 0 / +1) — a runtime,
     * tag-dispatched ordering of two NaN-boxed cells (PHP-ish `<=>` semantics for
     * the common homogeneous cases). Both string → strcmp; both int → signed int
     * compare (no double-precision loss on large ints); otherwise numeric compare
     * via the double promotion. Used when both operands of an ordering compare are
     * statically CELL (guaranteed boxed) — e.g. sorting `array_keys` output or an
     * erased mixed array, where a raw int compare would order string keys by
     * pointer. Callers do `icmp <pred> result, 0`.
     */
    /** True when any class in the module extends $cls (transitively). A variable
     *  typed `C` may then hold a subclass at runtime, whose class identity and
     *  extra properties the static property unroll can't see — PHP compares the
     *  RUNTIME classes, so the unroll would be a guess. */
    private function classHasSubclass(string $cls): bool
    {
        foreach ($this->classes as $cd) {
            $p = $cd->parent;
            $depth = 0;
            while ($p !== '' && $depth < 32) {
                if ($p === $cls) { return true; }
                $pd = $this->classes[$p] ?? null;
                if ($pd === null) { break; }
                $p = $pd->parent;
                $depth = $depth + 1;
            }
        }
        return false;
    }

    /**
     * Tag a raw value as a cell WITHOUT rebuilding an array's elements, unlike
     * boxToCell (which deep-copies a raw vec into a cell vec). Sound only where
     * the consumer can't inspect the elements — PHP's mixed rows (array vs bool,
     * array vs scalar) settle on emptiness or on "an array is greater" and never
     * look inside. `$this->lastValue` carries the operand in / the cell out.
     */
    private function shallowBoxToCell(Type $t): string
    {
        $this->rt->needsTagged = true;
        return match ($t->kind) {
            Type::KIND_CELL, Type::KIND_UNKNOWN => $this->coerceToI64(),
            Type::KIND_ARRAY  => $this->shallowBoxCall('ptr', '@__manticore_box_array'),
            Type::KIND_OBJ    => $this->shallowBoxCall('ptr', '@__manticore_box_object'),
            Type::KIND_STRING => $this->shallowBoxCall('ptr', '@__manticore_box_ptr'),
            Type::KIND_FLOAT  => $this->shallowBoxCall('double', '@__manticore_box_float'),
            Type::KIND_BOOL   => $this->shallowBoxCall('i64', '@__manticore_box_bool'),
            default           => $this->shallowBoxCall('i64', '@__manticore_box_int'),
        };
    }

    private function shallowBoxCall(string $argTy, string $fn): string
    {
        $out = $argTy === 'ptr' ? $this->coerceToPtr()
            : ($argTy === 'double' ? $this->coerceTo('double') : $this->coerceToI64());
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 ' . $fn . '(' . $argTy . ' ' . $this->lastValue . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * Element-kind tags for the array compare runtime. An array's element
     * REPRESENTATION is a compile-time property (a `vec[int]` holds raw i64s, a
     * `vec[cell]` holds NaN-boxed cells), and a raw element can NOT be
     * tag-inspected — a large or negative int masquerades as a boxed pointer
     * (the same trap __mir_array_copy_cells documents). So each call site passes
     * the kind of each side's elements and the runtime normalizes to a cell.
     */
    private const EK_CELL   = 0;
    private const EK_INT    = 1;
    private const EK_FLOAT  = 2;
    private const EK_STRING = 3;
    private const EK_BOOL   = 4;
    /** The element is a RAW nested array; the next nibble up describes ITS
     *  elements (a `vec[vec[int]]` is 5 | (EK_INT << 4)). A raw inner array
     *  can't be recovered from a tag, so the chain carries every level. */
    private const EK_ARRAY  = 5;
    /** Not a representation the array compare runtime can normalize. */
    private const EK_NONE   = -1;

    /**
     * Encode a static element type as a chain of 4-bit {@see EK_CELL} kinds
     * (LSB = this level) for the array compare runtime, or {@see EK_NONE} when
     * some level isn't a representation the runtime can normalize (an object
     * element in a RAW array) — the caller then keeps the old pointer-identity
     * path. Returns a SENTINEL rather than null on purpose: a `?int` local is
     * typed `null|int`, and the native self-build leaves that slot un-zeroed, so
     * a `!== null` test reads garbage — it fired the array path on an `int`
     * property and handed __mir_array_loose_eq the raw value 1 as a pointer.
     */
    private function elemChainOf(?Type $t, int $depth = 0): int
    {
        if ($t === null || $depth > 12) {
            return self::EK_NONE;
        }
        if ($t->kind === Type::KIND_ARRAY) {
            $inner = $this->elemChainOf($t->element, $depth + 1);
            if ($inner === self::EK_NONE) { return self::EK_NONE; }
            return self::EK_ARRAY | ($inner << 4);
        }
        return match ($t->kind) {
            Type::KIND_CELL, Type::KIND_UNKNOWN => self::EK_CELL,
            Type::KIND_INT                      => self::EK_INT,
            Type::KIND_FLOAT                    => self::EK_FLOAT,
            Type::KIND_STRING                   => self::EK_STRING,
            Type::KIND_BOOL                     => self::EK_BOOL,
            default                             => self::EK_NONE,
        };
    }

    /**
     * Two element chains are comparable only if a RAW nested array on one side
     * meets a raw nested array on the other. A raw inner array facing a CELL
     * would be boxed and handed to the tagged comparator, which assumes the
     * "erased ⟹ cell" invariant and would read the raw side's elements as
     * cells. Reject that pairing and let the caller fall back.
     */
    private function chainsComparable(int $a, int $b): bool
    {
        for ($i = 0; $i < 16; $i++) {
            $na = ($a >> ($i * 4)) & 15;
            $nb = ($b >> ($i * 4)) & 15;
            if ($na === 0 && $nb === 0) {
                return true;
            }
            if (($na === self::EK_ARRAY) !== ($nb === self::EK_ARRAY)) {
                return false;
            }
            if ($na !== self::EK_ARRAY) {
                return true;
            }
        }
        return true;
    }

    /**
     * `__mir_elem_to_cell(v, ek) -> i64` — lift a raw array element to a cell so
     * the tagged comparators can dispatch on it. EK_CELL is the identity; a raw
     * double's bits are re-boxed through box_float so a signaling NaN can't
     * collide with the tagged range (0xFFF1..0xFFF8).
     */
    private function elemToCellRuntime(): string
    {
        $out  = "\ndefine i64 @__mir_elem_to_cell(i64 %v, i64 %ek) {\nentry:\n";
        $out .= "  %k = and i64 %ek, 15\n";
        $out .= "  switch i64 %k, label %cell [\n";
        $out .= "    i64 " . self::EK_INT    . ", label %asint\n";
        $out .= "    i64 " . self::EK_FLOAT  . ", label %asfloat\n";
        $out .= "    i64 " . self::EK_STRING . ", label %asstr\n";
        $out .= "    i64 " . self::EK_BOOL   . ", label %asbool\n";
        $out .= "    i64 " . self::EK_ARRAY  . ", label %asarr\n";
        $out .= "  ]\n";
        $out .= "cell:\n  ret i64 %v\n";
        $out .= "asarr:\n";
        $out .= "  %ap = inttoptr i64 %v to ptr\n";
        $out .= "  %ba = call i64 @__manticore_box_array(ptr %ap)\n  ret i64 %ba\n";
        $out .= "asint:\n";
        $out .= "  %bi = call i64 @__manticore_box_int(i64 %v)\n  ret i64 %bi\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        $out .= "  %bf = call i64 @__manticore_box_float(double %fd)\n  ret i64 %bf\n";
        $out .= "asstr:\n";
        $out .= "  %sp = inttoptr i64 %v to ptr\n";
        $out .= "  %bs = call i64 @__manticore_box_ptr(ptr %sp)\n  ret i64 %bs\n";
        $out .= "asbool:\n";
        $out .= "  %bb = call i64 @__manticore_box_bool(i64 %v)\n  ret i64 %bb\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * The array half of PHP's comparison table, over the unified array layout.
     *
     * `__mir_array_loose_eq(a, eka, b, ekb) -> i1` — PHP `==`: same count, and
     * every key of `a` present in `b` with a loosely-equal value. Key ORDER is
     * irrelevant (`['x'=>1,'y'=>2] == ['y'=>2,'x'=>1]`), so `b` is probed by key
     * rather than walked in step.
     *
     * `__mir_array_strict_eq(a, eka, b, ekb) -> i1` — PHP `===`: same count,
     * same key/value pairs in the SAME ORDER, compared strictly. Walks both in
     * step and compares key i against key i.
     *
     * `__mir_array_compare(a, eka, b, ekb) -> i64` — PHP `<`/`>`/`<=>`: fewer
     * members is smaller; at equal count the first key of `a` missing from `b`
     * makes them uncomparable (PHP answers 1), else the first non-zero element
     * comparison wins.
     *
     * All three recurse back into the tagged comparators for the elements, so a
     * nested `[[1,2],[3]]` compares by value at every level.
     */
    private function arrayCompareRuntime(): string
    {
        $mask = '281474976710655';
        $out  = $this->elemToCellRuntime();

        // Walk `a`, probing `b` by key. Shared by loose_eq (loose element
        // compare) and compare (ordering); $mode 'e' or 'c'.
        $walk = function (string $mode) use ($mask): string {
            $eq = $mode === 'e';
            $fn = $eq ? '__mir_array_loose_eq' : '__mir_array_compare';
            $ret = $eq ? 'i1' : 'i64';
            $no  = $eq ? 'ret i1 false' : 'ret i64 1';
            $out  = "\ndefine " . $ret . " @" . $fn . "(ptr %a, i64 %eka, ptr %b, i64 %ekb) {\nentry:\n";
            $out .= "  %anull = icmp eq ptr %a, null\n";
            $out .= "  %bnull = icmp eq ptr %b, null\n";
            $out .= "  %anynull = or i1 %anull, %bnull\n";
            $out .= "  br i1 %anynull, label %nulls, label %lens\n";
            $out .= "nulls:\n";
            $out .= "  %bothnull = and i1 %anull, %bnull\n";
            $out .= $eq
                ? "  ret i1 %bothnull\n"
                : "  %nres = select i1 %bothnull, i64 0, i64 1\n  ret i64 %nres\n";
            $out .= "lens:\n";
            $out .= "  %la = load i64, ptr %a\n";
            $out .= "  %lb = load i64, ptr %b\n";
            if ($eq) {
                $out .= "  %leneq = icmp eq i64 %la, %lb\n";
                $out .= "  br i1 %leneq, label %loop, label %no\n";
            } else {
                // PHP: the array with fewer members is the smaller one.
                $out .= "  %lenlt = icmp slt i64 %la, %lb\n";
                $out .= "  %lengt = icmp sgt i64 %la, %lb\n";
                $out .= "  %lendiff = or i1 %lenlt, %lengt\n";
                $out .= "  br i1 %lendiff, label %bylen, label %loop\n";
                $out .= "bylen:\n";
                $out .= "  %lres = select i1 %lenlt, i64 -1, i64 1\n  ret i64 %lres\n";
            }
            $out .= "loop:\n";
            $out .= "  %i = phi i64 [ 0, %lens ], [ %inext, %cont ]\n";
            $out .= "  %done = icmp sge i64 %i, %la\n";
            $out .= "  br i1 %done, label %yes, label %body\n";
            $out .= "body:\n";
            $out .= "  %k = call i64 @__mir_array_key_cell_at(ptr %a, i64 %i)\n";
            $out .= "  %rawa = call i64 @__mir_array_value_at(ptr %a, i64 %i)\n";
            $out .= "  %tk = call i64 @__manticore_tag(i64 %k)\n";
            $out .= "  %kisstr = icmp eq i64 %tk, 4\n";
            $out .= "  br i1 %kisstr, label %skey, label %ikey\n";
            $out .= "skey:\n";
            $out .= "  %kp = and i64 %k, " . $mask . "\n";
            $out .= "  %kpp = inttoptr i64 %kp to ptr\n";
            $out .= "  %hass = call i64 @__mir_array_isset_str(ptr %b, ptr %kpp)\n";
            $out .= "  %hassb = icmp ne i64 %hass, 0\n";
            $out .= "  br i1 %hassb, label %sget, label %no\n";
            $out .= "sget:\n";
            $out .= "  %rawb1 = call i64 @__mir_array_get_str(ptr %b, ptr %kpp)\n";
            $out .= "  br label %have\n";
            $out .= "ikey:\n";
            $out .= "  %ki = call i64 @__manticore_unbox_int(i64 %k)\n";
            $out .= "  %hasi = call i64 @__mir_array_isset_int(ptr %b, i64 %ki)\n";
            $out .= "  %hasib = icmp ne i64 %hasi, 0\n";
            $out .= "  br i1 %hasib, label %iget, label %no\n";
            $out .= "iget:\n";
            $out .= "  %rawb2 = call i64 @__mir_array_get_int(ptr %b, i64 %ki)\n";
            $out .= "  br label %have\n";
            // A RAW nested array on BOTH sides recurses one level down the chain
            // instead of going through the tagged comparator — box_array'ing it
            // would tell the tagged path the inner elements are cells, and they
            // are not. emitCmp's chainsComparable() rejects the mixed pairing,
            // so raw-vs-cell can't reach here.
            $out .= "have:\n";
            $out .= "  %rawb = phi i64 [ %rawb1, %sget ], [ %rawb2, %iget ]\n";
            $out .= "  %eka0 = and i64 %eka, 15\n";
            $out .= "  %ekb0 = and i64 %ekb, 15\n";
            $out .= "  %aisarr = icmp eq i64 %eka0, " . self::EK_ARRAY . "\n";
            $out .= "  %bisarr = icmp eq i64 %ekb0, " . self::EK_ARRAY . "\n";
            $out .= "  %bothrawarr = and i1 %aisarr, %bisarr\n";
            $out .= "  br i1 %bothrawarr, label %rec, label %viacell\n";
            $out .= "rec:\n";
            $out .= "  %reca = inttoptr i64 %rawa to ptr\n";
            $out .= "  %recb = inttoptr i64 %rawb to ptr\n";
            $out .= "  %eka1 = lshr i64 %eka, 4\n";
            $out .= "  %ekb1 = lshr i64 %ekb, 4\n";
            if ($eq) {
                $out .= "  %re = call i1 @" . $fn . "(ptr %reca, i64 %eka1, ptr %recb, i64 %ekb1)\n";
                $out .= "  br i1 %re, label %cont, label %no\n";
            } else {
                $out .= "  %rc = call i64 @" . $fn . "(ptr %reca, i64 %eka1, ptr %recb, i64 %ekb1)\n";
                $out .= "  %rcz = icmp eq i64 %rc, 0\n";
                $out .= "  br i1 %rcz, label %cont, label %recdiff\n";
                $out .= "recdiff:\n  ret i64 %rc\n";
            }
            $out .= "viacell:\n";
            $out .= "  %va = call i64 @__mir_elem_to_cell(i64 %rawa, i64 %eka)\n";
            $out .= "  %vb = call i64 @__mir_elem_to_cell(i64 %rawb, i64 %ekb)\n";
            if ($eq) {
                $out .= "  %e = call i64 @__manticore_tagged_loose_eq(i64 %va, i64 %vb)\n";
                $out .= "  %eb = icmp ne i64 %e, 0\n";
                $out .= "  br i1 %eb, label %cont, label %no\n";
            } else {
                $out .= "  %c = call i64 @__manticore_tagged_compare(i64 %va, i64 %vb)\n";
                $out .= "  %cz = icmp eq i64 %c, 0\n";
                $out .= "  br i1 %cz, label %cont, label %diff\n";
                $out .= "diff:\n  ret i64 %c\n";
            }
            $out .= "cont:\n";
            $out .= "  %inext = add i64 %i, 1\n";
            $out .= "  br label %loop\n";
            $out .= $eq ? "yes:\n  ret i1 true\n" : "yes:\n  ret i64 0\n";
            $out .= "no:\n  " . $no . "\n";
            $out .= "}\n";
            return $out;
        };
        $out .= $walk('e');
        $out .= $walk('c');

        // `===`: same length, same key/value pairs in the same ORDER.
        $out .= "\ndefine i1 @__mir_array_strict_eq(ptr %a, i64 %eka, ptr %b, i64 %ekb) {\nentry:\n";
        $out .= "  %anull = icmp eq ptr %a, null\n";
        $out .= "  %bnull = icmp eq ptr %b, null\n";
        $out .= "  %anynull = or i1 %anull, %bnull\n";
        $out .= "  br i1 %anynull, label %nulls, label %lens\n";
        $out .= "nulls:\n";
        $out .= "  %bothnull = and i1 %anull, %bnull\n  ret i1 %bothnull\n";
        $out .= "lens:\n";
        $out .= "  %la = load i64, ptr %a\n";
        $out .= "  %lb = load i64, ptr %b\n";
        $out .= "  %leneq = icmp eq i64 %la, %lb\n";
        $out .= "  br i1 %leneq, label %loop, label %no\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [ 0, %lens ], [ %inext, %cont ]\n";
        $out .= "  %done = icmp sge i64 %i, %la\n";
        $out .= "  br i1 %done, label %yes, label %body\n";
        $out .= "body:\n";
        $out .= "  %ka = call i64 @__mir_array_key_cell_at(ptr %a, i64 %i)\n";
        $out .= "  %kb = call i64 @__mir_array_key_cell_at(ptr %b, i64 %i)\n";
        $out .= "  %keq = call i64 @__manticore_tagged_strict_eq(i64 %ka, i64 %kb)\n";
        $out .= "  %keqb = icmp ne i64 %keq, 0\n";
        $out .= "  br i1 %keqb, label %vals, label %no\n";
        $out .= "vals:\n";
        $out .= "  %rawa = call i64 @__mir_array_value_at(ptr %a, i64 %i)\n";
        $out .= "  %rawb = call i64 @__mir_array_value_at(ptr %b, i64 %i)\n";
        $out .= "  %eka0 = and i64 %eka, 15\n";
        $out .= "  %ekb0 = and i64 %ekb, 15\n";
        $out .= "  %aisarr = icmp eq i64 %eka0, " . self::EK_ARRAY . "\n";
        $out .= "  %bisarr = icmp eq i64 %ekb0, " . self::EK_ARRAY . "\n";
        $out .= "  %bothrawarr = and i1 %aisarr, %bisarr\n";
        $out .= "  br i1 %bothrawarr, label %rec, label %viacell\n";
        $out .= "rec:\n";
        $out .= "  %reca = inttoptr i64 %rawa to ptr\n";
        $out .= "  %recb = inttoptr i64 %rawb to ptr\n";
        $out .= "  %eka1 = lshr i64 %eka, 4\n";
        $out .= "  %ekb1 = lshr i64 %ekb, 4\n";
        $out .= "  %re = call i1 @__mir_array_strict_eq(ptr %reca, i64 %eka1, ptr %recb, i64 %ekb1)\n";
        $out .= "  br i1 %re, label %cont, label %no\n";
        $out .= "viacell:\n";
        $out .= "  %va = call i64 @__mir_elem_to_cell(i64 %rawa, i64 %eka)\n";
        $out .= "  %vb = call i64 @__mir_elem_to_cell(i64 %rawb, i64 %ekb)\n";
        $out .= "  %veq = call i64 @__manticore_tagged_strict_eq(i64 %va, i64 %vb)\n";
        $out .= "  %veqb = icmp ne i64 %veq, 0\n";
        $out .= "  br i1 %veqb, label %cont, label %no\n";
        $out .= "cont:\n";
        $out .= "  %inext = add i64 %i, 1\n";
        $out .= "  br label %loop\n";
        $out .= "yes:\n  ret i1 true\n";
        $out .= "no:\n  ret i1 false\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * The shared head of PHP's comparison table, emitted into both
     * `tagged_loose_eq` ($mode 'e' → i64 0/1) and `tagged_compare` ($mode 'c'
     * → i64 -1/0/+1). Expects %ta/%tb (the operand tags) live, and falls
     * through to a `row4:` label the caller defines for the number/string row.
     * Row order is load-bearing and follows the PHP manual's table top-down:
     *   1. null <=> string   — NULL becomes "", compare as strings. MUST precede
     *      the bool row: `null == "0"` is FALSE ("" vs "0"), but the bool row
     *      would call both falsy and answer TRUE.
     *   2. bool|null <=> anything — both sides to bool, FALSE < TRUE.
     *   3. object <=> object, then object <=> anything (object is greater — this
     *      outranks the array row, so an object beats an array).
     *   4. array <=> array, then array <=> anything (array is greater).
     * Everything else falls through to row4 (numbers, strings, numeric strings).
     */
    private function juggleRows(string $mode): string
    {
        $eq = $mode === 'e';
        $mask = '281474976710655';
        // Row 1 — null <=> string.
        $out  = "  %r1a = icmp eq i64 %ta, 3\n";
        $out .= "  %r1b = icmp eq i64 %tb, 4\n";
        $out .= "  %r1ab = and i1 %r1a, %r1b\n";
        $out .= "  br i1 %r1ab, label %nullstrB, label %chk1b\n";
        $out .= "chk1b:\n";
        $out .= "  %r1c = icmp eq i64 %ta, 4\n";
        $out .= "  %r1d = icmp eq i64 %tb, 3\n";
        $out .= "  %r1cd = and i1 %r1c, %r1d\n";
        $out .= "  br i1 %r1cd, label %nullstrA, label %row2\n";
        $out .= "nullstrB:\n";
        $out .= "  %nsb = and i64 %b, $mask\n";
        $out .= "  %nsbp = inttoptr i64 %nsb to ptr\n";
        $out .= "  %nsbl = call i64 @__mir_strlen(ptr %nsbp)\n";
        $out .= "  %nsbz = icmp eq i64 %nsbl, 0\n";
        $out .= $eq
            ? "  %nsbr = zext i1 %nsbz to i64\n  ret i64 %nsbr\n"
            : "  %nsbr = select i1 %nsbz, i64 0, i64 -1\n  ret i64 %nsbr\n";
        $out .= "nullstrA:\n";
        $out .= "  %nsa = and i64 %a, $mask\n";
        $out .= "  %nsap = inttoptr i64 %nsa to ptr\n";
        $out .= "  %nsal = call i64 @__mir_strlen(ptr %nsap)\n";
        $out .= "  %nsaz = icmp eq i64 %nsal, 0\n";
        $out .= $eq
            ? "  %nsar = zext i1 %nsaz to i64\n  ret i64 %nsar\n"
            : "  %nsar = select i1 %nsaz, i64 0, i64 1\n  ret i64 %nsar\n";
        // Row 2 — bool|null <=> anything.
        $out .= "row2:\n";
        $out .= "  %r2a = icmp eq i64 %ta, 2\n";
        $out .= "  %r2b = icmp eq i64 %ta, 3\n";
        $out .= "  %r2c = icmp eq i64 %tb, 2\n";
        $out .= "  %r2d = icmp eq i64 %tb, 3\n";
        $out .= "  %r2ab = or i1 %r2a, %r2b\n";
        $out .= "  %r2cd = or i1 %r2c, %r2d\n";
        $out .= "  %r2any = or i1 %r2ab, %r2cd\n";
        $out .= "  br i1 %r2any, label %boolrow, label %rowobj\n";
        $out .= "boolrow:\n";
        $out .= "  %tva = call i64 @__manticore_tagged_truthy(i64 %a)\n";
        $out .= "  %tvb = call i64 @__manticore_tagged_truthy(i64 %b)\n";
        if ($eq) {
            $out .= "  %beq = icmp eq i64 %tva, %tvb\n";
            $out .= "  %bez = zext i1 %beq to i64\n  ret i64 %bez\n";
        } else {
            $out .= "  %blt = icmp slt i64 %tva, %tvb\n";
            $out .= "  %bgt = icmp sgt i64 %tva, %tvb\n";
            $out .= "  %bsel = select i1 %bgt, i64 1, i64 0\n";
            $out .= "  %bres = select i1 %blt, i64 -1, i64 %bsel\n  ret i64 %bres\n";
        }
        // Row 3 — objects. Ordered ABOVE the array row: PHP ranks an object
        // greater than everything, an array greater than everything else.
        $out .= "rowobj:\n";
        $out .= "  %aobj = icmp eq i64 %ta, 8\n";
        $out .= "  %bobj = icmp eq i64 %tb, 8\n";
        $out .= "  %bothobj = and i1 %aobj, %bobj\n";
        $out .= "  br i1 %bothobj, label %objboth, label %objmix\n";
        // No runtime property table exists (the descriptor is {class_id,
        // drop_fn}), so structural == on two objects reached through a CELL is
        // still a hole — identity is the sound subset. The statically-typed
        // path unrolls the real property compare in emitCmp.
        $out .= "objboth:\n";
        $out .= "  %oeq = icmp eq i64 %a, %b\n";
        $out .= $eq
            ? "  %oez = zext i1 %oeq to i64\n  ret i64 %oez\n"
            : "  %oez = select i1 %oeq, i64 0, i64 1\n  ret i64 %oez\n";
        $out .= "objmix:\n";
        $out .= "  %anyobj = or i1 %aobj, %bobj\n";
        $out .= "  br i1 %anyobj, label %objgreater, label %rowarr\n";
        $out .= "objgreater:\n";
        $out .= $eq
            ? "  ret i64 0\n"
            : "  %ogr = select i1 %aobj, i64 1, i64 -1\n  ret i64 %ogr\n";
        // Row 4 — arrays.
        $out .= "rowarr:\n";
        $out .= "  %aarr = icmp eq i64 %ta, 7\n";
        $out .= "  %barr = icmp eq i64 %tb, 7\n";
        $out .= "  %botharr = and i1 %aarr, %barr\n";
        $out .= "  br i1 %botharr, label %arrboth, label %arrmix\n";
        // Both operands arrive through a cell, so the "erased ⟹ cell" invariant
        // says their elements are boxed: element kind 0 on both sides.
        $out .= "arrboth:\n";
        $out .= "  %aap = and i64 %a, $mask\n";
        $out .= "  %aapp = inttoptr i64 %aap to ptr\n";
        $out .= "  %bap = and i64 %b, $mask\n";
        $out .= "  %bapp = inttoptr i64 %bap to ptr\n";
        if ($eq) {
            $out .= "  %are = call i1 @__mir_array_loose_eq(ptr %aapp, i64 0, ptr %bapp, i64 0)\n";
            $out .= "  %arez = zext i1 %are to i64\n  ret i64 %arez\n";
        } else {
            $out .= "  %arc = call i64 @__mir_array_compare(ptr %aapp, i64 0, ptr %bapp, i64 0)\n";
            $out .= "  ret i64 %arc\n";
        }
        $out .= "arrmix:\n";
        $out .= "  %anyarr = or i1 %aarr, %barr\n";
        $out .= "  br i1 %anyarr, label %arrgreater, label %row4\n";
        $out .= "arrgreater:\n";
        $out .= $eq
            ? "  ret i64 0\n"
            : "  %agr = select i1 %aarr, i64 1, i64 -1\n  ret i64 %agr\n";
        return $out;
    }

    private function taggedCompareRuntime(): string
    {
        $out  = "\ndefine i64 @__manticore_tagged_compare(i64 %a, i64 %b) {\n";
        $out .= "entry:\n";
        $out .= "  %ta = call i64 @__manticore_tag(i64 %a)\n";
        $out .= "  %tb = call i64 @__manticore_tag(i64 %b)\n";
        $out .= $this->juggleRows('c');
        $out .= "row4:\n";
        $out .= "  %as = icmp eq i64 %ta, 4\n";
        $out .= "  %bs = icmp eq i64 %tb, 4\n";
        $out .= "  %bothstr = and i1 %as, %bs\n";
        $out .= "  br i1 %bothstr, label %chknum, label %chkint\n";
        // Two strings: PHP compares them NUMERICALLY when both are numeric
        // ("10" > "9"), byte-wise otherwise ("abc" < "abd").
        $out .= "chknum:\n";
        $out .= "  %anum = call i1 @__mir_cell_numeric(i64 %a)\n";
        $out .= "  %bnum = call i1 @__mir_cell_numeric(i64 %b)\n";
        $out .= "  %bothnum = and i1 %anum, %bnum\n";
        $out .= "  br i1 %bothnum, label %fcmp, label %scmp\n";
        $out .= "scmp:\n";
        $out .= "  %pa = and i64 %a, 281474976710655\n";
        $out .= "  %ppa = inttoptr i64 %pa to ptr\n";
        $out .= "  %pb = and i64 %b, 281474976710655\n";
        $out .= "  %ppb = inttoptr i64 %pb to ptr\n";
        $out .= "  %sc = call i64 @__mir_str_cmp(ptr %ppa, ptr %ppb)\n";
        $out .= "  ret i64 %sc\n";
        $out .= "chkint:\n";
        $out .= "  %ai = icmp eq i64 %ta, 1\n";
        $out .= "  %bi = icmp eq i64 %tb, 1\n";
        $out .= "  %bothint = and i1 %ai, %bi\n";
        $out .= "  br i1 %bothint, label %icmp, label %fcmp\n";
        $out .= "icmp:\n";
        $out .= "  %ua = call i64 @__manticore_unbox_int(i64 %a)\n";
        $out .= "  %ub = call i64 @__manticore_unbox_int(i64 %b)\n";
        $out .= "  %ilt = icmp slt i64 %ua, %ub\n";
        $out .= "  %igt = icmp sgt i64 %ua, %ub\n";
        $out .= "  %isel = select i1 %igt, i64 1, i64 0\n";
        $out .= "  %ires = select i1 %ilt, i64 -1, i64 %isel\n";
        $out .= "  ret i64 %ires\n";
        $out .= "fcmp:\n";
        $out .= "  %da = call double @__manticore_tagged_to_double(i64 %a)\n";
        $out .= "  %db = call double @__manticore_tagged_to_double(i64 %b)\n";
        $out .= "  %flt = fcmp olt double %da, %db\n";
        $out .= "  %fgt = fcmp ogt double %da, %db\n";
        $out .= "  %fsel = select i1 %fgt, i64 1, i64 0\n";
        $out .= "  %fres = select i1 %flt, i64 -1, i64 %fsel\n";
        $out .= "  ret i64 %fres\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `==` / `===` for two NaN-boxed cells with PHP juggling. `__manticore_tagged_
     * loose_eq`: numbers, bools, null and NUMERIC strings compare numerically
     * (`5 == "5"`, `"10" == "1e1"`, `null == 0`); two strings where at least one is
     * non-numeric compare byte-wise; anything else falls back to raw-bit identity.
     * `__manticore_tagged_strict_eq`: different tag ⇒ not equal; strings compare
     * byte-wise (non-interned), everything else by raw bits. `__mir_is_numeric_str`
     * is the PHP-numeric-string test (strtod consumed the whole string modulo
     * trailing ASCII whitespace). Used by the cell==cell / cell===cell path.
     */
    private function taggedEqRuntime(): string
    {
        $this->rt->needsStrcmp = true;
        $this->libcExtra['strtod'] = 'declare double @strtod(ptr, ptr)';
        // __mir_is_numeric_str(s) -> i1
        $out  = "\ndefine i1 @__mir_is_numeric_str(ptr %s) {\nentry:\n";
        $out .= "  %c0 = load i8, ptr %s\n";
        $out .= "  %empty = icmp eq i8 %c0, 0\n";
        $out .= "  br i1 %empty, label %no, label %parse\n";
        $out .= "parse:\n";
        $out .= "  %end = alloca ptr\n";
        $out .= "  %d = call double @strtod(ptr %s, ptr %end)\n";
        $out .= "  %ep = load ptr, ptr %end\n";
        $out .= "  %noparse = icmp eq ptr %ep, %s\n";
        $out .= "  br i1 %noparse, label %no, label %tail\n";
        $out .= "tail:\n";
        $out .= "  %cur = phi ptr [ %ep, %parse ], [ %nxt, %skip ]\n";
        $out .= "  %tc = load i8, ptr %cur\n";
        $out .= "  %isnul = icmp eq i8 %tc, 0\n";
        $out .= "  br i1 %isnul, label %yes, label %chkws\n";
        $out .= "chkws:\n";
        // ASCII whitespace: space(32) tab(9) nl(10) cr(13) vt(11) ff(12)
        $out .= "  %w1 = icmp eq i8 %tc, 32\n  %w2 = icmp eq i8 %tc, 9\n";
        $out .= "  %w3 = icmp eq i8 %tc, 10\n  %w4 = icmp eq i8 %tc, 13\n";
        $out .= "  %w5 = icmp eq i8 %tc, 11\n  %w6 = icmp eq i8 %tc, 12\n";
        $out .= "  %o1 = or i1 %w1, %w2\n  %o2 = or i1 %w3, %w4\n  %o3 = or i1 %w5, %w6\n";
        $out .= "  %o4 = or i1 %o1, %o2\n  %isws = or i1 %o4, %o3\n";
        $out .= "  br i1 %isws, label %skip, label %no\n";
        $out .= "skip:\n  %nxt = getelementptr inbounds i8, ptr %cur, i64 1\n  br label %tail\n";
        $out .= "yes:\n  ret i1 true\n";
        $out .= "no:\n  ret i1 false\n}\n";

        // isNumericCell(v) -> i1 : tag int/bool/null/float, OR a numeric string.
        $out .= "define i1 @__mir_cell_numeric(i64 %v) {\nentry:\n";
        $out .= "  %t = call i64 @__manticore_tag(i64 %v)\n";
        $out .= "  %t1 = icmp eq i64 %t, 1\n  %t2 = icmp eq i64 %t, 2\n";
        $out .= "  %t3 = icmp eq i64 %t, 3\n  %t6 = icmp eq i64 %t, 6\n";
        $out .= "  %n1 = or i1 %t1, %t2\n  %n2 = or i1 %t3, %t6\n  %numty = or i1 %n1, %n2\n";
        $out .= "  br i1 %numty, label %y, label %chkstr\n";
        $out .= "chkstr:\n";
        $out .= "  %isstr = icmp eq i64 %t, 4\n";
        $out .= "  br i1 %isstr, label %s, label %n\n";
        $out .= "s:\n  %p = and i64 %v, 281474976710655\n  %pp = inttoptr i64 %p to ptr\n";
        $out .= "  %sn = call i1 @__mir_is_numeric_str(ptr %pp)\n  ret i1 %sn\n";
        $out .= "y:\n  ret i1 true\n  n:\n  ret i1 false\n}\n";

        // __manticore_tagged_loose_eq(a,b) -> i64 (0/1)
        $out .= "define i64 @__manticore_tagged_loose_eq(i64 %a, i64 %b) {\nentry:\n";
        $out .= "  %ta = call i64 @__manticore_tag(i64 %a)\n";
        $out .= "  %tb = call i64 @__manticore_tag(i64 %b)\n";
        $out .= $this->juggleRows('e');
        $out .= "row4:\n";
        $out .= "  %an = call i1 @__mir_cell_numeric(i64 %a)\n";
        $out .= "  %bn = call i1 @__mir_cell_numeric(i64 %b)\n";
        $out .= "  %bothnum = and i1 %an, %bn\n";
        $out .= "  br i1 %bothnum, label %num, label %chkstr\n";
        $out .= "num:\n";
        $out .= "  %da = call double @__manticore_tagged_to_double(i64 %a)\n";
        $out .= "  %db = call double @__manticore_tagged_to_double(i64 %b)\n";
        $out .= "  %feq = fcmp oeq double %da, %db\n";
        $out .= "  %fz = zext i1 %feq to i64\n  ret i64 %fz\n";
        $out .= "chkstr:\n";
        $out .= "  %sa = icmp eq i64 %ta, 4\n  %sb = icmp eq i64 %tb, 4\n";
        $out .= "  %bothstr = and i1 %sa, %sb\n";
        $out .= "  br i1 %bothstr, label %scmp, label %raw\n";
        $out .= "scmp:\n";
        $out .= "  %pa = and i64 %a, 281474976710655\n  %ppa = inttoptr i64 %pa to ptr\n";
        $out .= "  %pb = and i64 %b, 281474976710655\n  %ppb = inttoptr i64 %pb to ptr\n";
        $out .= "  %se = call i1 @__mir_str_eq(ptr %ppa, ptr %ppb)\n  %sz = zext i1 %se to i64\n  ret i64 %sz\n";
        $out .= "raw:\n";
        $out .= "  %req = icmp eq i64 %a, %b\n  %rz = zext i1 %req to i64\n  ret i64 %rz\n}\n";

        // __manticore_tagged_strict_eq(a,b) -> i64 (0/1)
        $out .= "define i64 @__manticore_tagged_strict_eq(i64 %a, i64 %b) {\nentry:\n";
        $out .= "  %ta = call i64 @__manticore_tag(i64 %a)\n  %tb = call i64 @__manticore_tag(i64 %b)\n";
        $out .= "  %same = icmp eq i64 %ta, %tb\n";
        $out .= "  br i1 %same, label %chk, label %ne\n";
        $out .= "chk:\n";
        // Same tag: an ARRAY needs the recursive by-value `===` (same pairs, in
        // order); an OBJECT stays raw-bit identity, which is what PHP's `===`
        // means for objects.
        $out .= "  %isarr = icmp eq i64 %ta, 7\n";
        $out .= "  br i1 %isarr, label %arrs, label %chkstr2\n";
        $out .= "arrs:\n";
        $out .= "  %apa = and i64 %a, 281474976710655\n";
        $out .= "  %apap = inttoptr i64 %apa to ptr\n";
        $out .= "  %apb = and i64 %b, 281474976710655\n";
        $out .= "  %apbp = inttoptr i64 %apb to ptr\n";
        $out .= "  %ase = call i1 @__mir_array_strict_eq(ptr %apap, i64 0, ptr %apbp, i64 0)\n";
        $out .= "  %asez = zext i1 %ase to i64\n  ret i64 %asez\n";
        $out .= "chkstr2:\n";
        $out .= "  %isstr = icmp eq i64 %ta, 4\n";
        $out .= "  br i1 %isstr, label %scmp, label %raw\n";
        $out .= "scmp:\n";
        $out .= "  %pa = and i64 %a, 281474976710655\n  %ppa = inttoptr i64 %pa to ptr\n";
        $out .= "  %pb = and i64 %b, 281474976710655\n  %ppb = inttoptr i64 %pb to ptr\n";
        $out .= "  %se = call i1 @__mir_str_eq(ptr %ppa, ptr %ppb)\n  %sz = zext i1 %se to i64\n  ret i64 %sz\n";
        $out .= "raw:\n";
        $out .= "  %req = icmp eq i64 %a, %b\n  %rz = zext i1 %req to i64\n  ret i64 %rz\n";
        $out .= "ne:\n  ret i64 0\n}\n";

        // __mir_str_loose_eq(a,b) -> i1 — PHP `==` on two strings: equal bytes,
        // OR both NUMERIC and numerically equal ("1.0" == "1", "1e2" == "100",
        // " 1" == "1"). Byte equality is tried FIRST so the common case stays a
        // memcmp and only a mismatch pays for the two strtod probes.
        $out .= "\ndefine i1 @__mir_str_loose_eq(ptr %a, ptr %b) {\nentry:\n";
        $out .= "  %be = call i1 @__mir_str_eq(ptr %a, ptr %b)\n";
        $out .= "  br i1 %be, label %yes, label %chknum\n";
        $out .= "chknum:\n";
        $out .= "  %an = call i1 @__mir_is_numeric_str(ptr %a)\n";
        $out .= "  %bn = call i1 @__mir_is_numeric_str(ptr %b)\n";
        $out .= "  %both = and i1 %an, %bn\n";
        $out .= "  br i1 %both, label %num, label %no\n";
        $out .= "num:\n";
        $out .= "  %da = call double @strtod(ptr %a, ptr null)\n";
        $out .= "  %db = call double @strtod(ptr %b, ptr null)\n";
        $out .= "  %eq = fcmp oeq double %da, %db\n  ret i1 %eq\n";
        $out .= "yes:\n  ret i1 true\n";
        $out .= "no:\n  ret i1 false\n}\n";
        return $out;
    }

    /**
     * Dynamic `cellA <op> cellB` for `+ - *`: PHP promotes to float iff either
     * operand is a float, else integer. Each helper checks the two tags, and on
     * a float tag (6) on either side does the float op over tagged_to_double and
     * re-boxes a float cell, otherwise the integer op over tagged_to_int and
     * re-boxes an int cell. Operands are always boxed cells (emitTaggedArith).
     */
    private function taggedArithRuntime(): string
    {
        return $this->taggedArithOne('add', 'add', 'fadd')
            . $this->taggedArithOne('sub', 'sub', 'fsub')
            . $this->taggedArithOne('mul', 'mul', 'fmul');
    }

    private function taggedArithOne(string $name, string $iop, string $fop): string
    {
        // PHP promotes an int op to float on overflow. The signed-overflow
        // intrinsic gives {result, overflow-bit}; on overflow re-do the op in
        // double and box a float cell, mirroring Zend.
        $intr = $iop === 'add' ? 'sadd' : ($iop === 'sub' ? 'ssub' : 'smul');
        $out  = "\ndefine i64 @__manticore_tagged_" . $name . "(i64 %a, i64 %b) {\n";
        $out .= "entry:\n";
        $out .= "  %aistag = icmp ugt i64 %a, -4503599627370496\n";
        $out .= "  %tas = lshr i64 %a, 48\n";
        $out .= "  %tan = and i64 %tas, 15\n";
        $out .= "  %taa = select i1 %aistag, i64 %tan, i64 6\n";
        $out .= "  %bistag = icmp ugt i64 %b, -4503599627370496\n";
        $out .= "  %tbs = lshr i64 %b, 48\n";
        $out .= "  %tbn = and i64 %tbs, 15\n";
        $out .= "  %tbb = select i1 %bistag, i64 %tbn, i64 6\n";
        $out .= "  %afl = icmp eq i64 %taa, 6\n";
        $out .= "  %bfl = icmp eq i64 %tbb, 6\n";
        $out .= "  %isf = or i1 %afl, %bfl\n";
        $out .= "  br i1 %isf, label %flt, label %int\n";
        $out .= "flt:\n";
        $out .= "  %fa = call double @__manticore_tagged_to_double(i64 %a)\n";
        $out .= "  %fb = call double @__manticore_tagged_to_double(i64 %b)\n";
        $out .= "  %fr = " . $fop . " double %fa, %fb\n";
        $out .= "  %fboxed = call i64 @__manticore_box_float(double %fr)\n";
        $out .= "  ret i64 %fboxed\n";
        $out .= "int:\n";
        $out .= "  %ia = call i64 @__manticore_tagged_to_int(i64 %a)\n";
        $out .= "  %ib = call i64 @__manticore_tagged_to_int(i64 %b)\n";
        $out .= "  %ovf = call {i64, i1} @llvm." . $intr . ".with.overflow.i64(i64 %ia, i64 %ib)\n";
        $out .= "  %ir = extractvalue {i64, i1} %ovf, 0\n";
        $out .= "  %obit = extractvalue {i64, i1} %ovf, 1\n";
        $out .= "  br i1 %obit, label %promo, label %okint\n";
        $out .= "okint:\n";
        $out .= "  %iboxed = call i64 @__manticore_box_int(i64 %ir)\n";
        $out .= "  ret i64 %iboxed\n";
        $out .= "promo:\n";
        $out .= "  %pa = sitofp i64 %ia to double\n";
        $out .= "  %pb = sitofp i64 %ib to double\n";
        $out .= "  %pr = " . $fop . " double %pa, %pb\n";
        $out .= "  %pboxed = call i64 @__manticore_box_float(double %pr)\n";
        $out .= "  ret i64 %pboxed\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * Truthiness of a NaN-boxed cell → i64 0/1 (PHP semantics): int≠0, bool bit,
     * null→0, string truthy unless "" or "0", float≠0.0, array non-empty, object
     * always true. A raw cell can't be tested with `icmp ne i64 v, 0` — a boxed
     * `0`/`false`/`""` has non-zero tag bits and would read truthy.
     */
    private function taggedTruthyRuntime(): string
    {
        $out  = "\ndefine i64 @__manticore_tagged_truthy(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  switch i64 %tag, label %asint [\n";
        $out .= "    i64 2, label %asbool\n";
        $out .= "    i64 3, label %asnull\n";
        $out .= "    i64 4, label %asstr\n";
        $out .= "    i64 6, label %asfloat\n";
        $out .= "    i64 7, label %asarray\n";
        $out .= "    i64 8, label %astrue\n";
        $out .= "  ]\n";
        $out .= "asint:\n";
        $out .= "  %i = call i64 @__manticore_unbox_int(i64 %v)\n";
        $out .= "  %inz = icmp ne i64 %i, 0\n";
        $out .= "  %ir = zext i1 %inz to i64\n";
        $out .= "  ret i64 %ir\n";
        $out .= "asbool:\n";
        $out .= "  %bb = and i64 %v, 1\n";
        $out .= "  ret i64 %bb\n";
        $out .= "asnull:\n";
        $out .= "  ret i64 0\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        $out .= "  %fnz = fcmp une double %fd, 0.000000e+00\n";
        $out .= "  %fr = zext i1 %fnz to i64\n";
        $out .= "  ret i64 %fr\n";
        $out .= "asarray:\n";
        $out .= "  %ap = and i64 %v, 281474976710655\n";
        $out .= "  %anull = icmp eq i64 %ap, 0\n";
        $out .= "  br i1 %anull, label %sfalse, label %aload\n";
        $out .= "aload:\n";
        $out .= "  %aptr = inttoptr i64 %ap to ptr\n";
        $out .= "  %alen = load i64, ptr %aptr\n";
        $out .= "  %ane = icmp ne i64 %alen, 0\n";
        $out .= "  %ar = zext i1 %ane to i64\n";
        $out .= "  ret i64 %ar\n";
        $out .= "astrue:\n";
        $out .= "  ret i64 1\n";
        // string: falsy iff "" (byte0==0) or "0" (byte0=='0' && byte1==0).
        $out .= "asstr:\n";
        $out .= "  %sp = and i64 %v, 281474976710655\n";
        $out .= "  %snull = icmp eq i64 %sp, 0\n";
        $out .= "  br i1 %snull, label %sfalse, label %sload\n";
        $out .= "sload:\n";
        $out .= "  %sptr = inttoptr i64 %sp to ptr\n";
        $out .= "  %c0 = load i8, ptr %sptr\n";
        $out .= "  %empty = icmp eq i8 %c0, 0\n";
        $out .= "  br i1 %empty, label %sfalse, label %schk0\n";
        $out .= "schk0:\n";
        $out .= "  %isz = icmp eq i8 %c0, 48\n";
        $out .= "  br i1 %isz, label %schkb1, label %strue\n";
        $out .= "schkb1:\n";
        $out .= "  %p1 = getelementptr inbounds i8, ptr %sptr, i64 1\n";
        $out .= "  %c1 = load i8, ptr %p1\n";
        $out .= "  %c1z = icmp eq i8 %c1, 0\n";
        $out .= "  br i1 %c1z, label %sfalse, label %strue\n";
        $out .= "strue:\n  ret i64 1\n";
        $out .= "sfalse:\n  ret i64 0\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * A value kind that NaN-boxes in place (a single box_* with no buffer
     * rebuild): scalars, string (box_ptr), object (box_object), and an
     * already-boxed cell. A concrete array/assoc would REBUILD (boxToCell
     * copies into a fresh cell-array — wrong for a co-owned / SPL backing slot)
     * and unknown/closure/generator mis-box, so those keep the slot RAW. A
     * boxed object cell var_dumps / `instanceof`s / dispatches correctly; a
     * chained `$cell->prop` still needs instanceof narrowing (see
     * inferPropertyAccess path-narrowing) — unguarded it hits the bag path.
     */
    private function cellBoxableKind(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_BOOL || $k === Type::KIND_NULL
            || $k === Type::KIND_STRING || $k === Type::KIND_OBJ
            || $k === Type::KIND_CELL;
    }

    /** The property name when $n uses `$obj->name` as a RAW array base
     *  (subscript read/write or foreach subject), else null. */
    private function cellPropArrayBaseName(Node $n): ?string
    {
        $base = null;
        if ($n->kind === Node::KIND_ARRAY_ACCESS) {
            $base = $n->array;
        } elseif ($n->kind === Node::KIND_STORE_ELEMENT) {
            $base = $n->array;
        } elseif ($n->kind === Node::KIND_FOREACH) {
            $base = $n->array;
        }
        if ($base !== null && $base->kind === Node::KIND_PROPERTY_ACCESS) {
            return $base->property;
        }
        return null;
    }

    /**
     * A cell/`mixed` property that is self-describing (boxed NULL default +
     * box-store) rather than a raw cell-array backing slot. True iff the
     * declared type is a cell, the name is never used as a raw array base, and
     * every store boxes in place. A concrete array store rides along (boxed as a
     * cell-array) ONLY when the slot is also stored a scalar/string/object — i.e.
     * a genuinely heterogeneous bag; an array-only slot stays raw.
     */
    private function cellPropBoxed(?Type $ptype, string $prop): bool
    {
        if ($ptype === null || $ptype->kind !== Type::KIND_CELL) { return false; }
        if (isset($this->cellPropNotBoxable[$prop])) { return false; }
        if (isset($this->cellPropArrayBase[$prop])) { return false; }
        if (isset($this->cellPropHasArrayStore[$prop])
            && !isset($this->cellPropHasInPlaceBox[$prop])) {
            return false;
        }
        return true;
    }

    private function emitStringConst(StringConst $n): string
    {
        $sc = $n;
        $id = $this->pool->intern($sc->value);
        $this->lastValue = $this->strLitId($id);
        $this->lastValueType = 'ptr';
        return '';
    }

    /**
     * Coerce `$this->lastValue` (current $lastValueType) to the
     * given target type. Emits an instruction when a real cast is
     * needed; otherwise returns ''.
     */
    private function coerceTo(string $target): string
    {
        if ($this->lastValueType === $target) { return ''; }
        if ($target === 'double' && $this->lastValueType === 'i64') {
            $reg = $this->ssa->allocReg();
            $out = '  ' . $reg . ' = sitofp i64 ' . $this->lastValue . " to double\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'double';
            return $out;
        }
        if ($target === 'i64' && $this->lastValueType === 'double') {
            $reg = $this->ssa->allocReg();
            $out = '  ' . $reg . ' = fptosi double ' . $this->lastValue . " to i64\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        return '';
    }

    private function emitArith(Node $self, Node $left, Node $right, string $intOp, string $floatOp): string
    {
        // A numeric (int|float) cell result is dynamically int-or-float — its
        // runtime tag decides — so route to __manticore_tagged_<op>: box both
        // operands, the helper promotes to float iff either is float and
        // re-boxes a cell. Only a NUMERIC cell (Type::isNumericCell, from an
        // int|float union); a plain mixed cell never reaches here.
        if ($self->type->isNumericCell()) {
            return $this->emitTaggedArith($left, $right, $intOp);
        }
        $isFloat = $self->type->kind === Type::KIND_FLOAT;
        $target = $isFloat ? 'double' : 'i64';
        $op = $isFloat ? $floatOp : $intOp;
        $out = $this->emitNode($left);
        $out .= $this->coerceArithOperand($left, $isFloat);
        $l = $this->lastValue;
        $out .= $this->emitNode($right);
        $out .= $this->coerceArithOperand($right, $isFloat);
        $r = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = ' . $op . ' ' . $target . ' ' . $l . ', ' . $r . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = $target;
        return $out;
    }

    /** Coerce a just-emitted arithmetic operand: to double for a float op
     *  (unboxing a cell via tagged_to_double, not bitcasting its tagged bits),
     *  to i64 + unboxCellInt for an integer op. */
    private function coerceArithOperand(Node $op, bool $isFloat): string
    {
        if ($isFloat) {
            return $this->coerceDoubleOperand($op);
        }
        // A STRING operand in integer arithmetic is PHP-coerced to its leading
        // numeric value (strtol base 10), NOT ptrtoint'd — else `"2026" + "06"`
        // adds the raw pointers. (`explode(...)[i] + …` is the canonical case.)
        if ($op->type->kind === Type::KIND_STRING) {
            $this->rt->needsStrtol = true;
            $out = $this->coerceToPtr();
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call i64 @strtol(ptr ' . $this->lastValue . ', ptr null, i32 10)' . "\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out = $this->coerceTo('i64');
        if ($op->type->kind === Type::KIND_CELL) {
            $out .= $this->unboxCellInt($this->lastValue);
        }
        return $out;
    }

    /** A just-emitted operand → double; a cell goes through tagged_to_double
     *  (int→sitofp, float→bits, …) instead of bitcasting the NaN-boxed bits. */
    private function coerceDoubleOperand(Node $op): string
    {
        // A STRING operand in float arithmetic → its numeric value via strtod
        // (PHP numeric-string coercion), not a bitcast of the pointer.
        if ($op->type->kind === Type::KIND_STRING) {
            $this->rt->needsStrtod = true;
            $out = $this->coerceToPtr();
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call double @strtod(ptr ' . $this->lastValue . ', ptr null)' . "\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'double';
            return $out;
        }
        if ($op->type->kind !== Type::KIND_CELL) {
            return $this->coerceTo('double');
        }
        $this->rt->needsTaggedToFloat = true;
        $this->rt->needsStrtod = true;
        $out = $this->coerceToI64();
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'double';
        return $out;
    }

    /**
     * Unbox a tagged-cell i64 carrier to its signed int payload (false →
     * 0) via __manticore_unbox_int, leaving the result in lastValue. For
     * `int|false` (strpos) operands feeding integer arithmetic/comparison.
     */
    private function unboxCellInt(string $v): string
    {
        $this->rt->needsTagged = true;
        $u = $this->ssa->allocReg();
        $this->lastValue = $u;
        $this->lastValueType = 'i64';
        return '  ' . $u . ' = call i64 @__manticore_unbox_int(i64 ' . $v . ")\n";
    }

    /**
     * PHP `/` always evaluates to float. Coerce both operands to
     * double, fdiv. Integer floor division goes through `intdiv`
     * separately — not surfaced as a MIR node yet.
     */
    private function emitDiv(Div $n): string
    {
        $d = $n;
        $out = $this->emitNode($d->left);
        $out .= $this->coerceDoubleOperand($d->left);
        $l = $this->lastValue;
        $out .= $this->emitNode($d->right);
        $out .= $this->coerceDoubleOperand($d->right);
        $r = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = fdiv double ' . $l . ', ' . $r . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'double';
        return $out;
    }

    private function emitNeg(Neg $n): string
    {
        $neg = $n;
        // A numeric (int|float) cell is dynamic — negate as tagged `0 - $x` so a
        // float value keeps its float tag and the result stays a numeric cell.
        // The operand is ALREADY a boxed cell, so it is passed to tagged_sub
        // as-is (not re-boxed, which would double-tag).
        if ($neg->operand->type->isNumericCell()) {
            $this->rt->needsTaggedArith = true;
            $this->rt->needsTagged = true;
            $this->rt->needsTaggedToInt = true;
            $this->rt->needsStrtol = true;
            $this->rt->needsTaggedToFloat = true;
            $this->rt->needsStrtod = true;
            $out = $this->emitNode($neg->operand);
            $out .= $this->coerceToI64();
            $xc = $this->lastValue;
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . " = call i64 @__manticore_box_int(i64 0)\n";
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call i64 @__manticore_tagged_sub(i64 ' . $z
                  . ', i64 ' . $xc . ")\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out = $this->emitNode($neg->operand);
        // Float negation needs fneg, not integer `sub i64 0, x` (the
        // operand carries a double — e.g. `-PHP_FLOAT_MAX`).
        if ($this->lastValueType === 'double' || $neg->operand->type->kind === Type::KIND_FLOAT) {
            $out .= $this->coerceTo('double');
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = fneg double ' . $this->lastValue . "\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'double';
            return $out;
        }
        // Unbox a cell operand (and numeric-coerce a string) BEFORE the integer
        // negate — negating the raw NaN-boxed bits of `-$x` on a mixed/untyped
        // param produced garbage. Mirrors {@see coerceArithOperand}.
        $out .= $this->coerceArithOperand($neg->operand, false);
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = sub i64 0, ' . $this->lastValue . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitNot(Not_ $n): string
    {
        $out = $this->emitCondVal($n->operand);
        $val = $this->lastValue;
        $cmpReg = $this->ssa->allocReg();
        $out .= '  ' . $cmpReg . ' = icmp eq i64 ' . $val . ", 0\n";
        $extReg = $this->ssa->allocReg();
        $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
        $this->lastValue = $extReg;
        return $out;
    }

    private function emitBitOp(\Compile\Mir\BitOp $n): string
    {
        $b = $n;
        $out = $this->emitNode($b->left);
        $out .= $this->coerceToI64();
        $l = $this->lastValue;
        $out .= $this->emitNode($b->right);
        $out .= $this->coerceToI64();
        $r = $this->lastValue;
        // PHP `>>` is an arithmetic (sign-extending) shift → ashr.
        $op = $b->op;
        $ll = 'and';
        if ($op === 'shl')      { $ll = 'shl'; }
        elseif ($op === 'shr')  { $ll = 'ashr'; }
        elseif ($op === 'or')   { $ll = 'or'; }
        elseif ($op === 'xor')  { $ll = 'xor'; }
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = ' . $ll . ' i64 ' . $l . ', ' . $r . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitBitNot(\Compile\Mir\BitNot_ $n): string
    {
        $out = $this->emitNode($n->operand);
        $out .= $this->coerceToI64();
        $val = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = xor i64 ' . $val . ", -1\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Box the current lastValue into a tagged cell chosen by its LLVM repr
     *  (double → box_float, ptr → box_ptr, else box_int). Used for an
     *  unknown-typed value whose static type can't pick the box but whose
     *  carrier repr can. lastValue ← the boxed i64. */
    private function boxLastByRepr(): string
    {
        if ($this->lastValueType === 'double') {
            $r = $this->ssa->allocReg();
            $out = '  ' . $r . ' = call i64 @__manticore_box_float(double ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($this->lastValueType === 'ptr') {
            $r = $this->ssa->allocReg();
            $out = '  ' . $r . ' = call i64 @__manticore_box_ptr(ptr ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out = $this->coerceToI64();
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 @__manticore_box_int(i64 ' . $this->lastValue . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitNullCoalesce(NullCoalesce_ $n): string
    {
        $nc = $n;
        if ($nc->left->kind === Node::KIND_PROPERTY_ACCESS) {
            $lpa = $nc->left;
            if ($lpa->object->kind === Node::KIND_PROPERTY_ACCESS
                && $lpa->object->type->kind === Type::KIND_OBJ) {
                return $this->emitCoalesceChain($nc, $n->type);
            }
        }
        // An array/assoc access can be ABSENT (PHP: missing key/index →
        // null → use the default). Decide by key/index PRESENCE, not by
        // the value: the int short-circuit below would otherwise drop the
        // default entirely, and an int-valued assoc reads a missing key as
        // 0 — indistinguishable from a present 0 by a value-null check.
        if ($nc->left->kind === Node::KIND_ARRAY_ACCESS) {
            // A cell result (a `mixed`-element array — e.g. ArrayAccess offsetGet)
            // must store BOTH arms as cells: the left rides a cell already (the
            // cell-element read), the right (a raw scalar default) is boxed, so a
            // downstream `mixed` return / var_dump reads a uniform tagged value
            // instead of re-boxing the cell arm (the double-box masked by the
            // 48-bit truncation).
            $wantCell = $n->type->kind === Type::KIND_CELL;
            $res = $this->ssa->allocReg();
            $out = '  ' . $res . " = alloca i64\n";
            $out .= $this->emitIssetTarget($nc->left);
            $present = $this->lastValue;
            $bit = $this->ssa->allocReg();
            $out .= '  ' . $bit . ' = icmp ne i64 ' . $present . ", 0\n";
            $useL = $this->ssa->allocLabel('nc.left');
            $useR = $this->ssa->allocLabel('nc.right');
            $end  = $this->ssa->allocLabel('nc.end');
            $out .= '  br i1 ' . $bit . ', label %' . $useL . ', label %' . $useR . "\n";
            $out .= $useL . ":\n";
            $out .= $this->emitNode($nc->left);
            $out .= $wantCell ? $this->boxToCell($nc->left->type) : $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $end . "\n";
            $out .= $useR . ":\n";
            $out .= $this->emitNode($nc->right);
            $out .= $wantCell ? $this->boxToCell($nc->right->type) : $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $end . "\n";
            $out .= $end . ":\n";
            $loaded = $this->ssa->allocReg();
            $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
            $this->lastValue = $loaded;
            $this->lastValueType = 'i64';
            return $out;
        }
        $lk = $nc->left->type->kind;
        if ($lk === Type::KIND_NULL) {
            return $this->emitNode($nc->right);
        }
        if ($lk === Type::KIND_INT || $lk === Type::KIND_FLOAT || $lk === Type::KIND_BOOL) {
            return $this->emitNode($nc->left);
        }
        // Runtime: use left when it isn't null. A null POINTER is 0
        // (string/obj/array), a null SCALAR is the boxed-NULL sentinel (a
        // nullable `?int`/`?float`/`?bool` rides a numeric cell) — reject both.
        // The raw int/float/bool cases returned above, so box_null can't collide.
        $res = $this->ssa->allocReg();
        $out = '  ' . $res . " = alloca i64\n";
        $out .= $this->emitNode($nc->left);
        $out .= $this->coerceToI64();
        $lv = $this->lastValue;
        $nz = $this->ssa->allocReg();
        $out .= '  ' . $nz . ' = icmp ne i64 ' . $lv . ", 0\n";
        $nnul = $this->ssa->allocReg();
        $out .= '  ' . $nnul . ' = icmp ne i64 ' . $lv . ", -3659174697238528\n";
        $bit = $this->ssa->allocReg();
        $out .= '  ' . $bit . ' = and i1 ' . $nz . ', ' . $nnul . "\n";
        // A cell result (arms of differing repr) boxes BOTH arms so a consumer
        // (echo / var_dump) dispatches on the arm actually taken.
        $wantCell = $n->type->kind === Type::KIND_CELL;
        $useL = $this->ssa->allocLabel('nc.left');
        $useR = $this->ssa->allocLabel('nc.right');
        $end  = $this->ssa->allocLabel('nc.end');
        $out .= '  br i1 ' . $bit . ', label %' . $useL . ', label %' . $useR . "\n";
        $out .= $useL . ":\n";
        if ($wantCell) {
            $this->lastValue = $lv;
            $this->lastValueType = 'i64';
            $out .= $this->boxToCell($nc->left->type);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        } else {
            $out .= '  store i64 ' . $lv . ', ptr ' . $res . "\n";
        }
        $out .= '  br label %' . $end . "\n";
        $out .= $useR . ":\n";
        $out .= $this->emitNode($nc->right);
        $out .= $wantCell ? $this->boxToCell($nc->right->type) : $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitCoalesceChain(NullCoalesce_ $nc, Type $resultType): string
    {
        $chain = [];
        $node = $nc->left;
        while ($node->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $node;
            $rc = $pa->object->type->class ?? '';
            if ($pa->object->type->kind === Type::KIND_OBJ && $rc !== ''
                && isset($this->classes[$rc]) && !isset($this->enums[$rc])
                && !$this->classes[$rc]->usesBag()
                && $this->classes[$rc]->propertyOffset($pa->property) >= 0
                && !isset($this->classes[$rc]->propHooks[$pa->property])) {
                $chain[] = $pa;
                $node = $pa->object;
            } else {
                break;
            }
        }
        $hops = \count($chain);
        $leafPa = $chain[0];
        if ($leafPa->kind !== Node::KIND_PROPERTY_ACCESS) {
            throw new \RuntimeException('emitCoalesceChain: non-property leaf');
        }
        $leafCls = $leafPa->object->type->class ?? '';
        $leafType = ($leafCls !== '' && isset($this->classes[$leafCls]))
            ? ($this->classes[$leafCls]->propertyTypes[$leafPa->property] ?? Type::unknown())
            : Type::unknown();
        $wantCell = $resultType->kind === Type::KIND_CELL;
        $res = $this->ssa->allocReg();
        $out = '  ' . $res . " = alloca i64\n";
        $useR = $this->ssa->allocLabel('ncc.right');
        $keep = $this->ssa->allocLabel('ncc.keep');
        $end  = $this->ssa->allocLabel('ncc.end');
        $out .= $this->emitNode($node);
        $out .= $this->coerceToI64();
        $cur = $this->lastValue;
        for ($i = $hops - 1; $i >= 0; $i = $i - 1) {
            $hop = $chain[$i];
            if ($hop->kind !== Node::KIND_PROPERTY_ACCESS) {
                throw new \RuntimeException('emitCoalesceChain: non-property hop');
            }
            $hoff = $this->propertyOffset($hop->object, $hop->property);
            $z0 = $this->ssa->allocReg();
            $out .= '  ' . $z0 . ' = icmp eq i64 ' . $cur . ", 0\n";
            $cont = $this->ssa->allocLabel('ncc.hop');
            $out .= '  br i1 ' . $z0 . ', label %' . $useR . ', label %' . $cont . "\n";
            $out .= $cont . ":\n";
            $op = $this->ssa->allocReg();
            $out .= '  ' . $op . ' = inttoptr i64 ' . $cur . " to ptr\n";
            $fp = $this->ssa->allocReg();
            $out .= '  ' . $fp . ' = getelementptr inbounds i8, ptr ' . $op . ', i64 ' . (string)$hoff . "\n";
            $nx = $this->ssa->allocReg();
            $out .= '  ' . $nx . ' = load i64, ptr ' . $fp . "\n";
            $cur = $nx;
        }
        // A present-but-NULL leaf value also takes the default.
        $vn = $this->ssa->allocReg();
        $out .= '  ' . $vn . ' = icmp eq i64 ' . $cur . ", -3659174697238528\n";
        $out .= '  br i1 ' . $vn . ', label %' . $useR . ', label %' . $keep . "\n" . $keep . ":\n";
        if ($wantCell) {
            $out .= $this->boxRawValue($cur, $leafType);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        } else {
            $out .= '  store i64 ' . $cur . ', ptr ' . $res . "\n";
        }
        $out .= '  br label %' . $end . "\n";
        $out .= $useR . ":\n";
        $out .= $this->emitNode($nc->right);
        $out .= $wantCell ? $this->boxToCell($nc->right->type) : $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitInstanceof(Instanceof_ $n): string
    {
        $io = $n;
        $out = $this->emitNode($io->operand);
        $out .= $this->coerceToI64();
        $obj = $this->lastValue;
        $ids = $this->instanceofMatchIds($io->class);
        if ($ids === []) {
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
            return $out;
        }
        // A cell operand is an instance only when it's object-tagged (tag 8):
        // any other tag (int/string/null/array/...) → false. Guard the
        // class_id load behind the tag check (a non-object cell's payload is
        // not an object ptr) and unbox the payload before reading the id. A
        // result slot avoids a phi.
        if ($io->operand->type->kind === Type::KIND_CELL) {
            $slot = $this->ssa->allocReg();
            $out .= '  ' . $slot . " = alloca i64\n";
            $out .= '  store i64 0, ptr ' . $slot . "\n";
            $out .= $this->cellTagIr($obj);
            $tag = $this->cellTagReg;
            $isObj = $this->ssa->allocReg();
            $out .= '  ' . $isObj . ' = icmp eq i64 ' . $tag . ", 8\n";
            $objL = $this->ssa->allocLabel('io.obj');
            $doneL = $this->ssa->allocLabel('io.done');
            $out .= '  br i1 ' . $isObj . ', label %' . $objL . ', label %' . $doneL . "\n";
            $out .= $objL . ":\n";
            $payload = $this->ssa->allocReg();
            $out .= '  ' . $payload . ' = and i64 ' . $obj . ", 281474976710655\n";
            $objpc = $this->ssa->allocReg();
            $out .= '  ' . $objpc . ' = inttoptr i64 ' . $payload . " to ptr\n";
            $out .= $this->emitLoadClassId($objpc);
            $out .= $this->emitClassIdMatch($this->classIdReg, $ids);
            $accc = $this->classIdMatchReg;
            $mext = $this->ssa->allocReg();
            $out .= '  ' . $mext . ' = zext i1 ' . $accc . " to i64\n";
            $out .= '  store i64 ' . $mext . ', ptr ' . $slot . "\n";
            $out .= '  br label %' . $doneL . "\n";
            $out .= $doneL . ":\n";
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = load i64, ptr ' . $slot . "\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        // A non-cell obj operand can be a null (0) pointer at runtime — an
        // obj-typed value from a plain-ternary null arm (`$c ? new P() : null`).
        // Reading the class id from a null ptr is a wild load (heap roulette
        // SIGSEGV); guard it — null is an instance of nothing. A result slot
        // avoids a phi (mirrors the cell path above).
        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $slot . "\n";
        $isNull = $this->ssa->allocReg();
        $out .= '  ' . $isNull . ' = icmp eq i64 ' . $obj . ", 0\n";
        $objL = $this->ssa->allocLabel('io.obj');
        $doneL = $this->ssa->allocLabel('io.done');
        $out .= '  br i1 ' . $isNull . ', label %' . $doneL . ', label %' . $objL . "\n";
        $out .= $objL . ":\n";
        $objp = $this->ssa->allocReg();
        $out .= '  ' . $objp . ' = inttoptr i64 ' . $obj . " to ptr\n";
        $out .= $this->emitLoadClassId($objp);
        $out .= $this->emitClassIdMatch($this->classIdReg, $ids);
        $mx = $this->ssa->allocReg();
        $out .= '  ' . $mx . ' = zext i1 ' . $this->classIdMatchReg . " to i64\n";
        $out .= '  store i64 ' . $mx . ', ptr ' . $slot . "\n";
        $out .= '  br label %' . $doneL . "\n";
        $out .= $doneL . ":\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = load i64, ptr ' . $slot . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitCast(Cast $n): string
    {
        $c = $n;
        $ok = $c->operand->type->kind;
        $out = $this->emitNode($c->operand);
        if ($c->target === 'string') {
            if ($ok === Type::KIND_STRING) { $out .= $this->coerceToPtr(); return $out; }
            if ($ok === Type::KIND_CELL) {
                $this->rt->needsTaggedToStr = true;
                $out .= $this->coerceToI64();
                $r = $this->ssa->allocReg();
                $out .= '  ' . $r . ' = call ptr @__manticore_tagged_to_str(i64 ' . $this->lastValue . ")\n";
                $this->lastValue = $r; $this->lastValueType = 'ptr';
                return $out;
            }
            $out .= $this->coerceToStr($c->operand);
            return $out;
        }
        if ($c->target === 'int') {
            if ($ok === Type::KIND_STRING) {
                $this->rt->needsStrtol = true;
                $out .= $this->coerceToPtr();
                $reg = $this->ssa->allocReg();
                $out .= '  ' . $reg . ' = call i64 @strtol(ptr ' . $this->lastValue . ', ptr null, i32 10)' . "\n";
                $this->lastValue = $reg; $this->lastValueType = 'i64';
                return $out;
            }
            if ($ok === Type::KIND_FLOAT) {
                $out .= $this->coerceTo('double');
                $reg = $this->ssa->allocReg();
                $out .= '  ' . $reg . ' = fptosi double ' . $this->lastValue . " to i64\n";
                $this->lastValue = $reg; $this->lastValueType = 'i64';
                return $out;
            }
            if ($ok === Type::KIND_CELL) {
                $this->rt->needsTaggedToInt = true;
                $this->rt->needsStrtol = true;
                $out .= $this->coerceToI64();
                $reg = $this->ssa->allocReg();
                $out .= '  ' . $reg . ' = call i64 @__manticore_tagged_to_int(i64 ' . $this->lastValue . ")\n";
                $this->lastValue = $reg; $this->lastValueType = 'i64';
                return $out;
            }
            $out .= $this->coerceToI64();
            return $out;
        }
        if ($c->target === 'float') {
            if ($ok === Type::KIND_STRING) {
                $this->rt->needsStrtod = true;
                $out .= $this->coerceToPtr();
                $reg = $this->ssa->allocReg();
                $out .= '  ' . $reg . ' = call double @strtod(ptr ' . $this->lastValue . ', ptr null)' . "\n";
                $this->lastValue = $reg; $this->lastValueType = 'double';
                return $out;
            }
            if ($ok === Type::KIND_FLOAT) { $out .= $this->coerceTo('double'); return $out; }
            if ($ok === Type::KIND_CELL) {
                $this->rt->needsTaggedToFloat = true;
                $out .= $this->coerceToI64();
                $reg = $this->ssa->allocReg();
                $out .= '  ' . $reg . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
                $this->lastValue = $reg; $this->lastValueType = 'double';
                return $out;
            }
            $out .= $this->coerceToI64();
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = sitofp i64 ' . $this->lastValue . " to double\n";
            $this->lastValue = $reg; $this->lastValueType = 'double';
            return $out;
        }
        if ($c->target === 'object') {
            // `(object)$assoc` → a stdClass whose bag is that assoc.
            $std = $this->classes['stdClass'] ?? null;
            $bagOff = $std === null ? 16 : $std->bagOffset();
            $size = $std === null ? 24 : $std->instanceSize();
            $out .= $this->coerceToPtr();
            $bagI = $this->ssa->allocReg();
            $out .= '  ' . $bagI . ' = ptrtoint ptr ' . $this->lastValue . " to i64\n";
            $obj = $this->ssa->allocReg();
            $out .= '  ' . $obj . ' = call ptr @__mir_alloc_tagged(i64 ' . (string)$size . ")\n";
            $out .= '  store i64 ' . $this->lib->descSlotValue($std) . ', ptr ' . $obj . "\n";
            $rcg = $this->ssa->allocReg();
            $out .= '  ' . $rcg . ' = getelementptr inbounds i64, ptr ' . $obj . ", i64 1\n";
            $out .= '  store i64 1, ptr ' . $rcg . "\n";
            $bg = $this->ssa->allocReg();
            $out .= '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $obj . ', i64 ' . (string)$bagOff . "\n";
            $out .= '  store i64 ' . $bagI . ', ptr ' . $bg . "\n";
            $this->lastValue = $obj; $this->lastValueType = 'ptr';
            return $out;
        }
        if ($c->target === 'array') {
            // `(array)$cell` — a tagged OBJECT cell → its bag assoc.
            if ($ok === Type::KIND_CELL) {
                $out .= $this->cellToPtr();
                $std = $this->classes['stdClass'] ?? null;
                $bagOff = $std === null ? 16 : $std->bagOffset();
                $bg = $this->ssa->allocReg();
                $out .= '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $this->lastValue . ', i64 ' . (string)$bagOff . "\n";
                $bagI = $this->ssa->allocReg();
                $out .= '  ' . $bagI . ' = load i64, ptr ' . $bg . "\n";
                $bagP = $this->ssa->allocReg();
                $out .= '  ' . $bagP . ' = inttoptr i64 ' . $bagI . " to ptr\n";
                $this->lastValue = $bagP; $this->lastValueType = 'ptr';
                return $out;
            }
            // `(array)$stdClass` → its bag assoc; an array stays itself.
            if ($ok === Type::KIND_OBJ) {
                $std = $this->classes['stdClass'] ?? null;
                $bagOff = $std === null ? 16 : $std->bagOffset();
                $out .= $this->coerceToPtr();
                $bg = $this->ssa->allocReg();
                $out .= '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $this->lastValue . ', i64 ' . (string)$bagOff . "\n";
                $bagI = $this->ssa->allocReg();
                $out .= '  ' . $bagI . ' = load i64, ptr ' . $bg . "\n";
                $bagP = $this->ssa->allocReg();
                $out .= '  ' . $bagP . ' = inttoptr i64 ' . $bagI . " to ptr\n";
                $this->lastValue = $bagP; $this->lastValueType = 'ptr';
                return $out;
            }
            $out .= $this->coerceToPtr();
            return $out;
        }
        // bool: truthiness → i64 0/1. A cell must unbox by tag (a boxed
        // 0/false/"" has non-zero raw bits → would read truthy).
        if ($ok === Type::KIND_CELL) {
            $this->rt->needsTaggedTruthy = true;
            $out .= $this->coerceToI64();
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_tagged_truthy(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r; $this->lastValueType = 'i64';
            return $out;
        }
        $out .= $this->coerceToI64();
        $bit = $this->ssa->allocReg();
        $out .= '  ' . $bit . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = zext i1 ' . $bit . " to i64\n";
        $this->lastValue = $reg; $this->lastValueType = 'i64';
        return $out;
    }

    private function emitIncDec(IncDec $n): string
    {
        $d = $n;
        // `$s++` on a string local (typed CELL by inferIncDec): the value rides a
        // cell — delegate to the stdlib Perl/numeric increment, which returns the
        // next value (int/float/string) as a cell. Post returns the old cell.
        if ($d->type->kind === Type::KIND_CELL && $d->op === '+'
            && isset($this->locals->slots[$d->name])) {
            $slot = $this->locals->slots[$d->name];
            $old = $this->ssa->allocReg();
            $out = '  ' . $old . ' = load i64, ptr ' . $slot . "\n";
            $new = $this->ssa->allocReg();
            $out .= '  ' . $new . ' = call i64 @manticore___mir_str_increment(i64 ' . $old . ")\n";
            $out .= '  store i64 ' . $new . ', ptr ' . $slot . "\n";
            $this->lastValue = $d->prefix ? $new : $old;
            $this->lastValueType = 'i64';
            return $out;
        }
        $instr = $d->op === '+' ? 'add' : 'sub';
        // Static locals (backed by a global cell) and by-ref params / captures
        // (the slot holds a POINTER to the real storage) don't live in a plain
        // i64 slot. `++`/`--` must load/store through the same indirection as
        // Load/StoreLocal, else the write-back hits a stale local and no-ops.
        if (isset($this->locals->globalBacked[$d->name])) {
            $cell = $this->locals->globalBacked[$d->name];
            $old = $this->ssa->allocReg();
            $out = '  ' . $old . ' = load i64, ptr ' . $cell . "\n";
            $new = $this->ssa->allocReg();
            $out .= '  ' . $new . ' = ' . $instr . ' i64 ' . $old . ", 1\n";
            $out .= '  store i64 ' . $new . ', ptr ' . $cell . "\n";
            $this->lastValue = $d->prefix ? $new : $old;
            $this->lastValueType = 'i64';
            return $out;
        }
        if (isset($this->locals->refLocals[$d->name]) && isset($this->locals->slots[$d->name])) {
            $addr = $this->ssa->allocReg();
            $out = '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$d->name] . "\n";
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
            $old = $this->ssa->allocReg();
            $out .= '  ' . $old . ' = load i64, ptr ' . $p . "\n";
            $new = $this->ssa->allocReg();
            $out .= '  ' . $new . ' = ' . $instr . ' i64 ' . $old . ", 1\n";
            $out .= '  store i64 ' . $new . ', ptr ' . $p . "\n";
            $this->lastValue = $d->prefix ? $new : $old;
            $this->lastValueType = 'i64';
            return $out;
        }
        $slot = $this->locals->slots[$d->name] ?? null;
        if ($slot === null) {
            // No prior assignment seen — treat as starting from 0.
            $slot = $this->ssa->allocReg();
            $this->locals->slots[$d->name] = $slot;
            $out = '  ' . $slot . " = alloca i64\n";
            $out .= '  store i64 0, ptr ' . $slot . "\n";
        } else {
            $out = '';
        }
        $old = $this->ssa->allocReg();
        $out .= '  ' . $old . ' = load i64, ptr ' . $slot . "\n";
        $new = $this->ssa->allocReg();
        $out .= '  ' . $new . ' = ' . $instr . ' i64 ' . $old . ", 1\n";
        $out .= '  store i64 ' . $new . ', ptr ' . $slot . "\n";
        $this->lastValue = $d->prefix ? $new : $old;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitConcat(Concat $n): string
    {
        $this->rt->needsConcat = true;
        $c = $n;
        // Route confined (Arena) concats through the arena allocator so
        // they are bulk-freed at the frame's mem_arena_leave; escaping
        // ones stay on the heap. The kind comes from InferAllocKind via
        // the MemoryOps contract — EmitLlvm never decides it here. The
        // operand int/float→string coercions are confined too, so they
        // bump-allocate alongside the concat buffer.
        $arena = $n->allocKind === \Compile\Mir\AllocationKind::ARENA;
        if ($arena) { $this->rt->needsArena = true; }
        // Tier-1 fusion: a chain `a.b.c.d` lowers to nested Concat nodes, each
        // doing its own malloc+memcpy (N-1 mallocs, N-2 dead intermediates).
        // Flatten the chain to its leaf operands and build the result in ONE
        // malloc. Operand lengths stay on libc strlen (NOT len@-16) — fusion
        // touches only the allocation count, never the length-read path, so it
        // sidesteps the layout-sensitive self-host heisenbug.
        $ops = [];
        $this->flattenConcat($c, $ops);
        // Adjacent string literals merge into one (ConstFold only folds a fully
        // constant pair bottom-up, so `$x."a"."b"` still arrives as two lits):
        // fewer operands, fewer memcpys, and their length is compile-time known.
        $ops = $this->mergeAdjacentStrConsts($ops);
        if (count($ops) === 1) {
            // Everything merged to one literal (only if ConstFold somehow left
            // it) — just yield that value; immortal, nothing to free.
            $out = $this->emitNode($ops[0]);
            return $out . $this->coerceToStr($ops[0], $arena);
        }
        // The fused path formats int operands straight into the buffer (no
        // int_to_str temp). Use it for >2 operands, and for a 2-operand concat
        // that has an int operand (e.g. `"key".$i`, `$id.":"`); a pure string
        // pair keeps the single-__mir_concat fast path.
        if (count($ops) > 2 || $this->hasIntConcatOperand($ops)) {
            return $this->emitConcatFused($ops, $arena);
        }
        return $this->emitConcatPair($ops[0], $ops[1], $arena);
    }

    /** Two-operand concat via the __mir_concat runtime (the stable path). */
    private function emitConcatPair(Node $l, Node $r, bool $arena): string
    {
        $out = $this->emitNode($l);
        $out .= $this->coerceToStr($l, $arena);
        $lp = $this->lastValue;
        $out .= $this->emitNode($r);
        $out .= $this->coerceToStr($r, $arena);
        $rp = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $fn = $arena ? '@__mir_concat_arena' : '@__mir_concat';
        $out .= '  ' . $reg . ' = call ptr ' . $fn . '(ptr ' . $lp . ', ptr ' . $rp . ")\n";
        // The concat copied both operands' bytes; a freshly-produced operand
        // (int/float/bool coercion temp, or a nested concat / string-builtin
        // call result) is now dead and freed here. Borrowed operands (a
        // literal, a local, a property / element read) and cell coercions
        // (tagged_to_str may hand back a borrowed inner ptr) are left alone.
        $out .= $this->concatTempRelease($l, $lp);
        $out .= $this->concatTempRelease($r, $rp);
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Collapse runs of adjacent string-literal operands into a single literal.
     * @param Node[] $ops
     * @return Node[]
     */
    private function mergeAdjacentStrConsts(array $ops): array
    {
        /** @var Node[] */
        $merged = [];
        foreach ($ops as $op) {
            $n = count($merged);
            if ($op->kind === Node::KIND_STRING_CONST && $n > 0) {
                $prev = $merged[$n - 1];
                if ($prev->kind === Node::KIND_STRING_CONST) {
                    $cur = $op;
                    $merged[$n - 1] = new StringConst($prev->value . $cur->value, Type::string_());
                    continue;
                }
            }
            $merged[] = $op;
        }
        return $merged;
    }

    /**
     * Fused N-way concat: emit every operand once, sum their lengths, allocate
     * a single buffer, memcpy each in at a running offset, write one NUL. One
     * malloc instead of N-1; no dead intermediate strings to free.
     * @param Node[] $ops
     */
    private function emitConcatFused(array $ops, bool $arena): string
    {
        $empty = $this->strSymBytes('@.cstr.empty');
        $out = '';
        $raws = [];   // coerced operand temp (for the post-copy release); '' for int
        $gptrs = [];  // null→"" guarded ptr (what we memcpy from); '' for int
        $lens = [];   // byte length: a compile-time const for a literal, else strlen
        $intVals = []; // int operand's i64 value reg (formatted in-place), else ''
        foreach ($ops as $op) {
            // An int operand is formatted straight into the buffer via
            // __mir_int_fmt — no int_to_str temp string / memcpy / release.
            if ($op->type->kind === Type::KIND_INT) {
                $out .= $this->emitNode($op);
                $out .= $this->coerceToI64();
                $iv = $this->lastValue;
                $intVals[] = $iv;
                $raws[] = '';
                $gptrs[] = '';
                $l = $this->ssa->allocReg();
                $out .= '  ' . $l . ' = call i64 @__mir_int_len(i64 ' . $iv . ")\n";
                $lens[] = $l;
                continue;
            }
            $intVals[] = '';
            $out .= $this->emitNode($op);
            $out .= $this->coerceToStr($op, $arena);
            $raw = $this->lastValue;
            $raws[] = $raw;
            // A string literal is never null and its length is known at compile
            // time — skip the null-guard AND the runtime strlen scan. Also
            // binary-safe: a literal with an embedded NUL keeps its true byte
            // length here, where libc strlen would truncate it.
            if ($op->kind === Node::KIND_STRING_CONST) {
                $gptrs[] = $raw;
                $lens[] = (string) \strlen($op->value);
                continue;
            }
            // A null `?string` operand concatenates as "" (PHP), not a memcpy
            // of null — map 0 to the empty C-string, exactly like __mir_concat.
            $nn = $this->ssa->allocReg();
            $out .= '  ' . $nn . ' = icmp eq ptr ' . $raw . ", null\n";
            $g = $this->ssa->allocReg();
            $out .= '  ' . $g . ' = select i1 ' . $nn . ', ptr ' . $empty
                  . ', ptr ' . $raw . "\n";
            $gptrs[] = $g;
            // O(1) binary-safe length (len@-16) with a libc-strlen fallback for
            // a raw operand — same contract as __mir_concat.
            $l = $this->ssa->allocReg();
            $out .= '  ' . $l . ' = call i64 @__mir_strlen(ptr ' . $g . ")\n";
            $lens[] = $l;
        }
        $sum = $lens[0];
        $n = count($lens);
        for ($i = 1; $i < $n; $i++) {
            $ns = $this->ssa->allocReg();
            $out .= '  ' . $ns . ' = add i64 ' . $sum . ', ' . $lens[$i] . "\n";
            $sum = $ns;
        }
        $sz = $this->ssa->allocReg();
        $out .= '  ' . $sz . ' = add i64 ' . $sum . ", 1\n";
        $alloc = $arena ? '@__mir_str_alloc_arena' : '@__mir_str_alloc';
        $buf = $this->ssa->allocReg();
        $out .= '  ' . $buf . ' = call ptr ' . $alloc . '(i64 ' . $sz . ")\n";
        // Copy each operand at a running offset: an int operand is formatted
        // in place (__mir_int_fmt), a string operand is memcpy'd.
        $off = '0';
        for ($i = 0; $i < $n; $i++) {
            if ($intVals[$i] !== '') {
                $out .= '  call void @__mir_int_fmt(ptr ' . $buf . ', i64 '
                      . $off . ', i64 ' . $intVals[$i] . ")\n";
            } elseif ($off === '0') {
                $out .= '  call ptr @memcpy(ptr ' . $buf . ', ptr ' . $gptrs[$i]
                      . ', i64 ' . $lens[$i] . ")\n";
            } else {
                $d = $this->ssa->allocReg();
                $out .= '  ' . $d . ' = getelementptr inbounds i8, ptr ' . $buf
                      . ', i64 ' . $off . "\n";
                $out .= '  call ptr @memcpy(ptr ' . $d . ', ptr ' . $gptrs[$i]
                      . ', i64 ' . $lens[$i] . ")\n";
            }
            if ($i < $n - 1) {
                $no = $this->ssa->allocReg();
                $out .= '  ' . $no . ' = add i64 ' . $off . ', ' . $lens[$i] . "\n";
                $off = $no;
            }
        }
        $dend = $this->ssa->allocReg();
        $out .= '  ' . $dend . ' = getelementptr inbounds i8, ptr ' . $buf
              . ', i64 ' . $sum . "\n";
        $out .= '  store i8 0, ptr ' . $dend . "\n";
        foreach ($ops as $i => $op) {
            // Int operands were formatted in place — no temp to release.
            if ($intVals[$i] !== '') { continue; }
            $out .= $this->concatTempRelease($op, $raws[$i]);
        }
        $this->lastValue = $buf;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Materialize `$this->lastValue` as a string `ptr` for concat:
     * string stays as-is (inttoptr if carried as i64); int / bool /
     * other scalars route through `__mir_int_to_str`. Float text
     * isn't precise yet — it degrades to the integer formatter,
     * which is wrong for fractional values (tracked for a follow-up).
     */
    private function coerceToStr(Node $operand, bool $arena = false): string
    {
        if ($operand->type->kind === Type::KIND_STRING) {
            return $this->coerceToPtr();
        }
        // null in a string context → "" (PHP), not "0" from the int path.
        if ($operand->type->kind === Type::KIND_NULL) {
            $this->lastValue = $this->strSymBytes('@.cstr.empty');
            $this->lastValueType = 'ptr';
            return '';
        }
        // A tagged cell (mixed) → dispatch on its tag at runtime.
        if ($operand->type->kind === Type::KIND_CELL) {
            $this->rt->needsTaggedToStr = true;
            $out = $this->coerceToI64();
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call ptr @__manticore_tagged_to_str(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'ptr';
            return $out;
        }
        $ts = $this->toStringClassOf($operand);
        if ($ts !== '') {
            return $this->emitToStringCall($ts);
        }
        // When the result feeds an arena-bound consumer (an Arena concat),
        // the coercion buffer is confined too — bump-allocate it so it is
        // freed at the same scope exit instead of leaking on the heap.
        if ($operand->type->kind === Type::KIND_FLOAT) {
            $this->rt->needsFloatStr = true;
            $out = $this->coerceTo('double');
            $reg = $this->ssa->allocReg();
            $fn = $arena ? '@__mir_float_to_str_arena' : '@__mir_float_to_str';
            if ($arena) { $this->rt->needsArena = true; }
            $out .= '  ' . $reg . ' = call ptr ' . $fn . '(double ' . $this->lastValue . ")\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'ptr';
            return $out;
        }
        $this->rt->needsIntStr = true;
        $out = $this->coerceToI64();
        $reg = $this->ssa->allocReg();
        $fn = $arena ? '@__mir_int_to_str_arena' : '@__mir_int_to_str';
        if ($arena) { $this->rt->needsArena = true; }
        $out .= '  ' . $reg . ' = call ptr ' . $fn . '(i64 ' . $this->lastValue . ")\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
        return $out;
    }

    private function emitCmp(Cmp $n): string
    {
        $c = $n;
        // `X === null` / `!== null` (and == / !=) — null-ness is a
        // compile-time property of the operand's type (the i64 carrier
        // can't tell null from int 0 at runtime). Evaluate the non-null
        // side for its side effects, then yield the constant.
        $leftNull = $c->left->kind === Node::KIND_NULL_CONST;
        $rightNull = $c->right->kind === Node::KIND_NULL_CONST;
        $op = $c->op;
        $isEq = ($op === '===' || $op === '==');
        $isNe = ($op === '!==' || $op === '!=');
        // LOOSE `X == null` does NOT test null-ness — PHP juggles it. Against a
        // STRING, NULL becomes "" and they compare as strings, so `null == ""`
        // is true but `null == "0"` is FALSE (the bool row would call "0" falsy
        // and wrongly answer true). Against anything else both sides go to bool,
        // so `null == false`, `null == 0` and `null == []` are all true. Folding
        // this to a constant off the static type (as the strict path below does,
        // soundly) answered false for every one of them.
        $looseNullKinds = [
            Type::KIND_STRING => true, Type::KIND_NULL  => true, Type::KIND_OBJ   => true,
            Type::KIND_CLOSURE => true, Type::KIND_CELL => true, Type::KIND_UNKNOWN => true,
            Type::KIND_ARRAY  => true, Type::KIND_FLOAT => true, Type::KIND_INT   => true,
            Type::KIND_BOOL   => true,
        ];
        if (($leftNull || $rightNull) && ($isEq || $isNe)
            && !($op === '===' || $op === '!==') && !($leftNull && $rightNull)
            && isset($looseNullKinds[($leftNull ? $c->right : $c->left)->type->kind])) {
            $other = $leftNull ? $c->right : $c->left;
            $ok = $other->type->kind;
            $out = $this->emitNode($other);
            $res = null;
            if ($ok === Type::KIND_STRING) {
                $out .= $this->coerceToPtr();
                $len = $this->ssa->allocReg();
                $out .= '  ' . $len . ' = call i64 @__mir_strlen(ptr ' . $this->lastValue . ")\n";
                $b = $this->ssa->allocReg();
                $out .= '  ' . $b . ' = icmp ' . ($isEq ? 'eq' : 'ne') . ' i64 ' . $len . ", 0\n";
                $res = $this->ssa->allocReg();
                $out .= '  ' . $res . ' = zext i1 ' . $b . " to i64\n";
            } elseif ($ok === Type::KIND_NULL) {
                $res = $isEq ? '1' : '0';
            } elseif ($ok === Type::KIND_OBJ || $ok === Type::KIND_CLOSURE) {
                // An object is always truthy, so it is never loosely null.
                $res = $isEq ? '0' : '1';
            } elseif ($ok === Type::KIND_CELL || $ok === Type::KIND_UNKNOWN) {
                $this->rt->needsTaggedEq = true;
                $out .= $this->coerceToI64();
                $cv = $this->lastValue;
                $nb = $this->ssa->allocReg();
                $out .= '  ' . $nb . " = call i64 @__manticore_box_null()\n";
                $e = $this->ssa->allocReg();
                $out .= '  ' . $e . ' = call i64 @__manticore_tagged_loose_eq(i64 ' . $nb . ', i64 ' . $cv . ")\n";
                $res = $e;
                if ($isNe) {
                    $res = $this->ssa->allocReg();
                    $out .= '  ' . $res . ' = xor i64 ' . $e . ", 1\n";
                }
            } elseif ($ok === Type::KIND_ARRAY) {
                $this->rt->needsTagged = true;
                $this->rt->needsTaggedTruthy = true;
                $out .= $this->coerceToPtr();
                $ba = $this->ssa->allocReg();
                $out .= '  ' . $ba . ' = call i64 @__manticore_box_array(ptr ' . $this->lastValue . ")\n";
                $tv = $this->ssa->allocReg();
                $out .= '  ' . $tv . ' = call i64 @__manticore_tagged_truthy(i64 ' . $ba . ")\n";
                $b = $this->ssa->allocReg();
                $out .= '  ' . $b . ' = icmp ' . ($isEq ? 'eq' : 'ne') . ' i64 ' . $tv . ", 0\n";
                $res = $this->ssa->allocReg();
                $out .= '  ' . $res . ' = zext i1 ' . $b . " to i64\n";
            } elseif ($ok === Type::KIND_FLOAT) {
                $out .= $this->coerceTo('double');
                $b = $this->ssa->allocReg();
                $out .= '  ' . $b . ' = fcmp ' . ($isEq ? 'oeq' : 'une')
                      . ' double ' . $this->lastValue . ", 0.000000e+00\n";
                $res = $this->ssa->allocReg();
                $out .= '  ' . $res . ' = zext i1 ' . $b . " to i64\n";
            } elseif ($ok === Type::KIND_INT || $ok === Type::KIND_BOOL) {
                $out .= $this->coerceToI64();
                $b = $this->ssa->allocReg();
                $out .= '  ' . $b . ' = icmp ' . ($isEq ? 'eq' : 'ne') . ' i64 ' . $this->lastValue . ", 0\n";
                $res = $this->ssa->allocReg();
                $out .= '  ' . $res . ' = zext i1 ' . $b . " to i64\n";
            }
            if ($res !== null) {
                $this->lastValue = $res;
                $this->lastValueType = 'i64';
                return $out;
            }
        }
        if (($leftNull || $rightNull) && ($isEq || $isNe)) {
            $other = $leftNull ? $c->right : $c->left;
            // Pointer-carried operands (string / obj / vec / assoc / closure)
            // and unknown-typed ones (e.g. a `null`-initialised accumulator
            // that unions to `unknown`) are genuinely null at runtime when
            // their i64 carrier is 0. Compare the carrier instead of folding
            // to a compile-time constant from the static type — the fold is
            // only valid for scalars (int/float/bool can't carry null) and a
            // literally-null operand.
            $ok = $other->type->kind;
            // A `mixed`/cell operand carries its type in a NaN tag — a boxed
            // null (tag NULL=3) is NOT i64 0, so compare the tag at runtime.
            // (`$o === null` in an SPL offsetSet is the canonical case.)
            if (!($leftNull && $rightNull) && $ok === Type::KIND_CELL) {
                $out = $this->emitNode($other);
                $out .= $this->coerceToI64();
                $out .= $this->cellTagIr($this->lastValue);
                $tag = $this->cellTagReg;
                $r = $this->ssa->allocReg();
                $out .= '  ' . $r . ' = icmp ' . ($isEq ? 'eq' : 'ne')
                      . ' i64 ' . $tag . ", 3\n";
                $z = $this->ssa->allocReg();
                $out .= '  ' . $z . ' = zext i1 ' . $r . " to i64\n";
                $this->lastValue = $z;
                $this->lastValueType = 'i64';
                return $out;
            }
            $ptrCarried = $ok === Type::KIND_STRING || $ok === Type::KIND_OBJ
                || $ok === Type::KIND_ARRAY
                || $ok === Type::KIND_CLOSURE || $ok === Type::KIND_UNKNOWN;
            if (!($leftNull && $rightNull) && $ptrCarried) {
                $out = $this->emitNode($other);
                $out .= $this->coerceToI64();
                $r = $this->ssa->allocReg();
                $out .= '  ' . $r . ' = icmp ' . ($isEq ? 'eq' : 'ne')
                      . ' i64 ' . $this->lastValue . ", 0\n";
                $z = $this->ssa->allocReg();
                $out .= '  ' . $z . ' = zext i1 ' . $r . " to i64\n";
                $this->lastValue = $z;
                $this->lastValueType = 'i64';
                return $out;
            }
            $out = '';
            if (!$leftNull)  { $out .= $this->emitNode($c->left); }
            if (!$rightNull) { $out .= $this->emitNode($c->right); }
            $otherIsNull = ($leftNull && $rightNull) || $other->type->kind === Type::KIND_NULL;
            $res = $isEq ? ($otherIsNull ? '1' : '0') : ($otherIsNull ? '0' : '1');
            $this->lastValue = $res;
            $this->lastValueType = 'i64';
            return $out;
        }
        // `===` / `!==` between two statically-different scalar kinds folds to
        // false / true: PHP's strict compare demands identical types, and
        // int/float/bool all ride the same raw i64|double carrier. Without the
        // fold the generic path compares carriers, so `0 === false`, `1 === true`
        // and `0 === 0.0` all came out true. Both sides are still emitted — the
        // operands may have side effects. (string/null carry distinctly and are
        // handled above; cell/unknown stay runtime-tagged and must not fold.)
        $lkS = $c->left->type->kind;
        $rkS = $c->right->type->kind;
        $rawScalar = [Type::KIND_INT => true, Type::KIND_FLOAT => true, Type::KIND_BOOL => true];
        if (($op === '===' || $op === '!==')
            && isset($rawScalar[$lkS]) && isset($rawScalar[$rkS])
            && $lkS !== $rkS) {
            $out = $this->emitNode($c->left);
            $out .= $this->emitNode($c->right);
            $this->lastValue = ($op === '===') ? '0' : '1';
            $this->lastValueType = 'i64';
            return $out;
        }

        // `cell === false` / `!== false` (e.g. `strpos(...) === false`).
        // A NaN-boxed `int|false` is false iff its tag is BOOL(2); a
        // boxed int never equals false. Compare the tag, skip payload.
        $lCell = $c->left->type->kind === Type::KIND_CELL;
        $rCell = $c->right->type->kind === Type::KIND_CELL;
        $lFalse = $c->left->kind === Node::KIND_BOOL_CONST && !$c->left->value;
        $rFalse = $c->right->kind === Node::KIND_BOOL_CONST && !$c->right->value;
        if (($isEq || $isNe) && (($lCell && $rFalse) || ($rCell && $lFalse))) {
            $cellNode = $lCell ? $c->left : $c->right;
            $out = $this->emitNode($cellNode);
            $out .= $this->coerceToI64();
            $v = $this->lastValue;
            $out .= $this->cellTagIr($v);
            $tag = $this->cellTagReg;
            $cmpReg = $this->ssa->allocReg();
            $pred = $isEq ? 'eq' : 'ne';
            $out .= '  ' . $cmpReg . ' = icmp ' . $pred . ' i64 ' . $tag . ", 2\n";
            $extReg = $this->ssa->allocReg();
            $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
            $this->lastValue = $extReg;
            $this->lastValueType = 'i64';
            return $out;
        }

        // `$x === []` / `$x !== []` — PHP compares arrays by content, so an
        // empty-array literal operand means "is $x empty?". Comparing the
        // (always-distinct) buffer pointers would make `[] !== []` true.
        // Use the length instead.
        $lEmptyLit = $this->isEmptyArrayLit($c->left);
        $rEmptyLit = $this->isEmptyArrayLit($c->right);
        if (($isEq || $isNe) && ($lEmptyLit || $rEmptyLit)) {
            if ($lEmptyLit && $rEmptyLit) {
                $this->lastValue = $isEq ? '1' : '0';
                $this->lastValueType = 'i64';
                return '';
            }
            $arrNode = $lEmptyLit ? $c->right : $c->left;
            $ak = $arrNode->type->kind;
            if ($ak === Type::KIND_ARRAY || $ak === Type::KIND_UNKNOWN) {
                $out = $this->emitNode($arrNode);
                $out .= $this->coerceToPtr();
                $len = $this->ssa->allocReg();
                $out .= '  ' . $len . ' = load i64, ptr ' . $this->lastValue . "\n";
                $cmpReg = $this->ssa->allocReg();
                $out .= '  ' . $cmpReg . ' = icmp ' . ($isEq ? 'eq' : 'ne')
                      . ' i64 ' . $len . ", 0\n";
                $extReg = $this->ssa->allocReg();
                $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
                $this->lastValue = $extReg;
                $this->lastValueType = 'i64';
                return $out;
            }
        }

        $out = $this->emitNode($c->left);
        $l = $this->lastValue;
        $lt = $this->lastValueType;
        $lk = $c->left->type->kind;
        $out .= $this->emitNode($c->right);
        $r = $this->lastValue;
        $rt = $this->lastValueType;
        $rk = $c->right->type->kind;

        // `cell === enum` / `enum === cell` — an enum case in a cell is
        // box_object(per-case singleton). Box the raw-ordinal enum operand to
        // its singleton cell too and compare carriers: same case → same global
        // → equal (identity); a non-enum cell or a different case differs.
        if ($isEq || $isNe) {
            $lEnum = $lk === Type::KIND_OBJ && isset($this->enums[$c->left->type->class ?? '']);
            $rEnum = $rk === Type::KIND_OBJ && isset($this->enums[$c->right->type->class ?? '']);
            if (($lk === Type::KIND_CELL && $rEnum) || ($lEnum && $rk === Type::KIND_CELL)) {
                if ($lEnum) {
                    $this->lastValue = $l; $this->lastValueType = $lt;
                    $out .= $this->boxToCell($c->left->type);
                    $l = $this->lastValue; $lt = $this->lastValueType;
                } else {
                    $this->lastValue = $r; $this->lastValueType = $rt;
                    $out .= $this->boxToCell($c->right->type);
                    $r = $this->lastValue; $rt = $this->lastValueType;
                }
                if ($lt === 'ptr') { $tmp = $this->ssa->allocReg(); $out .= '  ' . $tmp . ' = ptrtoint ptr ' . $l . " to i64\n"; $l = $tmp; }
                if ($rt === 'ptr') { $tmp = $this->ssa->allocReg(); $out .= '  ' . $tmp . ' = ptrtoint ptr ' . $r . " to i64\n"; $r = $tmp; }
                $cmpReg = $this->ssa->allocReg();
                $out .= '  ' . $cmpReg . ' = icmp ' . ($isEq ? 'eq' : 'ne') . ' i64 ' . $l . ', ' . $r . "\n";
                $z = $this->ssa->allocReg();
                $out .= '  ' . $z . ' = zext i1 ' . $cmpReg . " to i64\n";
                $this->lastValue = $z;
                $this->lastValueType = 'i64';
                return $out;
            }
        }

        // `string === cell` / `cell === string` (strict): equal iff the cell is
        // a string (NaN tag PTR=4) whose bytes match the known string. A
        // non-string cell is never strictly ===. (Loose ==/!= juggles types and
        // is left to the fallthrough.)
        $strictEq = $op === '===' || $op === '!==';
        if ($strictEq
            && (($lk === Type::KIND_STRING && $rk === Type::KIND_CELL)
                || ($lk === Type::KIND_CELL && $rk === Type::KIND_STRING))) {
            $this->rt->needsStrcmp = true;
            $cellI = ($lk === Type::KIND_CELL) ? $l : $r;
            $cellT = ($lk === Type::KIND_CELL) ? $lt : $rt;
            $strV  = ($lk === Type::KIND_CELL) ? $r : $l;
            $strT  = ($lk === Type::KIND_CELL) ? $rt : $lt;
            $ci = $cellI;
            if ($cellT === 'ptr') { $ci = $this->ssa->allocReg(); $out .= '  ' . $ci . ' = ptrtoint ptr ' . $cellI . " to i64\n"; }
            $sp = $strV;
            if ($strT !== 'ptr') { $sp = $this->ssa->allocReg(); $out .= '  ' . $sp . ' = inttoptr i64 ' . $strV . " to ptr\n"; }
            $out .= $this->cellTagIr($ci); $tag = $this->cellTagReg;
            $isStr = $this->ssa->allocReg(); $out .= '  ' . $isStr . ' = icmp eq i64 ' . $tag . ", 4\n";
            // Guard a null string carrier (a `?string` operand) — skip the deref.
            $stri = $strV;
            if ($strT === 'ptr') { $stri = $this->ssa->allocReg(); $out .= '  ' . $stri . ' = ptrtoint ptr ' . $strV . " to i64\n"; }
            $spNN = $this->ssa->allocReg(); $out .= '  ' . $spNN . ' = icmp ne i64 ' . $stri . ", 0\n";
            $can = $this->ssa->allocReg(); $out .= '  ' . $can . ' = and i1 ' . $isStr . ', ' . $spNN . "\n";
            $cmpL = $this->ssa->allocLabel('streqc.cmp');
            $nsL = $this->ssa->allocLabel('streqc.ns');
            $jnL = $this->ssa->allocLabel('streqc.join');
            $out .= '  br i1 ' . $can . ', label %' . $cmpL . ', label %' . $nsL . "\n";
            $out .= $cmpL . ":\n";
            $payload = $this->ssa->allocReg(); $out .= '  ' . $payload . ' = and i64 ' . $ci . ", 281474976710655\n";
            $cp = $this->ssa->allocReg(); $out .= '  ' . $cp . ' = inttoptr i64 ' . $payload . " to ptr\n";
            $eqc = $this->ssa->allocReg(); $out .= '  ' . $eqc . ' = call i1 @__mir_str_eq(ptr ' . $sp . ', ptr ' . $cp . ")\n";
            $out .= '  br label %' . $jnL . "\n";
            $out .= $nsL . ":\n  br label %" . $jnL . "\n";
            $out .= $jnL . ":\n";
            $phi = $this->ssa->allocReg();
            $out .= '  ' . $phi . ' = phi i1 [ ' . $eqc . ', %' . $cmpL . ' ], [ false, %' . $nsL . " ]\n";
            $res = $phi;
            if ($op === '!==') { $res = $this->ssa->allocReg(); $out .= '  ' . $res . ' = xor i1 ' . $phi . ", true\n"; }
            $z = $this->ssa->allocReg(); $out .= '  ' . $z . ' = zext i1 ' . $res . " to i64\n";
            $this->lastValue = $z; $this->lastValueType = 'i64';
            return $out;
        }
        // `cell === float` / `float === cell` (strict): equal iff the cell is a
        // FLOAT (tag 6) whose value equals the float operand. A non-float cell is
        // never strictly === a float. Compare the unboxed double (a float cell is
        // raw double bits under canonical NaN-boxing) — NOT the carrier i64.
        if ($strictEq
            && (($lk === Type::KIND_FLOAT && $rk === Type::KIND_CELL)
                || ($lk === Type::KIND_CELL && $rk === Type::KIND_FLOAT))) {
            $this->rt->needsTaggedToFloat = true;
            $ci    = ($lk === Type::KIND_CELL) ? $l : $r;
            $cellT = ($lk === Type::KIND_CELL) ? $lt : $rt;
            $fltV  = ($lk === Type::KIND_CELL) ? $r : $l;
            $fltT  = ($lk === Type::KIND_CELL) ? $rt : $lt;
            if ($cellT === 'ptr') { $cp = $this->ssa->allocReg(); $out .= '  ' . $cp . ' = ptrtoint ptr ' . $ci . " to i64\n"; $ci = $cp; }
            $fd = $fltV;
            if ($fltT !== 'double') { $fd = $this->ssa->allocReg(); $out .= '  ' . $fd . ' = bitcast i64 ' . $fltV . " to double\n"; }
            $out .= $this->cellTagIr($ci); $tag = $this->cellTagReg;
            $isFlt = $this->ssa->allocReg(); $out .= '  ' . $isFlt . ' = icmp eq i64 ' . $tag . ", 6\n";
            $cd = $this->ssa->allocReg(); $out .= '  ' . $cd . ' = call double @__manticore_tagged_to_double(i64 ' . $ci . ")\n";
            $eqf = $this->ssa->allocReg(); $out .= '  ' . $eqf . ' = fcmp oeq double ' . $cd . ', ' . $fd . "\n";
            $res = $this->ssa->allocReg(); $out .= '  ' . $res . ' = and i1 ' . $isFlt . ', ' . $eqf . "\n";
            if ($op === '!==') { $nn = $this->ssa->allocReg(); $out .= '  ' . $nn . ' = xor i1 ' . $res . ", true\n"; $res = $nn; }
            $z = $this->ssa->allocReg(); $out .= '  ' . $z . ' = zext i1 ' . $res . " to i64\n";
            $this->lastValue = $z; $this->lastValueType = 'i64';
            return $out;
        }
        // Loose ==/!= between a STRING and a NUMBER (int/float): PHP numeric-string
        // juggling. A numeric string ("10", "1e2") compares BY VALUE; a
        // non-numeric string ("abc") is never == a number (PHP 8 casts the number
        // to string, which a non-numeric string can't match). A null `?string`
        // carrier coerces to numeric 0.
        // LOOSE only — `"10" === 10` stays false (distinct types).
        $looseEqNum = $op === '==' || $op === '!=';
        if ($looseEqNum
            && (($lk === Type::KIND_STRING && ($rk === Type::KIND_INT || $rk === Type::KIND_FLOAT))
                || ($rk === Type::KIND_STRING && ($lk === Type::KIND_INT || $lk === Type::KIND_FLOAT)))) {
            $this->rt->needsTaggedEq = true;   // emits __mir_is_numeric_str
            $this->rt->needsStrtod = true;
            $lStr = $lk === Type::KIND_STRING;
            $strV = $lStr ? $l : $r; $strT = $lStr ? $lt : $rt;
            $numV = $lStr ? $r : $l; $numT = $lStr ? $rt : $lt;
            $numK = $lStr ? $rk : $lk;
            $si = $strV;
            if ($strT === 'ptr') { $si = $this->ssa->allocReg(); $out .= '  ' . $si . ' = ptrtoint ptr ' . $strV . " to i64\n"; }
            $sp = $this->ssa->allocReg(); $out .= '  ' . $sp . ' = inttoptr i64 ' . $si . " to ptr\n";
            if ($numK === Type::KIND_FLOAT && $numT === 'double') {
                $nd = $numV;
            } elseif ($numK === Type::KIND_FLOAT) {
                $nd = $this->ssa->allocReg(); $out .= '  ' . $nd . ' = bitcast i64 ' . $numV . " to double\n";
            } else {
                $nd = $this->ssa->allocReg(); $out .= '  ' . $nd . ' = sitofp i64 ' . $numV . " to double\n";
            }
            $snz = $this->ssa->allocReg(); $out .= '  ' . $snz . ' = icmp ne i64 ' . $si . ", 0\n";
            $chkL = $this->ssa->allocLabel('nseq.chk');
            $nullL = $this->ssa->allocLabel('nseq.null');
            $numL = $this->ssa->allocLabel('nseq.num');
            $nnumL = $this->ssa->allocLabel('nseq.nnum');
            $joinL = $this->ssa->allocLabel('nseq.join');
            $out .= '  br i1 ' . $snz . ', label %' . $chkL . ', label %' . $nullL . "\n";
            $out .= $chkL . ":\n";
            $isn = $this->ssa->allocReg(); $out .= '  ' . $isn . ' = call i1 @__mir_is_numeric_str(ptr ' . $sp . ")\n";
            $out .= '  br i1 ' . $isn . ', label %' . $numL . ', label %' . $nnumL . "\n";
            $out .= $numL . ":\n";
            $sd = $this->ssa->allocReg(); $out .= '  ' . $sd . ' = call double @strtod(ptr ' . $sp . ", ptr null)\n";
            $eqn = $this->ssa->allocReg(); $out .= '  ' . $eqn . ' = fcmp oeq double ' . $sd . ', ' . $nd . "\n";
            $out .= '  br label %' . $joinL . "\n";
            $out .= $nnumL . ":\n  br label %" . $joinL . "\n";
            $out .= $nullL . ":\n";
            $eqz = $this->ssa->allocReg(); $out .= '  ' . $eqz . ' = fcmp oeq double 0.0, ' . $nd . "\n";
            $out .= '  br label %' . $joinL . "\n";
            $out .= $joinL . ":\n";
            $phi = $this->ssa->allocReg();
            $out .= '  ' . $phi . ' = phi i1 [ ' . $eqn . ', %' . $numL . ' ], [ false, %' . $nnumL . ' ], [ ' . $eqz . ', %' . $nullL . " ]\n";
            $res = $phi;
            if ($isNe) { $res = $this->ssa->allocReg(); $out .= '  ' . $res . ' = xor i1 ' . $phi . ", true\n"; }
            $z = $this->ssa->allocReg(); $out .= '  ' . $z . ' = zext i1 ' . $res . " to i64\n";
            $this->lastValue = $z; $this->lastValueType = 'i64';
            return $out;
        }
        // String ordering / equality → strcmp(l, r) <pred> 0. Fires when
        // one side is a known string and the other is a string OR unknown
        // (e.g. an `array $args` element whose element type was erased to
        // i64): without this the fallthrough does a POINTER compare, which
        // only accidentally matches interned literals and fails on runtime
        // strings (argv, file reads). A known non-string operand (int /
        // obj / …) is excluded — that stays a value/identity compare.
        $lStrish = $lk === Type::KIND_STRING || $lk === Type::KIND_UNKNOWN;
        $rStrish = $rk === Type::KIND_STRING || $rk === Type::KIND_UNKNOWN;
        if (($lk === Type::KIND_STRING || $rk === Type::KIND_STRING)
            && $lStrish && $rStrish) {
            $this->rt->needsStrcmp = true;
            // i64 carriers for the null guard (a `?string` operand carries 0
            // when null at runtime, e.g. an unset `?string` field).
            $li = $l;
            if ($lt === 'ptr') { $li = $this->ssa->allocReg(); $out .= '  ' . $li . ' = ptrtoint ptr ' . $l . " to i64\n"; }
            $ri = $r;
            if ($rt === 'ptr') { $ri = $this->ssa->allocReg(); $out .= '  ' . $ri . ' = ptrtoint ptr ' . $r . " to i64\n"; }
            $lp = $this->ssa->allocReg();
            $out .= '  ' . $lp . ' = inttoptr i64 ' . $li . " to ptr\n";
            $rp = $this->ssa->allocReg();
            $out .= '  ' . $rp . ' = inttoptr i64 ' . $ri . " to ptr\n";
            // Equality (=== / == / !== / !=): null is a valid operand value
            // (a string is never == to null). strcmp(null, …) dereferences
            // address 0 — guard it: strcmp only when both carriers are
            // non-null; otherwise the result is the i64-carrier identity
            // (both null → equal, one null → unequal).
            if ($isEq || $isNe) {
                $lnz = $this->ssa->allocReg();
                $out .= '  ' . $lnz . ' = icmp ne i64 ' . $li . ", 0\n";
                $rnz = $this->ssa->allocReg();
                $out .= '  ' . $rnz . ' = icmp ne i64 ' . $ri . ", 0\n";
                $both = $this->ssa->allocReg();
                $out .= '  ' . $both . ' = and i1 ' . $lnz . ', ' . $rnz . "\n";
                $scLbl = $this->ssa->allocLabel('streq.cmp');
                $idLbl = $this->ssa->allocLabel('streq.id');
                $jnLbl = $this->ssa->allocLabel('streq.join');
                $out .= '  br i1 ' . $both . ', label %' . $scLbl . ', label %' . $idLbl . "\n";
                $out .= $scLbl . ":\n";
                // Two statically-known strings under LOOSE `==` compare
                // NUMERICALLY when both are numeric strings, so `"1.0" == "1"`
                // is true — byte equality alone answered false. An UNKNOWN-typed
                // (erased) operand keeps the plain byte compare: it may not be a
                // string at all, and juggling it here would be a guess.
                $bothKnownStr = $lk === Type::KIND_STRING && $rk === Type::KIND_STRING;
                $eqFn = '@__mir_str_eq';
                if (!$strictEq && $bothKnownStr) {
                    $eqFn = '@__mir_str_loose_eq';
                    $this->rt->needsTaggedEq = true;
                    $this->rt->needsStrtod = true;
                }
                $eqr = $this->ssa->allocReg();
                $out .= '  ' . $eqr . ' = call i1 ' . $eqFn . '(ptr ' . $lp . ', ptr ' . $rp . ")\n";
                $scRes = $eqr;
                if ($isNe) {
                    $scRes = $this->ssa->allocReg();
                    $out .= '  ' . $scRes . ' = xor i1 ' . $eqr . ", true\n";
                }
                $out .= '  br label %' . $jnLbl . "\n";
                $out .= $idLbl . ":\n";
                $idRes = $this->ssa->allocReg();
                $out .= '  ' . $idRes . ' = icmp ' . ($isEq ? 'eq' : 'ne') . ' i64 ' . $li . ', ' . $ri . "\n";
                $out .= '  br label %' . $jnLbl . "\n";
                $out .= $jnLbl . ":\n";
                $phi = $this->ssa->allocReg();
                $out .= '  ' . $phi . ' = phi i1 [ ' . $scRes . ', %' . $scLbl . ' ], [ ' . $idRes . ', %' . $idLbl . " ]\n";
                $extReg = $this->ssa->allocReg();
                $out .= '  ' . $extReg . ' = zext i1 ' . $phi . " to i64\n";
                $this->lastValue = $extReg;
                $this->lastValueType = 'i64';
                return $out;
            }
            $call = $this->ssa->allocReg();
            $out .= '  ' . $call . ' = call i64 @__mir_str_cmp(ptr ' . $lp . ', ptr ' . $rp . ")\n";
            $cmpReg = $this->ssa->allocReg();
            $out .= '  ' . $cmpReg . ' = icmp ' . $this->cmpPredicate($c->op) . ' i64 ' . $call . ", 0\n";
            $extReg = $this->ssa->allocReg();
            $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
            $this->lastValue = $extReg;
            $this->lastValueType = 'i64';
            return $out;
        }

        // Two objects of the same statically-known class under LOOSE `==`: PHP
        // compares them PROPERTY-BY-PROPERTY (loosely), not by handle, so
        // `new P(1,"a") == new P(1,"a")` is true and `!=` is its negation (both
        // came out backwards on the pointer path). Unrolled from the static
        // layout — there is no runtime property table to walk (the descriptor is
        // just {class_id, drop_fn}), which is also why an object reached through
        // a CELL still falls back to identity in the tagged runtime.
        // Bail to the old identity path when the unroll would be a guess: a
        // subclassable class (the runtime class may differ from the static one,
        // and PHP demands equal classes), or an array property whose element
        // representation isn't one the array runtime can normalize.
        if (($isEq || $isNe) && !$strictEq
            && $lk === Type::KIND_OBJ && $rk === Type::KIND_OBJ) {
            $cls = $c->left->type->class ?? '';
            $rcls = $c->right->type->class ?? '';
            if ($cls !== '' && $cls === $rcls
                && isset($this->classes[$cls]) && !isset($this->enums[$cls])
                && !$this->classHasSubclass($cls)) {
                $cd = $this->classes[$cls];
                $plan = [];
                $planOk = true;
                foreach ($cd->propertyNames as $pn) {
                    if (!isset($cd->propertyTypes[$pn])) { $planOk = false; break; }
                    $pt = $cd->propertyTypes[$pn];
                    $isArr = $pt->kind === Type::KIND_ARRAY;
                    $chain = self::EK_NONE;
                    if ($isArr) {
                        $chain = $this->elemChainOf($pt->element);
                        if ($chain === self::EK_NONE) { $planOk = false; break; }
                    }
                    $plan[] = [$pn, $pt, $isArr, $chain];
                }
                if ($planOk && \count($plan) > 0) {
                    $this->rt->needsTaggedEq = true;
                    $lp = $l;
                    if ($lt !== 'ptr') { $lp = $this->ssa->allocReg(); $out .= '  ' . $lp . ' = inttoptr i64 ' . $l . " to ptr\n"; }
                    $rp = $r;
                    if ($rt !== 'ptr') { $rp = $this->ssa->allocReg(); $out .= '  ' . $rp . ' = inttoptr i64 ' . $r . " to ptr\n"; }
                    $yesL  = $this->ssa->allocLabel('objeq.same');
                    $nullL = $this->ssa->allocLabel('objeq.chknull');
                    $noL   = $this->ssa->allocLabel('objeq.no');
                    $propL = $this->ssa->allocLabel('objeq.props');
                    $joinL = $this->ssa->allocLabel('objeq.join');
                    $ideq = $this->ssa->allocReg();
                    $out .= '  ' . $ideq . ' = icmp eq ptr ' . $lp . ', ' . $rp . "\n";
                    $out .= '  br i1 ' . $ideq . ', label %' . $yesL . ', label %' . $nullL . "\n";
                    // A `?C` null carrier can't be dereferenced. Both-null already
                    // went to the identity arm, so either-null here means unequal.
                    $out .= $nullL . ":\n";
                    $ln = $this->ssa->allocReg();
                    $out .= '  ' . $ln . ' = icmp eq ptr ' . $lp . ", null\n";
                    $rn = $this->ssa->allocReg();
                    $out .= '  ' . $rn . ' = icmp eq ptr ' . $rp . ", null\n";
                    $anyn = $this->ssa->allocReg();
                    $out .= '  ' . $anyn . ' = or i1 ' . $ln . ', ' . $rn . "\n";
                    $out .= '  br i1 ' . $anyn . ', label %' . $noL . ', label %' . $propL . "\n";
                    $out .= $propL . ":\n";
                    $acc = 'true';
                    foreach ($plan as $p) {
                        $pn = $p[0]; $pt = $p[1]; $isArr = $p[2]; $chain = $p[3];
                        $off = (string)$cd->propertyOffset($pn);
                        $lg = $this->ssa->allocReg();
                        $out .= '  ' . $lg . ' = getelementptr inbounds i8, ptr ' . $lp . ', i64 ' . $off . "\n";
                        $lv = $this->ssa->allocReg();
                        $out .= '  ' . $lv . ' = load i64, ptr ' . $lg . "\n";
                        $rg = $this->ssa->allocReg();
                        $out .= '  ' . $rg . ' = getelementptr inbounds i8, ptr ' . $rp . ', i64 ' . $off . "\n";
                        $rv = $this->ssa->allocReg();
                        $out .= '  ' . $rv . ' = load i64, ptr ' . $rg . "\n";
                        $peq = $this->ssa->allocReg();
                        if ($isArr) {
                            $lap = $this->ssa->allocReg();
                            $out .= '  ' . $lap . ' = inttoptr i64 ' . $lv . " to ptr\n";
                            $rap = $this->ssa->allocReg();
                            $out .= '  ' . $rap . ' = inttoptr i64 ' . $rv . " to ptr\n";
                            $out .= '  ' . $peq . ' = call i1 @__mir_array_loose_eq(ptr ' . $lap
                                  . ', i64 ' . $chain . ', ptr ' . $rap . ', i64 ' . $chain . ")\n";
                        } else {
                            $this->lastValue = $lv; $this->lastValueType = 'i64';
                            $out .= $this->shallowBoxToCell($pt);
                            $lc2 = $this->lastValue;
                            $this->lastValue = $rv; $this->lastValueType = 'i64';
                            $out .= $this->shallowBoxToCell($pt);
                            $rc2 = $this->lastValue;
                            $pe = $this->ssa->allocReg();
                            $out .= '  ' . $pe . ' = call i64 @__manticore_tagged_loose_eq(i64 '
                                  . $lc2 . ', i64 ' . $rc2 . ")\n";
                            $out .= '  ' . $peq . ' = icmp ne i64 ' . $pe . ", 0\n";
                        }
                        $nacc = $this->ssa->allocReg();
                        $out .= '  ' . $nacc . ' = and i1 ' . $acc . ', ' . $peq . "\n";
                        $acc = $nacc;
                    }
                    $out .= '  br label %' . $joinL . "\n";
                    $out .= $yesL . ":\n  br label %" . $joinL . "\n";
                    $out .= $noL . ":\n  br label %" . $joinL . "\n";
                    $out .= $joinL . ":\n";
                    $phi = $this->ssa->allocReg();
                    $out .= '  ' . $phi . ' = phi i1 [ ' . $acc . ', %' . $propL . ' ], [ true, %'
                          . $yesL . ' ], [ false, %' . $noL . " ]\n";
                    $fin = $phi;
                    if ($isNe) {
                        $fin = $this->ssa->allocReg();
                        $out .= '  ' . $fin . ' = xor i1 ' . $phi . ", true\n";
                    }
                    $res = $this->ssa->allocReg();
                    $out .= '  ' . $res . ' = zext i1 ' . $fin . " to i64\n";
                    $this->lastValue = $res;
                    $this->lastValueType = 'i64';
                    return $out;
                }
            }
        }
        // Statically-typed operand pairs the RAW carrier compare gets wrong under
        // PHP's LOOSE `==`, routed through the tagged table instead:
        //   · a BOOL on exactly one side  — the whole compare goes boolean, so
        //     `"a" == true` and `[] == false` are true (raw: carrier bits).
        //   · two STRINGS — two NUMERIC strings compare as numbers, so
        //     `"1.0" == "1"`, `"1e2" == "100"` and `" 1" == "1"` are true
        //     (raw: byte-wise, all false).
        //   · an ARRAY on exactly one side — never equal to a non-array.
        // Elements are never inspected on these rows, so the arrays can be
        // tagged shallowly rather than rebuilt into cell arrays.
        if (($isEq || $isNe) && !$strictEq) {
            $jug = [
                Type::KIND_INT => true, Type::KIND_FLOAT => true, Type::KIND_STRING => true,
                Type::KIND_BOOL => true, Type::KIND_ARRAY => true, Type::KIND_OBJ => true,
            ];
            $boolMix = ($lk === Type::KIND_BOOL) !== ($rk === Type::KIND_BOOL);
            $bothStr = $lk === Type::KIND_STRING && $rk === Type::KIND_STRING;
            $arrMix  = ($lk === Type::KIND_ARRAY) !== ($rk === Type::KIND_ARRAY);
            if (isset($jug[$lk]) && isset($jug[$rk]) && ($boolMix || $bothStr || $arrMix)) {
                $this->rt->needsTaggedEq = true;
                $this->lastValue = $l; $this->lastValueType = $lt;
                $out .= $this->shallowBoxToCell($c->left->type);
                $li = $this->lastValue;
                $this->lastValue = $r; $this->lastValueType = $rt;
                $out .= $this->shallowBoxToCell($c->right->type);
                $ri = $this->lastValue;
                $eqr = $this->ssa->allocReg();
                $out .= '  ' . $eqr . ' = call i64 @__manticore_tagged_loose_eq(i64 '
                      . $li . ', i64 ' . $ri . ")\n";
                $res = $eqr;
                if ($isNe) {
                    $res = $this->ssa->allocReg();
                    $out .= '  ' . $res . ' = xor i64 ' . $eqr . ", 1\n";
                }
                $this->lastValue = $res;
                $this->lastValueType = 'i64';
                return $out;
            }
        }
        // A CELL against a statically-typed BOOL or STRING. PHP's table says a
        // bool on either side makes the whole compare boolean (`"a" == true`),
        // and null-vs-string compares "" against the string (`o(null) == ""`) —
        // neither of which the raw-carrier paths below can express. Box the
        // typed side and let the tagged runtime walk the table. Ints/floats keep
        // the cheaper numeric paths below; strict string===cell already returned
        // through its own tag-guarded branch above.
        if ($isEq || $isNe) {
            $lJug = $lk === Type::KIND_BOOL || $lk === Type::KIND_STRING;
            $rJug = $rk === Type::KIND_BOOL || $rk === Type::KIND_STRING;
            if (($lk === Type::KIND_CELL && $rJug) || ($rk === Type::KIND_CELL && $lJug)) {
                $this->rt->needsTaggedEq = true;
                if ($lk !== Type::KIND_CELL) {
                    $this->lastValue = $l; $this->lastValueType = $lt;
                    $out .= $this->boxToCell($c->left->type);
                    $l = $this->lastValue; $lt = 'i64';
                }
                if ($rk !== Type::KIND_CELL) {
                    $this->lastValue = $r; $this->lastValueType = $rt;
                    $out .= $this->boxToCell($c->right->type);
                    $r = $this->lastValue; $rt = 'i64';
                }
                $li = $l;
                if ($lt === 'ptr') { $li = $this->ssa->allocReg(); $out .= '  ' . $li . ' = ptrtoint ptr ' . $l . " to i64\n"; }
                $ri = $r;
                if ($rt === 'ptr') { $ri = $this->ssa->allocReg(); $out .= '  ' . $ri . ' = ptrtoint ptr ' . $r . " to i64\n"; }
                $fn = $strictEq ? '@__manticore_tagged_strict_eq' : '@__manticore_tagged_loose_eq';
                $eqr = $this->ssa->allocReg();
                $out .= '  ' . $eqr . ' = call i64 ' . $fn . '(i64 ' . $li . ', i64 ' . $ri . ")\n";
                $res = $eqr;
                if ($isNe) {
                    $res = $this->ssa->allocReg();
                    $out .= '  ' . $res . ' = xor i64 ' . $eqr . ", 1\n";
                }
                $this->lastValue = $res;
                $this->lastValueType = 'i64';
                return $out;
            }
        }
        // Two statically-typed ARRAYS compare BY VALUE in PHP, recursively: `==`
        // is same-count + loosely-equal values per key (order-independent),
        // `===` adds order and strictness, and ordering goes by count first. The
        // pointer-identity fallthrough at the bottom of this method answered
        // `[1,2,3] == [1,2,3]` false and ordered `$a <=> $a` as -1, which also
        // sank in_array/array_search/sort over array elements.
        if ($lk === Type::KIND_ARRAY && $rk === Type::KIND_ARRAY) {
            $eka = $this->elemChainOf($c->left->type->element);
            $ekb = $this->elemChainOf($c->right->type->element);
            if ($eka !== self::EK_NONE && $ekb !== self::EK_NONE
                && $this->chainsComparable($eka, $ekb)) {
                $this->rt->needsTaggedCompare = true;
                $lp = $l;
                if ($lt !== 'ptr') { $lp = $this->ssa->allocReg(); $out .= '  ' . $lp . ' = inttoptr i64 ' . $l . " to ptr\n"; }
                $rp = $r;
                if ($rt !== 'ptr') { $rp = $this->ssa->allocReg(); $out .= '  ' . $rp . ' = inttoptr i64 ' . $r . " to ptr\n"; }
                $args = '(ptr ' . $lp . ', i64 ' . $eka . ', ptr ' . $rp . ', i64 ' . $ekb . ')';
                $res = $this->ssa->allocReg();
                if ($isEq || $isNe) {
                    $fn = $strictEq ? '@__mir_array_strict_eq' : '@__mir_array_loose_eq';
                    $b1 = $this->ssa->allocReg();
                    $out .= '  ' . $b1 . ' = call i1 ' . $fn . $args . "\n";
                    $out .= '  ' . $res . ' = zext i1 ' . $b1 . " to i64\n";
                    if ($isNe) {
                        $neg = $this->ssa->allocReg();
                        $out .= '  ' . $neg . ' = xor i64 ' . $res . ", 1\n";
                        $res = $neg;
                    }
                } else {
                    $cmp = $this->ssa->allocReg();
                    $out .= '  ' . $cmp . ' = call i64 @__mir_array_compare' . $args . "\n";
                    $pr = $this->ssa->allocReg();
                    $out .= '  ' . $pr . ' = icmp ' . $this->cmpPredicate($c->op) . ' i64 ' . $cmp . ", 0\n";
                    $out .= '  ' . $res . ' = zext i1 ' . $pr . " to i64\n";
                }
                $this->lastValue = $res;
                $this->lastValueType = 'i64';
                return $out;
            }
        }
        // Both operands are statically CELL, EQ/NE — dispatch by tag with PHP
        // juggling at runtime (`5 == "5"`, non-interned `"x" === "x"`). A raw i64
        // compare only accidentally works for canonical-repr ints / interned
        // strings; it misses int-vs-numeric-string and non-interned strings.
        if (($isEq || $isNe)
            && $lk === Type::KIND_CELL && $rk === Type::KIND_CELL) {
            $this->rt->needsTaggedEq = true;
            $this->rt->needsTagged = true;
            $this->rt->needsTaggedToFloat = true;
            $li = $l;
            if ($lt === 'ptr') { $li = $this->ssa->allocReg(); $out .= '  ' . $li . ' = ptrtoint ptr ' . $l . " to i64\n"; }
            $ri = $r;
            if ($rt === 'ptr') { $ri = $this->ssa->allocReg(); $out .= '  ' . $ri . ' = ptrtoint ptr ' . $r . " to i64\n"; }
            $fn = $strictEq ? '@__manticore_tagged_strict_eq' : '@__manticore_tagged_loose_eq';
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = call i64 ' . $fn . '(i64 ' . $li . ', i64 ' . $ri . ")\n";
            $res = $eq;
            if ($isNe) {
                $res = $this->ssa->allocReg();
                $out .= '  ' . $res . ' = xor i64 ' . $eq . ", 1\n";
            }
            $this->lastValue = $res;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Both operands are statically CELL (guaranteed NaN-boxed) in an ORDERING
        // compare — their runtime types (string / int / float) are only known at
        // runtime, so dispatch by tag: string→strcmp, else numeric. Without this a
        // string cell orders by raw pointer bits (sorting array_keys / an erased
        // mixed array). Eq/ne keep the existing tag/carrier paths above.
        if (!$isEq && !$isNe
            && $lk === Type::KIND_CELL && $rk === Type::KIND_CELL) {
            $this->rt->needsTaggedCompare = true;
            $this->rt->needsTagged = true;
            $this->rt->needsTaggedToFloat = true;
            $this->rt->needsStrcmp = true;
            $li = $l;
            if ($lt === 'ptr') { $li = $this->ssa->allocReg(); $out .= '  ' . $li . ' = ptrtoint ptr ' . $l . " to i64\n"; }
            $ri = $r;
            if ($rt === 'ptr') { $ri = $this->ssa->allocReg(); $out .= '  ' . $ri . ' = ptrtoint ptr ' . $r . " to i64\n"; }
            $cmp = $this->ssa->allocReg();
            $out .= '  ' . $cmp . ' = call i64 @__manticore_tagged_compare(i64 ' . $li . ', i64 ' . $ri . ")\n";
            $cmpReg = $this->ssa->allocReg();
            $out .= '  ' . $cmpReg . ' = icmp ' . $this->cmpPredicate($c->op) . ' i64 ' . $cmp . ", 0\n";
            $extReg = $this->ssa->allocReg();
            $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
            $this->lastValue = $extReg;
            $this->lastValueType = 'i64';
            return $out;
        }
        // A NUMERIC cell (int|float) ordered against a RAW int carries either a
        // boxed int or a raw DOUBLE. The unbox_int below would read a double's
        // bits as an int and order it wrongly (`f(7.5) < 5` answered TRUE). Box
        // the raw side and let the tagged runtime order them by tag. (Cell-vs-cell
        // already returned through the tagged_compare branch above, which is why
        // only the mixed pairing was broken.)
        if (!$isEq && !$isNe) {
            $lNumCell = $c->left->type->isNumericCell();
            $rNumCell = $c->right->type->isNumericCell();
            $lRawNum = $lk === Type::KIND_INT || $lk === Type::KIND_FLOAT;
            $rRawNum = $rk === Type::KIND_INT || $rk === Type::KIND_FLOAT;
            if (($lNumCell && $rRawNum) || ($rNumCell && $lRawNum)) {
                $this->rt->needsTaggedCompare = true;
                $this->rt->needsTagged = true;
                $this->rt->needsTaggedToFloat = true;
                $this->rt->needsStrtod = true;
                $this->rt->needsStrcmp = true;
                if ($lRawNum) {
                    $this->lastValue = $l; $this->lastValueType = $lt;
                    $out .= $this->boxToCell($c->left->type);
                    $l = $this->lastValue; $lt = 'i64';
                }
                if ($rRawNum) {
                    $this->lastValue = $r; $this->lastValueType = $rt;
                    $out .= $this->boxToCell($c->right->type);
                    $r = $this->lastValue; $rt = 'i64';
                }
                $li = $l;
                if ($lt === 'ptr') { $li = $this->ssa->allocReg(); $out .= '  ' . $li . ' = ptrtoint ptr ' . $l . " to i64\n"; }
                $ri = $r;
                if ($rt === 'ptr') { $ri = $this->ssa->allocReg(); $out .= '  ' . $ri . ' = ptrtoint ptr ' . $r . " to i64\n"; }
                $cmp = $this->ssa->allocReg();
                $out .= '  ' . $cmp . ' = call i64 @__manticore_tagged_compare(i64 ' . $li . ', i64 ' . $ri . ")\n";
                $cmpReg = $this->ssa->allocReg();
                $out .= '  ' . $cmpReg . ' = icmp ' . $this->cmpPredicate($c->op) . ' i64 ' . $cmp . ", 0\n";
                $extReg = $this->ssa->allocReg();
                $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
                $this->lastValue = $extReg;
                $this->lastValueType = 'i64';
                return $out;
            }
        }

        // Float comparison when either side carries a double.
        if ($lt === 'double' || $rt === 'double'
            || $lk === Type::KIND_FLOAT || $rk === Type::KIND_FLOAT) {
            $ld = $l;
            if ($lt !== 'double') {
                $ld = $this->ssa->allocReg();
                $out .= '  ' . $ld . ' = sitofp i64 ' . $l . " to double\n";
            }
            $rd = $r;
            if ($rt !== 'double') {
                $rd = $this->ssa->allocReg();
                $out .= '  ' . $rd . ' = sitofp i64 ' . $r . " to double\n";
            }
            $cmpReg = $this->ssa->allocReg();
            $out .= '  ' . $cmpReg . ' = fcmp ' . $this->cmpPredicateF($c->op)
                  . ' double ' . $ld . ', ' . $rd . "\n";
            $extReg = $this->ssa->allocReg();
            $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
            $this->lastValue = $extReg;
            $this->lastValueType = 'i64';
            return $out;
        }

        // A tagged-cell operand (e.g. `strpos(...)` → int|false) carries
        // its int payload NaN-boxed; the raw carrier is meaningless in a
        // numeric compare. Unbox to the signed int (false → 0) so
        // `strpos(...) > 0` / `< 0` / `=== 3` work. Restrict to numeric
        // contexts — an ordering op, or eq/ne against an int/bool side —
        // so a `string|false` cell (`getenv`) isn't mangled. (`=== false`
        // returned above via the tag-compare branch.)
        $numericCtx = (!$isEq && !$isNe)
            || $rk === Type::KIND_INT || $rk === Type::KIND_BOOL
            || $lk === Type::KIND_INT || $lk === Type::KIND_BOOL;
        if ($lk === Type::KIND_CELL && $numericCtx) {
            if ($lt === 'ptr') { $tmp = $this->ssa->allocReg(); $out .= '  ' . $tmp . ' = ptrtoint ptr ' . $l . " to i64\n"; $l = $tmp; $lt = 'i64'; }
            $this->rt->needsTagged = true;
            $u = $this->ssa->allocReg();
            $out .= '  ' . $u . ' = call i64 @__manticore_unbox_int(i64 ' . $l . ")\n";
            $l = $u; $lt = 'i64';
        }
        if ($rk === Type::KIND_CELL && $numericCtx) {
            if ($rt === 'ptr') { $tmp = $this->ssa->allocReg(); $out .= '  ' . $tmp . ' = ptrtoint ptr ' . $r . " to i64\n"; $r = $tmp; $rt = 'i64'; }
            $this->rt->needsTagged = true;
            $u = $this->ssa->allocReg();
            $out .= '  ' . $u . ' = call i64 @__manticore_unbox_int(i64 ' . $r . ")\n";
            $r = $u; $rt = 'i64';
        }
        // Handle comparison (identity / equality of vec / assoc / obj
        // handles, e.g. `$x !== []`): the carrier is i64, so a ptr operand
        // (a fresh array-literal / alloc) must be ptrtoint'd first.
        if ($lt === 'ptr') {
            $lp = $this->ssa->allocReg();
            $out .= '  ' . $lp . ' = ptrtoint ptr ' . $l . " to i64\n";
            $l = $lp;
        }
        if ($rt === 'ptr') {
            $rp = $this->ssa->allocReg();
            $out .= '  ' . $rp . ' = ptrtoint ptr ' . $r . " to i64\n";
            $r = $rp;
        }
        $pred = $this->cmpPredicate($c->op);
        $cmpReg = $this->ssa->allocReg();
        $out .= '  ' . $cmpReg . ' = icmp ' . $pred . ' i64 ' . $l . ', ' . $r . "\n";
        $extReg = $this->ssa->allocReg();
        $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
        $this->lastValue = $extReg;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitEcho(Echo_ $n): string
    {
        $out = '';
        foreach ($n->exprs as $e) {
            $out .= $this->emitNode($e);
            $kind = $e->type->kind;
            // Stringable object → __toString, then print as a string.
            $ts = $this->toStringClassOf($e);
            if ($ts !== '') {
                $out .= $this->emitToStringCall($ts);
                $kind = Type::KIND_STRING;
            }
            // A NaN-boxed cell (e.g. `int|false` from strpos) dispatches
            // on its tag at runtime — int prints decimal, false / null
            // print nothing, matching PHP echo.
            if ($kind === Type::KIND_CELL) {
                $out .= $this->coerceToI64();
                $this->rt->needsTaggedEcho = true;
                $out .= '  call void @__manticore_echo_tagged(i64 '
                      . $this->lastValue . ")\n";
                continue;
            }
            // Coerce the cursor to the printf arg type — a string
            // local arrives as the i64 slot payload and must be
            // inttoptr'd; a float bitcast back to double.
            // PHP `echo` of a bool prints "1" for true and "" (nothing) for
            // false — NOT "0". Emit `printf("%.*s", b?1:0, "1")`: the precision
            // arg gates whether the single "1" char prints.
            if ($kind === Type::KIND_BOOL) {
                $out .= $this->coerceToI64();
                $nz = $this->ssa->allocReg();
                $w = $this->ssa->allocReg();
                $reg = $this->ssa->allocReg();
                $out .= '  ' . $nz . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
                $out .= '  ' . $w . ' = zext i1 ' . $nz . " to i32\n";
                $out .= '  ' . $reg . ' = call i32 (ptr, ...) @printf(ptr @.fmt.ds, i32 '
                      . $w . ', ptr @.str.one)' . "\n";
                continue;
            }
            if ($kind === Type::KIND_FLOAT) {
                // Print via __mir_float_to_str, not a raw `printf("%.14g")`: PHP's
                // `echo` scientific form is "1.0E+20", which the runtime formatter
                // produces and C's `%g` ("1e+20") does not.
                $this->rt->needsFloatStr = true;
                $this->rt->needsStrRc = true;
                $out .= $this->coerceTo('double');
                $fsr = $this->ssa->allocReg();
                $out .= '  ' . $fsr . ' = call ptr @__mir_float_to_str(double ' . $this->lastValue . ")\n";
                $reg = $this->ssa->allocReg();
                $out .= '  ' . $reg . ' = call i32 (ptr, ...) @printf(ptr @.fmt.s, ptr ' . $fsr . ")\n";
                $out .= '  call void @__mir_rc_release_str(ptr ' . $fsr . ")\n";
                continue;
            }
            if ($kind === Type::KIND_STRING) {
                $out .= $this->coerceToPtr();
                // A null `?string` (ptr 0) echoes "" in PHP — map 0 → the empty
                // C-string so printf doesn't dereference null.
                $sp = $this->lastValue;
                $snn = $this->ssa->allocReg();
                $ssafe = $this->ssa->allocReg();
                $out .= '  ' . $snn . ' = icmp eq ptr ' . $sp . ", null\n";
                $out .= '  ' . $ssafe . ' = select i1 ' . $snn
                      . ', ptr ' . $this->strSymBytes('@.cstr.empty') . ', ptr ' . $sp . "\n";
                $this->lastValue = $ssafe;
                $fmt = '@.fmt.s';
                $argType = 'ptr';
            } else {
                $out .= $this->coerceToI64();
                $fmt = '@.fmt.d';
                $argType = 'i64';
            }
            $val = $this->lastValue;
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call i32 (ptr, ...) @printf(ptr '
                  . $fmt . ', ' . $argType . ' ' . $val . ")\n";
            // A fresh string temp (`echo $a . $b`) is dead after printing.
            // ($val is a ptr in the string branch; freeStrTemp no-ops the
            // non-string branches, where $e is never a fresh string.)
            $out .= $this->freeStrTemp($e, $val);
        }
        return $out;
    }

    /**
     * Unbox a just-emitted tagged-cell arg when the callee param is a raw
     * scalar (bool/int). A heterogeneous assoc (`['static' => false, ...]`)
     * stores its values boxed, so reading one yields a cell; passed to a
     * `bool`/`int` param it would otherwise carry the tag bits (a boxed
     * `false` is non-zero → reads truthy). Returns IR; updates lastValue.
     * `$mask` is the callee's per-param type table; `$pi` the param index.
     */
    private function unboxCellArg(Node $a, array $ptypes, int $pi): string
    {
        if ($a->type->kind !== Type::KIND_CELL) { return ''; }
        $pt = $ptypes[$pi] ?? null;
        if ($pt === null) { return ''; }
        $out = $this->unboxCellToType($pt);
        // ABI: every arg crosses as i64. A FLOAT param is the one unboxing that
        // leaves a `double` behind (the tag has to be read to get a real value
        // out of the cell) — carry it over as its bit pattern, or the call site
        // emits `i64 %d` for a double-typed register and clang rejects the
        // module. Every other kind already leaves an i64 and this is a no-op.
        return $out . $this->coerceToI64();
    }

    /**
     * Unbox the cell currently in lastValue (i64) to the representation a
     * concrete target type `$pt` expects: bool → `& 1`, int → unbox_int,
     * array/string/object → strip the NaN tag to the payload pointer. Any other
     * kind (cell/float/unknown/…) is left as-is. Used at every cell→concrete
     * boundary (call arg, `return`): a cell carries tag bits a typed consumer
     * would mis-read (a boxed `false` is non-zero → truthy; a boxed array
     * inttoptr's the tagged bits → fault).
     */
    private function unboxCellToType(Type $pt): string
    {
        $pk = $pt->kind;
        if ($pk === Type::KIND_BOOL) {
            $r = $this->ssa->allocReg();
            $out = '  ' . $r . ' = and i64 ' . $this->lastValue . ", 1\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($pk === Type::KIND_INT) {
            $r = $this->ssa->allocReg();
            $out = '  ' . $r . ' = call i64 @__manticore_unbox_int(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($pk === Type::KIND_FLOAT) {
            // A boxed cell is a NaN-pattern i64; reinterpreting it as a double
            // yields NaN. Unbox by tag to a real double (the caller coerces it
            // back through the i64 carrier for the ABI).
            $this->rt->needsTaggedToFloat = true;
            $r = $this->ssa->allocReg();
            $out = '  ' . $r . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'double';
            return $out;
        }
        if ($pk === Type::KIND_OBJ && $pt->class !== null && isset($this->enums[$pt->class])) {
            // Enum cell → the ORDINAL a typed-enum consumer expects. The cell is
            // box_object(singleton); mask to the data ptr, load ordinal @+16
            // (mirrors emitEnumCellSingletons' layout), NOT the raw payload.
            $m = $this->ssa->allocReg();
            $out = '  ' . $m . ' = and i64 ' . $this->lastValue . ", 281474976710655\n";
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $m . " to ptr\n";
            $g = $this->ssa->allocReg();
            $out .= '  ' . $g . ' = getelementptr i8, ptr ' . $p . ", i64 16\n";
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = load i64, ptr ' . $g . "\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($pk === Type::KIND_ARRAY || $pk === Type::KIND_STRING
            || $pk === Type::KIND_OBJ) {
            $r = $this->ssa->allocReg();
            $out = '  ' . $r . ' = and i64 ' . $this->lastValue . ", 281474976710655\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        return '';
    }

    /**
     * Transform the current lastValue into an i64 that is 0/non-0 for its PHP
     * truthiness. A CELL unboxes by tag; a STRING is falsy for "" and "0" (a raw
     * ptr coerce would read any non-null string, incl. "", as truthy) — box it
     * (box_ptr is bit-ops, no alloc) and reuse the tagged-truthy byte check; any
     * other type coerces to i64 unchanged (the caller's `icmp ne 0` is correct).
     * Split from emitCondVal so the short-ternary (`?:`) can compute truthiness
     * WITHOUT clobbering the raw operand it reuses as its then-value.
     */
    private function truthinessOf(Type $t): string
    {
        if ($t->kind === Type::KIND_CELL) {
            $this->rt->needsTaggedTruthy = true;
            $out = $this->coerceToI64();
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_tagged_truthy(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($t->kind === Type::KIND_STRING) {
            $out = $this->boxToCell(Type::string_());
            $this->rt->needsTaggedTruthy = true;
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_tagged_truthy(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        // An array is falsy iff empty (len 0); a raw ptr coerce reads any
        // non-null array (incl. `[]`) as truthy. Tag the raw ptr (box_array is
        // bit-ops + a null guard, no element rebuild) and reuse tagged-truthy's
        // length check.
        if ($t->isArray()) {
            $out = $this->coerceToPtr();
            $this->rt->needsTagged = true;
            $ba = $this->ssa->allocReg();
            $out .= '  ' . $ba . ' = call i64 @__manticore_box_array(ptr ' . $this->lastValue . ")\n";
            $this->rt->needsTaggedTruthy = true;
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_tagged_truthy(i64 ' . $ba . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        return $this->coerceToI64();
    }

    private function coerceToI64(): string
    {
        if ($this->lastValueType === 'i64') { return ''; }
        if ($this->lastValueType === 'double') {
            $reg = $this->ssa->allocReg();
            $out = '  ' . $reg . ' = bitcast double ' . $this->lastValue . " to i64\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($this->lastValueType === 'ptr') {
            $reg = $this->ssa->allocReg();
            $out = '  ' . $reg . ' = ptrtoint ptr ' . $this->lastValue . " to i64\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        return '';
    }

    private function llvmStringBytes(string $s): string
    {
        $out = '';
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i = $i + 1) {
            // Index (`$s[$i]`), NOT substr: string indexing is binary-safe but
            // the runtime substr is C-strlen bounded, so `substr($s,$i,1)` on a
            // string with an embedded NUL truncates → bytes after the NUL emit
            // as \00 (the trim mask `\0\x0B`, any `\xNN`/binary literal).
            $code = \ord($s[$i]);
            if ($code === 92 || $code === 34 || $code < 0x20 || $code >= 0x7F) {
                $out .= '\\' . $this->hexByte($code);
                continue;
            }
            $out .= $s[$i];
        }
        return $out;
    }

    /** (a * b) mod 2^64, exact (no float overflow) — 16-bit limb multiply. */
    private function mulmod64(int $a, int $b): int
    {
        $a0 = $a & 0xFFFF; $a1 = ($a >> 16) & 0xFFFF; $a2 = ($a >> 32) & 0xFFFF; $a3 = ($a >> 48) & 0xFFFF;
        $b0 = $b & 0xFFFF; $b1 = ($b >> 16) & 0xFFFF; $b2 = ($b >> 32) & 0xFFFF; $b3 = ($b >> 48) & 0xFFFF;
        $c0 = $a0 * $b0;
        $c1 = $a0 * $b1 + $a1 * $b0;
        $c2 = $a0 * $b2 + $a1 * $b1 + $a2 * $b0;
        $c3 = $a0 * $b3 + $a1 * $b2 + $a2 * $b1 + $a3 * $b0;
        $r0 = $c0 & 0xFFFF;
        $k1 = ($c0 >> 16) + $c1; $r1 = $k1 & 0xFFFF;
        $k2 = ($k1 >> 16) + $c2; $r2 = $k2 & 0xFFFF;
        $k3 = ($k2 >> 16) + $c3; $r3 = $k3 & 0xFFFF;
        $low = $r0 | ($r1 << 16) | ($r2 << 32);   // < 2^48
        $hiLow = ($r3 & 0x7FFF) << 48;            // < 2^63 (no overflow)
        $bit63 = (($r3 >> 15) & 1) << 63;         // 0 or PHP_INT_MIN
        return $low | $hiLow | $bit63;
    }

    /**
     * Materialize `$this->lastValue` as a `ptr` — used when an
     * inttoptr is needed (e.g. a vec ptr came in as the i64 slot
     * payload).
     */
    private function coerceToPtr(): string
    {
        if ($this->lastValueType === 'ptr') { return ''; }
        if ($this->lastValueType === 'i64') {
            $reg = $this->ssa->allocReg();
            $out = '  ' . $reg . ' = inttoptr i64 ' . $this->lastValue . " to ptr\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'ptr';
            return $out;
        }
        return '';
    }

    /**
     * Render a PHP float as an LLVM IR literal. LLVM accepts both
     * decimal (`1.5`, `1.500000e+00`) and bit-exact hex
     * (`0x3FF8000000000000`) forms; decimal is human-readable and
     * round-trips for the small set of literals current MIR
     * surfaces. Forces a decimal point so LLVM doesn't parse an
     * integer-looking value as `i64`.
     */
    private function formatFloat(float $v): string
    {
        // INF / NAN have no decimal form LLVM accepts — emit the exact IEEE-754
        // double bit pattern in hex (LLVM `double 0x...`). Detected with plain
        // fcmp (NaN != itself; |v| past the finite max is infinite) so this
        // works in the self-hosted compiler too (no is_nan/is_infinite builtin).
        if (!($v == $v)) { return '0x7FF8000000000000'; }
        // ±INF detection must NOT compare against a DBL_MAX *literal*: that
        // literal, parsed by the runtime's own strtod, can itself round to
        // INF, making `INF > DBL_MAX_literal` == `INF > INF` == false — then
        // +INF leaks through to `(string)$v` = "inf" and we emit the INVALID
        // IR token `inf.0`. A finite value satisfies `v - v == 0`; ±INF gives
        // `INF - INF == NaN != 0`. Threshold-free, so it always fires.
        $d = $v - $v;
        if (!($d == 0.0)) {
            return ($v > 0.0) ? '0x7FF0000000000000' : '0xFFF0000000000000';
        }
        // `(string)$v` uses PHP `precision` (14 sig figs) → a literal like
        // 0.30000000000000004 would round to "0.3" and the emitted constant
        // (and any `=== 0.300…04`) would be WRONG. 17 significant digits is the
        // round-trip width for binary64, so `%.17g` reproduces the exact double
        // (LLVM parses the decimal to the nearest double = the original bits).
        $s = \sprintf('%.17g', $v);
        // Belt-and-suspenders: if the float→string ever yields a non-numeric
        // word ("inf"/"nan"/"INF"), never let it reach the IR — map to the
        // IEEE-754 bit pattern by sign (NaN already handled above).
        if ($s === 'inf' || $s === 'INF' || $s === 'nan' || $s === 'NAN') {
            return ($v < 0.0) ? '0xFFF0000000000000' : '0x7FF0000000000000';
        }
        // LLVM requires a decimal point in the MANTISSA. Bare "5" is parsed as
        // i64 (rejecting the surrounding `bitcast double`); an exponent form
        // with no point — "1e+308" (manticore's float→string) — is likewise
        // rejected. So locate the exponent and the dot manually (self-host
        // strpos returns -1 on miss, so scan), and inject ".0" before the
        // exponent (or at the end) when the mantissa has no point.
        $n = \strlen($s);
        $ePos = -1;
        $dotPos = -1;
        for ($i = 0; $i < $n; $i = $i + 1) {
            $c = \substr($s, $i, 1);
            if ($c === '.') { $dotPos = $i; }
            if ($c === 'e' || $c === 'E') { $ePos = $i; break; }
        }
        if ($dotPos >= 0) { return $s; }            // already has a point
        if ($ePos < 0) { return $s . '.0'; }        // "5" -> "5.0"
        return \substr($s, 0, $ePos) . '.0' . \substr($s, $ePos); // "1e+308" -> "1.0e+308"
    }
}
