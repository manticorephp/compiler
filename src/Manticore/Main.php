<?php

namespace Manticore;

use Ffi\Library;
use Ffi\Symbol;
use Parser\Parser;

// ── libc bindings used by the driver ─────────────────────────────────

#[Library('c'), Symbol('puts')]
function puts(string $s): int {}

#[Library('c'), Symbol('fflush')]
function fflush(\Ffi\Ptr $stream): int {}

#[Library('c'), Symbol('write')]
function write(int $fd, string $buf, int $n): int { return 0; }

#[Library('c'), Symbol('read')]
function read(int $fd, \Ffi\Ptr $buf, int $n): int { return 0; }

#[Library('c'), Symbol('malloc')]
function malloc(int $size): \Ffi\Ptr {}

#[Library('c'), Symbol('calloc')]
function calloc(int $count, int $size): \Ffi\Ptr {}

#[Library('c'), Symbol('uname')]
function uname(\Ffi\Ptr $buf): int { return 0; }

#[Library('c'), Symbol('manticore_cli_argc')]
function argc(): int { return $GLOBALS['argc'] ?? 0; }

// Raw OS char* — converted to a headered string via cstr_to_str at the edge.
#[Library('c'), Symbol('manticore_cli_argv')]
function argv(int $i): \Ffi\Ptr {}

#[Library('c'), Symbol('system')]
function system(string $cmd): int { return 0; }

#[Library('c'), Symbol('fopen')]
function fopen(string $path, string $mode): \Ffi\Ptr {}

#[Library('c'), Symbol('fwrite')]
function fwrite(string $buf, int $size, int $count, \Ffi\Ptr $stream): int { return 0; }

#[Library('c'), Symbol('fread')]
function fread(\Ffi\Ptr $buf, int $size, int $count, \Ffi\Ptr $stream): int { return 0; }

#[Library('c'), Symbol('fseek')]
function fseek(\Ffi\Ptr $stream, int $offset, int $whence): int { return 0; }

#[Library('c'), Symbol('ftell')]
function ftell(\Ffi\Ptr $stream): int { return 0; }

#[Library('c'), Symbol('fclose')]
function fclose(\Ffi\Ptr $stream): int { return 0; }

#[Library('c'), Symbol('getpid')]
function getpid(): int { return 0; }

#[Library('c'), Symbol('access')]
function access(string $path, int $mode): int { return -1; }

#[Library('c'), Symbol('opendir')]
function opendir(string $path): \Ffi\Ptr {}

#[Library('c'), Symbol('closedir')]
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
        $src = \file_get_contents($path);
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
     * `--emit-library` — build the bundled stdlib as a standalone `.o`
     * (no `@main`, no stdlib linking). Used by bin/compile / bin/build to
     * produce `lib/manticore_stdlib.o` once after the compiler is built.
     */
    public static bool $emitLibrary = false;

    /**
     * Set by {@see compile_via_mir} when at least one bundled-stdlib extern
     * was injected into the module → cmd_compile links the prebuilt
     * `stdlib.o` at the cc step. Stays false for self-contained programs
     * (the compiler's own source defines the stdlib) so no duplicate symbols.
     */
    public static bool $linkStdlib = false;

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
 *   --prelude · --effects · --emit-library
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
 */
