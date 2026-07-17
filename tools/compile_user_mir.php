<?php

/**
 * Zend-hosted USER-program compile driver — the fixpoint-break harness.
 *
 * Compiles ONE user program to LLVM IR with the compiler's own sources run by
 * the Zend host, so a `src/` change is validated WITHOUT a self-build. That
 * matters twice over: a rebuild compiles the change INTO the compiler, so a
 * broken change cannot even build itself (and a stale binary silently measures
 * OLD source instead).
 *
 * Distinct from {@see compile_files_mir.php}, which is the BOOTSTRAP driver:
 * there the cold seed defines the stdlib itself, so injecting the bundled
 * extern decls would double-define. Here we want exactly those decls — without
 * them a prelude callee (`__mc_dtoa_core`, reached by any `var_dump` of a
 * float) is called but never declared and clang rejects the module.
 *
 * `collect_stdlib_extern_decls()` is not reachable under Zend (it resolves the
 * .sig next to the binary via `argv()`, an FFI stub that throws), so the .sig
 * path is passed in explicitly.
 *
 *   MC_SRC=$PWD/src MC_SIG=$PWD/lib/manticore_stdlib.o.sig \
 *   MANTICORE_PRELUDE=$PWD/prelude php -d memory_limit=2048M \
 *     tools/compile_user_mir.php prog.php > prog.ll
 *
 * Emits `LINK_STDLIB=0|1` on stderr: whether the module referenced any bundled
 * stdlib extern, i.e. whether `lib/manticore_stdlib.o` must be linked. Linking
 * it unconditionally duplicates every symbol the prelude also defines.
 */

$srcBase = \getenv('MC_SRC');
if (!\is_string($srcBase) || $srcBase === '') {
    \fwrite(STDERR, "MC_SRC must point at the compiler's src/ directory\n");
    exit(64);
}

\spl_autoload_register(function ($class) use ($srcBase) {
    $path = $srcBase . '/' . \str_replace('\\', '/', $class) . '.php';
    if (\file_exists($path)) {
        require $path;
        return;
    }
    if (\str_starts_with($class, 'Parser\\Ast\\')) {
        foreach (['Stmt.php', 'Expr.php'] as $umbrella) {
            $u = $srcBase . '/Parser/Ast/' . $umbrella;
            if (\is_file($u)) {
                require_once $u;
            }
        }
    }
    // MIR node variants all live in the umbrella Nodes.php.
    if (\str_starts_with($class, 'Compile\\Mir\\')) {
        require_once $srcBase . '/Compile/Mir/Nodes.php';
    }
});

require_once $srcBase . '/Manticore/Main.php';

\Compile\Debug::initFromEnvironment();

if ($argc < 2) {
    \fwrite(STDERR, "usage: compile_user_mir.php <file.php>\n");
    exit(64);
}

$file = $argv[1];
if (!\is_file($file)) {
    \fwrite(STDERR, "not a file: $file\n");
    exit(66);
}

$sig = \getenv('MC_SIG');
if (\is_string($sig) && $sig !== '' && \is_file($sig)) {
    $json = \file_get_contents($sig);
    if ($json !== false) {
        \Manticore\CompileArgs::$externDecls = \Manticore\Sig::declsFromJson($json);
    }
}

\Manticore\CompileArgs::$files = [$file];

$ir = \Manticore\compile_via_mir([\file_get_contents($file)]);
if ($ir === null) {
    \fwrite(STDERR, "compile error (MIR)\n");
    exit(70);
}

\fwrite(STDERR, 'LINK_STDLIB=' . (\Manticore\CompileArgs::$linkStdlib ? '1' : '0') . "\n");
echo $ir;
