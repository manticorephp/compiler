<?php

declare(strict_types=1);

namespace Compile\Mir;

/**
 * Cuts the large shareable bodies out of an executable's module, one part per
 * body, so the object cache compiles each body VARIANT once and every later
 * program that emits the same text reuses the object.
 *
 * Part 0 is the program part: the module with each cut body replaced by its
 * `declare`. Every other part holds one body and is written to depend on
 * nothing but that body's own text — the object cache keys a part by its
 * bytes, so a part that carried the program's header, its declares or its
 * global ownership would never be the same twice. A body part therefore holds:
 *
 *  - only the declarations the body names, sorted;
 *  - a shared global as `external`, a file-local `unnamed_addr` constant as a
 *    copy (its address means nothing), any other file-local global or function
 *    PROMOTED to `linkonce_odr` in the program part and named `external` here;
 *  - `available_externally` copies of small callees, so the body still inlines
 *    what it inlined in one module — never a whole-program helper (`_mapp`), a
 *    `$mono$` clone or a closure, whose text is the program's own;
 *  - the body pinned by its own `@llvm.compiler.used`.
 *
 * The program part pins every symbol a body part needs from it.
 *
 * Executables only: the promotions give file-local symbols a module-wide name,
 * and only an executable's module is alone in its link (a library's module
 * shares the link with the app and keeps one unit).
 */
final class ShareSplit
{
    /** Bodies at least this many IR bytes get a part of their own. */
    public const SHARE_MIN_BYTES = 8192;

    /** IR bytes up to which a callee is copied `available_externally`. */
    private const AVAIL_MAX_BYTES = 512;

    /** Transitive depth of the copies. */
    private const AVAIL_MAX_DEPTH = 2;

    /** The size cut, {@see SHARE_MIN_BYTES} unless tuned. */
    public int $minBytes = self::SHARE_MIN_BYTES;

    /** {@see AVAIL_MAX_BYTES} unless tuned. */
    public int $availMaxBytes = self::AVAIL_MAX_BYTES;

    /** Bodies cut out by the last {@see run}. */
    public int $bodies = 0;

    /**
     * @return string[] part texts; [0] is the program part. One element (the
     *                  module unchanged) when nothing is worth cutting.
     */
    public function run(string $ir): array
    {
        $this->bodies = 0;
        $sm = new SplitModule();
        $lines = \explode("\n", $ir);
        /** @var string[] */
        $header = [];
        /** @var array<string, string> */
        $globals = [];
        /** @var array<string, string> */
        $defs = [];
        /** @var string[] */
        $defOrder = [];
        /** @var array<string, string> */
        $declares = [];
        $cur = '';
        $buf = '';
        foreach ($lines as $l) {
            if ($cur !== '') {
                $buf = $buf . "\n" . $l;
                if (\rtrim($l) === '}') {
                    $defs[$cur] = $buf;
                    $defOrder[] = $cur;
                    $cur = '';
                }
                continue;
            }
            if (\str_starts_with($l, 'define ')) {
                $sym = $sm->defineSymbol($l);
                if ($sym === '') { return [$ir]; }
                $cur = $sym;
                $buf = $l;
                continue;
            }
            if ($l !== '' && $l[0] === '@') {
                $g = $sm->globalName($l);
                if ($g === '') { return [$ir]; }
                // Somebody else already pins symbols: two appending arrays of the
                // same name would not merge here, so this module stays whole.
                if ($g === 'llvm.compiler.used' || $g === 'llvm.used') { return [$ir]; }
                $globals[$g] = $l;
                continue;
            }
            if (\str_starts_with($l, 'declare ')) {
                $d = $sm->defineSymbol($l);
                if ($d !== '') { $declares[$d] = $l; }
                continue;
            }
            if (\str_starts_with($l, 'module asm')) { return [$ir]; }
            // Blank and comment lines sit between sections in a number that
            // depends on the program; a body part must not inherit them.
            $tl = \trim($l);
            if ($tl === '' || $tl[0] === ';') { continue; }
            $header[] = $l;
        }

        /** @var array<string, string> */
        $head = [];
        /** @var array<string, array<string, bool>> */
        $refs = [];
        /** @var array<string, bool> file-local functions */
        $localFn = [];
        foreach ($defOrder as $s) {
            $h = $sm->head($defs[$s]);
            $head[$s] = $h;
            $r = [];
            $sm->collectRefsBulk($defs[$s], $r);
            unset($r[$s]);
            $refs[$s] = $r;
            if (\str_contains($h, ' internal ') || \str_contains($h, ' private ')) { $localFn[$s] = true; }
        }

        /** @var array<string, bool> */
        $cut = [];
        foreach ($defOrder as $s) {
            if (\strlen($defs[$s]) >= $this->minBytes && \str_starts_with($head[$s], 'define linkonce_odr ')
                && !\str_ends_with($s, '_mapp')) {
                $cut[$s] = true;
            }
        }
        if ($cut === []) { return [$ir]; }

        /** @var array<string, bool> file-local symbols the program part must promote */
        $promote = [];
        /** @var array<string, bool> symbols the program part must keep for a body part */
        $pin = [];
        /** @var array<string, string> */
        $parts = [];
        foreach ($defOrder as $s) {
            if (!isset($cut[$s])) { continue; }
            $parts[$s] = $this->bodyPart($s, $sm, $header, $globals, $defs, $head, $refs, $declares,
                                         $localFn, $cut, $promote, $pin);
        }
        $this->bodies = \count($parts);

        $prog = [];
        foreach ($header as $l) { $prog[] = $l; }
        foreach ($globals as $g => $l) {
            $prog[] = isset($promote[$g]) ? $sm->promoteGlobal($l) : $l;
        }
        foreach ($declares as $l) { $prog[] = $l; }
        foreach ($defOrder as $s) {
            if (isset($cut[$s])) {
                $prog[] = $sm->declareFromDefine($defs[$s]);
                continue;
            }
            if (isset($promote[$s])) {
                $prog[] = $this->promoteFn($head[$s]) . \substr($defs[$s], \strlen($head[$s]));
                continue;
            }
            $prog[] = $defs[$s];
        }
        /** @var string[] */
        $pins = [];
        foreach ($pin as $p => $_) {
            if (isset($cut[$p]) || isset($declares[$p])) { continue; }
            if (isset($defs[$p]) || (isset($globals[$p]) && !\str_contains($globals[$p], ' = external '))) {
                $pins[] = 'ptr ' . $this->symRef($p);
            }
        }
        \sort($pins);
        if ($pins !== []) {
            $prog[] = '@llvm.compiler.used = appending global [' . (string)\count($pins) . ' x ptr] ['
                . \implode(', ', $pins) . '], section "llvm.metadata"';
        }
        $out = [\implode("\n", $prog) . "\n"];
        \ksort($parts, SORT_STRING);
        foreach ($parts as $text) { $out[] = $text; }
        return $out;
    }

