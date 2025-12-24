<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\ApiKeysManager;
use Pecee\SimpleRouter\SimpleRouter;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class ApiController
{
    private Environment $Twig;
    private ApiKeysManager $ApiKeysManager;
    private Translator $Translator;

    public function __construct(Environment $Twig, ApiKeysManager $ApiKeysManager, Translator $Translator)
    {
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->ApiKeysManager = $ApiKeysManager;
        $this->Translator = $Translator;
    }

    public function PreferencesApi(): ?string
    {
        if (empty($_SESSION['account'])) {
            SimpleRouter::response()->httpCode(401);
            return null;
        }

        $account_id = $_SESSION['account']['id'];
        $errors = [];
        $default_permissions = [
            'contacts' => [
                'read' => true,
                'create' => true,
                'update' => true,
                'delete' => false,
            ],
        ];
        $form_permissions = $default_permissions;
        $form_name = '';
        $flash_key = $_SESSION['api_key_flash'] ?? null;
        unset($_SESSION['api_key_flash']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? 'create';
            if ($action === 'delete') {
                $key_id = $_POST['key_id'] ?? '';
                if (!$key_id || !$this->ApiKeysManager->deleteKey($account_id, $key_id)) {
                    $errors[] = $this->Translator->trans('preferences.api.errors.delete_failed');
                } else {
                    SimpleRouter::response()->redirect('/preferences/api', 302);
                    return null;
                }
            } else {
                $name = trim($_POST['name'] ?? '');
                $form_name = $name;
                if ($name === '') {
                    $errors[] = $this->Translator->trans('preferences.api.errors.name_required');
                }
                $submitted = $_POST['permissions'] ?? [];
                $form_permissions = [
                    'contacts' => [
                        'read' => isset($submitted['contacts']['read']),
                        'create' => isset($submitted['contacts']['create']),
                        'update' => isset($submitted['contacts']['update']),
                        'delete' => isset($submitted['contacts']['delete']),
                    ],
                ];

                if (!$errors) {
                    $key = $this->ApiKeysManager->createKey($account_id, $name, $form_permissions);
                    $_SESSION['api_key_flash'] = $key['key'];
                    SimpleRouter::response()->redirect('/preferences/api', 302);
                    return null;
                }
            }
        }

        $keys = array_map(function ($key) use ($flash_key, $default_permissions) {
            $key['permissions']['contacts'] = array_merge(
                $default_permissions['contacts'],
                (array) ($key['permissions']['contacts'] ?? [])
            );
            $is_new = $flash_key && $flash_key === $key['key'];
            $can_view_full = $is_new || $key['last_used_at'] === null;
            $key['is_new'] = $is_new;
            $key['display_key'] = $can_view_full ? $key['key'] : $this->maskKey($key['key']);
            $key['show_full'] = $can_view_full;
            if (!$can_view_full) {
                $key['key'] = null;
            }
            return $key;
        }, $this->ApiKeysManager->getKeysByAccount($account_id));

        $Template = $this->Twig->load('preferences_api.twig');
        return $Template->render([
            'keys' => $keys,
            'errors' => $errors,
            'form_permissions' => $form_permissions,
            'form_name' => $form_name,
        ]);
    }

    public function ApiIndex(): string
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

        $key = $this->ApiKeysManager->findByKey($token);
        if (!$key) {
            SimpleRouter::response()->httpCode(403);
            return $this->jsonResponse(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $this->ApiKeysManager->markUsed($key["id"], $ip);
        $key['last_used_at'] = date('Y-m-d H:i:s');
        $key['last_used_at_ts'] = time();
        $key['last_ip'] = $ip;
        $key['key_masked'] = $this->maskKey($token);
        unset($key['key']);

        SimpleRouter::response()->httpCode(200);
        return $this->jsonResponse([
            'status' => 'OK',
            'key_details' => $key,
        ]);
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

    private function maskKey(?string $key): string
    {
        if (!$key) {
            return '';
        }
        if (strlen($key) <= 8) {
            return $key;
        }
        return substr($key, 0, 6) . '…' . substr($key, -4);
    }
}
