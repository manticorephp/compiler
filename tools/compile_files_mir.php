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

\Compile\Debug::initFromEnvironment();

if ($argc < 2) {
    fwrite(STDERR, "usage: compile_files_mir.php <file.php> [<file.php> ...]\n");
    exit(64);
}

$sources = [];
for ($i = 1; $i < $argc; $i++) {
    $path = $argv[$i];
    if (!is_file($path)) {
        fwrite(STDERR, "not a file: $path\n");
        exit(66);
    }
    $sources[] = file_get_contents($path);
}

$ir = \Manticore\compile_via_mir($sources);
if ($ir === null) {
    fwrite(STDERR, "compile error (MIR)\n");
    exit(70);
}
echo $ir;
