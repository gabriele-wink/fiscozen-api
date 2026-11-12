<?php

declare(strict_types=1);

namespace FiscozenApi\Mcp;

class McpError extends \RuntimeException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
