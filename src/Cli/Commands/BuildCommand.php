<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\Option;
use Cli\ParsedArgs;

/**
 * Cargo-like manifest build. Builds every library target (to a standalone
 * `.o`), then every application target (to an executable, auto-linking and
 * importing the signatures of all library targets).
 *
 * The manifest is decoded with the native `json_decode`; values flow as
 * `mixed`, extracted into typed locals via `(string)` casts before use.
 *
 * Last positional = manifest path; `--libs-only` builds the library targets
 * and stops (used by the cold seed to refresh stdlib.o without re-linking the
 * applications), `--apps-only` is its mirror. The shared compile options ride
 * along so a manifest build accepts `-O0` / `--memory` like `compile` does.
 *
 * The pair is what lets a self-host rebuild put the LIBRARIES on the far
 * side of the binary swap: one process cannot do it, because the libraries
 * are built by the compiler that is running, and the point is to build them
 * with the compiler that was just produced ({@see bin/build}).
 */
final class BuildCommand extends Command
{
    public function name(): string { return 'build'; }

    public function summary(): string { return 'Build all targets from a manticore.json manifest (libraries + applications)'; }

    public function usage(): string { return '[options] <manticore.json>'; }

    /** @return \Cli\Option[] */
    public function options(): array
    {
        return \array_merge(CompileOptions::shared(), [
            new Option('libs-only', \Cli\ArgParse::FLAG, 'Build library targets and stop (cold seed)'),
            new Option('apps-only', \Cli\ArgParse::FLAG, 'Build application targets only'),
            new Option('no-analyze', \Cli\ArgParse::FLAG, 'Skip the analyzer'),
        ]);
    }