function compile_with_backend(array $sources): ?string {
    return compile_via_mir($sources);
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

    $sources = resolve_sources(CompileArgs::$files);
    if ($sources === null) {
        dprint("compile: source resolution failed — no input read (rc=66)");
        return 66;
    }
    if (\count($sources) === 0) {
        dprint("compile: source list is empty (rc=66)");
        return 66;
    }

    if ($analyze) {
        // Advisory by default: any failure inside the analyzer is swallowed so it
        // can NEVER break a build. `--no-analyze` is the escape hatch. With
        // `--analyze-strict`, error-severity findings instead FAIL the compile
        // (rc=65, before codegen) — a lint gate for CI.
        try {
            $afiles = resolve_source_files(CompileArgs::$files);
            if ($afiles !== null && \count($afiles) > 0) {
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
        CompileArgs::$externDecls = collect_stdlib_extern_decls();
    }
    // NOTE: the bundled PHP stdlib is NOT prepended here. Merging the whole
    // stdlib into every user program both bloats output and crashes the
    // compiler on some stdlib+user combinations (the "stdlib as guest"
    // hazard). The chosen design is a prebuilt stdlib.o linked at the cc step
    // (see discover_stdlib_files / the link tail) — built once, in isolation.
    $ir = compile_with_backend($sources);
    if ($ir === null) {
        dprint("compile: front-end (parse/typeck/IR) returned null (rc=65)");
        return 65;
    }
    if (\strlen($ir) === 0) {
        dprint("compile: front-end produced empty IR (rc=65)");
        return 65;
    }

    $pid = getpid();
    $base = "/tmp/manticore_" . (string)$pid;
    $llPath = $base . ".ll";
    $objPath = $base . ".o";

    if (!write_file($llPath, $ir)) {
        dprint("compile: cannot write " . $llPath . " (rc=73)");
        return 73;
    }

    // Library build: assemble straight to the output .o, no link. The
    // runtime preamble helpers are emitted linkonce_odr so this object
    // coexists with a user program's preamble at link time.
    if (CompileArgs::$emitLibrary) {
        $rcLib = system("clang -O" . CompileArgs::$optLevel . " -c -x ir " . $llPath . " -o " . $output . " -Wno-override-module");
        if ($rcLib !== 0) {
            dprint("compile: clang -c (library) failed (rc=" . (string)$rcLib . "); IR at " . $llPath);
            return 75;
        }
        return 0;
    }

    // clang understands `-x ir` for plain LLVM textual IR. cc on both
    // Linux and macOS picks the system linker plus libc by default.
    // `-ffunction-sections` puts each function in its own section so the
    // link-time dead-strip below drops every unreferenced stdlib function
    // (a hello-world no longer drags in all of Json/Libc/stdlib).
    // Errors on stderr already, but surface our own rc too.
    $rc1 = system("clang -O" . CompileArgs::$optLevel . " -ffunction-sections -fdata-sections -c -x ir " . $llPath . " -o " . $objPath . " -Wno-override-module");
    if ($rc1 !== 0) {
        dprint("compile: clang -c failed (rc=" . (string)$rc1 . "); IR at " . $llPath);
        return 75;
    }
    // Link the prebuilt stdlib.o only when the program imported a bundled
    // stdlib function (str_starts_with / ctype_* / file_*). A self-contained
    // program (e.g. the compiler's own source, which defines the stdlib)
    // links without it — avoids duplicate symbols.
    $linkExtra = "";
    if (CompileArgs::$linkStdlib) {
        $stdlibObj = find_stdlib_object();
        if ($stdlibObj !== "") {
            $linkExtra = " " . $stdlibObj;
        } else {
            dprint("compile: program uses bundled stdlib but stdlib.o not found — link may fail");
        }
        // The bundled stdlib carries the preg_* FFI wrappers, which reference
        // the host libpcre2-8. Dead-strip drops those wrappers (and the dylib
        // load command, see the flags below) for a program that never calls a
        // preg_* function, so this is harmless when regex is unused.
        $pcre = pcre2_link_flags();
        if ($pcre !== "") { $linkExtra .= " " . $pcre; }
        // The bundled stdlib carries the TLS transport (https://), which references
        // the host libssl/libcrypto. Dead-strip + --as-needed drop them for a
        // program that opens no TLS stream, same as pcre2 above.
        $ssl = openssl_link_flags();
        if ($ssl !== "") { $linkExtra .= " " . $ssl; }
    }
    // Dead-strip unreferenced functions at link time — the prebuilt stdlib.o
    // is one object (linked wholesale), so without this a tiny program carries
    // the entire stdlib (~75 KB hello-world). macOS ld64 strips at the
    // symbol/atom level (`-dead_strip`); GNU/lld need section GC (`--gc-sections`,
    // paired with the `-ffunction-sections` above). `-dead_strip_dylibs` /
    // `--as-needed` drop a linked-but-unreferenced dylib (e.g. libpcre2 in a
    // program that uses the stdlib but no preg_*).
    //
    // errno's per-host accessor (__mc_errno) declares BOTH __error and
    // __errno_location extern_weak; on Darwin the latter is a weak-undefined that
    // ld64 still errors on unless allowed. `-U ___errno_location` permits exactly
    // that one symbol undefined (it binds to 0, and the null-test branches away
    // from it). GNU ld auto-binds weak-undefined to 0, so Linux needs no flag.
    // -lm: libm is folded into libSystem on Darwin (auto-linked) but is a
    // separate archive on glibc/musl — a program calling tanh/sinh/pow/fmod
    // (non-intrinsic libm fns the compiler lowers to plain calls) links with an
    // undefined reference without it. `--as-needed` drops it when unreferenced.
    $gc = \substr(host_os(), 0, 6) === "Darwin"
        ? " -Wl,-dead_strip -Wl,-dead_strip_dylibs -Wl,-U,___errno_location"
        : " -Wl,--gc-sections -Wl,--as-needed -lm";
    $rc2 = system("cc " . $objPath . $linkExtra . $gc . " -o " . $output);
    if ($rc2 !== 0) {
        dprint("compile: cc link failed (rc=" . (string)$rc2 . "); objects at " . $objPath);
        return 76;
    }
    return 0;
}

/**
 * Host OS sysname ("Darwin" / "Linux") via libc uname(2). The `sysname`
 * member is at offset 0, NUL-terminated; a generously zeroed buffer covers
 * macOS's ~1.3 KB utsname.
 */
function host_os(): string {
    $buf = calloc(2048, 1);
    uname($buf);
    // `$buf` is a raw calloc block (no header). cstr_to_str copies to the NUL
    // into an owned, headered MIR string — the single raw→string boundary.
    return \cstr_to_str($buf);
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
            if (\strlen($ex) > 0 && \str_starts_with($path, $ex)) { $skip = true; break; }
        }
        if ($skip) { continue; }
        $src = read_file($path);
        if ($src !== null) { $out[] = $src; }
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
 * The directories named by one composer `autoload` block — psr-4 / psr-0 roots
 * and classmap DIRECTORY entries — each prefixed with `$base`. `files` and
 * single-`*.php` classmap entries are deferred: whole-program AOT already pulls
 * in every declaration once a directory is scanned, and `files` side effects
 * have no analogue in a compiled program.
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
        $ps = (string)$p;
        if (\substr($ps, -4) !== ".php") { $out[] = composer_path_join($base, $ps); }
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
function build_compile_module(array $sources, string $output, bool $emitLibrary, array $linkObjs, string $linkFlags = '', bool $withStdlib = false): int
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
    }
    $module = lower_module($sources);
    if ($module === null) { dprint("build: front-end returned null for " . $output); return 65; }
    try {
        $emit = new \Compile\Mir\Passes\EmitLlvm();
        $emit->emitLibrary = $emitLibrary;
        $ir = $emit->emit($module);
    } catch (\Throwable $e) {
        dprint("build: emit failed for " . $output . ": " . $e->getMessage());
        return 65;
    }
    if (\strlen($ir) === 0) { dprint("build: empty IR for " . $output); return 65; }
    $pid = getpid();
    $base = "/tmp/manticore_buildobj_" . (string)$pid;
    $llPath = $base . ".ll";
    if (!write_file($llPath, $ir)) { dprint("build: cannot write " . $llPath); return 73; }
    if ($emitLibrary) {
        $rc = system("clang -O" . CompileArgs::$optLevel . " -c -x ir " . $llPath . " -o " . $output . " -Wno-override-module");
        if ($rc !== 0) { dprint("build: clang -c (library) failed for " . $output); return 75; }
        // Emit the module-interface .sig next to the object so dependents
        // import this library's exported symbols without re-parsing it.
        if (!write_file($output . ".sig", Sig::emitModule($module))) {
            dprint("build: cannot write " . $output . ".sig");
            return 73;
        }
        return 0;
    }
    $objPath = $base . ".o";
    $rc1 = system("clang -O" . CompileArgs::$optLevel . " -c -x ir " . $llPath . " -o " . $objPath . " -Wno-override-module");
    if ($rc1 !== 0) { dprint("build: clang -c failed for " . $output); return 75; }
    $linkExtra = "";
    foreach ($linkObjs as $obj) { $linkExtra = $linkExtra . " " . $obj; }
    if ($linkFlags !== "") { $linkExtra = $linkExtra . " " . $linkFlags; }
    // Link the bundled stdlib.o when a stdlib function was actually referenced
    // (lower_module sets linkStdlib from the injected externs) — a program that
    // touches no stdlib function links nothing extra.
    if ($withStdlib && CompileArgs::$linkStdlib) {
        $stdObj = find_stdlib_object();
        if ($stdObj !== "") { $linkExtra = $linkExtra . " " . $stdObj; }
        // Same host libs the single-file `compile` path links: the bundled stdlib
        // references libpcre2 (preg_*) and libssl/libcrypto (https://). Dead-strip
        // + --as-needed drop whichever the program never reaches.
        $pcre = pcre2_link_flags();
        if ($pcre !== "") { $linkExtra = $linkExtra . " " . $pcre; }
        $ssl = openssl_link_flags();
        if ($ssl !== "") { $linkExtra = $linkExtra . " " . $ssl; }
    }
    // Link via the stub-generating tail: the no-Rust bootstrap leaves native
    // FFI-boundary primitives (`manticore_rt_*`) undefined; they link-stub to
    // 0. Falls back to a plain cc when the helper isn't found.
    $stubs = find_link_stubs_script();
    if ($stubs !== "") {
        $rc2 = system("bash " . $stubs . " " . $output . " " . $objPath . $linkExtra);
    } else {
        $rc2 = system("cc " . $objPath . $linkExtra . " -o " . $output);
    }
    if ($rc2 !== 0) { dprint("build: link failed for " . $output); return 76; }
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
    // Parse: first non-flag arg = manifest path; `--libs-only` builds the
    // library targets and stops (used by the cold seed to refresh stdlib.o
    // without re-linking the applications).
    $manifestPath = "manticore.json";
    $libsOnly = false;
    foreach ($args as $a) {
        if ($a === "--libs-only") { $libsOnly = true; continue; }
        if (\strlen($a) > 0 && $a[0] === '-') { dprint("build: unknown flag: " . $a); return 64; }
        $manifestPath = $a;
    }
    $src = read_file($manifestPath);
    if ($src === null) {
        dprint("build: cannot read manifest: " . $manifestPath);
        return 66;
    }
    $manifest = json_decode($src, true);
    $libs = isset($manifest["libraries"]) ? $manifest["libraries"] : [];
    foreach ($libs as $lib) {
        $name = (string)$lib["name"];
        $srcDir = (string)$lib["src"];
        $output = (string)$lib["output"];
        /** @var string[] $excludes */
        $excludes = [];
        foreach ($lib["exclude"] as $e) { $excludes[] = (string)$e; }
        dprint("build: library '" . $name . "' (" . $srcDir . " -> " . $output . ")");
        $sources = collect_php_sources($srcDir, $excludes);
        if (\count($sources) === 0) {
            dprint("build: no sources for library '" . $name . "'");
            return 66;
        }
        CompileArgs::$externDecls = [];
        $rc = build_compile_module($sources, $output, true, []);
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
        $sources = collect_php_sources($srcDir, $moduleExcludes);
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
            /** @var array<string,bool> $covered */
            $covered = [];
            $covered[\rtrim($srcDir, "/")] = true;
            foreach (composer_source_dirs(".", $withVendor) as $cdir) {
                $nd = \rtrim($cdir, "/");
                if (isset($covered[$nd])) { continue; }
                $covered[$nd] = true;
                dprint("build: + composer autoload '" . $nd . "'");
                foreach (collect_php_sources($nd, $moduleExcludes) as $g) { $sources[] = $g; }
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
            foreach (collect_php_sources($extSrc, []) as $g) { $sources[] = $g; }
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
        }
        if (\count($sources) === 0) {
            dprint("build: no sources for application '" . $name . "'");
            return 66;
        }
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
                foreach (Sig::declsFromJson($sigJson) as $d) {
                    $externDecls[] = $d;
                }
            } else {
                dprint("build: missing .sig for library output " . $libOut);
            }
            $linkObjs[] = $libOut;
        }
        CompileArgs::$externDecls = $externDecls;
        $rc = build_compile_module($sources, $output, false, $linkObjs, $linkFlags, !$skipStdlib);
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
 * @param string[] $sources
 */
