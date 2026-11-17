<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Montelibero\BSN\MTLA\TgMembersCache;
use Montelibero\BSN\MTLA\TgMembersClient;
use Montelibero\BSN\MTLA\TgMembersSync;
use Psr\Http\Message\RequestInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkTgSync(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function tgChat(int $id, ?int $stamp, array $members, string $type = 'group'): array
{
    return [
        'id' => $id,
        'type' => $type,
        'title' => 'Test chat',
        'data_received_at' => $stamp,
        'members' => array_map(static fn(int $uid, ?string $username): array => [
            'id' => $uid, 'username' => $username, 'name' => 'Test user',
        ], array_keys($members), array_values($members)),
    ];
}

function tgList(array $chats): array
{
    return ['chats' => array_map(static function (array $chat): array {
        $chat['members_count'] = count($chat['members']);
        unset($chat['members']);
        return $chat;
    }, $chats)];
}

$cache = new class(new MongoDB\Driver\Manager(), 'test', 'fixture') extends TgMembersCache {
    public array $state = ['chats' => [], 'checked_at' => 0];
    private string $run = '';

    protected function snapshot(): array { return $this->state; }
    public function begin(string $run): array { $this->run = $run; return $this->state; }
    public function publish(string $run, array $chats, int $checked_at): void
    {
        checkTgSync($run === $this->run, 'A superseded refresh must not publish');
        $this->state = compact('chats', 'checked_at');
    }
    public function revoke(): void
    {
        $this->run = '';
        $this->state = ['chats' => [], 'checked_at' => 0];
    }
};

$refresh = static function (array $chats, array $overrides = [], ?int $expected_error = null) use ($cache): array {
    $responses = ['/chats' => tgList($chats)];
    foreach ($chats as $chat) {
        $responses['/chats/' . $chat['id']] = $chat;
    }
    $responses = array_replace($responses, $overrides);
    $requests = [];
    $handler = static function (RequestInterface $request) use ($responses, &$requests): FulfilledPromise {
        $uri = $request->getUri();
        $path = $uri->getPath();
        if ($request->getMethod() !== 'GET' || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'tg-members.mtla.me' || $request->getHeaderLine('Authorization') !== 'Bearer fixture'
            || !array_key_exists($path, $responses)) {
            throw new LogicException('Unexpected tg-members request: ' . $path);
        }
        $requests[] = $path;
        $payload = $responses[$path];
        $response = is_int($payload) ? new Response($payload) : new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
        return new FulfilledPromise($response);
    };
    $sync = new TgMembersSync($cache, new TgMembersClient('fixture', new Client(['handler' => $handler])));
    $error = null;
    try {
        $sync->sync();
    } catch (RuntimeException $e) {
        if ($expected_error === null) {
            throw $e;
        }
        $error = $e->getCode();
    }
    checkTgSync($error === $expected_error, 'Unexpected sync result for mocked HTTP response');
    return $requests;
};

$group = tgChat(-1001, 100, [11 => 'before', 22 => '']);
$channel = tgChat(-1002, 100, [11 => 'before'], 'channel');
$legacy = tgChat(-1003, null, [33 => null]);
$private = tgChat(12345, 100, [44 => 'private_user'], 'private');
checkTgSync($cache->users() === null, 'A cold cache must report unavailable');
$requests = $refresh([$group, $channel, $legacy, $private]);
$users = $cache->users();
checkTgSync(!in_array('/chats/12345', $requests, true) && !isset($users[44]), 'Skip chat types outside groups and channels');
checkTgSync($users[33] === ['chats' => ['-1003'], 'username' => null], 'A null username still contributes chat membership');
$chats = $users[11]['chats'];
sort($chats, SORT_STRING);
checkTgSync($chats === ['-1001', '-1002'], 'Return signed IDs from both groups and channels as strings');
checkTgSync($users[11]['username'] === 'before' && $users[22]['username'] === null, 'Use nonempty upstream usernames only');
checkTgSync($refresh([$group, $channel, $legacy]) === ['/chats', '/chats/-1003'], 'Reuse unchanged versions and reread chats without timestamps');

// Telegram profiles are global. A newly read name supersedes one from an older cached response.
$group = tgChat(-1001, 101, [11 => 'after']);
checkTgSync($refresh([$channel, $group]) === ['/chats', '/chats/-1001'], 'Only the changed roster needs a new request');
$users = $cache->users();
checkTgSync($users[11]['username'] === 'after', 'Changed username must replace the older cached profile');
checkTgSync(!isset($users[22]) && !isset($users[33]), 'Drop departed users and delisted chat memberships');

// Membership versions do not track Telegram profile edits. Re-read older profiles even for an unchanged version.
$cache->state['chats'][-1002]['fetched_at'] = (time() - 86401) * 1000000;
$channel = tgChat(-1002, 100, [11 => ''], 'channel');
checkTgSync($refresh([$group, $channel]) === ['/chats', '/chats/-1002'], 'An unchanged roster must eventually refresh its user profiles');
$users = $cache->users();
checkTgSync($users[11]['username'] === null, 'A newer empty profile must allow fallback to Grist');

$refresh([$group], ['/chats' => ['chats' => (object) []]], 0);
checkTgSync($cache->users() === $users, 'A malformed catalog object must not clear the last good snapshot');
$refresh([$group], ['/chats' => 503], 503);
checkTgSync($cache->users() === $users, 'A failed catalog request must retain a recent successful snapshot');
$cache->state['checked_at'] = time() - 86401;
$refresh([$group], ['/chats' => 503], 503);
checkTgSync($cache->users() === null, 'Failed refreshes must not extend the 24-hour window');
$refresh([$group, $channel]);
checkTgSync(isset($cache->users()[11]), 'Successful polling restores access after expiration');

$group = tgChat(-1001, 102, [11 => 'after']);
$refresh([$group], ['/chats/-1001' => 503], 503);
$users = $cache->users();
checkTgSync($users[11]['chats'] === ['-1001'], 'A failed detail request must preserve the allowed chat and drop the delisted one');
$refresh([$group, $channel], ['/chats/-1001' => 404]);
checkTgSync($cache->users()[11]['chats'] === ['-1002'], 'A 404 for a detail request must remove only that chat from cached results');

foreach ([['/chats', 401], ['/chats/-1001', 403]] as [$path, $status]) {
    $refresh([$group]);
    ++$group['data_received_at'];
    $refresh([$group], [$path => $status], $status);
    checkTgSync($cache->users() === null, 'An upstream denial must make even a recent snapshot unavailable immediately');
    $refresh([$group], ['/chats' => 503], 503);
    checkTgSync($cache->users() === null, 'A subsequent network failure must not revive a revoked snapshot');
}
$refresh([]);
checkTgSync($cache->users() === [], 'A successful empty directory is distinct from unavailable data');

fwrite(STDOUT, "tg-members sync and cache regression tests passed.\n");
