<?php

namespace Manticore;

use Ffi\CType;
use Ffi\Library;
use Ffi\Symbol;
use Parser\Parser;

// ── libc bindings used by the driver ─────────────────────────────────
//
// These duplicate symbols `Runtime\Libc` also binds — the driver needs them
// before the stdlib exists — so their C signatures must match it EXACTLY. The
// emitter rejects a module where two bindings of one symbol disagree: the
// declare is keyed by symbol, so otherwise whichever wrapper is emitted first
// silently decides what every call site is typed against.

#[Library('c'), Symbol('puts'), CType('int')]
function puts(string $s): int {}

#[Library('c'), Symbol('fflush'), CType('int')]
function fflush(\Ffi\Ptr $stream): int {}

#[Library('c'), Symbol('write')]
function write(#[CType('int')] int $fd, string $buf,
               #[CType('size_t')] int $n): int { return 0; }

#[Library('c'), Symbol('read')]
function read(#[CType('int')] int $fd, \Ffi\Ptr $buf,
              #[CType('size_t')] int $n): int { return 0; }

#[Library('c'), Symbol('malloc')]
function malloc(int $size): \Ffi\Ptr {}

#[Library('c'), Symbol('calloc')]
function calloc(int $count, int $size): \Ffi\Ptr {}

#[Library('c'), Symbol('uname'), CType('int')]
function uname(\Ffi\Ptr $buf): int { return 0; }

#[Library('c'), Symbol('manticore_cli_argc')]
function argc(): int { return $GLOBALS['argc'] ?? 0; }

// Raw OS char* — converted to a headered string via cstr_to_str at the edge.
#[Library('c'), Symbol('manticore_cli_argv')]
function argv(int $i): \Ffi\Ptr {}

#[Library('c'), Symbol('system'), CType('int')]
function system(string $cmd): int { return 0; }

#[Library('c'), Symbol('fopen')]
function fopen(string $path, string $mode): \Ffi\Ptr {}

#[Library('c'), Symbol('fwrite')]
function fwrite(string $buf, #[CType('size_t')] int $size,
                #[CType('size_t')] int $count, \Ffi\Ptr $stream): int { return 0; }

#[Library('c'), Symbol('fread')]
function fread(\Ffi\Ptr $buf, #[CType('size_t')] int $size,
               #[CType('size_t')] int $count, \Ffi\Ptr $stream): int { return 0; }

#[Library('c'), Symbol('fseek'), CType('int')]
function fseek(\Ffi\Ptr $stream, #[CType('long')] int $offset,
               #[CType('int')] int $whence): int { return 0; }

#[Library('c'), Symbol('ftell')]
function ftell(\Ffi\Ptr $stream): int { return 0; }

#[Library('c'), Symbol('fclose'), CType('int')]
function fclose(\Ffi\Ptr $stream): int { return 0; }

#[Library('c'), Symbol('getpid'), CType('int')]
function getpid(): int { return 0; }

#[Library('c'), Symbol('access'), CType('int')]
function access(string $path, #[CType('int')] int $mode): int { return -1; }

#[Library('c'), Symbol('opendir')]
function opendir(string $path): \Ffi\Ptr {}

#[Library('c'), Symbol('closedir'), CType('int')]
function closedir(\Ffi\Ptr $dir): int { return 0; }

/**
 * Tiny self-host shim. Under Zend the real `file_exists` shadows
 * this in user code; here it lowers to `access(path, F_OK=0)`.
 */
function file_exists(string $path): bool {
    return access($path, 0) === 0;
}

/**
 * Cheap "is this path a directory?" probe via `opendir`. Returns
 * true when the system call succeeds (and immediately closes the
 * dir handle). False for regular files and non-existent paths.
 *
 * Used by `resolve_sources` to detect `bin/manticore compile src/`
 * style invocations where the argv path is a directory we should
 * recurse into for `*.php` files rather than feed straight to
 * `read_file`.
 */
function is_directory(string $path): bool {
    // We deliberately do NOT capture opendir's result into a local
    // — self-host pre-scan defaults the `$dir = opendir(...)` slot
    // to i64 because Ffi-Ptr return-type inference only handles
    // the canonical `fopen` shape today. We just need a yes/no
    // answer; leaking the directory handle is acceptable for the
    // CLI lifetime (process exits before the OS cares).
    return opendir($path) !== null;
}


/**
 * Write to stderr — bypasses any stdout buffering so the line lands
 * before any subsequent crash. Under our compiled binary this is the
 * libc `write` FFI binding above. Under Zend the call is a no-op
 * (the FFI binding has an empty body); use `fwrite(STDERR, ...)`
 * directly when you need traces while running the compiler itself
 * under Zend.
 */
function dprint(string $s): void {
    // error_log, NOT the libc `write` binding: the binding's body is EMPTY, so
    // every diagnostic vanished whenever the compiler ran under Zend (the cold
    // seed, tools/compile_files_mir.php) and a real "compile failed: <reason>"
    // surfaced as a bare "compile error (MIR)". error_log is a codegen builtin
    // natively AND a php function under Zend — the message survives both.
    \error_log($s);
}

// ── Driver entry point ────────────────────────────────────────────────

/**
 * Smoke driver: parse a hard-coded PHP snippet, run the compiler,
 * print the generated LLVM IR via puts(). Proves the merged-compile
 * pipeline actually executes the Parser + Compiler classes once
 * linked into the native binary.
 *
 * Next iterations replace the snippet with argv-driven file reading.
 */
/**
 * Read the whole source from stdin into a fresh buffer.
 * Single libc `read(0, ...)` into a 1 MiB block — big enough for the
 * snippets the bootstrap currently digests; we'll grow this later.
 */
function read_stdin_source(): string {
    $cap = 1048576; // 1 MiB
    $buf = calloc($cap + 1, 1);
    $n = read(0, $buf, $cap);
    if ($n < 0) { $n = 0; }
    // Copy the raw libc buffer into a real (rc-headered) MIR string (see
    // read_file) — the calloc block has no header and must not be released.
    return \str_from_buffer($buf, $n);
}

/**
 * Pull the process argv into a real PHP array so the CLI router can
 * iterate it without re-querying libc on every access. Stops at the
 * first NULL argv entry as a defensive bound.
 *
 * @return string[]
 */
function collect_argv(): array {
    $n = argc();
    $out = [];
    $i = 0;
    while ($i < $n) {
        // argv($i) is a raw libc C string (no rc header). Copy it into a
        // real MIR string before it enters the vec — appending the raw
        // pointer would rc-retain a headerless buffer and corrupt the
        // adjacent argv strings on the stack.
        $raw = argv($i);
        $out[] = \cstr_to_str($raw);
        $i = $i + 1;
    }
    return $out;
}

/**
 * Read a file from disk into a fresh calloc'd buffer (zero-init tail
 * acts as implicit NUL terminator). Returns the buffer or null when
 * the file can't be opened.
 */
function read_file(string $path): ?string {
    $fp = fopen($path, "rb");
    if ($fp === null) {
        dprint("read_file: fopen failed: " . $path);
        return null;
    }
    // SEEK_END = 2 / SEEK_SET = 0 across both Linux and macOS libcs.
    fseek($fp, 0, 2);
    $size = ftell($fp);
    fseek($fp, 0, 0);
    if ($size < 0) {
        dprint("read_file: ftell returned <0 for " . $path);
        fclose($fp);
        return null;
    }
    $buf = calloc($size + 1, 1);
    if ($buf === null) {
        dprint("read_file: calloc returned null (size=" . (string)$size . ")");
        fclose($fp);
        return null;
    }
    $n = fread($buf, 1, $size, $fp);
    if ($n !== $size) {
        dprint("read_file: fread short read (got " . (string)$n . " expected " . (string)$size . ")");
    }
    fclose($fp);
    // Copy the raw libc buffer into a real (rc-headered) MIR string —
    // `$buf` is a calloc block with no string header and must never be
    // rc-released; str_from_buffer returns an owned, releasable string. (calloc
    // is an FFI call so $buf itself is left non-rc by InsertMemoryOps.)
    return \str_from_buffer($buf, $size);
}

/**
 * Discover bundled stdlib sources (`Runtime/Stdlib/*.php`) relative to
 * argv[0]. Tries a couple of conventional layouts:
 *   - <argv0_dir>/../src/Runtime/Stdlib/   (dev tree: bin/manticore)
 *   - <argv0_dir>/Runtime/Stdlib/          (installed flat)
 *   - MANTICORE_STDLIB env var override
 *
 * Returns the concatenated source of every `*.php` discovered, or
 * an empty string when nothing was found. Prepended to user code
 * so `array_slice` / `str_contains` / friends resolve at link time
 * without having to bundle source into the binary.
 */
/**
 * Discover bundled stdlib sources (`Runtime/Stdlib/*.php`) relative to
 * argv[0]. Returns one source string per file. Each file is parsed
 * separately by the caller so multiple `<?php` headers don't trip
 * the parser.
 *
 * NOTE: not auto-prepended in compile_sources today — the
 * self-host parse/inference path hits unresolved subclass-dispatch
 * crashes when fed the stdlib source as a guest program. Left in
 * place as a hook for the follow-up that fixes those.
 *
 * Search order:
 *   - MANTICORE_STDLIB env var override (single dir)
 *   - <argv0_dir>/../src/Runtime/Stdlib/   (dev tree: bin/manticore)
 *   - <argv0_dir>/Runtime/Stdlib/          (installed flat)
 *
 * @return string[]
 */
function discover_stdlib_files(): array {
    // Each candidate is passed DIRECTLY to read_stdlib_dir (a `string $dir`
    // param) instead of through a shared `string[]` array — a heterogeneous
    // array (getenv cell + concat strings) mis-infers its element type to i64,
    // so reading an element back yields the pointer as a number.
    $envPath = \getenv("MANTICORE_STDLIB");
    if (\is_string($envPath) && $envPath !== "") {
        $r = read_stdlib_dir($envPath);
        if (\count($r) > 0) { return $r; }
    }
    $rawSelf = argv(0);
    $self = \cstr_to_str($rawSelf);
    $slashAt = \strrpos($self, "/");
    if ($slashAt !== false && $slashAt >= 0) {
        $selfDir = \substr($self, 0, $slashAt);
        // The bundled stdlib lives under Runtime/ (Libc.php + Stdlib/*.php);
        // the #[Symbol] libc bindings in Libc.php are required by Stdlib.
        $r = read_stdlib_dir($selfDir . "/../src/Runtime");
        if (\count($r) > 0) { return $r; }
        $r = read_stdlib_dir($selfDir . "/Runtime");
        if (\count($r) > 0) { return $r; }
    }
    return [];
}

/**
 * Read every `*.php` under `$dir` into a source-string array (empty if none).
 *
 * @return string[]
 */
function read_stdlib_dir(string $dir): array {
    /** @var string[] $out */
    $out = [];
    $listPath = "/tmp/manticore_stdlib_" . (string)getpid() . ".txt";
    system("find " . $dir . " -name '*.php' -type f 2>/dev/null | sort > " . $listPath);
    $contents = read_file($listPath);
    if ($contents === null) { return $out; }
    foreach (\explode("\n", $contents) as $path) {
        if (\strlen($path) === 0) { continue; }
        $src = read_file($path);
        if ($src !== null) { $out[] = $src; }
    }
    return $out;
}

/**
 * Linker flags for the host PCRE2 (dynamic link), via `pcre2-config --libs8`
 * so the -L path is right on any host (e.g. Homebrew's non-standard prefix).
 * Falls back to a bare `-lpcre2-8`; empty only if that too would be pointless.
 * Used by the preg_* stdlib wrappers; dead-strip drops it when regex is unused.
 */
function pcre2_link_flags(): string {
    $listPath = "/tmp/manticore_pcre2_" . (string)getpid() . ".txt";
    $rc = system("pcre2-config --libs8 > " . $listPath . " 2>/dev/null");
    if ($rc === 0) {
        $c = read_file($listPath);
        if ($c !== null) {
            $t = \trim($c);
            if ($t !== "") { return $t; }
        }
    }
    return "-lpcre2-8";
}

/**
 * Linker flags for the host OpenSSL (libssl + libcrypto), via `pkg-config`
 * so the -L path is right on any host (Homebrew keeps openssl@3 off the default
 * search path). Falls back to bare `-lssl -lcrypto`. Used by the TLS transport
 * under `https://`; dead-strip drops it for a program that never opens a TLS
 * stream, exactly as pcre2 is dropped when regex is unused.
 */
function openssl_link_flags(): string {
    $listPath = "/tmp/manticore_openssl_" . (string)getpid() . ".txt";
    $rc = system("pkg-config --libs openssl > " . $listPath . " 2>/dev/null");
    if ($rc === 0) {
        $c = read_file($listPath);
        if ($c !== null) {
            $t = \trim($c);
            if ($t !== "") { return $t; }
        }
    }
    return "-lssl -lcrypto";
}

/**
 * Linker flags for the host iconv. Unlike pcre2 and openssl this is NOT a
 * uniform `-l`: glibc implements iconv INSIDE libc, so `-liconv` there fails to
 * resolve, while Darwin (and musl with gnu-libiconv) needs it. Host-conditional
 * by construction rather than probed, because the answer is a property of the C
 * library, not of what happens to be installed.
 */
function iconv_link_flags(): string {
    return \Manticore\host_os() === "Darwin" ? "-liconv" : "";
}

/**
 * Resolve a set of `#[Ffi\Library]` names to `cc` link tokens.
 *
 * The two probes above stay the RESOLVERS — they exist because Homebrew keeps
 * openssl@3 and pcre2 off the default search path, so a bare `-lssl` would not
 * link here. What changes is who asks: the flags used to be appended
 * unconditionally from a "did we link stdlib.o" branch, which made
 * `#[Ffi\Library]` decorative. Now the bindings that name a library are what
 * pulls it in.
 *
 * Anything else resolves through `pkg-config`, then `<name>-config`, then a
 * bare `-l<name>`. `$already` is a link-flag string the caller has ALREADY
 * placed on the line — the manifest's `extensions[].link` — whose `-l<name>`
 * tokens are skipped here, so an extension declaring both `"link": ["z"]` and
 * `#[Library('z')]` yields one `-lz` rather than a duplicate-library warning.
 *
 * @param string[] $libs
 */
function ffi_link_flags(array $libs, string $already = ""): string
{
    $seen = [];
    foreach (\explode(" ", $already) as $tok) {
        $t = \trim($tok);
        if (\strlen($t) > 2 && \substr($t, 0, 2) === "-l") {
            $seen[\substr($t, 2)] = true;
        }
    }
    $out = "";
    foreach ($libs as $lib) {
        $name = (string)$lib;
        // libc/libSystem is always linked; naming it is documentation.
        if ($name === "" || $name === "c") { continue; }
        if (isset($seen[$name])) { continue; }
        $seen[$name] = true;
        if ($name === "ssl" || $name === "crypto") {
            $flags = openssl_link_flags();   // one probe covers both
            if (isset($seen["\0openssl"])) { continue; }
            $seen["\0openssl"] = true;
        } elseif ($name === "pcre2-8") {
            $flags = pcre2_link_flags();
        } elseif ($name === "iconv") {
            // NOT a probe: glibc implements iconv inside libc, so `-liconv`
            // there fails to resolve even though the symbols are present. The
            // answer is a property of the C library, not of what is installed.
            $flags = iconv_link_flags();
        } else {
            $flags = generic_link_flags($name);
        }
        if ($flags !== "") { $out = $out . " " . $flags; }
    }
    return $out;
}

/**
 * Link flags for a library with no special-cased probe: `pkg-config`, then
 * `<name>-config --libs`, then a bare `-l<name>`. The fallback is what makes an
 * ordinary system library (`-lz`, `-lm`) work with no configuration at all.
 */
function generic_link_flags(string $name): string
{
    $listPath = "/tmp/manticore_lib_" . $name . "_" . (string)getpid() . ".txt";
    $rc = system("pkg-config --libs " . $name . " > " . $listPath . " 2>/dev/null");
    if ($rc === 0) {
        $c = read_file($listPath);
        if ($c !== null) {
            $t = \trim($c);
            if ($t !== "") { return $t; }
        }
    }
    $rc = system($name . "-config --libs > " . $listPath . " 2>/dev/null");
    if ($rc === 0) {
        $c = read_file($listPath);
        if ($c !== null) {
            $t = \trim($c);
            if ($t !== "") { return $t; }
        }
    }
    return "-l" . $name;
}

/**
 * Darwin's allowance for weak-undefined symbols, derived from what the module
 * actually declared `extern_weak` rather than hand-maintained beside the
 * bindings. ld64 errors on a weak-undefined unless `-U <sym>` permits it; the
 * Mach-O leading underscore is added here. GNU ld auto-binds weak-undefined to
 * 0, so this is Darwin-only and the Linux arm passes an empty set.
 *
 * A `-U` for a symbol that IS present is accepted silently (verified on
 * ld-1267), so the derived set never needs filtering against the host.
 *
 * @param string[] $syms
 */
function weak_undef_flags(array $syms): string
{
    $out = "";
    $seen = [];
    foreach ($syms as $s) {
        $name = (string)$s;
        if ($name === "" || isset($seen[$name])) { continue; }
        $seen[$name] = true;
        $out = $out . " -Wl,-U,_" . $name;
    }
    return $out;
}

/**
 * The link requirements the prebuilt stdlib `.sig` records — the libraries its
 * FFI wrappers call, and the symbols it declared extern_weak.
 *
 * These must reach the FINAL link of a program that never names them: the
 * pcre2/openssl/signalfd wrappers live inside `lib/manticore_stdlib.o`, and a
 * user module that calls `preg_match` has no `#[Ffi\Library]` of its own.
 *
 * ⚠ The `$fallback` is load-bearing, not defensive. A `.sig` built before these
 * keys existed has neither, and that is exactly the state during the first
 * rebuild after this change — returning the old unconditional set reproduces
 * the previous behaviour byte for byte instead of silently linking nothing.
 *
 * @param string[] $fallback
 * @return string[]
 */
function stdlib_sig_list(string $key, array $fallback): array
{
    $sigPath = find_stdlib_sig();
    if ($sigPath === "") { return $fallback; }
    $sigJson = read_file($sigPath);
    if ($sigJson === null) { return $fallback; }
    $got = $key === "libs" ? Sig::libsFromJson($sigJson) : Sig::weakFromJson($sigJson);
    if ($got === null) { return $fallback; }
    return $got;
}

/**
 * Parse the bundled stdlib sources and collect every GLOBAL-namespace
 * function declaration, for signature-only extern injection (so user code
 * can call `str_starts_with`, `ctype_*`, `file_get_contents`, … with the
 * definition supplied by the linked `stdlib.o`).
 *
 * Namespaced declarations (the `Runtime\Libc\*` FFI bindings) are skipped —
 * they're internal to stdlib.o and user code never names them. Parse errors
 * on a single file are non-fatal (skip it).
 *
 * @return \Parser\Ast\FunctionDecl[]
 */
/**
 * How many `clang` processes to assemble with. `-j` wins; otherwise the host's
 * core count, capped — past ~8 the parts get small enough that process startup
 * and the duplicated file-local definitions eat the gain.
 */
function assemble_jobs(): int {
    if (CompileArgs::$jobs !== 0) { return CompileArgs::$jobs; }
    $n = 0;
    $probe = is_darwin() ? "sysctl -n hw.ncpu 2>/dev/null" : "nproc 2>/dev/null";
    $out = \shell_exec($probe);
    if ($out !== null && $out !== false) { $n = (int)\trim((string)$out); }
    if ($n < 1) { return 1; }
    $n = $n - 1;
    if ($n < 1) { return 1; }
    if ($n > 8) { return 8; }
    return $n;
}

/**
 * Assemble `$ir` into one or more objects and return their paths, or `[]` on
 * failure (the caller reports; the staged files are left for inspection).
 *
 * With more than one job the module is split ({@see \Compile\Mir\SplitModule})
 * and the parts are handed to concurrent `clang` processes. The concurrency is
 * the SHELL's, one `system()` with `&` and a `wait`: the compiler must run this
 * identically under the Zend cold seed and natively, and `wait`'s exit status
 * only reports the last job, so success is decided by checking that every
 * expected object exists rather than by the shell's status.
 *
 * @return string[]
 */
function assemble_ir(string $ir, string $base, string $cflags): array {
    $llPath = $base . ".ll";
    $objPath = $base . ".o";
    // Size gate FIRST: assemble_jobs() may shell out to sysctl/nproc, and paying
    // a process spawn to decide not to split made a hello world 12 ms slower.
    // Below a few hundred KB the split cannot pay for itself anyway.
    $jobs = \strlen($ir) < 262144 ? 1 : assemble_jobs();
    // A CEILING, not a preference. `-j` is a speed knob and `bin/build`
    // deliberately never passes it (a part boundary is an inlining boundary),
    // but past a certain size one translation unit stops being slow and becomes
    // IMPOSSIBLE: clang's source-location space is finite, and the symfony
    // tier-4 unit — 785 MB of IR from 1548 files — died on
    //
    //   fatal error: translation unit is too large for Clang to process:
    //   ran out of source locations
    //
    // after the compiler had emitted every byte of it correctly. So the split
    // is forced by SIZE here even at `-j1`; a program small enough to fit keeps
    // exactly the single-TU behaviour, inlining and all.
    $partCeiling = 48 * 1024 * 1024;
    $needed = \intdiv(\strlen($ir) + $partCeiling - 1, $partCeiling);
    if ($needed > $jobs) { $jobs = $needed; }
    if ($jobs < 2) {
        // Below a few hundred KB the split cannot pay for itself.
        if (!write_file($llPath, $ir)) { dprint("assemble: cannot write " . $llPath); return []; }
        $rc = system("clang -O" . CompileArgs::$optLevel . " " . $cflags
            . " -c -x ir " . $llPath . " -o " . $objPath . " -Wno-override-module");
        if ($rc !== 0) { dprint("assemble: clang -c failed (rc=" . (string)$rc . "); IR at " . $llPath); return []; }
        return [$objPath];
    }
    $statT = \Compile\Stats::now();
    $splitter = new \Compile\Mir\SplitModule();
    $parts = $splitter->run($ir, $jobs);
    \Compile\Stats::step('  split module (' . (string)$jobs . ' parts)', $statT,
        $splitter->sharedDefs, $splitter->internalDefs);
    $objs = [];
    /** @var string[] $cmds one clang invocation per part */
    $cmds = [];
    foreach ($parts as $i => $partIr) {
        $pll = $base . ".p" . (string)$i . ".ll";
        $pobj = $base . ".p" . (string)$i . ".o";
        if (!write_file($pll, $partIr)) { dprint("assemble: cannot write " . $pll); return []; }
        $cmds[] = "clang -O" . CompileArgs::$optLevel . " " . $cflags
             . " -c -x ir " . $pll . " -o " . $pobj . " -Wno-override-module";
        $objs[] = $pobj;
    }
    // Remove stale objects first: existence is what decides success below, so a
    // leftover from an earlier run must not read as a part that built.
    foreach ($objs as $o) { system("rm -f " . $o); }
    $statT = \Compile\Stats::now();
    // In WAVES, not all at once. The part count is now driven by SIZE as well as
    // by `-j` ({@see the ceiling above}), so a big application can produce far
    // more parts than the machine has cores — and each clang holds its whole
    // part in memory at -O2. The wave width is the job count the machine asked
    // for; a `-j`-sized build is one wave, exactly as before.
    //
    // `wait` must be INSIDE the subshell: the background jobs are ITS children,
    // so an outer `wait` has nothing to wait for and returns at once — the
    // existence check below then ran before clang had written anything and
    // reported "part 0 failed to build" on a build that was merely still going.
    $wave = assemble_jobs();
    if ($wave < 1) { $wave = 1; }
    $cmd = '';
    $inWave = 0;
    foreach ($cmds as $c) {
        if ($cmd !== '') { $cmd = $cmd . ' & '; }
        $cmd = $cmd . $c;
        $inWave = $inWave + 1;
        if ($inWave >= $wave) {
            system("( " . $cmd . " ; wait )");
            $cmd = '';
            $inWave = 0;
        }
    }
    if ($cmd !== '') { system("( " . $cmd . " ; wait )"); }
    \Compile\Stats::step('  clang -O' . CompileArgs::$optLevel . ' -c x' . (string)\count($parts),
        $statT, -1, -1);
    foreach ($objs as $i => $o) {
        if (!\file_exists($o)) {
            dprint("assemble: part " . (string)$i . " failed to build; IR at " . $base . ".p" . (string)$i . ".ll");
            return [];
        }
    }
    return $objs;
}

function collect_stdlib_extern_decls(): array
{
    /** @var \Parser\Ast\FunctionDecl[] $decls */
    $decls = [];
    // Preferred: read the bundled interface `.sig` next to the binary. A
    // distributed compiler ships bin/ + lib/ only (no src/Runtime sources), and
    // the .sig carries the full exported table (incl namespaced/FFI bindings),
    // so this is both portable and richer than re-parsing.
    $sigPath = find_stdlib_sig();
    if ($sigPath !== "") {
        $sigJson = read_file($sigPath);
        if ($sigJson !== null) {
            $bad = Sig::validateImport($sigJson, $sigPath);
            if ($bad !== "") { CompileArgs::$sigError = $bad; return $decls; }
            return Sig::declsFromJson($sigJson);
        }
    }
    // Dev-tree fallback: no built .sig yet → re-parse the stdlib sources. Reads
    // via the libc fopen binding (a throwing stub under the Zend bootstrap), so
    // guard — collection is a no-op there (the bootstrap defines stdlib itself).
    $files = [];
    try {
        $files = discover_stdlib_files();
    } catch (\Throwable $e) {
        return $decls;
    }
    foreach ($files as $src) {
        try {
            $program = Parser::parseSource($src);
        } catch (\Throwable $e) {
            continue;
        }
        foreach ($program->statements as $stmt) {
            if ($stmt->kind !== 'Function') { continue; }
            // Global namespace only: a `\` in the name marks a namespaced
            // FFI binding (Runtime\Libc\…) — internal to stdlib.o.
            if (\strpos($stmt->decl->name, '\\') !== false) { continue; }
            $decls[] = $stmt->decl;
        }
    }
    return $decls;
}

/**
 * Locate the prebuilt `stdlib.o` relative to argv[0] (one file → robust):
 *   - MANTICORE_STDLIB_O env override
 *   - <argv0_dir>/../lib/manticore_stdlib.o   (dev tree: bin/manticore)
 *   - <argv0_dir>/lib/manticore_stdlib.o      (installed)
 *   - <argv0_dir>/manticore_stdlib.o          (flat install)
 * Returns the path or "" when absent (caller links without it).
 */
function find_stdlib_object(): string
{
    $envPath = \getenv("MANTICORE_STDLIB_O");
    if (\is_string($envPath) && $envPath !== "" && file_exists($envPath)) {
        return $envPath;
    }
    $rawSelf = argv(0);
    $self = \cstr_to_str($rawSelf);
    $slashAt = \strrpos($self, "/");
    if ($slashAt === false || $slashAt < 0) { return ""; }
    $selfDir = \substr($self, 0, $slashAt);
    $c1 = $selfDir . "/../lib/manticore_stdlib.o";
    if (file_exists($c1)) { return $c1; }
    $c2 = $selfDir . "/lib/manticore_stdlib.o";
    if (file_exists($c2)) { return $c2; }
    $c3 = $selfDir . "/manticore_stdlib.o";
    if (file_exists($c3)) { return $c3; }
    return "";
}

/**
 * Locate the bundled stdlib interface next to the binary (mirrors
 * find_stdlib_object): MANTICORE_STDLIB_SIG env, then <argv0_dir>/../lib, /lib,
 * /. The manifest build writes `<output>.sig` → `manticore_stdlib.o.sig`; the
 * legacy `manticore_stdlib.sig` (old dump-sig step) is checked as a fallback.
 * Lets a DISTRIBUTED compiler (bin/ + lib/, no sources) type + resolve
 * bundled-stdlib calls. Returns "" if absent.
 */
function find_stdlib_sig(): string
{
    $envPath = \getenv("MANTICORE_STDLIB_SIG");
    if (\is_string($envPath) && $envPath !== "" && file_exists($envPath)) {
        return $envPath;
    }
    $rawSelf = argv(0);
    $self = \cstr_to_str($rawSelf);
    $slashAt = \strrpos($self, "/");
    if ($slashAt === false || $slashAt < 0) { return ""; }
    $selfDir = \substr($self, 0, $slashAt);
    // Preferred: the manifest's `<output>.sig` (manticore_stdlib.o.sig).
    $p1 = $selfDir . "/../lib/manticore_stdlib.o.sig";
    if (file_exists($p1)) { return $p1; }
    $p2 = $selfDir . "/lib/manticore_stdlib.o.sig";
    if (file_exists($p2)) { return $p2; }
    $p3 = $selfDir . "/manticore_stdlib.o.sig";
    if (file_exists($p3)) { return $p3; }
    // Legacy fallback: the old dump-sig name.
    $c1 = $selfDir . "/../lib/manticore_stdlib.sig";
    if (file_exists($c1)) { return $c1; }
    $c2 = $selfDir . "/lib/manticore_stdlib.sig";
    if (file_exists($c2)) { return $c2; }
    $c3 = $selfDir . "/manticore_stdlib.sig";
    if (file_exists($c3)) { return $c3; }
    return "";
}

/**
 * Read a prelude PHP file (`prelude/<file>`) relative to argv[0] and return
 * its class source with the leading `<?php` header stripped, ready to append
 * to the parsed prelude (which already opens with `<?php`). Mirrors
 * find_stdlib_object's argv0-relative search:
 *   - MANTICORE_PRELUDE env override (a directory)
 *   - <argv0_dir>/../prelude/<file>   (dev tree: bin/manticore → repo/prelude)
 *   - <argv0_dir>/prelude/<file>      (flat install next to the binary)
 *   - <argv0_dir>/../lib/prelude/<file>, <argv0_dir>/lib/prelude/<file>  (shipped under lib)
 * Returns "" when absent / unreadable — the prelude is REQUIRED (there is no
 * embedded copy any more), so lower_module turns that into a clean compile error,
 * exactly as a missing `lib/manticore_stdlib.o` already does.
 */
/**
 * {@see find_prelude_src}, but never throws: the libc `argv`/`fopen` bindings are
 * throwing stubs under the Zend cold-seed. "" means the prelude module provides
 * nothing, so nothing demands it.
 */
function prelude_src_or_empty(string $file): string
{
    try {
        return find_prelude_src($file);
    } catch (\Throwable $e) {
        return "";
    }
}

function find_prelude_src(string $file): string
{
    $cands = [];
    $envDir = \getenv("MANTICORE_PRELUDE");
    if (\is_string($envDir) && $envDir !== "") {
        $cands[] = $envDir . "/" . $file;
    }
    // argv0-relative candidates. GUARDED: the libc `argv` binding + `cstr_to_str`
    // are absent under the Zend cold-seed (Call-to-undefined Error) — without the
    // catch the throw escapes before the env candidate is ever read, so a prelude
    // fn the compiler itself uses (explode) never injects into the seed. Under
    // Zend MANTICORE_PRELUDE (added above) is the resolution path.
    try {
        $rawSelf = argv(0);
        $self = \cstr_to_str($rawSelf);
        $slashAt = \strrpos($self, "/");
        if ($slashAt !== false && $slashAt >= 0) {
            $selfDir = \substr($self, 0, $slashAt);
            $cands[] = $selfDir . "/../prelude/" . $file;
            $cands[] = $selfDir . "/prelude/" . $file;
            $cands[] = $selfDir . "/../lib/prelude/" . $file;
            $cands[] = $selfDir . "/lib/prelude/" . $file;
        }
    } catch (\Throwable $e) {
        // Zend cold-seed — rely on MANTICORE_PRELUDE.
    }
    foreach ($cands as $path) {
        // `\file_get_contents` (global) works in BOTH worlds: PHP's builtin
        // under the Zend cold-seed (where the libc `read_file`/`file_exists`
        // bindings are empty stubs), and the stdlib implementation in the native
        // binary. Using the libc path here left the seed without any prelude fn
        // the compiler itself calls (explode) → `@manticore_explode` undefined.
        // ⚠ `@`, and it is load-bearing under the ZEND COLD SEED. A prelude file
        // that does not exist is normal — this loop probes four candidate
        // directories and `prelude_src_or_empty` treats "absent" as "not
        // demanded" — but php's file_get_contents WARNS on a missing path, and
        // php CLI warnings go to STDOUT, which here is the generated seed .ll.
        // clang then died on `2:1: error: expected top-level entity` pointing at
        // the warning text. The native build never saw it: its own
        // file_get_contents returns false silently.
        $src = @\file_get_contents($path);
        if ($src === false) { continue; }
        // Drop everything up to and including the opening `<?php` tag so the
        // remaining class source appends cleanly after the prelude's own header.
        $tag = \strpos($src, "<?php");
        if ($tag !== false) {
            $src = \substr($src, $tag + 5, \strlen($src) - ($tag + 5));
        }
        return $src;
    }
    return "";
}


/**
 * Write `$bytes` to `$path` via libc fopen/fwrite/fclose. Returns
 * true on success, false on any failure (open or write). Used to
 * stage IR for clang and the .o for cc.
 */
function write_file(string $path, string $bytes): bool {
    $fp = fopen($path, "wb");
    if ($fp === null) { return false; }
    $n = \strlen($bytes);
    $w = fwrite($bytes, 1, $n, $fp);
    fclose($fp);
    return $w === $n;
}

/**
 * `MANTICORE_DUMP_SOURCES=<path>` — write the compile unit's RESOLVED file list
 * and stop being the only thing that knows it.
 *
 * The manifest builder is what turns a manifest into a set of files: composer's
 * psr-4/psr-0/classmap roots, minus the manifest's excludes, minus composer's
 * own, minus the scripts that declare nothing, plus the entry. Nothing else can
 * reproduce that, which is exactly the problem when the build CRASHES: the one
 * tool that would name the site is the Zend front end
 * (`tools/compile_files_mir.php`, where a null against a typed parameter is a
 * TypeError with a stack trace instead of a SIGSEGV) and it takes a FILE LIST.
 * Reconstructing that list from the build log does NOT reproduce the build — it
 * misses the skips and the demand-loaded marking, so it fails on errors the real
 * build never sees.
 *
 * A demand-loaded path (declarations kept, top-level side effects dropped) is
 * written with a `D ` prefix so the driver can mark it the same way.
 *
 * @param string[] $paths
 */
function dump_resolved_sources(array $paths): void
{
    $out = \getenv("MANTICORE_DUMP_SOURCES");
    if ($out === false || $out === "") { return; }
    $buf = "";
    foreach ($paths as $p) {
        $norm = \rtrim($p, "/");
        $buf .= (isset(CompileArgs::$demandLoadedPaths[$norm]) ? "D " : "  ") . $p . "\n";
    }
    if (!write_file($out, $buf)) {
        dprint("build: could not write MANTICORE_DUMP_SOURCES to " . $out);
        return;
    }
    dprint("build: resolved " . (string)\count($paths) . " source file(s) -> " . $out);
}

/**
 * Heterogeneous return values across `assoc<string, mixed>` get
 * flattened to i64 by the self-host compiler today, so we stash the
 * parsed argv into typed static class properties instead of building
 * a return struct. Ugly but unambiguous.
 */
final class CompileArgs
{
    public static string $output = 'a.out';

    /** @var string[] */
    public static array $files = [];

    /**
     * Memory mode from `--memory=<rc|arena|hybrid>`. Empty string means
     * the flag wasn't passed; the compiler falls back to env-var or
     * the `rc` default in {@see \Compile\Debug}.
     */
    public static string $memory = '';

    /**
     * Backend selection. Empty (default) and `mir` route through the
     * MIR pipeline + EmitLlvm — now the reference path. `ast` selects
     * the legacy AST Compiler, kept as a fallback.
     */
    public static string $backend = '';

    /**
     * `dump-mir --prelude` includes the built-in Throwable / Exception
     * hierarchy in the dump. Off by default so golden snapshots stay
     * focused on user code.
     */
    public static bool $dumpPrelude = false;

    /**
     * `dump-mir --effects` annotates each op with its inferred memory
     * effect set and prints the per-function aggregate.
     */
    public static bool $dumpEffects = false;

    /**
     * clang optimization level for the emitted binary, set by `-O<level>`
     * (`0 1 2 3 s z`). Default `2` — optimized output. Use `-O0` for
     * readable/debuggable codegen (faster compile, no inlining/reordering).
     */
    public static string $optLevel = '2';

    /**
     * `--keep-ir` — write the intermediate `.ll`/`.o` next to the target
     * (`<output>.dbg.ll` / `<output>.dbg.o`) and leave them there instead of
     * staging them under a pid-derived /tmp path and deleting them. The one
     * way to read the IR of a manifest build; pairs with `-O0` for a binary
     * lldb can actually walk.
     */
    public static bool $keepIr = false;

    /**
     * `-j<n>` — assemble the module as `n` independent objects through that many
     * concurrent `clang` processes ({@see \Compile\Mir\SplitModule}). `-j0`
     * picks from the host's core count. Unset means 1: ONE object, exactly what
     * every build did before.
     *
     * ⚠ OPT-IN ON PURPOSE, and the default must stay 1. `clang -O2` is the
     * largest single term in a build (64% of a user compile, ~49% of
     * `bin/build`) and is single threaded, so splitting is a large BUILD-TIME
     * win — examples/http/hello.php 4.6 s -> 2.0 s. But a part boundary is an
     * inlining boundary, and that costs the PRODUCED program: the compiler
     * built as 8 parts runs 43% slower than the same source built as one object
     * (hello_world 260 ms -> 372 ms), which then makes every later build slower
     * in turn. A microbenchmark misses this — fib.php measured 111.7 vs 110.5 ms
     * — because its hot loop is self-contained; a large program lives on
     * cross-module inlining.
     *
     * So: use it while iterating on a program you are about to run once, never
     * for an artifact whose own speed matters. `bin/build` deliberately does
     * not pass it.
     */
    public static int $jobs = 1;

    /**
     * `--emit-library` — build the bundled stdlib as a standalone `.o`
     * (no `@main`, no stdlib linking). Used by bin/compile / bin/build to
     * produce `lib/manticore_stdlib.o` once after the compiler is built.
     */
    public static bool $emitLibrary = false;

    /**
     * `--allow-undefined-traps` — permit a LIBRARY target to ship calls the
     * emitter compiled into a "Call to undefined function" throw
     * ({@see \Compile\Mir\Passes\EmitLlvm::$undefinedCalls}). Refused by
     * default: a prebuilt `.o` outlives the build that made it, so a throw stub
     * baked into `lib/manticore_stdlib.o` keeps failing long after the source is
     * fixed — the exact way a missed cold seed used to hide for a generation.
     *
     * The opt-out exists for the legitimate case: a library whose call sits
     * behind an `extension_loaded()` guard that never runs.
     */
    public static bool $allowUndefinedTraps = false;

    /**
     * Set by {@see compile_via_mir} when at least one bundled-stdlib extern
     * was injected into the module → cmd_compile links the prebuilt
     * `stdlib.o` at the cc step. Stays false for self-contained programs
     * (the compiler's own source defines the stdlib) so no duplicate symbols.
     */
    public static bool $linkStdlib = false;

    /**
     * Native libraries this module's `#[Ffi\Library]` bindings need, captured
     * from {@see \Compile\Mir\Passes\EmitLlvm::$ffiLibs} right after emission
     * and resolved to `cc` tokens by {@see ffi_link_flags} at the link step.
     * Mirrors how `$linkStdlib` rides from the front end to the linker.
     * @var string[]
     */
    public static array $ffiLibs = [];

    /**
     * C symbols this module declared `extern_weak`, captured from
     * {@see \Compile\Mir\Passes\EmitLlvm::$weakSyms}. Drives Darwin's
     * `-Wl,-U,_<sym>` allowance so it cannot drift from the bindings.
     * @var string[]
     */
    public static array $weakSyms = [];

    /**
     * Bundled-stdlib signatures for declare-only extern injection, collected
     * by {@see cmd_compile} (native path, where the libc file bindings work)
     * and consumed by {@see compile_via_mir}. NOT collected inside
     * compile_via_mir: that path also runs under the Zend bootstrap build
     * (`tools/compile_files_mir.php`), where `fopen` is a typed stub that
     * throws — so collection must stay on the native CLI path only.
     * @var \Parser\Ast\FunctionDecl[]
     */
    public static array $externDecls = [];

    /**
     * The refusal from the last `.sig` compatibility check, or "" when every
     * imported interface was accepted. {@see Sig::validateImport} has no error
     * channel of its own because its callers ({@see collect_stdlib_extern_decls},
     * the `build` library loop) return decl LISTS — and an empty list from a
     * rejected stdlib interface would read as "no stdlib", silently producing a
     * program that cannot resolve `strlen`. The build tail checks this instead.
     */
    public static string $sigError = '';

    /**
     * Type declarations imported from dependency `.sig`s, hydrated into
     * synthetic AST and handed to the lowering exactly as {@see $externDecls}
     * is for functions.
     * @var \Parser\Ast\ClassDecl[]
     */
    public static array $externClassDecls = [];

    /** @var array<string, \Compile\Mir\ExternClassMeta> */
    public static array $externClassMeta = [];

    /**
     * Paths composer autoloads ON DEMAND (psr-4 / psr-0 / classmap) rather than
     * `require`ing at bootstrap. php reads such a file only when a class lookup
     * resolves to it, so its top-level SIDE EFFECTS run then — or, for a class
     * nothing ever names, never at all.
     *
     * The eager whole-program model has no "then": {@see lower_module} flattens
     * every file's top level into one `__main`, which runs the lot at startup.
     * Only DECLARATIONS are hoisted from a path in this set ({@see
     * __mc_stmt_declares}); pure side effects are dropped.
     *
     * @var array<string,bool>
     */
    public static array $demandLoadedPaths = [];

    /** @var array<string, \Parser\Ast\Expr> */
    public static array $externConstants = [];

    /**
     * Whether the library being built exports its TYPES, not just its
     * functions. False for a `runtime: true` library — the bundled stdlib.
     *
     * The stdlib's classes are internal (`Runtime\Json\Parser`,
     * `Runtime\AsyncHook`) or compiler-owned (`stdClass`, which every module
     * registers for itself), and its `.o` is linked into every program rather
     * than selected as a dependency. Exporting them would hand each program a
     * second definition of a class it already holds. The class-shaped stdlib
     * surface stays where it is — in the prelude.
     */
    public static bool $exportTypes = true;
}

/**
 * Walk argv tail, pulling out -o <path> into CompileArgs::$output
 * and positional args into CompileArgs::$files. Returns true on
 * success, false on any unknown flag.
 *
 * @param string[] $args
 */
/**
 * The compile/dump option spec, shared by every command that takes them:
 *   -o <out> · --memory=<rc|arena|hybrid> · --backend=<mir|ast> · -O<level>
 *   --prelude · --effects · --emit-library · --keep-ir
 * All value forms (`-o out`, `-O2`, `--memory=rc`, `--memory rc`) are accepted;
 * positionals (files) may appear in any position.
 *
 * @return array<string, string>
 */
function compile_arg_spec(): array {
    return [
        "o" => \Cli\ArgParse::VALUE,
        "memory" => \Cli\ArgParse::VALUE,
        "backend" => \Cli\ArgParse::VALUE,
        "O" => \Cli\ArgParse::VALUE,
        "prelude" => \Cli\ArgParse::FLAG,
        "effects" => \Cli\ArgParse::FLAG,
        "emit-library" => \Cli\ArgParse::FLAG,
        "keep-ir" => \Cli\ArgParse::FLAG,
        "j" => \Cli\ArgParse::VALUE,
    ];
}

/** Apply a parsed compile option set onto {@see CompileArgs}. false on a bad value. */
function apply_compile_args(\Cli\ParsedArgs $p): bool {
    $optLevel = $p->value("O", "2");
    if ($optLevel !== "0" && $optLevel !== "1" && $optLevel !== "2" && $optLevel !== "3"
        && $optLevel !== "s" && $optLevel !== "z") {
        dprint("unknown -O level: " . $optLevel . " (expected 0|1|2|3|s|z)");
        return false;
    }
    $memory = $p->value("memory", "");
    CompileArgs::$output = $p->value("o", "a.out");
    CompileArgs::$files = $p->positional;
    CompileArgs::$memory = $memory;
    CompileArgs::$backend = $p->value("backend", "");
    CompileArgs::$optLevel = $optLevel;
    CompileArgs::$dumpPrelude = $p->flag("prelude");
    CompileArgs::$dumpEffects = $p->flag("effects");
    if ($p->flag("emit-library")) { CompileArgs::$emitLibrary = true; }
    if ($p->flag("keep-ir")) { CompileArgs::$keepIr = true; }
    $jobs = $p->value("j", "");
    if ($jobs !== "") {
        $jn = (int)$jobs;
        if ($jn < 0) {
            dprint("-j must be >= 0 (got " . $jobs . "); 0 = one job per core");
            return false;
        }
        CompileArgs::$jobs = $jn;
    }
    if (\strlen($memory) > 0) {
        if (!\Compile\Debug::applyMemoryMode($memory)) {
            dprint("unknown --memory value: " . $memory . " (expected rc|arena|hybrid)");
            return false;
        }
    }
    return true;
}

function parse_compile_args(array $args): bool {
    $p = \Cli\ArgParse::parse($args, compile_arg_spec());
    if ($p->error !== null) { dprint($p->error); return false; }
    return apply_compile_args($p);
}

/**
 * Resolve the source list for a `compile` / `dump-llvm` invocation:
 *
 *   - explicit files on argv → read each from disk in order
 *   - a directory arg → recursive `*.php` scan of it
 *   - otherwise → read stdin
 *
 * Returns the source list (one entry per file, plus discovered
 * directory files) or null on IO error.
 *
 * @param string[] $files
 * @return string[]|null
 */
function resolve_sources(array $files): ?array {
    if (\count($files) > 0) {
        /** @var string[] $out */
        $out = [];
        foreach ($files as $path) {
            // Directory arg → recursive *.php scan (the simple
            // `manticore compile src/` ergonomics). Multi-target
            // projects use the `manticore.json` manifest via `build`.
            if (is_directory($path)) {
                // Inline the recursive enumeration — going through
                // `directory_php_files()` loses the `string[]` element
                // type across the call boundary in self-host
                // pre-scan, surfacing as `fopen failed: P` (the first
                // byte of the path is read as a single-char string).
                $listPath = "/tmp/manticore_files_" . (string)getpid() . ".txt";
                system("find " . $path . " -name '*.php' -type f 2>/dev/null | sort > " . $listPath);
                $listContents = read_file($listPath);
                if ($listContents !== null) {
                    foreach (\explode("\n", $listContents) as $file) {
                        if (\strlen($file) === 0) { continue; }
                        $fileSrc = read_file($file);
                        if ($fileSrc === null) { return null; }
                        $out[] = $fileSrc;
                    }
                }
                continue;
            }
            $src = read_file($path);
            if ($src === null) { return null; }
            $out[] = $src;
        }
        return $out;
    }
    $stdin = read_stdin_source();
    if (\strlen($stdin) === 0) {
        dprint("no input: pass file(s) or a directory, or pipe to stdin");
        return null;
    }
    return [$stdin];
}

/**
 * Like {@see resolve_sources}, but keeps each file's PATH alongside its
 * contents so a diagnostic can point at the file the user actually named.
 * Returns a `SourceFile[]` (a typed-field object per file — an
 * `array<path,contents>` loses its element types across the self-host
 * boundary, same reason `resolve_sources` returns a flat `string[]`). null on
 * an IO error, `[]` when nothing was read.
 *
 * @param string[] $files
 * @return \Analyze\SourceFile[]|null
 */
function resolve_source_files(array $files): ?array {
    /** @var \Analyze\SourceFile[] $out */
    $out = [];
    if (\count($files) > 0) {
        foreach ($files as $path) {
            if (is_directory($path)) {
                $listPath = "/tmp/manticore_afiles_" . (string)getpid() . ".txt";
                system("find " . $path . " -name '*.php' -type f 2>/dev/null | sort > " . $listPath);
                $listContents = read_file($listPath);
                if ($listContents !== null) {
                    foreach (\explode("\n", $listContents) as $file) {
                        if (\strlen($file) === 0) { continue; }
                        $fileSrc = read_file($file);
                        if ($fileSrc === null) { return null; }
                        $out[] = new \Analyze\SourceFile($file, $fileSrc);
                    }
                }
                continue;
            }
            $src = read_file($path);
            if ($src === null) { return null; }
            $out[] = new \Analyze\SourceFile($path, $src);
        }
        return $out;
    }
    $stdin = read_stdin_source();
    if (\strlen($stdin) === 0) {
        dprint("no input: pass file(s) or a directory, or pipe to stdin");
        return null;
    }
    $out[] = new \Analyze\SourceFile("<stdin>", $stdin);
    return $out;
}

/**
 * Full compile-to-binary pipeline. Writes IR to a per-pid temp file,
 * shells out to clang for IR→object, then cc for object→executable.
 * Default output is `a.out` in the cwd (mirrors `cc` defaults).
 * Works the same on Linux and macOS because both ship clang+cc and
 * the IR is target-triple-agnostic at our current emit level.
 *
 * @param string[] $args
 */
/**
 * Front-end entry: parse + lower + emit via the MIR pipeline. The sole
 * backend (the legacy AST Compiler was removed — MIR is self-hosting).
 *
 * @param string[] $sources
 * @param string[] $paths   parallel to $sources; used for diagnostics only
 */
function compile_with_backend(array $sources, array $paths = []): ?string {
    return compile_via_mir($sources, $paths);
}

function cmd_compile(array $args): int {
    // The static analyzer runs by DEFAULT over the sources and prints its
    // diagnostics to stderr — advisory, NEVER fatal (the build proceeds
    // regardless). `--no-analyze` turns it off; `--analyze` is the (now default)
    // explicit opt-in. Uses the fast AST engine, not the heavy `--deep` lowering.
    $spec = compile_arg_spec();
    $spec["analyze"] = \Cli\ArgParse::FLAG;
    $spec["no-analyze"] = \Cli\ArgParse::FLAG;
    $spec["analyze-strict"] = \Cli\ArgParse::FLAG;
    $p = \Cli\ArgParse::parse($args, $spec);
    if ($p->error !== null) {
        dprint("compile: " . $p->error . " (rc=64)");
        return 64;
    }
    if (!apply_compile_args($p)) {
        dprint("compile: failed to parse args (rc=64)");
        return 64;
    }
    $analyze = !$p->flag("no-analyze");
    $strict = $p->flag("analyze-strict");
    $output = CompileArgs::$output;

    // Read once, as SourceFile[] (path + contents), so every downstream stage
    // — the analyzer AND the front-end's `parse failed` diagnostics — can name
    // the specific file that broke. Previously we ran resolve_sources() and
    // resolve_source_files() back-to-back and lost the paths on the compile
    // path, so a `bin/manticore compile manticore.json` (or one bad file inside
    // a directory arg) printed `parse failed: expected ';' after expression`
    // with no filename.
    $afiles = resolve_source_files(CompileArgs::$files);
    if ($afiles === null) {
        dprint("compile: source resolution failed — no input read (rc=66)");
        return 66;
    }
    if (\count($afiles) === 0) {
        dprint("compile: source list is empty (rc=66)");
        return 66;
    }
    /** @var string[] $sources */
    $sources = [];
    /** @var string[] $paths */
    $paths = [];
    foreach ($afiles as $sf) { $sources[] = $sf->contents; $paths[] = $sf->path; }

    if ($analyze) {
        // Advisory by default: any failure inside the analyzer is swallowed so it
        // can NEVER break a build. `--no-analyze` is the escape hatch. With
        // `--analyze-strict`, error-severity findings instead FAIL the compile
        // (rc=65, before codegen) — a lint gate for CI.
        try {
            if (\count($afiles) > 0) {
                $adiags = perform_analysis($afiles, CompileArgs::$files, false);
                if (\count($adiags) > 0) { \error_log("\n" . \Analyze\Report::human($adiags)); }
                if ($strict) {
                    $errs = 0;
                    foreach ($adiags as $d) {
                        if ($d->severity === \Analyze\Diagnostic::SEV_ERROR) { $errs = $errs + 1; }
                    }
                    if ($errs > 0) {
                        dprint("compile: analysis found " . (string)$errs . " error(s) (--analyze-strict, rc=65)");
                        return 65;
                    }
                }
            }
        } catch (\Throwable $e) {
            dprint("analyze: skipped (internal error: " . $e->getMessage() . ")");
        }
    }
    // Collect bundled-stdlib signatures here (native path: the libc file
    // bindings resolve to real syscalls). compile_via_mir consumes them via
    // the static. Skipped when building stdlib.o itself.
    if (!CompileArgs::$emitLibrary) {
        $sigT = \Compile\Stats::now();
        CompileArgs::$externDecls = collect_stdlib_extern_decls();
        \Compile\Stats::step('stdlib .sig -> extern decls', $sigT,
            \count(CompileArgs::$externDecls), -1);
        if (CompileArgs::$sigError !== '') {
            dprint(CompileArgs::$sigError);
            return 65;
        }
    }
    // NOTE: the bundled PHP stdlib is NOT prepended here. Merging the whole
    // stdlib into every user program both bloats output and crashes the
    // compiler on some stdlib+user combinations (the "stdlib as guest"
    // hazard). The chosen design is a prebuilt stdlib.o linked at the cc step
    // (see discover_stdlib_files / the link tail) — built once, in isolation.
    $ir = compile_with_backend($sources, $paths);
    if ($ir === null) {
        dprint("compile: front-end (parse/typeck/IR) returned null (rc=65)");
        return 65;
    }
    if (\strlen($ir) === 0) {
        dprint("compile: front-end produced empty IR (rc=65)");
        return 65;
    }

    // Staging path for the IR and the intermediate object — the same contract
    // {@see build_target} uses: under --keep-ir they sit next to the target
    // (stable, one per target, never swept from /tmp), otherwise a pid-derived
    // /tmp base that is removed once the target links. Staged files are
    // deliberately LEFT BEHIND on failure: they are the only record of what the
    // compiler emitted for a build that did not finish, and every failure path
    // below names the path it kept.
    //
    // ⚠ These used to leak unconditionally — `compile` wrote /tmp/manticore_<pid>.ll
    // and never removed it, so every compile since the tool existed left its
    // whole module behind. One dev machine had accumulated 56 970 files / 43 GB.
    // `build_target` always cleaned up; only this path did not.
    $keep = CompileArgs::$keepIr;
    $base = $keep ? ($output . ".dbg") : ("/tmp/manticore_" . (string)getpid());
    $llPath = $base . ".ll";
    $objPath = $base . ".o";

    // Library build: assemble straight to the output .o, no link, and NEVER
    // split — `stdlib.o` is one object by contract (its `.sig` describes it, and
    // every consumer links exactly that file). The runtime preamble helpers are
    // linkonce_odr so it coexists with a user program's preamble at link time.
    if (CompileArgs::$emitLibrary) {
        if (!write_file($llPath, $ir)) {
            dprint("compile: cannot write " . $llPath . " (rc=73)");
            return 73;
        }
        if ($keep) { dprint("compile: kept IR " . $llPath); }
        $rcLib = system("clang -O" . CompileArgs::$optLevel . " -c -x ir " . $llPath . " -o " . $output . " -Wno-override-module");
        if ($rcLib !== 0) {
            dprint("compile: clang -c (library) failed (rc=" . (string)$rcLib . "); IR at " . $llPath);
            return 75;
        }
        if (!$keep) { system("rm -f " . $llPath); }
        return 0;
    }

    // clang understands `-x ir` for plain LLVM textual IR. cc on both
    // Linux and macOS picks the system linker plus libc by default.
    // `-ffunction-sections` puts each function in its own section so the
    // link-time dead-strip below drops every unreferenced stdlib function
    // (a hello-world no longer drags in all of Json/Libc/stdlib).
    // Errors on stderr already, but surface our own rc too.
    $objs = assemble_ir($ir, $base, "-ffunction-sections -fdata-sections");
    if ($objs === []) { return 75; }
    if ($keep) { dprint("compile: kept IR " . $base . ".*.ll"); }
    $objList = \implode(" ", $objs);
    // Link the prebuilt stdlib.o only when the program imported a bundled
    // stdlib function (str_starts_with / ctype_* / file_*). A self-contained
    // program (e.g. the compiler's own source, which defines the stdlib)
    // links without it — avoids duplicate symbols.
    $linkExtra = "";
    // Native libraries this module's own `#[Ffi\Library]` bindings named.
    $libs = CompileArgs::$ffiLibs;
    $weak = CompileArgs::$weakSyms;
    if (CompileArgs::$linkStdlib) {
        $stdlibObj = find_stdlib_object();
        if ($stdlibObj !== "") {
            $linkExtra = " " . $stdlibObj;
        } else {
            dprint("compile: program uses bundled stdlib but stdlib.o not found — link may fail");
        }
        // The bundled stdlib's own bindings — its preg_* wrappers reference
        // libpcre2-8, its TLS transport libssl/libcrypto, its iconv_* wrappers
        // libiconv (on Darwin; glibc has it inside libc), its scheduler
        // signalfd — none of which this module names. They ride in the stdlib's
        // `.sig`, since the wrapper that calls them is emitted over there.
        // Dead-strip + --as-needed drop whichever the program never reaches.
        foreach (stdlib_sig_list("libs",
                 ["pcre2-8", "ssl", "crypto", "iconv"]) as $l) {
            $libs[] = $l;
        }
        foreach (stdlib_sig_list("weak",
                 ["__errno_location", "epoll_create1", "epoll_ctl", "epoll_wait",
                  "signalfd"]) as $w) {
            $weak[] = $w;
        }
    }
    $libFlags = ffi_link_flags($libs);
    if ($libFlags !== "") { $linkExtra .= $libFlags; }
    // Dead-strip unreferenced functions at link time — the prebuilt stdlib.o
    // is one object (linked wholesale), so without this a tiny program carries
    // the entire stdlib (~75 KB hello-world). macOS ld64 strips at the
    // symbol/atom level (`-dead_strip`); GNU/lld need section GC (`--gc-sections`,
    // paired with the `-ffunction-sections` above). `-dead_strip_dylibs` /
    // `--as-needed` drop a linked-but-unreferenced dylib (e.g. libpcre2 in a
    // program that uses the stdlib but no preg_*).
    //
    // Every extern_weak the module emitted is a weak-undefined ld64 rejects
    // unless `-U <sym>` permits it: errno's per-host accessor (__mc_errno
    // declares BOTH __error and __errno_location, null-tests, and calls
    // whichever is present), plus Io\Poll's epoll backend and the scheduler's
    // signalfd, which bind Linux-only symbols `#[Ffi\Weak]`. Each binds to 0 on
    // the host that lacks it and the guarded branch never calls it.
    //
    // The set is DERIVED — it used to be a hand-written list sitting beside the
    // bindings it duplicated, free to drift the moment someone added a weak one.
    // Demand-gating falls out of that: a program that pulls in no weak binding
    // now gets no -U flags at all.
    //
    // GNU ld auto-binds weak-undefined to 0, so Linux needs none of this.
    // -lm: libm is folded into libSystem on Darwin (auto-linked) but is a
    // separate archive on glibc/musl — a program calling tanh/sinh/pow/fmod
    // (non-intrinsic libm fns the compiler lowers to plain calls) links with an
    // undefined reference without it. `--as-needed` drops it when unreferenced.
    $gc = is_darwin()
        ? " -Wl,-dead_strip -Wl,-dead_strip_dylibs" . weak_undef_flags($weak)
        : " -Wl,--gc-sections -Wl,--as-needed -lm";
    $rc2 = system("cc " . $objList . $linkExtra . $gc . " -o " . $output);
    if ($rc2 !== 0) {
        dprint("compile: cc link failed (rc=" . (string)$rc2 . "); objects at " . $objList);
        return 76;
    }
    // Linked: the staged IR and objects have no further use. Kept under
    // --keep-ir, which is the escape hatch for reading what was emitted.
    if (!$keep) {
        system("rm -f " . $llPath . " " . $objPath . " " . $objList . " " . $base . ".p*.ll");
    }
    return 0;
}

/**
 * Host OS sysname ("Darwin" / "Linux").
 *
 * Goes through `php_uname()`, the ONE primitive that exists in BOTH worlds: php's
 * own builtin while the compiler runs under the Zend cold seed, and the stdlib's
 * uname(2) wrapper in a native build. The calloc+uname FFI pair this used to call
 * is a STUB under Zend (an empty body with a `: \Ffi\Ptr` return type), so any
 * emitter reaching it killed `bin/compile` with
 * "Manticore\calloc(): Return value must be of type Ffi\Ptr, none returned".
 *
 * That stopped being hypothetical when the compiler's own source began demanding
 * fibers: `src/Runtime/AsyncHook.php` mentions `\Fiber`, so the demand gate turns
 * fibers on for the compiler itself, and the fiber preamble needs the host arch.
 * Routing through php_uname removes the hazard class — and with it the old
 * "never call host_os() from an emitter" rule.
 */
function host_os(): string {
    return \php_uname('s');
}

/**
 * Host CPU arch, normalized to the codegen names ("arm64" / "x86_64").
 *
 * Same both-worlds reasoning as {@see host_os()} — and this one mattered more: it
 * hand-walked utsname with an OS-divergent field stride (Darwin's _SYS_NAMELEN 256
 * vs glibc/musl's _UTSNAME_LENGTH 65) to reach `machine`. php_uname('m') answers
 * that directly, under Zend and natively.
 *
 * The compile-time host==target assumption stands: the arch the compiler runs on IS
 * the arch it emits for (no cross-compile yet).
 */
function host_arch(): string {
    $machine = \php_uname('m');
    if ($machine === 'arm64' || $machine === 'aarch64') { return 'arm64'; }
    if ($machine === 'x86_64' || $machine === 'amd64') { return 'x86_64'; }
    return $machine;
}

/**
 * Compile-time target OS family, one of "Darwin" / "Linux" (else the raw
 * sysname). The single source emitters branch on instead of re-deriving it
 * from host_os() at each site. Same host==target assumption as host_os().
 */
function target_os_family(): string {
    $os = host_os();
    return \substr($os, 0, 6) === 'Darwin' ? 'Darwin'
        : (\substr($os, 0, 5) === 'Linux' ? 'Linux' : $os);
}

/** True when the compile target is Darwin/macOS. */
function is_darwin(): bool {
    return \substr(host_os(), 0, 6) === 'Darwin';
}

// ── manticore.json manifest build (cargo-like targets) ────────────────────

/**
 * Recursively collect `*.php` under `$dir`, skipping any path that begins
 * with one of `$excludes`. Returns the file CONTENTS (compile order: find +
 * sort, so a `zzz_*` entry file lands last). IO errors on a single file drop
 * it.
 *
 * @param string[] $excludes
 * @return string[]
 */
/**
 * Prefix-match `$path` against a manifest `exclude` entry, with a leading `./`
 * meaning nothing on either side.
 *
 * The normalisation is the whole point. A VENDOR package's autoload root
 * arrives as `./vendor/name/src` — its base is `./vendor/name` — but the
 * PROJECT's own root arrives as bare `src`, because {@see composer_path_join}
 * drops a `.` base entirely. So `"exclude": ["./src"]` silently matched nothing
 * while the identical spelling worked for every vendor package, and a directory
 * the manifest plainly excluded was compiled anyway.
 */
function exclude_matches(string $path, string $ex): bool
{
    if (\strlen($ex) === 0) { return false; }
    $p = \str_starts_with($path, "./") ? \substr($path, 2) : $path;
    $e = \str_starts_with($ex, "./") ? \substr($ex, 2) : $ex;
    if (\strlen($e) === 0) { return false; }
    return \str_starts_with($p, $e);
}

function collect_php_sources(string $dir, array $excludes): array
{
    /** @var string[] $out */
    $out = [];
    $listPath = "/tmp/manticore_build_" . (string)getpid() . ".txt";
    system("find " . $dir . " -name '*.php' -type f 2>/dev/null | sort > " . $listPath);
    $contents = read_file($listPath);
    if ($contents === null) { return $out; }
    foreach (\explode("\n", $contents) as $path) {
        if (\strlen($path) === 0) { continue; }
        $skip = false;
        foreach ($excludes as $ex) {
            if (exclude_matches($path, $ex)) { $skip = true; break; }
        }
        if ($skip) { continue; }
        $src = read_file($path);
        if ($src !== null) { $out[] = $src; }
    }
    return $out;
}

/**
 * Same as {@see collect_php_sources}, but returns each file's PATH alongside
 * its contents so a diagnostic (`parse failed` at line/col) can name the real
 * file. Kept parallel rather than replacing the plain-`string[]` version
 * because the self-host boundary loses the element types across a mixed
 * `array<path,contents>` shape — hence a typed `SourceFile[]`.
 *
 * @param string[] $excludes
 * @return \Analyze\SourceFile[]
 */
function collect_php_source_files(string $dir, array $excludes): array
{
    /** @var \Analyze\SourceFile[] $out */
    $out = [];
    $listPath = "/tmp/manticore_buildf_" . (string)getpid() . ".txt";
    system("find " . $dir . " -name '*.php' -type f 2>/dev/null | sort > " . $listPath);
    $contents = read_file($listPath);
    if ($contents === null) { return $out; }
    foreach (\explode("\n", $contents) as $path) {
        if (\strlen($path) === 0) { continue; }
        $skip = false;
        foreach ($excludes as $ex) {
            if (exclude_matches($path, $ex)) { $skip = true; break; }
        }
        if ($skip) { continue; }
        $src = read_file($path);
        if ($src !== null) { $out[] = new \Analyze\SourceFile($path, $src); }
    }
    return $out;
}

/**
 * Join a composer base dir and a relative autoload path into a scan dir.
 * A "." / "" base collapses to the relative path; trailing slashes trimmed.
 */
function composer_path_join(string $base, string $rel): string
{
    $b = \rtrim($base, "/");
    $r = \rtrim($rel, "/");
    if ($b === "" || $b === ".") { return $r === "" ? "." : $r; }
    if ($r === "") { return $b; }
    return $b . "/" . $r;
}

/**
 * The sources named by one composer `autoload` block — psr-4 / psr-0 roots,
 * classmap entries, and `files` entries — each prefixed with `$base`.
 *
 * `files` entries used to be skipped, on the reasoning that their side effects
 * have no analogue in a compiled program. That reasoning missed what they are
 * mostly FOR: a `files` entry typically exists to DECLARE something that has no
 * class to autoload. symfony/deprecation-contracts is exactly one function in
 * exactly one `files` entry, and skipping it left every
 * `trigger_deprecation(...)` call in the dependency tree undefined at link time.
 * Whole-program AOT needs the declaration, so the file is compiled like any
 * other source.
 *
 * A returned path may be a FILE rather than a directory; collect_php_sources
 * handles both (its `find` accepts either).
 *
 * @param array<string,mixed> $autoload
 * @return string[]
 */
function composer_autoload_dirs(array $autoload, string $base): array
{
    /** @var string[] $out */
    $out = [];
    foreach (["psr-4", "psr-0"] as $key) {
        $map = isset($autoload[$key]) ? $autoload[$key] : [];
        foreach ($map as $paths) {
            if (\is_array($paths)) {
                foreach ($paths as $p) { $out[] = composer_path_join($base, (string)$p); }
            } else {
                $out[] = composer_path_join($base, (string)$paths);
            }
        }
    }
    $cm = isset($autoload["classmap"]) ? $autoload["classmap"] : [];
    foreach ($cm as $p) {
        $out[] = composer_path_join($base, (string)$p);
    }
    $fl = isset($autoload["files"]) ? $autoload["files"] : [];
    foreach ($fl as $p) {
        $out[] = composer_path_join($base, (string)$p);
    }
    return $out;
}

/**
 * The `autoload.files` entries of a composer project, as a path set. These are
 * composer's BOOTSTRAP files: it `require`s each one on every request, so their
 * top-level statements genuinely run at program start.
 *
 * Every other autoload mechanism is DEMAND-driven — psr-4/psr-0 map a class
 * NAME to a file and classmap maps a declared symbol to one, so such a file is
 * read only when a class lookup resolves to it, and never otherwise.
 *
 * @return array<string,bool>
 */
function composer_autoload_file_entries(string $projRoot, bool $withVendor): array
{
    /** @var array<string,bool> $out */
    $out = [];
    $add = function (array $autoload, string $base) use (&$out): void {
        $fl = isset($autoload["files"]) ? $autoload["files"] : [];
        if (!\is_array($fl)) { return; }
        foreach ($fl as $p) {
            $out[\rtrim(composer_path_join($base, (string)$p), "/")] = true;
        }
    };
    $cjPath = $projRoot . "/composer.json";
    $cjSrc = file_exists($cjPath) ? read_file($cjPath) : null;
    if ($cjSrc !== null) {
        $cj = json_decode($cjSrc, true);
        if (\is_array($cj) && isset($cj["autoload"]) && \is_array($cj["autoload"])) {
            $add($cj["autoload"], $projRoot);
        }
    }
    if ($withVendor) {
        $lockPath = $projRoot . "/composer.lock";
        $lockSrc = file_exists($lockPath) ? read_file($lockPath) : null;
        if ($lockSrc !== null) {
            $lock = json_decode($lockSrc, true);
            $pkgs = (\is_array($lock) && isset($lock["packages"])) ? $lock["packages"] : [];
            foreach ($pkgs as $pkg) {
                if (!\is_array($pkg) || !isset($pkg["name"])) { continue; }
                if (isset($pkg["autoload"]) && \is_array($pkg["autoload"])) {
                    $add($pkg["autoload"], $projRoot . "/vendor/" . (string)$pkg["name"]);
                }
            }
        }
    }
    return $out;
}

/**
 * Could `$src` ever be reached by a DEMAND-driven autoload rule? Only if it
 * declares something a lookup can name. A file under a psr-4 root that declares
 * nothing at all is dead weight under php — composer resolves a class name to a
 * path, finds no such class, and nothing else ever reads the file.
 *
 * Compiling one is not merely wasteful, it is WRONG: `lower_module` flattens
 * every file's top level into `__main`, so a standalone script shipped inside a
 * package root RUNS AT STARTUP. symfony/expression-language ships
 * `Resources/bin/generate_operator_regex.php` under a psr-4 root mapped to `""`
 * — a CLI script ending in `echo '/'.implode('|', $regex).'/A';`. It executed
 * before the program's own entry, and its `$operator[$len - 1]` was the SIGSEGV
 * that stopped tier 2.
 *
 * Deliberately CONSERVATIVE: any occurrence of a declaration keyword anywhere in
 * the file — even in a comment or a closure — keeps the file. A false "keeps it"
 * costs a little compile time; a false "drops it" would lose a declaration, and
 * a version-guarded `if (…) { class X {} }` must never be mistaken for a script.
 */
function __mc_source_may_declare(string $src): bool
{
    return \preg_match('/\b(class|interface|trait|enum|function)\b/i', $src) === 1;
}

/**
 * Does this top-level statement DECLARE something, at any depth?
 *
 * The keep/drop test for a demand-loaded file ({@see CompileArgs::$demandLoadedPaths}).
 * Declarations must survive — the whole point of compiling the file — while a
 * pure side effect must not, because php would only ever run it at the moment
 * a class lookup pulled the file in.
 *
 * ★ The recursion is what makes this safe. A version guard wrapping a class
 * (`if (\PHP_VERSION_ID < 80000) { class Foo {} }`) is an `if`, and dropping
 * `if`s wholesale would silently delete the class — so the test is "contains a
 * declaration", never "is a declaration". The shape this DOES drop is the
 * dependency guard symfony writes at the top of a class file:
 *
 *     if (!interface_exists(LocaleAwareInterface::class)) { throw new \LogicException(…); }
 *
 * Seven files in the pinned corpus carry one. Compiled eagerly they threw
 * before the program's own entry ran — symfony/string's AsciiSlugger is what
 * stopped tier 2 once the SIGSEGV ahead of it was fixed.
 *
 * ⚠ A top-level `class_alias()` in a psr-4 file is a side effect and IS
 * dropped. Recorded rather than special-cased: the alias would have to become a
 * compile-time class synonym, which is its own piece of work.
 */
function __mc_stmt_declares(\Parser\Ast\Stmt $s): bool
{
    $k = $s->kind;
    if ($k === 'Class' || $k === 'Function' || $k === 'UseDecl'
        || $k === 'Namespace' || $k === 'StaticLocal' || $k === 'Label') {
        return true;
    }
    // A `const X = …;` at file scope is a declaration too. The parser models it
    // as an ExpressionStmt only for `define()`, which is a CALL — and a call is
    // exactly the side effect this drops, matching php: an unloaded file's
    // define() never runs either.
    foreach (__mc_stmt_children($s) as $c) {
        if (__mc_stmt_declares($c)) { return true; }
    }
    return false;
}

/**
 * Nested STATEMENTS of `$s` (not expressions) — enough for {@see
 * __mc_stmt_declares} to find a declaration wrapped in any control flow.
 *
 * @return \Parser\Ast\Stmt[]
 */
function __mc_stmt_children(\Parser\Ast\Stmt $s): array
{
    /** @var \Parser\Ast\Stmt[] $out */
    $out = [];
    $k = $s->kind;
    // ⚠ Every arm goes through the `__mc_as_*` accessors and never reads a
    // subclass field off the base `Stmt`: doing that SEGFAULTS the native
    // self-build while Zend compiles the same program fine (the trap the
    // top-level-return work already paid for). Bodies are `Block`s — their
    // statements live under `->statements` — except a SwitchArm's, which is a
    // plain array.
    if ($k === 'If') {
        $n = __mc_as_if($s);
        foreach ($n->then->statements as $x) { $out[] = $x; }
        foreach ($n->elseifs as $ei) { foreach ($ei->body->statements as $x) { $out[] = $x; } }
        if ($n->else !== null) { foreach ($n->else->statements as $x) { $out[] = $x; } }
    } elseif ($k === 'While') {
        foreach (__mc_as_while($s)->body->statements as $x) { $out[] = $x; }
    } elseif ($k === 'DoWhile') {
        foreach (__mc_as_dowhile($s)->body->statements as $x) { $out[] = $x; }
    } elseif ($k === 'For') {
        foreach (__mc_as_for($s)->body->statements as $x) { $out[] = $x; }
    } elseif ($k === 'Foreach') {
        foreach (__mc_as_foreach($s)->body->statements as $x) { $out[] = $x; }
    } elseif ($k === 'TryCatch') {
        $n = __mc_as_trycatch($s);
        foreach ($n->try->statements as $x) { $out[] = $x; }
        foreach ($n->catches as $c) { foreach ($c->body->statements as $x) { $out[] = $x; } }
        if ($n->finally !== null) { foreach ($n->finally->statements as $x) { $out[] = $x; } }
    } elseif ($k === 'Switch') {
        foreach (__mc_as_switch($s)->cases as $a) { foreach ($a->body as $x) { $out[] = $x; } }
    } elseif ($k === 'Namespace') {
        $n = __mc_as_namespace($s);
        if ($n->body !== null) { foreach ($n->body->statements as $x) { $out[] = $x; } }
    }
    return $out;
}

/**
 * Source directories of a composer project rooted at `$projRoot`: its own
 * `autoload` (composer.json) and — when `$withVendor` — every installed
 * package's `autoload` (composer.lock, rooted at `vendor/<name>/`). Whole-program
 * AOT needs every reachable declaration up front, so this eagerly unions the
 * autoload roots; the result feeds straight into {@see collect_php_sources}.
 * Deduplicated by path, order preserved.
 *
 * @return string[]
 */
function composer_source_dirs(string $projRoot, bool $withVendor): array
{
    /** @var string[] $dirs */
    $dirs = [];
    $cjPath = $projRoot . "/composer.json";
    $cjSrc = file_exists($cjPath) ? read_file($cjPath) : null;
    if ($cjSrc !== null) {
        $cj = json_decode($cjSrc, true);
        if (\is_array($cj) && isset($cj["autoload"]) && \is_array($cj["autoload"])) {
            foreach (composer_autoload_dirs($cj["autoload"], $projRoot) as $d) { $dirs[] = $d; }
        }
    }
    if ($withVendor) {
        $lockPath = $projRoot . "/composer.lock";
        $lockSrc = file_exists($lockPath) ? read_file($lockPath) : null;
        if ($lockSrc !== null) {
            $lock = json_decode($lockSrc, true);
            $pkgs = (\is_array($lock) && isset($lock["packages"])) ? $lock["packages"] : [];
            foreach ($pkgs as $pkg) {
                if (!\is_array($pkg) || !isset($pkg["name"])) { continue; }
                $pkgRoot = $projRoot . "/vendor/" . (string)$pkg["name"];
                if (isset($pkg["autoload"]) && \is_array($pkg["autoload"])) {
                    foreach (composer_autoload_dirs($pkg["autoload"], $pkgRoot) as $d) { $dirs[] = $d; }
                }
            }
        }
    }
    /** @var string[] $out */
    $out = [];
    /** @var array<string,bool> $seen */
    $seen = [];
    foreach ($dirs as $d) {
        if (!isset($seen[$d])) { $seen[$d] = true; $out[] = $d; }
    }
    return $out;
}

/**
 * The paths composer's own `autoload.exclude-from-classmap` takes OUT of each
 * package — the package author's statement that this code is not part of what
 * the package ships. 51 of the 99 packages in the symfony-demo corpus declare
 * it, almost always `/Test/` or `/Tests/`.
 *
 * Honouring it is not tidiness. A shipped-but-excluded `Test/` directory holds
 * PHPUnit test-case base classes, and PHPUnit is a DEV dependency that
 * `--no-dev` does not install — so whole-program AOT compiled
 * `ServiceLocatorTestCase extends TestCase` and the module failed on an
 * undefined `assertFalse`. Composer never loads those files; neither should we.
 *
 * Each pattern is scoped to the package that declared it (prefixed with its
 * root) rather than applied globally — one package saying `/Test/` says nothing
 * about another's, and over-EXCLUSION is a regression exactly as
 * over-inclusion is.
 *
 * @return string[]
 */
function composer_classmap_excludes(string $projRoot, bool $withVendor): array
{
    /** @var string[] $out */
    $out = [];
    $cjPath = $projRoot . "/composer.json";
    $cjSrc = file_exists($cjPath) ? read_file($cjPath) : null;
    if ($cjSrc !== null) {
        $cj = json_decode($cjSrc, true);
        if (\is_array($cj) && isset($cj["autoload"]) && \is_array($cj["autoload"])) {
            foreach (classmap_exclude_paths($cj["autoload"], $projRoot) as $p) { $out[] = $p; }
        }
    }
    if ($withVendor) {
        $lockPath = $projRoot . "/composer.lock";
        $lockSrc = file_exists($lockPath) ? read_file($lockPath) : null;
        if ($lockSrc !== null) {
            $lock = json_decode($lockSrc, true);
            $pkgs = (\is_array($lock) && isset($lock["packages"])) ? $lock["packages"] : [];
            foreach ($pkgs as $pkg) {
                if (!\is_array($pkg) || !isset($pkg["name"])) { continue; }
                $pkgRoot = $projRoot . "/vendor/" . (string)$pkg["name"];
                if (isset($pkg["autoload"]) && \is_array($pkg["autoload"])) {
                    foreach (classmap_exclude_paths($pkg["autoload"], $pkgRoot) as $p) { $out[] = $p; }
                }
            }
        }
    }
    return $out;
}

/**
 * One autoload block's `exclude-from-classmap` entries, each joined to the
 * package root. Composer writes them with surrounding slashes (`"/Test/"`), so
 * the separators are normalised to exactly one on each side.
 *
 * @param array<string,mixed> $autoload
 * @return string[]
 */
function classmap_exclude_paths(array $autoload, string $base): array
{
    /** @var string[] $out */
    $out = [];
    if (!isset($autoload["exclude-from-classmap"])) { return $out; }
    $ents = $autoload["exclude-from-classmap"];
    if (!\is_array($ents)) { return $out; }
    foreach ($ents as $ent) {
        $rel = \trim((string)$ent, "/");
        if ($rel === "") { continue; }
        $out[] = composer_path_join($base, $rel);
    }
    return $out;
}

/**
 * Parse every global-namespace function declaration under `$dir` (minus
 * `$excludes`) — the exported API of a library target, offered to dependent
 * applications as declare-only externs. Mirrors collect_stdlib_extern_decls
 * but scoped to a manifest library's source root.
 *
 * @param string[] $excludes
 * @return \Parser\Ast\FunctionDecl[]
 */
function collect_extern_decls_from_dir(string $dir, array $excludes): array
{
    /** @var \Parser\Ast\FunctionDecl[] $decls */
    $decls = [];
    foreach (collect_php_sources($dir, $excludes) as $src) {
        try {
            $program = Parser::parseSource($src);
        } catch (\Throwable $e) {
            continue;
        }
        foreach ($program->statements as $stmt) {
            if ($stmt->kind !== 'Function') { continue; }
            if (\strpos($stmt->decl->name, '\\') !== false) { continue; }
            $decls[] = $stmt->decl;
        }
    }
    return $decls;
}

/**
 * Compile a ready source list to `$output`. `$emitLibrary` true → assemble a
 * standalone `.o` (no @main, no link). Otherwise → object + link, appending
 * every path in `$linkObjs` (the dependency libraries' `.o`). Externs/typing
 * come from {@see CompileArgs::$externDecls}, set by the caller.
 *
 * `$linkFlags` carries extra `cc` link tokens (e.g. `-lz` from an extension's
 * native library); appended after the objects. `$withStdlib` true → the bundled
 * stdlib is the always-on runtime: its externs are injected (so any stdlib call
 * types + resolves) and `manticore_stdlib.o` is linked when actually used —
 * read from the INSTALLED lib/ via the argv0-relative finders, so a user
 * manifest gets the stdlib without listing it. Mirrors {@see cmd_compile}.
 *
 * @param string[] $sources
 * @param string[] $linkObjs
 */
function build_compile_module(array $sources, string $output, bool $emitLibrary, array $linkObjs, string $linkFlags = '', bool $withStdlib = false, array $paths = []): int
{
    CompileArgs::$emitLibrary = $emitLibrary;
    // Ensure the output directory exists — a fresh checkout has no `lib/` (it is
    // a build artifact), and clang/cc cannot create the parent on write. Covers
    // any manifest target dir, not just the stdlib's `lib/`.
    system("mkdir -p \"$(dirname \"" . $output . "\")\"");
    // Always-on stdlib runtime: merge its externs alongside any user-library
    // externs the caller already set. Skipped for --emit-library and when the
    // app opted out (the self-contained compiler).
    if ($withStdlib && !$emitLibrary) {
        foreach (collect_stdlib_extern_decls() as $d) { CompileArgs::$externDecls[] = $d; }
        if (CompileArgs::$sigError !== '') {
            dprint(CompileArgs::$sigError);
            return 65;
        }
    }
    $module = lower_module($sources, null, $paths);
    if ($module === null) { dprint("build: front-end returned null for " . $output); return 65; }
    /** @var string[] $undefTraps */
    $undefTraps = [];
    try {
        $statT = \Compile\Stats::now();
        $emit = new \Compile\Mir\Passes\EmitLlvm();
        $emit->emitLibrary = $emitLibrary;
        $ir = $emit->emit($module);
        CompileArgs::$ffiLibs = \array_keys($emit->ffiLibs);
        CompileArgs::$weakSyms = \array_keys($emit->weakSyms);
        $undefTraps = \array_keys($emit->undefinedCalls);
        \Compile\Stats::step('EmitLlvm', $statT, \count($module->functions), -1);
        \Compile\Stats::line('IR: ' . (string)\strlen($ir) . ' bytes');
    } catch (\Throwable $e) {
        dprint("build: emit failed for " . $output . ": " . $e->getMessage());
        return 65;
    }
    if (\strlen($ir) === 0) { dprint("build: empty IR for " . $output); return 65; }
    // A call nothing defines compiled into a runtime throw rather than a link
    // error ({@see \Compile\Mir\Passes\EmitLlvmCalls::emitCall}). That is right
    // for a guarded `apcu_add`, and it is also exactly what a compiler ONE
    // GENERATION BEHIND its source produces for a name it does not know yet —
    // silently, which is why "this needed a cold seed" used to be discovered by
    // a crash instead of by the build. Name it, in a line a driver can parse.
    if (\count($undefTraps) > 0) {
        \sort($undefTraps);
        $names = \implode(", ", $undefTraps);
        dprint("build: undefined-function traps (" . (string)\count($undefTraps)
            . "): " . $names);
        // For a LIBRARY the stub is written to a `.o` that outlives this build
        // and is linked by every later program, so refuse it by default.
        if ($emitLibrary && !CompileArgs::$allowUndefinedTraps) {
            dprint("build: refusing to write " . $output
                . " with undefined-function traps (pass --allow-undefined-traps to override)");
            return 65;
        }
    }
    // Staging path for the IR and the intermediate object. Under --keep-ir they
    // sit next to the target (stable, one per target, and not swept from /tmp);
    // otherwise a pid-derived /tmp base, removed once the target links. The
    // staged files are deliberately LEFT BEHIND on failure — they are the only
    // record of what the compiler emitted for a build that did not finish.
    $keep = CompileArgs::$keepIr;
    $base = $keep ? ($output . ".dbg") : ("/tmp/manticore_buildobj_" . (string)getpid());
    $llPath = $base . ".ll";
    // The library path assembles this file directly; the application path hands
    // the IR to assemble_ir, which stages it itself (as one file, or as one per
    // part when it splits). A library is NEVER split: `stdlib.o` is one object
    // by contract — its `.sig` describes that file and every consumer links it.
    if ($emitLibrary) {
        if (!write_file($llPath, $ir)) { dprint("build: cannot write " . $llPath); return 73; }
        if ($keep) { dprint("build: kept IR " . $llPath); }
        $rc = system("clang -O" . CompileArgs::$optLevel . " -c -x ir " . $llPath . " -o " . $output . " -Wno-override-module");
        if ($rc !== 0) { dprint("build: clang -c (library) failed for " . $output); return 75; }
        if (!$keep) { system("rm -f " . $llPath); }
        // Emit the module-interface .sig next to the object so dependents
        // import this library's exported symbols without re-parsing it.
        // The .sig carries this library's LINK requirements alongside its
        // symbols: a dependent's own module has no `#[Ffi\Library]` for a
        // wrapper that lives in here, so without them the library it calls is
        // simply never linked and link_stubs.sh quietly stubs the symbol to 0.
        if (!write_file($output . ".sig", Sig::emitModule($module,
                CompileArgs::$ffiLibs, CompileArgs::$weakSyms))) {
            dprint("build: cannot write " . $output . ".sig");
            return 73;
        }
        return 0;
    }
    $objPath = $base . ".o";
    $objs = assemble_ir($ir, $base, "");
    if ($objs === []) { dprint("build: assemble failed for " . $output); return 75; }
    $objList = \implode(" ", $objs);
    $linkExtra = "";
    foreach ($linkObjs as $obj) { $linkExtra = $linkExtra . " " . $obj; }
    if ($linkFlags !== "") { $linkExtra = $linkExtra . " " . $linkFlags; }
    $libs = CompileArgs::$ffiLibs;
    $weak = CompileArgs::$weakSyms;
    // Every dependency object contributes the libraries ITS bindings call and
    // the symbols it declared weak — they are emitted in that object, so this
    // module never names them.
    foreach ($linkObjs as $obj) {
        $depSig = read_file($obj . ".sig");
        if ($depSig === null) { continue; }
        $dl = Sig::libsFromJson($depSig);
        if ($dl !== null) { foreach ($dl as $l) { $libs[] = $l; } }
        $dw = Sig::weakFromJson($depSig);
        if ($dw !== null) { foreach ($dw as $w) { $weak[] = $w; } }
    }
    // Link the bundled stdlib.o when a stdlib function was actually referenced
    // (lower_module sets linkStdlib from the injected externs) — a program that
    // touches no stdlib function links nothing extra.
    if ($withStdlib && CompileArgs::$linkStdlib) {
        $stdObj = find_stdlib_object();
        if ($stdObj !== "") { $linkExtra = $linkExtra . " " . $stdObj; }
        // Same requirements the single-file `compile` path picks up, from the
        // same place: the stdlib's own `.sig`.
        foreach (stdlib_sig_list("libs",
                 ["pcre2-8", "ssl", "crypto", "iconv"]) as $l) {
            $libs[] = $l;
        }
        foreach (stdlib_sig_list("weak",
                 ["__errno_location", "epoll_create1", "epoll_ctl", "epoll_wait",
                  "signalfd"]) as $w) {
            $weak[] = $w;
        }
    }
    $libFlags = ffi_link_flags($libs, $linkFlags);
    if ($libFlags !== "") { $linkExtra = $linkExtra . $libFlags; }
    // Darwin's weak-undefined allowance, derived exactly as in cmd_compile.
    // This path carried NO -U flags at all before, which is a divergence that
    // only stayed invisible because link_stubs.sh defines what ld would reject.
    if (is_darwin()) { $linkExtra = $linkExtra . weak_undef_flags($weak); }
    // Link via the stub-generating tail: the bootstrap leaves native
    // FFI-boundary primitives (`manticore_rt_*`) undefined; they link-stub to
    // 0. Falls back to a plain cc when the helper isn't found.
    $stubs = find_link_stubs_script();
    $statT = \Compile\Stats::now();
    if ($stubs !== "") {
        $rc2 = system("bash " . $stubs . " " . $output . " " . $objList . $linkExtra);
    } else {
        $rc2 = system("cc " . $objList . $linkExtra . " -o " . $output);
    }
    \Compile\Stats::step('link', $statT, -1, -1);
    \Compile\Stats::dumpCounters();
    if ($rc2 !== 0) { dprint("build: link failed for " . $output); return 76; }
    if (!$keep) {
        system("rm -f " . $llPath . " " . $objPath . " " . $objList . " " . $base . ".p*.ll");
    }
    return 0;
}

/**
 * Locate tools/link_stubs.sh relative to argv[0] (or cwd). Returns "" when
 * absent (caller falls back to a plain link).
 */
function find_link_stubs_script(): string
{
    $rawSelf = argv(0);
    $self = \cstr_to_str($rawSelf);
    $slashAt = \strrpos($self, "/");
    if ($slashAt !== false && $slashAt >= 0) {
        $selfDir = \substr($self, 0, $slashAt);
        $c1 = $selfDir . "/../tools/link_stubs.sh";
        if (file_exists($c1)) { return $c1; }
        $c2 = $selfDir . "/tools/link_stubs.sh";
        if (file_exists($c2)) { return $c2; }
    }
    if (file_exists("tools/link_stubs.sh")) { return "tools/link_stubs.sh"; }
    return "";
}

/**
 * `build [manticore.json]` — cargo-like manifest build. Builds every library
 * target (→ standalone `.o`), then every application target (→ executable,
 * auto-linking and importing the signatures of all library targets).
 *
 * The manifest is decoded with the native `json_decode`; values flow as
 * `mixed`, extracted into typed locals via `(string)` casts before use.
 *
 * @param string[] $args
 */
function cmd_build(array $args): int
{
    // Parse: last positional = manifest path; `--libs-only` builds the library
    // targets and stops (used by the cold seed to refresh stdlib.o without
    // re-linking the applications), `--apps-only` is its mirror. The shared
    // compile spec rides along so a manifest build accepts `-O0` / `--memory`
    // like `compile` does — same shape cmd_analyze uses.
    //
    // The pair is what lets a self-host rebuild put the LIBRARIES on the far
    // side of the binary swap: one process cannot do it, because the libraries
    // are built by the compiler that is running, and the point is to build them
    // with the compiler that was just produced ({@see bin/build}).
    $spec = compile_arg_spec();
    $spec["libs-only"] = \Cli\ArgParse::FLAG;
    $spec["apps-only"] = \Cli\ArgParse::FLAG;
    $spec["allow-undefined-traps"] = \Cli\ArgParse::FLAG;
    $spec["keep-ir"] = \Cli\ArgParse::FLAG;
    $p = \Cli\ArgParse::parse($args, $spec);
    if ($p->error !== null) { dprint("build: " . $p->error); return 64; }
    if (!apply_compile_args($p)) { return 64; }
    // apply_compile_args fills $files from the positionals, and lower_module
    // bakes $files[0] into $module->sourceFile — the text behind
    // Throwable::getFile() and every "… in <file> on line N" diagnostic. A
    // manifest path is not a source file, so clear it.
    CompileArgs::$files = [];
    $nPos = \count($p->positional);
    $manifestPath = $nPos > 0 ? $p->positional[$nPos - 1] : "manticore.json";
    $libsOnly = $p->flag("libs-only");
    $appsOnly = $p->flag("apps-only");
    if ($libsOnly && $appsOnly) {
        dprint("build: --libs-only and --apps-only are mutually exclusive");
        return 64;
    }
    CompileArgs::$allowUndefinedTraps = $p->flag("allow-undefined-traps");
    CompileArgs::$keepIr = $p->flag("keep-ir");
    $src = read_file($manifestPath);
    if ($src === null) {
        dprint("build: cannot read manifest: " . $manifestPath);
        return 66;
    }
    $manifest = json_decode($src, true);
    $libs = isset($manifest["libraries"]) ? $manifest["libraries"] : [];
    foreach ($libs as $lib) {
        // --apps-only skips BUILDING the libraries; the list itself is still
        // needed below, where each non-runtime library's `.o`/`.sig` joins the
        // application's link (they must already exist on disk).
        if ($appsOnly) { break; }
        $name = (string)$lib["name"];
        $srcDir = (string)$lib["src"];
        $output = (string)$lib["output"];
        /** @var string[] $excludes */
        $excludes = [];
        foreach ($lib["exclude"] as $e) { $excludes[] = (string)$e; }
        dprint("build: library '" . $name . "' (" . $srcDir . " -> " . $output . ")");
        /** @var string[] $sources */
        $sources = [];
        /** @var string[] $paths */
        $paths = [];
        foreach (collect_php_source_files($srcDir, $excludes) as $sf) {
            $sources[] = $sf->contents;
            $paths[] = $sf->path;
        }
        if (\count($sources) === 0) {
            dprint("build: no sources for library '" . $name . "'");
            return 66;
        }
        CompileArgs::$externDecls = [];
        CompileArgs::$externClassDecls = [];
        CompileArgs::$externClassMeta = [];
        CompileArgs::$externConstants = [];
        CompileArgs::$exportTypes =
            !(isset($lib["runtime"]) && (string)$lib["runtime"] === "1");
        $rc = build_compile_module($sources, $output, true, [], '', false, $paths);
        CompileArgs::$exportTypes = true;
        if ($rc !== 0) { return $rc; }
    }
    if ($libsOnly) { return 0; }
    $apps = isset($manifest["applications"]) ? $manifest["applications"] : [];
    foreach ($apps as $app) {
        $name = (string)$app["name"];
        $srcDir = (string)$app["src"];
        $output = (string)$app["output"];
        /** @var string[] $excludes */
        $excludes = [];
        foreach ($app["exclude"] as $e) { $excludes[] = (string)$e; }
        // Explicit entry point: the file whose top-level code becomes the
        // program's main(). Module files (everything else) contribute only
        // their declarations, so the entry is excluded from the module scan
        // and appended LAST — its top-level lowers into `__main` after every
        // class/function is registered. Optional: with no `entry`, fall back
        // to the find|sort order (a `zzz_*` driver sorts last by convention).
        $entry = "";
        if (isset($app["entry"])) { $entry = (string)$app["entry"]; }
        $moduleExcludes = $excludes;
        if ($entry !== "") { $moduleExcludes[] = $entry; }
        dprint("build: application '" . $name . "' (" . $srcDir . " -> " . $output . ")");
        /** @var string[] $sources */
        $sources = [];
        /** @var string[] $paths */
        $paths = [];
        // A FILE is compiled once, however many autoload roots name it. Keyed by
        // path rather than by directory because composer roots OVERLAP: symfony
        // polyfills map psr-4 to the PACKAGE ROOT and then classmap a
        // subdirectory of it (`"Symfony\\Polyfill\\Intl\\Icu\\": ""` plus
        // `classmap: ["Resources/stubs"]`). The $covered set below compares
        // directory STRINGS, so the nested entry is not equal to its parent and
        // both were scanned — the stub's class was lowered twice and clang
        // rejected the module with `invalid redefinition of function
        // manticore_IntlDateFormatter____construct`. Not audit-only: any project
        // whose autoload roots nest hits it.
        /** @var array<string,bool> $seenPath */
        $seenPath = [];
        foreach (collect_php_source_files($srcDir, $moduleExcludes) as $sf) {
            if (isset($seenPath[$sf->path])) { continue; }
            $seenPath[$sf->path] = true;
            $sources[] = $sf->contents;
            $paths[] = $sf->path;
        }
        // Composer discovery. "composer": true builds the project the way Composer
        // sees it — its own composer.json autoload (psr-4/psr-0 + classmap dirs)
        // AND every installed vendor package from composer.lock (vendor/<name>/).
        // The object form gives finer control: { "vendor": false } takes only the
        // project's own autoload (e.g. while a dependency uses PHP the compiler
        // does not yet support). Whole-program AOT unions these into the source
        // set exactly like `src`; dirs already covered by `src` are skipped so a
        // `"App\\": "src/"` map does not double-scan.
        $composer = isset($app["composer"]) ? $app["composer"] : false;
        $composerOn = ($composer === true) || \is_array($composer);
        if ($composerOn) {
            $withVendor = !(\is_array($composer) && isset($composer["vendor"]) && $composer["vendor"] === false);
            // Composer's own exclusions join the manifest's. Applied to the
            // composer-discovered roots only: the manifest's `src` is the
            // project's own code, where the author's `exclude` is the authority.
            $moduleExcludes = \array_merge(
                $moduleExcludes,
                composer_classmap_excludes(".", $withVendor),
            );
            /** @var array<string,bool> $covered */
            $covered = [];
            $covered[\rtrim($srcDir, "/")] = true;
            // composer's BOOTSTRAP set. Everything else it autoloads is
            // demand-driven, so a file there that declares nothing is not a
            // compile unit at all ({@see __mc_source_may_declare}).
            $bootFiles = composer_autoload_file_entries(".", $withVendor);
            $skippedScripts = 0;
            foreach (composer_source_dirs(".", $withVendor) as $cdir) {
                $nd = \rtrim($cdir, "/");
                if (isset($covered[$nd])) { continue; }
                $covered[$nd] = true;
                dprint("build: + composer autoload '" . $nd . "'");
                foreach (collect_php_source_files($nd, $moduleExcludes) as $sf) {
                    if (isset($seenPath[$sf->path])) { continue; }
                    $seenPath[$sf->path] = true;
                    $sfNorm = \rtrim($sf->path, "/");
                    $isBoot = isset($bootFiles[$sfNorm]) || isset($bootFiles[$nd]);
                    if (!$isBoot && !__mc_source_may_declare($sf->contents)) {
                        $skippedScripts = $skippedScripts + 1;
                        dprint("build:   - script (declares nothing, never autoloaded): " . $sf->path);
                        continue;
                    }
                    // Demand-loaded: keep its DECLARATIONS, drop its top-level
                    // side effects ({@see CompileArgs::$demandLoadedPaths}).
                    if (!$isBoot) { CompileArgs::$demandLoadedPaths[$sfNorm] = true; }
                    $sources[] = $sf->contents;
                    $paths[] = $sf->path;
                }
            }
            if ($skippedScripts > 0) {
                dprint("build: skipped " . (string)$skippedScripts
                    . " non-declaring script(s) under demand-loaded autoload roots");
            }
        }
        // Extensions: opt-in native bindings. Each named extension adds its thin
        // PHP glue (FFI bindings + wrappers, module-level decls → appended BEFORE
        // the entry) to the module, and its native library to the link
        // (`-l<lib>`). Declared under the manifest's top-level "extensions".
        $extDefs = isset($manifest["extensions"]) ? $manifest["extensions"] : [];
        $linkFlags = "";
        foreach ($app["extensions"] as $extName) {
            $en = (string)$extName;
            if (!isset($extDefs[$en])) {
                dprint("build: app '" . $name . "' wants unknown extension '" . $en . "'");
                return 66;
            }
            $ext = $extDefs[$en];
            $extSrc = (string)$ext["src"];
            foreach (collect_php_source_files($extSrc, []) as $sf) {
                $sources[] = $sf->contents;
                $paths[] = $sf->path;
            }
            foreach ($ext["link"] as $lib) { $linkFlags = $linkFlags . " -l" . (string)$lib; }
            dprint("build: + extension '" . $en . "' (" . $extSrc . ")");
        }
        if ($entry !== "") {
            $entrySrc = read_file($entry);
            if ($entrySrc === null) {
                dprint("build: cannot read entry point: " . $entry);
                return 66;
            }
            $sources[] = $entrySrc;
            $paths[] = $entry;
        }
        if (\count($sources) === 0) {
            dprint("build: no sources for application '" . $name . "'");
            return 66;
        }
        dump_resolved_sources($paths);
        // Library dependencies. A library marked "runtime": true (the bundled
        // stdlib) is the ALWAYS-ON runtime: every app imports + links it by
        // default, so the stdlib is transparently available with no manifest
        // ceremony — `manticore build`/`compile` "just work". An app opts OUT
        // with "stdlib": false (JSON; read via the (string) cast → "" for the
        // self-host cell-bool) — the self-contained compiler does this because
        // it already embeds src/Runtime and would otherwise double-define it.
        // NON-runtime (user) libraries follow the `libraries` selection: omit ⇒
        // all, [] ⇒ none, a named subset ⇒ just those. Crucially the stdlib is
        // independent of that selection, so `libraries: ["mylib"]` never drops it.
        // `runtime: true` libraries (the stdlib) are BUILT above but are not a
        // user dependency here — they are the always-on runtime, injected +
        // linked from the installed lib/ inside build_compile_module (so a USER
        // manifest that never lists the stdlib still gets it). `stdlib: false`
        // opts out (the self-contained compiler). NON-runtime libraries follow
        // the `libraries` selection (omit ⇒ all, [] ⇒ none, names ⇒ subset),
        // independent of the stdlib so `libraries: ["mylib"]` never drops it.
        $skipStdlib = isset($app["stdlib"]) && (string)$app["stdlib"] === "";
        $selectAll = !isset($app["libraries"]);
        /** @var string[] $wanted */
        $wanted = [];
        if (!$selectAll) {
            foreach ($app["libraries"] as $w) { $wanted[] = (string)$w; }
        }
        /** @var \Parser\Ast\FunctionDecl[] $externDecls */
        $externDecls = [];
        /** @var \Parser\Ast\ClassDecl[] $externClassDecls */
        $externClassDecls = [];
        /** @var array<string, \Compile\Mir\ExternClassMeta> $externClassMeta */
        $externClassMeta = [];
        /** @var array<string, \Parser\Ast\Expr> $externConstants */
        $externConstants = [];
        /** @var array<string, string> $classOrigin FQN → the library that exports it */
        $classOrigin = [];
        /** @var string[] $linkObjs */
        $linkObjs = [];
        foreach ($libs as $lib) {
            $isRuntime = isset($lib["runtime"]) && (string)$lib["runtime"] === "1";
            if ($isRuntime) { continue; }
            $libName = (string)$lib["name"];
            if (!$selectAll) {
                $take = false;
                foreach ($wanted as $w) { if ($w === $libName) { $take = true; break; } }
                if (!$take) { continue; }
            }
            $libOut = (string)$lib["output"];
            $sigJson = read_file($libOut . ".sig");
            if ($sigJson !== null) {
                $bad = Sig::validateImport($sigJson, $libOut . ".sig");
                if ($bad !== "") { dprint($bad); return 65; }
                foreach (Sig::declsFromJson($sigJson) as $d) {
                    $externDecls[] = $d;
                }
                foreach (Sig::classDeclsFromJson($sigJson) as $cdecl) {
                    $externClassDecls[] = $cdecl;
                }
                foreach (Sig::classMetaFromJson($sigJson) as $mname => $meta) {
                    // Two libraries exporting one FQN is not a merge — their
                    // layouts are independent and only one `.o` can win the
                    // symbol. Refuse rather than pick.
                    if (isset($classOrigin[$mname])) {
                        dprint("manticore: class " . $mname . " is exported by both "
                            . $classOrigin[$mname] . " and " . $libOut);
                        return 65;
                    }
                    $classOrigin[$mname] = $libOut;
                    $externClassMeta[$mname] = $meta;
                }
                foreach (Sig::constantsFromJson($sigJson) as $cn => $cv) {
                    $externConstants[$cn] = $cv;
                }
            } else {
                dprint("build: missing .sig for library output " . $libOut);
            }
            $linkObjs[] = $libOut;
        }
        CompileArgs::$externDecls = $externDecls;
        CompileArgs::$externClassDecls = $externClassDecls;
        CompileArgs::$externClassMeta = $externClassMeta;
        CompileArgs::$externConstants = $externConstants;
        $rc = build_compile_module($sources, $output, false, $linkObjs, $linkFlags, !$skipStdlib, $paths);
        if ($rc !== 0) { return $rc; }
    }
    return 0;
}

/**
 * Dump LLVM IR to stdout — same front-end as `compile`, without the
 * clang/cc tail. Useful when debugging codegen output or piping into
 * `llvm-dis`, `opt`, etc. Honours the same file/stdin/manifest
 * resolution as `compile`.
 *
 * @param string[] $args
 */
function cmd_dump_llvm(array $args): int {
    if (!parse_compile_args($args)) { return 64; }
    $sources = resolve_sources(CompileArgs::$files);
    if ($sources === null) { return 66; }
    $ir = compile_with_backend($sources);
    if ($ir === null) { return 65; }
    puts($ir);
    return 0;
}

function cmd_version(array $args): int {
    puts("manticore 0.6.0");
    return 0;
}

/**
 * Dump the parsed AST. Useful for validating the parser without
 * running codegen and for comparing AST diffs between releases.
 *
 * @param string[] $args
 */
function cmd_dump_ast(array $args): int {
    if (!parse_compile_args($args)) { return 64; }
    $sources = resolve_sources(CompileArgs::$files);
    if ($sources === null) { return 66; }
    if (\count($sources) === 0) { return 66; }
    try {
        $program = Parser::parseSource($sources[0]);
    } catch (\Throwable $e) {
        dprint("parse failed: " . $e->getMessage());
        return 65;
    }
    puts(\Parser\Dump::program($program));
    return 0;
}

/**
 * Dump the lowered MIR. Phase A scope: AST → MIR via
 * {@see \Compile\Mir\Passes\LowerFromAst}; later phases pipeline
 * more passes and accept `--after=<pass>` to dump intermediate
 * states.
 *
 * @param string[] $args
 */
/**
 * Front-end for `--backend=mir`. Parse → MIR pipeline → LLVM IR.
 * Single source file only at this round (multi-file linkage is the
 * existing `compile_sources` path's job).
 */
/**
 * Front-end for `--backend=mir`. Parse → MIR pipeline → LLVM IR.
 * Single source file at this round (multi-file linkage stays on
 * the existing `compile_sources` path until MIR grows class /
 * function symbol resolution across compilation units).
 */
/**
 * Front-end for `--backend=mir`. Parse every source, merge their
 * top-level statements into one Program (module files first, entry
 * last — the order resolve_sources hands us), then run the
 * MIR pipeline once. Class / function decls from all files register in
 * the pre-pass; the entry's top-level code lowers into `__main` last.
 *
 * @param string[] $sources
 */
/**
 * Parse + run the full MIR pipeline (everything before EmitLlvm) over a
 * merged source list, returning the typed Module (or null on error). Shared
 * by compile_via_mir (→ LLVM IR) and cmd_dump_sig (→ .sig). Externs/typing
 * come from {@see CompileArgs::$externDecls}.
 *
 * `$paths` is optional but STRONGLY recommended: it names each source so a
 * `parse failed` diagnostic points at the real file rather than swallowing
 * the location (a compile-time UX regression that hits hardest when the caller
 * passes `manticore.json` by mistake or one file in a 20-file manifest breaks).
 *
 * @param string[] $sources
 * @param string[] $paths   parallel to $sources; used only for diagnostics
 */
/**
 * Absolute path for a source file, for `__FILE__`/`__DIR__`. php reports the
 * resolved path, so symlinks and `./` segments are collapsed; an unresolvable or
 * empty path stays as it came (the constants then read '', matching php for sources
 * with no file).
 */
function __mc_abs_source_path(string $path): string {
    if ($path === '') { return ''; }
    $real = \realpath($path);
    return $real === false ? $path : $real;
}

/**
 * The global slot name holding what `require`/`include` of `$path` evaluates
 * to. Keyed on the RESOLVED path so the two spellings a program reaches one
 * file by (`__DIR__ . '/x.php'` and `./x.php`) name the same slot — the same
 * normalisation `__FILE__` already gets. '' for a source with no path, which
 * simply has no slot.
 */
function __mc_include_slot(string $path, int $index): string {
    $abs = __mc_abs_source_path($path);
    if ($abs === '') { return ''; }
    // Derived from the source INDEX plus a sanitised tail of the path — no hash.
    //
    // This used to be substr(sha1($abs), 0, 16), which is correct everywhere
    // except the one place that matters: sha1() routes through OpenSSL's EVP,
    // and tools/link_stubs.sh stubs EVP_sha1 to `return 0` when linking the
    // SELF-HOSTED compiler. Inside that compiler every path hashed to the same
    // string, so every file shared one slot and the include case SEGFAULTED —
    // and only under self-host, which is why the normal suite stayed green and
    // the fixpoint gate caught it.
    //
    // The index makes it unique by construction (one compile, one list), so
    // there is no collision to reason about; the tail is only there to keep the
    // emitted IR readable.
    $tail = '';
    $n = \strlen($abs);
    $from = $n > 40 ? $n - 40 : 0;
    for ($i = $from; $i < $n; $i = $i + 1) {
        $c = $abs[$i];
        $ok = ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')
            || ($c >= '0' && $c <= '9');
        $tail = $tail . ($ok ? $c : '_');
    }
    return '__mc_incl_' . (string)$index . '_' . $tail;
}

/**
 * What a non-entry file's top-level `return`s look like, as an int so no
 * nullable scalar is involved: 0 none, 1 only valueless, 2 at least one with a
 * value. Control flow is descended; a FUNCTION or CLASS declaration is NOT —
 * a `return` in there belongs to its own frame, not to the file.
 *
 * @param \Parser\Ast\Stmt[] $stmts
 */
function __mc_file_return_kind(array $stmts): int
{
    $kind = 0;
    foreach ($stmts as $s) {
        if ($s->kind === 'Return') {
            $here = __mc_as_return($s)->value === null ? 1 : 2;
            if ($here > $kind) { $kind = $here; }
            continue;
        }
        $sub = __mc_stmt_return_kind($s);
        if ($sub > $kind) { $kind = $sub; }
    }
    return $kind;
}

/**
 * {@see __mc_file_return_kind} for one statement's nested bodies.
 *
 * Recursion is direct rather than through a helper returning the child lists.
 * An `array` OF `Stmt[]` is a nested-element channel, and handing one back
 * across a call boundary erases the inner element type — the arrays came back
 * as values whose bits were read as a `Stmt`, which SIGBUS'd the Zend-seeded
 * compiler while the self-hosted build stayed green. Nothing here builds a
 * container it does not need.
 */
function __mc_stmt_return_kind(\Parser\Ast\Stmt $s): int
{
    $k = $s->kind;
    $kind = 0;
    if ($k === 'If') {
        $n = __mc_as_if($s);
        $kind = __mc_file_return_kind($n->then->statements);
        foreach ($n->elseifs as $arm) {
            $x = __mc_file_return_kind($arm->body->statements);
            if ($x > $kind) { $kind = $x; }
        }
        if ($n->else !== null) {
            $x = __mc_file_return_kind($n->else->statements);
            if ($x > $kind) { $kind = $x; }
        }
        return $kind;
    }
    if ($k === 'While')   { return __mc_file_return_kind(__mc_as_while($s)->body->statements); }
    if ($k === 'DoWhile') { return __mc_file_return_kind(__mc_as_dowhile($s)->body->statements); }
    if ($k === 'For')     { return __mc_file_return_kind(__mc_as_for($s)->body->statements); }
    if ($k === 'Foreach') { return __mc_file_return_kind(__mc_as_foreach($s)->body->statements); }
    if ($k === 'TryCatch') {
        $n = __mc_as_trycatch($s);
        $kind = __mc_file_return_kind($n->try->statements);
        foreach ($n->catches as $c) {
            $x = __mc_file_return_kind($c->body->statements);
            if ($x > $kind) { $kind = $x; }
        }
        if ($n->finally !== null) {
            $x = __mc_file_return_kind($n->finally->statements);
            if ($x > $kind) { $kind = $x; }
        }
        return $kind;
    }
    if ($k === 'Switch') {
        foreach (__mc_as_switch($s)->cases as $arm) {
            $x = __mc_file_return_kind($arm->body);
            if ($x > $kind) { $kind = $x; }
        }
        return $kind;
    }
    if ($k === 'Namespace') {
        $n = __mc_as_namespace($s);
        if ($n->body !== null) { return __mc_file_return_kind($n->body->statements); }
        return 0;
    }
    return 0;
}

function __mc_as_if(\Parser\Ast\IfStmt $s): \Parser\Ast\IfStmt { return $s; }
function __mc_as_while(\Parser\Ast\WhileStmt $s): \Parser\Ast\WhileStmt { return $s; }
function __mc_as_dowhile(\Parser\Ast\DoWhileStmt $s): \Parser\Ast\DoWhileStmt { return $s; }
function __mc_as_for(\Parser\Ast\ForStmt $s): \Parser\Ast\ForStmt { return $s; }
function __mc_as_foreach(\Parser\Ast\ForeachStmt $s): \Parser\Ast\ForeachStmt { return $s; }
function __mc_as_trycatch(\Parser\Ast\TryCatchStmt $s): \Parser\Ast\TryCatchStmt { return $s; }
function __mc_as_switch(\Parser\Ast\SwitchStmt $s): \Parser\Ast\SwitchStmt { return $s; }
function __mc_as_namespace(\Parser\Ast\NamespaceStmt $s): \Parser\Ast\NamespaceStmt { return $s; }
function __mc_as_return(\Parser\Ast\ReturnStmt $s): \Parser\Ast\ReturnStmt { return $s; }

/**
 * Rewrite every top-level `return` of a NON-ENTRY file into "store the value,
 * then jump past the rest of this file".
 *
 * php's `return` in an included file ends THAT FILE and hands a value back; the
 * including script carries on. Whole-program AOT flattens every file's
 * top-level statements into one `__main` with the entry last, so a surviving
 * `return` ends the PROGRAM instead — and the entry, which is last, never runs.
 * Dropping the statement (what this used to do for a bare top-level `return`)
 * is wrong in the other direction: the rest of the file then runs when php
 * would have skipped it.
 *
 * A jump to a label at the file's end is both. The statements stay in `__main`,
 * so top-level variables keep sharing one scope exactly as php's include does —
 * which is why this is a jump and not a synthetic per-file function.
 *
 * @param \Parser\Ast\Stmt[] $stmts
 * @return \Parser\Ast\Stmt[]
 */
function __mc_rewrite_file_returns(array $stmts, string $slot, string $label): array
{
    /** @var \Parser\Ast\Stmt[] $out */
    $out = [];
    foreach ($stmts as $s) {
        if ($s->kind === 'Return') {
            $ret = __mc_as_return($s);
            // A `return;` with NO value is not the same as never returning:
            // php's `require` of a file that RAN a bare `return` evaluates to
            // NULL, while a file that fell off its end gives int(1). Storing
            // NULL at the return site keeps both, because the store only
            // executes on the path that actually returned.
            $retVal = $ret->value === null
                ? \Parser\Ast\Expr::null($s->span)
                : $ret->value;
            if ($slot !== '') {
                $out[] = \Parser\Ast\Stmt::expression(
                    \Parser\Ast\Expr::assign(
                        \Parser\Ast\Expr::arrayAccess(
                            \Parser\Ast\Expr::variable('GLOBALS', $s->span),
                            \Parser\Ast\Expr::string($slot, $s->span),
                            $s->span,
                        ),
                        // Boxed on the way in for the same reason the reader is
                        // typed cell: the slot holds an array, a closure or a
                        // scalar interchangeably.
                        \Parser\Ast\Expr::call('__mir_to_cell', [$retVal], $s->span),
                        $s->span,
                    ),
                    $s->span,
                );
            }
            $out[] = new \Parser\Ast\GotoStmt($label, $s->span);
            continue;
        }
        $out[] = __mc_rewrite_stmt_returns($s, $slot, $label);
    }
    return $out;
}

/** One statement rebuilt with its nested statement lists rewritten. */
function __mc_rewrite_stmt_returns(\Parser\Ast\Stmt $s, string $slot, string $label): \Parser\Ast\Stmt
{
    // As in __mc_stmt_return_kind: every subclass field is read behind a
    // concrete parameter type, never off the base `Stmt`. Read off the base
    // (which declares only kind/span) a field resolves by the wrong offset
    // under the self-host and SEGFAULTS THE COMPILER, while Zend — where the
    // same access is dynamic — compiles the identical program without
    // complaint. Same rule, and the same reason, as foldGuard's typed dispatch.
    $k = $s->kind;
    if ($k === 'If') {
        $n = __mc_as_if($s);
        $elseifs = [];
        foreach ($n->elseifs as $arm) {
            $elseifs[] = new \Parser\Ast\ElseIfArm(
                $arm->condition,
                new \Parser\Ast\Block(__mc_rewrite_file_returns($arm->body->statements, $slot, $label)),
            );
        }
        $else = $n->else === null ? null
            : new \Parser\Ast\Block(__mc_rewrite_file_returns($n->else->statements, $slot, $label));
        return \Parser\Ast\Stmt::if_(
            $n->condition,
            new \Parser\Ast\Block(__mc_rewrite_file_returns($n->then->statements, $slot, $label)),
            $elseifs,
            $else,
            $s->span,
        );
    }
    if ($k === 'While') {
        $n = __mc_as_while($s);
        return \Parser\Ast\Stmt::while_(
            $n->condition,
            new \Parser\Ast\Block(__mc_rewrite_file_returns($n->body->statements, $slot, $label)),
            $s->span,
        );
    }
    if ($k === 'DoWhile') {
        $n = __mc_as_dowhile($s);
        return \Parser\Ast\Stmt::doWhile(
            new \Parser\Ast\Block(__mc_rewrite_file_returns($n->body->statements, $slot, $label)),
            $n->condition,
            $s->span,
        );
    }
    if ($k === 'For') {
        $n = __mc_as_for($s);
        return \Parser\Ast\Stmt::for_(
            $n->init,
            $n->condition,
            $n->update,
            new \Parser\Ast\Block(__mc_rewrite_file_returns($n->body->statements, $slot, $label)),
            $s->span,
        );
    }
    if ($k === 'Foreach') {
        $n = __mc_as_foreach($s);
        return \Parser\Ast\Stmt::foreach_(
            $n->expr,
            $n->key,
            $n->value,
            $n->valueByRef,
            new \Parser\Ast\Block(__mc_rewrite_file_returns($n->body->statements, $slot, $label)),
            $s->span,
        );
    }
    if ($k === 'TryCatch') {
        $n = __mc_as_trycatch($s);
        $catches = [];
        foreach ($n->catches as $c) {
            $catches[] = new \Parser\Ast\CatchClause(
                $c->types,
                $c->name,
                new \Parser\Ast\Block(__mc_rewrite_file_returns($c->body->statements, $slot, $label)),
            );
        }
        // The `finally` body is left ALONE: php forbids jumping out of one, so
        // a `return` there is not this transform's to move.
        return \Parser\Ast\Stmt::tryCatch(
            new \Parser\Ast\Block(__mc_rewrite_file_returns($n->try->statements, $slot, $label)),
            $catches,
            $n->finally,
            $s->span,
        );
    }
    if ($k === 'Switch') {
        $n = __mc_as_switch($s);
        $cases = [];
        foreach ($n->cases as $arm) {
            $cases[] = new \Parser\Ast\SwitchArm(
                $arm->value,
                __mc_rewrite_file_returns($arm->body, $slot, $label),
            );
        }
        return \Parser\Ast\Stmt::switch_($n->expr, $cases, $s->span);
    }
    if ($k === 'Namespace') {
        $n = __mc_as_namespace($s);
        $body = $n->body === null ? null
            : new \Parser\Ast\Block(__mc_rewrite_file_returns($n->body->statements, $slot, $label));
        return \Parser\Ast\Stmt::namespace_($n->name, $body, $s->span);
    }
    return $s;
}

function lower_module(array $sources, ?\Analyze\MirDiags $collect = null, array $paths = []): ?\Compile\Mir\Module {
    $stmts = [];
    $aliases = [];
    $docs = [];
    $statT = \Compile\Stats::now();
    $srcBytes = 0;
    foreach ($sources as $source) { $srcBytes = $srcBytes + \strlen($source); }
    \Compile\Stats::line('input: ' . (string)\count($sources) . ' file(s), '
        . (string)$srcBytes . ' bytes');
    // Every file's top-level statements are flattened into ONE `__main`, entry
    // last. A top-level `return` in any file BEFORE the entry is an
    // include-return — the value `require` hands back — and it must not
    // terminate the program. This is not an edge case: composer's
    // `vendor/autoload.php` returns the loader, and every `require`d DATA file
    // (symfony/string's width tables, polyfill-intl-normalizer's unidata) is
    // one `return [...]` of a few thousand entries. Flattened, the FIRST of
    // them ended the program before the entry ran a single statement, with its
    // truncated array pointer as the exit status.
    //
    // `require` is already a no-op here, so its value has no consumer: drop
    // these returns outright. The ENTRY's own `return` is kept — it ends the
    // script, which a mid-flatten one must not — but its VALUE is discarded too
    // ({@see \Compile\Mir\Passes\EmitLlvmModule::emitReturn}): php CLI does not
    // make a top-level return the exit status. Hence the test is "not the last
    // source": position decides whether the statement survives, not its value.
    $lastIdx = \count($sources) - 1;
    /** @var array<string, string> resolved path → value-slot global name */
    $includeSlots = [];
    foreach ($sources as $i => $source) {
        try {
            // The path travels with the source so `__FILE__`/`__DIR__` fold to it at
            // parse time — statements are flattened across every file right below,
            // which loses the per-file identity for good.
            $program = Parser::parseSource($source, __mc_abs_source_path(isset($paths[$i]) ? $paths[$i] : ''));
        } catch (\Throwable $e) {
            $where = isset($paths[$i]) ? $paths[$i] : "<source>";
            dprint($where . ": parse failed: " . $e->getMessage());
            return null;
        }
        $isEntry = $i === $lastIdx;
        $incSlot = __mc_include_slot(isset($paths[$i]) ? $paths[$i] : '', $i);
        $incAbs = __mc_abs_source_path(isset($paths[$i]) ? $paths[$i] : '');
        // A DEMAND-LOADED file (psr-4 / classmap) contributes its DECLARATIONS
        // only. php reads such a file when a class lookup resolves to it and
        // runs its top level THEN — for a class nothing names, never. The eager
        // model has no "then", so hoisting the side effects into `__main` runs
        // them at startup, ahead of the program's own entry.
        //
        // symfony/string's AsciiSlugger is the witness: a file-scope
        // `if (!interface_exists(LocaleAwareInterface::class)) { throw … }`
        // guarding a package that tier 2 deliberately excludes. Seven files in
        // the pinned corpus carry that shape. {@see __mc_stmt_declares} keeps a
        // version-guarded `if (…) { class Foo {} }`, which is why the test is
        // "contains a declaration" and not "is one".
        $srcPath = isset($paths[$i]) ? \rtrim($paths[$i], "/") : '';
        /** @var \Parser\Ast\Stmt[] $topStmts */
        $topStmts = $program->statements;
        if (!$isEntry && $srcPath !== '' && isset(CompileArgs::$demandLoadedPaths[$srcPath])) {
            $kept = [];
            foreach ($topStmts as $s) {
                if (__mc_stmt_declares($s)) { $kept[] = $s; }
            }
            $topStmts = $kept;
        }
        // Every compiled file is registered, with an EMPTY slot until one of its
        // top-level `return`s claims it. That distinction is the difference
        // between php's three answers: a file that returned a value gives it, a
        // file that returned nothing gives int(1), and a path that is not a
        // compile unit at all gives false — which is what php's `include` of a
        // missing file gives. Without the full path set the last two collapse.
        if ($incAbs !== '' && !$isEntry && !isset($includeSlots[$incAbs])) {
            $includeSlots[$incAbs] = '';
        }
        // A non-entry file's top-level `return` — at ANY depth, not just as a
        // top-level statement — ends THAT FILE in php and hands its value back.
        // Flattened into one `__main` it would end the PROGRAM, and since the
        // entry is last, the entry would never run. Rewrite each one to "store
        // the value, jump past this file"; the label goes after its statements.
        //
        // This is not a corner: `vendor/autoload.php` returns the loader, and
        // every symfony polyfill bootstrap is a version-guarded `return
        // require …`. Nested in an `if`, those used to silently kill the whole
        // program — a composer application compiled and then did nothing.
        $retKind = $isEntry ? 0 : __mc_file_return_kind($topStmts);
        if ($retKind > 0) {
            // Any return claims the slot, valued or not — a bare `return`
            // stores NULL, which is what php's `require` of it evaluates to.
            $slotFor = $incSlot;
            $span0 = $topStmts[0]->span;
            if ($slotFor !== '') {
                $includeSlots[$incAbs] = $slotFor;
                // Seed the slot UNCONDITIONALLY, before the file's own
                // statements, with php's answer for a file that reaches its end
                // without returning: int(1). Two things depend on it.
                //
                // It is what the value must be when a CONDITIONAL return is not
                // taken — the store at the return site only runs on the path
                // that returned. And it is the only thing that guarantees the
                // `@g_<slot>` cell EXISTS: the cell is created by a write, and
                // every write inside a version-guarded `if` now folds away with
                // its branch, which left `require` loading an undefined global.
                $stmts[] = \Parser\Ast\Stmt::expression(
                    \Parser\Ast\Expr::assign(
                        \Parser\Ast\Expr::arrayAccess(
                            \Parser\Ast\Expr::variable('GLOBALS', $span0),
                            \Parser\Ast\Expr::string($slotFor, $span0),
                            $span0,
                        ),
                        \Parser\Ast\Expr::call(
                            '__mir_to_cell',
                            [\Parser\Ast\Expr::int(1, $span0)],
                            $span0,
                        ),
                        $span0,
                    ),
                    $span0,
                );
            }
            $eofLabel = '__mc_eof_' . (string)$i;
            foreach (__mc_rewrite_file_returns($topStmts, $slotFor, $eofLabel) as $s) {
                $stmts[] = $s;
            }
            $stmts[] = new \Parser\Ast\LabelStmt($eofLabel, $span0);
            foreach ($program->useAliases as $short => $fqn) { $aliases[$short] = $fqn; }
            foreach ($program->docComments as $d) { $docs[] = $d; }
            continue;
        }
        foreach ($topStmts as $s) {
            if (!$isEntry && $s->kind === 'Return') {
                // Its VALUE is what `require`/`include` of this file evaluates
                // to, and it used to be thrown away with the statement. Keep the
                // value in a per-file global instead: the statements around it
                // still run inline, in the same order, so nothing about when
                // this file executes changes — only that its result survives to
                // be read back. Written through $GLOBALS so a `require` inside
                // any FUNCTION can reach it, not just __main's own scope.
                if ($incSlot !== '' && $s->value !== null) {
                    $includeSlots[$incAbs] = $incSlot;
                    $stmts[] = \Parser\Ast\Stmt::expression(
                        \Parser\Ast\Expr::assign(
                            \Parser\Ast\Expr::arrayAccess(
                                \Parser\Ast\Expr::variable('GLOBALS', $s->span),
                                \Parser\Ast\Expr::string($incSlot, $s->span),
                                $s->span,
                            ),
                            // Boxed on the way in: the slot has to hold an
                            // array, a closure or a scalar interchangeably, and
                            // the reader (a `require` expression) is typed cell
                            // because nothing static covers that union. Stored
                            // raw, an array pointer read back as its own bits.
                            \Parser\Ast\Expr::call('__mir_to_cell', [$s->value], $s->span),
                            $s->span,
                        ),
                        $s->span,
                    );
                }
                continue;
            }
            $stmts[] = $s;
        }
        foreach ($program->useAliases as $short => $fqn) { $aliases[$short] = $fqn; }
        foreach ($program->docComments as $d) { $docs[] = $d; }
    }
    \Compile\Stats::step('parse', $statT, \count($stmts), -1);
    $statT = \Compile\Stats::now();
    // What the program DEMANDS of the prelude, asked of the tokens. A substring
    // gate cannot tell a call from a mention, and this compiler is made of the
    // names it implements — `var_dump(` in a doc comment used to pull the whole
    // var_dump runtime (per-class __mir_dump_object, ~58k IR lines) into the
    // compiler's own binary. See Compile\Mir\PreludeDemand.
    $demand = new \Compile\Mir\PreludeDemand($sources);
    \Compile\Stats::step('prelude-demand (re-lex)', $statT, -1, -1);

    // The on-disk prelude sources. Reading them goes through the libc fopen
    // binding (a throwing stub under the Zend cold-seed), so guard: an
    // unreadable file provides nothing, and LowerFromAst falls back to its
    // embedded copy for the classes the bootstrap cannot live without.
    $statT = \Compile\Stats::now();
    $arrayFnsSrc = prelude_src_or_empty("array_fns.php");
    $arrayFnsExtSrc = prelude_src_or_empty("array_fns_ext.php");
    $cliSrc = prelude_src_or_empty("cli.php");
    $printRSrc = prelude_src_or_empty("print_r.php");
    $arrayClassesSrc = prelude_src_or_empty("spl_arrays.php");
    $reflectionSrc = prelude_src_or_empty("reflection.php");
    $attributesSrc = prelude_src_or_empty("attributes.php");
    $dateTimeSrc = prelude_src_or_empty("datetime.php");
    // Fiber (stackful coroutines) — DEMAND-GATED. Must NOT be unconditional:
    // the preamble emits arch-branched `module asm` under needsFibers, and
    // host_arch() is a libc-stub under the Zend cold seed, so a fiber-free
    // build (the compiler's own) must never pull \Fiber in.
    $fiberSrc = prelude_src_or_empty("fiber.php");
    // Io\Poll (PHP 8.6 fd-readiness multiplexer) — DEMAND-GATED, namespaced class
    // tree (braced namespaces isolate it in the prelude blob).
    $ioPollSrc = prelude_src_or_empty("io_poll.php");
    // Error / exception handlers + the shutdown queue. Gated on a CALL, like
    // array_fns: the handlers hold CALLABLES, so the file cannot live in the
    // stdlib .o, and a program that never touches them must not carry the
    // static registries (nor the atexit hook that drains them).
    $errorsSrc = prelude_src_or_empty("errors.php");
    // pack/unpack — prelude, not stdlib: `pack` is variadic and a variadic
    // cannot cross the stdlib.o boundary.
    $binarySrc = prelude_src_or_empty("binary.php");
    // ext/session — DEMAND-GATED. Interfaces + a handler OBJECT + user CALLABLES
    // + $_SESSION + serialize(): every one of those is a reason it cannot live in
    // the stdlib .o. Implies sapi (setcookie/headers_sent), serialize/unserialize
    // (the encoders) and errors (the end-of-request write-close).
    $sessionSrc = prelude_src_or_empty("session.php");
    // Response headers / cookies / the per-request context — DEMAND-GATED. Holds
    // ARRAYS (the header block, the parked superglobals), so the stdlib .o cannot
    // carry it; the one scalar it needs there is __mc_out_sent.
    $sapiSrc = prelude_src_or_empty("sapi.php");
    // Async\ (scheduler / tasks / channels / netpoller seam) — DEMAND-GATED,
    // braced-namespace tree. Built ON Fiber + Io\Poll, so it forces both on.
    $asyncSrc = prelude_src_or_empty("async.php");
    // ext/pcntl + posix process control — DEMAND-GATED, braced-namespace tree.
    $pcntlSrc = prelude_src_or_empty("pcntl.php");
    // Buffer\ (ByteBuffer + reader/writer) — DEMAND-GATED, braced-namespace tree.
    // Holds a mutable string + cursor as OBJECT fields, so no stdlib signature
    // could name it even if the parsing lived there.
    $bufferSrc = prelude_src_or_empty("buffer.php");
    // Http\ (server / request / response / the wire codec) — DEMAND-GATED,
    // braced-namespace tree. Wholly in the prelude, including the parts that
    // would fit the stdlib .o, because the .sig re-erases every array element
    // type that crosses it and cannot name a prelude class at all; one
    // compilation unit is what lets the parser hand real objects around.
    $httpSrc = prelude_src_or_empty("http.php");
    // serialize / unserialize — DEMAND-GATED, and gated SEPARATELY (two files):
    // each one generates a per-class arm set from the class table, so a program
    // that only serializes must not pay for unserialize's rebuild arms.
    $serializeSrc = prelude_src_or_empty("serialize.php");
    $unserializeSrc = prelude_src_or_empty("unserialize.php");
    // var_export's recursive walk — DEMAND-GATED like var_dump, and in the
    // prelude for the same reason: its object arm is generated per class table,
    // and the stdlib .o cannot be handed an object.
    $varExportSrc = prelude_src_or_empty("var_export.php");
    // ob_* — DEMAND-GATED. In the prelude, not the stdlib .o, because an
    // ob_start() handler is a CALLABLE; the buffered BYTES live in the codegen
    // runtime instead, which is the only thing both objects can see.
    $obSrc = prelude_src_or_empty("ob.php");
    $autoloadSrc = prelude_src_or_empty("autoload.php");
    // ext/simplexml + ext/libxml + ext/dom — DEMAND-GATED, global namespace.
    // In the prelude and not the stdlib .o for the usual reason: the .sig
    // carries functions only, so a SimpleXMLElement declared there would be
    // invisible to the program holding one. The three files are one module —
    // xml_xpath rides xml unconditionally (SimpleXMLElement::xpath calls it),
    // and xml_dom is gated on its own so a SimpleXML-only program pays nothing
    // for the DOM class tree.
    $xmlSrc = prelude_src_or_empty("xml.php");
    $xmlXpathSrc = prelude_src_or_empty("xml_xpath.php");
    $xmlDomSrc = prelude_src_or_empty("xml_dom.php");
    // ext/curl — DEMAND-GATED, global namespace, prelude and not the stdlib for
    // the same reason as xml: curl_init() returns a CurlHandle. curl_multi.php
    // is gated apart so a program making one request at a time does not carry
    // the multi/share half.
    $curlSrc = prelude_src_or_empty("curl.php");
    $curlMultiSrc = prelude_src_or_empty("curl_multi.php");

    // ext/pdo — DEMAND-GATED, global namespace, prelude and not the stdlib for
    // the same reason as curl: `new PDO(...)` hands back an object and
    // PDO::FETCH_ASSOC is a class constant, neither of which a `.sig` carries.
    // pdo.php is the driver-agnostic facade; pdo_sqlite.php implements its
    // driver seam over libsqlite3 and is what puts -lsqlite3 on the link line.
    $pdoSrc = prelude_src_or_empty("pdo.php");
    $pdoSqliteSrc = prelude_src_or_empty("pdo_sqlite.php");

    // ext/tokenizer, deliberately TWO files. tokenizer.php names nothing Zend
    // owns, so it stays require-able under `php` itself and tools/tokenizer_diff.php
    // can diff our scanner against the C tokenizer with no build in between;
    // tokenizer_api.php declares PhpToken/token_get_all/token_name and cannot.
    // Order is load-bearing — token_get_all() constructs __McTok.
    $tokenizerSrc = prelude_src_or_empty("tokenizer.php");
    $tokenizerApiSrc = prelude_src_or_empty("tokenizer_api.php");
    \Compile\Stats::step('prelude read (all files)', $statT, -1, -1);

    // array_fns gates on the functions the FILE defines (sort/usort/explode/…),
    // so adding one there needs no second edit here. These live in the prelude,
    // not the stdlib .o, so injecting the file cannot double-define anything.
    $useArrayFns = $demand->callsAny(\Compile\Mir\PreludeDemand::definedFunctions($arrayFnsSrc));
    // The EXTENDED array functions are a SEPARATE gate on purpose: array_fns is
    // injected into the compiler's own build (src/ calls array_map/sort), and a
    // prelude is injected WHOLE — so a miscompile in any function sharing that
    // file breaks generation 2 of the self-host. Nothing in src/ calls these.
    $useArrayFnsExt = $demand->callsAny(\Compile\Mir\PreludeDemand::definedFunctions($arrayFnsExtSrc));
    // Same definedFunctions gate: adding an ob_* function to prelude/ob.php
    // needs no second edit here.
    $useOb = $demand->callsAny(\Compile\Mir\PreludeDemand::definedFunctions($obSrc));
    // Same definedFunctions gate: adding a function to prelude/autoload.php
    // enrols it automatically, no second list to keep in step.
    $useAutoload = $demand->callsAny(\Compile\Mir\PreludeDemand::definedFunctions($autoloadSrc));
    // `array_multisort` is DESUGARED at the call site (LowerExprs) into
    // __mc_multisort_order + __mc_multisort_apply, so the user's source never
    // names either helper and the definedFunctions gate above cannot see the
    // demand. Gate on the name the program actually writes.
    if ($demand->callsAny(['array_multisort'])) { $useArrayFnsExt = true; }
    $useArrayClasses = $demand->mentionsAny(['ArrayIterator', 'ArrayObject'])
        // iterator_to_array / _count / _apply are plain FUNCTIONS in the same
        // file (they drain a Traversable, so they cannot live in the stdlib).
        // A program may call one without ever naming an SPL array class.
        || $demand->callsAny(['iterator_to_array', 'iterator_count', 'iterator_apply']);
    // `new Fiber(...)`, a `Fiber` hint, or `Fiber::suspend(...)` all mention it.
    $useFiber = $demand->mentionsAny(['Fiber']);
    // `new \Io\Poll\Context`, a `use Io\Poll\...`, or `new StreamPollHandle` all
    // mention one of these identifiers.
    $useIoPoll = $demand->mentionsAny(['StreamPollHandle', 'Poll', 'IoException']);
    // The error/shutdown family. `trigger_error` is included even though the
    // lowering rewrites it to `__mc_trigger_error` — the gate reads the SOURCE,
    // which still spells the php name.
    $useBinary = $demand->callsAny(['pack', 'unpack']);
    $useErrors = $demand->callsAny(['set_error_handler', 'restore_error_handler',
                                    'set_exception_handler', 'restore_exception_handler',
                                    'register_shutdown_function', 'trigger_error',
                                    'error_reporting', 'error_get_last']);
    // Async\: `use function Async\spawn`, `\Async\async(...)`, `use Async\TaskGroup`
    // — the Lexer emits the namespace qualifier as its own Identifier, so the
    // `Async` mention is the reliable gate. NOT gated on definedFunctions(): this
    // module provides read/write/close/select/connect, names any program may own.
    $useAsync = $demand->mentionsAny(['Async', 'TaskGroup', 'CancelledException',
                                      'DeadlockException']);
    // ext/pcntl gates on the `pcntl_*` / `posix_*` names the FILE defines — those
    // are prefixed, so no program owns them. The file ALSO defines a `Process\`
    // namespace whose members are fork/pid/workers/supervise; those are gated on
    // the `Process` qualifier instead, exactly as async.php is on `Async`,
    // because a program may very well own a function called `workers()`.
    $pcntlFns = [];
    foreach (\Compile\Mir\PreludeDemand::definedFunctions($pcntlSrc) as $fn) {
        if (\str_starts_with($fn, 'pcntl_') || \str_starts_with($fn, 'posix_')) { $pcntlFns[] = $fn; }
    }
    $usePcntl = $demand->callsAny($pcntlFns) || $demand->mentions('Process');
    // header/setcookie/the request seam, gated on the functions the FILE defines.
    //
    // A program that DEFINES one of those names gets its own — php would fatal
    // ("Cannot redeclare function header()"), and injecting on top of it emits
    // two definitions of one symbol, which fails in clang with no useful
    // diagnostic. Bowing out is the readable answer.
    $sapiFns = \Compile\Mir\PreludeDemand::definedFunctions($sapiSrc);
    $ownFns = [];
    foreach ($sources as $usrc) {
        foreach (\Compile\Mir\PreludeDemand::definedFunctions($usrc) as $fn) { $ownFns[$fn] = true; }
    }
    $sapiClash = false;
    foreach ($sapiFns as $fn) { if (isset($ownFns[$fn])) { $sapiClash = true; } }
    $useSapi = !$sapiClash && $demand->callsAny($sapiFns);
    // session_* + the handler interfaces a program may only MENTION (a class that
    // implements SessionHandlerInterface without calling a session_* function).
    $sessionFns = \Compile\Mir\PreludeDemand::definedFunctions($sessionSrc);
    $sessionClash = $sapiClash;
    foreach ($sessionFns as $fn) { if (isset($ownFns[$fn])) { $sessionClash = true; } }
    $useSession = !$sessionClash && ($demand->callsAny($sessionFns)
        || $demand->mentionsAny(['SessionHandler', 'SessionHandlerInterface',
                                'SessionIdInterface', 'SessionUpdateTimestampHandlerInterface'])
        || $demand->usesVar('_SESSION'));
    // Http\ gates on the QUALIFIER, exactly as Async\ and Process\ do one gate
    // up: Server, Request and Response are three of the most-owned class names
    // in PHP, and nobody reaches this namespace without writing `Http\`.
    // Buffer\ gates on the QUALIFIER, exactly as Async\, Process\ and Http\ do:
    // `ByteBuffer`, `Reader` and `Writer` are names a program may very well own,
    // and nobody reaches this namespace without writing `Buffer\`.
    $useBuffer = $demand->mentions('Buffer');
    $useHttp = $demand->mentions('Http');
    if ($useHttp && $sapiClash) {
        // The server runs every handler with the request seam live, so it cannot
        // bow out of sapi.php the way a plain program does — and injecting on top
        // of the program's own header() emits two definitions of one symbol.
        dprint("compile failed: Http\\ needs the request seam (header/setcookie/"
             . "http_response_code), but this program defines those names itself");
        return null;
    }
    if ($useHttp) {
        // The Server IS an Async\ accept loop, and every handler runs inside the
        // request seam: header()/setcookie()/http_response_code() are absorbed
        // into the Response it returns, and echo is funnelled through ob_start().
        // Forced HERE, before the $useAsync fan-out below and long before
        // $useCli, which reads $useSapi.
        $useBuffer = true;
        $useAsync = true;
        $useSapi = true;
        $useOb = true;
    }
    if ($useAsync) {
        // The engine IS a fiber loop over an Io\Poll reactor — it cannot compile
        // without either, whatever the program itself mentions. It also dispatches
        // signals every tick, so the pcntl layer has to be there too.
        $useFiber = true;
        $useIoPoll = true;
        $usePcntl = true;
    }
    if ($useSession) {
        // ext/session is built ON the request seam (setcookie, headers_sent,
        // __McSapi::$empty) and on the shutdown queue (the implicit
        // end-of-request write-close). Forced HERE, before $useCli, which reads
        // $useSapi; the serialize tiers are forced below, after their own gates
        // have run — forcing them here would be overwritten.
        $useSapi = true;
        $useErrors = true;
    }
    // Reflection is gated on a MENTION, like the array classes: `new
    // ReflectionClass(...)` / a `ReflectionClass` hint / a catch of
    // ReflectionException. A program that never reflects carries none of it.
    // This gate decides whether the CLASSES exist; it cannot decide WHICH
    // classes get metadata — PreludeDemand deliberately ignores string
    // literals, so `new ReflectionClass('Foo')` hides Foo from it. That is a
    // separate analysis (ReflectAnalysis).
    $useReflection = $demand->mentionsAny(['ReflectionClass', 'ReflectionObject',
                                           'ReflectionMethod', 'ReflectionProperty',
                                           'ReflectionParameter', 'ReflectionNamedType',
                                           'ReflectionAttribute', 'ReflectionFunction',
                                           'ReflectionClassConstant',
                                           // The enum + composite-type surface. A program
                                           // may name ONLY one of these — `new
                                           // ReflectionEnum(S::class)` mentions no other
                                           // Reflection name — and without them here the
                                           // whole file stayed out: the class was then
                                           // undefined and every call became the
                                           // "Call to undefined method" trap, which is
                                           // what the enum probe's IR actually contained.
                                           'ReflectionEnum', 'ReflectionEnumUnitCase',
                                           'ReflectionEnumBackedCase',
                                           'ReflectionUnionType', 'ReflectionIntersectionType',
                                           'ReflectionException'])
        // get_declared_* are plain FUNCTIONS living in the same file — a program
        // may call one without ever naming a Reflection class, and would then
        // get an undefined symbol (which this toolchain stubs to `return 0`
        // rather than failing).
        || $demand->callsAny(['get_declared_classes', 'get_declared_interfaces',
                              'get_declared_traits', 'class_implements',
                              'get_defined_constants']);
    // PHP's reserved attribute classes. Their SEMANTICS (#[Override] checking,
    // #[Deprecated] / #[NoDiscard] diagnostics, target validation) are entirely
    // compiler-side — the declarations matter only so reflection can hand back a
    // real instance. Hence the `$useReflection &&`: src/Ffi/*.php and
    // src/Manticore/Attr/*.php MENTION `Attribute` in code, so a bare mention
    // gate would inject 9 classes into the compiler's own build and shift every
    // stableClassId with them, for no runtime benefit.
    // SensitiveParameterValue is exempt — it is a plain value class a program can
    // construct without reflecting on anything.
    $useAttributes = ($useReflection
            && $demand->mentionsAny(['Attribute', 'Deprecated', 'NoDiscard', 'Override',
                                     'SensitiveParameter', 'ReturnTypeWillChange',
                                     'AllowDynamicProperties', 'DelayedTargetValidation']))
        || $demand->mentions('SensitiveParameterValue');
    // The DateTime family gates on a MENTION, like the array and Reflection
    // classes. It can be gated at all only because NO stdlib signature names a
    // DateTime* class — the whole family talks to the stdlib through scalars —
    // so a program that only calls date()/strtotime() carries none of it.
    $useDateTime = $demand->mentionsAny(['DateTime', 'DateTimeImmutable', 'DateTimeZone',
                                         'DateInterval', 'DatePeriod', 'DateTimeInterface',
                                         'DateError', 'DateException',
                                         'DateMalformedStringException',
                                         'DateInvalidTimeZoneException'])
        // The procedural aliases (date_create / date_diff / timezone_open / …)
        // live in the same file because they NAME those classes; a program may
        // call one without ever writing a class name.
        || $demand->callsAny(['date_create', 'date_create_immutable', 'date_create_from_format',
                              'date_format', 'date_timestamp_get', 'date_timestamp_set',
                              'date_offset_get', 'date_timezone_get', 'date_timezone_set',
                              'date_modify', 'date_add', 'date_sub', 'date_diff',
                              'date_date_set', 'date_time_set', 'date_isodate_set',
                              'date_interval_format', 'date_interval_create_from_date_string',
                              'timezone_open', 'timezone_name_get', 'timezone_offset_get',
                              'timezone_transitions_get', 'timezone_location_get',
                              'date_parse', 'date_parse_from_format']);
    // ext/simplexml gates on a MENTION of its classes / its LIBXML_* constants,
    // plus a CALL of the procedural entry points (a program may call
    // simplexml_load_string without ever naming the class). Constants need the
    // mention arm of their own: `$x | LIBXML_NOCDATA` names no class at all.
    $useXml = $demand->mentionsAny(['SimpleXMLElement', 'SimpleXMLIterator', 'LibXMLError',
                                    'LIBXML_NOCDATA', 'LIBXML_NOBLANKS', 'LIBXML_NOENT',
                                    'LIBXML_NOERROR', 'LIBXML_NOWARNING', 'LIBXML_COMPACT',
                                    'LIBXML_PARSEHUGE', 'LIBXML_DTDVALID', 'LIBXML_DTDLOAD',
                                    'LIBXML_NONET', 'LIBXML_NOXMLDECL', 'LIBXML_NOEMPTYTAG',
                                    'LIBXML_SCHEMA_CREATE', 'LIBXML_VERSION',
                                    'LIBXML_ERR_WARNING', 'LIBXML_ERR_ERROR', 'LIBXML_ERR_FATAL'])
        || $demand->callsAny(['simplexml_load_string', 'simplexml_load_file',
                              'simplexml_import_dom', 'dom_import_simplexml',
                              'libxml_use_internal_errors', 'libxml_get_errors',
                              'libxml_clear_errors', 'libxml_get_last_error',
                              'libxml_disable_entity_loader', 'libxml_set_streams_context']);
    // ext/dom rides the SAME node table, so it forces xml on. Gated apart
    // because the DOM class tree is the larger half and most SimpleXML programs
    // never touch it.
    $useXmlDom = $demand->mentionsAny(['DOMDocument', 'DOMNode', 'DOMElement', 'DOMAttr',
                                       'DOMText', 'DOMComment', 'DOMCdataSection',
                                       'DOMNodeList', 'DOMNamedNodeMap', 'DOMXPath',
                                       'DOMDocumentFragment', 'DOMException',
                                       'DOMCharacterData', 'DOMProcessingInstruction'])
        || $demand->callsAny(['dom_import_simplexml', 'simplexml_import_dom']);
    if ($useXmlDom) { $useXml = true; }
    // ext/curl gates on the `curl_*` names the FILE defines — a prefixed family,
    // so no program owns them. Same shape as the pcntl_/posix_ gate below.
    //
    // The MENTION arm is not optional: a program that only writes
    // `$opts[CURLOPT_URL] = $u` and hands the array to a helper in another file
    // CALLS nothing, and a constant reference names no function at all. The
    // class mention covers `function f(CurlHandle $ch)` in a file whose
    // curl_init() lives elsewhere in the same compile.
    $curlFns = [];
    foreach (\Compile\Mir\PreludeDemand::definedFunctions($curlSrc) as $fn) {
        if (\str_starts_with($fn, 'curl_')) { $curlFns[] = $fn; }
    }
    $useCurl = $demand->callsAny($curlFns)
        || $demand->mentionsAny(['CurlHandle', 'CURLOPT_URL', 'CURLOPT_RETURNTRANSFER',
                                 'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_HTTPHEADER',
                                 'CURLOPT_HEADER', 'CURLOPT_NOBODY', 'CURLOPT_FOLLOWLOCATION',
                                 'CURLOPT_TIMEOUT', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_USERAGENT',
                                 'CURLOPT_WRITEFUNCTION', 'CURLOPT_HEADERFUNCTION',
                                 'CURLOPT_SSL_VERIFYPEER', 'CURLINFO_HTTP_CODE',
                                 'CURLINFO_RESPONSE_CODE', 'CURLE_OK']);
    // curl_multi_* / curl_share_* — same prefixed family, own gate, and it
    // forces curl.php on because it names __McCurl and CurlHandle.
    $curlMultiFns = [];
    foreach (\Compile\Mir\PreludeDemand::definedFunctions($curlMultiSrc) as $fn) {
        if (\str_starts_with($fn, 'curl_')) { $curlMultiFns[] = $fn; }
    }
    $useCurlMulti = $demand->callsAny($curlMultiFns)
        || $demand->mentionsAny(['CurlMultiHandle', 'CurlShareHandle', 'CURLM_OK',
                                 'CURLMSG_DONE', 'CURLMOPT_MAXCONNECTS',
                                 'CURLSHOPT_SHARE', 'CURL_LOCK_DATA_COOKIE',
                                 'CURL_LOCK_DATA_DNS']);
    if ($useCurlMulti) { $useCurl = true; }

    // ext/pdo gates on a MENTION and nothing else. PDO is a class family, not a
    // `pdo_*` function prefix, so there is no defined-function list to key on
    // the way curl and pcntl do — and a program that only writes
    // `function repo(PDO $db)` calls nothing at all.
    $usePdo = $demand->mentionsAny(['PDO', 'PDOStatement', 'PDOException', 'PDORow']);
    // ⚠ The sqlite driver rides the facade unconditionally, because a DSN scheme
    // is a runtime STRING and PreludeDemand is token-based: nothing at compile
    // time can tell which driver `new PDO($dsn)` will open. One driver exists,
    // so loading it with the facade is honest. When a second driver lands, this
    // splits the same way curl_multi does — on the driver's own class names.
    $usePdoSqlite = $usePdo;
    // PDO::ERRMODE_WARNING routes through trigger_error, which lives in
    // errors.php — and the demand gate cannot see a call made from the prelude
    // itself, only one made from user code.
    if ($usePdo) { $useErrors = true; }

    // No T_* arm here on purpose: the T_* constants are compile-time folds in
    // LowerPrelude, so a program can use T_STRING with no prelude at all. Only
    // the CALLS and the class need the source.
    $useTokenizer = $demand->callsAny(['token_get_all', 'token_name'])
        || $demand->mentions('PhpToken');
    $useVarDump = $demand->calls('var_dump');
    $useVarExport = $demand->calls('var_export');
    $usePrintR = $demand->calls('print_r');
    // Token-based, so the compiler's own `$fn === 'serialize'` string literals
    // (EmitLlvm::isTagConsumer, CheckTypeDefs::observesObject) demand nothing,
    // and `unserialize(` does not match `serialize`.
    $useSerialize = $demand->calls('serialize');
    $useUnserialize = $demand->calls('unserialize');
    if ($useSession) {
        // The session encoders ARE serialize/unserialize — the `php` handler is
        // `key|serialize(v)` runs, and decoding drives the unserialize prelude's
        // own cursor (__McUnSt) to find where one value ends and the next key
        // begins. A program that never spells either name still needs both.
        $useSerialize = true;
        $useUnserialize = true;
    }
    // CLI prelude (__mc_argv / getopt): $_SERVER and $_ENV are BUILT by it
    // (__mc_server / __mc_env), so they gate it too; the other superglobals seed
    // an empty array literal and need nothing.
    $useCli = $demand->usesVar('argv') || $demand->usesVar('argc')
        || $demand->usesVar('_SERVER') || $demand->usesVar('_ENV')
        || $demand->calls('getopt')
        // no-arg `getenv()` lowers to the $_ENV builder (__mc_env), which lives here.
        || $demand->calls('getenv')
        // sapi.php MENTIONS $_SERVER, and superglobal demand is module-wide: the
        // seed for it is __mc_server(), which lives in cli.php. Without this the
        // request seam would link against an undefined symbol.
        || $useSapi;
    // Stack traces cost a frame push at EVERY call, so instrument only when the
    // program actually QUERIES a trace — the arrow-call form, never the prelude's
    // own `function getTrace(…)` definitions.
    $useBacktrace = $demand->callsAnyMethod(['getTrace', 'getTraceAsString', 'getLine', 'getFile'])
        || $demand->calls('debug_backtrace');

    // The Throwable hierarchy is unconditional, and it calls __mir_bt_frames —
    // supplied either by the real frame builder or by the stub, never both.
    $exceptionsSrc = prelude_src_or_empty("exceptions.php");
    // \Resource is unconditional, like the Throwable hierarchy, and for the same
    // reason: it must be REGISTERED IN EVERY MODULE. The stdlib .sig carries
    // functions only — no classes — so a class living in src/Runtime is invisible
    // to a user program: `$f instanceof Resource` read false while the stdlib's
    // own is_resource() read true, and STDOUT's properties came back as raw bits
    // (float(5.0E-324)). Exceptions already prove the prelude route works: a
    // RuntimeException thrown inside the stdlib is caught by user code.
    // Not PreludeDemand-gated — a resource can arrive from any stdlib call, and
    // a demand scan cannot see that.
    $resourceSrc = prelude_src_or_empty("resource.php");
    $backtraceSrc = prelude_src_or_empty($useBacktrace ? "backtrace.php" : "backtrace_stub.php");
    $varDumpSrc = $useVarDump ? prelude_src_or_empty("var_dump.php") : "";
    if ($useSerialize && $serializeSrc === "") {
        dprint("compile failed: prelude: cannot read serialize.php");
        return null;
    }
    if ($useUnserialize && $unserializeSrc === "") {
        dprint("compile failed: prelude: cannot read unserialize.php");
        return null;
    }
    if ($useVarExport && $varExportSrc === "") {
        dprint("compile failed: prelude: cannot read var_export.php");
        return null;
    }
    if ($useSapi && $sapiSrc === "") {
        dprint("compile failed: prelude: cannot read sapi.php");
        return null;
    }
    if ($useSession && $sessionSrc === "") {
        dprint("compile failed: prelude: cannot read session.php");
        return null;
    }
    if ($useBuffer && $bufferSrc === "") {
        dprint("compile failed: prelude: cannot read buffer.php");
        return null;
    }
    if ($useHttp && $httpSrc === "") {
        dprint("compile failed: prelude: cannot read http.php");
        return null;
    }
    if ($useXml && ($xmlSrc === "" || $xmlXpathSrc === "")) {
        dprint("compile failed: prelude: cannot read xml.php / xml_xpath.php");
        return null;
    }
    if ($useXmlDom && $xmlDomSrc === "") {
        dprint("compile failed: prelude: cannot read xml_dom.php");
        return null;
    }
    if ($useCurl && $curlSrc === "") {
        dprint("compile failed: prelude: cannot read curl.php");
        return null;
    }
    if ($useCurlMulti && $curlMultiSrc === "") {
        dprint("compile failed: prelude: cannot read curl_multi.php");
        return null;
    }
    if ($usePdo && ($pdoSrc === "" || $pdoSqliteSrc === "")) {
        dprint("compile failed: prelude: cannot read pdo.php / pdo_sqlite.php");
        return null;
    }
    if ($useTokenizer && ($tokenizerSrc === "" || $tokenizerApiSrc === "")) {
        dprint("compile failed: prelude: cannot read tokenizer.php / tokenizer_api.php");
        return null;
    }
    if ($exceptionsSrc === "" || $resourceSrc === "" || $backtraceSrc === "" || ($useVarDump && $varDumpSrc === "")) {
        dprint("compile failed: prelude not found (looked in \$MANTICORE_PRELUDE, "
            . "<compiler>/../prelude and <compiler>/../lib/prelude)");
        return null;
    }
    if ($useArrayClasses && $arrayClassesSrc === "") {
        dprint("compile failed: prelude: cannot read spl_arrays.php");
        return null;
    }
    if ($useDateTime && $dateTimeSrc === "") {
        dprint("compile failed: prelude: cannot read datetime.php");
        return null;
    }
    $program = new \Parser\Ast\Program($stmts, '', $aliases, $docs);
    // The pipeline throws RuntimeException on an unsupported construct.
    // Catch it so the compiler reports cleanly (and, in the self-hosted
    // binary, does NOT crash on an uncaught throw — the top-level uncaught
    // path longjmps on an unset handler jmp_buf → PAC fault → SIGSEGV).
    try {
        $module = new \Compile\Mir\Module();
        $module->needsBacktrace = $useBacktrace;
        $module->needsErrorHandlers = $useErrors;
        $module->needsOb = $useOb;
        $module->sourceFile = CompileArgs::$files[0] ?? '';
        $lower = new \Compile\Mir\Passes\LowerFromAst($program);
        $lower->includeVarDump = $useVarDump;
        $lower->includeVarExport = $useVarExport;
        $lower->varExportSrc = $useVarExport ? $varExportSrc : "";
        $lower->includePrintR = $usePrintR;
        $lower->includeSerialize = $useSerialize;
        $lower->serializeSrc = $serializeSrc;
        $lower->includeUnserialize = $useUnserialize;
        $lower->unserializeSrc = $unserializeSrc;
        $lower->includeArrayClasses = $useArrayClasses;
        $lower->includeReflection = $useReflection;
        $lower->includeAttributes = $useAttributes;
        $lower->includeDateTime = $useDateTime;
        $lower->includeArrayFns = $useArrayFns;
        $lower->includeArrayFnsExt = $useArrayFnsExt;
        $lower->includeCli = $useCli;
        // Library targets carry the extra bookkeeping their `.sig` exports.
        $lower->emitLibrary = CompileArgs::$emitLibrary && CompileArgs::$exportTypes;
        $lower->externClassDecls = CompileArgs::$externClassDecls;
        $lower->externClassMeta = CompileArgs::$externClassMeta;
        $lower->externConstants = CompileArgs::$externConstants;
        $lower->exceptionsSrc = $exceptionsSrc;
        $lower->resourceSrc = $resourceSrc;
        $lower->fiberSrc = $useFiber ? $fiberSrc : "";
        $lower->ioPollSrc = $useIoPoll ? $ioPollSrc : "";
        $lower->errorsSrc = $useErrors ? $errorsSrc : "";
        $lower->obSrc = $useOb ? $obSrc : "";
        $lower->autoloadSrc = $useAutoload ? $autoloadSrc : "";
        $lower->binarySrc = $useBinary ? $binarySrc : "";
        $lower->sapiSrc = $useSapi ? $sapiSrc : "";
        $lower->sessionSrc = $useSession ? $sessionSrc : "";
        $lower->asyncSrc = $useAsync ? $asyncSrc : "";
        $lower->pcntlSrc = $usePcntl ? $pcntlSrc : "";
        $lower->bufferSrc = $useBuffer ? $bufferSrc : "";
        $lower->httpSrc = $useHttp ? $httpSrc : "";
        $lower->xmlSrc = $useXml ? $xmlSrc : "";
        $lower->xmlXpathSrc = $useXml ? $xmlXpathSrc : "";
        $lower->xmlDomSrc = $useXmlDom ? $xmlDomSrc : "";
        $lower->curlSrc = $useCurl ? $curlSrc : "";
        $lower->curlMultiSrc = $useCurlMulti ? $curlMultiSrc : "";
        $lower->pdoSrc = $usePdo ? $pdoSrc : "";
        $lower->pdoSqliteSrc = $usePdoSqlite ? $pdoSqliteSrc : "";
        $lower->tokenizerSrc = $useTokenizer ? $tokenizerSrc : "";
        $lower->tokenizerApiSrc = $useTokenizer ? $tokenizerApiSrc : "";
        $lower->backtraceSrc = $backtraceSrc;
        $lower->varDumpSrc = $varDumpSrc;
        $lower->arrayClassesSrc = $arrayClassesSrc;
        $lower->reflectionSrc = $reflectionSrc;
        $lower->attributesSrc = $attributesSrc;
        $lower->dateTimeSrc = $dateTimeSrc;
        $lower->arrayFnsSrc = $arrayFnsSrc;
        $lower->arrayFnsExtSrc = $arrayFnsExtSrc;
        $lower->cliSrc = $cliSrc;
        $lower->printRSrc = $printRSrc;
        // Bundled-stdlib signatures (declare-only externs) so user calls
        // (str_starts_with / ctype_* / file_*) resolve + type, with the body
        // linked from stdlib.o. Collected by cmd_compile on the native path;
        // empty during the Zend bootstrap build and for --emit-library.
        $lower->externDecls = CompileArgs::$externDecls;
        // Reserved-attribute errors (#[Override] with no parent, a bad target, a
        // repeat) abort the build by default; analysis collects them instead.
        if ($collect !== null) { $lower->attrCollectMode = true; }
        $statT = \Compile\Stats::now();
        $module = $lower->run($module);
        // Path → value-slot map for `require`/`include`. Set after lowering
        // because that is when the Module exists; the map itself was built at
        // parse-merge time, the only point that still knows which file each
        // top-level statement came from.
        $module->includeSlots = $includeSlots;
        \Compile\Stats::step('LowerFromAst', $statT, \count($module->functions), \count($module->classes));
        if ($collect !== null) {
            foreach ($lower->attrErrors as $ae) { $collect->lines[] = $ae; }
        }
        CompileArgs::$linkStdlib = $lower->externInjected;
        // The AST is DEAD from here: lowering produced the module and the two
        // fields read just above were the last of it. Nothing below this line
        // touches $lower / $program / $stmts — but they kept the whole tree
        // reachable for the rest of the compile, INCLUDING the clang run, which
        // is 12 of the 13 seconds on a 510 KB input. Measured: the AST for that
        // input is 86 MB (~170x the source), and a 2 MB input carries 1.5 M live
        // 64-byte nodes. Dropping the references here is the only thing that
        // frees them — the runtime is refcounted and an AST is a tree, so the
        // release is immediate and complete.
        $stmts = [];
        $program = null;
        $lower = null;
        $statT = \Compile\Stats::now();
        $fold = new \Compile\Mir\Passes\ConstFold();
        $module = $fold->run($module);
        \Compile\Stats::step('ConstFold', $statT, \count($module->functions), -1);
        $statT = \Compile\Stats::now();
        $dse = new \Compile\Mir\Passes\DeadStore();
        $module = $dse->run($module);
        \Compile\Stats::step('DeadStore', $statT, \count($module->functions), -1);
        $statT = \Compile\Stats::now();
        $infer = new \Compile\Mir\Passes\InferTypes();
        $module = $infer->run($module);
        \Compile\Stats::step('InferTypes #1', $statT, \count($module->functions), -1);
        // Define the locals whose only definition is a by-ref ARGUMENT position
        // (php's out-parameter spelling). Runs here because a MethodCall_
        // receiver has no class before inference, and because InferTypes #2
        // below types the inits it plants. Must follow DeadStore — a store
        // inserted earlier would be a DSE candidate.
        $statT = \Compile\Stats::now();
        $module = (new \Compile\Mir\Passes\VivifyRefArgs())->run($module);
        \Compile\Stats::step('VivifyRefArgs', $statT, \count($module->functions), -1);
        // Narrow CONCRETE, param-independent bare-`array` returns now (a literal
        // `mk(){ return ["x"=>1]; }` → assoc[string,int]) so a call-site
        // `array_filter(mk(), …)` fuses on a concrete element and its result is
        // typed for the consumer (an erased vec[unknown] would read raw elements
        // as cells across a boxing boundary). Erased-param helpers (whose return
        // Monomorphize re-shapes) are skipped → the full post-Mono NarrowReturns
        // handles them.
        $statT = \Compile\Stats::now();
        $module = (new \Compile\Mir\Passes\NarrowReturns(true))->run($module);
        \Compile\Stats::step('NarrowReturns (concreteOnly)', $statT, \count($module->functions), -1);
        $statT = \Compile\Stats::now();
        $module = (new \Compile\Mir\Passes\InferTypes())->run($module);
        \Compile\Stats::step('InferTypes #2', $statT, \count($module->functions), -1);
        // Eliminate the boxed-cell closure ABI where it's avoidable: inline
        // captureless single-expr arrow closures at known invoke sites, and
        // fuse array_map/array_filter/array_reduce over a concrete array with a
        // literal closure into a native typed loop. Re-infer so the spliced /
        // fused expressions type from their (now concrete) operands.
        $statT = \Compile\Stats::now();
        $inlineCl = new \Compile\Mir\Passes\InlineClosures();
        $module = $inlineCl->run($module);
        \Compile\Stats::step('InlineClosures', $statT, \count($module->functions), -1);
        $statT = \Compile\Stats::now();
        $module = (new \Compile\Mir\Passes\InferTypes())->run($module);
        \Compile\Stats::step('InferTypes #3', $statT, \count($module->functions), -1);
        // Specialize erased-array / polymorphic functions per call-site
        // argument shape (runs after InferTypes so call-arg types are known;
        // re-runs InferTypes internally when it specializes anything).
        $statT = \Compile\Stats::now();
        $mono = new \Compile\Mir\Passes\Monomorphize();
        $module = $mono->run($module);
        \Compile\Stats::step('Monomorphize', $statT, \count($module->functions), -1);
        // Fuse implode(explode()) split-join round-trips into one native
        // str_replace (zero intermediate array/segment allocs). After Mono so
        // types + explode arg-counts are settled; before InferEffects so the
        // analysis sees the fused form.
        $statT = \Compile\Stats::now();
        $module = (new \Compile\Mir\Passes\FuseSplitJoin())->run($module);
        \Compile\Stats::step('FuseSplitJoin', $statT, \count($module->functions), -1);
        // Gated compile-time type checker (MANTICORE_TYPECHECK=1). Off by
        // default — it never runs during a normal build / self-host. When on,
        // any genuinely-incompatible type use (array↔scalar / object↔scalar at
        // a call arg or return) is fatal.
        // Strict static analyzer (MANTICORE_TYPECHECK=1) — OFF by default for
        // now; turning it on is a larger epic (the cold-seed / self-host corpus
        // still leans on patterns it would flag). The pass already emits clean
        // `line N: error: …` diagnostics (string arithmetic, array-ness arg /
        // return mismatches) when enabled. Any reported error is fatal — a clean
        // diagnostic beats a downstream clang failure or wrong codegen.
        // ANALYSIS MODE ($collect set): run the checker unconditionally and
        // COLLECT its findings for the `analyze` command instead of aborting.
        // The array-REPRESENTATION conflict check ({@see TypeCheck::$reprOnly})
        // is the one rule here that flags a MISCOMPILE rather than a style
        // opinion: it mirrors the codegen's own key/element reader choice, so a
        // hit means the callee walks the buffer at the wrong type — the silent
        // SIGSEGV that `array<string,V>` fed an int-keyed array produces.
        //
        // It is therefore FATAL BY DEFAULT — the one rule here that is. The
        // self-host source was made honest first: the `name => Type` maps and
        // the set-shaped params now carry `array<string, …>` docblocks instead
        // of a bare `array` that inference guessed `vec` for. Compiler and all
        // 573 AOT cases report zero hits, so a NEW conflict is a compile error
        // instead of a SIGSEGV at run time.
        $tcFlag = \getenv("MANTICORE_TYPECHECK");
        $tcOn = $collect !== null || (\is_string($tcFlag) && $tcFlag !== "" && $tcFlag !== "0");
        {
            $statT = \Compile\Stats::now();
            $tc = new \Compile\Mir\Passes\TypeCheck();
            $tc->reprOnly = !$tcOn;
            $module = $tc->run($module);
            \Compile\Stats::step('TypeCheck', $statT, \count($module->functions), -1);
            if ($collect !== null) {
                foreach ($tc->errors as $te) { $collect->lines[] = $te; }
            } elseif (\count($tc->errors) > 0) {
                foreach ($tc->errors as $te) { dprint($te); }
                return null;
            }
        }
        $statT = \Compile\Stats::now();
        $narrow = new \Compile\Mir\Passes\NarrowReturns();
        $module = $narrow->run($module);
        \Compile\Stats::step('NarrowReturns (full)', $statT, \count($module->functions), -1);
        // The `#[TypeDef]` soundness gate: an erased value must never reach a
        // site that would observe it AS AN OBJECT (`===`, instanceof, var_dump, a
        // `mixed` slot). Runs once types are final and before any memory pass —
        // a boxed cell downstream has already lost the marker. Throws; the catch
        // below turns it into a compile error.
        $statT = \Compile\Stats::now();
        $checkTypeDefs = new \Compile\Mir\Passes\CheckTypeDefs();
        if ($collect !== null) { $checkTypeDefs->collectMode = true; }
        $module = $checkTypeDefs->run($module);
        \Compile\Stats::step('CheckTypeDefs', $statT, \count($module->functions), -1);
        if ($collect !== null) {
            foreach ($checkTypeDefs->errors as $te) { $collect->lines[] = $te; }
            // Analysis needs nothing past the type checks; the memory passes are
            // codegen-only and can crash on the very unsoundness just collected.
            return $module;
        }
        // `$s[$i]` mints a fresh 1-char string — a malloc per character read.
        // Where the character is only ever compared to a one-char literal or
        // passed to ord(), read the byte instead. Before the memory passes, so rc
        // never sees the strings that are no longer created.
        $statT = \Compile\Stats::now();
        $refl = new \Compile\Mir\Passes\ReflectAnalysis();
        $refl->run($module);
        \Compile\Stats::step('ReflectAnalysis', $statT, -1, \count($module->classes));
        $module->reflectAll = $refl->all;
        $module->reflectNames = $refl->names;
        if (\Compile\Debug::$reflectReport) {
            // Built with an explicit loop, NOT
            //   $rnames = $refl->all ? ['<ALL>'] : \array_keys($refl->names);
            // That ternary unions two array sources; its elements then read back
            // as raw pointers under the native self-build and implode() SIGSEGVs
            // the compiler. Green under Zend. See [[reflection-epic]].
            /** @var string[] $rnames */
            $rnames = [];
            if ($refl->all) {
                $rnames[] = '<ALL — an unresolved name escaped>';
            } else {
                foreach ($refl->names as $rn => $rv) { $rnames[] = $rn; }
            }
            dprint('reflect: ' . (string)\count($rnames) . ' class(es) carry metadata: '
                . \implode(', ', $rnames));
        }
        $statT = \Compile\Stats::now();
        $module = (new \Compile\Mir\Passes\DemoteCharLocals())->run($module);
        \Compile\Stats::step('DemoteCharLocals', $statT, -1, -1);
        $statT = \Compile\Stats::now();
        $effects = new \Compile\Mir\Passes\InferEffects();
        $module = $effects->run($module);
        \Compile\Stats::step('InferEffects', $statT, -1, -1);
        $statT = \Compile\Stats::now();
        $allocKind = new \Compile\Mir\Passes\InferAllocKind();
        $module = $allocKind->run($module);
        \Compile\Stats::step('InferAllocKind', $statT, -1, -1);
        $statT = \Compile\Stats::now();
        $memMode = new \Compile\Mir\Passes\ApplyMemoryMode(CompileArgs::$memory);
        $module = $memMode->run($module);
        \Compile\Stats::step('ApplyMemoryMode', $statT, -1, -1);
        $statT = \Compile\Stats::now();
        $memOps = new \Compile\Mir\Passes\InsertMemoryOps();
        $module = $memOps->run($module);
        \Compile\Stats::step('InsertMemoryOps', $statT, -1, -1);
        $statT = \Compile\Stats::now();
        $verify = new \Compile\Mir\Passes\Verify();
        $module = $verify->run($module);
        \Compile\Stats::step('Verify', $statT, \count($module->functions), \count($module->classes));
        return $module;
    } catch (\Throwable $e) {
        dprint("compile failed: " . $e->getMessage());
        return null;
    }
}

function compile_via_mir(array $sources, array $paths = []): ?string {
    $module = lower_module($sources, null, $paths);
    if ($module === null) { return null; }
    try {
        $emit = new \Compile\Mir\Passes\EmitLlvm();
        $emit->emitLibrary = CompileArgs::$emitLibrary;
        $ir = $emit->emit($module);
        // The module's link requirements, captured before $emit goes out of
        // scope — the cc step below runs long after emission.
        CompileArgs::$ffiLibs = \array_keys($emit->ffiLibs);
        CompileArgs::$weakSyms = \array_keys($emit->weakSyms);
        return $ir;
    } catch (\Throwable $e) {
        dprint("compile failed (emit): " . $e->getMessage(). " ({$e->getFile()}:{$e->getLine()})");
        return null;
    }
}

function cmd_dump_llvm_mir(array $args): int {
    if (!parse_compile_args($args)) { return 64; }
    $sources = resolve_sources(CompileArgs::$files);
    if ($sources === null) { return 66; }
    if (\count($sources) === 0) { return 66; }
    $ir = compile_via_mir($sources);
    if ($ir === null) { return 65; }
    puts($ir);
    return 0;
}

/**
 * Emit the module-interface `.sig` (JSON) for a source set on stdout — the
 * exported public symbol table a dependent target imports. Same front-end as
 * compile; no codegen. Used standalone for inspection and by `build` to write
 * each library's `<output>.sig`.
 *
 * @param string[] $args
 */
function cmd_dump_sig(array $args): int {
    if (!parse_compile_args($args)) { return 64; }
    $sources = resolve_sources(CompileArgs::$files);
    if ($sources === null) { return 66; }
    if (\count($sources) === 0) { return 66; }
    $module = lower_module($sources);
    if ($module === null) { return 65; }
    // Empty libs/weak: this command runs the front end only, and the link
    // requirements are a property of what the EMITTER produced. Inspecting a
    // module's signatures never links anything, so there is nothing to record.
    puts(Sig::emitModule($module));
    return 0;
}

/**
 * Static analysis: parse the source set, run the analyzer's rule battery, and
 * print `path:line:col: severity: message` diagnostics. Read-only — no lowering,
 * no codegen, and NOT wired into the compile pipeline, so it can never perturb
 * the self-host build (the reason a strict analyzer lives here as its own
 * command rather than an on-by-default pass).
 *
 * Exit: 0 when clean, 1 when any error-severity diagnostic is reported, 64 on
 * bad args, 66 when no input is read. A parse failure becomes a diagnostic
 * (collected across every file) rather than aborting the run.
 *
 * @param string[] $args
 */
/**
 * Parse the prelude sources into the analyzer's symbol universe so its built-in
 * classes (the Throwable/Exception/Error hierarchy, Resource, Reflection) and
 * functions are KNOWN — otherwise the closed-world undefined-symbol rules would
 * flag every `catch (Exception)` / `new RuntimeException`. Resolved the same way
 * the compiler resolves the prelude (`prelude_src_or_empty`). Best-effort: a
 * missing / unparseable prelude file is skipped, not fatal.
 *
 * @return \Analyze\ParsedFile[]
 */
function analyze_prelude_files(): array {
    // The prelude file set (stable). Class-defining files matter most for the
    // undefined-class rule; loading all also seeds prelude functions.
    $names = [
        "exceptions.php", "resource.php", "reflection.php", "spl_arrays.php",
        "array_fns.php", "backtrace.php", "cli.php", "print_r.php", "var_dump.php",
        "datetime.php", "errors.php", "binary.php",
        "serialize.php", "unserialize.php",
        // \Fiber (fiber.php) and the Io\Poll\* class tree (io_poll.php) are
        // DEMAND-GATED at compile time (Main::lower_module), but the analyzer's
        // undefined-symbol rules run closed-world across the whole source set —
        // so they need every prelude class the user program can name. Without
        // these, `new \Fiber(...)`, an `\Io\Poll\Context` hint, or the
        // `StreamPollHandle` handle read as unknown classes.
        "fiber.php", "io_poll.php", "async.php", "pcntl.php",
        // Function-only prelude files. Omitting these is invisible in a normal
        // build (they are injected on demand all the same) but makes the
        // closed-world undefined-function rule report every one of their
        // declarations — `array_splice`, `array_replace_recursive`,
        // `array_walk_recursive`, `array_diff_ukey`, `var_export`, the reserved
        // attribute classes — against code that runs correctly.
        // `tools/audit/calibrate.sh` gates this list against `prelude/*.php`.
        "array_fns_ext.php", "attributes.php", "backtrace_stub.php", "var_export.php",
        "ob.php", "autoload.php", "sapi.php", "session.php",
        // The Buffer\ and Http\ class trees, same reasoning as the demand-gated
        // trees above: closed-world analysis must know every prelude class a
        // user program can name.
        "buffer.php", "http.php",
        // ext/simplexml + ext/dom: SimpleXMLElement, DOMDocument and the node
        // tree are prelude CLASSES, so closed-world analysis needs them for the
        // same reason as Buffer\/Http\.
        "xml.php", "xml_xpath.php", "xml_dom.php",
        // Same reason: PhpToken is demand-gated at compile time, but the
        // analyzer is closed-world and would read it as an unknown class.
        "tokenizer.php", "tokenizer_api.php",
        // And again for CurlHandle / CurlMultiHandle / CurlShareHandle — a
        // `function fetch(CurlHandle $ch)` hint is the ordinary way to write
        // ext/curl code, and closed-world it would read as an unknown class.
        "curl.php", "curl_multi.php",
        // And again for PDO / PDOStatement / PDOException — `function
        // repo(PDO $db)` is the ordinary way to write PDO code, and closed-world
        // it would read as an unknown class. pdo_sqlite.php comes along because
        // it declares the driver classes pdo.php's seam is satisfied by.
        "pdo.php", "pdo_sqlite.php",
    ];
    /** @var \Analyze\ParsedFile[] $out */
    $out = [];
    foreach ($names as $name) {
        $src = prelude_src_or_empty($name);
        if ($src === "") { continue; }
        // `prelude_src_or_empty` strips the opening `<?php` (it is built to append
        // after a prelude header); re-add it so this parses as a standalone file.
        try {
            $program = \Parser\Parser::parseSource("<?php\n" . $src);
            $out[] = new \Analyze\ParsedFile("prelude/" . $name, $program);
        } catch (\Throwable $e) {
            // A prelude file that does not parse standalone is simply skipped.
        }
    }
    return $out;
}

/**
 * Map one raw MIR-pass finding (`line N: error: …` from TypeCheck, or a
 * `#[TypeDef] …` from CheckTypeDefs) to an analyzer diagnostic. MIR carries only
 * a line, so the column is 0 and the file is the caller-supplied label.
 */
function mir_line_to_diag(string $line, string $fileLabel): \Analyze\Diagnostic {
    $ln = 0;
    if (\str_starts_with($line, "line ")) {
        $rest = \substr($line, 5, \strlen($line) - 5);
        $colon = \strpos($rest, ":");
        if ($colon !== false) { $ln = (int)\substr($rest, 0, $colon); }
    }
    $msg = $line;
    $ep = \strpos($line, "error: ");
    if ($ep !== false) { $msg = \substr($line, $ep + 7, \strlen($line) - ($ep + 7)); }
    $code = \str_starts_with($line, "#[TypeDef]") ? "repr.typedef" : "repr.type";
    // Reserved-attribute findings, keyed off the message Zend itself prints.
    if (\str_contains($msg, "#[\\Override] attribute")) { $code = "attr.override"; }
    elseif (\str_contains($msg, "must not be repeated")) { $code = "attr.repeat"; }
    elseif (\str_contains($msg, "cannot target")) { $code = "attr.target"; }
    elseif (\str_starts_with($msg, "Cannot apply #[\\Deprecated]")) { $code = "attr.deprecated"; }
    return \Analyze\Diagnostic::error($fileLabel, $ln, 0, $code, $msg);
}

/**
 * Stdlib function names from the bundled `.o.sig`, lowercased — the analyzer's
 * known-callable set for its undefined-function rule.
 *
 * Global declarations are taken as-is. Namespaced ones are NOT simply dropped:
 * `LowerFromAst::lowerProgram` (LowerFromAst.php:822-841) registers a bare-name
 * alias for every namespaced extern whose bare name is unique, and
 * `resolveCallName` (:1826) resolves an unqualified call through it. So an
 * unqualified `strncmp()` really does bind to `Runtime\Libc\strncmp`, and the
 * analyzer must model that or it reports a call that compiles and runs.
 *
 * The alias set is deliberately the SAME shape the lowering computes, including
 * the collision rule (two namespaced decls sharing a bare name cancel each
 * other out and no alias is registered).
 *
 * ⚠ Modelling this here makes the analyzer quiet about those names. Whether a
 * given capture is CORRECT is a separate question — `tools/audit/alias_scan.php`
 * answers it, and reports the ones that bind a raw C function under a PHP name.
 *
 * @return string[]
 */
function analyze_stdlib_fn_names(): array {
    $path = find_stdlib_sig();
    if ($path === "") { return []; }
    $json = read_file($path);
    if ($json === null) { return []; }
    /** @var string[] $out */
    $out = [];
    /** @var array<string,int> $bareCount  bare name -> namespaced decls seen */
    $bareCount = [];
    /** @var string[] $bareNames */
    $bareNames = [];
    try {
        foreach (Sig::declsFromJson($json) as $decl) {
            $name = $decl->name;
            $pos = \strrpos($name, "\\");
            if ($pos === false) { $out[] = \strtolower($name); continue; }
            $bare = \strtolower(\substr($name, $pos + 1));
            if (!isset($bareCount[$bare])) { $bareCount[$bare] = 0; $bareNames[] = $bare; }
            $bareCount[$bare] = $bareCount[$bare] + 1;
        }
        // Only a UNIQUE bare name becomes an alias — mirrors the `isset(...) ? ''`
        // collision guard in the lowering.
        foreach ($bareNames as $bare) {
            if ($bareCount[$bare] === 1) { $out[] = $bare; }
        }
    } catch (\Throwable $e) {
        // A malformed sig just yields no stdlib names (rule stays conservative).
    }
    return $out;
}

/**
 * Run the analyzer over a resolved source-file set and return the sorted
 * diagnostics. Shared by the `analyze` command and `compile --analyze`.
 *
 * @param \Analyze\SourceFile[] $files
 * @param string[] $argPaths  the original CLI paths (to detect directory input)
 * @return \Analyze\Diagnostic[]
 */
function perform_analysis(array $files, array $argPaths, bool $deep): array {
    // Undefined-symbol rules are closed-world: only sound when the whole project
    // is present. Enable for a directory argument or a multi-file run, not for a
    // single file (whose cross-file references would be mis-flagged).
    $checkUndefined = \count($files) > 1;
    foreach ($argPaths as $argPath) {
        if (is_directory($argPath)) { $checkUndefined = true; }
    }

    /** @var \Analyze\Diagnostic[] $diags */
    $diags = [];
    /** @var \Analyze\ParsedFile[] $parsed */
    $parsed = [];
    foreach ($files as $sf) {
        try {
            $program = \Parser\Parser::parseSource($sf->contents);
            $parsed[] = new \Analyze\ParsedFile($sf->path, $program);
        } catch (\Parser\ParseError $pe) {
            // ParseError appends ` at line N, column C`; the diagnostic already
            // prints that location, so strip the redundant tail.
            $msg = $pe->getMessage();
            $suffix = ' at line ' . (string)$pe->errLine . ', column ' . (string)$pe->column;
            if (\str_ends_with($msg, $suffix)) {
                $msg = \substr($msg, 0, \strlen($msg) - \strlen($suffix));
            }
            $diags[] = \Analyze\Diagnostic::error($sf->path, $pe->errLine, $pe->column, 'parse.error', $msg);
        } catch (\Throwable $e) {
            $diags[] = \Analyze\Diagnostic::error($sf->path, 0, 0, 'parse.error', $e->getMessage());
        }
    }

    /** @var \Analyze\ParsedFile[] $libFiles  prelude — known symbols, never reported */
    $libFiles = analyze_prelude_files();
    $stdlibFns = $checkUndefined ? analyze_stdlib_fn_names() : [];
    $analyzer = new \Analyze\Analyzer();
    foreach ($analyzer->run($parsed, $libFiles, $checkUndefined, $stdlibFns) as $d) { $diags[] = $d; }

    // Deep pass: drive the compiler's OWN MIR type checks (no duplicated logic).
    if ($deep) {
        /** @var string[] $raw */
        $raw = [];
        foreach ($files as $sf) { $raw[] = $sf->contents; }
        $collect = new \Analyze\MirDiags();
        lower_module($raw, $collect);
        $fileLabel = \count($files) === 1 ? $files[0]->path : "(project)";
        foreach ($collect->lines as $mline) { $diags[] = mir_line_to_diag($mline, $fileLabel); }
    }

    return \Analyze\Report::sortDiags($diags);
}

function cmd_analyze(array $args): int {
    // `--deep` also runs the compiler's own MIR type passes (repr-soundness) via
    // lower_module in analysis mode — heavier (it lowers the code), so opt-in.
    // `--json` prints machine-readable output for editors / CI.
    $spec = compile_arg_spec();
    $spec["deep"] = \Cli\ArgParse::FLAG;
    $spec["json"] = \Cli\ArgParse::FLAG;
    $spec["baseline"] = \Cli\ArgParse::VALUE;
    $spec["generate-baseline"] = \Cli\ArgParse::VALUE;
    // `--only <prefix[,prefix…]>` keeps just the diagnostic codes that start
    // with one of the prefixes, and gates on ANY survivor whatever its severity.
    // One caller drives the shape: the self-host preflight
    // (`analyze src --only undefined.,parse.error`), which asks the narrow
    // question "can THIS compiler still build this source" and must not be
    // drowned by the 177 style warnings a full run reports.
    $spec["only"] = \Cli\ArgParse::VALUE;
    $p = \Cli\ArgParse::parse($args, $spec);
    if ($p->error !== null) { dprint($p->error); return 64; }
    if (!apply_compile_args($p)) { return 64; }
    $deep = $p->flag("deep");
    $json = $p->flag("json");
    $baselinePath = $p->value("baseline", "");
    $genBaseline = $p->value("generate-baseline", "");
    $only = $p->value("only", "");

    $files = resolve_source_files(CompileArgs::$files);
    if ($files === null) { return 66; }
    if (\count($files) === 0) { return 66; }

    $diags = perform_analysis($files, CompileArgs::$files, $deep);

    // Generate a baseline: snapshot every current finding and report nothing.
    if ($genBaseline !== "") {
        if (!write_file($genBaseline, \Analyze\Baseline::generate($diags))) {
            dprint("analyze: could not write baseline to " . $genBaseline);
            return 73;
        }
        dprint("analyze: wrote baseline (" . (string)\count($diags) . " entries) to " . $genBaseline);
        return 0;
    }
    // Apply a baseline: drop known findings.
    if ($baselinePath !== "") {
        $bl = read_file($baselinePath);
        if ($bl !== null) { $diags = \Analyze\Baseline::filter($diags, $bl); }
    }

    if ($only !== "") {
        /** @var string[] $prefixes */
        $prefixes = \explode(",", $only);
        /** @var \Analyze\Diagnostic[] $kept */
        $kept = [];
        foreach ($diags as $d) {
            foreach ($prefixes as $pre) {
                if ($pre !== "" && \str_starts_with($d->code, $pre)) { $kept[] = $d; break; }
            }
        }
        $diags = $kept;
    }

    echo $json ? \Analyze\Report::json($diags) : \Analyze\Report::human($diags);

    // Under --only the selection IS the gate: a warning-severity undefined
    // symbol still means this compiler cannot resolve the name.
    if ($only !== "") { return \count($diags) > 0 ? 1 : 0; }
    foreach ($diags as $d) {
        if ($d->severity === \Analyze\Diagnostic::SEV_ERROR) { return 1; }
    }
    return 0;
}

function cmd_dump_mir(array $args): int {
    if (!parse_compile_args($args)) { return 64; }
    $sources = resolve_sources(CompileArgs::$files);
    if ($sources === null) { return 66; }
    if (\count($sources) === 0) { return 66; }
    // Share the one pipeline `compile`/`dump-sig` run (lower_module) rather
    // than a hand-copied subset. The old inlined list skipped InlineClosures,
    // FuseSplitJoin, DemoteCharLocals, Verify and the pre-mono re-runs, and
    // it built LowerFromAst with no prelude — so the dump was pre-optimization
    // IR of a program with no Exception hierarchy, and could never match what
    // a real compile lowers. It also parsed only $sources[0]; lower_module
    // loops every file, so multi-file dump-mir now works too.
    $module = lower_module($sources);
    if ($module === null) { return 65; }
    puts(\Compile\Mir\Dump::module($module, CompileArgs::$dumpPrelude, CompileArgs::$dumpEffects));
    return 0;
}

function main_driver(): int {
    \Compile\Debug::initFromEnvironment();
    $cli = new \Cli\Cli('manticore', 'PHP-to-native AOT compiler (self-hosted)');
    $cli->command('compile', 'Compile to a native binary (-o <out>); analyzes by default (--no-analyze skips, --analyze-strict gates on errors)')
        ->run(fn (array $args) => cmd_compile($args));
    $cli->command('build', 'Build all targets from a manticore.json manifest (libraries + applications)')
        ->run(fn (array $args) => cmd_build($args));
    $cli->command('dump-llvm', 'Read PHP source from stdin, emit LLVM IR on stdout')
        ->run(fn (array $args) => cmd_dump_llvm($args));
    $cli->command('dump-ast', 'Parse PHP source and print the resulting AST')
        ->run(fn (array $args) => cmd_dump_ast($args));
    $cli->command('analyze', 'Static type analysis; report diagnostics, no codegen')
        ->run(fn (array $args) => cmd_analyze($args));
    $cli->command('dump-mir', 'Parse PHP, lower to MIR, print the typed IR')
        ->run(fn (array $args) => cmd_dump_mir($args));
    $cli->command('dump-llvm-mir', 'Parse PHP, run MIR pipeline + EmitLlvm, print LLVM IR')
        ->run(fn (array $args) => cmd_dump_llvm_mir($args));
    $cli->command('dump-sig', 'Parse PHP, print the module-interface .sig (exported symbol table)')
        ->run(fn (array $args) => cmd_dump_sig($args));
    $cli->command('version', 'Print compiler version')
        ->run(fn (array $args) => cmd_version($args));
    $cli->command('help', 'Show this help text')
        ->run(function (array $args) use ($cli): int { return $cli->runHelp(); });
    return $cli->run(collect_argv());
}
