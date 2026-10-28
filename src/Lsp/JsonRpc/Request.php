<?php

namespace Lsp\JsonRpc;

class Request
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $method,
        public readonly array $params,
        public readonly mixed $rawId = null,
    ) {
    }

    public static function parse(string $json): Request
    {
        $j = json_decode($json, true);
        if ($j === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RpcError(RpcError::PARSE, 'Parse error');
        }
        if (!is_array($j) || !isset($j['method']) || !is_string($j['method'])) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'Invalid Request');
        }
        $params = $j['params'] ?? [];
        if (!is_array($params)) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'Invalid Request');
        }
        $raw = $j['id'] ?? null;
        if ($raw !== null && !is_int($raw) && !is_float($raw) && !is_string($raw)) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'Invalid Request');
        }
        $id = $raw === null ? null : (string)$raw;
        return new Request($id, $j['method'], $params, $raw);
    }

    public function isNotification(): bool
    {
        return $this->id === null;
    }
}
