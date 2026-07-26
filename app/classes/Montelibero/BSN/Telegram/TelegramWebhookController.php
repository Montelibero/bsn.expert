<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use Pecee\SimpleRouter\SimpleRouter;

final class TelegramWebhookController
{
    public const MAX_BODY_BYTES = 1048576;

    public function __construct(
        private readonly TelegramWebhookAccess $Access,
        private readonly TelegramUpdateParser $Parser,
        private readonly TelegramUpdateStore $Updates,
    ) {
    }

    public function receive(?string $raw_body = null): string
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return $this->respond(405, ['ok' => false, 'error' => 'method_not_allowed'], ['Allow' => 'POST']);
        }

        $content_type = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
        if (preg_match(
            '/\Aapplication\/json(?:\s*;\s*charset\s*=\s*[a-z0-9._-]+)?\s*\z/iD',
            $content_type
        ) !== 1) {
            return $this->respond(415, ['ok' => false, 'error' => 'unsupported_media_type']);
        }

        $secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
        if (!$this->Access->isAllowed(is_string($secret) ? $secret : null)) {
            return $this->respond(403, ['ok' => false, 'error' => 'forbidden']);
        }

        $content_length = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (is_string($content_length)
            && preg_match('/\A[0-9]+\z/D', $content_length) === 1
            && (int) $content_length > self::MAX_BODY_BYTES
        ) {
            return $this->respond(413, ['ok' => false, 'error' => 'payload_too_large']);
        }

        if ($raw_body === null) {
            $contents = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
            if (!is_string($contents)) {
                return $this->respond(400, ['ok' => false, 'error' => 'invalid_body']);
            }
            $raw_body = $contents;
        }
        if (strlen($raw_body) > self::MAX_BODY_BYTES) {
            return $this->respond(413, ['ok' => false, 'error' => 'payload_too_large']);
        }

        try {
            $update = json_decode($raw_body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->respond(400, ['ok' => false, 'error' => 'invalid_json']);
        }
        if (!is_array($update) || array_is_list($update)) {
            return $this->respond(200, ['ok' => true, 'status' => 'ignored']);
        }

        $payload = $this->Parser->parse($update);
        if ($payload === null) {
            return $this->respond(200, ['ok' => true, 'status' => 'ignored']);
        }

        try {
            $result = $this->Updates->enqueue($payload);
        } catch (\Throwable $Exception) {
            error_log('Telegram webhook enqueue failed: ' . $Exception::class);

            return $this->respond(503, ['ok' => false, 'error' => 'queue_unavailable']);
        }

        if ($result === TelegramUpdateStore::ENQUEUE_DUPLICATE) {
            return $this->respond(200, ['ok' => true, 'status' => 'duplicate']);
        }
        if ($result !== TelegramUpdateStore::ENQUEUE_ACCEPTED) {
            error_log('Telegram webhook enqueue returned an unknown result.');

            return $this->respond(503, ['ok' => false, 'error' => 'queue_unavailable']);
        }

        // Telegram can acknowledge the user's command while the durable worker
        // builds the actual answer. The result of this inline Bot API call is not
        // observable, so it is never treated as the durable job's completion.
        return $this->respond(200, [
            'method' => 'setMessageReaction',
            'chat_id' => $payload['chat']['id'],
            'message_id' => $payload['message_id'],
            'reaction' => [
                ['type' => 'emoji', 'emoji' => '👀'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function respond(int $status, array $data, array $headers = []): string
    {
        $Response = SimpleRouter::response();
        $Response->httpCode($status);
        $Response->header('Content-Type: application/json; charset=utf-8');
        $Response->header('Cache-Control: no-store');
        $Response->header('X-Content-Type-Options: nosniff');
        foreach ($headers as $name => $value) {
            $Response->header($name . ': ' . $value);
        }

        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
