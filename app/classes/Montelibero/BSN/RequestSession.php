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
}
