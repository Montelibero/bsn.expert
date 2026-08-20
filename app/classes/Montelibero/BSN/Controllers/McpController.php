<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use JsonException;
use Montelibero\BSN\Mcp\McpServer;
use Pecee\SimpleRouter\SimpleRouter;

final class McpController
{
    private const MAX_REQUEST_BYTES = 65536;

    public function __construct(private readonly McpServer $Server)
    {
    }

    public function Handle(): string
    {
        $this->responseHeaders();

        if (!$this->originAllowed()) {
            return $this->respond(403, McpServer::errorEnvelope(null, -32600, 'Forbidden Origin'));
        }

        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!is_string($content_type) || !str_starts_with(strtolower($content_type), 'application/json')) {
            return $this->respond(415, McpServer::errorEnvelope(null, -32600, 'Content-Type must be application/json'));
        }

        $raw_body = file_get_contents('php://input', false, null, 0, self::MAX_REQUEST_BYTES + 1);
        if ($raw_body === false) {
            return $this->respond(400, McpServer::errorEnvelope(null, -32700, 'Unable to read request body'));
        }
        if (strlen($raw_body) > self::MAX_REQUEST_BYTES) {
            return $this->respond(413, McpServer::errorEnvelope(null, -32600, 'MCP request is too large'));
        }
        try {
            $message = json_decode($raw_body, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->respond(400, McpServer::errorEnvelope(null, -32700, 'Parse error'));
        }

        $result = $this->Server->handle(
            $message,
            $this->requestHeader('MCP_PROTOCOL_VERSION'),
            $this->requestHeader('MCP_METHOD'),
            $this->requestHeader('MCP_NAME'),
        );
        SimpleRouter::response()->httpCode($result['status']);
        if ($result['body'] === null) {
            return '';
        }

        return $this->encode($result['body']);
    }

    public function MethodNotAllowed(): string
    {
        $this->responseHeaders();
        if (!$this->originAllowed()) {
            return $this->respond(403, McpServer::errorEnvelope(null, -32600, 'Forbidden Origin'));
        }
        SimpleRouter::response()->header('Allow: POST');

        return $this->respond(405, McpServer::errorEnvelope(null, -32600, 'Use POST for MCP requests'));
    }

    private function responseHeaders(): void
    {
        $Response = SimpleRouter::response();
        $Response->header('Content-Type: application/json; charset=utf-8');
        $Response->header('Cache-Control: no-store');
        $Response->header('X-Content-Type-Options: nosniff');
        $Response->header('Vary: Origin');
    }

    private function originAllowed(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin === null || $origin === '') {
            return true;
        }
        if (!is_string($origin) || $origin === 'null') {
            return false;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)
            || !is_string($parts['host'] ?? null)
            || !is_string($parts['scheme'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['path'])
        ) {
            return false;
        }
        $origin_hostname = strtolower($parts['host']);
        $origin_scheme = strtolower($parts['scheme']);
        if (!in_array($origin_scheme, ['http', 'https'], true)) {
            return false;
        }
        $origin_port = (int) ($parts['port'] ?? ($origin_scheme === 'https' ? 443 : 80));

        $request_host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $request_scheme = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO']
            ?? (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http')));
        $request_host_parts = parse_url('http://' . $request_host);
        $request_hostname = is_array($request_host_parts)
            ? strtolower((string) ($request_host_parts['host'] ?? ''))
            : '';
        $request_port = is_array($request_host_parts)
            ? (int) ($request_host_parts['port'] ?? ($request_scheme === 'https' ? 443 : 80))
            : 0;

        return in_array($request_hostname, ['bsn.expert', 'www.bsn.expert', 'localhost', '127.0.0.1', '::1'], true)
            && hash_equals($request_hostname, $origin_hostname)
            && $request_port === $origin_port
            && hash_equals($request_scheme, $origin_scheme);
    }

    private function requestHeader(string $name): ?string
    {
        $value = $_SERVER['HTTP_' . $name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $body */
    private function respond(int $status, array $body): string
    {
        SimpleRouter::response()->httpCode($status);

        return $this->encode($body);
    }

    /** @param array<string, mixed> $body */
    private function encode(array $body): string
    {
        return json_encode(
            $body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
