<?php

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
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

    public function __construct(Environment $Twig, StellarSDK $Stellar, Translator $Translator, Container $Container)
    {
        $this->Twig = $Twig;
        $this->Stellar = $Stellar;
        $this->Translator = $Translator;
        $this->Container = $Container;
    }

    public function TimeToken(): ?string
    {
        /*
         * Узнать аккаунт юзера, по-умолчанию если авторизовался, иначе ждём его ввода и не показываем всю форму
         * Если знаем аккаунт, ищем значения тегов, заполняем форму
         * Есл теги пустые, но есть выпущенные юзером токены, то предлагаем для автозаполнения их
         * При выборе — заполняются поля
         * Подписание, отправка
         */
        if (!empty($_POST['account']) && !empty($_POST['action']) && $_POST['action'] === 'load') {
            SimpleRouter::response()->redirect(
                SimpleRouter::router()->getUrl('tool_timetoken') . "?account=" . urlencode($_POST['account']),
                302
            );
            return null;
        }

        if (empty($_GET['account']) && ($_SESSION['account']['id'] ?? null)) {
            SimpleRouter::response()->redirect(
                SimpleRouter::router()->getUrl('tool_timetoken') . "?account=" . urlencode($_SESSION['account']['id']),
                302
            );
            return null;
        }

        $Account = null;
        $tt_code = null;
        $tt_issuer = null;
        $tt_offer = null;
        $variants = [];

        if (!empty($_GET['account']) && BSN::validateStellarAccountIdFormat($_GET['account'])) {
            $Account = $this->Stellar->requestAccount($_GET['account']);
            // Ищем текущие значения
            $tt_code = $Account->getData()->get('TimeTokenCode');
            $tt_issuer = $Account->getData()->get('TimeTokenIssuer');
            $tt_offer = $Account->getData()->get('TimeTokenOffer');

            $AssetsResponse = $this->Stellar->assets()->forAssetIssuer($Account->getAccountId())->execute();
            foreach ($AssetsResponse->getAssets() as $AssetResponse) {
                $variants[] = $AssetResponse->getAssetCode();
            }
        }

        // Save checks
        $errors = [];
        $save = false;

        if (!empty($_POST['action']) && $_POST['action'] === $this->Translator->trans(
                'tools_timetoken.buttons.save'
            )) {
            // Account
            if (empty($_POST['account'])) {
                $errors[] = $this->Translator->trans('tools_timetoken.errors.missing_account');
            } elseif (!BSN::validateStellarAccountIdFormat($_POST['account'])) {
                $errors[] = $this->Translator->trans('tools_timetoken.errors.wrong_account');
            }
            if ($_POST['tt_code'] !== '' && !preg_match('/\A[0-9a-z]{1,12}\Z/i', $_POST['tt_code'])) {
                $errors[] = $this->Translator->trans('tools_timetoken.errors.wrong_tt_code');
            }
            if ($_POST['tt_issuer'] !== '' && !BSN::validateStellarAccountIdFormat($_POST['tt_issuer'])) {
                $errors[] = $this->Translator->trans('tools_timetoken.errors.wrong_tt_issuer');
            }
            if ($_POST['tt_issuer'] === $_POST['account']) {
                $_POST['tt_issuer'] = '';
            }
            $_POST['tt_offer'] = strtolower($_POST['tt_offer']);
            if ($_POST['tt_offer'] !== '' && !preg_match('/\A[0-9a-f]{64}\Z/i', $_POST['tt_offer'])) {
                $errors[] = $this->Translator->trans('tools_timetoken.errors.wrong_tt_offer');
            }

            // Transaction
            if (!$errors) {
                $StellarAccount = $this->Stellar->requestAccount($_POST['account']);
                $Transaction = new TransactionBuilder($StellarAccount);
                $Transaction->setMaxOperationFee(10000);
                $operations = [];
                foreach ([['tt_code', 'TimeTokenCode'], ['tt_issuer', 'TimeTokenIssuer'], ['tt_offer', 'TimeTokenOffer']] as $pair) {
                    [$post_field, $data_field] = $pair;
                    if ($_POST[$post_field] !== (string) $StellarAccount?->getData()->get($data_field)) {
                        $ManageData = new ManageDataOperationBuilder($data_field, $_POST[$post_field]);
                        $operations[] = $ManageData->build();
                    }
                }
                if ($operations) {
                    $Transaction->addOperations($operations);
                    $xdr = $Transaction->build()->toEnvelopeXdrBase64();
                    $signing_form = $this->Container->get(CommonController::class)->SignTransaction($xdr);
                }
                $save = true;
            }
        }

        return $this->Twig->render('tools_timetoken.twig', [
            'self_url' => SimpleRouter::router()->getUrl('tool_timetoken'),
            'account' => $_POST['account'] ?? $Account?->getAccountId(),
            'tt_code' => $_POST['tt_code'] ?? $_GET['tt_code'] ?? $tt_code,
            'tt_issuer' => $_POST['tt_issuer'] ?? $_GET['tt_issuer'] ?? $tt_issuer,
            'variants' => $variants,
            'errors' => $errors,
            'signing_form' => $signing_form ?? null,
            'save' => $save,
        ]);
    }
}
