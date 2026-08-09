<?php
/**
 * report.php — turn a host profiler's text output into a table of PHP-level
 * names, aggregated by function.
 *
 *   php tools/prof/report.php sample <sample.txt> [top]
 *   php tools/prof/report.php malloc <malloc_history.txt> [top]
 *   php tools/prof/report.php heaptrack <heaptrack_print.txt> [top]
 *   php tools/prof/report.php phase <stats.txt> <elapsed-ms>    # which pass ran then
 *   php tools/prof/report.php callers <sample.txt> <symbol>    # who reaches <symbol>
 *
 * Every front-end feeds the SAME back-end: demangle → fold → render. The
 * binary carries no DWARF (`-g` is never passed, Main.php:526), so the symbol
 * table is the whole truth available and FUNCTION is the finest granularity
 * this tool can ever reach.
 */

$mode = $argv[1] ?? '';
$path = $argv[2] ?? '';
$top = isset($argv[3]) ? (int)$argv[3] : 40;
if ($mode === '' || $path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: report.php <sample|malloc|heaptrack> <file> [top]\n");
    exit(2);
}
$text = (string)file_get_contents($path);

/**
 * `_manticore_Compile_Mir_Passes_InferTypes__inferFn` -> `Compile\Mir\Passes\InferTypes::inferFn`.
 *
 * The forward mangling (EmitLlvm::mangle, EmitLlvmModule.php:877) maps `\` to
 * `_` and joins a method as `Class__method`, which is NOT injective — this is a
 * best-effort READING of the symbol, deliberately conservative:
 *   - `____` splits first (a magic method: `Foo_Bar____construct`),
 *     otherwise the LAST `__` separates class from method;
 *   - inside the class part, `_` becomes `\` only before an uppercase letter,
 *     so `Compile_Mir_Passes_X` re-namespaces while the plain function
 *     `collect_php_source_files` is left exactly as it was written.
 * A C symbol (no `manticore_` prefix) is returned untouched and marked.
 */
function prof_demangle(string $sym): string
{
    $s = ltrim($sym, '_');
    if (!str_starts_with($s, 'manticore_')) { return $sym; }
    $s = substr($s, 10);

    $class = '';
    $method = '';
    $q = strpos($s, '____');
    if ($q !== false) {
        $class = substr($s, 0, $q);
        $method = '__' . substr($s, $q + 4);
    } else {
        $q = strrpos($s, '__');
        if ($q !== false && $q > 0) {
            $class = substr($s, 0, $q);
            $method = substr($s, $q + 2);
        }
    }
    if ($class === '') { return prof_unmangle_ns($s); }
    return prof_unmangle_ns($class) . '::' . $method;
}

function prof_unmangle_ns(string $s): string
{
    $out = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '_' && $i + 1 < $n && ctype_upper($s[$i + 1]) && $i > 0) {
            $out .= '\\';
            continue;
        }
        $out .= $c;
    }
    return $out;
}

/** Frames that are plumbing, never an answer: the allocator wrappers and libc. */
function prof_is_noise(string $sym): bool
{
    $s = ltrim($sym, '_');
    foreach ([
        'mir_alloc', 'mir_realloc', 'mir_str_alloc', 'mir_array_alloc', 'mir_pool_',
        'mir_arena_', 'mir_str_reclaim', 'mir_rc_', 'malloc', 'calloc', 'realloc',
        'szone_', 'nanov2_', 'default_zone_', 'malloc_zone_',
    ] as $p) {
        if (str_starts_with($s, $p)) { return true; }
    }
    return false;
}

/**
 * macOS `sample`'s "Sort by top of stack" section is a SELF-time histogram —
 * one line per symbol, the count is samples with that symbol on top:
 *
 *     manticore_Compile_Lexer__scanOne  (in manticore)        1234
 *
 * @return array<string,int>
 */
function prof_parse_sample(string $text): array
{
    $lines = explode("\n", $text);
    $in = false;
    /** @var array<string,int> $out */
    $out = [];
    foreach ($lines as $ln) {
        if (str_starts_with(ltrim($ln), 'Sort by top of stack')) { $in = true; continue; }
        if (!$in) { continue; }
        $t = trim($ln);
        if ($t === '') { continue; }
        if (str_starts_with($t, 'Binary Images:')) { break; }
        if (!preg_match('/^(.+?)\s+\(in\s+[^)]*\)\s+(\d+)\s*$/', $t, $m)) { continue; }
        $sym = trim($m[1]);
        $out[$sym] = ($out[$sym] ?? 0) + (int)$m[2];
    }
    return $out;
}

