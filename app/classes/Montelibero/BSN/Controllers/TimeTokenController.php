<?php

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class TimeTokenController
{
    private Environment $Twig;
    private StellarSDK $Stellar;
    private Translator $Translator;
    private Container $Container;
    private CurrentUser $CurrentUser;
    private BSN $BSN;
    private CurrentContacts $CurrentContacts;

    public function __construct(
        BSN $BSN,
        CurrentContacts $CurrentContacts,
        Environment $Twig,
        StellarSDK $Stellar,
        Translator $Translator,
        Container $Container,
        CurrentUser $CurrentUser,
    ) {
        $this->BSN = $BSN;
        $this->CurrentContacts = $CurrentContacts;
        $this->Twig = $Twig;
        $this->Stellar = $Stellar;
        $this->Translator = $Translator;
        $this->Container = $Container;
        $this->CurrentUser = $CurrentUser;
    }

    public function TimeToken(): ?string
    {
        if (isset($_GET['account'])) {
            SimpleRouter::response()->redirect($this->buildLegacyAccountRedirectUrl(), 302);
            return null;
        }

        $account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$account_id) {
            SimpleRouter::response()->redirect(
                '/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/editor/timetoken'),
                302
            );
            return null;
        }

        if ($cleanup_url = $this->CurrentUser->getCurrentAccountCleanupUrl()) {
            SimpleRouter::response()->redirect($cleanup_url, 302);
            return null;
        }

        $tt_code = null;
        $tt_issuer = null;
        $tt_offer = null;
        $variants = [];

        $Account = $this->Stellar->requestAccount($account_id);
        $tt_code = $Account->getData()->get('TimeTokenCode');
        $tt_issuer = $Account->getData()->get('TimeTokenIssuer');
        $tt_offer = $Account->getData()->get('TimeTokenOffer');

        $AssetsResponse = $this->Stellar->assets()->forAssetIssuer($Account->getAccountId())->execute();
        foreach ($AssetsResponse->getAssets() as $AssetResponse) {
            $variants[] = $AssetResponse->getAssetCode();
        }

        $errors = [];
        $save = false;

        if (!empty($_POST['action']) && $_POST['action'] === $this->Translator->trans(
                'tools_timetoken.buttons.save'
            )) {
            $_POST['tt_code'] = (string) ($_POST['tt_code'] ?? '');
            $_POST['tt_issuer'] = (string) ($_POST['tt_issuer'] ?? '');
            $_POST['tt_offer'] = strtolower((string) ($_POST['tt_offer'] ?? ''));

            if ($_POST['tt_code'] !== '' && !preg_match('/\A[0-9a-z]{1,12}\Z/i', $_POST['tt_code'])) {
                $errors[] = $this->Translator->trans('tools_timetoken.errors.wrong_tt_code');
            }
            if ($_POST['tt_issuer'] !== '' && !BSN::validateStellarAccountIdFormat($_POST['tt_issuer'])) {
                $errors[] = $this->Translator->trans('tools_timetoken.errors.wrong_tt_issuer');
            }
            if ($_POST['tt_issuer'] === $account_id) {
                $_POST['tt_issuer'] = '';
            }
            if ($_POST['tt_offer'] !== '' && !preg_match('/\A[0-9a-f]{64}\Z/i', $_POST['tt_offer'])) {
                $errors[] = $this->Translator->trans('tools_timetoken.errors.wrong_tt_offer');
            }

            if (!$errors) {
                $Transaction = new TransactionBuilder($Account);
                $Transaction->setMaxOperationFee(10000);
                $operations = [];
                foreach ([['tt_code', 'TimeTokenCode'], ['tt_issuer', 'TimeTokenIssuer'], ['tt_offer', 'TimeTokenOffer']] as $pair) {
                    [$post_field, $data_field] = $pair;
                    if ($_POST[$post_field] !== (string) $Account?->getData()->get($data_field)) {
                        $ManageData = new ManageDataOperationBuilder($data_field, $_POST[$post_field]);
                        $operations[] = $ManageData->build();
                    }
                }
                if ($operations) {
                    $Transaction->addOperations($operations);
                    $xdr = $Transaction->build()->toEnvelopeXdrBase64();
                    $signing_form = $this->Container->get(SignController::class)->SignTransaction($xdr);
                }
                $save = true;
            }
        }

        return $this->Twig->render('tools_timetoken.twig', [
            'self_url' => SimpleRouter::router()->getUrl('editor_timetoken'),
            'account' => $this->CurrentContacts->serialize($this->BSN->makeAccountById($account_id)),
            'account_id' => $account_id,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'tt_code' => $_POST['tt_code'] ?? $_GET['tt_code'] ?? $tt_code,
            'tt_issuer' => $_POST['tt_issuer'] ?? $_GET['tt_issuer'] ?? $tt_issuer,
            'tt_offer' => $_POST['tt_offer'] ?? $_GET['tt_offer'] ?? $tt_offer,
            'variants' => $variants,
            'errors' => $errors,
            'signing_form' => $signing_form ?? null,
            'save' => $save,
        ]);
    }

    private function buildLegacyAccountRedirectUrl(): string
    {
        $parts = parse_url($_SERVER['REQUEST_URI'] ?? '/editor/timetoken');
        if ($parts === false) {
            return SimpleRouter::router()->getUrl('editor_timetoken');
        }

        parse_str($parts['query'] ?? '', $query);
        if (!isset($query['current_account'])) {
            $legacy_account = $query['account'] ?? '';
            $legacy_account = strtoupper(trim(is_scalar($legacy_account) ? (string) $legacy_account : ''));
            if ($legacy_account !== '') {
                $query['current_account'] = $legacy_account;
            }
        }
        unset($query['account']);

        $url = SimpleRouter::router()->getUrl('editor_timetoken');
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }
}
