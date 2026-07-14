<?php

namespace Montelibero\BSN;

class RequestSession
{
    public function __construct(
        private readonly bool $enabled,
    ) {
    }

    public function beginRequest(): void
    {
        if (!$this->enabled) {
            $_SESSION ??= [];
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_start();
    }

    public function endRequest(): void
    {
        if ($this->enabled && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function regenerateId(): void
    {
        if (!$this->enabled) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Cannot regenerate an inactive session.');
        }

        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Unable to regenerate the session ID.');
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (!$this->enabled || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);

        session_destroy();
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function consume(string $key): mixed
    {
        $value = $this->get($key);
        unset($_SESSION[$key]);

        return $value;
    }
}
