<?php

namespace Montelibero\BSN;

use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class AccountsManager
{
    private Manager $Mongo;
    private string $database;
    private string $collection = 'usernames';
    private bool $isReadOnly;

    const USERNAME_REGEX = '/^[a-zA-Z0-9_]+$/';

    public function __construct(Manager $Mongo, string $database, bool $isReadOnly = false)
    {
        $this->Mongo = $Mongo;
        $this->database = $database;
        $this->isReadOnly = $isReadOnly;
    }

    public function fetchUsername(string $account_id): ?string
    {
        $filter = [
            'account_id' => $account_id,
            'is_current' => true,
        ];
        $query = new Query($filter, ['limit' => 1, 'sort' => ['created_at' => -1]]);
        $cursor = $this->Mongo->executeQuery($this->namespace(), $query);
        $doc = current($cursor->toArray());
        return $doc->username ?? null;
    }

    public function fetchUsernames(): array
    {
        $result = [];
        $query = new Query(
            ['is_current' => true],
            ['projection' => ['account_id' => 1, 'username' => 1]]
        );
        $cursor = $this->Mongo->executeQuery($this->namespace(), $query);
        foreach ($cursor as $doc) {
            $result[$doc->account_id] = $doc->username;
        }
        return $result;
    }

    public function fetchAccountIdByUsername(string $username): ?string
    {
        $filter = ['username' => $username];
        $query = new Query($filter, [
            'limit' => 1,
            'collation' => ['locale' => 'en', 'strength' => 2],
        ]);
        $cursor = $this->Mongo->executeQuery($this->namespace(), $query);
        $doc = current($cursor->toArray());
        return $doc->account_id ?? null;
    }

    public static function validateUsername($text): bool
    {
        return preg_match(self::USERNAME_REGEX, $text);
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->collection);
    }
}
