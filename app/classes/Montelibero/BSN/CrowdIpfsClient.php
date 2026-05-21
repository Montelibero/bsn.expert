<?php

namespace Montelibero\BSN;

use GuzzleHttp\Client;
use Throwable;

class CrowdIpfsClient
{
    private const CACHE_PREFIX = 'crowd_ipfs_json:v1:';
    private const CACHE_TTL = 31536000;

    private Client $HttpClient;

    public function __construct(
        private readonly MongoCacheManager $CacheManager,
    ) {
        $this->HttpClient = new Client([
            'timeout' => 8,
            'connect_timeout' => 3,
            'http_errors' => false,
        ]);
    }

    public function fetchJson(string $cid, bool $force_refresh = false): array
    {
        $cid = trim($cid);
        if ($cid === '') {
            throw new \RuntimeException('IPFS CID is empty');
        }

        $cache_key = self::CACHE_PREFIX . $cid;
        $cached = $this->CacheManager->fetch($cache_key);
        if (!$force_refresh && is_array($cached) && is_array($cached['data'] ?? null)) {
            return $cached['data'] + ['_ipfs_from_cache' => true];
        }

        $last_error = null;
        foreach ($this->gateways($cid) as $url) {
            try {
                $Response = $this->HttpClient->request('GET', $url, [
                    'headers' => ['Accept' => 'application/json'],
                ]);
                if ($Response->getStatusCode() >= 400) {
                    $last_error = sprintf('HTTP %s from %s', $Response->getStatusCode(), $url);
                    continue;
                }

                $body = (string) $Response->getBody();
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    $last_error = sprintf('IPFS response is not an object: %s', $url);
                    continue;
                }

                $this->CacheManager->store($cache_key, $data, self::CACHE_TTL, [
                    'cid' => $cid,
                    'source_url' => $url,
                ]);

                return $data + ['_ipfs_from_cache' => false];
            } catch (Throwable $Exception) {
                $last_error = $Exception->getMessage();
            }
        }

        if (is_array($cached) && is_array($cached['data'] ?? null)) {
            $data = $cached['data'];
            $data['_ipfs_from_cache'] = true;
            $data['_ipfs_warning'] = $last_error;
            return $data;
        }

        throw new \RuntimeException(sprintf('IPFS JSON %s is unavailable: %s', $cid, $last_error ?? 'unknown error'));
    }

    private function gateways(string $cid): array
    {
        return [
            'https://ipfs.io/ipfs/' . rawurlencode($cid),
            'https://gateway.pinata.cloud/ipfs/' . rawurlencode($cid),
            'https://cloudflare-ipfs.com/ipfs/' . rawurlencode($cid),
        ];
    }
}
