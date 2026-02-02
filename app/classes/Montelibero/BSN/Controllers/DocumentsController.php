<?php
namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Contract;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\DocumentsManager;
use Parsedown;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class DocumentsController
{
    private const MAX_TEXT_LENGTH = 20000;
    private const MAX_MANAGE_DATA_NAME_LENGTH = 64;

    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private DocumentsManager $DocumentsManager;
    private Translator $Translator;
    private Container $Container;
    private CurrentUser $CurrentUser;

    public function __construct(
        BSN $BSN,
        Environment $Twig,
        StellarSDK $Stellar,
        DocumentsManager $DocumentsManager,
        Translator $Translator,
        Container $Container,
        CurrentUser $CurrentUser
    )
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
        
        $this->Stellar = $Stellar;
        $this->DocumentsManager = $DocumentsManager;
        $this->Translator = $Translator;
        $this->Container = $Container;
        $this->CurrentUser = $CurrentUser;

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
        $data['page_url'] = SimpleRouter::getUrl('document_page', ['id' => $hash]);
        $data['document'] = $Hash->jsonSerialize();
        $document_text = $Hash->getText();
        $data['text_like_markdown'] = self::looksLikeMarkdown($document_text);
        if ($data['text_like_markdown']) {
            $Parsedown = new Parsedown();
            $data['text_html'] = $Parsedown->text($document_text);
        }
        $data['show_original'] = isset($_GET['show']) && $_GET['show'] === 'original';
        if ($document_text) {
            $data['calculated_hash'] = hash("sha256", $document_text);
            $data['hash_is_correct'] = $data['calculated_hash'] === $Hash->hash;
        }
        if ($NewHash = $Hash->getNewContract()) {
            $data['new_hash'] = $NewHash->jsonSerialize();
        }
        $data['signatures'] = $signatures;

        $document_data = $this->DocumentsManager->getDocument($Hash->hash);
        $data['can_edit'] = $this->canEdit($document_data);
        $data['sign'] = $this->prepareSigningData($Hash);
        $data['sign']['link'] = $data['page_url'] . '?sign_form=yes#document-sign';

        return $Template->render($data);
    }

    private function prepareSigningData(Contract $Hash): array
    {
        $data = [
            'max_name_length' => self::MAX_MANAGE_DATA_NAME_LENGTH,
        ];
        $data['force_form'] = ($_GET['sign_form'] ?? '') === 'yes';
        $data['show_form'] = $Hash->getText() || $data['force_form'];

        $sign_errors = [];

        $account_id = $this->CurrentUser->getCurrentAccountId() ?? '';

        if (array_key_exists('account_id', $_GET) && trim($_GET['account_id']) !== '') {
            if (BSN::validateStellarAccountIdFormat(trim($_GET['account_id']))) {
                $account_id = trim($_GET['account_id']);
            } else {
                $account_id = null;
                $sign_errors[] = $this->Translator->trans('document_sign.errors.invalid_account');
            }
        }

        $sign_action = $_GET['sign_action'] ?? null;
        $signature_name = trim($_GET['signature_name'] ?? '');

        $existing_signature = null;

        if ($account_id) {
            $data['account'] = $this->BSN->makeAccountById($account_id)->jsonSerialize();
            foreach ($this->BSN->getSignatures()->getAccountsByContract($Hash) as $Signature) {
                if ($Signature->getAccount()->getId() === $account_id) {
                    $existing_signature = [
                        'name' => $Signature->getName(),
                        'account' => $Signature->getAccount()->jsonSerialize(),
                    ];
                    if ($signature_name === '') {
                        $signature_name = $Signature->getName();
                    }
                    break;
                }
            }
        }

        if ($signature_name === '') {
            $signature_name = $Hash->getName() ?: '';
        }

        $allowed_actions = ['sign', 'rename', 'revoke'];
        if ($sign_action && !in_array($sign_action, $allowed_actions, true)) {
            $sign_errors[] = $this->Translator->trans('document_sign.errors.unknown_action');
        }

        if ($data['show_form'] && $sign_action && !$sign_errors) {
            if (!$account_id) {
                $sign_errors[] = $this->Translator->trans('document_sign.errors.missing_account');
            }

            if ($sign_action !== 'revoke') {
                $signature_name_length = function_exists('mb_strlen')
                    ? mb_strlen($signature_name)
                    : strlen($signature_name);
                if ($signature_name === '') {
                    $sign_errors[] = $this->Translator->trans('document_sign.errors.name_required');
                } elseif ($signature_name_length > self::MAX_MANAGE_DATA_NAME_LENGTH) {
                    $sign_errors[] = $this->Translator->trans('document_sign.errors.name_too_long');
                }
            }

            if (in_array($sign_action, ['rename', 'revoke'], true) && !$existing_signature) {
                $sign_errors[] = $this->Translator->trans('document_sign.errors.not_signed_yet');
            }

            if (!$sign_errors) {
                try {
                    $StellarAccount = $this->Stellar->requestAccount($account_id);
                } catch (\Throwable $e) {
                    $sign_errors[] = $this->Translator->trans('document_sign.errors.account_not_loaded');
                }
            }

            if (!$sign_errors) {
                $Transaction = new TransactionBuilder($StellarAccount);
                $Transaction->setMaxOperationFee(10000);
                $Transaction->addMemo(Memo::text('BSN Document'));
                $operations = [];

                if ($sign_action === 'revoke') {
                    $operations[] = (new ManageDataOperationBuilder($existing_signature['name'], null))->build();
                } else {
                    if ($sign_action === 'rename' && $existing_signature && $existing_signature['name'] !== $signature_name) {
                        $operations[] = (new ManageDataOperationBuilder($existing_signature['name'], null))->build();
                    }
                    $operations[] = (new ManageDataOperationBuilder($signature_name, $Hash->hash))->build();
                }

                $Transaction->addOperations($operations);
                $xdr = $Transaction->build()->toEnvelopeXdrBase64();
                $data['signing_form'] = $this->Container->get(SignController::class)->SignTransaction($xdr);
            }
        }

        return array_merge($data, [
            'form_account_id' => ($_GET['account_id'] ?? null) ? trim($_GET['account_id']) : $account_id,
            'existing_signature' => $existing_signature,
            'signature_name' => $signature_name,
            'errors' => $sign_errors,
        ]);
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

    public static function looksLikeMarkdown(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        // List of common Markdown patterns
        $patterns = [
            '/^#{1,6}\s+/m',           // Headings: #, ##, ### ...
            '/\*\*.+?\*\*/',           // Bold text: **text**
            '/\*[^*\n]+\*/',           // Italic text: *text*
            '/`{1,3}.+?`{1,3}/s',      // Inline or block code: `code` or ```code```
            '/^\s*[-*+]\s+/m',         // Unordered lists: - item, * item
            '/^\s*\d+\.\s+/m',         // Ordered lists: 1. item
            '/\[[^\]]+\]\([^)]+\)/',   // Links: [text](url)
            '/^>\s+/m',                // Blockquotes: > quote
        ];

        $hits = 0;

        foreach ($patterns as $pattern) {
            // If this Markdown pattern is found in the text
            if (preg_match($pattern, $text)) {
                $hits++;
            }

            // If we found enough Markdown features,
            // we can safely assume this is Markdown
            if ($hits >= 2) {
                return true;
            }
        }

        // Not enough Markdown features found
        return false;
    }
}