function lower_module(array $sources, ?\Analyze\MirDiags $collect = null): ?\Compile\Mir\Module {
    $stmts = [];
    $aliases = [];
    $docs = [];
    foreach ($sources as $source) {
        try {
            $program = Parser::parseSource($source);
        } catch (\Throwable $e) {
            dprint("parse failed: " . $e->getMessage());
            return null;
        }
        foreach ($program->statements as $s) { $stmts[] = $s; }
        foreach ($program->useAliases as $short => $fqn) { $aliases[$short] = $fqn; }
        foreach ($program->docComments as $d) { $docs[] = $d; }
    }
    // What the program DEMANDS of the prelude, asked of the tokens. A substring
    // gate cannot tell a call from a mention, and this compiler is made of the
    // names it implements — `var_dump(` in a doc comment used to pull the whole
    // var_dump runtime (per-class __mir_dump_object, ~58k IR lines) into the
    // compiler's own binary. See Compile\Mir\PreludeDemand.
    $demand = new \Compile\Mir\PreludeDemand($sources);

    // The on-disk prelude sources. Reading them goes through the libc fopen
    // binding (a throwing stub under the Zend cold-seed), so guard: an
    // unreadable file provides nothing, and LowerFromAst falls back to its
    // embedded copy for the classes the bootstrap cannot live without.
    $arrayFnsSrc = prelude_src_or_empty("array_fns.php");
    $cliSrc = prelude_src_or_empty("cli.php");
    $printRSrc = prelude_src_or_empty("print_r.php");
    $arrayClassesSrc = prelude_src_or_empty("spl_arrays.php");
    $reflectionSrc = prelude_src_or_empty("reflection.php");
    $dateTimeSrc = prelude_src_or_empty("datetime.php");

    // array_fns gates on the functions the FILE defines (sort/usort/explode/…),
    // so adding one there needs no second edit here. These live in the prelude,
    // not the stdlib .o, so injecting the file cannot double-define anything.
    $useArrayFns = $demand->callsAny(\Compile\Mir\PreludeDemand::definedFunctions($arrayFnsSrc));
    $useArrayClasses = $demand->mentionsAny(['ArrayIterator', 'ArrayObject']);
    // Reflection is gated on a MENTION, like the array classes: `new
    // ReflectionClass(...)` / a `ReflectionClass` hint / a catch of
    // ReflectionException. A program that never reflects carries none of it.
    // This gate decides whether the CLASSES exist; it cannot decide WHICH
    // classes get metadata — PreludeDemand deliberately ignores string
    // literals, so `new ReflectionClass('Foo')` hides Foo from it. That is a
    // separate analysis (ReflectAnalysis).
    $useReflection = $demand->mentionsAny(['ReflectionClass', 'ReflectionObject',
                                           'ReflectionMethod', 'ReflectionException'])
        // get_declared_* are plain FUNCTIONS living in the same file — a program
        // may call one without ever naming a Reflection class, and would then
        // get an undefined symbol (which this toolchain stubs to `return 0`
        // rather than failing).
        || $demand->callsAny(['get_declared_classes', 'get_declared_interfaces',
                              'get_declared_traits']);
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
    $useVarDump = $demand->calls('var_dump');
    $usePrintR = $demand->calls('print_r');
    // CLI prelude (__mc_argv / getopt): $_SERVER and $_ENV are BUILT by it
    // (__mc_server / __mc_env), so they gate it too; the other superglobals seed
    // an empty array literal and need nothing.
    $useCli = $demand->usesVar('argv') || $demand->usesVar('argc')
        || $demand->usesVar('_SERVER') || $demand->usesVar('_ENV')
        || $demand->calls('getopt');
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
        $module->sourceFile = CompileArgs::$files[0] ?? '';
        $lower = new \Compile\Mir\Passes\LowerFromAst($program);
        $lower->includeVarDump = $useVarDump;
        $lower->includePrintR = $usePrintR;
        $lower->includeArrayClasses = $useArrayClasses;
        $lower->includeReflection = $useReflection;
        $lower->includeDateTime = $useDateTime;
        $lower->includeArrayFns = $useArrayFns;
        $lower->includeCli = $useCli;
        $lower->exceptionsSrc = $exceptionsSrc;
        $lower->resourceSrc = $resourceSrc;
        $lower->backtraceSrc = $backtraceSrc;
        $lower->varDumpSrc = $varDumpSrc;
        $lower->arrayClassesSrc = $arrayClassesSrc;
        $lower->reflectionSrc = $reflectionSrc;
        $lower->dateTimeSrc = $dateTimeSrc;
        $lower->arrayFnsSrc = $arrayFnsSrc;
        $lower->cliSrc = $cliSrc;
        $lower->printRSrc = $printRSrc;
        // Bundled-stdlib signatures (declare-only externs) so user calls
        // (str_starts_with / ctype_* / file_*) resolve + type, with the body
        // linked from stdlib.o. Collected by cmd_compile on the native path;
        // empty during the Zend bootstrap build and for --emit-library.
        $lower->externDecls = CompileArgs::$externDecls;
        $module = $lower->run($module);
        CompileArgs::$linkStdlib = $lower->externInjected;
        $fold = new \Compile\Mir\Passes\ConstFold();
        $module = $fold->run($module);
        $dse = new \Compile\Mir\Passes\DeadStore();
        $module = $dse->run($module);
        $infer = new \Compile\Mir\Passes\InferTypes();
        $module = $infer->run($module);
        // Narrow CONCRETE, param-independent bare-`array` returns now (a literal
        // `mk(){ return ["x"=>1]; }` → assoc[string,int]) so a call-site
        // `array_filter(mk(), …)` fuses on a concrete element and its result is
        // typed for the consumer (an erased vec[unknown] would read raw elements
        // as cells across a boxing boundary). Erased-param helpers (whose return
        // Monomorphize re-shapes) are skipped → the full post-Mono NarrowReturns
        // handles them.
        $module = (new \Compile\Mir\Passes\NarrowReturns(true))->run($module);
        $module = (new \Compile\Mir\Passes\InferTypes())->run($module);
        // Eliminate the boxed-cell closure ABI where it's avoidable: inline
        // captureless single-expr arrow closures at known invoke sites, and
        // fuse array_map/array_filter/array_reduce over a concrete array with a
        // literal closure into a native typed loop. Re-infer so the spliced /
        // fused expressions type from their (now concrete) operands.
        $inlineCl = new \Compile\Mir\Passes\InlineClosures();
        $module = $inlineCl->run($module);
        $module = (new \Compile\Mir\Passes\InferTypes())->run($module);
        // Specialize erased-array / polymorphic functions per call-site
        // argument shape (runs after InferTypes so call-arg types are known;
        // re-runs InferTypes internally when it specializes anything).
        $mono = new \Compile\Mir\Passes\Monomorphize();
        $module = $mono->run($module);
        // Fuse implode(explode()) split-join round-trips into one native
        // str_replace (zero intermediate array/segment allocs). After Mono so
        // types + explode arg-counts are settled; before InferEffects so the
        // analysis sees the fused form.
        $module = (new \Compile\Mir\Passes\FuseSplitJoin())->run($module);
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
            $tc = new \Compile\Mir\Passes\TypeCheck();
            $tc->reprOnly = !$tcOn;
            $module = $tc->run($module);
            if ($collect !== null) {
                foreach ($tc->errors as $te) { $collect->lines[] = $te; }
            } elseif (\count($tc->errors) > 0) {
                foreach ($tc->errors as $te) { dprint($te); }
                return null;
            }
        }
        $narrow = new \Compile\Mir\Passes\NarrowReturns();
        $module = $narrow->run($module);
        // The `#[TypeDef]` soundness gate: an erased value must never reach a
        // site that would observe it AS AN OBJECT (`===`, instanceof, var_dump, a
        // `mixed` slot). Runs once types are final and before any memory pass —
        // a boxed cell downstream has already lost the marker. Throws; the catch
        // below turns it into a compile error.
        $checkTypeDefs = new \Compile\Mir\Passes\CheckTypeDefs();
        if ($collect !== null) { $checkTypeDefs->collectMode = true; }
        $module = $checkTypeDefs->run($module);
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
        $refl = new \Compile\Mir\Passes\ReflectAnalysis();
        $refl->run($module);
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
        $module = (new \Compile\Mir\Passes\DemoteCharLocals())->run($module);
        $effects = new \Compile\Mir\Passes\InferEffects();
        $module = $effects->run($module);
        $allocKind = new \Compile\Mir\Passes\InferAllocKind();
        $module = $allocKind->run($module);
        $memMode = new \Compile\Mir\Passes\ApplyMemoryMode(CompileArgs::$memory);
        $module = $memMode->run($module);
        $memOps = new \Compile\Mir\Passes\InsertMemoryOps();
        $module = $memOps->run($module);
        $verify = new \Compile\Mir\Passes\Verify();
        $module = $verify->run($module);
        return $module;
    } catch (\Throwable $e) {
        dprint("compile failed: " . $e->getMessage());
        return null;
    }
}

