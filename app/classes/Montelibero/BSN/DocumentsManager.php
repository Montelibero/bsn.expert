<?php

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

use function gristRequest;

class DocumentsManager
{
    private Manager $Mongo;
    private string $database;
    private string $collection = 'documents';
    private bool $isReadOnly;

    public function __construct(Manager $Mongo, string $database, bool $isReadOnly = false)
    {
        $this->Mongo = $Mongo;
        $this->database = $database;
        $this->isReadOnly = $isReadOnly;
    }

    public function getDocuments(?string $source = null): array
    {
        $documents = $this->fetchFromDb($source);

        if (!$source && empty($documents)) {
            $this->refreshFromGrist();
        }

        return $documents;
    }

    /**
     * @return array{documents: array<string, array>, count: int}
     */
    public function refreshFromGrist(): array
    {
        if ($this->isReadOnly) {
            throw new \Exception('Database is in read-only mode.');
        }

        $grist_response = gristRequest(
            'https://montelibero.getgrist.com/api/docs/4ZvHAqR5wB33KedcdjQC1r/tables/Hashes/records',
            'GET'
        );
        $grist_records = [];
        foreach ($grist_response['records'] as $item) {
            $grist_records[$item['id']] = $item['fields'];
        }

        $documents = [];
        foreach ($grist_records as $row) {
            $documents[$row['Hash']] = [
                'hash' => $row['Hash'],
                'name' => $row['Name'] ?? null,
                'type' => $row['Type'] ?? null,
                'url' => $row['Document_URL'] ?? null,
                'text' => $row['Document_Text'] ?? null,
                'is_obsolete' => (bool) ($row['Obsolete'] ?? false),
                'new_hash' => ($row['Obsolete'] && $row['New_version'])
                    ? ($grist_records[$row['New_version']]['Hash'] ?? null)
                    : null,
                'source' => 'grist',
            ];
        }

        $this->saveDocuments($documents);

        return [
            'documents' => $documents,
            'count' => count($documents),
        ];
    }

    public function getDocument(string $hash): ?array
    {
        $hash = strtolower($hash);
        $documents = $this->fetchFromDb(null, $hash);

        return $documents[$hash] ?? null;
    }

    public function upsertDocument(array $document): ?array
    {
        if ($this->isReadOnly) {
            throw new \Exception('Database is in read-only mode.');
        }

        if (!isset($document['hash'])) {
            return null;
        }

        $this->saveDocuments([$document['hash'] => $document]);
        $documents = $this->fetchFromDb();

        return $documents[$document['hash']] ?? null;
    }

    private function fetchFromDb(?string $source = null, ?string $hash = null): array
    {
        $filter = [];
        if ($source !== null) {
            $filter['source'] = $source;
        }
        if ($hash !== null) {
            $filter['hash'] = strtolower($hash);
        }

        $options = [
            'projection' => ['_id' => 0],
            'sort' => ['created_at' => -1, 'updated_at' => -1],
        ];
        if ($hash !== null) {
            $options['limit'] = 1;
        }

        $query = new Query($filter, $options);
        $cursor = $this->Mongo->executeQuery($this->namespace(), $query);
        $documents = [];

        foreach ($cursor as $item) {
            $item = (array) $item;
            $documents[$item['hash']] = [
                'hash' => $item['hash'],
                'name' => $item['name'] ?? null,
                'type' => $item['type'] ?? null,
                'url' => $item['url'] ?? null,
                'text' => $item['text'] ?? null,
                'is_obsolete' => (bool) ($item['is_obsolete'] ?? false),
                'new_hash' => $item['new_hash'] ?? null,
                'source' => $item['source'] ?? null,
            ];
        }

        return $documents;
    }

    private function saveDocuments(array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $Now = new UTCDateTime((int) (microtime(true) * 1000));
        $Bulk = new BulkWrite();
        foreach ($documents as $document) {
            $document['hash'] = strtolower($document['hash']);
            $Bulk->update(
                ['hash' => $document['hash']],
                [
                    '$set' => [
                        'hash' => $document['hash'],
                        'name' => $document['name'] ?? null,
                        'type' => $document['type'] ?? null,
                        'url' => $document['url'] ?? null,
                        'text' => $document['text'] ?? null,
                        'is_obsolete' => (bool) ($document['is_obsolete'] ?? false),
                        'new_hash' => $document['new_hash'] ?? null,
                        'source' => $document['source'] ?? null,
                        'updated_at' => $Now,
                    ],
                    '$setOnInsert' => [
                        'created_at' => $Now,
                    ],
                ],
                ['upsert' => true]
            );
        }
        $this->Mongo->executeBulkWrite(
            $this->namespace(),
            $Bulk
        );
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->collection);
    }
}
