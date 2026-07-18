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
     * @return Diagnostic[]
     */
    public function run(array $files, array $libFiles = [], bool $checkUndefined = false, array $stdlibFns = []): array
    {
        /** @var ParsedFile[] $all */
        $all = [];
        foreach ($files as $pf) { $all[] = $pf; }
        foreach ($libFiles as $pf) { $all[] = $pf; }
        $index = Index::build($all);
        $index->addExternFunctions($stdlibFns);

        foreach ($files as $pf) {
            $argCount = new \Analyze\Rules\ArgCount();
            foreach ($argCount->run($pf, $index) as $d) { $this->diagnostics[] = $d; }

            $missingArray = new \Analyze\Rules\MissingArrayType();
            foreach ($missingArray->run($pf) as $d) { $this->diagnostics[] = $d; }

            $stringArith = new \Analyze\Rules\StringArithmetic();
            foreach ($stringArith->run($pf, $index) as $d) { $this->diagnostics[] = $d; }

            $argType = new \Analyze\Rules\ArgType();
            foreach ($argType->run($pf, $index) as $d) { $this->diagnostics[] = $d; }

            $returnType = new \Analyze\Rules\ReturnType();
            foreach ($returnType->run($pf, $index) as $d) { $this->diagnostics[] = $d; }

            // Method existence self-gates on a fully-known hierarchy, so it is
            // safe even in single-file mode.
            $undefMethod = new \Analyze\Rules\UndefinedMethod();
            foreach ($undefMethod->run($pf, $index) as $d) { $this->diagnostics[] = $d; }

            if ($checkUndefined) {
                $undefClass = new \Analyze\Rules\UndefinedClass();
                foreach ($undefClass->run($pf, $index) as $d) { $this->diagnostics[] = $d; }

                $undefFn = new \Analyze\Rules\UndefinedFunction();
                foreach ($undefFn->run($pf, $index) as $d) { $this->diagnostics[] = $d; }

                $undefConst = new \Analyze\Rules\UndefinedClassConst();
                foreach ($undefConst->run($pf, $index) as $d) { $this->diagnostics[] = $d; }
            }
        }
        $this->diagnostics = Report::sortDiags($this->diagnostics);
        return $this->diagnostics;
    }
}
