<?php
// Fiscozen MCP server, Streamable HTTP transport (ChatGPT connectors, OpenAI Responses API,
// Claude API MCP connector, any remote MCP client).
//
//   FISCOZEN_MCP_TOKEN=<secret> php -S 127.0.0.1:8787 public/mcp.php
//   -> endpoint http://127.0.0.1:8787/mcp, header "Authorization: Bearer <secret>"
//
// Requests without the right bearer token are rejected. Expose it publicly only behind HTTPS.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FiscozenApi\Mcp\FiscozenTools;

$send = function (int $status, ?array $body = null, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    if ($body !== null) {
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
};

$token = getenv('FISCOZEN_MCP_TOKEN') ?: '';
if ($token === '') {
    $send(500, ['error' => 'FISCOZEN_MCP_TOKEN is not set: refusing to serve without authentication']);
}
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
// Connectors added from the ChatGPT / claude.ai UI cannot send custom headers (OAuth or no auth only).
// Opt-in fallback: token in the URL (?key=...). URLs end up in logs: prefer the header when possible.
if ($auth === '' && getenv('FISCOZEN_MCP_ALLOW_QUERY_TOKEN') === '1' && isset($_GET['key'])) {
    $auth = 'Bearer ' . $_GET['key'];
}
if (!hash_equals("Bearer $token", $auth)) {
    $send(401, ['error' => 'unauthorized'], ['WWW-Authenticate' => 'Bearer']);
}

// DNS-rebinding protection: browsers send Origin, MCP clients usually don't.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = array_filter(explode(',', getenv('FISCOZEN_MCP_ALLOWED_ORIGINS') ?: 'https://chatgpt.com,https://chat.openai.com,https://claude.ai'));
if ($origin !== '' && !in_array($origin, $allowed, true)) {
    $send(403, ['error' => 'origin not allowed']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' || $method === 'DELETE') {
    // No server-initiated SSE stream and no server-side session state.
    $send(405, null, ['Allow' => 'POST']);
}
if ($method !== 'POST') {
    $send(405, null, ['Allow' => 'POST']);
}

$msg = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($msg)) {
    $send(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
}

$response = FiscozenTools::server(dirname(__DIR__))->handle($msg);
if ($response === null) {
    $send(202);
}
$isInit = !array_is_list($msg) && ($msg['method'] ?? '') === 'initialize';
$send(200, $response, $isInit ? ['Mcp-Session-Id' => bin2hex(random_bytes(16))] : []);
