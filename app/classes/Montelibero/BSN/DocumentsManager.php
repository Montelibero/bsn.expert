<?php

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class DocumentsManager
{
    private const CACHE_KEY = 'contracts_hashes_data';
    private const CACHE_TTL = 3600;

    private Manager $Mongo;
    private string $database;
    private string $collection = 'documents';

    public function __construct(Manager $Mongo, string $database)
    {
        $this->Mongo = $Mongo;
        $this->database = $database;
    }

    public function getDocuments(): array
    {
        if ($this->cacheExists()) {
            $cached = apcu_fetch(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $documents = $this->fetchFromDb();
        if (empty($documents)) {
            $documents = $this->refreshFromGrist()['documents'];
        }

        $this->storeCache($documents);

        return $documents;
    }

    /**
     * @return array{documents: array<string, array>, count: int}
     */
    public function refreshFromGrist(): array
    {
        $grist_response = \gristRequest(
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

        $this->clearCache();
        $this->saveDocuments($documents);
        $this->storeCache($documents);

        return [
            'documents' => $documents,
            'count' => count($documents),
        ];
    }

    public function clearCache(): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete(self::CACHE_KEY);
        }
    }

    private function fetchFromDb(): array
    {
        $query = new Query([], ['projection' => ['_id' => 0]]);
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
            $Bulk->update(
                ['hash' => $document['hash']],
                [
                    '$set' => [
                        'hash' => $document['hash'],
                        'name' => $document['name'],
                        'type' => $document['type'],
                        'url' => $document['url'],
                        'text' => $document['text'],
                        'is_obsolete' => $document['is_obsolete'],
                        'new_hash' => $document['new_hash'],
                        'source' => $document['source'],
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

    private function cacheExists(): bool
    {
        return function_exists('apcu_exists') && apcu_exists(self::CACHE_KEY);
    }

    private function storeCache(array $documents): void
    {
        if (function_exists('apcu_store')) {
            apcu_store(self::CACHE_KEY, $documents, self::CACHE_TTL);
        }
    }
}
