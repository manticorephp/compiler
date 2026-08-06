<?php

/**
 * Split one LLVM module into N objects so `clang` can assemble them in
 * parallel. PROTOTYPE — measures whether the design is worth wiring into
 * Main.php, and answers the two questions a design doc cannot: what actually
 * breaks at link time, and what the real speedup is.
 *
 *   php tools/split_ll.php <in.ll> <outdir> <n>
 *
 * `clang -O2` is 64% of a user compile (2704 ms of 4205 ms on
 * examples/http/hello.php) and ~49% of bin/build (53 s of 108 s), single
 * threaded, on a 10-core machine.
 *
 * ── What may and may not move ──
 * A function body may go in any part regardless of linkage: `external` and
 * `linkonce_odr` both resolve across objects. That matters because the split
 * is useless otherwise — in a user program 89% of the module is the injected
 * prelude, which is linkonce_odr, and pinning it to one part would leave
 * nothing to parallelise.
 *
 * What cannot move is an `internal` / `private` global: it is file-local, so a
 * reference from another part does not resolve. Those are duplicated into
 * every part that names them and promoted to `linkonce_odr` so the linker folds
 * the copies back to one. Their names are stable across the module (`@.str.20`
 * is the same pool entry everywhere), which is what makes folding correct.
 *
 * Each part then declares whatever it references but does not define; the
 * signature is taken from the defining `define` line, so no signature is
 * invented.
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: split_ll.php <in.ll> <outdir> <n>\n");
    exit(2);
}
$in = $argv[1];
$outDir = $argv[2];
$parts = (int)$argv[3];
if ($parts < 1) { fwrite(STDERR, "n must be >= 1\n"); exit(2); }
@mkdir($outDir, 0777, true);

$text = file_get_contents($in);
if ($text === false) { fwrite(STDERR, "cannot read $in\n"); exit(2); }
$lines = explode("\n", $text);
$n = count($lines);

/** Module-level lines that every part needs verbatim (target triple, datalayout, attributes, metadata, libc declares). */
$header = [];
/** name => ['text' => string, 'internal' => bool] */
$globals = [];
/** order of global names */
$globalOrder = [];
/** name => ['text' => string, 'header' => string] */
$defs = [];
$defOrder = [];
/** already-present declares, kept in every part (libc etc.) */
$declares = [];

$cur = null;
$buf = '';
for ($i = 0; $i < $n; $i++) {
    $l = $lines[$i];
    if ($cur !== null) {
        $buf .= "\n" . $l;
        if (rtrim($l) === '}') {
            $defs[$cur] = $buf;
            $defOrder[] = $cur;
            $cur = null;
        }
        continue;
    }
    if (strlen($l) > 7 && substr($l, 0, 7) === 'define ') {
        $sym = ll_symbol($l);
        if ($sym === '') { $header[] = $l; continue; }
        $cur = $sym;
        $buf = $l;
        continue;
    }
    if ($l !== '' && $l[0] === '@') {
        $sym = ll_global_name($l);
        if ($sym === '') { $header[] = $l; continue; }
        $globals[$sym] = $l;
        $globalOrder[] = $sym;
        continue;
    }
    if (str_starts_with($l, 'declare ')) { $declares[] = $l; continue; }
    $header[] = $l;
}

// An `internal` definition is file-local — a closure body is emitted
// `define internal`, so moving it away from its caller does not merely fail to
// link, it is invisible across the object boundary. Those are not partitioned
// at all: every part that reaches one gets its own copy, exactly like a
// file-local global.
$internal = [];
$shared = [];
foreach ($defOrder as $s) {
    $head = ll_head($defs[$s]);
    if (str_contains($head, ' internal ') || str_contains($head, ' private ')) {
        $internal[$s] = true;
        continue;
    }
    $shared[] = $s;
}

