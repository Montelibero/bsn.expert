<?php

namespace Montelibero\BSN\Controllers;

use JsonException;
use MongoDB\BSON\UTCDateTime;
use Montelibero\BSN\ApiKeysManager;
use Montelibero\BSN\BSN;
use Montelibero\BSN\ContactsManager;
use Pecee\SimpleRouter\SimpleRouter;

class ApiContactsController
{
    const TIMESTAMP_TOLERANCE_MS = 5000;
    private ApiKeysManager $ApiKeysManager;
    private ContactsManager $ContactsManager;

    private array $key;

    public function __construct(ApiKeysManager $ApiKeysManager, ContactsManager $ContactsManager)
    {
        $this->ApiKeysManager = $ApiKeysManager;
        $this->ContactsManager = $ContactsManager;
    }

    private function checkAuth()
    {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!$auth_header || stripos($auth_header, 'Bearer ') !== 0) {
            SimpleRouter::response()->httpCode(401);
            return $this->jsonResponse(['status' => 'error', 'message' => 'Missing Bearer token']);
        }

        $token = trim(substr($auth_header, 7));
        if ($token === '') {
            SimpleRouter::response()->httpCode(401);
            return $this->jsonResponse(['status' => 'error', 'message' => 'Missing Bearer token']);
        }

        $this->key = $this->ApiKeysManager->findByKey($token);
        if (!$this->key) {
            SimpleRouter::response()->httpCode(401);
            return $this->jsonResponse(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $this->ApiKeysManager->markUsed($this->key["id"], $_SERVER['REMOTE_ADDR'] ?? 'unknown');

        if (!$this->key['permissions']['contacts'] ?? null) {
            SimpleRouter::response()->httpCode(403);
            return $this->jsonResponse(['status' => 'error', 'message' => 'API key does not have contacts permissions']);
        }

        return true;
    }

    public function Sync(): string
    {
        if (($result = $this->checkAuth()) !== true) {
            return $result;
        }

        $account_id = $this->key['account_id'];
        $permissions = $this->key['permissions']['contacts'];

        $request = file_get_contents('php://input');
        try {
            $request_data = json_decode($request, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $E) {
            SimpleRouter::response()->httpCode(400);
            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'Invalid JSON request',
                'message_extra' => $E->getMessage(),
            ]);
        }

        if (!($request_data['current_timestamp'] ?? null)) {
            SimpleRouter::response()->httpCode(400);
            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'Missing `current_timestamp`',
            ]);
        }

        $current_timestamp = (int) (microtime(true) * 1000);

        if (abs($current_timestamp - $request_data['current_timestamp']) > self::TIMESTAMP_TOLERANCE_MS) {
            SimpleRouter::response()->httpCode(400);
            return $this->jsonResponse([
                'status' => 'error',
                'message' => '`current_timestamp` is too far from current time',
                'message_extra' => "Our $current_timestamp, your {$request_data['current_timestamp']}",
            ]);
        }

        if (array_key_exists('items', $request_data) && !is_array($request_data['items'])) {
            SimpleRouter::response()->httpCode(400);
            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'Wrong `items` type',
            ]);
        }
        $new_items = $request_data['items'] ?? [];

        $contacts = $this->ContactsManager->getAllItems($account_id);

        $errors = [];

        // Read new data from the client
        $to_add = [];
        $to_update = [];
        $to_delete = [];
        foreach ($new_items as $address => $new_item) {
            if (($error = $this->validateSyncItem($address, $new_item)) !== true) {
                $errors[] = $error;
                continue;
            }

            // Read
            $exists_item = $contacts[$address] ?? null;
            if ($new_item['label'] !== null
                && (
                    !$exists_item
                    || (
                        $exists_item['label'] === null
                        && $new_item['updated_at'] > $exists_item['updated_at']
                    )
                )
            ) {
                $to_add[$address] = $new_item;
            } else if ($new_item['label'] === null
                && $exists_item
                && $exists_item['label'] !== null
                && $new_item['updated_at'] > $exists_item['updated_at']
            ) {
                $to_delete[$address] = $new_item;
            } else if ($new_item['label'] !== null
                && $exists_item
                && $exists_item['label'] !== null
                && $new_item['updated_at'] > $exists_item['updated_at']
                && $new_item['label'] !== $exists_item['label']
            ) {
                $to_update[$address] = $new_item;
            }
        }

        $report = [
            'errors' => $errors,
            'permissions' => $permissions,
            'added' => [],
            'not_added' => [],
            'updated' => [],
            'not_updated' => [],
            'deleted' => [],
            'not_deleted' => [],
        ];

        $bulk_update = [];
        if ($to_add) {
            if ($permissions['create']) {
                $bulk_update = array_merge($bulk_update, $to_add);
                $report['added'] = array_keys($to_add);
            } else {
                $report['not_added'] = array_keys($to_add);
            }
        }
        if ($to_update) {
            if ($permissions['update']) {
                $bulk_update = array_merge($bulk_update, $to_update);
                $report['updated'] = array_keys($to_update);
            } else {
                $report['not_updated'] = array_keys($to_update);
            }
        }
        if ($to_delete) {
            if ($permissions['delete']) {
                $bulk_update = array_merge($bulk_update, $to_delete);
                $report['deleted'] = array_keys($to_delete);
            } else {
                $report['not_deleted'] = array_keys($to_delete);
            }
        }

        if ($bulk_update) {
            $normalized_bulk_update = [];
            foreach ($bulk_update as $address => $item) {
                $normalized_bulk_update[$address] = [
                    'name' => $item['label'],
                    'updated_at' => new UTCDateTime($item['updated_at']),
                ];
            }
            $this->ContactsManager->bulkUpdate($account_id, $normalized_bulk_update);
        }

        // Tell new data to the client
        $last_sync_at = $this->key['last_succeed_contacts_sync_at'] ?? 0;

        $response = [
            'status' => 'OK',
            'report' => $report,
        ];

        if ($permissions['read']) {
            $response['items'] = [];
            foreach ($contacts as $address => $item) {
                if ($item['updated_at'] > $last_sync_at) {
                    $response['items'][$address] = $item;
                }
            }
        }

        $this->updateLastSyncAt();

        return $this->jsonResponse($response);
    }

    private function jsonResponse(array $data): string
    {
        header('Access-Control-Allow-Origin *');
        header('Content-Type: application/json');

        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function validateSyncItem(mixed $address, mixed $data): bool|string
    {
        if (!BSN::validateStellarAccountIdFormat($address)) {
            return "Invalid address: $address";
        }
        if (!is_array($data)) {
            return "Invalid item value for address: $address";
        }
        if (!array_key_exists('updated_at', $data)) {
            return "Missing item's `updated_at` for address: $address";
        }
        if (!is_int($data['updated_at'])) {
            return "Invalid item's `updated_at` for address: $address";
        }
        if (!array_key_exists('label', $data)) {
            return "Missing item's `label` for address: $address";
        }
        if ($data['label'] !== null && !is_string($data['label'])) {
            return "Invalid item's `label` type for address: $address";
        }

        return true;
    }

    private function updateLastSyncAt(): void
    {
        $this->ApiKeysManager->updateKey($this->key['id'], [
            'last_succeed_contacts_sync_at' => new UTCDateTime((int) (microtime(true) * 1000)),
        ]);
    }
}
