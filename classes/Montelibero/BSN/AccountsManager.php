<?php

namespace Montelibero\BSN;

use PDO;

class AccountsManager
{
    private PDO $PDO;

    public function __construct(PDO $PDO)
    {
        $this->PDO = $PDO;
    }

    public function fetchUsername(string $account_id): ?string
    {
        $sql = 'SELECT username FROM usernames 
                WHERE account_id = :account_id AND the_last = TRUE 
                ORDER BY created_at DESC 
                LIMIT 1;';
        $stmt = $this->PDO->prepare($sql);
        $stmt->bindParam(':account_id', $account_id);
        $stmt->execute();

        return $stmt->fetchColumn();
    }

    public function fetchUsernames(): array
    {
        $sql = 'SELECT account_id, username FROM usernames 
                WHERE the_last = TRUE 
                ORDER BY created_at DESC;';
        $stmt = $this->PDO->prepare($sql);
        $stmt->execute();

        $result = [];

        while ($row = $stmt->fetch()) {
            $result[$row['account_id']] = $row['username'];
        }

        return $result;
    }

    public function fetchAccountIdByUsername(string $username): ?string
    {
        $sql = 'SELECT account_id FROM usernames WHERE username = :username;';
        $stmt = $this->PDO->prepare($sql);
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        return $stmt->fetchColumn();
    }
}