// Assign the externally-visible definitions round-robin by SIZE (largest first
// into the lightest part) so one 3.6 MB function does not decide the wall clock
// on its own.
$bySize = [];
foreach ($shared as $s) { $bySize[$s] = strlen($defs[$s]); }
arsort($bySize);
$load = array_fill(0, $parts, 0);
$assign = [];
foreach ($bySize as $sym => $sz) {
    $min = 0;
    for ($p = 1; $p < $parts; $p++) { if ($load[$p] < $load[$min]) { $min = $p; } }
    $assign[$sym] = $min;
    $load[$min] += $sz;
}
fwrite(STDERR, sprintf("defs: %d shared, %d internal (duplicated on demand)\n",
    count($shared), count($internal)));

$headerText = implode("\n", $header);
$declText = implode("\n", $declares);

$written = [];
for ($p = 0; $p < $parts; $p++) {
    $mine = [];
    foreach ($defOrder as $s) {
        if (isset($internal[$s])) { continue; }
        if ($assign[$s] === $p) { $mine[] = $s; }
    }
    // What this part references.
    $refs = [];
    foreach ($mine as $s) { ll_collect_refs($defs[$s], $refs); }
    // Pull in every file-local definition reachable from here, to a fixpoint —
    // one internal function can call another.
    $haveInternal = [];
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($defOrder as $s) {
            if (!isset($internal[$s]) || isset($haveInternal[$s]) || !isset($refs[$s])) { continue; }
            $haveInternal[$s] = true;
            ll_collect_refs($defs[$s], $refs);
            $changed = true;
        }
    }
    foreach ($defOrder as $s) {
        if (isset($haveInternal[$s])) { $mine[] = $s; }
    }
    // Globals it names — duplicated, and promoted when file-local.
    //
    // Closed to a fixpoint BEFORE anything is emitted: a global's initializer
    // can name another global (`@__mir_jmp_base = ... ptr @__mir_jmp_stack`),
    // and collecting refs while emitting missed whatever the emission order put
    // later — clang then rejected the part with "use of undefined value".
    // Part 0 carries every NON-DISCARDABLE global, so its initializers are live
    // here too — seed the closure with them or a global that only another
    // global names (`@E__fqns` -> `@E__fqn_0`) is emitted without its operand.
    if ($p === 0) {
        foreach ($globalOrder as $g) {
            if (ll_global_class($globals[$g]) === 'strong') { $refs[$g] = true; }
        }
    }
    $needG = [];
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($globalOrder as $g) {
            if (!isset($refs[$g]) || isset($needG[$g])) { continue; }
            $needG[$g] = true;
            ll_collect_refs($globals[$g], $refs);
            $changed = true;
        }
    }
    // A file-local global is duplicated into every part that names it (promoted
    // so the copies fold). One with EXTERNAL linkage must be defined exactly
    // once — duplicating `@g__SERVER` and the other superglobal cells is what
    // the linker reported as 6 duplicate symbols — so part 0 owns it and the
    // rest see `external`.
    // Three kinds of global, three rules.
    //
    //  local       `internal`/`private` — file-local, so every part naming it
    //              needs its own copy; promoted so the copies fold.
    //  coalesced   `linkonce_odr`/`weak_odr` — DISCARDABLE IF UNUSED. Defining
    //              it only in part 0 does not work: part 0 does not reference
    //              it, so clang -O2 drops it (the same GlobalDCE that PruneIr
    //              relies on) and the symbol is undefined at link. Define it in
    //              every part that names it and let the linker coalesce — which
    //              is exactly the contract this linkage exists for.
    //  strong      default linkage — not discardable, must be defined exactly
    //              once. Part 0 owns it; the rest see `external`.
    $gtext = '';
    foreach ($globalOrder as $g) {
        $gl = $globals[$g];
        $cls = $parts > 1 ? ll_global_class($gl) : 'strong';
        if ($cls === 'local') {
            if (!isset($needG[$g])) { continue; }
            $gtext .= ll_promote_global($gl) . "\n";
            continue;
        }
        if ($cls === 'coalesced') {
            if (!isset($needG[$g])) { continue; }
            $gtext .= $gl . "\n";
            continue;
        }
        // Gating part 0's DEFINITION on part 0's own references left globals
        // that only later parts touch defined by nobody, and link_stubs.sh then
        // quietly stubbed 133 of them to `return 0` — a mute binary, not a link
        // error. Part 0 defines them whether or not it uses them.
        if ($p === 0) { $gtext .= $gl . "\n"; continue; }
        if (!isset($needG[$g])) { continue; }
        $gtext .= ll_extern_global($gl) . "\n";
    }
    // Declares for everything referenced but defined elsewhere.
    $dtext = '';
    foreach ($refs as $r => $_) {
        if (!isset($defs[$r]) || isset($internal[$r]) || $assign[$r] === $p) { continue; }
        $dtext .= ll_declare_from_define($defs[$r]) . "\n";
    }
    $body = '';
    /** Discardable definitions this part owns but does not itself use. */
    $keep = [];
    foreach ($mine as $s) {
        $body .= $defs[$s] . "\n";
        if ($parts > 1 && str_contains(ll_head($defs[$s]), ' linkonce_odr ')) { $keep[] = $s; }
    }
    // A `linkonce_odr` definition is discardable if unused, and the part that
    // OWNS it is usually not the part that calls it — clang -O2 dropped
    // __manticore_box_null and friends, and the symbol went undefined at link.
    // llvm.compiler.used pins them through the optimizer while still letting the
    // LINKER dead-strip whatever the final program does not reach, so nothing
    // grows in the output.
    $usedText = '';
    if ($keep !== []) {
        $refsList = [];
        foreach ($keep as $s) { $refsList[] = 'ptr ' . ll_sym_ref($s); }
        $usedText = '@llvm.compiler.used = appending global [' . (string)count($keep)
            . ' x ptr] [' . implode(', ', $refsList) . '], section "llvm.metadata"' . "\n";
    }
    $out = $headerText . "\n" . $declText . "\n" . $gtext . $dtext . $usedText . $body;
    $path = $outDir . '/part' . (string)$p . '.ll';
    file_put_contents($path, $out);
    $written[] = $path;
    fwrite(STDERR, sprintf("part%d: %d fns, %.1f KB\n", $p, count($mine), strlen($out) / 1024));
}
foreach ($written as $w) { echo $w . "\n"; }