    public function run(ParsedArgs $args): int
    {
        if (!\Manticore\apply_compile_args($args)) {
            return 64;
        }
        // apply_compile_args fills $files from the positionals, and lower_module
        // bakes $files[0] into $module->sourceFile — the text behind
        // Throwable::getFile() and every "… in <file> on line N" diagnostic. A
        // manifest path is not a source file, so clear it.
        \Manticore\CompileArgs::$files = [];
        $nPos = \count($args->positional);
        $manifestPath = $nPos > 0 ? $args->positional[$nPos - 1] : "manticore.json";
        $libsOnly = $args->flag("libs-only");
        $appsOnly = $args->flag("apps-only");
        if ($libsOnly && $appsOnly) {
            \Manticore\dprint("build: --libs-only and --apps-only are mutually exclusive");
            return 64;
        }
        \Manticore\CompileArgs::$allowUndefinedTraps = $args->flag("allow-undefined-traps");
        \Manticore\CompileArgs::$keepIr = $args->flag("keep-ir");
        $src = \Manticore\read_file($manifestPath);
        if ($src === null) {
            \Manticore\dprint("build: cannot read manifest: " . $manifestPath);
            return 66;
        }
        $manifest = \json_decode($src, true);
        $libs = isset($manifest["libraries"]) ? $manifest["libraries"] : [];
        $rc = $this->buildManifestLibraries($libs, $appsOnly);
        if ($rc !== 0) {
            return $rc;
        }
        if ($libsOnly) {
            return 0;
        }
        $apps = isset($manifest["applications"]) ? $manifest["applications"] : [];
        foreach ($apps as $app) {
            $name = (string)$app["name"];
            $srcDir = (string)$app["src"];
            $output = (string)$app["output"];
            /** @var string[] $excludes */
            $excludes = [];
            foreach ($app["exclude"] as $e) {
                $excludes[] = (string)$e;
            }
            // Explicit entry point: the file whose top-level code becomes the
            // program's main(). Module files (everything else) contribute only
            // their declarations, so the entry is excluded from the module scan
            // and appended LAST — its top-level lowers into `__main` after every
            // class/function is registered. Optional: with no `entry`, fall back
            // to the find|sort order (a `zzz_*` driver sorts last by convention).
            $entry = "";
            if (isset($app["entry"])) {
                $entry = (string)$app["entry"];
            }
            $moduleExcludes = $excludes;
            if ($entry !== "") {
                $moduleExcludes[] = $entry;
            }
            \Manticore\dprint("build: application '" . $name . "' (" . $srcDir . " -> " . $output . ")");
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
            foreach (\Manticore\collect_php_source_files($srcDir, $moduleExcludes) as $sf) {
                if (isset($seenPath[\Manticore\__mc_source_path($sf)])) {
                    continue;
                }
                $seenPath[\Manticore\__mc_source_path($sf)] = true;
                $sources[] = \Manticore\__mc_source_contents($sf);
                $paths[] = \Manticore\__mc_source_path($sf);
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
                $withVendorBootstrap = $withVendor || (\is_array(
                            $composer
                        ) && isset($composer["bootstrap"]) && $composer["bootstrap"] === true);
                /** @var string[] $packageAllow */
                $packageAllow = [];
                if (\is_array($composer) && isset($composer["packages"]) && \is_array($composer["packages"])) {
                    foreach ($composer["packages"] as $packageName) {
                        $packageAllow[] = (string)$packageName;
                    }
                }
                // Composer's own exclusions join the manifest's. Applied to the
                // composer-discovered roots only: the manifest's `src` is the
                // project's own code, where the author's `exclude` is the authority.
                $moduleExcludes = \array_merge(
                    $moduleExcludes,
                    \Manticore\composer_classmap_excludes(".", $withVendor, $packageAllow),
                );
                /** @var array<string,bool> $covered */
                $covered = [];
                $covered[\rtrim($srcDir, "/")] = true;
                // composer's BOOTSTRAP set. Everything else it autoloads is
                // demand-driven, so a file there that declares nothing is not a
                // compile unit at all ({@see __mc_source_may_declare}).
                $bootFiles = \Manticore\composer_autoload_file_entries(".", $withVendorBootstrap, $packageAllow);
                $skippedScripts = 0;
                foreach (\Manticore\composer_source_dirs(".", $withVendor, $packageAllow) as $cdir) {
                    $nd = \rtrim($cdir, "/");
                    if (isset($covered[$nd])) {
                        continue;
                    }
                    $covered[$nd] = true;
                    \Manticore\dprint("build: + composer autoload '" . $nd . "'");
                    foreach (\Manticore\collect_php_source_files($nd, $moduleExcludes) as $sf) {
                        if (isset($seenPath[\Manticore\__mc_source_path($sf)])) {
                            continue;
                        }
                        $seenPath[\Manticore\__mc_source_path($sf)] = true;
                        $sfNorm = \rtrim(\Manticore\__mc_source_path($sf), "/");
                        $isBoot = isset($bootFiles[$sfNorm]) || isset($bootFiles[$nd]);
                        $sfContents = \Manticore\__mc_source_contents($sf);
                        $sfMayDeclare = \Manticore\__mc_source_may_declare($sfContents);
                        $sfPath = \Manticore\__mc_source_path($sf);
                        // A pure data file is kept as an ordinary (non-demand) unit:
                        // its only statement is the `return` a `require` reads.
                        if (!$isBoot && !$sfMayDeclare && \Manticore\__mc_source_is_data_file($sfContents)) {
                            $sources[] = $sfContents;
                            $paths[] = $sfPath;
                            continue;
                        }
                        if (!$isBoot && !$sfMayDeclare) {
                            $skippedScripts = $skippedScripts + 1;
                            \Manticore\dprint("build:   - script (declares nothing, never autoloaded): " . $sfPath);
                            continue;
                        }
                        // Demand-loaded: keep its DECLARATIONS, drop its top-level
                        // side effects ({@see \Manticore\CompileArgs::$demandLoadedPaths}). The
                        // The conservative detector is intentional here: Composer
                        // class files are the reachability boundary, and a false
                        // negative would silently erase a valid class from the AOT module.
                        if (!$isBoot) {
                            \Manticore\CompileArgs::$demandLoadedPaths[$sfNorm] = true;
                        }
                        $sources[] = $sfContents;
                        $paths[] = $sfPath;
                    }
                }
                if (!$withVendor && $withVendorBootstrap) {
                    foreach ($bootFiles as $bootPath => $_boot) {
                        $bootNorm = \str_starts_with($bootPath, "./") ? \substr($bootPath, 2) : $bootPath;
                        if (isset($seenPath[$bootNorm])) {
                            continue;
                        }
                        $bootSrc = \Manticore\read_file($bootPath);
                        if ($bootSrc === null) {
                            continue;
                        }
                        $seenPath[$bootNorm] = true;
                        $sources[] = $bootSrc;
                        $paths[] = $bootNorm;
                        \Manticore\dprint("build: + composer bootstrap \"" . $bootNorm . "\"");
                    }
                }
                if ($skippedScripts > 0) {
                    \Manticore\dprint(
                        "build: skipped " . (string)$skippedScripts
                        . " non-declaring script(s) under demand-loaded autoload roots"
                    );
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
                    \Manticore\dprint("build: app '" . $name . "' wants unknown extension '" . $en . "'");
                    return 66;
                }
                $ext = $extDefs[$en];
                $extSrc = (string)$ext["src"];
                foreach (\Manticore\collect_php_source_files($extSrc, []) as $sf) {
                    $sources[] = \Manticore\__mc_source_contents($sf);
                    $paths[] = \Manticore\__mc_source_path($sf);
                }
                foreach ($ext["link"] as $lib) {
                    $linkFlags = $linkFlags . " -l" . (string)$lib;
                }
                \Manticore\dprint("build: + extension '" . $en . "' (" . $extSrc . ")");
            }
            if ($entry !== "") {
                $entrySrc = \Manticore\read_file($entry);
                if ($entrySrc === null) {
                    \Manticore\dprint("build: cannot read entry point: " . $entry);
                    return 66;
                }
                $sources[] = $entrySrc;
                $paths[] = $entry;
            }
            if (\count($sources) === 0) {
                \Manticore\dprint("build: no sources for application '" . $name . "'");
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
                foreach ($app["libraries"] as $w) {
                    $wanted[] = (string)$w;
                }
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
                if ($isRuntime) {
                    continue;
                }
                $libName = (string)$lib["name"];
                if (!$selectAll) {
                    $take = false;
                    foreach ($wanted as $w) {
                        if ($w === $libName) {
                            $take = true;
                            break;
                        }
                    }
                    if (!$take) {
                        continue;
                    }
                }
                $libOut = (string)$lib["output"];
                $sigJson = \Manticore\read_file($libOut . ".sig");
                if ($sigJson !== null) {
                    $bad = \Manticore\Sig::validateImport($sigJson, $libOut . ".sig");
                    if ($bad !== "") {
                        \Manticore\dprint($bad);
                        return 65;
                    }
                    foreach (\Manticore\Sig::declsFromJson($sigJson) as $d) {
                        $externDecls[] = $d;
                    }
                    foreach (\Manticore\Sig::classDeclsFromJson($sigJson) as $cdecl) {
                        $externClassDecls[] = $cdecl;
                    }
                    foreach (\Manticore\Sig::classMetaFromJson($sigJson) as $mname => $meta) {
                        // Two libraries exporting one FQN is not a merge — their
                        // layouts are independent and only one `.o` can win the
                        // symbol. Refuse rather than pick.
                        if (isset($classOrigin[$mname])) {
                            \Manticore\dprint(
                                "manticore: class " . $mname . " is exported by both "
                                . $classOrigin[$mname] . " and " . $libOut
                            );
                            return 65;
                        }
                        $classOrigin[$mname] = $libOut;
                        $externClassMeta[$mname] = $meta;
                    }
                    foreach (\Manticore\Sig::constantsFromJson($sigJson) as $cn => $cv) {
                        $externConstants[$cn] = $cv;
                    }
                } else {
                    \Manticore\dprint("build: missing .sig for library output " . $libOut);
                }
                $linkObjs[] = $libOut;
            }
            \Manticore\CompileArgs::$externDecls = $externDecls;
            \Manticore\CompileArgs::$externClassDecls = $externClassDecls;
            \Manticore\CompileArgs::$externClassMeta = $externClassMeta;
            \Manticore\CompileArgs::$externConstants = $externConstants;
            if (\Manticore\build_cache_hit($sources, $paths, $output, false, $linkObjs, $linkFlags, !$skipStdlib)) {
                \Manticore\dprint("build: cache hit application " . $name);
                $rc = 0;
            } else {
                // lower_module releases raw source strings through the reference;
                // retain the cache identity before that intentional mutation.
                $cacheKey = \Manticore\build_cache_key($sources, $paths, $output, false, $linkObjs, $linkFlags, !$skipStdlib);
                $rc = \Manticore\build_compile_module($sources, $output, false, $linkObjs, $linkFlags, !$skipStdlib, $paths);
                if ($rc === 0) {
                    \Manticore\build_cache_store($sources, $paths, $output, false, $linkObjs, $linkFlags, !$skipStdlib, $cacheKey);
                }
            }
            if ($rc !== 0) {
                return $rc;
            }
        }
        return 0;
    }

