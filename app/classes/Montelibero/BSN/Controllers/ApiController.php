<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\ApiAuthenticationException;
use Montelibero\BSN\ApiKeysManager;
use Montelibero\BSN\ApiTokenAuthenticator;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\RequestSession;
use Pecee\SimpleRouter\SimpleRouter;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class ApiController
{
    private const CSRF_PURPOSE = 'csrf:api_keys';
    private const CREATE_NONCE_SESSION_KEY = 'api_key_create_nonce';

    private Environment $Twig;
    private ApiKeysManager $ApiKeysManager;
    private ApiTokenAuthenticator $ApiTokenAuthenticator;
    private Translator $Translator;
    private CurrentUser $CurrentUser;
    private RequestSession $RequestSession;

    public function __construct(
        Environment $Twig,
        ApiKeysManager $ApiKeysManager,
        ApiTokenAuthenticator $ApiTokenAuthenticator,
        Translator $Translator,
        CurrentUser $CurrentUser,
        RequestSession $RequestSession,
    ) {
        $this->Twig = $Twig;

        $this->ApiKeysManager = $ApiKeysManager;
        $this->ApiTokenAuthenticator = $ApiTokenAuthenticator;
        $this->Translator = $Translator;
        $this->CurrentUser = $CurrentUser;
        $this->RequestSession = $RequestSession;
    }

    public function PreferencesApi(): ?string
    {
        $this->noStoreHeaders();

        if (!$this->CurrentUser->isAuthorized()) {
            SimpleRouter::response()->httpCode(401);
            return null;
        }

        $account_id = $this->CurrentUser->getAccountId();
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
        $created_token = null;
        $csrf_token = $this->csrfToken();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? 'create';
            if (!$this->validCsrf($_POST['csrf_token'] ?? null)) {
                SimpleRouter::response()->httpCode(403);
                $errors[] = $this->Translator->trans('preferences.api.errors.csrf_invalid');
            } elseif ($action === 'delete') {
                $key_id = $this->scalarString($_POST['key_id'] ?? null);
                if ($key_id === '' || !$this->ApiKeysManager->deleteKey($account_id, $key_id)) {
                    $errors[] = $this->Translator->trans('preferences.api.errors.delete_failed');
                } else {
                    SimpleRouter::response()->redirect('/preferences/api', 302);
                    return null;
                }
            } elseif ($action === 'create') {
                if (!$this->consumeCreateNonce($_POST['create_nonce'] ?? null)) {
                    SimpleRouter::response()->httpCode(409);
                    $errors[] = $this->Translator->trans('preferences.api.errors.create_expired');
                }

                $name = $this->scalarString($_POST['name'] ?? null, trim: true);
                $form_name = $name;
                if ($name === '') {
                    $errors[] = $this->Translator->trans('preferences.api.errors.name_required');
                }
                $submitted = $_POST['permissions'] ?? [];
                if (!is_array($submitted)) {
                    $submitted = [];
                }
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
                    $created_token = $key['key'];
                }
            } else {
                SimpleRouter::response()->httpCode(400);
                $errors[] = $this->Translator->trans('preferences.api.errors.unknown_action');
            }
        }

        $keys = array_map(function ($key) use ($created_token, $default_permissions) {
            $key['permissions']['contacts'] = array_merge(
                $default_permissions['contacts'],
                (array) ($key['permissions']['contacts'] ?? [])
            );
            $stored_token = is_string($key['key'] ?? null) ? $key['key'] : null;
            $is_new = $created_token !== null
                && $stored_token !== null
                && hash_equals($created_token, $stored_token);
            $key['is_new'] = $is_new;
            $key['display_key'] = $is_new
                ? $created_token
                : ApiTokenAuthenticator::maskToken($stored_token);
            $key['show_full'] = $is_new;
            unset($key['key']);

            return $key;
        }, $this->ApiKeysManager->getKeysByAccount($account_id));

        $Template = $this->Twig->load('preferences_api.twig');
        return $Template->render([
            'keys' => $keys,
            'errors' => $errors,
            'form_permissions' => $form_permissions,
            'form_name' => $form_name,
            'csrf_token' => $csrf_token,
            'create_nonce' => $this->createNonce(),
        ]);
    }

    public function ApiIndex(): string
    {
        $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        try {
            $Principal = $this->ApiTokenAuthenticator->authenticate($this->authorizationHeader(), $ip);
        } catch (ApiAuthenticationException $Exception) {
            return $this->unauthorized($Exception->getMessage());
        }

        $key = $Principal->details();
        $key['last_used_at'] = date('Y-m-d H:i:s');
        $key['last_used_at_ts'] = time();
        $key['last_ip'] = $ip;

        SimpleRouter::response()->httpCode(200);
        return $this->jsonResponse([
            'status' => 'OK',
            'key_details' => $key,
        ]);
    }

    private function jsonResponse(array $data): string
    {
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function authorizationHeader(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        return is_string($header) ? $header : null;
    }

    private function unauthorized(string $message): string
    {
        SimpleRouter::response()->httpCode(401);
        SimpleRouter::response()->header('WWW-Authenticate: Bearer realm="bsn"');

        return $this->jsonResponse(['status' => 'error', 'message' => $message]);
    }

    private function csrfToken(): string
    {
        return $this->RequestSession->getOrCreateToken(self::CSRF_PURPOSE);
    }

    private function validCsrf(mixed $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->csrfToken(), $token);
    }

    private function createNonce(): string
    {
        $nonce = $this->RequestSession->get(self::CREATE_NONCE_SESSION_KEY);
        if (is_string($nonce) && preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
            return $nonce;
        }

        $nonce = bin2hex(random_bytes(32));
        $this->RequestSession->set(self::CREATE_NONCE_SESSION_KEY, $nonce);

        return $nonce;
    }

    private function consumeCreateNonce(mixed $submitted_nonce): bool
    {
        $expected_nonce = $this->RequestSession->consume(self::CREATE_NONCE_SESSION_KEY);
        return is_string($submitted_nonce)
            && is_string($expected_nonce)
            && hash_equals($expected_nonce, $submitted_nonce);
    }

    private function scalarString(mixed $value, bool $trim = false): string
    {
        $result = is_scalar($value) ? (string) $value : '';
        return $trim ? trim($result) : $result;
    }

    private function noStoreHeaders(): void
    {
        SimpleRouter::response()->header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        SimpleRouter::response()->header('Pragma: no-cache');
        SimpleRouter::response()->header('Referrer-Policy: no-referrer');
        SimpleRouter::response()->header('X-Content-Type-Options: nosniff');
    }
}