/** The first line of a definition. */
function ll_head(string $def): string
{
    $nl = strpos($def, "\n");
    return $nl === false ? $def : substr($def, 0, $nl);
}

/** An `@name` reference, quoted exactly as LLVM needs it. */
function ll_sym_ref(string $sym): string
{
    $len = strlen($sym);
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

/** The symbol a `define` line defines. */
function ll_symbol(string $line): string
{
    $at = strpos($line, '@');
    if ($at === false) { return ''; }
    return ll_read_symbol($line, $at + 1);
}

/** The name a module-level `@x = ...` line defines. */
function ll_global_name(string $line): string
{
    $eq = strpos($line, ' = ');
    if ($eq === false) { return ''; }
    return ll_read_symbol($line, 1);
}

/**
 * `internal` / `private` is file-local and cannot be referenced from another
 * object. Promote to linkonce_odr so identical copies in several parts fold
 * back to one at link time.
 */
function ll_promote_global(string $line): string
{
    $eq = strpos($line, ' = ');
    if ($eq === false) { return $line; }
    $head = substr($line, 0, $eq + 3);
    $rest = substr($line, $eq + 3);
    foreach (['internal ', 'private '] as $k) {
        if (str_starts_with($rest, $k)) {
            return $head . 'linkonce_odr ' . substr($rest, strlen($k));
        }
    }
    return $line;
}

/**
 * How a global must be replicated across parts: 'local' (file-local),
 * 'coalesced' (discardable-if-unused, so every user defines it) or 'strong'
 * (defined exactly once). {@see the emission comment above}.
 */
function ll_global_class(string $line): string
{
    $eq = strpos($line, ' = ');
    if ($eq === false) { return 'strong'; }
    $rest = substr($line, $eq + 3);
    if (str_starts_with($rest, 'internal ') || str_starts_with($rest, 'private ')) { return 'local'; }
    if (str_starts_with($rest, 'linkonce_odr ') || str_starts_with($rest, 'weak_odr ')
        || str_starts_with($rest, 'linkonce ') || str_starts_with($rest, 'weak ')) { return 'coalesced'; }
    return 'strong';
}

/**
 * The `external` form of a global definition — same name and type, no
 * initializer, so a part can reference a cell part 0 defines.
 */
function ll_extern_global(string $line): string
{
    $eq = strpos($line, ' = ');
    if ($eq === false) { return $line; }
    $name = substr($line, 0, $eq);
    $rest = substr($line, $eq + 3);
    $kw = '';
    $at = false;
    foreach (['global ', 'constant '] as $k) {
        $pos = strpos($rest, $k);
        if ($pos !== false && ($at === false || $pos < $at)) { $at = $pos; $kw = $k; }
    }
    if ($at === false) { return $line; }
    $type = ll_lead_type(ltrim(substr($rest, $at + strlen($kw))));
    if ($type === '') { return $line; }
    return $name . ' = external ' . rtrim($kw) . ' ' . $type;
}

/** The leading LLVM type of a string — `[12 x i8]`, `{ i64, ptr }`, `ptr`, `i64`. */
function ll_lead_type(string $s): string
{
    if ($s === '') { return ''; }
    $open = ['[' => ']', '{' => '}', '<' => '>'];
    $c = $s[0];
    if (isset($open[$c])) {
        $close = $open[$c];
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === $c) { $depth++; }
            elseif ($s[$i] === $close) {
                $depth--;
                if ($depth === 0) { return substr($s, 0, $i + 1); }
            }
        }
        return '';
    }
    $sp = strpos($s, ' ');
    return $sp === false ? rtrim($s) : substr($s, 0, $sp);
}

