<?php

namespace Lsp\Handlers;

use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;
use Lsp\Position;
use Semantic\DocumentSymbol;
use Semantic\Location;
use Semantic\Workspace;

/** textDocument/documentSymbol (hierarchical). A trait is reported as Class: LSP has no trait kind. */
final class SymbolHandlers
{
    private const KIND = [
        'class' => 5, 'trait' => 5, 'method' => 6, 'property' => 7, 'enum' => 10, 'interface' => 11,
        'function' => 12, 'constant' => 14, 'case' => 22,
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
        $d->on('textDocument/documentSymbol', function (array $p): mixed {
            $uri = $p['textDocument']['uri'] ?? null;
            $uri = is_string($uri) ? \Lsp\Uri::normalize($uri) : null;
            if (!is_string($uri)) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'textDocument.uri required');
            }
            /** @var array<string, string> $open */
            $open = ($this->docs)();
            if (!isset($open[$uri])) { return null; }
            return array_map(fn(DocumentSymbol $s): array => $this->convert($s, $open[$uri]), $this->ws->documentSymbols($uri));
        });
    }

    /** @return array<string, mixed> */
    private function convert(DocumentSymbol $s, string $text): array
    {
        return [
            'name' => $s->name,
            'kind' => self::KIND[$s->kind],
            'range' => self::range($s->range, $text),
            'selectionRange' => self::range($s->nameRange, $text),
            'children' => array_map(fn(DocumentSymbol $c): array => $this->convert($c, $text), $s->children),
        ];
    }

    /** @return array<string, mixed> */
    private static function range(Location $l, string $text): array
    {
        $a = Position::lineText($text, $l->line);
        $b = $l->endLine === $l->line ? $a : Position::lineText($text, $l->endLine);
        return [
            'start' => ['line' => $l->line - 1, 'character' => Position::toLsp($a, $l->col)],
            'end' => ['line' => $l->endLine - 1, 'character' => Position::toLsp($b, $l->endCol)],
        ];
    }
}
