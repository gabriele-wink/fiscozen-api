#!/usr/bin/env php
<?php
// Fiscozen MCP server, stdio transport (Claude Desktop, Claude Code, Codex CLI, Cursor...).
// Reads newline-delimited JSON-RPC from stdin, writes responses to stdout. Logs go to stderr only.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FiscozenApi\Mcp\FiscozenTools;

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');

$server = FiscozenTools::server(dirname(__DIR__));

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $msg = json_decode($line, true);
    $response = is_array($msg)
        ? $server->handle($msg)
        : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']];
    if ($response !== null) {
        fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    }
}
