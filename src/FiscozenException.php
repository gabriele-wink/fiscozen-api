<?php

declare(strict_types=1);

namespace FiscozenApi;

class FiscozenException extends \RuntimeException
{
    public function __construct(string $message, public readonly array $payload = [])
    {
        parent::__construct($message);
    }
}
