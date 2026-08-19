<?php

namespace Compile\Mir;

/**
 * Split one emitted module into N independent LLVM modules so `clang` can
 * assemble them concurrently.
 *
 * `clang -O2` is the single largest term in a build — 64% of a user compile
 * (2704 ms of 4205 ms on examples/http/hello.php) and ~49% of `bin/build`
 * (53 s of 108 s) — and it is single threaded. Everything else in this epic
 * made the front end emit less; this makes the back end use the machine.
 * Measured 2.375 s -> 0.449 s across 8 parts, byte-identical output, and no
 * runtime cost from the inlining lost at the part boundary (fib.php 111.7 ->
 * 110.5 ms).
 *
 * ── What may be partitioned ──
 * A function body may go in any part regardless of linkage: `external` and
 * `linkonce_odr` both resolve across objects. That is what makes this worth
 * anything — in a user program 89% of the module is the injected prelude, which
 * is linkonce_odr, so treating "the shared runtime" as one indivisible part
 * would leave nothing to parallelise. In the compiler's own module the ratio is
 * inverted (91% external), which is why `bin/build` splits trivially.
 *
 * ── What may not ──
 * `internal` / `private` is FILE-LOCAL. A closure body is emitted `define
 * internal`, so moving it away from its caller does not merely fail to link, it
 * is invisible across the object boundary. Those are never partitioned: every
 * part that reaches one gets its own copy, closed to a fixpoint (an internal
 * function can call another).
 *
 * ── Discardable vs strong ──
 * `linkonce_odr` means DISCARDABLE IF UNUSED, so a definition parked in a part
 * that does not itself use it is deleted by `clang -O2` — the same GlobalDCE
 * {@see PruneIr} relies on — and goes undefined at link. Coalesced GLOBALS are
 * therefore emitted into every part that names them (the linkage exists exactly
 * to let the linker fold them); coalesced FUNCTIONS stay partitioned and are
 * pinned with `@llvm.compiler.used`, which holds them through the optimizer
 * while still letting the LINKER dead-strip whatever the program cannot reach,
 * so nothing grows in the output. A global with plain external linkage is the
 * opposite case — not discardable, so it must be defined exactly once, or the
 * linker reports duplicate symbols (the superglobal cells did).
 */
final class SplitModule
{
    /** Definitions that stayed shared (partitioned), for MANTICORE_STATS. */
    public int $sharedDefs = 0;
    /** Definitions duplicated because they are file-local. */
    public int $internalDefs = 0;

    /** Cached symbol references for definitions reused by several parts. */
    /** @var array<string, array<string, bool>> */
    private array $refsCache = [];

