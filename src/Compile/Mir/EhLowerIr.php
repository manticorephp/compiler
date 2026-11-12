<?php

namespace Compile\Mir;

/**
 * Error-return exceptions over the FINAL module text (docs/design/error-return-exceptions.md).
 *
 * One process-wide slot `@__mc_exc` holds the exception in flight (null = none).
 * The emitters still produce `invoke` / `landingpad` / `resume`; this pass lowers
 * them, the way {@see PruneIr} sees every reference by reading the text rather
 * than enumerating emit sites:
 *
 * - `invoke … to label %n unwind label %p` → `call …` + slot test + `br` to %p / %n;
 * - any other `call` of a callee that may throw → slot test + `br` to the frame's
 *   propagate block `mc.prop` (`ret <ty> undef`; in `@main` the uncaught stop) and
 *   the block continues at a fresh `mc.k<N>` — `phi`s naming the split block are
 *   retargeted to its last continuation;
 * - `landingpad` → take the slot (load, store null): cleanup code runs with it clear;
 * - `resume` → put it back, branch to `mc.prop`.
 *
 * May-throw is a fixpoint over the module's own call graph: a function may throw
 * when it calls `@__mc_throw`, makes an indirect call, or calls a function that
 * may. A callee the module only declares: a PHP function (`@manticore_*`) is
 * nothrow when the {@see NothrowSummary} says so, any other runtime symbol
 * (`@__*`) may throw, C (anything else) never does. A WRONG nothrow lets code run
 * after a throw, so every unknown is may-throw.
 */
final class EhLowerIr
{
    /** Checks inserted by the last run (invoke + plain call). */
    public int $checks = 0;
    /** Defined functions that may leave with the slot set. */
    public int $mayThrow = 0;
    /** Bytes the last {@see runFile} wrote. */
    public int $bytes = 0;

    /** @var array<string, bool> `@manticore_*` symbols judged nothrow */
    private array $nothrowSyms;
    /** Marker an FFI wrapper emits after its C call; lowered to a bare slot test or dropped. */
    public const POLL = '@__mc_exc_poll';

    /** @var string[] PHP functions whose address `fn_to_ptr` hands to C */
    private array $ccallable = [];
    /** @var array<string, string[]> */
    private array $callees = [];
    /** @var array<string, bool> */
    private array $may = [];

    // sweep-2 state, per function
    private bool $inFn = false;
    private bool $skip = false;
    private bool $isMain = false;
    private bool $needProp = false;
    private string $retTy = 'void';
    private string $cur = '';
    private int $seq = 0;
    /** @var array<string, string> */
    private array $last = [];
    /** @var string[] */
    private array $fnBuf = [];
    /** @var string[] */
    private array $out = [];
    private ?\Ffi\Ptr $outFile = null;

    // sweep-1 state
    private string $scanFn = '';

    /** @param array<string, bool> $nothrowSyms */
    public function __construct(array $nothrowSyms)
    {
        $this->nothrowSyms = $nothrowSyms;
        $this->assert = \getenv('MANTICORE_EH_ASSERT') === '1';
    }

    /**
     * `MANTICORE_EH_ASSERT=1`: after every PHP / runtime call judged nothrow, a
     * set slot aborts with `EH ASSERT: exception set after nothrow call to @f`.
     * A wrong nothrow lets code run after a throw; this finds it.
     */
    private bool $assert = false;
    private bool $hasWrite = false;
    private bool $hasTrap = false;
    private bool $hasUncaught = false;
    private bool $usedUncaught = false;
    /** @var array<string, string> callee → its message global */
    private array $assertMsg = [];

    /** What follows the last function: the slot, and the assert helper when used. */
    private function tail(): string
    {
        $t = '@__mc_exc = linkonce_odr global ptr null';
        if ($this->usedUncaught && !$this->hasUncaught) { $t .= "\ndeclare void @__mc_uncaught(ptr)"; }
        if ($this->assertMsg === []) { return $t; }
        foreach ($this->assertMsg as $c => $g) {
            $m = 'EH ASSERT: exception set after nothrow call to ' . $c . "\n";
            $t .= "\n" . $g . ' = private unnamed_addr constant [' . (string)\strlen($m) . ' x i8] c"'
                . \str_replace("\n", '\0A', $m) . '"';
        }
        if (!$this->hasWrite) { $t .= "\ndeclare i64 @write(i32, ptr, i64)"; }
        if (!$this->hasTrap) { $t .= "\ndeclare void @llvm.trap()"; }
        return $t . "\ndefine linkonce_odr void @__mc_eh_assert_fail(ptr %m, i64 %n) cold noinline {\nentry:\n"
            . "  %w = call i64 (i32, ptr, i64) @write(i32 2, ptr %m, i64 %n)\n"
            . "  call void @llvm.trap()\n  unreachable\n}";
    }

