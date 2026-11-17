<?php

declare(strict_types=1);

namespace Montelibero\BSN\MTLA;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class TgMembersClient
{
    private readonly ClientInterface $http;

    public function __construct(#[\SensitiveParameter] private readonly string $key, ?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client();
    }

    public function get(string $path): array
    {
        if (!preg_match('/\A\/chats(?:\/-?[1-9][0-9]*)?\z/', $path)) {
            throw new RuntimeException('Invalid TG Members request path.');
        }
        try {
            $response = $this->http->request('GET', 'https://tg-members.mtla.me' . $path, [
                'headers' => ['Authorization' => 'Bearer ' . trim($this->key), 'Accept' => 'application/json'],
                'allow_redirects' => false,
                'http_errors' => false,
                'connect_timeout' => 3,
                'timeout' => 15,
            ]);
        } catch (GuzzleException) {
            throw new RuntimeException('TG Members request failed.');
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('TG Members returned an HTTP error.', $response->getStatusCode());
        }
        $payload = json_decode((string) $response->getBody(), flags: JSON_BIGINT_AS_STRING);
        $list = $path === '/chats' ? 'chats' : 'members';
        if (!is_object($payload) || !is_array($payload->{$list} ?? null)) {
            throw new RuntimeException('Invalid TG Members response.');
        }
        $result = (array) $payload;
        foreach ($result[$list] as $i => $row) {
            if (!is_object($row)) {
                throw new RuntimeException('Invalid TG Members response row.');
            }
            $result[$list][$i] = (array) $row;
        }
        return $result;
    }
}
