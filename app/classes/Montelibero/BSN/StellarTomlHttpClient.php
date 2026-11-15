<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Use public DNS unless an administrator explicitly trusts a neighbouring domain. */
final class StellarTomlHttpClient
{
    private const RESOLVERS = [
        ['url' => 'https://cloudflare-dns.com/dns-query', 'pin' => 'cloudflare-dns.com:443:1.1.1.1,1.0.0.1'],
        ['url' => 'https://dns.google/resolve', 'pin' => 'dns.google:443:8.8.8.8,8.8.4.4'],
    ];

    private ClientInterface $HttpClient;
    private array $resolved = [];

    public function __construct(private StellarTomlManager $TomlManager, ?ClientInterface $HttpClient = null)
    {
        // The stream handler ignores CURLOPT_RESOLVE. Always use cURL here.
        $this->HttpClient = $HttpClient ?? new Client([
            'handler' => HandlerStack::create(new CurlHandler()),
        ]);
    }

    public function get(string $url, int $max_bytes, array $headers = []): array
    {
        try {
            $uri = new Uri($url);
        } catch (\InvalidArgumentException) {
            return $this->error('invalid_url', 'Invalid resource URL');
        }
        $host = StellarTomlManager::normalizeHomeDomain($uri->getHost());
        if ($uri->getScheme() !== 'https' || $uri->getUserInfo() !== '' || $host === null) {
            return $this->error('invalid_domain', 'Invalid resource domain');
        }
        // cURL interprets numeric/hex hostnames as IP literals, bypassing DNS pins.
        if (preg_match('~\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}\z~i', $host)) {
            return $this->error('invalid_domain', 'Resource host must be a domain name');
        }

        // Read the policy on every request: workers must observe removals immediately.
        $neighbor = $this->TomlManager->isDomainNeighbor($host);
        if ($neighbor && ($uri->getPort() ?? 443) !== 443) {
            return $this->error('invalid_domain', 'Neighbour domains require HTTPS port 443');
        }
        $resolved = $neighbor ? $this->resolveNeighbor($host) : $this->resolve($host);
        if (isset($resolved['error'])) {
            return $resolved;
        }
        $addresses = array_map(
            static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
            $resolved['addresses']
        );
        $pin = $host . ':' . ($uri->getPort() ?? 443) . ':' . implode(',', $addresses);

        // Keep the hostname in the URL for Host, SNI and certificate verification.
        return $this->request((string) $uri->withHost($host), $pin, $max_bytes, $headers, 10);
    }