    public function run(string $ir): string
    {
        $lines = \explode("\n", $ir);
        foreach ($lines as $l) { $this->scan((string)$l); }
        $this->solve();
        $this->out = [];
        $this->outFile = null;
        foreach ($lines as $l) { $this->lower((string)$l); }
        $this->out[] = $this->tail();
        return \implode("\n", $this->out) . "\n";
    }

    /** Lower a staged module file into `$outPath`; false on an I/O failure. */
    public function runFile(string $path, string $outPath): bool
    {
        if (!$this->eachLine($path, true)) { return false; }
        $this->solve();
        $this->outFile = \Manticore\fopen($outPath, 'wb');
        if ($this->outFile === null) { return false; }
        $ok = $this->eachLine($path, false);
        $tail = $this->tail() . "\n";
        if (\Manticore\fwrite($tail, 1, \strlen($tail), $this->outFile) !== \strlen($tail)) { $ok = false; }
        $this->bytes = $this->bytes + \strlen($tail);
        \Manticore\fclose($this->outFile);
        $this->outFile = null;
        return $ok;
    }

    /** Feed every line of `$path` (no trailing newline) to scan or lower. */
    private function eachLine(string $path, bool $scan): bool
    {
        $in = \Manticore\fopen($path, 'rb');
        if ($in === null) { return false; }
        $cap = 1048576;
        $buf = \Manticore\malloc($cap);
        $pend = '';
        while (true) {
            $p = \Manticore\fgets($buf, $cap, $in);
            if ($p === 0) { break; }
            $chunk = \cstr_to_str($p);
            $n = \strlen($chunk);
            if ($n === 0 || $chunk[$n - 1] !== "\n") { $pend .= $chunk; continue; }
            $line = $pend . \substr($chunk, 0, $n - 1);
            $pend = '';
            if ($scan) { $this->scan($line); } else { $this->lower($line); }
        }
        if ($pend !== '') { if ($scan) { $this->scan($pend); } else { $this->lower($pend); } }
        \Manticore\free($buf);
        \Manticore\fclose($in);
        return true;
    }

    private static function defineName(string $l): string
    {
        if (!\str_starts_with($l, 'define ') || !\str_ends_with(\rtrim($l), '{')) { return ''; }
        $at = \strpos($l, ' @');
        if ($at === false) { return '?'; }
        $pe = \strpos($l, '(', $at);
        return $pe === false ? '?' : \substr($l, $at + 1, $pe - $at - 1);
    }

    private function scan(string $l): void
    {
        if ($this->scanFn === '') {
            if (\str_starts_with($l, '; mc.ccallable @')) { $this->ccallable[] = \substr($l, 15); return; }
            if (\str_starts_with($l, 'declare ')) {
                if (\strpos($l, ' @write(') !== false) { $this->hasWrite = true; }
                if (\strpos($l, ' @llvm.trap(') !== false) { $this->hasTrap = true; }
                if (\strpos($l, ' @__mc_uncaught(') !== false) { $this->hasUncaught = true; }
                return;
            }
            $fn = self::defineName($l);
            if ($fn !== '') {
                $this->scanFn = $fn;
                if ($fn === '@__mc_uncaught') { $this->hasUncaught = true; }
                $this->callees[$fn] = [];
                $this->may[$fn] = false;
            }
            return;
        }
        if ($l === '}') { $this->scanFn = ''; return; }
        if (\strpos($l, 'call ') === false && \strpos($l, 'invoke ') === false) { return; }
        $c = self::callee(self::invokeAsCall($l));
        if ($c === null) { return; }
        if ($c === '' || $c === '@__mc_throw') { $this->may[$this->scanFn] = true; return; }
        $this->callees[$this->scanFn][] = $c;
    }

