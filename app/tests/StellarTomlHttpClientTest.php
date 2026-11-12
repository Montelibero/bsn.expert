<?php

declare(strict_types=1);

namespace Montelibero\BSN {
    // Production used this lookup and received the neighbouring service's IP.
    function dns_get_record(string $host, int $type): array
    {
        throw new \RuntimeException('The crawler must not consult internal DNS');
    }
}

namespace {
    use GuzzleHttp\Client;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Promise\Create;
    use GuzzleHttp\Psr7\Response;
    use Montelibero\BSN\StellarTomlCrawler;
    use Montelibero\BSN\StellarTomlHttpClient;
    use Montelibero\BSN\StellarTomlImageCrawler;

    require dirname(__DIR__) . '/vendor/autoload.php';

    function assertPublicFetch(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function dnsAnswer(array $addresses, int $type = 1, int $ttl = 60): Response
    {
        return new Response(200, [], json_encode([
            'Status' => 0,
            'Answer' => array_map(static fn (string $ip): array => [
                'name' => 'eurmtl.me.', 'type' => $type, 'TTL' => $ttl, 'data' => $ip,
            ], $addresses),
        ], JSON_THROW_ON_ERROR));
    }

    function publicFetcher(array $responses, array &$requests): StellarTomlHttpClient
    {
        $handler = static function ($request, array $options) use (&$responses, &$requests) {
            $requests[] = ['request' => $request, 'options' => $options];
            assertPublicFetch($responses !== [], 'Unexpected network request: ' . $request->getUri());
            $response = array_shift($responses);
            if ($response instanceof Throwable) {
                throw $response;
            }
            // Emulate the transport's header and decoded-body callbacks, including
            // missing Content-Length, rather than bypassing the bounded sink.
            $options['on_headers']($response);
            $source = $response->getBody();
            while (!$source->eof()) {
                $options['sink']->write($source->read(8192));
            }
            $options['sink']->rewind();
            return Create::promiseFor($response->withBody($options['sink']));
        };
        return new StellarTomlHttpClient(new Client(['handler' => HandlerStack::create($handler)]));
    }

    $requests = [];
    $client = publicFetcher([
        dnsAnswer(['93.184.215.14']), dnsAnswer(['2606:4700:4700::1111'], 28),
        new Response(200, [], 'VERSION="2.5.0"'), new Response(200, [], 'cached DNS'),
    ], $requests);
    $result = $client->get('https://eurmtl.me/.well-known/stellar.toml', 102400);
    assertPublicFetch(($result['status'] ?? null) === 'ok', 'Public DNS must work despite internal DNS.');
    assertPublicFetch((string) $result['response']->getBody() === 'VERSION="2.5.0"', 'Body must survive the bounded sink.');
    assertPublicFetch(count($requests) === 3, 'Resolve both A and AAAA, then fetch.');
    assertPublicFetch($requests[0]['options']['curl'][CURLOPT_RESOLVE] === ['cloudflare-dns.com:443:1.1.1.1,1.0.0.1'], 'DoH bootstrap must bypass local DNS too.');
    assertPublicFetch($requests[2]['options']['curl'][CURLOPT_RESOLVE] === ['eurmtl.me:443:93.184.215.14,[2606:4700:4700::1111]'], 'Fetch must use the exact validated public addresses.');
    assertPublicFetch($requests[2]['request']->getHeaderLine('Host') === 'eurmtl.me', 'Preserve the hostname for HTTPS.');
    foreach ($requests as $request) {
        $options = $request['options'];
        assertPublicFetch($options['proxy'] === '' && $options['verify'] === true, 'Use direct HTTPS with certificate verification.');
        assertPublicFetch($options['allow_redirects'] === false, 'Redirects cannot escape the validated destination.');
        assertPublicFetch(empty($options['stream']), 'Do not select a handler that ignores the cURL pins.');
        assertPublicFetch($options['curl'][CURLOPT_FRESH_CONNECT] && $options['curl'][CURLOPT_FORBID_REUSE], 'Old connections cannot bypass new pins.');
    }
    $client->get('https://eurmtl.me/logo.png', 1048576);
    assertPublicFetch(count($requests) === 4, 'Reuse DNS within its TTL for logos on the same domain.');

    foreach (['10.0.1.9', '127.0.0.1', '169.254.169.254', '100.64.0.1', '224.0.0.1', '0.0.0.0', 'invalid'] as $ip) {
        $requests = [];
        $client = publicFetcher([dnsAnswer(['93.184.215.14', $ip])], $requests);
        $result = $client->get('https://eurmtl.me/file', 100);
        assertPublicFetch(($result['error']['code'] ?? null) === 'invalid_domain', 'Reject even one unsafe A record: ' . $ip);
        assertPublicFetch(count($requests) === 1, 'Never fetch a domain with an unsafe record.');
    }
    foreach (['::1', 'fc00::1', 'fe80::1', 'ff02::1', '::ffff:10.0.1.9', '64:ff9b::a00:109', '2001:db8::1'] as $ip) {
        $requests = [];
        $client = publicFetcher([dnsAnswer(['93.184.215.14']), dnsAnswer([$ip], 28)], $requests);
        $result = $client->get('https://eurmtl.me/file', 100);
        assertPublicFetch(($result['error']['code'] ?? null) === 'invalid_domain', 'Reject unsafe AAAA even with public A: ' . $ip);
        assertPublicFetch(count($requests) === 2, 'Never fetch after unsafe AAAA.');
    }
    foreach (['http://eurmtl.me/file', 'https://user:pass@eurmtl.me/', 'https://127.0.0.1/', 'https://127.1/', 'https://0x7f.1/', 'https://[::1]/', 'https://localhost/'] as $url) {
        $requests = [];
        $result = publicFetcher([], $requests)->get($url, 100);
        assertPublicFetch(isset($result['error']) && $requests === [], 'Reject unsafe URL before networking: ' . $url);
    }

    $requests = [];
    $client = publicFetcher([
        new Response(503), dnsAnswer(['93.184.215.14']), dnsAnswer([], 28), new Response(200, [], 'fallback'),
    ], $requests);
    $result = $client->get('https://eurmtl.me:8443/file', 100);
    assertPublicFetch(($result['status'] ?? null) === 'ok', 'Use the secondary public resolver when the first fails.');
    assertPublicFetch($requests[1]['options']['curl'][CURLOPT_RESOLVE] === ['dns.google:443:8.8.8.8,8.8.4.4'], 'Pin the fallback resolver too.');
    assertPublicFetch($requests[3]['options']['curl'][CURLOPT_RESOLVE] === ['eurmtl.me:8443:93.184.215.14'], 'Pin the requested HTTPS port.');

    foreach ([
        [new Response(200, [], '{"Status":3}')],
        [dnsAnswer([]), dnsAnswer([], 28)],
        [new Response(200, [], 'invalid JSON'), new Response(500)],
        [dnsAnswer(['93.184.215.14']), new Response(503), new Response(503)],
    ] as $responses) {
        $requests = [];
        $result = publicFetcher($responses, $requests)->get('https://eurmtl.me/file', 100);
        assertPublicFetch(($result['error']['code'] ?? null) === 'dns_error', 'DNS failures must not fall back to internal DNS or a partial result.');
        foreach ($requests as $request) {
            assertPublicFetch($request['request']->getUri()->getHost() !== 'eurmtl.me', 'No resource request after DNS failure.');
        }
    }

    $requests = [];
    $client = publicFetcher([
        dnsAnswer(['93.184.215.14'], 1, 0), dnsAnswer([], 28), new Response(200, [], 'first'),
        dnsAnswer(['1.1.1.1']), dnsAnswer([], 28), new Response(200, [], 'second'),
    ], $requests);
    $client->get('https://eurmtl.me/file', 100);
    $client->get('https://eurmtl.me/file', 100);
    assertPublicFetch(count($requests) === 6 && $requests[5]['options']['curl'][CURLOPT_RESOLVE] === ['eurmtl.me:443:1.1.1.1'], 'Expired DNS must be queried and pinned again.');

    foreach ([new Response(200, ['Content-Length' => '101']), new Response(200, [], str_repeat('x', 101))] as $response) {
        $requests = [];
        $result = publicFetcher([dnsAnswer(['93.184.215.14']), dnsAnswer([], 28), $response], $requests)->get('https://eurmtl.me/file', 100);
        assertPublicFetch(($result['error']['code'] ?? null) === 'too_large', 'Enforce size limits with and without Content-Length.');
    }

    // Exercise both existing crawler entry points without touching MongoDB.
    $requests = [];
    $client = publicFetcher([
        dnsAnswer(['93.184.215.14']), dnsAnswer([], 28), new Response(200, [], 'VERSION="2.5.0"'),
        new Response(302, ['Location' => 'https://127.0.0.1/']),
        new Response(200, [], '  '), new Response(200, [], str_repeat('x', 102401)),
        new Response(304, ['Content-Length' => '2000000']), new Response(200, ['ETag' => 'new'], 'image'),
        new Response(200, [], str_repeat('x', 1048577)),
    ], $requests);
    $reflection = new ReflectionClass(StellarTomlCrawler::class);
    $crawler = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('HttpClient')->setValue($crawler, $client);
    $fetch = $reflection->getMethod('fetchToml');
    assertPublicFetch($fetch->invoke($crawler, 'eurmtl.me')['content'] === 'VERSION="2.5.0"', 'TOML must use the public HTTP client.');
    assertPublicFetch($fetch->invoke($crawler, 'eurmtl.me')['error']['code'] === 'http_error', 'TOML redirects remain errors.');
    assertPublicFetch($fetch->invoke($crawler, 'eurmtl.me')['error']['code'] === 'empty_file', 'Empty TOML remains an error.');
    assertPublicFetch($fetch->invoke($crawler, 'eurmtl.me')['error']['code'] === 'too_large', 'Keep the 100 KB TOML limit.');

    $reflection = new ReflectionClass(StellarTomlImageCrawler::class);
    $crawler = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('HttpClient')->setValue($crawler, $client);
    $fetch = $reflection->getMethod('fetchImage');
    assertPublicFetch($fetch->invoke($crawler, 'https://eurmtl.me/logo.png', ['etag' => 'old', 'last_modified' => 'yesterday'])['status'] === 'not_modified', 'Keep conditional image requests.');
    $image_request = $requests[count($requests) - 1]['request'];
    assertPublicFetch($image_request->getHeaderLine('If-None-Match') === 'old' && $image_request->getHeaderLine('If-Modified-Since') === 'yesterday', 'Preserve both cache validators.');
    $result = $fetch->invoke($crawler, 'https://eurmtl.me/logo.png', null);
    assertPublicFetch($result['content'] === 'image' && $result['etag'] === 'new', 'Preserve image data and validators.');
    assertPublicFetch($fetch->invoke($crawler, 'https://eurmtl.me/logo.png', null)['error']['code'] === 'too_large', 'Keep the 1 MB image limit.');

    // PHP-DI must construct the real client with its cURL-only transport.
    $container = new DI\Container();
    assertPublicFetch($container->get(StellarTomlHttpClient::class) instanceof StellarTomlHttpClient, 'Public HTTP client must autowire.');

    fwrite(STDOUT, "Stellar TOML public HTTP client tests passed.\n");
}
