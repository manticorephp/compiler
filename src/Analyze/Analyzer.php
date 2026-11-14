<?php

namespace Analyze;

/**
 * Orchestrator. Given the parsed input files it builds a project-wide symbol
 * {@see Index}, then runs every rule over each file's AST and collects the
 * emitted {@see Diagnostic}s.
 *
 * Read-only: this never runs the compile / codegen pipeline, so it cannot
 * affect the self-host build (that isolation is the whole reason `analyze` is a
 * separate command rather than a pass wired into `compile`). The repr-soundness
 * family (a later phase) is the one exception — it consumes the lowered MIR
 * module, but still only reads it.
 *
 * Rules run per file against the shared {@see Index}; their diagnostics are
 * concatenated into one stream.
 */
final class Analyzer
{
    /** @var Diagnostic[] */
    public array $diagnostics = [];

    /**
     * @param ParsedFile[] $files   the user files to REPORT on
     * @param ParsedFile[] $libFiles  prelude/library files added to the symbol
     *                                 index but never themselves reported
     * @param bool $checkUndefined  run closed-world undefined-symbol rules (only
     *                              safe in whole-project mode)
     * @param ?Index $libIndex    the libFiles already indexed (a cached prelude index), used instead of them
     * @return Diagnostic[]
     */
    public function run(array $files, array $libFiles = [], bool $checkUndefined = false, array $stdlibFns = [], ?Index $libIndex = null): array
    {
        /** @var ParsedFile[] $all */
        $all = [];
        // Index::build keeps the FIRST declarer; the legacy rule is last wins among user files
        // and library over user, so feed the library first and the user files reversed.
        foreach ($libFiles as $pf) { $all[] = $pf; }
        for ($i = \count($files) - 1; $i >= 0; $i--) { $all[] = $files[$i]; }
        $statT = \Compile\Stats::begin('analyze: index');
        $index = Index::build($all);
        if ($libIndex !== null) { $index->merge($libIndex); }
        $index->addExternFunctions($stdlibFns);
        \Compile\Stats::step('analyze: index', $statT, \count($all), -1);

        /** @var array<string, bool> $unitPaths */
        $unitPaths = [];
        foreach ($files as $pf) {
            $rp = \realpath($pf->path);
            if ($rp !== false) { $unitPaths[$rp] = true; }
        }

        foreach ($files as $pf) {
            foreach ($this->runFile($pf, $index, $checkUndefined, $unitPaths) as $d) { $this->diagnostics[] = $d; }
        }
        $this->diagnostics = Report::sortDiags($this->diagnostics);
        return $this->diagnostics;
    }

    /**
     * @param array<string, bool>|null $unitPaths realpaths of the compile set; null (single document) skips UnresolvedInclude
     * @return Diagnostic[] unsorted
     */
    public function runFile(ParsedFile $pf, Index $index, bool $checkUndefined, ?array $unitPaths = null): array
    {
        /** @var Diagnostic[] $out */
        $out = [];
        $argCount = new \Analyze\Rules\ArgCount();
        foreach ($argCount->run($pf, $index) as $d) { $out[] = $d; }

        $missingArray = new \Analyze\Rules\MissingArrayType();
        foreach ($missingArray->run($pf) as $d) { $out[] = $d; }

        $stringArith = new \Analyze\Rules\StringArithmetic();
        foreach ($stringArith->run($pf, $index) as $d) { $out[] = $d; }

        $argType = new \Analyze\Rules\ArgType();
        foreach ($argType->run($pf, $index) as $d) { $out[] = $d; }

        $returnType = new \Analyze\Rules\ReturnType();
        foreach ($returnType->run($pf, $index) as $d) { $out[] = $d; }

        // Async hazards. Both self-gate on the file naming `Async\`, so a
        // program that never touches the runtime keeps its output unchanged.
        $asyncStatic = new \Analyze\Rules\AsyncStaticLocal();
        foreach ($asyncStatic->run($pf) as $d) { $out[] = $d; }

        $asyncIo = new \Analyze\Rules\AsyncBlockingIo();
        foreach ($asyncIo->run($pf) as $d) { $out[] = $d; }

        // Method existence self-gates on a fully-known hierarchy, so it is
        // safe even in single-file mode.
        $undefMethod = new \Analyze\Rules\UndefinedMethod();
        foreach ($undefMethod->run($pf, $index) as $d) { $out[] = $d; }

        if ($unitPaths !== null) {
            $unresolvedInc = new \Analyze\Rules\UnresolvedInclude();
            foreach ($unresolvedInc->run($pf, $unitPaths) as $d) { $out[] = $d; }
        }

        if ($checkUndefined) {
            $undefClass = new \Analyze\Rules\UndefinedClass();
            foreach ($undefClass->run($pf, $index) as $d) { $out[] = $d; }

            $undefFn = new \Analyze\Rules\UndefinedFunction();
            foreach ($undefFn->run($pf, $index) as $d) { $out[] = $d; }

            $undefConst = new \Analyze\Rules\UndefinedClassConst();
            foreach ($undefConst->run($pf, $index) as $d) { $out[] = $d; }

            $classString = new \Analyze\Rules\ClassStringArg();
            foreach ($classString->run($pf, $index) as $d) { $out[] = $d; }
        }
        return $out;
    }
}
