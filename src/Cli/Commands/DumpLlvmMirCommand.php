<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\ParsedArgs;

final class DumpLlvmMirCommand extends Command
{
    public function name(): string { return 'dump-llvm-mir'; }

    public function summary(): string { return 'Parse PHP, run MIR pipeline + EmitLlvm, print LLVM IR'; }

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
        $ir = \Manticore\compile_via_mir($sources);
        if ($ir === null) {
            return 65;
        }
        \Manticore\puts($ir);
        return 0;
    }
}