/**
 * `malloc_history <pid> -allBySize` prints one stanza per distinct call stack,
 * headed by a count/size line and followed by the stack, innermost first:
 *
 *     4211 calls for 2695040 bytes: thread_... | start | main | ... | malloc
 *
 * The stack is charged to its DEEPEST non-plumbing `manticore_*` frame — the
 * allocator wrappers are shared by every site and would collapse the whole
 * report into `__mir_alloc_tagged`.
 *
 * @return array{bytes: array<string,int>, calls: array<string,int>}
 */
function prof_parse_malloc(string $text): array
{
    /** @var array<string,int> $bytes */
    $bytes = [];
    /** @var array<string,int> $calls */
    $calls = [];
    foreach (explode("\n", $text) as $ln) {
        if (!preg_match('/^\s*(\d+)\s+calls?\s+for\s+(\d+)\s+bytes:\s*(.*)$/', $ln, $m)) { continue; }
        $n = (int)$m[1];
        $b = (int)$m[2];
        $frames = array_map('trim', explode('|', $m[3]));
        $site = prof_charge_frame($frames);
        $bytes[$site] = ($bytes[$site] ?? 0) + $b;
        $calls[$site] = ($calls[$site] ?? 0) + $n;
    }
    return ['bytes' => $bytes, 'calls' => $calls];
}

/**
 * heaptrack_print --print-peak emits, per peak-contributing stack:
 *
 *     123456 bytes / 789 allocations from:
 *       manticore_Foo__bar
 *       ...
 *
 * @return array{bytes: array<string,int>, calls: array<string,int>}
 */
function prof_parse_heaptrack(string $text): array
{
    /** @var array<string,int> $bytes */
    $bytes = [];
    /** @var array<string,int> $calls */
    $calls = [];
    $pendB = 0;
    $pendN = 0;
    /** @var string[] $frames */
    $frames = [];
    $flush = static function () use (&$bytes, &$calls, &$pendB, &$pendN, &$frames): void {
        if ($pendB === 0 && $pendN === 0) { return; }
        $site = prof_charge_frame($frames);
        $bytes[$site] = ($bytes[$site] ?? 0) + $pendB;
        $calls[$site] = ($calls[$site] ?? 0) + $pendN;
        $pendB = 0;
        $pendN = 0;
        $frames = [];
    };
    foreach (explode("\n", $text) as $ln) {
        if (preg_match('/^\s*(\d+)\s+bytes?\s*\/\s*(\d+)\s+allocations?\s+from/', $ln, $m)) {
            $flush();
            $pendB = (int)$m[1];
            $pendN = (int)$m[2];
            continue;
        }
        $t = trim($ln);
        if ($t === '') { $flush(); continue; }
        if ($pendB > 0 || $pendN > 0) {
            // "  symbol at /path:line" / "  symbol in /path" — take the symbol.
            $sym = preg_split('/\s+(at|in)\s+/', $t)[0] ?? $t;
            $frames[] = trim($sym);
        }
    }
    $flush();
    return ['bytes' => $bytes, 'calls' => $calls];
}

/**
 * The frame a stack is charged to: the FIRST (innermost) frame that is neither
 * allocator plumbing nor a non-`manticore_` symbol. Falls back to the innermost
 * frame of all, so plumbing-only stacks stay visible instead of vanishing.
 *
 * @param string[] $frames innermost first
 */
function prof_charge_frame(array $frames): string
{
    // The negative control for the self-test: charge the innermost frame, which
    // collapses every site onto the allocator wrapper. A run with this set MUST
    // produce a useless report — that is what proves the fold is doing the work.
    if (getenv('PROF_NOFOLD') === '1') {
        foreach ($frames as $f) {
            if ($f !== '') { return prof_demangle($f); }
        }
        return '(unknown)';
    }
    foreach ($frames as $f) {
        if ($f === '') { continue; }
        if (prof_is_noise($f)) { continue; }
        if (!str_starts_with(ltrim($f, '_'), 'manticore_')) { continue; }
        return prof_demangle($f);
    }
    foreach ($frames as $f) {
        if ($f !== '') { return prof_demangle($f); }
    }
    return '(unknown)';
}

/**
 * @param array<string,int> $primary  the ranking key
 * @param array<string,int> $second   optional second column
 */
function prof_render(array $primary, array $second, string $c1, string $c2, int $top, string $unit): void
{
    arsort($primary);
    $total = array_sum($primary);
    if ($total === 0) {
        echo "no samples parsed — is this the right file/format?\n";
        return;
    }
    printf("total %s: %s\n\n", $c1, prof_fmt($total, $unit));
    printf("%-4s %-52s %14s %8s %14s\n", '#', 'function', $c1, '%', $c2);
    $i = 0;
    foreach ($primary as $sym => $v) {
        if ($i++ >= $top) { break; }
        printf(
            "%-4d %-52s %14s %7.1f%% %14s\n",
            $i,
            substr($sym, 0, 52),
            prof_fmt($v, $unit),
            100.0 * $v / $total,
            isset($second[$sym]) ? number_format($second[$sym]) : '',
        );
    }
}

