<?php

namespace Montelibero\BSN;

use \PDO;

class ContactsManager
{
    private $tg_id;
    private $PDO;
    
    private $contacts = [];

    public function __construct($tg_id)
    {
        $this->tg_id = $tg_id;

        $this->PDO = new PDO(
            'mysql:host=' . $_ENV['MYSQL_HOST'] . ';dbname=' . $_ENV['MYSQL_BASENAME'],
            $_ENV['MYSQL_USERNAME'],
            $_ENV['MYSQL_PASSWORD']
        );
    }

    public function getContacts(): array
    {
        $stmt = $this->PDO->prepare('SELECT * FROM contacts WHERE telegram_id = :telegram_id');
        $stmt->bindParam(':telegram_id', $this->tg_id);
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

    public function addContact(string $stellar_account, ?string $name = null): void
    {
        $stmt = $this->PDO->prepare('INSERT INTO contacts (telegram_id, stellar_address, name) VALUES (:telegram_id, :stellar_address, :name)');
        $stmt->bindParam(':telegram_id', $this->tg_id);
        $stmt->bindParam(':stellar_address', $stellar_account);
        $stmt->bindParam(':name', $name);
        $stmt->execute();
    }

    public function updateContact(string $stellar_account, ?string $name)
    {
        $stmt = $this->PDO->prepare('UPDATE contacts SET name = :name WHERE telegram_id = :telegram_id AND stellar_address = :stellar_address');
        $stmt->bindParam(':telegram_id', $this->tg_id);
        $stmt->bindParam(':stellar_address', $stellar_account);
        $stmt->bindParam(':name', $name);
        $stmt->execute();
    }
}