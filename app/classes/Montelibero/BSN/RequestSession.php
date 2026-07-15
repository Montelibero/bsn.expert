<?php

namespace Montelibero\BSN;

class RequestSession
{
    private const TOKEN_KEY = '_request_tokens';

    private ?\Closure $onStarted = null;

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

        // FrankenPHP workers serve multiple requests in one process. Do not let
        // a session id from the previous request leak into a new visitor.
        session_id('');
        $_SESSION = [];

        $session_id = $this->sessionCookieId();
        if ($session_id === null) {
            return;
        }

        session_id($session_id);
        $this->start();
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

        $this->ensureStarted();

        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Unable to regenerate the session ID.');
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (!$this->enabled) {
            return;
        }

        $session_id = $this->sessionCookieId();
        if (session_status() !== PHP_SESSION_ACTIVE && $session_id !== null) {
            session_id($session_id);
            $this->start();
            $_SESSION = [];
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->expireCookie();
            return;
        }

        $this->expireCookie();
        session_destroy();
    }

    private function expireCookie(): void
    {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function consume(string $key): mixed
    {
        $value = $this->get($key);

        if (!array_key_exists($key, $_SESSION)) {
            return null;
        }

        $this->ensureStarted();
        unset($_SESSION[$key]);

        return $value;
    }

    public function remove(string $key): void
    {
        if (!array_key_exists($key, $_SESSION)) {
            return;
        }

        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    public function id(): string
    {
        $this->ensureStarted();
        return session_id();
    }

    public function getOrCreateToken(string $purpose): string
    {
        $tokens = $this->get(self::TOKEN_KEY);
        if (!is_array($tokens)) {
            $tokens = [];
        }

        $token = $tokens[$purpose] ?? null;
        if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $tokens[$purpose] = $token;
        $this->set(self::TOKEN_KEY, $tokens);

        return $token;
    }

    public function onStarted(callable $callback): void
    {
        $this->onStarted = \Closure::fromCallable($callback);
    }

    public function isStarted(): bool
    {
        return $this->enabled && session_status() === PHP_SESSION_ACTIVE;
    }

    private function sessionCookieId(): ?string
    {
        $session_id = $_COOKIE[session_name()] ?? null;
        if (
            !is_string($session_id)
            || $session_id === ''
            || strlen($session_id) > 256
            || !preg_match('/^[A-Za-z0-9,-]+$/D', $session_id)
        ) {
            unset($_COOKIE[session_name()]);
            return null;
        }

        return $session_id;
    }

    private function ensureStarted(): void
    {
        if (!$this->enabled) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $pending_data = $_SESSION ?? [];
        $this->start(false);
        $_SESSION = array_replace($_SESSION, $pending_data);

        if ($this->onStarted !== null) {
            ($this->onStarted)();
        }
    }

    private function start(bool $notify = true): void
    {
        if (!session_start()) {
            throw new \RuntimeException('Unable to start the session.');
        }

        if ($notify && $this->onStarted !== null) {
            ($this->onStarted)();
        }
    }
}
