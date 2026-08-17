<?php

/**
 * MIR multi-file compile driver for the bootstrap. Reads every `.php`
 * path on the command line and emits one merged LLVM module via the
 * MIR backend ({@see Manticore\compile_via_mir}). Replaces the AST
 * `Compile\Compiler` path in `bin/compile` so the self-hosted compiler
 * is built by the same backend it ships as the default.
 *
 *     php tools/compile_files_mir.php $(find src -name '*.php' | sort) > out.ll
 */

spl_autoload_register(function ($class) {
    $base = __DIR__ . '/../src/';
    $path = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($path)) {
        require $path;
        return;
    }
    if (str_starts_with($class, 'Parser\\Ast\\')) {
        foreach (['Stmt.php', 'Expr.php'] as $umbrella) {
            $u = $base . 'Parser/Ast/' . $umbrella;
            if (is_file($u)) {
                require_once $u;
            }
        }
    }
    // MIR node variants all live in the umbrella Nodes.php.
    if (str_starts_with($class, 'Compile\\Mir\\')) {
        require_once $base . 'Compile/Mir/Nodes.php';
    }
});

require_once __DIR__ . '/../src/Manticore/Main.php';
if (!function_exists('str_bytes')) { function str_bytes(string $s): int { return $s === "" ? 0 : 1; } function manticore_raw_str_bytes(string $s): int { return $s === "" ? 0 : 1; } }

\Compile\Debug::initFromEnvironment();

if ($argc < 2) {
    fwrite(STDERR, "usage: compile_files_mir.php <file.php> [<file.php> ...]\n");
    exit(64);
}

/**
 * `@<listfile>` — the RESOLVED file list a manifest build wrote with
 * `MANTICORE_DUMP_SOURCES` ({@see Manticore\dump_resolved_sources}). Each line
 * is `  <path>` or `D <path>` for a demand-loaded one, whose top-level side
 * effects the builder drops while keeping its declarations.
 *
 * This is how a manifest build that CRASHES gets diagnosed: the same unit,
 * compiled by the Zend front end, where a null against a typed parameter is a
 * TypeError with a stack trace rather than a SIGSEGV with no unwind. A
 * hand-reconstructed list does not reproduce — it misses these two rules.
 */
$paths = [];
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '' || $arg[0] !== '@') { $paths[] = $arg; continue; }
    $listFile = substr($arg, 1);
    if (!is_file($listFile)) {
        fwrite(STDERR, "not a file: $listFile\n");
        exit(66);
    }
    foreach (explode("\n", file_get_contents($listFile)) as $line) {
        if ($line === '') { continue; }
        $demand = str_starts_with($line, 'D ');
        $p = substr($line, 2);
        if ($p === '') { continue; }
        $paths[] = $p;
        if ($demand) { \Manticore\CompileArgs::$demandLoadedPaths[rtrim($p, '/')] = true; }
    }
}

$sources = [];
foreach ($paths as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "not a file: $path\n");
        exit(66);
    }
    $sources[] = file_get_contents($path);
}

// PATHS matter, not just the sources: the demand-loaded keep/drop test keys on
// the file's path, so passing sources alone silently compiles every top-level
// statement of a demand-loaded file — a template's short-echo of a property
// then lands in `__main` and Verify refuses the whole unit for a `$this` the
// real build never sees.
$ir = \Manticore\compile_via_mir($sources, $paths);
if ($ir === null) {
    fwrite(STDERR, "compile error (MIR)\n");
    exit(70);
}
echo $ir;
