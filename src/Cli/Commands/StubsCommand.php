<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\Option;
use Cli\ParsedArgs;
use Semantic\LibrarySymbols;
use Semantic\Stubber;

// Declaration-only stubs (phpstorm-stubs style) of PHP files and directories (*.php, recursive,
// sorted). Bodies become `{}`; declarations, docblocks, attributes and defaults are kept byte-exact.
// One input file keeps its own shape; several merge into one file of braced namespace blocks.
// `--library` writes the analyzer/LSP library stubs (prelude + stdlib .sig) that bin/build ships
// as lib/manticore.stubs.php and embeds in the compiler.
final class StubsCommand extends Command
{
    public function name(): string { return 'stubs'; }

    public function summary(): string { return 'Export declaration-only stubs of PHP code'; }

    public function usage(): string { return '[options] <paths…>'; }

    /** @return \Cli\Option[] */
    public function options(): array
    {
        return [
            new Option('o', \Cli\ArgParse::VALUE, 'Write to this file (with --split: directory) instead of stdout', 'path'),
            new Option('split', \Cli\ArgParse::FLAG, 'One stub file per input file, under the -o directory'),
            new Option('keep-lines', \Cli\ArgParse::FLAG, 'Keep every declaration on its source line (bodies become blank lines)'),
            new Option('library', \Cli\ArgParse::FLAG, 'Write the library stubs the analyzer and LSP read (prelude + stdlib)'),
        ];
    }

    public function run(ParsedArgs $args): int
    {
        $out = $args->value('o', '');
        if ($args->flag('library')) {
            try {
                return self::emit($out, LibrarySymbols::generate());
            } catch (\Throwable $e) {
                \Manticore\dprint('stubs: ' . $e->getMessage());
                return 65;
            }
        }
        $split = $args->flag('split');
        if ($split && $out === '') {
            \Manticore\dprint('stubs: --split needs -o <directory>');
            return 64;
        }
        if (\count($args->positional) === 0) {
            \Manticore\dprint('stubs: no input paths');
            return 64;
        }
        $files = \Manticore\resolve_source_files($args->positional);
        if ($files === null || \count($files) === 0) {
            return 66;
        }
        $keep = $args->flag('keep-lines');
        $merge = !$split && \count($files) > 1;
        $st = new Stubber();
        /** @var string[] $parts */
        $parts = [];
        foreach ($files as $sf) {
            $path = \Manticore\__mc_source_path($sf);
            try {
                $stub = $st->stubFile(\Manticore\__mc_source_contents($sf), $keep, $merge ? $path : '');
            } catch (\Throwable $e) {
                \Manticore\dprint('stubs: ' . $path . ': ' . $e->getMessage());
                return 65;
            }
            if ($split) {
                $rel = \ltrim(\str_starts_with($path, './') ? \substr($path, 2) : $path, '/');
                $rc = self::emit($out . '/' . $rel, $stub);
                if ($rc !== 0) { return $rc; }
                continue;
            }
            $parts[] = $stub;
        }
        if ($split) {
            return 0;
        }
        return self::emit($out, $merge ? Stubber::merge($parts) : $parts[0]);
    }

    private static function emit(string $path, string $text): int
    {
        if ($path === '') {
            echo $text;
            return 0;
        }
        $dir = \dirname($path);
        if ($dir !== '' && !\is_dir($dir)) {
            @\mkdir($dir, 0777, true);
        }
        if (@\file_put_contents($path, $text) !== \strlen($text)) {
            \Manticore\dprint('stubs: cannot write ' . $path);
            return 73;
        }
        return 0;
    }
}
