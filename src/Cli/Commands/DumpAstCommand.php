<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\Option;
use Cli\ParsedArgs;

final class DumpAstCommand extends Command
{
    public function name(): string { return 'dump-ast'; }

    public function summary(): string { return 'Parse PHP source and print the resulting AST'; }

    public function usage(): string { return '[options] [files…]'; }

    /** @return \Cli\Option[] */
    public function options(): array
    {
        return \array_merge(CompileOptions::shared(), [
            new Option('tolerant', \Cli\ArgParse::FLAG, 'Recover from syntax errors; print the tree and every error'),
            new Option('ranges', \Cli\ArgParse::FLAG, 'Print spans as start-end ranges'),
        ]);
    }

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
        if ($args->flag('tolerant')) {
            $r = \Parser\Parser::parseTolerant($sources[0]);
            $out = \Parser\Dump::program($r->program);
            foreach ($r->errors as $e) {
                $out .= 'error ' . (string)$e->errLine . ':' . (string)$e->column . '-'
                    . (string)$e->endLine . ':' . (string)$e->endColumn . ' ' . $e->reason . "\n";
            }
            \Manticore\puts($out);
            return 0;
        }
        try {
            $program = \Parser\Parser::parseSource($sources[0]);
        } catch (\Throwable $e) {
            \Manticore\dprint("parse failed: " . $e->getMessage());
            return 65;
        }
        \Manticore\puts(\Parser\Dump::program($program));
        return 0;
    }
}