    /**
     * @param string[] $header
     * @param array<string, string> $globals
     * @param array<string, string> $defs
     * @param array<string, string> $head
     * @param array<string, array<string, bool>> $refs
     * @param array<string, string> $declares
     * @param array<string, bool> $localFn
     * @param array<string, bool> $cut
     * @param array<string, bool> $promote
     * @param array<string, bool> $pin
     */
    private function bodyPart(string $s, SplitModule $sm, array $header, array $globals, array $defs,
                              array $head, array $refs, array $declares, array $localFn, array $cut,
                              array &$promote, array &$pin): string
    {
        /** @var array<string, string> sorted later: symbol => line */
        $decl = [];
        /** @var array<string, string> */
        $glob = [];
        /** @var array<string, string> */
        $avail = [];
        /** @var array<int, array{string, int}> */
        $work = [];
        foreach ($refs[$s] as $r => $_) { $work[] = [$r, 0]; }
        /** @var array<string, bool> */
        $seen = [$s => true];
        $i = 0;
        while ($i < \count($work)) {
            $r = $work[$i][0];
            $depth = $work[$i][1];
            $i = $i + 1;
            if (isset($seen[$r])) { continue; }
            $seen[$r] = true;
            if (isset($defs[$r])) {
                $pin[$r] = true;
                if (!isset($cut[$r]) && !isset($localFn[$r]) && $depth < self::AVAIL_MAX_DEPTH
                    && \strlen($defs[$r]) <= $this->availMaxBytes && $this->stableName($r)
                    && !\str_contains($head[$r], ' prefix ')) {
                    $avail[$r] = $sm->availHead($head[$r]) . \substr($defs[$r], \strlen($head[$r]));
                    foreach ($refs[$r] as $rr => $_) { $work[] = [$rr, $depth + 1]; }
                    continue;
                }
                if (isset($localFn[$r])) { $promote[$r] = true; }
                $decl[$r] = $sm->declareFromDefine($defs[$r]);
                continue;
            }
            if (isset($declares[$r])) {
                $decl[$r] = $declares[$r];
                continue;
            }
            if (isset($globals[$r])) {
                $line = $globals[$r];
                if ($this->copyable($line)) {
                    $glob[$r] = $line;
                    $gr = [];
                    $sm->collectRefsBulk(\substr($line, \strlen($r) + 1), $gr);
                    foreach ($gr as $rr => $_) { $work[] = [$rr, $depth]; }
                    continue;
                }
                if ($this->fileLocal($line)) { $promote[$r] = true; }
                $pin[$r] = true;
                $glob[$r] = $sm->externGlobal($line);
            }
        }
        \ksort($decl, SORT_STRING);
        \ksort($glob, SORT_STRING);
        \ksort($avail, SORT_STRING);
        $t = [];
        foreach ($header as $l) { $t[] = $l; }
        foreach ($decl as $l) { $t[] = $l; }
        foreach ($glob as $l) { $t[] = $l; }
        $t[] = '@llvm.compiler.used = appending global [1 x ptr] [ptr ' . $this->symRef($s) . '], section "llvm.metadata"';
        foreach ($avail as $b) { $t[] = $b; }
        $t[] = $defs[$s];
        return \implode("\n", $t) . "\n";
    }

    /** Whether a callee's text can be the same in every program (see the class doc). */
    private function stableName(string $sym): bool
    {
        return !\str_ends_with($sym, '_mapp') && !\str_contains($sym, '$mono$')
            && !\str_contains($sym, '__closure_');
    }

    /** A file-local constant whose address nobody observes: a copy is the same value. */
    private function copyable(string $line): bool
    {
        return $this->fileLocal($line) && \str_contains($line, ' unnamed_addr constant ');
    }

    private function fileLocal(string $line): bool
    {
        $eq = \strpos($line, ' = ');
        if ($eq === false) { return false; }
        $rest = \substr($line, $eq + 3);
        return \str_starts_with($rest, 'internal ') || \str_starts_with($rest, 'private ');
    }

    private function promoteFn(string $headLine): string
    {
        foreach ([' internal ', ' private '] as $k) {
            $p = \strpos($headLine, $k);
            if ($p !== false) {
                return \substr($headLine, 0, $p) . ' linkonce_odr ' . \substr($headLine, $p + \strlen($k));
            }
        }
        return $headLine;
    }

    private function symRef(string $sym): string
    {
        return \preg_match('/^[A-Za-z0-9_.$\\\\-]+$/', $sym) === 1 ? '@' . $sym : '@"' . $sym . '"';
    }
}
