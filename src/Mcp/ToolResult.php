<?php

declare(strict_types=1);

namespace FiscozenApi\Mcp;

/** Explicit tool result: text and/or binary file content. */
class ToolResult
{
    private array $content = [];

    public static function text(string $text): self
    {
        $r = new self();
        $r->content[] = ['type' => 'text', 'text' => $text];
        return $r;
    }

    /** Attach a file as an embedded MCP resource (base64 blob). */
    public function file(string $uri, string $mimeType, string $bytes): self
    {
        $this->content[] = ['type' => 'resource', 'resource' => [
            'uri' => $uri, 'mimeType' => $mimeType, 'blob' => base64_encode($bytes),
        ]];
        return $this;
    }

    public function toArray(): array
    {
        return ['content' => $this->content];
    }
}
