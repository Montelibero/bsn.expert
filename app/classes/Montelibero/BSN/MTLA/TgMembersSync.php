<?php

declare(strict_types=1);

namespace Montelibero\BSN\MTLA;

use Montelibero\BSN\GristSyncService;
use RuntimeException;

class TgMembersSync
{
    public function __construct(
        private readonly TgMembersCache $cache,
        private readonly TgMembersClient $client,
    ) {
    }

    public function sync(): void
    {
        $run = bin2hex(random_bytes(16));
        $previous = $this->cache->begin($run);
        if ($previous === null) {
            return;
        }
        try {
            $versions = $this->chatVersions($this->client->get('/chats'));
            // Drop chats removed from the key immediately, even if a later fetch fails.
            $fallback = array_intersect_key($previous['chats'], $versions);
            if (count($fallback) !== count($previous['chats'])) {
                $this->cache->publish($run, $fallback, $previous['checked_at']);
            }

            $next = [];
            $refresh_profiles_before = (time() - TgMembersCache::MAX_AGE) * 1000000;
            foreach ($versions as $id => $version) {
                $cached = $fallback[$id] ?? null;
                if ($cached !== null && $version !== null && $cached['version'] === $version
                    && $cached['fetched_at'] > $refresh_profiles_before) {
                    $next[$id] = $cached;
                    continue;
                }
                try {
                    $detail = $this->client->get('/chats/' . $id);
                } catch (RuntimeException $e) {
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                    // Access to this chat disappeared between listing and fetching it.
                    if (isset($fallback[$id])) {
                        unset($fallback[$id]);
                        $this->cache->publish($run, $fallback, $previous['checked_at']);
                    }
                    continue;
                }
                $next[$id] = $this->parseChat((string) $id, $detail);
            }
        } catch (RuntimeException $e) {
            if (in_array($e->getCode(), [401, 403], true)) {
                $this->cache->revoke();
            }
            throw $e;
        }
        $this->cache->publish($run, $next, time());
    }

    private function chatVersions(array $payload): array
    {
        if (!is_array($payload['chats'] ?? null) || !array_is_list($payload['chats'])) {
            throw new RuntimeException('Invalid TG Members chat list.');
        }
        $versions = [];
        foreach ($payload['chats'] as $chat) {
            if (!is_string($chat['type'] ?? null)) {
                throw new RuntimeException('Invalid TG Members chat type.');
            }
            if (!in_array($chat['type'], ['group', 'channel'], true)) {
                continue;
            }
            $id = $chat['id'] ?? null;
            if ((!is_int($id) && !is_string($id)) || !preg_match('/\A-?[1-9][0-9]*\z/', (string) $id)
                || array_key_exists($id, $versions)) {
                throw new RuntimeException('Invalid TG Members chat ID.');
            }
            $versions[$id] = $this->version($chat);
        }
        ksort($versions, SORT_STRING);
        return $versions;
    }

    private function parseChat(string $id, array $detail): array
    {
        $detail_id = $detail['id'] ?? null;
        if ((!is_int($detail_id) && !is_string($detail_id)) || (string) $detail_id !== $id
            || !is_array($detail['members'] ?? null)
            || !array_is_list($detail['members'])) {
            throw new RuntimeException('Invalid TG Members chat detail.');
        }
        $members = [];
        foreach ($detail['members'] as $member) {
            $user_id = GristSyncService::normalizeTelegramId($member['id'] ?? null);
            $username = $member['username'] ?? '';
            if ($user_id === null || !is_string($username) || array_key_exists($user_id, $members)) {
                throw new RuntimeException('Invalid TG Members user.');
            }
            $username = trim($username, "@ \t\r\n");
            $members[$user_id] = $username === '' ? null : $username;
        }
        return [
            'version' => $this->version($detail),
            'fetched_at' => (int) (microtime(true) * 1000000),
            'members' => $members,
        ];
    }

    private function version(array $chat): ?int
    {
        if (!array_key_exists('data_received_at', $chat)
            || ($chat['data_received_at'] !== null && !is_int($chat['data_received_at']))) {
            throw new RuntimeException('Invalid TG Members snapshot timestamp.');
        }
        return $chat['data_received_at'];
    }
}