    /**
     * @return string[] one complete module text per part
     */
    public function run(string $ir, int $parts): array
    {
        if ($parts < 2) { return [$ir]; }
        $this->refsCache = [];
        $lines = \explode("\n", $ir);
        $n = \count($lines);

        /** @var string[] module-level lines every part needs verbatim */
        $header = [];
        /** @var array<string, string> global name => its definition line */
        $globals = [];
        /** @var string[] */
        $globalOrder = [];
        /** @var array<string, string> symbol => full definition text */
        $defs = [];
        /** @var string[] */
        $defOrder = [];
        /** @var string[] */
        $declares = [];

        $cur = '';
        $buf = '';
        for ($i = 0; $i < $n; $i++) {
            $l = $lines[$i];
            if ($cur !== '') {
                $buf = $buf . "\n" . $l;
                if (\rtrim($l) === '}') {
                    $defs[$cur] = $buf;
                    $defOrder[] = $cur;
                    $cur = '';
                }
                continue;
            }
            if (\strlen($l) > 7 && \substr($l, 0, 7) === 'define ') {
                $sym = $this->defineSymbol($l);
                if ($sym === '') { $header[] = $l; continue; }
                $cur = $sym;
                $buf = $l;
                continue;
            }
            if ($l !== '' && $l[0] === '@') {
                $sym = $this->globalName($l);
                if ($sym === '') { $header[] = $l; continue; }
                $globals[$sym] = $l;
                $globalOrder[] = $sym;
                continue;
            }
            if (\str_starts_with($l, 'declare ')) { $declares[] = $l; continue; }
            $header[] = $l;
        }

        /** @var array<string, bool> */
        $internal = [];
        /** @var string[] */
        $shared = [];
        foreach ($defOrder as $s) {
            $head = $this->head($defs[$s]);
            if (\str_contains($head, ' internal ') || \str_contains($head, ' private ')) {
                $internal[$s] = true;
                continue;
            }
            $shared[] = $s;
        }
        $this->sharedDefs = \count($shared);
        $this->internalDefs = \count($internal);

        // Largest first into the lightest part, so one fat function does not
        // decide the wall clock on its own.
        $bySize = [];
        foreach ($shared as $s) { $bySize[$s] = \strlen($defs[$s]); }
        \arsort($bySize);
        // ⚠ NOT array_fill(0, $parts, 0): the array it hands back does not
        // survive a write-then-read natively. Filled here, written on iteration
        // 1 and read on iteration 2, it faulted on a garbage address (0x56413 —
        // an integer read as a pointer) in a self-built compiler while Zend ran
        // the identical code fine. An explicit loop is correct; the array_fill
        // repr bug is real and outlives this file.
        $load = [];
        for ($q = 0; $q < $parts; $q = $q + 1) { $load[] = 0; }
        /** @var array<string, int> */
        $assign = [];
        foreach ($bySize as $sym => $sz) {
            $min = 0;
            for ($p = 1; $p < $parts; $p++) {
                if ($load[$p] < $load[$min]) { $min = $p; }
            }
            $assign[$sym] = $min;
            $load[$min] = $load[$min] + $sz;
        }

        $headerText = \implode("\n", $header);
        $declText = \implode("\n", $declares);
        $out = [];
        for ($p = 0; $p < $parts; $p++) {
            // `module asm` may contain global symbol definitions (the ARM
            // fiber switch helpers). Copying that header verbatim to every
            // part creates duplicate symbols when relocatable objects merge.
            // Keep module asm in part 0; ordinary headers/declarations remain
            // shared by every part.
            $partHeader = $headerText;
            if ($p !== 0) {
                $partHeader = (string)\preg_replace('/^module asm .*\n?/m', '', $partHeader);
            }
            $out[] = $this->emitPart($p, $parts, $defOrder, $defs, $assign,
                                     $internal, $globalOrder, $globals, $partHeader, $declText);
        }
        return $out;
    }

