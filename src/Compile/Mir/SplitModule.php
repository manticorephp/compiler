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
 * therefore emitted ONCE — see below; coalesced FUNCTIONS stay partitioned and are
 * pinned with `@llvm.compiler.used`, which holds them through the optimizer
 * while still letting the LINKER dead-strip whatever the program cannot reach,
 * so nothing grows in the output. A global with plain external linkage is the
 * opposite case — not discardable, so it must be defined exactly once, or the
 * linker reports duplicate symbols (the superglobal cells did).
 *
 * ── A coalesced GLOBAL is ONE definition per module, not one per part ──
 * Copying it into every part that names it is legal, and free while a module
 * has two parts. It is not free at 24: symfony-demo T5 fed clang 3.96 GB of
 * part text for a 1.74 GB module, and 615 MB of that difference was 163 595
 * distinct `@.rmeta.*` globals arriving as 2 338 967 copies. clang's peak is
 * roughly proportional to the bytes it is handed, so the duplication is paid
 * twice — in wall clock and in the memory ceiling that decides whether the
 * program builds at all. {@see closePart} claims one owning part per global —
 * the first that reaches it — and every other part gets `external`. The claim
 * also CUTS the closure: a part holding only `@desc = external` does not name
 * what @desc initializer named, so the reflection table stops travelling with
 * the descriptor.
 */
final class SplitModule
{
    /** Definitions that stayed shared (partitioned), for MANTICORE_STATS. */
    public int $sharedDefs = 0;
    /** Definitions duplicated because they are file-local. */
    public int $internalDefs = 0;

    /**
     * Assign by symbol HASH instead of by load, so a part's membership does not
     * depend on any other definition.
     *
     * The balancing partitioner is the better one for a single build: it prices
     * each placement against what the part would then have to copy. But it makes
     * every part a function of the WHOLE module — one body growing re-shuffles
     * the lot — and that is exactly what an object cache cannot survive. Hashing
     * the symbol gives up some balance to buy the property that an unchanged
     * definition stays where it was.
     */
    public bool $stable = false;

    /** Size from which a file-local global gets one definition per module. */
    private const SHARED_LOCAL_BYTES = 4096;

    private const OPTNONE_DEFAULT_KB = 1024;

    /** Cached symbol references for definitions reused by several parts. */
    /** @var array<string, array<string, bool>> */
    private array $refsCache = [];

    /** @var array<string, string> global name => globalClass(), computed once */
    private array $gcls = [];

    /** @var array<string, int> definition symbol => index in defOrder */
    private array $defPos = [];

    /** @var array<string, int> global name => index in globalOrder */
    private array $gpos = [];

    /**
     * Symbols the INPUT module pins with its own `@llvm.compiler.used` (a shared-
     * generics program part pins what its body parts name). The array itself is
     * dropped — each part writes one — and a pinned global is defined, non-
     * discardable, in part 0.
     * @var array<string, bool>
     */
    private array $inputPins = [];

    /** @var array<int, string[]> part => its own non-internal definitions in defOrder order */
    private array $partMine = [];

