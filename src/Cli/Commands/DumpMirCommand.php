<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\ParsedArgs;

final class DumpMirCommand extends Command
{
    public function name(): string { return 'dump-mir'; }

    public function summary(): string { return 'Parse PHP, lower to MIR, print the typed IR'; }

    public function usage(): string { return '[options] [files…]'; }

    /** @return \Cli\Option[] */
    public function options(): array { return CompileOptions::shared(); }

    public function run(ParsedArgs $args): int
    {
        if (!\Manticore\apply_compile_args($args)) {
            return 64;
        }
        $sources = \Manticore\resolve_sources(\Manticore\CompileArgs::$files);
        if ($sources === null) {
            return 66;
        }
        if (\count($sources) === 0) {
            return 66;
        }
        // Share the one pipeline `compile`/`dump-sig` run (lower_module) rather
        // than a hand-copied subset. The old inlined list skipped InlineClosures,
        // FuseSplitJoin, DemoteCharLocals, Verify and the pre-mono re-runs, and
        // it built LowerFromAst with no prelude — so the dump was pre-optimization
        // IR of a program with no Exception hierarchy, and could never match what
        // a real compile lowers. It also parsed only $sources[0]; lower_module
        // loops every file, so multi-file dump-mir now works too.
        $module = \Manticore\lower_module($sources);
        if ($module === null) {
            return 65;
        }
        \Manticore\puts(\Compile\Mir\Dump::module($module, \Manticore\CompileArgs::$dumpPrelude, \Manticore\CompileArgs::$dumpEffects));
        \Compile\Stats::dumpCounters();
        return 0;
    }
}
