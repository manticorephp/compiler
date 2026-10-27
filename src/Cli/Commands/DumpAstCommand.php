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
            new Option('names', \Cli\ArgParse::FLAG, 'Print the written-name table instead of the tree'),
            new Option('decl-names', \Cli\ArgParse::VALUE, 'With --tolerant --names: also record declaration names and use roles (1 = class-likes, functions, enum cases; 2 = also members and class constants)', 'level'),
            new Option('check-names', \Cli\ArgParse::FLAG, 'Verify every name span slices to its written text; print each mismatch, exit 1 if any'),
            new Option('check-ranges', \Cli\ArgParse::FLAG, 'Verify the range invariants; print each violation, exit 1 if any'),
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
        $ranges = $args->flag('ranges');
        if ($args->flag('check-ranges')) {
            $file = \count(\Manticore\CompileArgs::$files) === \count($sources) ? \Manticore\CompileArgs::$files[0] : '';
            return $this->checkRanges($sources[0], $file, $args->flag('tolerant'));
        }
        if ($args->flag('check-names')) {
            $file = \count(\Manticore\CompileArgs::$files) === \count($sources) ? \Manticore\CompileArgs::$files[0] : '';
            return $this->checkNames($sources[0], $file, $args->flag('tolerant'), (int)$args->value('decl-names', '0'));
        }
        if ($args->flag('names')) {
            $program = $this->parseForNames($sources[0], true, (int)$args->value('decl-names', '0'));
            if ($program === null) { return 65; }
            \Manticore\puts(\Parser\Dump::names($program));
            return 0;
        }
        if ($args->flag('tolerant')) {
            $r = \Parser\Parser::parseTolerant($sources[0]);
            $out = \Parser\Dump::program($r->program, $ranges);
            foreach ($r->errors as $e) {
                $out .= 'error ' . (string)$e->errLine . ':' . (string)$e->column . '-'
                    . (string)$e->endLine . ':' . (string)$e->endColumn . ' ' . $e->reason . "\n";
            }
            \Manticore\puts($out);
            return 0;
        }
        try {
            $program = \Parser\Parser::parseSource($sources[0], '', false, $ranges);
        } catch (\Throwable $e) {
            \Manticore\dprint("parse failed: " . $e->getMessage());
            return 65;
        }
        \Manticore\puts(\Parser\Dump::program($program, $ranges));
        return 0;
    }

    private function parseForNames(string $source, bool $tolerant = true, int $declNames = 0): ?\Parser\Ast\Program
    {
        try {
            if ($tolerant) {
                return \Parser\Parser::parseTolerant($source, '', true, true, $declNames)->program;
            }
            return \Parser\Parser::parseSource($source, '', false, false, true);
        } catch (\Throwable $e) {
            \Manticore\dprint("parse failed: " . $e->getMessage());
            return null;
        }
    }

    private function checkNames(string $source, string $file, bool $tolerant, int $declNames): int
    {
        $program = $this->parseForNames($source, $tolerant, $declNames);
        if ($program === null) { return 65; }
        $violations = \Parser\NameCheck::verify($program, $source, $file);
        if (\count($violations) === 0) {
            return 0;
        }
        \Manticore\puts(\implode("\n", $violations) . "\n");
        return 1;
    }

    private function checkRanges(string $source, string $file, bool $tolerant): int
    {
        try {
            if ($tolerant) {
                $program = \Parser\Parser::parseTolerant($source)->program;
            } else {
                $program = \Parser\Parser::parseSource($source, '', false, true);
            }
        } catch (\Throwable $e) {
            \Manticore\dprint("parse failed: " . $e->getMessage());
            return 65;
        }
        $violations = \Parser\RangeCheck::verify($program, true, $file);
        if (\count($violations) === 0) {
            return 0;
        }
        \Manticore\puts(\implode("\n", $violations) . "\n");
        return 1;
    }
}
