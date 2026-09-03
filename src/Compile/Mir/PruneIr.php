<?php

namespace Compile\Mir;

/**
 * Drop every `linkonce_odr` definition the emitted module never references.
 *
 * ── Why this exists ──
 * `echo "Hello, World!";` emitted 203 function bodies; 232 of the 8 939 IR
 * lines were the user's program. The rest is the runtime preamble (the whole
 * unified array runtime, the rc/arena/pool helpers, the reflection registry)
 * plus the unconditional prelude (`exceptions.php`, `resource.php`), all of
 * which is emitted whether or not the program can reach it. Pruning it here
 * cuts that module from 278 KB to 54 KB and `clang -O2` from 74 ms to 44 ms
 * for byte-identical behaviour. `docs/ROADMAP.md` states the thesis: IR volume
 * is the build-time lever, and `clang -O2` is ~66% of `bin/build`.
 *
 * ── Why it is safe ──
 * `linkonce_odr` is LLVM's "discardable if unused" linkage; GlobalDCE drops
 * these anyway, only after clang has already parsed, verified and run the early
 * pipeline over them. This does the same deletion earlier and for free. It runs
 * on the FINAL text, so it sees every reference the emitters actually made —
 * completeness is by construction, not by enumerating emit sites. A missed
 * reference cannot happen; an over-approximated one only keeps a body alive.
 *
 * ⚠ Only `linkonce_odr` is ever dropped. A user / stdlib PHP function is
 * `external` and must survive even when nothing in ITS OWN module calls it —
 * that is the whole point of a `.o` plus its `.sig`. The same goes for `@main`.
 * See the linkage contract at {@see Passes\EmitLlvmModule} (~:715) — making
 * these bodies `internal` once silently turned `sort()` into a `return 0` stub
 * because a program relied on coalescing to `stdlib.o`'s copy. Dropping an
 * UNREFERENCED body is a different operation: no reference means no dependency.
 *
 * ⚠ A body may be reachable only through a global initializer — a class
 * descriptor's `@__mir_drop_<hash>` field, `@llvm.global_ctors`, an rmeta
 * method table's trampoline pointer. Every symbol named on a module-level `@`
 * line is therefore a root.
 */
final class PruneIr
{
    /**
     * Pruning is a userland graph walk over the final textual IR. It needs an
     * exploded copy of every line, indexes all definitions, then walks every
     * reachable body looking for `@symbol` references. That is useful when the
     * whole module fits comfortably in memory, but it is the wrong algorithm
     * once the retained IR is hundreds of MiB: LLVM's own GlobalDCE can discard
     * the same `linkonce_odr` helpers after parsing, while this pass would first
     * retain several additional full-text representations.
     *
     * Keep the gate above the 54 MiB self-hosted module, where the pass remains
     * inexpensive and preserves its existing clang-saving benefit. Symfony Tier
     * 4 produces roughly 1.8 GiB of IR and has only 73 discardable helper
     * definitions, so walking its bodies in PHP is overwhelmingly more costly
     * than leaving that tiny tail to LLVM.
     */
    public const MAX_INPUT_BYTES = 64 * 1024 * 1024;

    /** Whether final-text pruning is bounded enough to be worthwhile. */
    public static function shouldRun(int $bytes): bool
    {
        return $bytes <= self::MAX_INPUT_BYTES;
    }

    /** Definitions dropped by the last {@see run} — for MANTICORE_STATS. */
    public int $dropped = 0;
    /** Definitions kept by the last {@see run}. */
    public int $kept = 0;
    /** Bytes the last {@see runFile} sweep removed. */
    public int $droppedBytes = 0;

