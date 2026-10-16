<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\Option;
use Cli\ParsedArgs;

// `--deep` also runs the compiler's own MIR type passes (repr-soundness) via
// lower_module in analysis mode — heavier (it lowers the code), so opt-in.
// `--json` prints machine-readable output for editors / CI.
// `--only <prefix[,prefix…]>` keeps just the diagnostic codes that start
// with one of the prefixes, and gates on ANY survivor whatever its severity.
// One caller drives the shape: the self-host preflight
// (`analyze src --only undefined.,parse.error`), which asks the narrow
// question "can THIS compiler still build this source" and must not be
// drowned by the 177 style warnings a full run reports.
final class AnalyzeCommand extends Command
{
    public function name(): string { return 'analyze'; }

    public function summary(): string { return 'Static type analysis; report diagnostics, no codegen'; }

    public function usage(): string { return '[options] [paths…]'; }

    /** @return \Cli\Option[] */
    public function options(): array
    {
        return \array_merge(CompileOptions::shared(), [
            new Option('deep', \Cli\ArgParse::FLAG, "Also run the compiler's MIR type passes (heavier)"),
            new Option('json', \Cli\ArgParse::FLAG, 'Machine-readable output'),
            new Option('baseline', \Cli\ArgParse::VALUE, 'Suppress findings listed in this baseline file', 'path'),
            new Option('generate-baseline', \Cli\ArgParse::VALUE, 'Write a baseline file from current findings', 'path'),
            new Option('only', \Cli\ArgParse::VALUE, 'Keep only diagnostic codes starting with these prefixes (comma list); gates on any survivor', 'prefixes'),
        ]);
    }

    public function run(ParsedArgs $args): int
    {
        if (!\Manticore\apply_compile_args($args)) {
            return 64;
        }
        $deep = $args->flag("deep");
        $json = $args->flag("json");
        $baselinePath = $args->value("baseline", "");
        $genBaseline = $args->value("generate-baseline", "");
        $only = $args->value("only", "");

        $files = \Manticore\resolve_source_files(\Manticore\CompileArgs::$files);
        if ($files === null) {
            return 66;
        }
        if (\count($files) === 0) {
            return 66;
        }

        $diags = \Manticore\perform_analysis($files, \Manticore\CompileArgs::$files, $deep);

        // Generate a baseline: snapshot every current finding and report nothing.
        if ($genBaseline !== "") {
            if (!\Manticore\write_file($genBaseline, \Analyze\Baseline::generate($diags))) {
                \Manticore\dprint("analyze: could not write baseline to " . $genBaseline);
                return 73;
            }
            \Manticore\dprint("analyze: wrote baseline (" . (string)\count($diags) . " entries) to " . $genBaseline);
            return 0;
        }
        // Apply a baseline: drop known findings.
        if ($baselinePath !== "") {
            $bl = \Manticore\read_file($baselinePath);
            if ($bl !== null) {
                $diags = \Analyze\Baseline::filter($diags, $bl);
            }
        }

        if ($only !== "") {
            /** @var string[] $prefixes */
            $prefixes = \explode(",", $only);
            /** @var \Analyze\Diagnostic[] $kept */
            $kept = [];
            foreach ($diags as $d) {
                foreach ($prefixes as $pre) {
                    if ($pre !== "" && \str_starts_with($d->code, $pre)) {
                        $kept[] = $d;
                        break;
                    }
                }
            }
            $diags = $kept;
        }

        echo $json ? \Analyze\Report::json($diags) : \Analyze\Report::human($diags);
        // Counters live on stderr while the report goes to stdout, so
        // `analyze --deep` doubles as the whole-project instrumentation vehicle
        // (no giant `dump-mir` text to build and throw away).
        \Compile\Stats::dumpCounters();

        // Under --only the selection IS the gate: a warning-severity undefined
        // symbol still means this compiler cannot resolve the name.
        if ($only !== "") {
            return \count($diags) > 0 ? 1 : 0;
        }
        foreach ($diags as $d) {
            if ($d->severity === \Analyze\Diagnostic::SEV_ERROR) {
                return 1;
            }
        }
        return 0;
    }
}