function prof_fmt(int $v, string $unit): string
{
    if ($unit === 'bytes') { return number_format($v / 1048576, 1) . ' MB'; }
    return number_format($v);
}

/**
 * The pass that was running at `$ms`, from a `MANTICORE_STATS=1` stderr log.
 * `Stats::step()` prints AFTER a pass finishes (`stats: <elapsed>ms +<d>ms <name>`),
 * so the pass covering an instant is the FIRST line whose elapsed exceeds it.
 */
if ($mode === 'phase') {
    $ms = isset($argv[3]) ? (int)$argv[3] : 0;
    $prev = 0;
    foreach (explode("\n", $text) as $ln) {
        if (!preg_match('/^stats:\s+(\d+)ms\s+\+(\d+)ms\s+(.+?)\s*$/', trim($ln), $m)) { continue; }
        $at = (int)$m[1];
        if ($at >= $ms) {
            echo trim($m[3]) . ' (' . $prev . '..' . $at . 'ms)' . "\n";
            exit(0);
        }
        $prev = $at;
    }
    echo "after the last recorded pass (" . $prev . "ms) — clang/link\n";
    exit(0);
}

/**
 * Who reaches a symbol, from `sample`'s call-graph tree.
 *
 * ⚠ READ THE `truncated` LINE BEFORE BELIEVING THE TABLE. A leaf routine
 * compiled without a frame record (`-fno-omit-frame-pointer` is never passed —
 * Main.php:526) gives the unwinder nothing to walk, so those samples attach
 * straight to the thread root and their caller is simply not in the file. The
 * attributed rows are a BIASED SAMPLE of the callers, useful for direction and
 * useless as a share.
 */
if ($mode === 'callers') {
    $needle = isset($argv[3]) ? (string)$argv[3] : '';
    if ($needle === '') { fwrite(STDERR, "callers: need a symbol substring\n"); exit(2); }
    $top = isset($argv[4]) ? (int)$argv[4] : 40;
    /** @var array<string,int> $out */
    $out = [];
    $truncated = 0;
    /** @var array<int,string> $stack  column => symbol */
    $stack = [];
    foreach (explode("\n", $text) as $ln) {
        // "      + ! : | 48 _xzm_xzone_malloc  (in lib...) + 40  [0x...]"
        if (!preg_match('/^([ +!:|]*)(\d+)\s+(.+?)\s+\(in\s/', $ln, $m)) { continue; }
        $col = strlen($m[1]);
        $n = (int)$m[2];
        $sym = trim($m[3]);
        foreach (array_keys($stack) as $c) {
            if ($c >= $col) { unset($stack[$c]); }
        }
        if (str_contains($sym, $needle)) {
            $parent = '';
            $cols = array_keys($stack);
            rsort($cols);
            foreach ($cols as $c) {
                $s = $stack[$c];
                if (str_starts_with($s, 'Thread_')) { break; }
                if (prof_is_noise($s)) { continue; }
                if (!str_starts_with(ltrim($s, '_'), 'manticore_')) { continue; }
                $parent = prof_demangle($s);
                break;
            }
            if ($parent === '') { $truncated += $n; }
            else { $out[$parent] = ($out[$parent] ?? 0) + $n; }
        }
        $stack[$col] = $sym;
    }
    echo "callers of '" . $needle . "'\n";
    echo "truncated (no frame record to unwind): " . number_format($truncated) . " samples\n\n";
    prof_render($out, [], 'samples', '', $top, 'count');
    exit(0);
}

if ($mode === 'sample') {
    $self = prof_parse_sample($text);
    /** @var array<string,int> $folded */
    $folded = [];
    foreach ($self as $sym => $n) {
        $folded[prof_demangle($sym)] = ($folded[prof_demangle($sym)] ?? 0) + $n;
    }
    prof_render($folded, [], 'samples', '', $top, 'count');
    exit(0);
}

if ($mode === 'malloc' || $mode === 'heaptrack') {
    $r = $mode === 'malloc' ? prof_parse_malloc($text) : prof_parse_heaptrack($text);
    prof_render($r['bytes'], $r['calls'], 'live', 'blocks', $top, 'bytes');
    exit(0);
}

fwrite(STDERR, "report.php: unknown mode '" . $mode . "'\n");
exit(2);
