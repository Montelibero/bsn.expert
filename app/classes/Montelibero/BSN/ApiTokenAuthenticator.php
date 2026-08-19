<?php

declare(strict_types=1);

namespace Montelibero\BSN;

final class ApiTokenAuthenticator
{
    private const MAX_AUTHORIZATION_HEADER_BYTES = 1024;
    private const MAX_TOKEN_BYTES = 512;

    public function __construct(
        private readonly ApiKeysManager $ApiKeysManager,
    ) {
    }

    public function authenticate(?string $authorization_header, string $ip): ApiPrincipal
    {
        $token = $this->extractBearerToken($authorization_header);
        $key = $this->ApiKeysManager->findByKey($token);
        if ($key === null) {
            throw new ApiAuthenticationException(ApiAuthenticationException::INVALID_TOKEN);
        }

        $this->ApiKeysManager->markUsed($key['id'], $ip);

        return new ApiPrincipal($key, self::maskToken($token));
    }

    private function extractBearerToken(?string $authorization_header): string
    {
        if ($authorization_header === null || strlen($authorization_header) > self::MAX_AUTHORIZATION_HEADER_BYTES) {
            throw new ApiAuthenticationException(ApiAuthenticationException::MISSING_TOKEN);
        }

        $authorization_header = trim($authorization_header);
        if (strncasecmp($authorization_header, 'Bearer ', 7) !== 0) {
            throw new ApiAuthenticationException(ApiAuthenticationException::MISSING_TOKEN);
        }

        $token = trim(substr($authorization_header, 7));
        if ($token === '') {
            throw new ApiAuthenticationException(ApiAuthenticationException::MISSING_TOKEN);
        }
        if (strlen($token) > self::MAX_TOKEN_BYTES || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new ApiAuthenticationException(ApiAuthenticationException::INVALID_TOKEN);
        }

        return $token;
    }

    public static function maskToken(?string $token): string
    {
        if ($token === null || $token === '') {
            return '';
        }
        if (strlen($token) <= 8) {
            return $token;
        }

        return substr($token, 0, 6) . '…' . substr($token, -4);
    }
}
