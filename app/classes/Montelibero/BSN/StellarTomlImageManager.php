<?php

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class StellarTomlImageManager
{
    public const PUBLIC_PREFIX = '/stellar-toml-images';
    public const STORAGE_ROOT = '/var/www/bsn/stellar-toml-images';

    private string $images_collection = 'stellar_toml_images';
    private string $refs_collection = 'stellar_toml_image_refs';

    public function __construct(
        private Manager $Mongo,
        private string $database,
    ) {
    }

    public function fetchImageByUrl(string $source_url): ?array
    {
        $doc = $this->fetchImageByUrlRaw($source_url);

        return $doc ? $this->normalizeMongoValue($doc) : null;
    }

    public function fetchImageByUrlRaw(string $source_url): ?array
    {
        $query = new Query(['image_id' => self::imageId($source_url)], ['limit' => 1]);
        $doc = current($this->Mongo->executeQuery($this->imagesNamespace(), $query)->toArray()) ?: null;

        return $doc ? get_object_vars($doc) : null;
    }

    public function fetchRefsForEntity(string $entity_type, string $entity_key): array
    {
        $query = new Query(
            [
                'entity_type' => $entity_type,
                'entity_key' => strtoupper($entity_key),
                'status' => 'ok',
                'public_path' => ['$ne' => null],
            ],
            ['sort' => ['updated_at' => -1]]
        );

        $result = [];
        foreach ($this->Mongo->executeQuery($this->refsNamespace(), $query) as $doc) {
            $result[] = $this->normalizeMongoValue($doc);
        }

        return $result;
    }

    public function fetchAccountImages(string $account_id): array
    {
        return $this->fetchRefsForEntity('account', strtoupper($account_id));
    }

    public function fetchTokenImages(string $code, string $issuer): array
    {
        return $this->fetchRefsForEntity('token', StellarTomlManager::tokenKey($code, $issuer));
    }

    public function fetchTokenImageMap(array $tokens): array
    {
        $entity_keys = [];
        foreach ($tokens as $token) {
            $code = (string) ($token['code'] ?? '');
            $issuer = (string) ($token['issuer'] ?? '');
            if ($code === '' || !BSN::validateStellarAccountIdFormat($issuer)) {
                continue;
            }
            $entity_keys[StellarTomlManager::tokenKey($code, $issuer)] = true;
        }

        if (!$entity_keys) {
            return [];
        }

        $query = new Query(
            [
                'entity_type' => 'token',
                'entity_key' => ['$in' => array_keys($entity_keys)],
                'role' => 'token_image',
                'status' => 'ok',
                'public_path' => ['$ne' => null],
            ],
            ['sort' => ['updated_at' => -1]]
        );

        $result = [];
        foreach ($this->Mongo->executeQuery($this->refsNamespace(), $query) as $doc) {
            $doc = $this->normalizeMongoValue($doc);
            $entity_key = (string) ($doc['entity_key'] ?? '');
            if ($entity_key !== '' && !isset($result[$entity_key])) {
                $result[$entity_key] = $doc;
            }
        }

        return $result;
    }

    public function applyTokenImages(array &$tokens): void
    {
        $images = $this->fetchTokenImageMap($tokens);
        foreach ($tokens as &$token) {
            $key = StellarTomlManager::tokenKey((string) ($token['code'] ?? ''), (string) ($token['issuer'] ?? ''));
            if (isset($images[$key]['public_path'])) {
                $token['image_path'] = $images[$key]['public_path'];
            }
        }
        unset($token);
    }

    public function applyTokenImage(array &$token): void
    {
        $tokens = [$token];
        $this->applyTokenImages($tokens);
        $token = $tokens[0];
    }

    public function saveImage(array $doc): array
    {
        $now = StellarTomlManager::now();
        unset($doc['_id']);
        unset($doc['created_at']);
        $doc['updated_at'] = $now;

        $bulk = new BulkWrite();
        $bulk->update(
            ['image_id' => $doc['image_id']],
            [
                '$set' => $doc,
                '$setOnInsert' => ['created_at' => $now],
            ],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite($this->imagesNamespace(), $bulk);

        return $doc;
    }

    public function replaceDomainRefs(string $home_domain, array $refs): void
    {
        $now = StellarTomlManager::now();
        $bulk = new BulkWrite();
        $bulk->delete(['home_domain' => $home_domain]);

        foreach ($refs as $ref) {
            $ref['home_domain'] = $home_domain;
            $ref['created_at'] = $now;
            $ref['updated_at'] = $now;
            $bulk->insert($ref);
        }

        $this->Mongo->executeBulkWrite($this->refsNamespace(), $bulk);
    }

    public function buildCacheTarget(string $image_id, string $output_hash): array
    {
        $filename = $image_id . '-' . substr($output_hash, 0, 12) . '.png';

        return [
            'file_path' => self::STORAGE_ROOT . '/cache/' . $filename,
            'public_path' => self::PUBLIC_PREFIX . '/cache/' . $filename,
        ];
    }

    public function buildEntityTarget(string $entity_type, string $entity_key, string $role, string $output_hash): array
    {
        $folder = $entity_type === 'token' ? 'tokens' : 'accounts';
        $slug = $this->entitySlug($entity_key);
        $filename = $slug . '-' . $role . '-' . substr($output_hash, 0, 12) . '.png';

        return [
            'file_path' => self::STORAGE_ROOT . '/' . $folder . '/' . $filename,
            'public_path' => self::PUBLIC_PREFIX . '/' . $folder . '/' . $filename,
        ];
    }

    public static function imageId(string $source_url): string
    {
        return hash('sha256', $source_url);
    }

    private function entitySlug(string $entity_key): string
    {
        $slug = strtoupper($entity_key);
        $slug = preg_replace('~[^A-Z0-9-]+~', '-', $slug) ?: 'entity';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'entity';
    }

    private function imagesNamespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->images_collection);
    }

    private function refsNamespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->refs_collection);
    }

    private function normalizeMongoValue(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            return [
                'iso' => $value->toDateTime()->format('Y-m-d H:i:s'),
                'ts' => $value->toDateTime()->getTimestamp(),
            ];
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeMongoValue($item);
        }

        return $value;
    }
}
