<?php

namespace Lsp\JsonRpc;

class Dispatcher
{
    /** @var array<string, callable> */
    private array $handlers = [];

    /** @var callable|null */
    private $guard = null;
    private Cancellation $cancel;

    public function __construct()
    {
        $this->cancel = new Cancellation();
    }

    public function cancellation(): Cancellation
    {
        return $this->cancel;
    }

    /** Runs before every lookup with the method name; throws RpcError to refuse (lifecycle state checks). */
    public function guard(callable $g): void
    {
        $this->guard = $g;
    }

    public function on(string $method, callable $h): void
    {
        $this->handlers[$method] = $h;
    }

    public function handle(string $json): ?string
    {
        try {
            $req = Request::parse($json);
        } catch (RpcError $e) {
            if ($e->rpcCode === RpcError::INVALID_REQUEST && self::isResponse($json)) {
                return null;
            }
            return self::error(null, $e->rpcCode, $e->getMessage());
        }
        if ($this->guard !== null) {
            try {
                ($this->guard)($req->method);
            } catch (RpcError $e) {
                return $req->isNotification() ? null : self::error($req->rawId, $e->rpcCode, $e->getMessage());
            } catch (\Throwable $e) {
                return $req->isNotification() ? null : self::error($req->rawId, RpcError::INTERNAL, $e->getMessage());
            }
        }
        $h = $this->handlers[$req->method] ?? null;
        if ($h === null) {
            return $req->isNotification() ? null : self::error($req->rawId, RpcError::METHOD_NOT_FOUND, 'Method not found: ' . $req->method);
        }
        if ($req->id !== null && $this->cancel->isCancelled($req->id)) {
            $this->cancel->begin($req->id);
            $this->cancel->end();
            return self::error($req->rawId, RpcError::CANCELLED, 'Request cancelled');
        }
        if ($req->id !== null) { $this->cancel->begin($req->id); }
        try {
            $result = $h($req->params);
        } catch (RpcError $e) {
            return $req->isNotification() ? null : self::error($req->rawId, $e->rpcCode, $e->getMessage());
        } catch (\Throwable $e) {
            return $req->isNotification() ? null : self::error($req->rawId, RpcError::INTERNAL, $e->getMessage());
        } finally {
            $this->cancel->end();
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

    /** A reply from the client to a server-to-client request: nothing to answer. */
    private static function isResponse(string $json): bool
    {
        $j = json_decode($json, true);
        return is_array($j) && !isset($j['method']) && isset($j['id']) && (array_key_exists('result', $j) || isset($j['error']));
    }

    public static function error(mixed $id, int $code, string $message): string
    {
        $out = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($out === false) {
            $out = json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => 'Error']]);
        }
        return $out === false ? '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Error"}}' : $out;
    }
}
