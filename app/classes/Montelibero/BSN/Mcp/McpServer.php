<?php

declare(strict_types=1);

namespace Montelibero\BSN\Mcp;

use InvalidArgumentException;
use Throwable;

final class McpServer
{
    public const MODERN_VERSION = '2026-07-28';
    public const LATEST_LEGACY_VERSION = '2025-11-25';
    public const SERVER_NAME = 'bsn-expert';
    public const SERVER_VERSION = '0.1.0';

    private const LEGACY_VERSIONS = [
        self::LATEST_LEGACY_VERSION,
        '2025-06-18',
        '2025-03-26',
    ];

    public function __construct(private readonly McpBsnTools $Tools)
    {
    }

    /** @return array{status: int, body: array<string, mixed>|null} */
    public function handle(
        mixed $message,
        ?string $protocol_version = null,
        ?string $method_header = null,
        ?string $name_header = null,
    ): array {
        if (!is_array($message) || array_is_list($message)) {
            return $this->failure(null, -32600, 'Invalid Request', 400);
        }

        $id = $message['id'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            return $this->failure($id, -32600, 'Invalid Request', 400);
        }

        $method = $message['method'];
        if (!array_key_exists('id', $message)) {
            return ['status' => 202, 'body' => null];
        }
        if (!is_int($id) && !is_string($id)) {
            return $this->failure(null, -32600, 'Invalid Request', 400);
        }
        $message_params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $message_meta = is_array($message_params['_meta'] ?? null) ? $message_params['_meta'] : [];
        $body_protocol_version = $message_meta['io.modelcontextprotocol/protocolVersion'] ?? null;
        if (is_string($body_protocol_version) && $body_protocol_version !== $protocol_version) {
            return $this->failure(
                $id,
                -32020,
                'Header mismatch: MCP-Protocol-Version must match request _meta',
                400,
            );
        }
        if ($protocol_version !== null && !$this->supports($protocol_version)) {
            return $this->failure($id, -32022, 'Unsupported protocol version', 400, [
                'requested' => $protocol_version,
                'supported' => self::supportedVersions(),
            ]);
        }

        $modern = $protocol_version === self::MODERN_VERSION;
        if ($modern) {
            $modern_error = $this->validateModernRequest($message, $method_header, $name_header);
            if ($modern_error !== null) {
                return $this->failure($id, $modern_error['code'], $modern_error['message'], 400);
            }
        }

        $params = $message['params'] ?? [];
        if (!is_array($params)) {
            return $this->failure($id, -32602, 'Invalid params');
        }

        return match ($method) {
            'server/discover' => $modern
                ? $this->success($id, $this->discover(), true)
                : $this->failure($id, -32601, 'Method not found', 404),
            'initialize' => $modern
                ? $this->failure($id, -32601, 'Method not found', 404)
                : $this->initialize($id, $params),
            'ping' => $this->success($id, [], $modern),
            'tools/list' => $this->success($id, $this->listTools($modern), $modern),
            'tools/call' => $this->callTool($id, $params, $modern),
            default => $this->failure($id, -32601, 'Method not found', $modern ? 404 : 200),
        };
    }

    /** @return array<string, mixed> */
    public static function errorEnvelope(int|string|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /** @param array<string, mixed> $params */
    private function initialize(int|string|null $id, array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        if (!is_string($requested)) {
            return $this->failure($id, -32602, 'Missing protocolVersion');
        }
        if ($requested === self::MODERN_VERSION) {
            return $this->failure($id, -32022, 'Use server/discover for protocol version 2026-07-28', 400, [
                'requested' => $requested,
                'supported' => self::LEGACY_VERSIONS,
            ]);
        }
        $negotiated = in_array($requested, self::LEGACY_VERSIONS, true)
            ? $requested
            : self::LATEST_LEGACY_VERSION;

        return $this->success($id, [
            'protocolVersion' => $negotiated,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => self::serverInfo(),
            'instructions' => self::instructions(),
        ], false);
    }

    /** @return array<string, mixed> */
    private function discover(): array
    {
        return [
            'resultType' => 'complete',
            'supportedVersions' => self::supportedVersions(),
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'instructions' => self::instructions(),
            'ttlMs' => 86400000,
            'cacheScope' => 'public',
        ];
    }

    /** @return array<string, mixed> */
    private function listTools(bool $modern): array
    {
        $result = ['tools' => $this->Tools->definitions()];
        if ($modern) {
            $result = [
                'resultType' => 'complete',
                'ttlMs' => 86400000,
                'cacheScope' => 'public',
            ] + $result;
        }

        return $result;
    }

    /** @param array<string, mixed> $params */
    private function callTool(int|string|null $id, array $params, bool $modern): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || !is_array($arguments)) {
            return $this->failure($id, -32602, 'tools/call requires a string name and object arguments');
        }
        if (!in_array($name, [McpBsnTools::ACCOUNT_GET, McpBsnTools::ACCOUNT_TAGS, McpBsnTools::TAGS_LIST], true)) {
            return $this->failure($id, -32602, sprintf('Unknown tool: %s', $name));
        }

