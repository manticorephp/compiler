<?php

namespace Lsp\JsonRpc;

class Dispatcher
{
    /** @var array<string, callable> */
    private array $handlers = [];

    public function on(string $method, callable $h): void
    {
        $this->handlers[$method] = $h;
    }

    public function handle(string $json): ?string
    {
        try {
            $req = Request::parse($json);
        } catch (RpcError $e) {
            return self::error(null, $e->rpcCode, $e->getMessage());
        }
        $h = $this->handlers[$req->method] ?? null;
        if ($h === null) {
            return $req->isNotification() ? null : self::error($req->rawId, RpcError::METHOD_NOT_FOUND, 'Method not found: ' . $req->method);
        }
        try {
            $result = $h($req->params);
        } catch (RpcError $e) {
            return $req->isNotification() ? null : self::error($req->rawId, $e->rpcCode, $e->getMessage());
        } catch (\Throwable $e) {
            return $req->isNotification() ? null : self::error($req->rawId, RpcError::INTERNAL, $e->getMessage());
        }
        if ($req->isNotification()) {
            return null;
        }
        $out = json_encode(['jsonrpc' => '2.0', 'id' => $req->rawId, 'result' => $result]);
        if ($out === false) {
            return self::error($req->rawId, RpcError::INTERNAL, 'Result is not JSON-encodable');
        }
        return $out;
    }

    private static function error(mixed $id, int $code, string $message): string
    {
        $out = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($out === false) {
            $out = json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => 'Error']]);
        }
        return $out === false ? '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Error"}}' : $out;
    }
}
