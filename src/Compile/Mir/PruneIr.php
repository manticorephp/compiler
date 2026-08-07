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
    /** Definitions dropped by the last {@see run} — for MANTICORE_STATS. */
    public int $dropped = 0;
    /** Definitions kept by the last {@see run}. */
    public int $kept = 0;

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