    /**
     * @param string[]              $defOrder
     * @param array<string, string> $defs
     * @param array<string, int>    $assign
     * @param array<string, bool>   $internal
     * @param string[]              $globalOrder
     * @param array<string, string> $globals
     */
    private function emitPart(int $p, int $parts, array $defOrder, array $defs, array $assign,
                              array $internal, array $globalOrder, array $globals,
                              string $headerText, string $declText): string
    {
        /** @var string[] */
        $mine = [];
        foreach ($defOrder as $s) {
            if (isset($internal[$s])) { continue; }
            if (($assign[$s] ?? -1) === $p) { $mine[] = $s; }
        }
        /** @var array<string, bool> */
        $refs = [];
        foreach ($mine as $s) {
            foreach ($this->refsOf($s, $defs[$s]) as $r => $_) { $refs[$r] = true; }
        }
        // Every file-local definition reachable from here, to a fixpoint.
        /** @var array<string, bool> */
        $haveInternal = [];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($defOrder as $s) {
                if (!isset($internal[$s]) || isset($haveInternal[$s]) || !isset($refs[$s])) { continue; }
                $haveInternal[$s] = true;
                foreach ($this->refsOf($s, $defs[$s]) as $r => $_) { $refs[$r] = true; }
                $changed = true;
            }
        }
        foreach ($defOrder as $s) {
            if (isset($haveInternal[$s])) { $mine[] = $s; }
        }
        // Part 0 carries every non-discardable global, so seed the closure with
        // them: one may be named only by another global's initializer
        // (`@E__fqns` -> `@E__fqn_0`) and would otherwise be emitted without it.
        if ($p === 0) {
            foreach ($globalOrder as $g) {
                if ($this->globalClass($globals[$g]) === 'strong') { $refs[$g] = true; }
            }
        }
        /** @var array<string, bool> */
        $needG = [];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($globalOrder as $g) {
                if (!isset($refs[$g]) || isset($needG[$g])) { continue; }
                $needG[$g] = true;
                foreach ($this->refsOf('global:' . $g, $globals[$g]) as $r => $_) { $refs[$r] = true; }
                $changed = true;
            }
        }
        $gtext = '';
        foreach ($globalOrder as $g) {
            $gl = $globals[$g];
            $cls = $this->globalClass($gl);
            if ($cls === 'local') {
                if (!isset($needG[$g])) { continue; }
                $gtext = $gtext . $this->promoteGlobal($gl) . "\n";
                continue;
            }
            if ($cls === 'coalesced') {
                if (!isset($needG[$g])) { continue; }
                $gtext = $gtext . $gl . "\n";
                continue;
            }
            if ($p === 0) { $gtext = $gtext . $gl . "\n"; continue; }
            if (!isset($needG[$g])) { continue; }
            $gtext = $gtext . $this->externGlobal($gl) . "\n";
        }
        // Declares for what this part calls but another part defines.
        $dtext = '';
        foreach ($refs as $r => $_) {
            if (!isset($defs[$r]) || isset($internal[$r])) { continue; }
            if (($assign[$r] ?? -1) === $p) { continue; }
            $d = $this->declareFromDefine($defs[$r]);
            if ($d !== '') { $dtext = $dtext . $d . "\n"; }
        }
        $body = '';
        /** @var string[] */
        $keep = [];
        foreach ($mine as $s) {
            $body = $body . $defs[$s] . "\n";
            if (\str_contains($this->head($defs[$s]), ' linkonce_odr ')) { $keep[] = $s; }
        }
        $usedText = '';
        if ($keep !== []) {
            $refsList = [];
            foreach ($keep as $s) { $refsList[] = 'ptr ' . $this->symRef($s); }
            $usedText = '@llvm.compiler.used = appending global [' . (string)\count($keep)
                . ' x ptr] [' . \implode(', ', $refsList) . '], section "llvm.metadata"' . "\n";
        }
        return $headerText . "\n" . $declText . "\n" . $gtext . $dtext . $usedText . $body;
    }

    private function head(string $def): string
    {
        $nl = \strpos($def, "\n");
        return $nl === false ? $def : \substr($def, 0, $nl);
    }

    private function symRef(string $sym): string
    {
        $len = \strlen($sym);
        for ($i = 0; $i < $len; $i++) {
            $c = $sym[$i];
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9')
                || $c === '_' || $c === '.' || $c === '$' || $c === '-') {
                continue;
            }
            return '@"' . $sym . '"';
        }
        return '@' . $sym;
    }

    private function defineSymbol(string $line): string
    {
        $at = \strpos($line, '@');
        if ($at === false) { return ''; }
        return $this->readSymbol($line, $at + 1);
    }

    private function globalName(string $line): string
    {
        $eq = \strpos($line, ' = ');
        if ($eq === false) { return ''; }
        return $this->readSymbol($line, 1);
    }

    /** 'local' (file-local), 'coalesced' (discardable if unused) or 'strong'. */
    private function globalClass(string $line): string
    {
        $eq = \strpos($line, ' = ');
        if ($eq === false) { return 'strong'; }
        $rest = \substr($line, $eq + 3);
        if (\str_starts_with($rest, 'internal ') || \str_starts_with($rest, 'private ')) { return 'local'; }
        if (\str_starts_with($rest, 'linkonce_odr ') || \str_starts_with($rest, 'weak_odr ')
            || \str_starts_with($rest, 'linkonce ') || \str_starts_with($rest, 'weak ')) { return 'coalesced'; }
        return 'strong';
    }

    private function promoteGlobal(string $line): string
    {
        $eq = \strpos($line, ' = ');
        if ($eq === false) { return $line; }
        $headPart = \substr($line, 0, $eq + 3);
        $rest = \substr($line, $eq + 3);
        if (\str_starts_with($rest, 'internal ')) {
            return $headPart . 'linkonce_odr ' . \substr($rest, 9);
        }
        if (\str_starts_with($rest, 'private ')) {
            return $headPart . 'linkonce_odr ' . \substr($rest, 8);
        }
        return $line;
    }

    /** The `external` form of a global definition — same name and type, no initializer. */
    private function externGlobal(string $line): string
    {
        $eq = \strpos($line, ' = ');
        if ($eq === false) { return $line; }
        $name = \substr($line, 0, $eq);
        $rest = \substr($line, $eq + 3);
        $kw = '';
        $at = -1;
        $gp = \strpos($rest, 'global ');
        $cp = \strpos($rest, 'constant ');
        if ($gp !== false) { $at = $gp; $kw = 'global '; }
        if ($cp !== false && ($at < 0 || $cp < $at)) { $at = $cp; $kw = 'constant '; }
        if ($at < 0) { return $line; }
        $type = $this->leadType(\ltrim(\substr($rest, $at + \strlen($kw))));
        if ($type === '') { return $line; }
        return $name . ' = external ' . \rtrim($kw) . ' ' . $type;
    }

    /** The leading LLVM type — `[12 x i8]`, `{ i64, ptr }`, `ptr`, `i64`. */
    private function leadType(string $s): string
    {
        if ($s === '') { return ''; }
        $c = $s[0];
        $close = '';
        if ($c === '[') { $close = ']'; }
        elseif ($c === '{') { $close = '}'; }
        elseif ($c === '<') { $close = '>'; }
        if ($close !== '') {
            $depth = 0;
            $len = \strlen($s);
            for ($i = 0; $i < $len; $i++) {
                if ($s[$i] === $c) { $depth = $depth + 1; }
                elseif ($s[$i] === $close) {
                    $depth = $depth - 1;
                    if ($depth === 0) { return \substr($s, 0, $i + 1); }
                }
            }
            return '';
        }
        $sp = \strpos($s, ' ');
        return $sp === false ? \rtrim($s) : \substr($s, 0, $sp);
    }

    /** A `declare` taken from the definition's own header — never invented. */
    private function declareFromDefine(string $def): string
    {
        $headLine = $this->head($def);
        $paren = \strpos($headLine, '(');
        if ($paren === false) { return ''; }
        $close = \strrpos($headLine, ')');
        if ($close === false || $close < $paren) { return ''; }
        $sig = \substr($headLine, 7, $close + 1 - 7);
        $keys = ['linkonce_odr ', 'weak_odr ', 'internal ', 'private ', 'external ',
                 'dso_local ', 'hidden ', 'weak '];
        $again = true;
        while ($again) {
            $again = false;
            foreach ($keys as $k) {
                if (\str_starts_with($sig, $k)) {
                    $sig = \substr($sig, \strlen($k));
                    $again = true;
                }
            }
        }
        return 'declare ' . $sig;
    }

    /** @param array<string, bool> $into */
    private function collectRefs(string $text, array &$into): void
    {
        $len = \strlen($text);
        $i = 0;
        while ($i < $len) {
            $at = \strpos($text, '@', $i);
            if ($at === false) { return; }
            $s = $this->readSymbol($text, $at + 1);
            if ($s !== '') { $into[$s] = true; }
            $i = $at + 1 + \strlen($s);
            if ($i <= $at) { $i = $at + 1; }
        }
    }

    /** @return array<string, bool> */
    private function refsOf(string $key, string $text): array
    {
        if (isset($this->refsCache[$key])) { return $this->refsCache[$key]; }
        $refs = [];
        $this->collectRefs($text, $refs);
        $this->refsCache[$key] = $refs;
        return $refs;
    }

    private function readSymbol(string $s, int $p): string
    {
        $len = \strlen($s);
        if ($p >= $len) { return ''; }
        if ($s[$p] === '"') {
            $end = \strpos($s, '"', $p + 1);
            if ($end === false) { return ''; }
            return \substr($s, $p + 1, $end - $p - 1);
        }
        $start = $p;
        while ($p < $len) {
            $c = $s[$p];
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9')
                || $c === '_' || $c === '.' || $c === '$' || $c === '\\' || $c === '-') {
                $p = $p + 1;
                continue;
            }
            break;
        }
        if ($p === $start) { return ''; }
        return \substr($s, $start, $p - $start);
    }
}
