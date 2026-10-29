<?php

namespace Lsp\Handlers;

use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;
use Lsp\Position;
use Semantic\Completer;
use Semantic\Workspace;

/** textDocument/completion. */
final class CompletionHandlers
{
    private const KINDS = [
        'method' => 2, 'function' => 3, 'field' => 5, 'variable' => 6, 'class' => 7, 'interface' => 8,
        'enum' => 13, 'enumMember' => 20, 'constant' => 21,
    ];

    /** @var callable */
    private $docs;

    /** @param callable $docs returns array<string, string> uri => open text */
    public function __construct(private Workspace $ws, callable $docs)
    {
        $this->docs = $docs;
    }

    public function register(Dispatcher $d): void
    {
        $d->on('textDocument/completion', function (array $p): mixed {
            $uri = $p['textDocument']['uri'] ?? null;
            $uri = is_string($uri) ? \Lsp\Uri::normalize($uri) : null;
            $pos = $p['position'] ?? null;
            if (!is_string($uri) || !is_array($pos) || !is_int($pos['line'] ?? null) || !is_int($pos['character'] ?? null)
                || $pos['line'] < 0 || $pos['character'] < 0) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'textDocument.uri and a non-negative position required');
            }
            /** @var array<string, string> $open */
            $open = ($this->docs)();
            $text = $open[$uri] ?? null;
            if ($text === null) { return null; }
            $line = $pos['line'] + 1;
            $items = [];
            $found = $this->ws->completionsAt($uri, $line, Position::toCore(Position::lineText($text, $line), $pos['character']));
            foreach ($found as $c) {
                $items[] = ['label' => $c->label, 'kind' => self::KINDS[$c->kind] ?? 1, 'detail' => $c->detail, 'insertText' => $c->insertText,
                    'sortText' => '0' . $c->rank . '_' . strtolower($c->label)];
            }
            return ['isIncomplete' => count($found) >= Completer::CAP, 'items' => $items];
        });
    }
}