    /**
     * @param string $ir the complete module text (preamble ⧺ bodies)
     */
    public function run(string $ir): string
    {
        $lines = \explode("\n", $ir);
        $n = \count($lines);
        // Parallel arrays, not an array of objects: an assoc of assocs erases to
        // KIND_UNKNOWN under the self-host and every read comes back raw.
        /** @var string[] */
        $defName = [];
        /** @var bool[] */
        $defDrop = [];
        /** @var int[] */
        $defStart = [];
        /** @var int[] */
        $defEnd = [];
        /** @var array<string, int> symbol → index into the arrays above */
        $index = [];
        /** @var array<string, bool> */
        $roots = [];
        $cur = -1;
        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            $isDefine = \strlen($line) > 7 && $line[0] === 'd' && \substr($line, 0, 7) === 'define ';
            if ($cur >= 0) {
                if (\rtrim($line) === '}') { $defEnd[$cur] = $i; $cur = -1; continue; }
                // A define that never closed would swallow every later one and
                // silently disable the sweep. Close it at the next header.
                if (!$isDefine) { continue; }
                $defEnd[$cur] = $i - 1;
                $cur = -1;
            }
            if ($isDefine) {
                $sym = $this->defineSymbol($line);
                if ($sym === '') { continue; }
                $k = \count($defName);
                $defName[] = $sym;
                $defDrop[] = \strpos($line, ' linkonce_odr ') !== false;
                $defStart[] = $i;
                $defEnd[] = $i;
                $index[$sym] = $k;
                $cur = $k;
                continue;
            }
            // A module-level global / alias / ifunc initializer can hold the only
            // reference to a body (descriptor drop fns, ctor lists, trampoline
            // tables). `declare` does not define anything and roots nothing.
            if ($line !== '' && $line[0] === '@') {
                $this->collectRefs($line, $roots);
            }
        }
        $count = \count($defName);
        if ($count === 0) { return $ir; }
        // Mark: every non-discardable definition is a root, plus anything a
        // global initializer named.
        /** @var string[] */
        $work = [];
        /** @var array<string, bool> */
        $live = [];
        for ($k = 0; $k < $count; $k++) {
            if (!$defDrop[$k]) { $work[] = $defName[$k]; }
        }
        foreach ($roots as $sym => $_) { $work[] = $sym; }
        while ($work !== []) {
            $sym = \array_pop($work);
            if (isset($live[$sym])) { continue; }
            $live[$sym] = true;
            if (!isset($index[$sym])) { continue; }
            $k = $index[$sym];
            /** @var array<string, bool> */
            $refs = [];
            $end = $defEnd[$k];
            for ($i = $defStart[$k]; $i <= $end; $i++) {
                $this->collectRefs($lines[$i], $refs);
            }
            foreach ($refs as $r => $_) {
                if (!isset($live[$r])) { $work[] = $r; }
            }
        }
        // Sweep.
        $this->dropped = 0;
        $this->kept = 0;
        /** @var bool[] parallel to $lines: true = drop */
        $cut = [];
        for ($k = 0; $k < $count; $k++) {
            if (!$defDrop[$k] || isset($live[$defName[$k]])) { $this->kept++; continue; }
            $this->dropped++;
            $end = $defEnd[$k];
            for ($i = $defStart[$k]; $i <= $end; $i++) { $cut[$i] = true; }
        }
        if ($this->dropped === 0) { return $ir; }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if (isset($cut[$i])) { continue; }
            $out[] = $lines[$i];
        }
        return \implode("\n", $out);
    }

    /**
     * The staged path's ceiling, far above {@see MAX_INPUT_BYTES}.
     *
     * That 64 MiB cap exists because {@see run} explodes the module into lines
     * and rebuilds it — several full-text copies at once. This path holds only
     * the index, so the memory argument does not apply and the real cost is one
     * extra read plus one write. The cap that remains is about I/O: Symfony Tier
     * 4 emits ~1.8 GiB of IR for 73 discardable helpers, where copying the file
     * twice to save clang 73 bodies is plainly the wrong trade.
     */
    public const MAX_FILE_BYTES = 512 * 1024 * 1024;

    /** Whether pruning a staged module file is worth its two passes. */
    public static function shouldRunFile(int $bytes): bool
    {
        return $bytes <= self::MAX_FILE_BYTES;
    }

    /**
     * Prune a STAGED module file, writing the result to $tmpPath.
     *
     * Same mark-and-sweep as {@see run}, addressed by byte offsets instead of
     * line indices, so the module is never resident. The sweep is the reason
     * this is cheap: dropping a definition means NOT copying its byte range, so
     * the output is the input minus those ranges — nothing is reconstructed and
     * the preamble, globals and declares pass through untouched.
     *
     * Streaming made this pass dead code: `EmitLlvm::emit` returns the staged
     * marker before it ever reaches the pruner, so every manifest build has been
     * handing clang the discardable helpers it could have dropped.
     *
     * @return bool false on an I/O failure; true otherwise, with $this->dropped
     *              telling the caller whether $tmpPath was actually written
     */
    public function runFile(string $path, string $tmpPath): bool
    {
        $in = \Manticore\fopen($path, 'rb');
        $cap = 1048576;
        $buf = \Manticore\malloc($cap);

        /** @var string[] */
        $defName = [];
        /** @var bool[] */
        $defDrop = [];
        /** @var int[] */
        $defOff = [];
        /** @var int[] */
        $defEnd = [];
        /** @var array<string, array<string, bool>> */
        $defRefs = [];
        /** @var array<string, int> */
        $index = [];
        /** @var array<string, bool> */
        $roots = [];

        $pos = 0;
        $cur = -1;
        /** @var array<string, bool> */
        $refs = [];
        while (true) {
            $p = \Manticore\fgets($buf, $cap, $in);
            if ($p === 0) { break; }
            $line = \cstr_to_str($p);
            $n = \strlen($line);
            $bare = \rtrim($line, "\n");
            $isDefine = \strlen($bare) > 7 && $bare[0] === 'd' && \substr($bare, 0, 7) === 'define ';
            if ($cur >= 0) {
                if (\rtrim($bare) === '}') {
                    $pos = $pos + $n;
                    $defEnd[$cur] = $pos;
                    $defRefs[$defName[$cur]] = $refs;
                    $refs = [];
                    $cur = -1;
                    continue;
                }
                // A define that never closed would swallow every later one and
                // silently disable the sweep. Close it at the next header.
                if (!$isDefine) {
                    $this->collectRefs($bare, $refs);
                    $pos = $pos + $n;
                    continue;
                }
                $defEnd[$cur] = $pos;
                $defRefs[$defName[$cur]] = $refs;
                $refs = [];
                $cur = -1;
            }
            if ($isDefine) {
                $sym = $this->defineSymbol($bare);
                if ($sym === '') { $pos = $pos + $n; continue; }
                $k = \count($defName);
                $defName[] = $sym;
                $defDrop[] = \strpos($bare, ' linkonce_odr ') !== false;
                $defOff[] = $pos;
                $defEnd[] = $pos + $n;
                $index[$sym] = $k;
                $refs = [];
                $this->collectRefs($bare, $refs);
                $cur = $k;
                $pos = $pos + $n;
                continue;
            }
            if ($bare !== '' && $bare[0] === '@') { $this->collectRefs($bare, $roots); }
            $pos = $pos + $n;
        }
        $total = $pos;
        $count = \count($defName);
        $this->dropped = 0;
        $this->kept = 0;
        $this->droppedBytes = 0;
        if ($count === 0) {
            \Manticore\free($buf);
            \Manticore\fclose($in);
            return true;
        }

        /** @var string[] */
        $work = [];
        /** @var array<string, bool> */
        $live = [];
        for ($k = 0; $k < $count; $k = $k + 1) {
            if (!$defDrop[$k]) { $work[] = $defName[$k]; }
        }
        foreach ($roots as $sym => $_) { $work[] = $sym; }
        while ($work !== []) {
            $sym = \array_pop($work);
            if (isset($live[$sym])) { continue; }
            $live[$sym] = true;
            if (!isset($defRefs[$sym])) { continue; }
            foreach ($defRefs[$sym] as $r => $_) {
                if (!isset($live[$r])) { $work[] = $r; }
            }
        }

        /** @var int[] */
        $cutOff = [];
        /** @var int[] */
        $cutEnd = [];
        for ($k = 0; $k < $count; $k = $k + 1) {
            if (!$defDrop[$k] || isset($live[$defName[$k]])) { $this->kept = $this->kept + 1; continue; }
            $this->dropped = $this->dropped + 1;
            $cutOff[] = $defOff[$k];
            $cutEnd[] = $defEnd[$k];
            $this->droppedBytes = $this->droppedBytes + ($defEnd[$k] - $defOff[$k]);
        }
        if ($this->dropped === 0) {
            \Manticore\free($buf);
            \Manticore\fclose($in);
            return true;
        }

        // Definitions were indexed in file order, so the cut ranges already are.
        $out = \Manticore\fopen($tmpPath, 'wb');
        $at = 0;
        $nCut = \count($cutOff);
        for ($k = 0; $k < $nCut; $k = $k + 1) {
            if ($cutOff[$k] > $at
                && !$this->copySpan($in, $out, $buf, $cap, $at, $cutOff[$k] - $at)) {
                \Manticore\fclose($out);
                \Manticore\free($buf);
                \Manticore\fclose($in);
                return false;
            }
            $at = $cutEnd[$k];
        }
        if ($total > $at && !$this->copySpan($in, $out, $buf, $cap, $at, $total - $at)) {
            \Manticore\fclose($out);
            \Manticore\free($buf);
            \Manticore\fclose($in);
            return false;
        }
        \Manticore\fclose($out);
        \Manticore\free($buf);
        \Manticore\fclose($in);
        return true;
    }

    /** Copy $len bytes at $off from $in to $out through the shared buffer. */
    private function copySpan(\Ffi\Ptr $in, \Ffi\Ptr $out, \Ffi\Ptr $buf, int $cap,
                              int $off, int $len): bool
    {
        if (\Manticore\fseek($in, $off, 0) !== 0) { return false; }
        $left = $len;
        while ($left > 0) {
            $want = $left < $cap ? $left : $cap;
            $got = \Manticore\fread($buf, 1, $want, $in);
            if ($got <= 0) { return false; }
            if (\Manticore\fwrite_buf($buf, 1, $got, $out) !== $got) { return false; }
            $left = $left - $got;
        }
        return true;
    }

    /**
     * The symbol a `define` line defines, or '' when the line is malformed.
     * The FIRST `@` on the line is the name: linkage keywords and the return
     * type (`i64`, `ptr`, `{i64, i1}`) never carry one.
     */
    private function defineSymbol(string $line): string
    {
        $at = \strpos($line, '@');
        if ($at === false) { return ''; }
        return $this->readSymbol($line, $at + 1);
    }

    /**
     * Every `@symbol` named on one line, added to `$into`.
     *
     * Hand-rolled rather than `preg_match_all`: this runs over every line of a
     * module that can reach 80k lines, and the regex engine costs more than the
     * clang time being saved. A `@` inside a `c"…"` string constant can only
     * appear on a module-level `@` line, which is a root line anyway — an
     * over-collected name that matches no definition is dropped by the
     * `isset($index[...])` test in the mark loop.
     *
     * @param array<string, bool> $into
     */
    private function collectRefs(string $line, array &$into): void
    {
        $len = \strlen($line);
        $i = 0;
        while ($i < $len) {
            $at = \strpos($line, '@', $i);
            if ($at === false) { return; }
            $sym = $this->readSymbol($line, $at + 1);
            if ($sym !== '') { $into[$sym] = true; }
            $i = $at + 1 + \strlen($sym);
            if ($i <= $at) { $i = $at + 1; }
        }
    }

    /**
     * The LLVM identifier starting at `$p` (just past an `@`). Handles the
     * quoted form `@"Foo\\Bar"` the mangler emits for a namespaced name.
     */
    private function readSymbol(string $line, int $p): string
    {
        $len = \strlen($line);
        if ($p >= $len) { return ''; }
        if ($line[$p] === '"') {
            $end = \strpos($line, '"', $p + 1);
            if ($end === false) { return ''; }
            return \substr($line, $p + 1, $end - $p - 1);
        }
        $s = $p;
        while ($p < $len) {
            $c = $line[$p];
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')
                || ($c >= '0' && $c <= '9')
                || $c === '_' || $c === '.' || $c === '$' || $c === '\\' || $c === '-') {
                $p++;
                continue;
            }
            break;
        }
        if ($p === $s) { return ''; }
        return \substr($line, $s, $p - $s);
    }
}