    private function solve(): void
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($this->callees as $f => $cs) {
                if ($this->may[$f]) { continue; }
                foreach ($cs as $c) {
                    if ($this->calleeMay($c)) { $this->may[$f] = true; $changed = true; break; }
                }
            }
        }
        $this->mayThrow = 0;
        foreach ($this->may as $v) { if ($v) { $this->mayThrow = $this->mayThrow + 1; } }
    }

    private function calleeMay(string $c): bool
    {
        if ($c === '' || $c === '@__mc_throw') { return true; }
        if ($c === self::POLL) {
            // After an FFI call: C may have called a PHP callback that left the slot set.
            foreach ($this->ccallable as $cc) { if ($this->calleeMay($cc)) { return true; } }
            return false;
        }
        if (isset($this->may[$c])) { return $this->may[$c]; }
        if (\str_starts_with($c, '@manticore_')) { return !isset($this->nothrowSyms[$c]); }
        return true;
    }

    /** `invoke <op> to label … unwind label …` as the `call <op>` it performs; other lines unchanged. */
    private static function invokeAsCall(string $l): string
    {
        $iv = \strpos($l, 'invoke ');
        if ($iv === false || !($iv === 2 || \strpos($l, ' = invoke ') !== false)) { return $l; }
        $tl = \strrpos($l, ' to label %');
        return \substr($l, 0, $iv) . 'call ' . \substr($l, $iv + 7, ($tl === false ? \strlen($l) : $tl) - $iv - 7);
    }

    /**
     * The callee of an instruction line that may need a check: '@name', '' for an
     * indirect call, null when the line is not such a call — not a call, an
     * intrinsic, inline asm, a C symbol (outside `@manticore_` / `@__`), or a
     * runtime helper on {@see nounwind}.
     */
    public static function callee(string $l, bool $all = false): ?string
    {
        if (\str_ends_with($l, ' ;!t')) { $l = \substr($l, 0, \strlen($l) - 4); }
        if (\strlen($l) < 3 || $l[0] !== ' ') { return null; }
        $body = \substr($l, 2);
        if ($body !== '' && $body[0] === '%') {
            $eq = \strpos($body, ' = ');
            if ($eq === false) { return null; }
            $body = \substr($body, $eq + 3);
        }
        if (\str_starts_with($body, 'tail call ')) { $body = \substr($body, 5); }
        if (!\str_starts_with($body, 'call ')) { return null; }
        $rest = \substr($body, 5);
        $at = \strpos($rest, '@');
        $pc = \strpos($rest, '%');
        $ai = $at === false ? -1 : $at;
        $pi = $pc === false ? -1 : $pc;
        $p = $ai;
        if ($p < 0 || ($pi >= 0 && $pi < $p)) { $p = $pi; }
        if ($p < 0) { return null; }
        $asm = \strpos($rest, ' asm ');
        if ($asm !== false && $asm < $p) { return null; }
        if ($rest[$p] !== '@') { return ''; }
        $q = \strpos($rest, '(', $p);
        if ($q === false) { return null; }
        $c = \substr($rest, $p, $q - $p);
        if (!\str_starts_with($c, '@manticore_') && !\str_starts_with($c, '@__')) { return null; }
        if (!$all && self::nounwind($c)) { return null; }
        return $c;
    }

    /** Runtime helpers that never run user code and never throw. */
    public static function nounwind(string $callee): bool
    {
        // Each invoke costs a block and an unwind edge per call site: in the
        // Laravel build the backtrace ring alone was 648K of them.
        return $callee === '@__mc_eh_catch'
            || $callee === '@__mir_elem_untag'
            || $callee === '@__mir_bt_push'
            || $callee === '@__mir_bt_push_rel'
            || $callee === '@__mir_bt_pop'
            || $callee === '@__manticore_deref'
            || $callee === '@__manticore_tag'
            || $callee === '@__manticore_cell_int_bad'
            || $callee === '@__mir_obj_handle_alloc'
            || \str_starts_with($callee, '@__mc_dynf_')
            || \str_starts_with($callee, '@__mir_arena_')
            || \str_starts_with($callee, '@__mir_rc_retain')
            || \str_starts_with($callee, '@__manticore_box_');
    }

    /**
     * `$line` (a `phi`) with every incoming block that was split renamed to its
     * last continuation. An incoming block is the `%name` after the last comma of
     * a pair, right before its `]` — a value never sits there.
     * @param array<string, string> $last
     */
    public static function phiRetarget(string $line, array $last): string
    {
        $out = '';
        $p = 0;
        $len = \strlen($line);
        while ($p < $len) {
            $cm = \strpos($line, ', %', $p);
            if ($cm === false) { break; }
            $s = $cm + 3;
            $e = $s;
            while ($e < $len && $line[$e] !== ']' && $line[$e] !== ' ' && $line[$e] !== ',') { $e = $e + 1; }
            $f = $e;
            while ($f < $len && $line[$f] === ' ') { $f = $f + 1; }
            $lab = \substr($line, $s, $e - $s);
            $out .= \substr($line, $p, $s - $p);
            if ($f < $len && $line[$f] === ']' && isset($last[$lab])) {
                $out .= $last[$lab];
            } else {
                $out .= $lab;
            }
            $p = $e;
        }
        return $out . \substr($line, $p);
    }

    /** The return type in a `define` line's text before ` @name`. */
    private static function retTypeOf(string $head): string
    {
        $h = \rtrim($head);
        if (\str_ends_with($h, '}')) {
            $o = \strrpos($h, '{');
            return $o === false ? 'void' : \substr($h, $o);
        }
        $sp = \strrpos($h, ' ');
        return $sp === false ? $h : \substr($h, $sp + 1);
    }

    /** A label operand up to the first `,` or space (drops trailing `, !dbg …`). */
    private static function labelAt(string $s): string
    {
        $e = 0;
        $n = \strlen($s);
        while ($e < $n && $s[$e] !== ',' && $s[$e] !== ' ') { $e = $e + 1; }
        return \substr($s, 0, $e);
    }

    private function emit(string $l): void
    {
        if ($this->inFn) { $this->fnBuf[] = $l; return; }
        $this->put($l);
    }

    private function put(string $l): void
    {
        if ($this->outFile === null) { $this->out[] = $l; return; }
        $s = $l . "\n";
        $this->bytes = $this->bytes + \strlen($s);
        \Manticore\fwrite($s, 1, \strlen($s), $this->outFile);
    }

    private function nextSeq(): string
    {
        $this->seq = $this->seq + 1;
        return (string)$this->seq;
    }

    private function lower(string $l): void
    {
        if (!$this->inFn) {
            $name = self::defineName($l);
            if ($name !== '') {
                $this->inFn = true;
                // The runtime's own entry points never propagate: no checks inside.
                $this->skip = \str_starts_with($name, '@__mc_eh_') || $name === '@__mc_throw' || $name === '@__mc_uncaught';
                $this->isMain = $name === '@main';
                $at = \strpos($l, ' @');
                $this->retTy = $at === false ? 'void' : self::retTypeOf(\substr($l, 0, $at));
                $this->cur = '';
                $this->needProp = false;
            }
            $this->emit($l);
            return;
        }
        if ($l === '}') { $this->endFn(); return; }
        if ($this->skip) {
            $this->emit($l);
            return;
        }
        $len = \strlen($l);
        if ($len > 0 && $l[0] !== ' ' && $l[$len - 1] === ':' && $l[0] !== ';') {
            $this->cur = \substr($l, 0, $len - 1);
            $this->emit($l);
            return;
        }
        $lp = \strpos($l, ' = landingpad ');
        if ($lp !== false) {
            $reg = \trim(\substr($l, 0, $lp));
            $sel = \strpos($l, ' catch ') !== false ? '1' : '0';
            $s = $this->nextSeq();
            $this->emit('  %mc.le' . $s . ' = load ptr, ptr @__mc_exc');
            $this->emit('  store ptr null, ptr @__mc_exc');
            $this->emit('  %mc.la' . $s . ' = insertvalue { ptr, i32 } undef, ptr %mc.le' . $s . ', 0');
            $this->emit('  ' . $reg . ' = insertvalue { ptr, i32 } %mc.la' . $s . ', i32 ' . $sel . ', 1');
            return;
        }
        if (\str_starts_with($l, '  resume { ptr, i32 } ')) {
            $s = $this->nextSeq();
            $this->emit('  %mc.re' . $s . ' = extractvalue { ptr, i32 } ' . self::labelAt(\substr($l, 22)) . ', 0');
            $this->emit('  store ptr %mc.re' . $s . ', ptr @__mc_exc');
            $this->emit('  br label %mc.prop');
            $this->needProp = true;
            return;
        }
        $iv = \strpos($l, 'invoke ');
        if ($iv !== false && ($iv === 2 || \strpos($l, ' = invoke ') !== false)) {
            $tl = \strrpos($l, ' to label %');
            $ul = \strrpos($l, ' unwind label %');
            if ($tl !== false && $ul !== false && $ul > $tl) {
                $norm = \substr($l, $tl + 11, $ul - $tl - 11);
                $pad = self::labelAt(\substr($l, $ul + 15));
                $s = $this->nextSeq();
                $this->emit(\substr($l, 0, $iv) . 'call ' . \substr($l, $iv + 7, $tl - $iv - 7));
                $this->emit('  %mc.c' . $s . ' = load ptr, ptr @__mc_exc');
                $this->emit('  %mc.d' . $s . ' = icmp ne ptr %mc.c' . $s . ', null');
                $this->emit('  br i1 %mc.d' . $s . ', label %' . $pad . ', label %' . $norm);
                $this->checks = $this->checks + 1;
                return;
            }
        }
        if ($len > 8 && \strpos($l, 'call ') !== false && \strpos($l, 'musttail') === false) {
            $c = self::callee($l);
            if ($c === self::POLL && !$this->calleeMay($c)) { return; }
            if ($c !== null && $this->calleeMay($c)) {
                $s = $this->nextSeq();
                $k = 'mc.k' . $s;
                if ($c !== self::POLL) { $this->emit($l); }
                $this->emit('  %mc.c' . $s . ' = load ptr, ptr @__mc_exc');
                $this->emit('  %mc.d' . $s . ' = icmp ne ptr %mc.c' . $s . ', null');
                $this->emit('  br i1 %mc.d' . $s . ', label %mc.prop, label %' . $k);
                $this->emit($k . ':');
                if ($this->cur !== '') { $this->last[$this->cur] = $k; }
                $this->needProp = true;
                $this->checks = $this->checks + 1;
                return;
            }
            $a = $this->assert ? self::callee($l, true) : null;
            if ($a !== null && $a !== '' && $a !== self::POLL) {
                if (!isset($this->assertMsg[$a])) { $this->assertMsg[$a] = '@.mc.ea.' . (string)\count($this->assertMsg); }
                $len = \strlen('EH ASSERT: exception set after nothrow call to ' . $a . "\n");
                $s = $this->nextSeq();
                $k = 'mc.k' . $s;
                $this->emit($l);
                $this->emit('  %mc.c' . $s . ' = load ptr, ptr @__mc_exc');
                $this->emit('  %mc.d' . $s . ' = icmp ne ptr %mc.c' . $s . ', null');
                $this->emit('  br i1 %mc.d' . $s . ', label %mc.af' . $s . ', label %' . $k);
                $this->emit('mc.af' . $s . ':');
                $this->emit('  call void @__mc_eh_assert_fail(ptr ' . $this->assertMsg[$a] . ', i64 ' . (string)$len . ')');
                $this->emit('  unreachable');
                $this->emit($k . ':');
                if ($this->cur !== '') { $this->last[$this->cur] = $k; }
                return;
            }
        }
        if (\strpos($l, ' = landingpad ') !== false || \str_starts_with($l, '  invoke ') || \strpos($l, ' = invoke ') !== false) {
            throw new \RuntimeException('EhLowerIr: unlowered exception edge: ' . \substr($l, 0, 200));
        }
        $this->emit($l);
    }

    private function endFn(): void
    {
        if ($this->needProp) {
            $this->fnBuf[] = 'mc.prop:';
            if ($this->isMain) {
                $this->fnBuf[] = '  %mc.pe = load ptr, ptr @__mc_exc';
                $this->fnBuf[] = '  store ptr null, ptr @__mc_exc';
                $this->fnBuf[] = '  call void @__mc_uncaught(ptr %mc.pe)';
                $this->usedUncaught = true;
                $this->fnBuf[] = '  unreachable';
            } elseif ($this->retTy === 'void') {
                $this->fnBuf[] = '  ret void';
            } else {
                $this->fnBuf[] = '  ret ' . $this->retTy . ' undef';
            }
        }
        $this->fnBuf[] = '}';
        $this->inFn = false;
        $retarget = $this->last !== [];
        foreach ($this->fnBuf as $b) {
            $line = (string)$b;
            if ($retarget && \strpos($line, ' = phi ') !== false) { $line = self::phiRetarget($line, $this->last); }
            $this->put($line);
        }
        $this->fnBuf = [];
        $this->last = [];
    }
}
