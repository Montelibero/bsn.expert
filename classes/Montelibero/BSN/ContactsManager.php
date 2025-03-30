<?php

namespace Montelibero\BSN;

use \PDO;

class ContactsManager
{
    private $account_id;
    private $PDO;
    
    private $contacts = [];

    public function __construct($account_id)
    {
        $this->account_id = $account_id;

        $this->PDO = new PDO(
            'mysql:host=' . $_ENV['MYSQL_HOST'] . ';dbname=' . $_ENV['MYSQL_BASENAME'],
            $_ENV['MYSQL_USERNAME'],
            $_ENV['MYSQL_PASSWORD']
        );
    }

    public function getContacts(?string $stellar_address = null): array
    {
        $sql = 'SELECT * FROM contacts WHERE account_id = :account_id';
        if ($stellar_address) {
            $sql .= ' AND stellar_address = :stellar_address';
        }
        $stmt = $this->PDO->prepare($sql);
        $stmt->bindParam(':account_id', $this->account_id);
        if ($stellar_address) {
            $stmt->bindParam(':stellar_address', $stellar_address);
        }
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $item = [
                'name' => $row['name'],
                'time' => $row['updated_at'],
            ];
            $this->contacts[$row['stellar_address']] = $item;
        }

        return $this->contacts;
    }

    public function getContact($id): ?array
    {
        $contacts = $this->getContacts($id);

        return $contacts ? $contacts[$id] : null;
    }

    public function addContact(string $stellar_account, ?string $name = null): void
    {
        $stmt = $this->PDO->prepare('INSERT INTO contacts (account_id, stellar_address, name) VALUES (:account_id, :stellar_address, :name)');
        $stmt->bindParam(':account_id', $this->account_id);
        $stmt->bindParam(':stellar_address', $stellar_account);
        $stmt->bindParam(':name', $name);
        $stmt->execute();
    }

    public function updateContact(string $stellar_account, ?string $name)
    {
        $stmt = $this->PDO->prepare('UPDATE contacts SET name = :name WHERE account_id = :account_id AND stellar_address = :stellar_address');
        $stmt->bindParam(':account_id', $this->account_id);
        $stmt->bindParam(':stellar_address', $stellar_account);
        $stmt->bindParam(':name', $name);
        $stmt->execute();
    }

    public function deleteContact($stellar_account)
    {
        $stmt = $this->PDO->prepare('DELETE FROM contacts WHERE account_id = :account_id AND stellar_address = :stellar_address');
        $stmt->bindParam(':account_id', $this->account_id);
        $stmt->bindParam(':stellar_address', $stellar_account);
        $stmt->execute();
    }
}