<?php

namespace App\Services\Mcp;

/** A JSON-RPC error: the exception code is the JSON-RPC error code (-32700 … -32603). */
final class McpError extends \RuntimeException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
