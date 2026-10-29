<?php

namespace Lsp;

use Lsp\JsonRpc\Dispatcher;
use Lsp\Handlers\CompletionHandlers;
use Lsp\Handlers\NavigationHandlers;
use Lsp\Handlers\RenameHandlers;
use Lsp\Handlers\SymbolHandlers;
use Lsp\JsonRpc\RpcError;
use Semantic\Workspace;

/**
 * LSP lifecycle and full text sync. Synchronous: `$/cancelRequest` is accepted and ignored
 * (a request is answered before the next message is read; ceiling of the single-threaded design).
 */
final class Server
{
    private bool $initialized = false;
    private bool $shutdown = false;
    private ?int $exit = null;
    private string $rootUri = '';
    /** @var string[] */
    private array $folders = [];
    /** @var array<string, string> uri => current text */
    private array $docs = [];
    /** @var callable */
    private $send;
    /** @var array<string, string> uri => last published diagnostics JSON */
    private array $published = [];
    private ClosedFiles $closed;
    private const MAX_SCAN_BYTES = 2097152;

    public function __construct(private Workspace $ws, callable $send, int $closedMax = ClosedFiles::MAX)
    {
        $this->send = $send;
        $this->closed = new ClosedFiles($ws, fn(): array => $this->docs, $closedMax);
    }

    public function exitCode(): ?int
    {
        return $this->exit;
    }

    public function register(Dispatcher $d): void
    {
        $d->guard(function (string $method): void {
            if ($method === 'exit' || $method === 'initialize' || $method === 'initialized') {
                return;
            }
            if ($this->shutdown) {
                throw new RpcError(RpcError::INVALID_REQUEST, 'Server is shut down');
            }
            if (!$this->initialized && $method !== '$/cancelRequest') {
                throw new RpcError(RpcError::NOT_INITIALIZED, 'Server not initialized');
            }
        });
        $d->on('initialize', function (array $p): mixed {
            if ($this->initialized) {
                throw new RpcError(RpcError::INVALID_REQUEST, 'Already initialized');
            }
            $this->initialized = true;
            $root = $p['rootUri'] ?? '';
            $this->rootUri = is_string($root) ? $root : '';
            $this->folders = [];
            $wf = $p['workspaceFolders'] ?? [];
            if (is_array($wf)) {
                foreach ($wf as $f) {
                    if (is_array($f) && is_string($f['uri'] ?? null)) {
                        $this->folders[] = $f['uri'];
                    }
                }
            }
            return [
                'capabilities' => [
                    'textDocumentSync' => ['openClose' => true, 'change' => 1],
                    'hoverProvider' => true,
                    'definitionProvider' => true,
                    'referencesProvider' => true,
                    'documentSymbolProvider' => true,
                    'renameProvider' => ['prepareProvider' => true],
                    'completionProvider' => ['triggerCharacters' => ['$', '>', ':', '\\']],
                ],
                'serverInfo' => ['name' => 'manticore', 'version' => \Manticore\Version::STRING],
            ];
        });
        $d->on('initialized', function (array $p): mixed {
            $this->scan();
            return null;
        });
        $d->on('shutdown', function (array $p): mixed {
            $this->shutdown = true;
            return null;
        });
        $d->on('exit', function (array $p): mixed {
            $this->exit = $this->shutdown ? 0 : 1;
            return null;
        });
        (new NavigationHandlers($this->ws, fn(): array => $this->docs, $this->closed))->register($d);
        (new CompletionHandlers($this->ws, fn(): array => $this->docs))->register($d);
        (new SymbolHandlers($this->ws, fn(): array => $this->docs))->register($d);
        (new RenameHandlers($this->ws, fn(): array => $this->docs, $this->closed, $this->send))->register($d);
        $d->on('$/cancelRequest', function (array $p): mixed { return null; });
        $d->on('textDocument/didOpen', function (array $p): mixed {
            $td = $p['textDocument'] ?? null;
            if (!is_array($td) || !is_string($td['uri'] ?? null) || !is_string($td['text'] ?? null)) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'didOpen: textDocument.uri and .text required');
            }
            $this->ws->open($td['uri'], $td['text']);
            $this->docs[$td['uri']] = $td['text'];
            $this->republish($td['uri']);
            return null;
        });
        $d->on('textDocument/didChange', function (array $p): mixed {
            $uri = $p['textDocument']['uri'] ?? null;
            $ch = $p['contentChanges'] ?? null;
            if (!is_string($uri) || !is_array($ch) || $ch === []) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'didChange: uri and contentChanges required');
            }
            if (!isset($this->docs[$uri])) {
                return null;
            }
            $text = $ch[count($ch) - 1]['text'] ?? null;
            if (!is_string($text)) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'didChange: full-text change required');
            }
            $this->ws->update($uri, $text);
            $this->docs[$uri] = $text;
            $this->republish($uri);
            return null;
        });
        $d->on('textDocument/didClose', function (array $p): mixed {
            $uri = $p['textDocument']['uri'] ?? null;
            if (is_string($uri) && isset($this->docs[$uri])) {
                unset($this->docs[$uri]);
                $this->closed->add($uri);
                $this->ws->close($uri);
                unset($this->published[$uri]);
                $this->push($uri, []);
            }
            return null;
        });
    }

    /** Index every PHP file under the root (and workspace folders) that is not open: parse, keep the index entries, drop the AST. */
    private function scan(): void
    {
        $roots = $this->folders;
        if ($this->rootUri !== '') { $roots[] = $this->rootUri; }
        $seen = [];
        foreach ($roots as $root) {
            $dir = Uri::toPath($root);
            if ($dir === null) { continue; }
            foreach (WorkspaceScan::phpFiles($dir) as $path) {
                $uri = Uri::fromPath($path);
                if (isset($seen[$uri]) || isset($this->docs[$uri])) { continue; }
                $seen[$uri] = true;
                try {
                    $size = @filesize($path);
                    if ($size === false || $size > self::MAX_SCAN_BYTES) { continue; }
                    $text = @file_get_contents($path);
                    if ($text === false) { continue; }
                    $this->ws->open($uri, $text);
                    $this->ws->close($uri);
                    $this->closed->add($uri);
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }
    }

    /** Publish $uri, then every other open doc whose diagnostics changed (a declaration here can fix or break them). */
    private function republish(string $uri): void
    {
        $this->publish($uri, true);
        foreach ($this->docs as $other => $_) {
            if ($other === $uri) { continue; }
            try {
                $this->publish($other, false);
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    private function publish(string $uri, bool $force): void
    {
        $text = $this->docs[$uri] ?? '';
        $items = [];
        foreach ($this->ws->diagnostics($uri) as $d) {
            $sl = $d->startLine === 0 ? $d->line : $d->startLine;
            $el = $d->startLine === 0 ? $d->line : $d->endLine;
            $items[] = DiagnosticMap::toLsp($d, Position::lineText($text, $sl), Position::lineText($text, $el));
        }
        $json = json_encode($items, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) { $json = '[]'; }
        if (!$force && ($this->published[$uri] ?? null) === $json) { return; }
        $this->published[$uri] = $json;
        $this->push($uri, $items);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function push(string $uri, array $items): void
    {
        $msg = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/publishDiagnostics',
            'params' => ['uri' => $uri, 'diagnostics' => $items],
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($msg !== false) { ($this->send)($msg); }
    }
}
