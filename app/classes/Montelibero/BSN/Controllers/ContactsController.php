<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\ContactsManager;
use Pecee\SimpleRouter\SimpleRouter;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class ContactsController
{
    private BSN $BSN;
    private Environment $Twig;
    private Translator $Translator;
    private ContactsManager $ContactsManager;

    public function __construct(BSN $BSN, Environment $Twig, Translator $Translator, ContactsManager $ContactsManager)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Translator = $Translator;
        $this->ContactsManager = $ContactsManager;
    }

    public function Contacts(): ?string
    {
        if (empty($_SESSION['account'])) {
            SimpleRouter::response()->redirect('/login/', 302);
        }

        $contacts = $this->ContactsManager->getContacts($_SESSION['account']['id']);

        foreach ($contacts as $stellar_account => &$contact) {
            $Account = $this->BSN->makeAccountById($stellar_account);
            $contact = [
                'display_name' => $Account->getDisplayName(ignore_contact: true),
            ] + $Account->jsonSerialize() + $contact;
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
                    $_SESSION['account']['id'],
                    $_POST['new_stellar_account_1'],
                    $_POST['new_name_1'] ?: ''
                );
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
                                $this->ContactsManager->updateContact($_SESSION['account']['id'], $address, $name);
                            } catch (\Exception $e) {
                                $errors[] = "Не смог обновить контакт $address: {$e->getMessage()}";
                            }
                        }
                    } elseif (!in_array($address, $new_accounts, true)) {
                        try {
                            $this->ContactsManager->addContact($_SESSION['account']['id'], $address, $name ?: '');
                            $new_accounts[] = $address;
                        } catch (\Exception $e) {
                            $errors[] = "Не смог добавить контакт $address: {$e->getMessage()}";
                        }
                    }
                }
            }
            if (!$errors) {
                SimpleRouter::response()->redirect('/contacts', 302);
            }
        }

        if (($_GET['export'] ?? null) === 'json') {
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

    public function ContactsEdit($account_id): ?string
    {
        if (!$this->BSN::validateStellarAccountIdFormat($account_id)) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $csrf_token = md5(session_id() . 'contacts');

        $Account = $this->BSN->makeAccountById($account_id);

        if (!$_SESSION['account']) {
            SimpleRouter::response()->redirect('/tg/', 302);
        }

        $ContactsManager = $this->ContactsManager;

        $exists_contact = $ContactsManager->getContact($_SESSION['account']['id'], $account_id);

        $return_to = $_POST['return_to']
            ?? $_SERVER['HTTP_REFERER']
            ?? SimpleRouter::getUrl('account', ['id' => $Account->getId()]);

        if (($_POST ?? []) && ($_POST['csrf_token'] ?? null) === $csrf_token) {
            if ($_POST['action'] === $this->Translator->trans('contacts.edit.action.delete')) {
                $ContactsManager->deleteContact($_SESSION['account']['id'], $account_id);
            } elseif ($_POST['action'] && $exists_contact) {
                $ContactsManager->updateContact($_SESSION['account']['id'], $account_id, trim($_POST['name']));
            } elseif ($_POST['action'] && !$exists_contact) {
                $ContactsManager->addContact($_SESSION['account']['id'], $account_id, trim($_POST['name']));
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
                'display_name' => $Account->getDisplayName(ignore_contact: true),
            ],
            'csrf_token' => $csrf_token,
            'return_to' => $return_to,
            'is_exists' => (bool) $exists_contact,
            'name' => $name,
        ]);
    }
}
