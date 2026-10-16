<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\ParsedArgs;

final class DumpLlvmCommand extends Command
{
    public function name(): string { return 'dump-llvm'; }

    public function summary(): string { return 'Read PHP source from stdin, emit LLVM IR on stdout'; }

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
        $ir = \Manticore\compile_with_backend($sources);
        if ($ir === null) {
            return 65;
        }
        \Manticore\puts($ir);
        return 0;
    }
}
