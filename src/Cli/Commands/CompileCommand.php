<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\Option;
use Cli\ParsedArgs;

/**
 * Compile PHP sources to a native binary (or, with --emit-library, a library
 * object). The static analyzer runs by DEFAULT over the sources and prints its
 * diagnostics to stderr — advisory, NEVER fatal (the build proceeds
 * regardless). `--no-analyze` turns it off; `--analyze` is the (now default)
 * explicit opt-in. Uses the fast AST engine, not the heavy `--deep` lowering.
 */
final class CompileCommand extends Command
{
    public function name(): string { return 'compile'; }

    public function summary(): string { return 'Compile to a native binary (-o <out>); analyzes by default (--no-analyze skips, --analyze-strict gates on errors)'; }

    public function usage(): string { return '[options] <files…|manifest.json> -o <out>'; }

    /** @return \Cli\Option[] */
    public function options(): array
    {
        return \array_merge(CompileOptions::shared(), [
            new Option('analyze', \Cli\ArgParse::FLAG, 'Run the analyzer (default)'),
            new Option('no-analyze', \Cli\ArgParse::FLAG, 'Skip the analyzer'),
            new Option('analyze-strict', \Cli\ArgParse::FLAG, 'Fail with rc 65 on analyzer errors'),
        ]);
    }

