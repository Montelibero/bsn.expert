<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\ContactsManager;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Pecee\SimpleRouter\SimpleRouter;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class ContactsController
{
    private BSN $BSN;
    private Environment $Twig;
    private Translator $Translator;
    private ContactsManager $ContactsManager;

    public function __construct(
        BSN $BSN,
        Environment $Twig,
        Translator $Translator,
        ContactsManager $ContactsManager,
        private readonly CurrentUser $CurrentUser,
        private readonly CurrentContacts $CurrentContacts,
    ) {
        $this->BSN = $BSN;

        $this->Twig = $Twig;

        $this->Translator = $Translator;
        $this->ContactsManager = $ContactsManager;
    }

    public function Contacts(): ?string
    {
        $is_json_request = $this->isJsonContactsRequest();

        $current_account_id = $this->CurrentUser->getAccountId();
        if ($current_account_id === null) {
            if ($is_json_request) {
                $this->setJsonSecurityHeaders();
                SimpleRouter::response()->header('Content-Type: application/json; charset=utf-8');
                SimpleRouter::response()->httpCode(401);
                return json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
            }
            SimpleRouter::response()->redirect(LoginController::getLoginUrlForCurrentRequest('/contacts/'), 302);
            return null;
        }

        $contacts = $this->ContactsManager->getContacts($current_account_id);

        if ($is_json_request) {
            $this->setJsonSecurityHeaders();
            SimpleRouter::response()->header('Content-Type: application/json; charset=utf-8');
            return $this->jsonContactsResponse($contacts);
        }

        foreach ($contacts as $stellar_account => &$contact) {
            $Account = $this->BSN->makeAccountById($stellar_account);
            $contact = $this->CurrentContacts->serialize($Account, ignore_contact: true) + [
                'ignore_contact' => true,
            ] + $contact;
        }
        unset($contact);

        uasort($contacts, function ($a, $b) {
            if ($a['name'] === $b['name']) {
                return 0;
            }
            return $a['name'] < $b['name'] ? -1 : 1;
        });

        $errors = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (
                ($_POST['new_stellar_account_1'] ?? null)
                && BSN::validateStellarAccountIdFormat($_POST['new_stellar_account_1'])
                && !array_key_exists($_POST['new_stellar_account_1'], $contacts)
            ) {
                $this->ContactsManager->addContact(
                    $current_account_id,
                    $_POST['new_stellar_account_1'],
                    $_POST['new_name_1'] ?: ''
                );
                $this->CurrentContacts->refresh();
            }

            if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
                $duplicates = $_POST['duplicates'] ?? 'ignore';
                $data = file_get_contents($_FILES['import_file']['tmp_name']);
                $data = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                    $data = [];
                }
                $new_accounts = [];
                foreach ($data as $address => $item) {
                    $address = trim(strtoupper($address));
                    $name = is_array($item) && array_key_exists('label', $item) ? $item['label'] : $item;
                    if (array_key_exists($address, $contacts)) {
                        if ($duplicates === 'update' && $name !== $contacts[$address]['name']) {
                            try {
                                $this->ContactsManager->updateContact($current_account_id, $address, $name);
                                $this->CurrentContacts->refresh();
                            } catch (\Exception $e) {
                                $errors[] = "Не смог обновить контакт $address: {$e->getMessage()}";
                            }
                        }
                    } elseif (!in_array($address, $new_accounts, true)) {
                        try {
                            $this->ContactsManager->addContact($current_account_id, $address, $name ?: '');
                            $this->CurrentContacts->refresh();
                            $new_accounts[] = $address;
                        } catch (\Exception $e) {
                            $errors[] = "Не смог добавить контакт $address: {$e->getMessage()}";
                        }
                    }
                }
            }
            if (!$errors) {
                SimpleRouter::response()->redirect('/contacts', 302);
                return null;
            }
        }

        if (($_GET['export'] ?? null) === 'json') {
            $this->setJsonSecurityHeaders();
            header('Content-Disposition: attachment; filename="contacts.json"');
            header('Content-Type: application/json');

            $formatted_contacts = [];
            foreach ($contacts as $key => $contact) {
                $item = [];
                if (!empty($contact['name'])) {
                    $item['label'] = $contact['name'];
                }
                $formatted_contacts[$key] = $item;
            }

            return json_encode($formatted_contacts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            $Template = $this->Twig->load('contacts.twig');
            return $Template->render([
                'contacts' => $contacts,
                'errors' => $errors,
            ]);
        }
    }

    private function isJsonContactsRequest(): bool
    {
        if (($_GET['format'] ?? null) === 'json') {
            return true;
        }

        return $this->acceptHeaderContainsJson($_SERVER['HTTP_ACCEPT'] ?? '');
    }

    private function acceptHeaderContainsJson(string $accept): bool
    {
        if ($accept === '') {
            return false;
        }

        foreach (explode(',', strtolower($accept)) as $accept_part) {
            $accept_type = trim(explode(';', $accept_part, 2)[0]);
            if ($accept_type === 'application/json' || str_ends_with($accept_type, '+json')) {
                return true;
            }
        }

        return false;
    }

    private function jsonContactsResponse(array $contacts): string
    {
        $formatted_contacts = [];
        foreach ($contacts as $address => $contact) {
            $formatted_contacts[$address] = [
                'label' => (string) ($contact['name'] ?? ''),
            ];
        }

        return json_encode(
            ['contacts' => $formatted_contacts],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    private function setJsonSecurityHeaders(): void
    {
        SimpleRouter::response()->header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        SimpleRouter::response()->header('Pragma: no-cache');
        SimpleRouter::response()->header('Vary: Accept');
        SimpleRouter::response()->header('X-Content-Type-Options: nosniff');
        SimpleRouter::response()->header('X-Frame-Options: SAMEORIGIN');
        SimpleRouter::response()->header("Content-Security-Policy: frame-ancestors 'self'");
        SimpleRouter::response()->header('Cross-Origin-Resource-Policy: same-origin');
    }

    public function ContactsEdit($account_id): ?string
    {
        if (!$this->BSN::validateStellarAccountIdFormat($account_id)) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $csrf_token = md5(session_id() . 'contacts');

        $current_account_id = $this->CurrentUser->getAccountId();
        if ($current_account_id === null) {
            SimpleRouter::response()->redirect(LoginController::getLoginUrlForCurrentRequest(SimpleRouter::getUrl('account', ['id' => $account_id])), 302);
            return null;
        }

        $Account = $this->BSN->makeAccountById($account_id);

        $ContactsManager = $this->ContactsManager;

        $exists_contact = $ContactsManager->getContact($current_account_id, $account_id);

        $return_to = LoginController::normalizeReturnTo(
            $_POST['return_to'] ?? $_SERVER['HTTP_REFERER'] ?? null,
            SimpleRouter::getUrl('account', ['id' => $Account->getId()])
        );

        if (($_POST ?? []) && ($_POST['csrf_token'] ?? null) === $csrf_token) {
            if ($_POST['action'] === $this->Translator->trans('contacts.edit.action.delete')) {
                $ContactsManager->deleteContact($current_account_id, $account_id);
                $this->CurrentContacts->refresh();
            } elseif ($_POST['action'] && $exists_contact) {
                $ContactsManager->updateContact($current_account_id, $account_id, trim($_POST['name']));
                $this->CurrentContacts->refresh();
            } elseif ($_POST['action'] && !$exists_contact) {
                $ContactsManager->addContact($current_account_id, $account_id, trim($_POST['name']));
                $this->CurrentContacts->refresh();
            }
            SimpleRouter::response()->redirect($return_to, 302);
        }

        $name = $Account->getName() ? $Account->getName()[0] : '';
        if ($exists_contact && isset($exists_contact['name']) && $exists_contact['name']) {
            $name = $exists_contact['name'];
        }

        $Template = $this->Twig->load('contact_edit.twig');
        return $Template->render([
            'account' => [
                'id' => $Account->getId(),
                'short_id' => $Account->getShortId(),
            ] + $this->CurrentContacts->serialize($Account, ignore_contact: true),
            'csrf_token' => $csrf_token,
            'return_to' => $return_to,
            'is_exists' => (bool) $exists_contact,
            'name' => $name,
        ]);
    }
}