/** A `declare` matching a definition's signature — never invented, always
 *  taken from the `define` line the other part carries. */
function ll_declare_from_define(string $def): string
{
    $nl = strpos($def, "\n");
    $head = $nl === false ? $def : substr($def, 0, $nl);
    $paren = strpos($head, '(');
    if ($paren === false) { return ''; }
    $close = strrpos($head, ')');
    if ($close === false || $close < $paren) { return ''; }
    $sig = substr($head, 0, $close + 1);
    // define <linkage...> <ret> @name(params)  ->  declare <ret> @name(params)
    $sig = substr($sig, 7);
    foreach (['linkonce_odr ', 'weak_odr ', 'internal ', 'private ', 'external ',
              'dso_local ', 'hidden ', 'weak '] as $k) {
        while (str_starts_with($sig, $k)) { $sig = substr($sig, strlen($k)); }
    }
    return 'declare ' . $sig;
}

/** Every `@symbol` named in a chunk of IR. */
function ll_collect_refs(string $text, array &$into): void
{
    $len = strlen($text);
    $i = 0;
    while ($i < $len) {
        $at = strpos($text, '@', $i);
        if ($at === false) { return; }
        $s = ll_read_symbol($text, $at + 1);
        if ($s !== '') { $into[$s] = true; }
        $i = $at + 1 + strlen($s);
        if ($i <= $at) { $i = $at + 1; }
    }
}

function ll_read_symbol(string $s, int $p): string
{
    $len = strlen($s);
    if ($p >= $len) { return ''; }
    if ($s[$p] === '"') {
        $end = strpos($s, '"', $p + 1);
        if ($end === false) { return ''; }
        return substr($s, $p + 1, $end - $p - 1);
    }
    $start = $p;
    while ($p < $len) {
        $c = $s[$p];
        if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9')
            || $c === '_' || $c === '.' || $c === '$' || $c === '\\' || $c === '-') {
            $p++;
            continue;
        }
        break;
    }
    if ($p === $start) { return ''; }
    return substr($s, $start, $p - $start);
}
