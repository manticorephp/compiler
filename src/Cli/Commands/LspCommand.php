<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\ParsedArgs;
use Lsp\JsonRpc\Dispatcher;
use Lsp\Server;
use Lsp\Transport\Stdio;
use Semantic\LibrarySymbols;
use Semantic\Workspace;

final class LspCommand extends Command
{
    public function name(): string { return 'lsp'; }

    public function summary(): string { return 'Run the language server on stdio'; }

    public function usage(): string { return ''; }

    public function run(ParsedArgs $args): int
    {
        $lib = LibrarySymbols::load();
        if (count($lib->preludeFiles()) === 0 || count($lib->stdlibFunctionNames()) === 0) {
            $dir = \Manticore\self_dir();
            fwrite(STDERR, "manticore lsp: the library stubs were not found.\n"
                . "  The language server reads them from MANTICORE_STUBS, then " . $dir . "/../lib/manticore.stubs.php,\n"
                . "  then the copy embedded in the binary (empty in a compiler built by a release without `stubs`:\n"
                . "  rebuild with bin/build). MANTICORE_PRELUDE and MANTICORE_STDLIB_SIG still override the sources.\n");
            return 1;
        }
        try {
            $ws = new Workspace($lib);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, 'manticore lsp: ' . $e->getMessage() . "\n");
            return 1;
        }
        $server = new Server($ws, function (string $json): void {
            Stdio::write(STDOUT, Stdio::encode($json));
        });
        $d = new Dispatcher();
        $server->register($d);
        (new Stdio(fn(): bool => $server->exitCode() !== null))->run($d);
        return $server->exitCode() ?? 1;
    }
}
