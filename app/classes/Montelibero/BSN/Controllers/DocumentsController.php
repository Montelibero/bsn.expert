<?php
namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Contract;
use Montelibero\BSN\DocumentsManager;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class DocumentsController
{
    private const MAX_TEXT_LENGTH = 20000;

    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private DocumentsManager $DocumentsManager;
    private Translator $Translator;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar, DocumentsManager $DocumentsManager, Translator $Translator)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
        
        $this->Stellar = $Stellar;
        $this->DocumentsManager = $DocumentsManager;
        $this->Translator = $Translator;

    }

    public function Documents(): ?string
    {
        $Contracts = $this->BSN->getSignatures();
        $documents = [];
        foreach ($Contracts->getContractsByUsing() as $hash => $using_count) {
            if ($using_count < 2) {
                continue;
            }
            $Hash = $Contracts->makeContract($hash);
            $documents[] = [
                'hash' => $Hash->hash,
                'hash_short' => $Hash->hash_short,
                'name' => $Hash->getName(),
                'display_name' => $Hash->getDisplayName(),
                'using_count' => $using_count,
            ];
        }
        $Template = $this->Twig->load('documents.twig');
        $can_add = false;
        if ($Account = $this->getCurrentAccount()) {
            $can_add = $this->hasMtlaToken($Account);
        }
        return $Template->render([
            'documents' => $documents,
            'can_add' => $can_add,
        ]);
    }

    public function MyDocuments(): ?string
    {
        $Account = $this->requireAuthAccount();
        if (!$Account) {
            return null;
        }

        $documents_data = $this->DocumentsManager->getDocuments($Account->getId());
        $using_counts = $this->BSN->getSignatures()->getContractsByUsing();

        $documents = [];
        foreach ($documents_data as $hash => $item) {
            $documents[] = [
                'hash' => $hash,
                'name' => $item['name'] ?? null,
                'using_count' => $using_counts[$hash] ?? 0,
                'is_obsolete' => (bool) ($item['is_obsolete'] ?? false),
                'new_hash' => $item['new_hash'] ?? null,
                'url' => $item['url'] ?? null,
            ];
        }

        usort($documents, fn($a, $b) => ($b['using_count'] <=> $a['using_count']) ?: strcmp($a['hash'], $b['hash']));

        $Template = $this->Twig->load('documents_my.twig');
        return $Template->render([
            'documents' => $documents,
            'can_add' => $this->hasMtlaToken($Account),
        ]);
    }

    public function Document(string $hash): ?string
    {
        $Hash = null;
        $Contracts = $this->BSN->getSignatures();

        if (Contract::validate($hash)) {
            $Hash = $Contracts->makeContract($hash);
        }

        if (!$Hash) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $signatures = [];
        foreach ($Contracts->getAccountsByContract($Hash) as $Signature) {
            $signatures[] = [
                'account' => $Signature->getAccount()->jsonSerialize(),
                'name' => $Signature->getName(),
            ];
        }

        $Template = $this->Twig->load('document.twig');
        $data = [];
        $data['document'] = $Hash->jsonSerialize();
        if ($NewHash = $Hash->getNewContract()) {
            $data['new_hash'] = $NewHash->jsonSerialize();
        }
        $data['signatures'] = $signatures;

        $document_data = $this->DocumentsManager->getDocument($Hash->hash);
        $data['can_edit'] = $this->canEdit($document_data);

        return $Template->render($data);
    }

    public function DocumentText(string $hash): ?string
    {
        $Hash = null;

        if (Contract::validate($hash)) {
            $Hash = $this->BSN->getSignatures()->makeContract($hash);
        }

        if (!$Hash || !$Hash->getText()) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $Template = $this->Twig->load('document_text.twig');
        $data = [];
        $data['document'] = $Hash->jsonSerialize();
        $calculated_hash = hash("sha256", $Hash->getText());
        if ($calculated_hash !== $Hash->hash) {
            $data['invalid_hash'] = true;
            $data['calculated_hash'] = $calculated_hash;
        }
        $data['default_id'] = isset($_SESSION['account']) ? $_SESSION['account']['id'] : null;
        return $Template->render($data);
    }

    public function DocumentSign(string $hash): ?string
    {
        $Hash = null;

        if (Contract::validate($hash)) {
            $Hash = $this->BSN->getSignatures()->makeContract($hash);
        }

        if (!$Hash || !$Hash->getText()) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $account_id = $_GET['id'] ?? null;
        if (!$account_id || !BSN::validateStellarAccountIdFormat($account_id)) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }
        
        $data_set = $this->Stellar->requestAccount($account_id)->getData()->getData();
        $entry_names = [];
        foreach ($data_set as $key => $value) {
            $value = base64_decode($value);
            if ($value === $Hash->hash) {
                $entry_names[] = $key;
            }
        }

        $data = [];
        $data['account'] = $this->BSN->makeAccountById($account_id)->jsonSerialize();
        $data['is_signed'] = !!$entry_names;
        $data['entry_names'] = $entry_names;

        /*
         * Если ключа не найдено, предлагаем добавить (с каким именем?)
         * Если ключ есть, предлагаем удалить, или переопределить его имя
         * Старое имя показываем, если оно одно, если больше одного, то тоже об этом говорим.
         */

        // TODO: находить устаревшие версии документа, предлагать обновиться

        $Template = $this->Twig->load('document_sign.twig');
        $data['document'] = $Hash->jsonSerialize();
        return $Template->render($data);
    }

    public function Add(): ?string
    {
        $Account = $this->requireMtlaHolder();
        if (!$Account) {
            return null;
        }

        $errors = [];
        $values = [
            'hash' => trim($_POST['hash'] ?? ''),
            'name' => trim($_POST['name'] ?? ''),
            'url' => trim($_POST['url'] ?? ''),
            'text' => $_POST['text'] ?? '',
            'is_obsolete' => isset($_POST['is_obsolete']),
            'new_hash' => trim($_POST['new_hash'] ?? ''),
        ];

        $calculated_hash = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $values['hash'] = strtolower($values['hash']);
            $values['is_obsolete'] = isset($_POST['is_obsolete']);
            $values['new_hash'] = trim($_POST['new_hash'] ?? '');

            if ($values['name'] === '') {
                $errors[] = $this->Translator->trans('documents.form.errors.name_required');
            }

            if ($values['text'] !== '' && $this->getTextLength($values['text']) > self::MAX_TEXT_LENGTH) {
                $errors[] = $this->Translator->trans(
                    'documents.form.errors.text_too_long',
                    ['%limit%' => self::MAX_TEXT_LENGTH]
                );
            }

            if ($values['url'] !== '' && !filter_var($values['url'], FILTER_VALIDATE_URL)) {
                $errors[] = $this->Translator->trans('documents.form.errors.url_invalid');
            }

            if ($values['hash'] === '' && $values['text'] === '') {
                $errors[] = $this->Translator->trans('documents.form.errors.hash_or_text_required');
            }

            if ($values['hash'] !== '' && !Contract::validate($values['hash'])) {
                $errors[] = $this->Translator->trans('documents.form.errors.hash_invalid');
            }

            if ($values['new_hash'] !== '' && !Contract::validate($values['new_hash'])) {
                $errors[] = $this->Translator->trans('documents.form.errors.new_hash_invalid');
            }

            if ($values['text'] !== '') {
                $calculated_hash = hash('sha256', $values['text']);
                if ($values['hash'] === '') {
                    $values['hash'] = $calculated_hash;
                } elseif ($values['hash'] !== $calculated_hash) {
                    $errors[] = $this->Translator->trans('documents.form.errors.hash_mismatch');
                }
            }

            if ($values['hash']) {
                $exists = $this->DocumentsManager->getDocument($values['hash']);
                if ($exists) {
                    $errors[] = $this->Translator->trans('documents.form.errors.hash_exists');
                }
            }

            if (!$errors && $values['hash']) {
                $document_data = [
                    'hash' => $values['hash'],
                    'name' => $values['name'],
                    'type' => null,
                    'url' => $values['url'] ?: null,
                    'text' => $values['text'] !== '' ? $values['text'] : null,
                    'is_obsolete' => $values['is_obsolete'],
                    'new_hash' => $values['new_hash'] ?: null,
                    'source' => $Account->getId(),
                ];
                $SavedDocument = $this->DocumentsManager->upsertDocument($document_data);
                if ($SavedDocument) {
                    SimpleRouter::response()->redirect('/documents/' . $values['hash'] . '/', 302);
                    return null;
                }
                $errors[] = $this->Translator->trans('documents.form.errors.save_failed');
            }
        }

        $Template = $this->Twig->load('document_form.twig');
        return $Template->render([
            'mode' => 'add',
            'values' => $values,
            'errors' => $errors,
            'calculated_hash' => $calculated_hash,
            'max_text_length' => self::MAX_TEXT_LENGTH,
            'remove_text' => false,
        ]);
    }

    public function Edit(string $hash): ?string
    {
        if (!Contract::validate($hash)) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $document = $this->DocumentsManager->getDocument($hash);
        if (!$document) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $Account = $this->requireMtlaHolder();
        if (!$Account) {
            return null;
        }

        if (!$this->canEdit($document)) {
            SimpleRouter::response()->httpCode(403);
            return null;
        }

        $errors = [];
        $Hash = $this->BSN->getSignatures()->makeContract($hash);

        $values = [
            'hash' => $document['hash'],
            'name' => $document['name'] ?? $Hash->getDisplayName(),
            'url' => $document['url'] ?? '',
            'text' => $document['text'] ?? null,
            'is_obsolete' => (bool) ($document['is_obsolete'] ?? false),
            'new_hash' => $document['new_hash'] ?? '',
        ];
        $calculated_hash = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $values['name'] = trim($_POST['name'] ?? '');
            $values['url'] = trim($_POST['url'] ?? '');
            $values['is_obsolete'] = isset($_POST['is_obsolete']);
            $values['new_hash'] = trim($_POST['new_hash'] ?? '');
            $posted_text = $_POST['text'] ?? null;
            $existing_text = $values['text'] ?? '';

            if ($values['name'] === '') {
                $errors[] = $this->Translator->trans('documents.form.errors.name_required');
            }

            if ($values['url'] !== '' && !filter_var($values['url'], FILTER_VALIDATE_URL)) {
                $errors[] = $this->Translator->trans('documents.form.errors.url_invalid');
            }

            if ($values['new_hash'] !== '' && !Contract::validate($values['new_hash'])) {
                $errors[] = $this->Translator->trans('documents.form.errors.new_hash_invalid');
            }

            if ($posted_text !== null) {
                if ($this->getTextLength($posted_text) > self::MAX_TEXT_LENGTH) {
                    $errors[] = $this->Translator->trans(
                        'documents.form.errors.text_too_long',
                        ['%limit%' => self::MAX_TEXT_LENGTH]
                    );
                }

                $trimmed = trim($posted_text);
                if ($trimmed === '') {
                    $values['text'] = null;
                } else {
                    $calculated_hash = hash('sha256', $posted_text);
                    if ($calculated_hash !== $values['hash']) {
                        $errors[] = $this->Translator->trans('documents.form.errors.text_hash_mismatch');
                    } else {
                        $values['text'] = $posted_text;
                    }
                }
            }

            if (!$errors) {
                $document_data = [
                    'hash' => $values['hash'],
                    'name' => $values['name'],
                    'type' => $document['type'] ?? null,
                    'url' => $values['url'] ?: null,
                    'text' => $values['text'] !== '' ? $values['text'] : null,
                    'is_obsolete' => $values['is_obsolete'],
                    'new_hash' => $values['new_hash'] ?: null,
                    'source' => $document['source'] ?? $Account->getId(),
                ];
                $SavedDocument = $this->DocumentsManager->upsertDocument($document_data);
                if ($SavedDocument) {
                    SimpleRouter::response()->redirect('/documents/' . $values['hash'] . '/', 302);
                    return null;
                }
                $errors[] = $this->Translator->trans('documents.form.errors.save_failed');
            }
        }

        $Template = $this->Twig->load('document_form.twig');
        return $Template->render([
            'mode' => 'edit',
            'values' => $values,
            'errors' => $errors,
            'max_text_length' => self::MAX_TEXT_LENGTH,
            'calculated_hash' => $calculated_hash,
            'document_title' => $Hash->getDisplayName(),
        ]);
    }

    public function UpdateFromGrist(): string
    {
        try {
            $result = $this->DocumentsManager->refreshFromGrist();
        } catch (\Throwable $E) {
            SimpleRouter::response()->httpCode(500);
            SimpleRouter::response()->header('Content-Type', 'application/json; charset=utf-8');
            return json_encode([
                'status' => 'error',
                'message' => $E->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        SimpleRouter::response()->header('Content-Type: application/json; charset=utf-8');

        return json_encode([
            'status' => 'ok',
            'updated' => $result['count'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function requireMtlaHolder(): ?Account
    {
        $Account = $this->requireAuthAccount();
        if (!$Account) {
            return null;
        }

        if (!$this->hasMtlaToken($Account)) {
            SimpleRouter::response()->httpCode(403);
            echo 'Недостаточно прав (нужны токены MTLAP или MTLAC).';
            return null;
        }

        return $Account;
    }

    private function canEdit(?array $document): bool
    {
        if (!$document) {
            return false;
        }

        $Account = $this->getCurrentAccount();
        if (!$Account || !$this->hasMtlaToken($Account)) {
            return false;
        }

        return ($document['source'] ?? null) === $Account->getId();
    }

    private function getCurrentAccount(): ?Account
    {
        if (empty($_SESSION['account']['id']) || !BSN::validateStellarAccountIdFormat($_SESSION['account']['id'])) {
            return null;
        }

        return $this->BSN->makeAccountById($_SESSION['account']['id']);
    }

    private function requireAuthAccount(): ?Account
    {
        if (empty($_SESSION['account']['id']) || !BSN::validateStellarAccountIdFormat($_SESSION['account']['id'])) {
            $return_to = $_SERVER['REQUEST_URI'] ?? '/documents/';
            SimpleRouter::response()->redirect('/login?return_to=' . urlencode($return_to), 302);
            return null;
        }

        return $this->BSN->makeAccountById($_SESSION['account']['id']);
    }

    private function hasMtlaToken(Account $Account): bool
    {
        return $Account->getBalance('MTLAP') > 0 || $Account->getBalance('MTLAC') > 0;
    }

    private function getTextLength(?string $text): int
    {
        if ($text === null) {
            return 0;
        }

        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }
}
