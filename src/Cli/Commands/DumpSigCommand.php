<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\ParsedArgs;

final class DumpSigCommand extends Command
{
    public function name(): string { return 'dump-sig'; }

    public function summary(): string { return 'Parse PHP, print the module-interface .sig (exported symbol table)'; }

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
        $module = \Manticore\lower_module($sources);
        if ($module === null) {
            return 65;
        }
        // Empty libs/weak: this command runs the front end only, and the link
        // requirements are a property of what the EMITTER produced. Inspecting a
        // module's signatures never links anything, so there is nothing to record.
        \Manticore\puts(\Manticore\Sig::emitModule($module));
        return 0;
    }
}