    public function run(ParsedArgs $args): int
    {
        if (!\Manticore\apply_compile_args($args)) {
            \Manticore\dprint("compile: failed to parse args (rc=64)");
            return 64;
        }
        $analyze = !$args->flag("no-analyze");
        $strict = $args->flag("analyze-strict");
        $output = \Manticore\CompileArgs::$output;

        // Read once, as SourceFile[] (path + contents), so every downstream stage
        // — the analyzer AND the front-end's `parse failed` diagnostics — can name
        // the specific file that broke. Previously we ran resolve_sources() and
        // \Manticore\resolve_source_files() back-to-back and lost the paths on the compile
        // path, so a `bin/manticore compile manticore.json` (or one bad file inside
        // a directory arg) printed `parse failed: expected ';' after expression`
        // with no filename.
        $afiles = \Manticore\resolve_source_files(\Manticore\CompileArgs::$files);
        if ($afiles === null) {
            \Manticore\dprint("compile: source resolution failed — no input read (rc=66)");
            return 66;
        }
        if (\count($afiles) === 0) {
            \Manticore\dprint("compile: source list is empty (rc=66)");
            return 66;
        }
        /** @var string[] $sources */
        $sources = [];
        /** @var string[] $paths */
        $paths = [];
        foreach ($afiles as $sf) {
            $sources[] = \Manticore\__mc_source_contents($sf);
            $paths[] = \Manticore\__mc_source_path($sf);
        }

        if ($analyze) {
            // Advisory by default: any failure inside the analyzer is swallowed so it
            // can NEVER break a build. `--no-analyze` is the escape hatch. With
            // `--analyze-strict`, error-severity findings instead FAIL the compile
            // (rc=65, before codegen) — a lint gate for CI.
            try {
                if (\count($afiles) > 0) {
                    $adiags = \Manticore\perform_analysis($afiles, \Manticore\CompileArgs::$files, false);
                    if (\count($adiags) > 0) {
                        \error_log("\n" . \Analyze\Report::human($adiags));
                    }
                    if ($strict) {
                        $errs = 0;
                        foreach ($adiags as $d) {
                            if ($d->severity === \Analyze\Diagnostic::SEV_ERROR) {
                                $errs = $errs + 1;
                            }
                        }
                        if ($errs > 0) {
                            \Manticore\dprint("compile: analysis found " . (string)$errs . " error(s) (--analyze-strict, rc=65)");
                            return 65;
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Manticore\dprint("analyze: skipped (internal error: " . $e->getMessage() . ")");
            }
        }
        // Collect bundled-stdlib signatures here (native path: the libc file
        // bindings resolve to real syscalls). compile_via_mir consumes them via
        // the static. A library declares them too; only the stdlib itself
        // (`--runtime`) does not.
        $sigT = \Compile\Stats::now();
        \Manticore\CompileArgs::$externDecls = [];
        if (!\Manticore\import_stdlib_decls(\Manticore\CompileArgs::$emitLibrary, \Manticore\CompileArgs::$runtimeLibrary)) { return 65; }
        \Compile\Stats::step('stdlib .sig -> extern decls', $sigT,
            \count(\Manticore\CompileArgs::$externDecls), -1);
        // NOTE: the bundled PHP stdlib is NOT prepended here. Merging the whole
        // stdlib into every user program both bloats output and crashes the
        // compiler on some stdlib+user combinations (the "stdlib as guest"
        // hazard). The chosen design is a prebuilt stdlib.o linked at the cc step
        // (see discover_stdlib_files / the link tail) — built once, in isolation.
        $ir = \Manticore\compile_with_backend($sources, $paths);
        if ($ir === null) {
            \Manticore\dprint("compile: front-end (parse/typeck/IR) returned null (rc=65)");
            return 65;
        }
        if (\strlen($ir) === 0) {
            \Manticore\dprint("compile: front-end produced empty IR (rc=65)");
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
        $keep = \Manticore\CompileArgs::$keepIr;
        $base = $keep ? ($output . ".dbg") : ("/tmp/manticore_" . (string)\Manticore\getpid());
        $llPath = $base . ".ll";
        $objPath = $base . ".o";

        // Library build: assemble straight to the output .o, no link, and NEVER
        // split — `stdlib.o` is one object by contract (its `.sig` describes it, and
        // every consumer links exactly that file). The runtime preamble helpers are
        // linkonce_odr so it coexists with a user program's preamble at link time.
        if (\Manticore\CompileArgs::$emitLibrary) {
            if (!\Manticore\write_file($llPath, \Manticore\with_frame_pointers($ir))) {
                \Manticore\dprint("compile: cannot write " . $llPath . " (rc=73)");
                return 73;
            }
            if ($keep) {
                \Manticore\dprint("compile: kept IR " . $llPath);
            }
            $rcLib = \Manticore\system(
                "clang -O" . \Manticore\clang_opt_level() . \Manticore\clang_tuning_flags() . " -c -x ir " . $llPath . " -o " . $output . " -Wno-override-module"
            );
            if ($rcLib !== 0) {
                \Manticore\dprint("compile: clang -c (library) failed (rc=" . (string)$rcLib . "); IR at " . $llPath);
                return 75;
            }
            if (!$keep) {
                \Manticore\system("rm -f " . $llPath);
            }
            return 0;
        }

        // clang understands `-x ir` for plain LLVM textual IR. cc on both
        // Linux and macOS picks the system linker plus libc by default.
        // `-ffunction-sections` puts each function in its own section so the
        // link-time dead-strip below drops every unreferenced stdlib function
        // (a hello-world no longer drags in all of Json/Libc/stdlib).
        // Errors on stderr already, but surface our own rc too.
        $objs = \Manticore\assemble_ir($ir, $base, "-ffunction-sections -fdata-sections");
        if ($objs === []) {
            return 75;
        }
        if ($keep) {
            \Manticore\dprint("compile: kept IR " . $base . ".*.ll");
        }
        $objList = \implode(" ", $objs);
        // Link the prebuilt stdlib.o only when the program imported a bundled
        // stdlib function (str_starts_with / ctype_* / file_*). A self-contained
        // program (e.g. the compiler's own source, which defines the stdlib)
        // links without it — avoids duplicate symbols.
        $linkExtra = "";
        // Native libraries this module's own `#[Ffi\Library]` bindings named.
        $libs = \Manticore\CompileArgs::$ffiLibs;
        $weak = \Manticore\CompileArgs::$weakSyms;
        if (\Manticore\CompileArgs::$linkStdlib) {
            $stdlibObj = \Manticore\find_stdlib_object();
            if ($stdlibObj !== "") {
                $linkExtra = " " . $stdlibObj;
            } else {
                \Manticore\dprint("compile: program uses bundled stdlib but stdlib.o not found — link may fail");
            }
            // The bundled stdlib's own bindings — its preg_* wrappers reference
            // libpcre2-8, its TLS transport libssl/libcrypto, its iconv_* wrappers
            // libiconv (on Darwin; glibc has it inside libc), its scheduler
            // signalfd — none of which this module names. They ride in the stdlib's
            // `.sig`, since the wrapper that calls them is emitted over there.
            // Dead-strip + --as-needed drop whichever the program never reaches.
            foreach (
                \Manticore\stdlib_sig_list(
                    "libs",
                    ["pcre2-8", "ssl", "crypto", "iconv"]
                ) as $l
            ) {
                $libs[] = $l;
            }
            foreach (
                \Manticore\stdlib_sig_list(
                    "weak",
                    [
                        "__errno_location",
                        "epoll_create1",
                        "epoll_ctl",
                        "epoll_wait",
                        "signalfd",
                    ]
                ) as $w
            ) {
                $weak[] = $w;
            }
        }
        $libFlags = \Manticore\ffi_link_flags($libs);
        if ($libFlags !== "") {
            $linkExtra .= $libFlags;
        }
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
        //
        // `--as-needed` is POSITIONAL: it governs the libraries after it. Placed at
        // the tail it covered -lm alone, so on Debian every library reached the
        // binary, while Alpine's and Ubuntu's gcc switch it on at the head — two
        // different links from one command. It leads the line now, on every Linux.
        $gc = \Manticore\is_darwin()
            ? " -Wl,-dead_strip -Wl,-dead_strip_dylibs" . \Manticore\weak_undef_flags($weak) . \Manticore\darwin_export_flags()
            : " -Wl,--gc-sections -lm";
        $gc = $gc . \Manticore\unwind_link_flags();
        $asNeeded = \Manticore\is_darwin() ? "" : " -Wl,--as-needed";
        $rc2 = \Manticore\system("cc" . $asNeeded . " " . $objList . $linkExtra . $gc . " -o " . $output);
        if ($rc2 !== 0) {
            \Manticore\dprint("compile: cc link failed (rc=" . (string)$rc2 . "); objects at " . $objList);
            return 76;
        }
        // Linked: the staged IR and objects have no further use. Kept under
        // --keep-ir, which is the escape hatch for reading what was emitted.
        if (!$keep) {
            \Manticore\system("rm -f " . $llPath . " " . $objPath . " " . $objList . " " . $base . ".p*.ll");
        }
        return 0;
    }
}
