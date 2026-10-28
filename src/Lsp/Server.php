<?php

namespace Lsp;

use Lsp\JsonRpc\Dispatcher;
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

    public function __construct(private Workspace $ws, callable $send)
    {
        $this->send = $send;
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
                'capabilities' => ['textDocumentSync' => ['openClose' => true, 'change' => 1]],
                'serverInfo' => ['name' => 'manticore', 'version' => \Manticore\Version::STRING],
            ];
        });
        $d->on('initialized', function (array $p): mixed { return null; });
        $d->on('shutdown', function (array $p): mixed {
            $this->shutdown = true;
            return null;
        });
        $d->on('exit', function (array $p): mixed {
            $this->exit = $this->shutdown ? 0 : 1;
            return null;
        });
        $d->on('$/cancelRequest', function (array $p): mixed { return null; });
        $d->on('textDocument/didOpen', function (array $p): mixed {
            $td = $p['textDocument'] ?? null;
            if (!is_array($td) || !is_string($td['uri'] ?? null) || !is_string($td['text'] ?? null)) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'didOpen: textDocument.uri and .text required');
            }
            $this->docs[$td['uri']] = $td['text'];
            $this->ws->open($td['uri'], $td['text']);
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
            $this->docs[$uri] = $text;
            $this->ws->update($uri, $text);
            return null;
        });
        $d->on('textDocument/didClose', function (array $p): mixed {
            $uri = $p['textDocument']['uri'] ?? null;
            if (is_string($uri) && isset($this->docs[$uri])) {
                unset($this->docs[$uri]);
                $this->ws->close($uri);
            }
            return null;
        });
    }
}
