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
        try {
            $ws = new Workspace(LibrarySymbols::load());
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
