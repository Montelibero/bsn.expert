<?php
namespace Montelibero\BSN;

use PDO;
use SessionHandlerInterface;

class PdoSessionHandler implements SessionHandlerInterface
{
    private PDO $PDO;
    private int $ttl_seconds;

    public function __construct(PDO $pdo, int $ttlSeconds)
    {
        $this->PDO = $pdo;
        $this->ttl_seconds = $ttlSeconds;
    }

    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string
    {
        $stmt = $this->PDO->prepare('SELECT `data` FROM `sessions` WHERE `id` = :id AND `expires_at` > :now LIMIT 1');
        $stmt->execute([':id' => $id, ':now' => time()]);
        $row = $stmt->fetch();
        if (!$row) {
            return '';
        }
        $data = $row['data'];
        if (is_resource($data)) {
            return stream_get_contents($data) ?: '';
        }
        return (string) $data;
    }

    public function write($id, $data): bool
    {
        $expiresAt = time() + $this->ttl_seconds;
        $stmt = $this->PDO->prepare(
            'INSERT INTO `sessions` (`id`, `data`, `expires_at`) VALUES (:id, :data, :expires) ON DUPLICATE KEY UPDATE `data` = VALUES(`data`), `expires_at` = VALUES(`expires_at`)'
        );
        return $stmt->execute([':id' => $id, ':data' => $data, ':expires' => $expiresAt]);
    }

    public function destroy($id): bool
    {
        $stmt = $this->PDO->prepare('DELETE FROM `sessions` WHERE `id` = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function gc($max_lifetime): false|int
    {
        $stmt = $this->PDO->prepare('DELETE FROM `sessions` WHERE `expires_at` < :now');
        $stmt->execute([':now' => time()]);
        return $stmt->rowCount();
    }
}