    /** @param mixed[] $libs */
    private function buildManifestLibraries(array $libs, bool $appsOnly): int
    {
        foreach ($libs as $lib) {
            // --apps-only skips BUILDING the libraries; the list itself is still
            // needed below, where each non-runtime library's `.o`/`.sig` joins the
            // application's link (they must already exist on disk).
            if ($appsOnly) {
                break;
            }
            $name = (string)$lib["name"];
            $srcDir = (string)$lib["src"];
            $output = (string)$lib["output"];
            /** @var string[] $excludes */
            $excludes = [];
            foreach ($lib["exclude"] as $e) {
                $excludes[] = (string)$e;
            }
            \Manticore\dprint("build: library '" . $name . "' (" . $srcDir . " -> " . $output . ")");
            /** @var string[] $sources */
            $sources = [];
            /** @var string[] $paths */
            $paths = [];
            $libComposer = isset($lib["composer"]) ? $lib["composer"] : false;
            $libComposerOn = ($libComposer === true) || \is_array($libComposer);
            if ($libComposerOn) {
                $withVendor = !(
                    \is_array($libComposer)
                    && isset($libComposer["vendor"])
                    && $libComposer["vendor"] === false
                );
                /** @var string[] $packageAllow */
                $packageAllow = [];
                if (\is_array($libComposer) && isset($libComposer["packages"]) && \is_array($libComposer["packages"])) {
                    foreach ($libComposer["packages"] as $packageName) {
                        $packageAllow[] = (string)$packageName;
                    }
                }
                // Libraries can opt into the same Composer-resolved source set as
                // applications. This is deliberately opt-in: the old `src` walk
                // remains byte-for-byte the default for existing manifests.
                $libExcludes = \array_merge(
                    $excludes,
                    \Manticore\composer_classmap_excludes(".", $withVendor, $packageAllow),
                );
                $bootFiles = \Manticore\composer_autoload_file_entries(".", $withVendor, $packageAllow);
                /** @var array<string,bool> $seenPath */
                $seenPath = [];
                /** @var array<string,bool> $covered */
                $covered = [];
                foreach (\Manticore\composer_source_dirs(".", $withVendor, $packageAllow) as $cdir) {
                    $nd = \rtrim($cdir, "/");
                    if (isset($covered[$nd])) {
                        continue;
                    }
                    $covered[$nd] = true;
                    foreach (\Manticore\collect_php_source_files($nd, $libExcludes) as $sf) {
                        $sfNorm = \rtrim(\Manticore\__mc_source_path($sf), "/");
                        if (\str_starts_with($sfNorm, "./")) {
                            $sfNorm = \substr($sfNorm, 2);
                        }
                        if (isset($seenPath[$sfNorm])) {
                            continue;
                        }
                        $seenPath[$sfNorm] = true;
                        // Composer files are application bootstrap code, not a
                        // library declaration unit. The application-side
                        // `bootstrap:true` path adds them later.
                        if (isset($bootFiles[$sfNorm])) {
                            continue;
                        }
                        if (!\Manticore\__mc_library_source_may_declare(\Manticore\__mc_source_contents($sf))
                            && !\Manticore\__mc_source_is_data_file(\Manticore\__mc_source_contents($sf))) {
                            continue;
                        }
                        $sources[] = \Manticore\__mc_source_contents($sf);
                        $paths[] = \Manticore\__mc_source_path($sf);
                    }
                }
            } else {
                foreach (\Manticore\collect_php_source_files($srcDir, $excludes) as $sf) {
                    $sources[] = \Manticore\__mc_source_contents($sf);
                    $paths[] = \Manticore\__mc_source_path($sf);
                }
            }
            if (\count($sources) === 0) {
                \Manticore\dprint("build: no sources for library '" . $name . "'");
                return 66;
            }
            \Manticore\CompileArgs::$externDecls = [];
            \Manticore\CompileArgs::$externClassDecls = [];
            \Manticore\CompileArgs::$externClassMeta = [];
            \Manticore\CompileArgs::$externConstants = [];
            \Manticore\CompileArgs::$exportTypes =
                !(isset($lib["runtime"]) && (string)$lib["runtime"] === "1");
            // Every library but the stdlib itself declares the stdlib's functions
            // ({@see build_compile_module}).
            $libStdlib = \Manticore\CompileArgs::$exportTypes;
            if (\Manticore\build_cache_hit($sources, $paths, $output, true, [], "", $libStdlib)) {
                \Manticore\dprint("build: cache hit library " . $name);
                $rc = 0;
            } else {
                // Preserve the cache identity before lower_module releases source
                // contents through its reference parameter.
                $cacheKey = \Manticore\build_cache_key($sources, $paths, $output, true, [], "", $libStdlib);
                $rc = \Manticore\build_compile_module($sources, $output, true, [], "", $libStdlib, $paths);
                if ($rc === 0) { \Manticore\build_cache_store($sources, $paths, $output, true, [], "", $libStdlib, $cacheKey); }
            }
            \Manticore\CompileArgs::$exportTypes = true;
            if ($rc !== 0) {
                return $rc;
            }
        }
        return 0;
    }
}
