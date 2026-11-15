<?php

namespace Lsp\JsonRpc;

class RpcError extends \Exception
{
    public const PARSE = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL = -32603;
    public const NOT_INITIALIZED = -32002;
    public const CANCELLED = -32800;

    public function __construct(public readonly int $rpcCode, string $message)
    {
        parent::__construct($message);
    }
}
