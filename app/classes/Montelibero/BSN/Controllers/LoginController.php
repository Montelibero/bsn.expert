<?php
declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use DateInterval;
use DateTime;
use DI\Container;
use Memcached;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\RequestSession;
use Montelibero\BSN\ReturnTo;
use Pecee\SimpleRouter\SimpleRouter;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\Account;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\ManageDataOperation;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\SEP\URIScheme\URIScheme;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TimeBounds;
use Soneso\StellarSDK\Transaction;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\Xdr\XdrTransactionEnvelope;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class LoginController
{
    private const BROWSER_SESSION_HASH_KEY = 'browser_session_hash';

    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private Memcached $Memcached;
    private Container $Container;
    private CurrentUser $CurrentUser;
    private RequestSession $RequestSession;

    public function __construct(
        BSN $BSN,
        Environment $Twig,
        StellarSDK $Stellar,
        Memcached $Memcached,
        Container $Container,
        CurrentUser $CurrentUser,
        RequestSession $RequestSession,
    ) {
        $this->BSN = $BSN;

        $this->Twig = $Twig;

        $this->Stellar = $Stellar;
        $this->Memcached = $Memcached;

        $this->Container = $Container;
        $this->CurrentUser = $CurrentUser;
        $this->RequestSession = $RequestSession;
    }

    public function Login(): ?string
    {
        $return_to = $this->resolveReturnTo();

        // Cookie check
        if (!isset($_COOKIE[session_name()]) && !isset($_GET['no_cookie'])) {
            SimpleRouter::response()->redirect('/login?no_cookie=1&return_to=' . urlencode($return_to), 302);
            return null;
        }
        if (isset($_GET['no_cookie']) && isset($_COOKIE[session_name()])) {
            SimpleRouter::response()->redirect(self::getLoginUrl($return_to), 302);
            return null;
        }

        $nonce = $_GET['nonce'] ?? null;
        $error = null;

        if (!$nonce) {
            $ServerKeypair = Keypair::fromSeed($_ENV['SERVER_STELLAR_SECRET_KEY']);
            $StellarAccount = new Account($ServerKeypair->getAccountId(), new BigInteger(0));
            $Transaction = new TransactionBuilder($StellarAccount);
            $nonce = substr(md5(random_bytes(10)), -16);
            $Operation = new ManageDataOperationBuilder('bsn.expert', $nonce);
            $Transaction->addOperation($Operation->build());
            $Operation = new ManageDataOperationBuilder('web_auth_domain', 'bsn.expert');
            $Transaction->addOperation($Operation->build());
            $Transaction->setTimeBounds(
                new TimeBounds(
                    new DateTime(),
                    (new DateTime())->add((new DateInterval('PT5M')))
                )
            );
            $Transaction = $Transaction->build();
            $Transaction->sign($ServerKeypair, Network::public());
            $tx = $Transaction->toEnvelopeXdrBase64();
            $UriScheme = new URIScheme();
            $uri = $UriScheme->generateSignTransactionURI(
                $tx,
                replace: "sourceAccount:X;X:account to authenticate",
                callback: 'url:https://bsn.expert/login/callback',
                //            callback: 'url:https://webhook.site/a0baef6f-aad9-409b-a5dc-7b5dd0418e08',
                message: "bsn.expert auth",
                originDomain: "bsn.expert"
            );
            $uri_signed = SignController::signSep07Uri($uri, $ServerKeypair);

            $data = [
                'uri' => $uri_signed,
                'status' => 'created',
                'timestamp' => time(),
                'return_to' => $return_to,
                self::BROWSER_SESSION_HASH_KEY => $this->currentBrowserSessionHash(),
            ];

            $this->Memcached->set("login_nonce_" . $nonce, $data, 300);
        } else {
            $data = $this->Memcached->get("login_nonce_" . $nonce) ?: null;
            if ($data && !$this->challengeBelongsToCurrentBrowser($data)) {
                $data = null;
            }
            if (($_GET['format'] ?? null) === 'json' || ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                header('Content-type: application/json');
                if (!$data) {
                    $data = ['status' => 'timeout'];
                }
                return json_encode(
                    $this->publicChallengeStatus($data),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
            if (!$data) {
                $error = 'timeout';
            } elseif ($data['status'] === 'created') {
                $uri_signed = $data['uri'];
                $return_to = self::normalizeReturnTo($data['return_to'] ?? $return_to);
            } elseif ($data['status'] === 'OK') {
                $this->authenticate($data['account_id']);
                $this->Memcached->delete("login_nonce_" . $nonce);
                SimpleRouter::response()->redirect(self::normalizeReturnTo($data['return_to'] ?? $return_to), 302);
            } else {
                $error = $data['status'];
                $return_to = self::normalizeReturnTo($data['return_to'] ?? $return_to);
            }
        }

        /**
         * Если нонсенса нет — создаём транзакцию (+ нонсенс), и настраиваем авто-рефреш с этим нонсенсом
         * Если нонсенс есть, то проверить его содержимое
         * Если содержимого нет — ошибка, что-то не получилось, кнопка «попытаться вновь» ведёт на страницу без
         * нонсенса, авторефреш остановлен
         * Если содержимое есть, смотрим статус
         * Если ОК — авторизовываем и перекидываем назад.
         * Если created - продолжить авторефреш
         * Иначе — ошибка, отобразить, кнопка повторить.
         */


        $signing_form = null;
        if (isset($uri_signed)) {
            $signing_form = $this->Container->get(SignController::class)->SignTransaction(null, $uri_signed);
        }


        $Template = $this->Twig->load('login.twig');
        return $Template->render([
            'return_to' => $return_to,
            'no_cookie' => isset($_GET['no_cookie']),
            'signing_form' => $signing_form,
            'sign_uri' => $uri_signed ?? null,
            'sign_qr' => $qr ?? null,
            'nonce' => $nonce,
            'timer' => isset($data) ? (300 - (time() - $data['timestamp'])) : null,
            'error' => $error,
        ]);
    }

    public function Callback(): string
    {
//        $_POST['xdr'] = 'AAAAAgAAAADoxXsJz7Iv6EBlTrodaCzbJY/2LZ3+w3PRHEt/t7H49gAe02oCZexSAAAEJgAAAAEAAAAAAAAAAAAAAABn4d1BAAAAAAAAAAIAAAAAAAAACgAAAApic24uZXhwZXJ0AAAAAAABAAAAEDg4OGY0MWRmM2QzMjFiMzgAAAAAAAAACgAAAA93ZWJfYXV0aF9kb21haW4AAAAAAQAAAApic24uZXhwZXJ0AAAAAAAAAAAAARWoBiEAAABADCqqBVq6EGNZg99WC1KS25LUJpB9jNo7VVKykg/asV0fMYxE3zF45UxDjfo+MJfcjFe2mYQy3VI2Lt1w3IZ7Bw==';
        if (!isset($_POST['xdr'])) {
            SimpleRouter::response()->httpCode(400);
            return 'missing xdr';
        }
        try {
            /** @var Transaction $Transaction */
            $Transaction = Transaction::fromEnvelopeBase64XdrString($_POST["xdr"]);
        } catch (\Exception $E) {
            SimpleRouter::response()->httpCode(400);
            return 'invalid xdr';
        }
        $Envelope = XdrTransactionEnvelope::fromEnvelopeBase64XdrString($_POST['xdr']);

        $account_id = $Transaction->getSourceAccount()->getAccountId();
        $FirstOperation = $Transaction->getOperations()[0];
        if (!($FirstOperation instanceof ManageDataOperation)) {
            SimpleRouter::response()->httpCode(400);
            return 'invalid xdr (operation)';
        }
        $nonce = $FirstOperation->getValue();

        $data = $this->Memcached->get("login_nonce_" . $nonce);
        if (!$data) {
            SimpleRouter::response()->httpCode(400);
            return 'unknown nonce';
        }

        if ($data['status'] !== 'created') {
            SimpleRouter::response()->httpCode(400);
            return 'obsolete transaction';
        }

        /** @var TimeBounds $TimeBounds */
        $TimeBounds = $Transaction->getTimeBounds();
        if (!$TimeBounds) {
            SimpleRouter::response()->httpCode(400);
            return 'missing time bounds';
        }

        if (time() < $TimeBounds->getMinTime()->getTimestamp() || time() > $TimeBounds->getMaxTime()->getTimestamp()) {
            $data['status'] = 'timeout';
            $this->Memcached->set("login_nonce_" . $nonce, $data, 300);
            SimpleRouter::response()->httpCode(400);
            return 'too old';
        }

        $check_sign = $this->checkSignature($_POST['xdr']);

        if ($check_sign === null) {
            SimpleRouter::response()->httpCode(503);
            SimpleRouter::response()->header('Retry-After: 5');
            return 'Stellar node error';
        }

        if ($check_sign === false) {
            $data['status'] = 'bad_signature';
            $this->Memcached->set("login_nonce_" . $nonce, $data, 300);
            SimpleRouter::response()->httpCode(403);
            return "Missing client signature";
        }

        $data['status'] = 'OK';
        $data['account_id'] = $account_id;
        $this->Memcached->set("login_nonce_" . $nonce, $data, 300);

        return 'OK';
    }

    /**
     * Returns null when Horizon is unavailable so callers can fail closed
     * without invalidating an otherwise reusable login challenge.
     */
    public function checkSignature(string $xdr): ?bool
    {
        /** @var Transaction $Transaction */
        $Transaction = Transaction::fromEnvelopeBase64XdrString($xdr);
        $Envelope = XdrTransactionEnvelope::fromEnvelopeBase64XdrString($xdr);

        $account_id = $Transaction->getSourceAccount()->getAccountId();

        $sign_weight_sum = 0;
        $StellarAccount = null;
        $last_error = null;
        for ($attempt = 1; $attempt <= 4 && $StellarAccount === null; $attempt++) {
            try {
                $StellarAccount = $this->Stellar->requestAccount($account_id);
            } catch (\Throwable $E) {
                $last_error = $E;
            }
        }
        if ($StellarAccount === null) {
            error_log(sprintf(
                'Unable to verify login signature for %s after 4 Horizon attempts: %s',
                $account_id,
                $last_error?->getMessage() ?? 'unknown error'
            ));
            return null;
        }

        $signers = [];
        foreach ($StellarAccount->getSigners()->toArray() as $Signature) {
            $id = $Signature->getKey();
            if (!$this->BSN::validateStellarAccountIdFormat($id)) {
                continue;
            }
            $signers[$id] = [
                'weight' => $Signature->getWeight(),
                'Keypair' => Keypair::fromAccountId($id),
            ];
        }

        $envelope_signatures = $Envelope->getV1()->getSignatures();
        $seen_signatures = [];
        foreach ($envelope_signatures as $Signature) {
            $signature_id = base64_encode($Signature->getSignature());
            if (isset($seen_signatures[$signature_id])) {
                return false;
            }
            $seen_signatures[$signature_id] = true;
        }

        $tx_hash = $Transaction->hash(Network::public());
        $verified_signer_ids = [];
        foreach ($envelope_signatures as $Signature) {
            foreach ($signers as $signer_id => $signer) {
                if (isset($verified_signer_ids[$signer_id])) {
                    continue;
                }
                if (!hash_equals($signer['Keypair']->getHint(), $Signature->getHint())) {
                    continue;
                }
                if ($signer['Keypair']->verifySignature($Signature->getSignature(), $tx_hash)) {
                    $verified_signer_ids[$signer_id] = true;
                    $sign_weight_sum += $signer['weight'];
                    break;
                }
            }
        }

        return $sign_weight_sum >= $StellarAccount->getThresholds()->getMedThreshold();
    }

    public function LoginManual()
    {
        $Template = $this->Twig->load('login_manual.twig');
        $return_to = $this->resolveReturnTo();

        $account_id = $_POST['account_id'] ?? null;
        $Account = null;

        $error = null;
        $Translator = $this->Container->get(Translator::class);
        if ($account_id) {
            if (!$this->BSN::validateStellarAccountIdFormat($account_id)) {
                $error = $Translator->trans('login_manual.errors.invalid_account_id');
            } else {
                $Account = $this->Stellar->requestAccount($account_id);
            }
        }

        $xdr = $_POST['xdr'] ?? null;

        if ($Account && !$xdr) {
            $xdr = (new TransactionBuilder($Account))
                ->addOperation(
                    (new PaymentOperationBuilder(
                        'GBDWGABTWCQHVARC7OQH4FC42O2SFI6FQRJRKVFMZ2U33RUGO3UMFBSN',
                        Asset::createNonNativeAsset('EURMTL', 'GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V'),
                        '0.10',
                    ))->build()
                )->build()->toEnvelopeXdrBase64();
        }

        $signed_xdr = $_POST['signed_xdr'] ?? null;

        if ($Account && $signed_xdr) {
            try {
                /** @var Transaction $Transaction */
                $Transaction = Transaction::fromEnvelopeBase64XdrString($signed_xdr);
            } catch (\Exception $E) {
                SimpleRouter::response()->httpCode(400);
                $error = $Translator->trans('login_manual.errors.invalid_signed_xdr');
            }

            if (!$error && $Transaction->getSourceAccount()->getAccountId() !== $account_id) {
                $error = $Translator->trans('login_manual.errors.wrong_account_id');
            }

            if (!$error && $Transaction->getSequenceNumber()->compare($Account->getIncrementedSequenceNumber()) !== 0) {
                $error = $Translator->trans('login_manual.errors.wrong_seq_number');
            }

            if (!$error) {
                $check_sign = $this->checkSignature($signed_xdr);

                if ($check_sign === null) {
                    SimpleRouter::response()->httpCode(503);
                    $error = $Translator->trans('login_manual.errors.stellar_node_unavailable');
                } elseif ($check_sign === true) {
                    $this->authenticate($account_id);
                    SimpleRouter::response()->redirect($return_to, 302);
                } else {
                    $error = $Translator->trans('login_manual.errors.bad_signature');
                }
            }
        }

        return $Template->render([
            'error' => $error,
            'account_id' => $account_id,
            'xdr' => $xdr,
            'signed_xdr' => $signed_xdr,
            'return_to' => $return_to,
        ]);

    }

    public static function getLoginUrlForCurrentRequest(string $fallback = '/'): string
    {
        return self::getLoginUrl($_SERVER['REQUEST_URI'] ?? $fallback, $fallback);
    }

    public static function getLoginUrl(?string $return_to = '/', string $fallback = '/'): string
    {
        return '/login?return_to=' . urlencode(self::normalizeReturnTo($return_to, $fallback));
    }

    public static function normalizeReturnTo(?string $return_to, string $fallback = '/'): string
    {
        return ReturnTo::normalize($return_to, $fallback);
    }

    private function resolveReturnTo(string $fallback = '/'): string
    {
        return ReturnTo::getFromRequest($fallback);
    }

    private function authenticate($account_id): void
    {
        $this->RequestSession->regenerateId();
        $this->CurrentUser->authenticate($account_id);
    }

    private function currentBrowserSessionHash(): string
    {
        $session_id = session_id();
        if ($session_id === '') {
            throw new \RuntimeException('Login challenge requires an active browser session.');
        }

        return hash('sha256', $session_id);
    }

    private function challengeBelongsToCurrentBrowser(array $data): bool
    {
        $expected_hash = $data[self::BROWSER_SESSION_HASH_KEY] ?? null;
        return is_string($expected_hash)
            && hash_equals($expected_hash, $this->currentBrowserSessionHash());
    }

    private function publicChallengeStatus(array $data): array
    {
        return array_intersect_key($data, array_flip(['status', 'timestamp']));
    }
}