function compile_via_mir(array $sources): ?string {
    $module = lower_module($sources);
    if ($module === null) { return null; }
    try {
        $emit = new \Compile\Mir\Passes\EmitLlvm();
        $emit->emitLibrary = CompileArgs::$emitLibrary;
        return $emit->emit($module);
    } catch (\Throwable $e) {
        dprint("compile failed (emit): " . $e->getMessage());
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
        "datetime.php",
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
    return \Analyze\Diagnostic::error($fileLabel, $ln, 0, $code, $msg);
}

/**
 * Global-namespace stdlib function names from the bundled `.o.sig`, lowercased —
 * the analyzer's known-callable set for its undefined-function rule. Namespaced
 * internals (`Runtime\…`, `__mc_…`) are filtered: user code never names them.
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
    try {
        foreach (Sig::declsFromJson($json) as $decl) {
            if (\strpos($decl->name, "\\") !== false) { continue; }
            $out[] = \strtolower($decl->name);
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
    $p = \Cli\ArgParse::parse($args, $spec);
    if ($p->error !== null) { dprint($p->error); return 64; }
    if (!apply_compile_args($p)) { return 64; }
    $deep = $p->flag("deep");
    $json = $p->flag("json");
    $baselinePath = $p->value("baseline", "");
    $genBaseline = $p->value("generate-baseline", "");

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

    echo $json ? \Analyze\Report::json($diags) : \Analyze\Report::human($diags);

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
