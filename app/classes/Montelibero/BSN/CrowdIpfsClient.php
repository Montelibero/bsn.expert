<?php

namespace Montelibero\BSN;

use GuzzleHttp\Client;
use Throwable;

class CrowdIpfsClient
{
    private const CACHE_PREFIX = 'crowd_ipfs_json:v1:';
    private const UPLOAD_CACHE_PREFIX = 'crowd_ipfs_upload:v1:';
    private const CACHE_TTL = 31536000;

    private Client $HttpClient;

    public function __construct(
        private readonly CrowdConfig $Config,
        private readonly MongoCacheManager $CacheManager,
    ) {
        $this->HttpClient = new Client([
            'timeout' => 20,
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

    public function uploadProjectJson(array $data, string $code): array
    {
        $code = strtoupper(trim($code));
        $json = $this->canonicalJson($data);
        $hash = hash('sha256', $json);
        $cache_key = self::UPLOAD_CACHE_PREFIX . $hash;
        $cached = $this->CacheManager->fetch($cache_key);
        if (is_array($cached) && is_string($cached['data']['cid'] ?? null)) {
            return [
                'cid' => $cached['data']['cid'],
                'from_cache' => true,
                'is_duplicate' => (bool) ($cached['data']['is_duplicate'] ?? false),
            ];
        }

        $jwt = $this->Config->pinataJwt();
        if ($jwt) {
            $upload = $this->uploadJsonFile($json, $code, $jwt);
        } else {
            $upload = $this->pinJsonLegacy($data, $code);
        }

        $cid = trim((string) ($upload['cid'] ?? ''));
        if ($cid === '') {
            throw new \RuntimeException('Pinata did not return a CID');
        }

        $stored = [
            'cid' => $cid,
            'is_duplicate' => (bool) ($upload['is_duplicate'] ?? false),
        ];
        $this->CacheManager->store($cache_key, $stored, self::CACHE_TTL, [
            'code' => $code,
            'sha256' => $hash,
        ]);
        $this->CacheManager->store(self::CACHE_PREFIX . $cid, $data, self::CACHE_TTL, [
            'cid' => $cid,
            'source_url' => 'pinata-upload',
        ]);

        return [
            'cid' => $cid,
            'from_cache' => false,
            'is_duplicate' => $stored['is_duplicate'],
        ];
    }

    private function gateways(string $cid): array
    {
        return [
            'https://ipfs.io/ipfs/' . rawurlencode($cid),
            'https://gateway.pinata.cloud/ipfs/' . rawurlencode($cid),
            'https://cloudflare-ipfs.com/ipfs/' . rawurlencode($cid),
        ];
    }

    private function uploadJsonFile(string $json, string $code, string $jwt): array
    {
        $multipart = [
            [
                'name' => 'network',
                'contents' => 'public',
            ],
            [
                'name' => 'file',
                'contents' => $json,
                'filename' => 'project-' . $code . '.json',
                'headers' => ['Content-Type' => 'application/json'],
            ],
            [
                'name' => 'name',
                'contents' => 'project-' . $code . '.json',
            ],
            [
                'name' => 'keyvalues',
                'contents' => json_encode([
                    'type' => 'crowd',
                    'code' => $code,
                ], JSON_THROW_ON_ERROR),
            ],
        ];
        if ($group_id = $this->Config->pinataCrowdGroupId()) {
            $multipart[] = [
                'name' => 'group_id',
                'contents' => $group_id,
            ];
        }

        $Response = $this->HttpClient->request('POST', 'https://uploads.pinata.cloud/v3/files', [
            'headers' => [
                'Authorization' => 'Bearer ' . $jwt,
                'Accept' => 'application/json',
            ],
            'multipart' => $multipart,
        ]);

        $body = (string) $Response->getBody();
        $decoded = json_decode($body, true);
        if ($Response->getStatusCode() >= 400 || !is_array($decoded)) {
            throw new \RuntimeException(sprintf('Pinata upload failed: HTTP %s %s', $Response->getStatusCode(), $body));
        }

        return [
            'cid' => $decoded['data']['cid'] ?? null,
            'is_duplicate' => (bool) ($decoded['data']['is_duplicate'] ?? false),
        ];
    }

    private function pinJsonLegacy(array $data, string $code): array
    {
        $api_key = $this->Config->pinataApiKey();
        $api_secret = $this->Config->pinataApiSecret();
        if (!$api_key || !$api_secret) {
            throw new \RuntimeException('PINATA_API_JWT or PINATA_API_KEY/PINATA_API_SECRET is required');
        }

        $options = ['cidVersion' => 1];
        if ($group_id = $this->Config->pinataCrowdGroupId()) {
            $options['groupId'] = $group_id;
        }

        $Response = $this->HttpClient->request('POST', 'https://api.pinata.cloud/pinning/pinJSONToIPFS', [
            'headers' => [
                'pinata_api_key' => $api_key,
                'pinata_secret_api_key' => $api_secret,
                'Accept' => 'application/json',
            ],
            'json' => [
                'pinataOptions' => $options,
                'pinataMetadata' => [
                    'name' => 'project-' . $code . '.json',
                    'keyvalues' => [
                        'type' => 'crowd',
                        'code' => $code,
                    ],
                ],
                'pinataContent' => $data,
            ],
        ]);

        $body = (string) $Response->getBody();
        $decoded = json_decode($body, true);
        if ($Response->getStatusCode() >= 400 || !is_array($decoded)) {
            throw new \RuntimeException(sprintf('Pinata upload failed: HTTP %s %s', $Response->getStatusCode(), $body));
        }

        return [
            'cid' => $decoded['IpfsHash'] ?? null,
            'is_duplicate' => (bool) ($decoded['isDuplicate'] ?? false),
        ];
    }

    private function canonicalJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!is_string($json)) {
            throw new \RuntimeException('Could not encode IPFS JSON');
        }

        return $json;
    }
}