        try {
            $structured = $this->Tools->call($name, $arguments);
            $text = json_encode(
                $structured,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            );
            $result = [
                'content' => [[
                    'type' => 'text',
                    'text' => $text,
                ]],
                'structuredContent' => $structured,
                'isError' => false,
            ];
        } catch (InvalidArgumentException $Exception) {
            $result = [
                'content' => [[
                    'type' => 'text',
                    'text' => $Exception->getMessage(),
                ]],
                'structuredContent' => ['error' => $Exception->getMessage()],
                'isError' => true,
            ];
        } catch (Throwable $Throwable) {
            error_log(sprintf('MCP tool %s failed: %s', $name, $Throwable->getMessage()));
            $result = [
                'content' => [[
                    'type' => 'text',
                    'text' => 'The BSN tool failed to read its data.',
                ]],
                'structuredContent' => ['error' => 'internal_error'],
                'isError' => true,
            ];
        }
        if ($modern) {
            $result = ['resultType' => 'complete'] + $result;
        }

        return $this->success($id, $result, $modern);
    }

    /**
     * @param array<string, mixed> $message
     * @return array{code: int, message: string}|null
     */
    private function validateModernRequest(array $message, ?string $method_header, ?string $name_header): ?array
    {
        $method = (string) $message['method'];
        if ($method_header === null || $method_header !== $method) {
            return [
                'code' => -32020,
                'message' => 'Header mismatch: Mcp-Method must match the JSON-RPC method',
            ];
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $meta = $params['_meta'] ?? null;
        if (!is_array($meta)
            || !is_string($meta['io.modelcontextprotocol/protocolVersion'] ?? null)
            || !is_array($meta['io.modelcontextprotocol/clientCapabilities'] ?? null)
        ) {
            return [
                'code' => -32602,
                'message' => 'Invalid params: modern requests require protocolVersion and clientCapabilities in _meta',
            ];
        }
        if ($meta['io.modelcontextprotocol/protocolVersion'] !== self::MODERN_VERSION) {
            return [
                'code' => -32020,
                'message' => 'Header mismatch: Mcp-Protocol-Version must match request _meta',
            ];
        }

        if ($method === 'tools/call') {
            $body_name = $params['name'] ?? null;
            if (!is_string($body_name) || $name_header === null || $name_header !== $body_name) {
                return [
                    'code' => -32020,
                    'message' => 'Header mismatch: Mcp-Name must match the tool name',
                ];
            }
        } elseif ($name_header !== null) {
            return [
                'code' => -32020,
                'message' => 'Header mismatch: Mcp-Name is not valid for this method',
            ];
        }

        return null;
    }

    /** @param array<string, mixed> $result @return array{status: int, body: array<string, mixed>} */
    private function success(int|string|null $id, array $result, bool $modern): array
    {
        if ($modern) {
            $result['resultType'] ??= 'complete';
            $meta = is_array($result['_meta'] ?? null) ? $result['_meta'] : [];
            $meta['io.modelcontextprotocol/serverInfo'] = self::serverInfo();
            $result['_meta'] = $meta;
        }

        return [
            'status' => 200,
            'body' => [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ],
        ];
    }

    /** @param array<string, mixed>|null $data @return array{status: int, body: array<string, mixed>} */
    private function failure(
        int|string|null $id,
        int $code,
        string $message,
        int $status = 200,
        ?array $data = null,
    ): array {
        $body = self::errorEnvelope($id, $code, $message);
        if ($data !== null) {
            $body['error']['data'] = $data;
        }

        return ['status' => $status, 'body' => $body];
    }

    private function supports(string $version): bool
    {
        return $version === self::MODERN_VERSION || in_array($version, self::LEGACY_VERSIONS, true);
    }

    /** @return list<string> */
    private static function supportedVersions(): array
    {
        return array_merge([self::MODERN_VERSION], self::LEGACY_VERSIONS);
    }

    /** @return array{name: string, title: string, version: string, websiteUrl: string} */
    private static function serverInfo(): array
    {
        return [
            'name' => self::SERVER_NAME,
            'title' => 'BSN Expert',
            'version' => self::SERVER_VERSION,
            'websiteUrl' => 'https://bsn.expert/',
        ];
    }

    private static function instructions(): string
    {
        return 'Read public BSN Expert snapshot data. Use bsn.account.get for a general public account lookup; its compact known-tag summary points to bsn.account.tags when linked accounts, unknown tags, or reciprocal-pair details are needed. Use bsn.account.tags directly for questions specifically about an account\'s tags or relationships, and bsn.tags.list to understand the tag catalog and reciprocal-pair semantics. This server never signs transactions and does not prove account ownership.';
    }
}