    /**
     * @return string[] one complete module text per part
     */
    public function run(string $ir, int $parts): array
    {
        if ($parts < 2) { return [$ir]; }
        $this->refsCache = [];
        $this->inputPins = [];
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
                if ($sym === 'llvm.compiler.used') { $this->notePins($l); continue; }
                $globals[$sym] = $l;
                $globalOrder[] = $sym;
                continue;
            }
            if (\str_starts_with($l, 'declare ')) { $declares[] = $l; continue; }
            $header[] = $l;
        }

        // Everything the partitioning needs from a definition is its header line
        // and the symbols it names — never the body. Deriving both up front is
        // what lets {@see runFile} answer the same questions from an index of
        // byte offsets, without a module-sized string.
        /** @var array<string, string> symbol => its `define …` header line */
        $defHead = [];
        /** @var array<string, array<string, bool>> symbol => symbols it names */
        $defRefs = [];
        /** @var array<string, int> symbol => body size, for load balancing */
        $defSize = [];
        foreach ($defOrder as $s) {
            $defHead[$s] = $this->head($defs[$s]);
            $defRefs[$s] = $this->refsOf($s, $defs[$s]);
            $defSize[$s] = \strlen($defs[$s]);
        }
        foreach ($this->promoteLarge($defOrder, $defHead, $defSize, $defRefs) as $s => $oldLen) {
            $defs[$s] = $defHead[$s] . \substr($defs[$s], $oldLen);
        }

        /** @var array<string, bool> */
        $internal = [];
        /** @var string[] */
        $shared = [];
        foreach ($defOrder as $s) {
            $head = $defHead[$s];
            if (\str_contains($head, ' internal ') || \str_contains($head, ' private ')) {
                $internal[$s] = true;
                continue;
            }
            $shared[] = $s;
        }
        $this->sharedDefs = \count($shared);
        $this->internalDefs = \count($internal);

        $this->indexModule($defOrder, $globalOrder, $globals);
        $assign = $this->assignParts($shared, $defOrder, $defSize, $defRefs, $internal, $parts);
        // Threaded through the part loop: the first part that reaches a
        // coalesced global defines it, every later part declares it.
        /** @var array<string, bool> */
        $owned = [];
        $lineTable = $this->lineTableGlobals($globalOrder, $owned);

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
            $plan = $this->planPart($p, $defOrder, $defHead, $defRefs, $defSize, $assign,
                                    $internal, $globalOrder, $globals, $owned);
            $body = '';
            foreach ($plan->mine as $s) { $body = $body . $defs[$s] . "\n"; }
            foreach ($plan->avail as $s) {
                $body = $body . $this->availHead($defHead[$s])
                      . \substr($defs[$s], \strlen($defHead[$s])) . "\n";
            }
            $out[] = $partHeader . "\n" . $declText . "\n"
                   . $plan->gtext . $plan->dtext . $plan->usedText . $body;
        }
        if ($lineTable !== []) {
            $out[] = $this->lineTablePart($headerText, $lineTable, $globals);
        }
        return $out;
    }

    /**
     * Insert $v into the sorted tail of $a starting at $from.
     *
     * @param int[] $a
     */
    private function sortedInsert(array &$a, int $from, int $v): void
    {
        $lo = $from;
        $hi = \count($a);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($a[$mid] < $v) { $lo = $mid + 1; } else { $hi = $mid; }
        }
        \array_splice($a, $lo, 0, [$v]);
    }

    /**
     * @param string[]              $defOrder
     * @param string[]              $globalOrder
     * @param array<string, string> $globals
     */
    private function indexModule(array $defOrder, array $globalOrder, array $globals): void
    {
        $this->gcls = [];
        $this->gpos = [];
        $k = 0;
        foreach ($globalOrder as $g) {
            // A pinned global is defined once, in part 0, whatever its linkage.
            $this->gcls[$g] = isset($this->inputPins[$g]) ? 'strong' : $this->globalClass($globals[$g]);
            $this->gpos[$g] = $k;
            $k = $k + 1;
        }
        $this->defPos = [];
        $i = 0;
        foreach ($defOrder as $s) { $this->defPos[$s] = $i; $i = $i + 1; }
    }

    /**
     * The `@.btl.<fn>` base-line globals (EmitLlvm::btPush), claimed up front so
     * no ordinary part owns one: every part declares them `external`, and one
     * extra part — globals only — defines them all.
     *
     * A base line moves whenever a line is inserted above its function; owned
     * by the function's own part it would still rewrite that part, and an edit
     * to one file would still dirty every part holding a later function of it.
     * Here the edit dirties its own function's part and this table, which is
     * cheap to recompile, so the object cache keeps the rest.
     *
     * @param string[]            $globalOrder
     * @param array<string, bool> $owned
     * @return string[]
     */
    private function lineTableGlobals(array $globalOrder, array &$owned): array
    {
        $out = [];
        foreach ($globalOrder as $g) {
            if (!\str_starts_with($g, '.btl.')) { continue; }
            $owned[$g] = true;
            $out[] = $g;
        }
        return $out;
    }

    /**
     * @param string[]              $lineTable
     * @param array<string, string> $globals
     */
    private function lineTablePart(string $headerText, array $lineTable, array $globals): string
    {
        $text = (string)\preg_replace('/^module asm .*\n?/m', '', $headerText) . "\n";
        foreach ($lineTable as $g) { $text .= $this->pinGlobal($globals[$g]) . "\n"; }
        return $text;
    }

    /**
     * Promote the big file-local bodies that several definitions call.
     *
     * A `define internal` body is copied into every part that reaches one
     * ({@see assignParts}), so a 600 KB closure called from 60 parts is 60
     * copies for clang to optimise. Past {@see PROMOTE_MIN_BYTES} and with two
     * or more callers the body is worth more as ONE definition than as a copy
     * that can be inlined: it goes `hidden` (external to the parts, invisible to
     * the final binary's symbol table), becomes an ordinary shared definition
     * and lands in exactly one part. `$defHead` is rewritten in place.
     *
     * @param string[]                           $defOrder
     * @param array<string, string>              $defHead
     * @param array<string, int>                 $defSize
     * @param array<string, array<string, bool>> $defRefs
     * @return array<string, int> promoted symbol => its ORIGINAL header length
     */
    private function promoteLarge(array $defOrder, array &$defHead, array $defSize, array $defRefs): array
    {
        /** @var array<string, int> */
        $users = [];
        foreach ($defOrder as $s) {
            foreach ($defRefs[$s] as $r => $_) {
                if ($r !== $s) { $users[$r] = ($users[$r] ?? 0) + 1; }
            }
        }
        /** @var array<string, int> */
        $promoted = [];
        foreach ($defOrder as $s) {
            if (($defSize[$s] ?? 0) < self::PROMOTE_MIN_BYTES || ($users[$s] ?? 0) < 2) { continue; }
            $h = $defHead[$s];
            $at = \strpos($h, ' internal ');
            if ($at === false) { continue; }
            $promoted[$s] = \strlen($h);
            $defHead[$s] = \substr($h, 0, $at) . ' hidden ' . \substr($h, $at + 10);
        }
        $this->markOversized($defOrder, $defSize, $defHead, $promoted);
        return $promoted;
    }

    /**
     * Leave a definition of at least {@see OPTNONE_DEFAULT_KB} KB unoptimized:
     * clang's cost grows faster than a function's size, so a few giants dominate
     * (78 definitions were 30% of clang's CPU on a Symfony app, and they are mostly
     * generated dispatch). `MANTICORE_OPTNONE_KB=<n>` moves the bar, 0 turns it off,
     * and `-O3` asks for everything to be optimized.
     *
     * @param string[]              $defOrder
     * @param array<string, int>    $defSize
     * @param array<string, string> $defHead
     * @param array<string, int>    $promoted symbol => length of its head in the file
     */
    private function markOversized(array $defOrder, array $defSize, array &$defHead, array &$promoted): void
    {
        $env = \getenv('MANTICORE_OPTNONE_KB');
        $kb = $env === false ? self::OPTNONE_DEFAULT_KB : (int)$env;
        if ($kb <= 0 || ($env === false && \Manticore\clang_opt_level() === '3')) { return; }
        foreach ($defOrder as $s) {
            if (($defSize[$s] ?? 0) < $kb * 1024) { continue; }
            $h = $defHead[$s];
            if (\str_contains($h, 'alwaysinline')) { continue; }
            $open = \strpos($h, '(', (int)\strpos($h, '@'));
            if ($open === false) { continue; }
            $depth = 0;
            $n = \strlen($h);
            for ($i = $open; $i < $n; $i = $i + 1) {
                if ($h[$i] === '(') { $depth = $depth + 1; }
                if ($h[$i] === ')') { $depth = $depth - 1; }
                if ($depth === 0) { break; }
            }
            if ($i >= $n) { continue; }
            if (!isset($promoted[$s])) { $promoted[$s] = \strlen($h); }
            $defHead[$s] = \substr($h, 0, $i + 1) . ' noinline optnone' . \substr($h, $i + 1);
        }
    }


    /**
     * Assign every shared definition to a part, charging each part for the
     * file-local bodies that placing it there would COPY.
     *
     * Balancing by size alone is what makes a part boundary expensive. A
     * `define internal` body is file-local, so every part that reaches one gets
     * its own copy — and with the shared definitions scattered by size, the same
     * closure is reached from many parts. On symfony-demo T5 that put 22 375
     * distinct internal bodies into 54 852 copies: 1.08 GB of duplicate text out
     * of the 3.96 GB handed to clang for a 1.74 GB module.
     *
     * The cost of a placement is therefore the part's RESULTING size, not the
     * definition's own: its bytes, plus every internal component it names that
     * the part does not already hold. A part that already carries the component
     * gets it for free and wins; once it has taken enough of them its load is
     * what dominates and the next definition goes elsewhere. Affinity and
     * balance out of one number, with no clustering step to run away.
     *
     * ⚠ Do NOT do this by MERGING instead — union the definitions that share a
     * component and pack the unions. Measured: an internal body every part calls
     * (the out-lined dispatchers) merges the whole module into one cluster, and
     * part 0 comes out at 1318 MB against 17-85 MB for the other 23. clang's
     * peak follows the fattest part, so that is worse than the duplication it
     * removes. Capping the merge width at 8 referrers keeps the balance and
     * gives back nearly all of the saving (x2.275 -> x2.017); this rule gets it
     * without the cap.
     *
     * ⚠ NOT array_fill(0, $parts, 0): the array it hands back does not survive a
     * write-then-read natively. Filled here, written on iteration 1 and read on
     * iteration 2, it faulted on a garbage address (0x56413 — an integer read as
     * a pointer) in a self-built compiler while Zend ran the identical code fine.
     * An explicit loop is correct; the array_fill repr bug is real and outlives
     * this file.
     *
     * @param string[]                            $shared
     * @param string[]                            $defOrder
     * @param array<string, int>                  $defSize
     * @param array<string, array<string, bool>>  $defRefs
     * @param array<string, bool>                 $internal
     * @return array<string, int> symbol => part index
     */
    private function assignParts(array $shared, array $defOrder, array $defSize,
                                 array $defRefs, array $internal, int $parts): array
    {
        /** @var array<string, string> symbol => its component's root */
        $compOf = [];
        /** @var array<string, int> component root => bytes */
        $compSize = [];
        $this->internalComponents($defOrder, $defSize, $defRefs, $internal, $compOf, $compSize);

        if ($this->stable) {
            return $this->assignStable($shared, $defSize, $defRefs, $compOf, $compSize, $parts);
        }
        $bySize = [];
        foreach ($shared as $s) { $bySize[$s] = $defSize[$s] ?? 0; }
        \arsort($bySize);
        $load = [];
        for ($q = 0; $q < $parts; $q = $q + 1) { $load[] = 0; }
        // Which parts already hold a component, as a bit per part. Parts are
        // capped at 64 (Main.php), so one integer says it — and a flat map of
        // ints avoids mutating a nested array per placement.
        /** @var array<string, int> */
        $have = [];
        /** @var array<string, int> */
        $assign = [];
        foreach ($bySize as $sym => $sz) {
            /** @var array<string, bool> */
            $comps = [];
            foreach ($defRefs[$sym] as $r => $_) {
                if (!isset($compOf[$r])) { continue; }
                $comps[$compOf[$r]] = true;
            }
            $best = 0;
            $bestLoad = -1;
            for ($p = 0; $p < $parts; $p = $p + 1) {
                $bit = 1 << $p;
                $add = 0;
                foreach ($comps as $c => $_) {
                    if ((($have[$c] ?? 0) & $bit) === 0) { $add = $add + ($compSize[$c] ?? 0); }
                }
                $cand = $load[$p] + $sz + $add;
                if ($bestLoad < 0 || $cand < $bestLoad) { $bestLoad = $cand; $best = $p; }
            }
            $assign[$sym] = $best;
            $load[$best] = $bestLoad;
            $bit = 1 << $best;
            foreach ($comps as $c => $_) { $have[$c] = ($have[$c] ?? 0) | $bit; }
        }
        return $assign;
    }

    /** Hash buckets the stable placement packs into parts. */
    private const STABLE_BUCKETS = 4096;

    /**
     * Placement that an edit barely moves, for the object cache, and that still
     * balances: a symbol's bucket is its name's hash, and the buckets, in index
     * order, are cut into runs of about equal weight. A body that grows shifts
     * the cuts by its growth, so only a bucket at a cut can change parts.
     *
     * Hashing straight to a part (`crc32 % parts`) ignored sizes and the internal
     * bodies a definition drags along: on symfony-demo two of 65 parts came out
     * at 99 and 127 MB against 34 on average, and clang's peak follows them.
     *
     * A bucket weighs its definitions plus each internal component they name,
     * once per bucket: the component is copied into every part that names it.
     *
     * @param string[]                            $shared
     * @param array<string, int>                  $defSize
     * @param array<string, array<string, bool>>  $defRefs
     * @param array<string, string>               $compOf
     * @param array<string, int>                  $compSize
     * @return array<string, int> symbol => part index
     */
    private function assignStable(array $shared, array $defSize, array $defRefs, array $compOf,
                                  array $compSize, int $parts): array
    {
        $nb = self::STABLE_BUCKETS;
        /** @var int[] $weight */
        $weight = [];
        for ($b = 0; $b < $nb; $b = $b + 1) { $weight[] = 0; }
        /** @var array<string, int> $bucketOf */
        $bucketOf = [];
        /** @var array<string, bool> $seen bucket|component already weighed */
        $seen = [];
        foreach ($shared as $s) {
            $b = (int)(\crc32($s) % $nb);
            $bucketOf[$s] = $b;
            $w = $defSize[$s] ?? 0;
            foreach ($defRefs[$s] ?? [] as $r => $_) {
                $c = $compOf[$r] ?? null;
                if ($c === null) { continue; }
                $k = (string)$b . '|' . $c;
                if (isset($seen[$k])) { continue; }
                $seen[$k] = true;
                $w = $w + ($compSize[$c] ?? 0);
            }
            $weight[$b] = $weight[$b] + $w;
        }
        $total = 0;
        foreach ($weight as $w) { $total = $total + $w; }
        /** @var int[] $partOf */
        $partOf = [];
        $p = 0;
        $cum = 0;
        for ($b = 0; $b < $nb; $b = $b + 1) {
            // Cut where the run passes its share; the bucket's midpoint decides which side it lands on.
            // At most one cut per bucket, so a heavy bucket leaves no part empty.
            if ($p < $parts - 1 && 2 * $cum + $weight[$b] > 2 * \intdiv($total * ($p + 1), $parts)) { $p = $p + 1; }
            $partOf[] = $p;
            $cum = $cum + $weight[$b];
        }
        /** @var array<string, int> */
        $assign = [];
        foreach ($shared as $s) { $assign[$s] = $partOf[$bucketOf[$s]]; }
        return $assign;
    }

    /**
     * Group the file-local definitions into components that cannot be split.
     *
     * An internal body may call another, so a part that takes one takes the
     * whole reachable set — {@see closePart} closes exactly that to a fixpoint.
     * Naming the component up front is what lets {@see assignParts} price a
     * placement: the unit that gets copied is the component, not the body.
     *
     * @param string[]                            $defOrder
     * @param array<string, int>                  $defSize
     * @param array<string, array<string, bool>>  $defRefs
     * @param array<string, bool>                 $internal
     * @param array<string, string>               $compOf   out: symbol => root
     * @param array<string, int>                  $compSize out: root => bytes
     */
    private function internalComponents(array $defOrder, array $defSize, array $defRefs,
                                        array $internal, array &$compOf,
                                        array &$compSize): void
    {
        /** @var array<string, string> */
        $parent = [];
        foreach ($defOrder as $s) {
            if (isset($internal[$s])) { $parent[$s] = $s; }
        }
        foreach ($defOrder as $s) {
            if (!isset($internal[$s])) { continue; }
            foreach ($defRefs[$s] as $r => $_) {
                if (!isset($parent[$r])) { continue; }
                $this->unite($parent, $s, $r);
            }
        }
        foreach ($defOrder as $s) {
            if (!isset($internal[$s])) { continue; }
            $r = $this->findRoot($parent, $s);
            $compOf[$s] = $r;
            $compSize[$r] = ($compSize[$r] ?? 0) + ($defSize[$s] ?? 0);
        }
    }

    /**
     * ⚠ BY REFERENCE. Handed the map by value, every union copies the whole
     * table and the split turns quadratic.
     *
     * @param array<string, string> $parent
     */
    private function unite(array &$parent, string $a, string $b): void
    {
        $ra = $this->findRoot($parent, $a);
        $rb = $this->findRoot($parent, $b);
        if ($ra === $rb) { return; }
        $parent[$rb] = $ra;
    }

    /** @param array<string, string> $parent */
    private function findRoot(array &$parent, string $x): string
    {
        while (isset($parent[$x]) && $parent[$x] !== $x) {
            $g = $parent[$parent[$x]] ?? $parent[$x];
            $parent[$x] = $g;
            $x = $g;
        }
        return $x;
    }

    /**
     * Decide a part's contents from the index alone — never from body text.
     *
     * @param string[]                            $defOrder
     * @param array<string, string>               $defHead
     * @param array<string, array<string, bool>>  $defRefs
     * @param array<string, int>                  $defSize
     * @param array<string, int>                  $assign
     * @param array<string, bool>                 $internal
     * @param string[]                            $globalOrder
     * @param array<string, string>               $globals
     * @param array<string, bool>                 $owned globals already defined by an earlier part
     */
    private function planPart(int $p, array $defOrder, array $defHead, array $defRefs,
                              array $defSize, array $assign, array $internal,
                              array $globalOrder, array $globals, array &$owned): PartPlan
    {
        $plan = $this->closePart($p, $defOrder, $defRefs, $defSize, $assign, $internal,
                                 $globalOrder, $globals, $owned);
        $this->renderPart($plan, $p, $defHead, $assign, $internal, $globalOrder,
                          $globals);
        return $plan;
    }

    /**
     * What a part must CONTAIN: its own definitions, the file-local closure they
     * reach, and the globals that closure names. Text is not built here — the
     * owner pass runs this for every part before any part is rendered.
     *
     * @param string[]                            $defOrder
     * @param array<string, array<string, bool>>  $defRefs
     * @param array<string, int>                  $defSize
     * @param array<string, int>                  $assign
     * @param array<string, bool>                 $internal
     * @param string[]                            $globalOrder
     * @param array<string, string>               $globals
     */
    private function closePart(int $p, array $defOrder, array $defRefs, array $defSize,
                               array $assign, array $internal, array $globalOrder,
                               array $globals, array &$owned): PartPlan
    {
        if ($p === 0) {
            $this->partMine = [];
            foreach ($defOrder as $s) {
                if (isset($internal[$s])) { continue; }
                $a = $assign[$s] ?? -1;
                if ($a >= 0) { $this->partMine[$a][] = $s; }
            }
        }
        /** @var string[] */
        $mine = $this->partMine[$p] ?? [];
        /** @var array<string, bool> */
        $refs = [];
        foreach ($mine as $s) {
            foreach ($defRefs[$s] as $r => $_) { $refs[$r] = true; }
        }
        // Small callees another part owns come along as `available_externally`
        // copies: inlinable here, never emitted here. A part boundary is an
        // INLINING boundary (8 plain parts made the compiler 69% slower) and
        // what the inliner takes across it is exactly the small bodies. One that
        // names a file-local definition stays out — it would drag that body's
        // copy into the part as well.
        /** @var array<string, bool> */
        $availSet = [];
        // Transitive: a copied body's own small foreign callees come along too
        // (depth-capped, per-part byte budget), so a call it inlines onward
        // does not turn back into a call across the boundary.
        $availBytes = 0;
        /** @var string[] */
        $frontier = $mine;
        for ($depth = 0; $depth < self::AVAIL_MAX_DEPTH && $frontier !== []; $depth++) {
            /** @var string[] */
            $next = [];
            foreach ($frontier as $s) {
                foreach ($defRefs[$s] as $r => $_) {
                    if (isset($availSet[$r]) || !isset($assign[$r]) || $assign[$r] === $p) { continue; }
                    $sz = $defSize[$r] ?? self::AVAIL_MAX_BYTES + 1;
                    if ($sz > self::AVAIL_MAX_BYTES) { continue; }
                    if ($depth > 0 && $availBytes + $sz > self::AVAIL_PART_BYTES) { continue; }
                    if ($this->namesInternal($defRefs[$r], $internal)) { continue; }
                    $availSet[$r] = true;
                    if ($depth > 0) { $availBytes += $sz; }
                    $next[] = $r;
                }
            }
            $frontier = $next;
        }
        /** @var string[] */
        $avail = [];
        /** @var int[] */
        $availPos = [];
        foreach ($availSet as $s => $_) { $availPos[] = $this->defPos[$s]; }
        \sort($availPos);
        foreach ($availPos as $i) {
            $s = $defOrder[$i];
            $avail[] = $s;
            foreach ($defRefs[$s] as $r => $_) { $refs[$r] = true; }
        }
        // Every file-local definition reachable from here, to a fixpoint.
        //
        // ⚠ This closure and the GLOBAL one below feed each other, so neither
        // can run once: a global's initializer names definitions (a dispatch
        // TABLE's rows are `ptr @helper`), and a definition names globals. With
        // the internal closure running first, an internal body named ONLY by a
        // global initializer was never copied — part 22 of symfony-demo T5 took
        // the 2072-row `@.dynf.rows.*` table and clang refused it with `use of
        // undefined value @manticore___mc_dyn_method0___bits_int_`. The table is
        // the only reference such a helper has. Alternate to a JOINT fixpoint.
        /** @var array<string, bool> */
        $haveInternal = [];
        // Part 0 carries every non-discardable global, so seed the closure with
        // them: one may be named only by another global's initializer
        // (`@E__fqns` -> `@E__fqn_0`) and would otherwise be emitted without it.
        if ($p === 0) {
            foreach ($globalOrder as $g) {
                if ($this->gcls[$g] === 'strong') { $refs[$g] = true; }
            }
        }
        // ⚠ The closure follows a global's INITIALIZER only where this part
        // will emit one. A part that merely declares `@desc = external` does
        // not name what @desc's initializer named, so it needs none of it —
        // and that is the whole reason a class descriptor used to drag its
        // entire reflection table into all 24 parts: 163 595 `@.rmeta.*`
        // globals arrived as 2 086 013 declarations even AFTER one part had
        // been made their owner. Claim on first reach, in part order.
        /** @var array<string, bool> */
        $needG = [];
        /** @var array<string, bool> */
        $ownG = [];
        // Worklist form of the joint fixpoint. It visits names in exactly the
        // order the old defOrder/globalOrder sweeps did (a name reached ahead of
        // the sweep cursor is taken in the same sweep, one behind it in the next),
        // because `$refs` insertion order decides the order of the `declare`s.
        /** @var int[] */
        $pendI = [];
        /** @var int[] */
        $pendG = [];
        foreach ($refs as $r => $_) {
            $r = (string)$r;
            if (isset($internal[$r])) { $pendI[] = $this->defPos[$r]; $haveInternal[$r] = false; }
            elseif (isset($this->gcls[$r])) { $pendG[] = $this->gpos[$r]; $needG[$r] = false; }
        }
        while ($pendI !== [] || $pendG !== []) {
            while ($pendI !== []) {
                $cur = $pendI;
                $pendI = [];
                \sort($cur);
                $idx = 0;
                while ($idx < \count($cur)) {
                    $i = $cur[$idx];
                    $idx = $idx + 1;
                    $sym = $defOrder[$i];
                    $haveInternal[$sym] = true;
                    foreach ($defRefs[$sym] as $r => $_) {
                        if (isset($refs[$r])) { continue; }
                        $refs[$r] = true;
                        $r = (string)$r;
                        if (isset($internal[$r])) {
                            $haveInternal[$r] = false;
                            $j = $this->defPos[$r];
                            if ($j > $i) { $this->sortedInsert($cur, $idx, $j); } else { $pendI[] = $j; }
                        } elseif (isset($this->gcls[$r])) {
                            $needG[$r] = false;
                            $pendG[] = $this->gpos[$r];
                        }
                    }
                }
            }
            while ($pendG !== []) {
                $cur = $pendG;
                $pendG = [];
                \sort($cur);
                $idx = 0;
                while ($idx < \count($cur)) {
                    $i = $cur[$idx];
                    $idx = $idx + 1;
                    $g = $globalOrder[$i];
                    $needG[$g] = true;
                    $cls = $this->gcls[$g];
                    if ($cls === 'coalesced') {
                        if (isset($owned[$g])) { continue; }
                        $owned[$g] = true;
                        $ownG[$g] = true;
                    } elseif ($cls === 'strong' && $p !== 0) {
                        continue;
                    }
                    foreach ($this->refsOf('global:' . $g, $globals[$g]) as $r => $_) {
                        if (isset($refs[$r])) { continue; }
                        $refs[$r] = true;
                        $r = (string)$r;
                        if (isset($internal[$r])) {
                            $haveInternal[$r] = false;
                            $pendI[] = $this->defPos[$r];
                        } elseif (isset($this->gcls[$r])) {
                            $needG[$r] = false;
                            $j = $this->gpos[$r];
                            if ($j > $i) { $this->sortedInsert($cur, $idx, $j); } else { $pendG[] = $j; }
                        }
                    }
                }
            }
        }
        /** @var int[] */
        $hp = [];
        foreach ($haveInternal as $s => $_) { $hp[] = $this->defPos[$s]; }
        \sort($hp);
        foreach ($hp as $i) { $mine[] = $defOrder[$i]; }
        $plan = new PartPlan();
        $plan->mine = $mine;
        $plan->avail = $avail;
        $plan->refs = $refs;
        $plan->needG = $needG;
        $plan->ownG = $ownG;
        return $plan;
    }

    /**
     * Turn a closed part into the text that precedes its bodies.
     *
     * @param array<string, string> $defHead
     * @param array<string, int>    $assign
     * @param array<string, bool>   $internal
     * @param string[]              $globalOrder
     * @param array<string, string> $globals
     */
    private function renderPart(PartPlan $plan, int $p, array $defHead, array $assign,
                                array $internal, array $globalOrder, array $globals): void
    {
        $needG = $plan->needG;
        $refs = $plan->refs;
        $gtext = '';
        foreach ($globalOrder as $g) {
            $gl = $globals[$g];
            $cls = $this->gcls[$g];
            if ($cls === 'local') {
                if (!isset($needG[$g])) { continue; }
                $gtext = $gtext . $this->promoteGlobal($gl) . "\n";
                continue;
            }
            if ($cls === 'coalesced') {
                if (!isset($needG[$g])) { continue; }
                // ⚠ The owner's copy is emitted `weak_odr`, not `linkonce_odr`:
                // linkonce is DISCARDABLE IF UNUSED, and the one part that
                // defines it may be the part where -O2 proves its only use dead
                // — every other part would then hold an unresolved external.
                // weak_odr is the same coalescing with no discard.
                if (isset($plan->ownG[$g])) {
                    $gtext = $gtext . $this->pinGlobal($this->promoteGlobal($gl)) . "\n";
                    continue;
                }
                $gtext = $gtext . $this->externGlobal($gl) . "\n";
                continue;
            }
            if ($p === 0) {
                $gtext = $gtext . (isset($this->inputPins[$g]) ? $this->pinGlobal($gl) : $gl) . "\n";
                continue;
            }
            if (!isset($needG[$g])) { continue; }
            $gtext = $gtext . $this->externGlobal($gl) . "\n";
        }
        // Declares for what this part calls but another part defines.
        /** @var array<string, bool> */
        $availSet = [];
        foreach ($plan->avail as $s) { $availSet[$s] = true; }
        $dtext = '';
        foreach ($refs as $r => $_) {
            if (!isset($defHead[$r]) || isset($internal[$r]) || isset($availSet[$r])) { continue; }
            if (($assign[$r] ?? -1) === $p) { continue; }
            $d = $this->declareFromDefine($defHead[$r]);
            if ($d !== '') { $dtext = $dtext . $d . "\n"; }
        }
        /** @var string[] */
        $keep = [];
        foreach ($plan->mine as $s) {
            if (\str_contains($defHead[$s], ' linkonce_odr ')) { $keep[] = $s; }
        }
        $usedText = '';
        if ($keep !== []) {
            $refsList = [];
            foreach ($keep as $s) { $refsList[] = 'ptr ' . $this->symRef($s); }
            $usedText = '@llvm.compiler.used = appending global [' . (string)\count($keep)
                . ' x ptr] [' . \implode(', ', $refsList) . '], section "llvm.metadata"' . "\n";
        }
        $plan->gtext = $gtext;
        $plan->dtext = $dtext;
        $plan->usedText = $usedText;
    }

    /** A coalesced global's definition, made non-discardable for its owning part. */
    private function notePins(string $line): void
    {
        if (\preg_match_all('/ptr @("[^"]+"|[A-Za-z0-9_.$\\\\-]+)/', $line, $m) > 0) {
            foreach ($m[1] as $raw) { $this->inputPins[\trim((string)$raw, '"')] = true; }
        }
    }

    private function pinGlobal(string $line): string
    {
        $eq = \strpos($line, ' = ');
        if ($eq === false) { return $line; }
        $headPart = \substr($line, 0, $eq + 3);
        $rest = \substr($line, $eq + 3);
        if (\str_starts_with($rest, 'linkonce_odr ')) {
            return $headPart . 'weak_odr ' . \substr($rest, 13);
        }
        if (\str_starts_with($rest, 'linkonce ')) {
            return $headPart . 'weak ' . \substr($rest, 9);
        }
        return $line;
    }

    /**
     * Split a STAGED module file into part files without ever holding the module.
     *
     * Same partitioning as {@see run} — it shares planPart/assignParts, so the
     * internal-duplication fixpoint, the global linkage classes and the
     * linkonce_odr pinning are decided by one owner, not two. The difference is
     * only where a definition's bytes come from: `run` concatenates strings,
     * this copies byte ranges straight from the source file.
     *
     * That matters because streaming exists precisely for modules that must not
     * be resident: `run` would need the text, an exploded line array AND a
     * symbol=>text map at once. Here the resident cost is the index — one header
     * line and one reference set per definition.
     *
     * @return string[] the written part paths, or [] on failure
     */
    public function runFile(string $llPath, int $parts, string $outBase): array
    {
        if ($parts < 2) { return []; }
        $this->refsCache = [];
        $this->inputPins = [];
        $in = \Manticore\fopen($llPath, 'rb');
        $cap = 1048576;
        $buf = \Manticore\malloc($cap);

        /** @var string[] */
        $header = [];
        /** @var array<string, string> */
        $globals = [];
        /** @var string[] */
        $globalOrder = [];
        /** @var string[] */
        $declares = [];
        /** @var string[] */
        $defOrder = [];
        /** @var array<string, string> */
        $defHead = [];
        /** @var array<string, array<string, bool>> */
        $defRefs = [];
        /** @var array<string, int> */
        $defOff = [];
        /** @var array<string, int> */
        $defSize = [];

        $pos = 0;
        $cur = '';
        $start = 0;
        /** @var array<string, bool> */
        $refs = [];
        // The body of the define being read, scanned for `@symbol` once at its closing brace.
        $body = '';
        while (true) {
            // fgets stops at the BUFFER as readily as at the newline, and this
            // module has global initialisers whose single line runs to megabytes
            // (a per-enum case table, a class descriptor array). Treating each
            // chunk as a line put the tail of one on a line of its own, where it
            // parses as nothing: `p0.ll:4:1: error: expected top-level entity`,
            // and the whole split fell back to the serial path that cannot
            // compile the module at all. Read until the newline actually arrives.
            $line = '';
            while (true) {
                $p = \Manticore\fgets($buf, $cap, $in);
                if ($p === 0) { break; }
                $chunk = \cstr_to_str($p);
                $line .= $chunk;
                if (\substr($chunk, -1) === "\n") { break; }
            }
            if ($line === '') { break; }
            $n = \strlen($line);
            if ($cur !== '') {
                $body .= $line;
                $pos = $pos + $n;
                if ($line[0] === '}' && \rtrim($line) === '}') {
                    $this->collectRefsBulk($body, $refs);
                    $body = '';
                    $defOff[$cur] = $start;
                    $defSize[$cur] = $pos - $start;
                    $defRefs[$cur] = $refs;
                    $defOrder[] = $cur;
                    $refs = [];
                    $cur = '';
                }
                continue;
            }
            // The stored text must match `run`'s: lines joined by "\n" with the
            // closing brace's newline included, since run appends one itself.
            $bare = \rtrim($line, "\n");
            if (\strlen($bare) > 7 && \substr($bare, 0, 7) === 'define ') {
                $sym = $this->defineSymbol($bare);
                if ($sym === '') { $header[] = $bare; $pos = $pos + $n; continue; }
                $cur = $sym;
                $start = $pos;
                $defHead[$sym] = $bare;
                $refs = [];
                $this->collectRefs($bare, $refs);
                $pos = $pos + $n;
                continue;
            }
            $pos = $pos + $n;
            if ($bare !== '' && $bare[0] === '@') {
                $sym = $this->globalName($bare);
                if ($sym === '') { $header[] = $bare; continue; }
                if ($sym === 'llvm.compiler.used') { $this->notePins($bare); continue; }
                $globals[$sym] = $bare;
                $globalOrder[] = $sym;
                continue;
            }
            if (\str_starts_with($bare, 'declare ')) { $declares[] = $bare; continue; }
            $header[] = $bare;
        }

        // A stable split is a function of the module's CONTENT, not of the order it was
        // written in: parallel emission writes the same definitions in another order, and
        // an object cache keyed by part text should not care either.
        if ($this->stable) {
            \sort($defOrder, SORT_STRING);
            \sort($globalOrder, SORT_STRING);
            \sort($declares, SORT_STRING);
        }
        $promoted = $this->promoteLarge($defOrder, $defHead, $defSize, $defRefs);
        /** @var array<string, bool> */
        $internal = [];
        /** @var string[] */
        $shared = [];
        foreach ($defOrder as $s) {
            if (\str_contains($defHead[$s], ' internal ') || \str_contains($defHead[$s], ' private ')) {
                $internal[$s] = true;
                continue;
            }
            $shared[] = $s;
        }
        $this->sharedDefs = \count($shared);
        $this->internalDefs = \count($internal);
        $this->indexModule($defOrder, $globalOrder, $globals);
        $assign = $this->assignParts($shared, $defOrder, $defSize, $defRefs, $internal, $parts);
        // Threaded through the part loop: the first part that reaches a
        // coalesced global defines it, every later part declares it.
        /** @var array<string, bool> */
        $owned = [];
        $lineTable = $this->lineTableGlobals($globalOrder, $owned);

        $headerText = \implode("\n", $header);
        $declText = \implode("\n", $declares);
        /** @var string[] */
        $out = [];
        for ($p = 0; $p < $parts; $p = $p + 1) {
            $partHeader = $headerText;
            if ($p !== 0) {
                $partHeader = (string)\preg_replace('/^module asm .*\n?/m', '', $partHeader);
            }
            $plan = $this->planPart($p, $defOrder, $defHead, $defRefs, $defSize, $assign,
                                    $internal, $globalOrder, $globals, $owned);
            $path = $outBase . '.p' . (string)$p . '.ll';
            if (!\Manticore\write_file($path,
                    $partHeader . "\n" . $declText . "\n" . $plan->gtext . $plan->dtext . $plan->usedText)) {
                \Manticore\free($buf);
                \Manticore\fclose($in);
                return [];
            }
            $o = \Manticore\fopen($path, 'ab');
            foreach ($plan->mine as $s) {
                if (isset($promoted[$s])) {
                    $h = $defHead[$s] . "\n";
                    $hl = $promoted[$s] + 1;
                    if (\Manticore\fwrite($h, 1, \strlen($h), $o) !== \strlen($h)
                        || !$this->copyRange($in, $o, $buf, $cap, $defOff[$s] + $hl, $defSize[$s] - $hl)) {
                        \Manticore\fclose($o);
                        \Manticore\free($buf);
                        \Manticore\fclose($in);
                        return [];
                    }
                    continue;
                }
                if (!$this->copyRange($in, $o, $buf, $cap, $defOff[$s], $defSize[$s])) {
                    \Manticore\fclose($o);
                    \Manticore\free($buf);
                    \Manticore\fclose($in);
                    return [];
                }
            }
            foreach ($plan->avail as $s) {
                $h = $this->availHead($defHead[$s]) . "\n";
                $hl = \strlen($defHead[$s]) + 1;
                if (\Manticore\fwrite($h, 1, \strlen($h), $o) !== \strlen($h)
                    || !$this->copyRange($in, $o, $buf, $cap, $defOff[$s] + $hl, $defSize[$s] - $hl)) {
                    \Manticore\fclose($o);
                    \Manticore\free($buf);
                    \Manticore\fclose($in);
                    return [];
                }
            }
            \Manticore\fclose($o);
            $out[] = $path;
        }
        if ($lineTable !== []) {
            $path = $outBase . '.p' . (string)$parts . '.ll';
            if (!\Manticore\write_file($path, $this->lineTablePart($headerText, $lineTable, $globals))) {
                \Manticore\free($buf);
                \Manticore\fclose($in);
                return [];
            }
            $out[] = $path;
        }
        \Manticore\free($buf);
        \Manticore\fclose($in);
        return $out;
    }

    /** Copy $len bytes at $off from $in to $o through the shared buffer. */
    private function copyRange(\Ffi\Ptr $in, \Ffi\Ptr $o, \Ffi\Ptr $buf, int $cap, int $off, int $len): bool
    {
        if (\Manticore\fseek($in, $off, 0) !== 0) { return false; }
        $left = $len;
        while ($left > 0) {
            $want = $left < $cap ? $left : $cap;
            $got = \Manticore\fread($buf, 1, $want, $in);
            if ($got <= 0) { return false; }
            if (\Manticore\fwrite_buf($buf, 1, $got, $o) !== $got) { return false; }
            $left = $left - $got;
        }
        return true;
    }

    public function head(string $def): string
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

    public function defineSymbol(string $line): string
    {
        $at = \strpos($line, '@');
        if ($at === false) { return ''; }
        return $this->readSymbol($line, $at + 1);
    }

    public function globalName(string $line): string
    {
        $eq = \strpos($line, ' = ');
        if ($eq === false) { return ''; }
        return $this->readSymbol($line, 1);
    }

    /**
     * 'local' (file-local), 'coalesced' (discardable if unused) or 'strong'.
     *
     * A big file-local table is coalesced too. Copied into every part that names it,
     * three dispatch tables alone cost 3.2 MB per part. A small one stays local: the
     * optimizer reads a short string's bytes, which a declaration would hide from it.
     */
    private function globalClass(string $line): string
    {
        $eq = \strpos($line, ' = ');
        if ($eq === false) { return 'strong'; }
        $rest = \substr($line, $eq + 3);
        if (\str_starts_with($rest, 'internal ') || \str_starts_with($rest, 'private ')) {
            return \strlen($line) >= self::SHARED_LOCAL_BYTES ? 'coalesced' : 'local';
        }
        if (\str_starts_with($rest, 'linkonce_odr ') || \str_starts_with($rest, 'weak_odr ')
            || \str_starts_with($rest, 'linkonce ') || \str_starts_with($rest, 'weak ')) { return 'coalesced'; }
        return 'strong';
    }

    public function promoteGlobal(string $line): string
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
    public function externGlobal(string $line): string
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

    /** IR bytes up to which another part's callee is copied in `available_externally`. */
    private const AVAIL_MAX_BYTES = 2048;

    /** A file-local body at least this big, with two or more callers, is promoted to one shared definition. */
    private const PROMOTE_MIN_BYTES = 32768;

    /** Transitive depth of the copy closure. */
    private const AVAIL_MAX_DEPTH = 4;

    /** IR bytes of TRANSITIVE available_externally copies (depth >= 1) one part may take; first-hop copies stay unbounded as before. */
    private const AVAIL_PART_BYTES = 262144;

    /**
     * @param array<string, bool> $refs
     * @param array<string, bool> $internal
     */
    private function namesInternal(array $refs, array $internal): bool
    {
        foreach ($refs as $r => $_) {
            if (isset($internal[$r])) { return true; }
        }
        return false;
    }

    /** A definition header relinked `available_externally` — same signature and attributes. */
    public function availHead(string $headLine): string
    {
        $rest = \substr($headLine, 7);
        $keys = ['linkonce_odr ', 'weak_odr ', 'external ', 'dso_local ', 'weak ', 'linkonce '];
        $again = true;
        while ($again) {
            $again = false;
            foreach ($keys as $k) {
                if (\str_starts_with($rest, $k)) {
                    $rest = \substr($rest, \strlen($k));
                    $again = true;
                }
            }
        }
        return 'define available_externally ' . $rest;
    }

    /** A `declare` taken from the definition's own header — never invented. */
    public function declareFromDefine(string $def): string
    {
        $headLine = $this->head($def);
        $paren = \strpos($headLine, '(');
        if ($paren === false) { return ''; }
        // The parameter list ends at the paren matching the first one. The LAST
        // paren is not it: a closure header trails `prefix [..] [.. sub (i64
        // ptrtoint (..))]` data that is part of the definition, not the signature.
        $close = false;
        $depth = 0;
        $hn = \strlen($headLine);
        for ($i = $paren; $i < $hn; $i++) {
            $c = $headLine[$i];
            if ($c === '(') { $depth++; }
            elseif ($c === ')') {
                $depth--;
                if ($depth === 0) { $close = $i; break; }
            }
        }
        if ($close === false) { return ''; }
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

    /**
     * {@see collectRefs} over a whole body: one PCRE pass instead of a per-byte symbol reader
     * per `@`. A quoted symbol stays on its line, as it does line by line.
     *
     * @param array<string, bool> $into
     */
    public function collectRefsBulk(string $text, array &$into): void
    {
        if (\strpos($text, '@') === false) { return; }
        \preg_match_all('/@("[^"\n]*"|[A-Za-z0-9_.$\\\\-]+)/', $text, $m);
        /** @var array<string, bool> $uniq */
        $uniq = [];
        foreach ($m[1] as $raw) { $uniq[$raw] = true; }
        foreach ($uniq as $raw => $unused) {
            $sym = (string)$raw;
            if ($sym !== '' && $sym[0] === '"') { $sym = \substr($sym, 1, \strlen($sym) - 2); }
            if ($sym !== '') { $into[$sym] = true; }
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