    private function resolveNeighbor(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return $this->error('invalid_domain', 'Invalid resource address');
            }
            $packed = inet_pton($ip);
            $type = str_contains($ip, ':') ? 28 : 1;
            // Permit RFC1918 and IPv6 ULA, but not loopback, link-local or metadata endpoints.
            $private = $type === 1
                ? ord($packed[0]) === 10
                    || (ord($packed[0]) === 172 && (ord($packed[1]) & 0xf0) === 16)
                    || (ord($packed[0]) === 192 && ord($packed[1]) === 168)
                : (ord($packed[0]) & 0xfe) === 0xfc;
            if (!$private && !$this->isPublicAddress($ip, $type)) {
                return $this->error('invalid_domain', 'Invalid resource address');
            }
            $addresses[] = $ip;
        }
        if ($addresses === []) {
            return $this->error('dns_error', 'Domain does not resolve');
        }

        return ['addresses' => array_values(array_unique($addresses))];
    }

    private function resolve(string $host): array
    {
        if (($this->resolved[$host]['expires'] ?? 0) > time()) {
            return $this->resolved[$host];
        }

        $last_error = 'Public DNS lookup failed';
        foreach (self::RESOLVERS as $resolver) {
            $addresses = [];
            $ttl = 60;
            foreach ([1, 28] as $type) {
                $url = $resolver['url'] . '?' . http_build_query(['name' => $host, 'type' => $type]);
                $result = $this->request($url, $resolver['pin'], 65536, ['Accept' => 'application/dns-json'], 5);
                if (isset($result['error'])) {
                    $last_error = $result['error']['message'];
                    continue 2;
                }
                $response = $result['response'];
                $data = json_decode((string) $response->getBody(), true);
                if ($response->getStatusCode() !== 200 || !is_array($data)
                    || !in_array($data['Status'] ?? null, [0, 3], true) || ($data['TC'] ?? false) === true
                    || !is_array($data['Answer'] ?? [])
                ) {
                    $last_error = 'Invalid public DNS response';
                    continue 2;
                }
                if ($data['Status'] === 3) {
                    return $this->error('dns_error', 'Domain does not resolve in public DNS');
                }
                foreach ($data['Answer'] ?? [] as $record) {
                    $ttl = min($ttl, max(0, (int) ($record['TTL'] ?? 0)));
                    if (($record['type'] ?? null) !== $type) {
                        continue;
                    }
                    $ip = $record['data'] ?? null;
                    if (!is_string($ip) || !$this->isPublicAddress($ip, $type)) {
                        return $this->error('invalid_domain', 'Public DNS returned a non-public or invalid IP: ' . (is_string($ip) ? $ip : '(missing)'));
                    }
                    $addresses[] = $ip;
                }
            }
            if ($addresses === []) {
                return $this->error('dns_error', 'Domain has no public A or AAAA records');
            }
            // Bound both lifetime and size in long-lived web workers.
            if (count($this->resolved) >= 256) {
                $this->resolved = [];
            }
            return $this->resolved[$host] = [
                'addresses' => array_values(array_unique($addresses)),
                'expires' => time() + $ttl,
            ];
        }

        return $this->error('dns_error', $last_error);
    }

    private function isPublicAddress(string $ip, int $type): bool
    {
        $family = $type === 1 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
        if (!filter_var($ip, FILTER_VALIDATE_IP, $family | FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        // Global unicast only: exclude multicast and IPv6 translation prefixes.
        return $type === 1 ? ord($packed[0]) < 224 : (ord($packed[0]) & 0xe0) === 0x20;
    }

    private function request(string $url, string $pin, int $max_bytes, array $headers, int $timeout): array
    {
        $body = Utils::streamFor();
        $too_large = false;
        $sink = FnStream::decorate($body, [
            'write' => static function (string $chunk) use ($body, $max_bytes, &$too_large): int {
                if ($body->tell() + strlen($chunk) > $max_bytes) {
                    $too_large = true;
                    throw new \LengthException('Response size limit exceeded');
                }
                return $body->write($chunk);
            },
        ]);

        try {
            $response = $this->HttpClient->request('GET', $url, [
                'timeout' => $timeout,
                'connect_timeout' => $timeout,
                'http_errors' => false,
                'allow_redirects' => false,
                'proxy' => '',
                'verify' => true,
                'headers' => $headers,
                'sink' => $sink,
                'on_headers' => static function (ResponseInterface $response) use ($max_bytes, &$too_large): void {
                    // A 304 can describe the cached representation's size without sending it.
                    if ($response->getStatusCode() !== 304 && (int) $response->getHeaderLine('Content-Length') > $max_bytes) {
                        $too_large = true;
                        throw new \LengthException('Response size limit exceeded');
                    }
                },
                'curl' => [
                    CURLOPT_RESOLVE => [$pin],
                    // Do not reuse a connection established for an earlier DNS answer.
                    CURLOPT_FRESH_CONNECT => true,
                    CURLOPT_FORBID_REUSE => true,
                ],
            ]);
            return ['status' => 'ok', 'response' => $response];
        } catch (Throwable $e) {
            if ($too_large) {
                return $this->error('too_large', 'Response exceeds ' . $max_bytes . ' bytes');
            }
            $message = $e->getMessage();
            $code = str_contains(strtolower($message), 'timeout') || str_contains(strtolower($message), 'timed out')
                ? 'timeout' : ($e instanceof ConnectException ? 'connect_error' : 'server_error');
            return $this->error($code, $message);
        }
    }

    private function error(string $code, string $message): array
    {
        return ['status' => 'error', 'error' => ['code' => $code, 'message' => $message]];
    }
}
