<?php

declare(strict_types=1);

namespace FiscozenApi\Mcp;

/**
 * Minimal, dependency-free Model Context Protocol server (JSON-RPC 2.0).
 *
 * Implements: initialize, ping, tools/list, tools/call (+ notifications).
 * Transport-agnostic: feed decoded JSON-RPC messages to handle() and send back what it returns.
 */
class McpServer
{
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** @var array<string, array{def: array, handler: callable}> */
    private array $tools = [];

    public function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly string $instructions = '',
    ) {
    }

    /**
     * @param array $inputSchema JSON Schema (type: object)
     * @param array $annotations MCP tool annotations (readOnlyHint, destructiveHint, idempotentHint, openWorldHint)
     */
    public function tool(string $name, string $description, array $inputSchema, callable $handler,
                         array $annotations = []): void
    {
        $def = ['name' => $name, 'description' => $description, 'inputSchema' => $inputSchema + ['type' => 'object']];
        if (!isset($def['inputSchema']['properties'])) {
            $def['inputSchema']['properties'] = new \stdClass();
        }
        if ($annotations) {
            $def['annotations'] = $annotations;
        }
        $this->tools[$name] = ['def' => $def, 'handler' => $handler];
    }

    /** Handle one message or a batch. Returns the response(s), or null for notifications. */
    public function handle(array $message): ?array
    {
        if (array_is_list($message)) {
            $out = array_values(array_filter(array_map(fn($m) => is_array($m) ? $this->handleOne($m) : null, $message)));
            return $out ?: null;
        }
        return $this->handleOne($message);
    }

    private function handleOne(array $msg): ?array
    {
        $id = $msg['id'] ?? null;
        $method = $msg['method'] ?? null;
        $isNotification = !array_key_exists('id', $msg);

        if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
            return $isNotification ? null : self::error($id, -32600, 'Invalid Request');
        }
        try {
            $result = match ($method) {
                'initialize' => $this->initialize($msg['params'] ?? []),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => array_values(array_column($this->tools, 'def'))],
                'tools/call' => $this->call($msg['params'] ?? []),
                'resources/list' => ['resources' => []],
                'prompts/list' => ['prompts' => []],
                default => str_starts_with($method, 'notifications/') ? null : throw new McpError(-32601, "Method not found: $method"),
            };
        } catch (McpError $e) {
            return $isNotification ? null : self::error($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            return $isNotification ? null : self::error($id, -32603, 'Internal error: ' . $e->getMessage());
        }
        if ($isNotification) {
            return null;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? self::SUPPORTED_VERSIONS[0];
        return [
            'protocolVersion' => in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => $this->name, 'version' => $this->version],
            'instructions' => $this->instructions,
        ];
    }

    private function call(array $params): array
    {
        $name = $params['name'] ?? '';
        if (!isset($this->tools[$name])) {
            throw new McpError(-32602, "Unknown tool: $name");
        }
        $args = $params['arguments'] ?? [];
        try {
            $result = ($this->tools[$name]['handler'])(is_array($args) ? $args : []);
        } catch (\Throwable $e) {
            // Tool errors are reported in-band so the model can read and react to them.
            $detail = method_exists($e, 'getPayload') ? $e->getPayload() : (property_exists($e, 'payload') ? $e->payload : null);
            $text = $e->getMessage() . ($detail ? "\n" . json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
            return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
        }
        return self::toResult($result);
    }

    /** Tool handlers may return a ToolResult, a string, or any JSON-serialisable value. */
    private static function toResult(mixed $result): array
    {
        if ($result instanceof ToolResult) {
            return $result->toArray();
        }
        if (is_string($result)) {
            return ['content' => [['type' => 'text', 'text' => $result]]];
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $out = ['content' => [['type' => 'text', 'text' => $json]]];
        if (is_array($result) && !array_is_list($result)) {
            $out['structuredContent'] = $result;
        } elseif (is_array($result)) {
            $out['structuredContent'] = ['items' => $result];
        }
        return $out;
    }

    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
