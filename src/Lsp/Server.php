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
 * LSP lifecycle, incremental text sync and watched-file events. Single-threaded: `$/cancelRequest` is honoured
 * between files of a closed-file pass (Cancellation::check) and for requests still queued, not inside one parse.
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
    private bool $annotations = false;
    private bool $watchDynamic = false;
    /** @var array<string, int> uri => version of an open document */
    private array $versions = [];
    /** @var array<string, string> normalised uri => the spelling the client opened it with (echoed back in publishDiagnostics) */
    private array $clientUri = [];

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
            $we = $p['capabilities']['workspace']['workspaceEdit'] ?? null;
            $this->annotations = is_array($we) && ($we['documentChanges'] ?? false) === true && is_array($we['changeAnnotationSupport'] ?? null);
            $this->watchDynamic = ($p['capabilities']['workspace']['didChangeWatchedFiles']['dynamicRegistration'] ?? false) === true;
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
                    'textDocumentSync' => ['openClose' => true, 'change' => 2],
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
            if ($this->watchDynamic) {
                ($this->send)(json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 'mc-watch-php',
                    'method' => 'client/registerCapability',
                    'params' => ['registrations' => [[
                        'id' => 'mc-watch-php',
                        'method' => 'workspace/didChangeWatchedFiles',
                        'registerOptions' => ['watchers' => [['globPattern' => '**/*.php']]],
                    ]]],
                ]));
            }
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
        (new RenameHandlers($this->ws, fn(): array => $this->docs, $this->closed, $this->send, fn(): bool => $this->annotations, fn(): array => $this->versions))->register($d);
        $this->closed->setCheck(function () use ($d): void { $d->cancellation()->check(); });
        $d->on('$/cancelRequest', function (array $p): mixed { return null; });
        $d->on('workspace/didChangeWatchedFiles', function (array $p): mixed {
            $changes = $p['changes'] ?? null;
            if (!is_array($changes)) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'didChangeWatchedFiles: changes required');
            }
            $touched = false;
            foreach ($changes as $c) {
                if (!is_array($c) || !is_string($c['uri'] ?? null) || !is_int($c['type'] ?? null)) { continue; }
                $uri = Uri::normalize($c['uri']);
                $path = Uri::toPath($uri);
                if ($path === null) { continue; }
                if ($c['type'] === 3) {
                    if (!isset($this->docs[$uri])) { $this->closed->forget($uri); }
                    $this->closed->forgetUnder($uri . '/');
                    $touched = true;
                } elseif (@is_dir($path)) {
                    foreach (WorkspaceScan::phpFiles($path) as $f) {
                        $u = Uri::fromPath($f);
                        if (!isset($this->docs[$u])) { $this->closed->reindex($u); $touched = true; }
                    }
                } elseif (str_ends_with($path, '.php') && !isset($this->docs[$uri])) {
                    $this->closed->reindex($uri);
                    $touched = true;
                }
            }
            if ($touched) { $this->republishAll(); }
            return null;
        });
        $d->on('textDocument/didOpen', function (array $p): mixed {
            $td = $p['textDocument'] ?? null;
            if (!is_array($td) || !is_string($td['uri'] ?? null) || !is_string($td['text'] ?? null)) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'didOpen: textDocument.uri and .text required');
            }
            $uri = Uri::normalize($td['uri']);
            $this->clientUri[$uri] = $td['uri'];
            $this->ws->open($uri, $td['text']);
            $this->docs[$uri] = $td['text'];
            if (is_int($td['version'] ?? null)) { $this->versions[$uri] = $td['version']; } else { unset($this->versions[$uri]); }
            $this->republish($uri);
            return null;
        });
        $d->on('textDocument/didChange', function (array $p): mixed {
            $uri = $p['textDocument']['uri'] ?? null;
            $uri = is_string($uri) ? Uri::normalize($uri) : null;
            $ch = $p['contentChanges'] ?? null;
            if (!is_string($uri) || !is_array($ch) || $ch === []) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'didChange: uri and contentChanges required');
            }
            if (!isset($this->docs[$uri])) {
                return null;
            }
            $text = $this->docs[$uri];
            foreach ($ch as $c) {
                $new = is_array($c) ? ($c['text'] ?? null) : null;
                if (!is_string($new)) {
                    throw new RpcError(RpcError::INVALID_PARAMS, 'didChange: text required in every change');
                }
                $r = $c['range'] ?? null;
                if ($r === null) { $text = $new; continue; }
                $s = is_array($r) ? ($r['start'] ?? null) : null;
                $e = is_array($r) ? ($r['end'] ?? null) : null;
                if (!is_array($s) || !is_array($e) || !is_int($s['line'] ?? null) || !is_int($s['character'] ?? null) || !is_int($e['line'] ?? null) || !is_int($e['character'] ?? null)) {
                    throw new RpcError(RpcError::INVALID_PARAMS, 'didChange: bad range');
                }
                $text = Position::applyChange($text, $s['line'], $s['character'], $e['line'], $e['character'], $new);
            }
            $this->ws->update($uri, $text);
            $this->docs[$uri] = $text;
            $v = $p['textDocument']['version'] ?? null;
            if (is_int($v)) { $this->versions[$uri] = $v; } else { unset($this->versions[$uri]); }
            $this->republish($uri);
            return null;
        });
        $d->on('textDocument/didClose', function (array $p): mixed {
            $uri = $p['textDocument']['uri'] ?? null;
            $uri = is_string($uri) ? Uri::normalize($uri) : null;
            if ($uri !== null && isset($this->docs[$uri])) {
                unset($this->docs[$uri], $this->versions[$uri]);
                $this->closed->reindex($uri);
                unset($this->published[$uri]);
                $this->push($uri, []);
                unset($this->clientUri[$uri]);
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
                if (!@is_file($path)) { continue; }
                $this->closed->reindex($uri);
            }
        }
    }

    private function republishAll(): void
    {
        foreach ($this->docs as $other => $_) {
            try {
                $this->publish($other, false);
            } catch (\Throwable $e) {
                continue;
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
            'params' => ['uri' => $this->clientUri[$uri] ?? $uri, 'diagnostics' => $items],
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($msg !== false) { ($this->send)($msg); }
    }
}
