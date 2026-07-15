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
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\ManageDataOperation;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Network;
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
    private const CHALLENGE_XDR_KEY = 'challenge_xdr';
    private const CHALLENGE_MODE_KEY = 'challenge_mode';
    private const EXPECTED_ACCOUNT_ID_KEY = 'expected_account_id';
    private const CHALLENGE_DATA_KEY = 'bsn.expert';
    private const WEB_AUTH_DOMAIN = 'bsn.expert';
    private const MODE_SEP07 = 'sep07';
    private const MODE_MANUAL = 'manual';
    private const CHALLENGE_TTL = 300;

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
        $this->RequestSession->id();
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
            $Challenge = $this->buildLoginChallenge($return_to);
            $nonce = $Challenge['nonce'];
            $tx = $Challenge['xdr'];
            $ServerKeypair = Keypair::fromSeed($_ENV['SERVER_STELLAR_SECRET_KEY']);
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

            $data = $Challenge['data'];
            $data['uri'] = $uri_signed;

            $this->storeLoginChallenge($nonce, $data);
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
        if (!isset($_POST['xdr'])) {
            SimpleRouter::response()->httpCode(400);
            return 'missing xdr';
        }
        if (!is_string($_POST['xdr'])) {
            SimpleRouter::response()->httpCode(400);
            return 'invalid xdr';
        }
        $Result = $this->verifyLoginChallenge($_POST['xdr'], self::MODE_SEP07);

        if ($Result['status'] === 'upstream_error') {
            SimpleRouter::response()->httpCode(503);
            SimpleRouter::response()->header('Retry-After: 5');
            return 'Stellar node error';
        }
        if ($Result['status'] === 'bad_signature') {
            $data = $Result['data'];
            $data['status'] = 'bad_signature';
            $this->storeLoginChallenge($Result['nonce'], $data);
            SimpleRouter::response()->httpCode(403);
            return 'Missing client signature';
        }
        if ($Result['status'] !== 'OK') {
            SimpleRouter::response()->httpCode(400);
            return match ($Result['status']) {
                'invalid_xdr' => 'invalid xdr',
                'unknown_nonce' => 'unknown nonce',
                'obsolete' => 'obsolete transaction',
                'timeout' => 'too old',
                default => 'invalid challenge',
            };
        }

        $data = $Result['data'];
        $data['status'] = 'OK';
        $data['account_id'] = $Result['account_id'];
        $this->storeLoginChallenge($Result['nonce'], $data);

        return 'OK';
    }

    /**
     * @return array{
     *     nonce: string,
     *     xdr: string,
     *     data: array<string, mixed>
     * }
     */
    private function buildLoginChallenge(string $return_to, ?string $account_id = null): array
    {
        $ServerKeypair = Keypair::fromSeed($_ENV['SERVER_STELLAR_SECRET_KEY']);
        $source_account_id = $account_id ?? $ServerKeypair->getAccountId();

        // Sequence 1 is intentional. It keeps a manually signed auth challenge
        // unusable on the public network while remaining a valid signable XDR.
        $SourceAccount = new Account($source_account_id, new BigInteger(0));
        $nonce = bin2hex(random_bytes(16));
        $now = new DateTime();
        $Builder = new TransactionBuilder($SourceAccount);
        $Builder->addOperation(
            (new ManageDataOperationBuilder(self::CHALLENGE_DATA_KEY, $nonce))->build()
        );
        $Builder->addOperation(
            (new ManageDataOperationBuilder('web_auth_domain', self::WEB_AUTH_DOMAIN))->build()
        );
        $Builder->setTimeBounds(new TimeBounds(
            $now,
            (clone $now)->add(new DateInterval('PT5M')),
        ));
        $Transaction = $Builder->build();
        if ($account_id === null) {
            $Transaction->sign($ServerKeypair, Network::public());
        }
        $xdr = $Transaction->toEnvelopeXdrBase64();

        $data = [
            'status' => 'created',
            'timestamp' => time(),
            'return_to' => $return_to,
            self::CHALLENGE_XDR_KEY => $xdr,
            self::CHALLENGE_MODE_KEY => $account_id === null ? self::MODE_SEP07 : self::MODE_MANUAL,
            self::BROWSER_SESSION_HASH_KEY => $this->currentBrowserSessionHash(),
        ];
        if ($account_id !== null) {
            $data[self::EXPECTED_ACCOUNT_ID_KEY] = $account_id;
        }

        return compact('nonce', 'xdr', 'data');
    }

    /**
     * @return array{
     *     status: string,
     *     nonce?: string,
     *     account_id?: string,
     *     data?: array<string, mixed>
     * }
     */
    private function verifyLoginChallenge(string $xdr, string $mode): array
    {
        $Transaction = $this->parseV1Transaction($xdr);
        if ($Transaction === null) {
            return ['status' => 'invalid_xdr'];
        }

        $nonce = $this->extractChallengeNonce($Transaction);
        if ($nonce === null) {
            return ['status' => 'invalid_challenge'];
        }

        $data = $this->Memcached->get('login_nonce_' . $nonce);
        if (!is_array($data)) {
            return ['status' => 'unknown_nonce', 'nonce' => $nonce];
        }
        if (($data['status'] ?? null) !== 'created') {
            return ['status' => 'obsolete', 'nonce' => $nonce, 'data' => $data];
        }
        if (($data[self::CHALLENGE_MODE_KEY] ?? null) !== $mode) {
            return ['status' => 'invalid_challenge', 'nonce' => $nonce, 'data' => $data];
        }
        if ($mode === self::MODE_MANUAL && !$this->challengeBelongsToCurrentBrowser($data)) {
            return ['status' => 'invalid_challenge', 'nonce' => $nonce];
        }
        if (!$this->challengeTransactionMatches($Transaction, $data, $mode)) {
            return ['status' => 'invalid_challenge', 'nonce' => $nonce, 'data' => $data];
        }

        $TimeBounds = $Transaction->getTimeBounds();
        if (
            $TimeBounds === null
            || time() < $TimeBounds->getMinTime()->getTimestamp()
            || time() > $TimeBounds->getMaxTime()->getTimestamp()
        ) {
            $data['status'] = 'timeout';
            $this->storeLoginChallenge($nonce, $data);
            return ['status' => 'timeout', 'nonce' => $nonce, 'data' => $data];
        }

        $check_sign = $this->checkSignature($xdr);
        if ($check_sign === null) {
            return ['status' => 'upstream_error', 'nonce' => $nonce, 'data' => $data];
        }
        if ($check_sign === false) {
            return ['status' => 'bad_signature', 'nonce' => $nonce, 'data' => $data];
        }

        return [
            'status' => 'OK',
            'nonce' => $nonce,
            'account_id' => $Transaction->getSourceAccount()->getAccountId(),
            'data' => $data,
        ];
    }

    /** @param array<string, mixed> $data */
    private function challengeTransactionMatches(Transaction $Submitted, array $data, string $mode): bool
    {
        $challenge_xdr = $data[self::CHALLENGE_XDR_KEY] ?? null;
        if (!is_string($challenge_xdr)) {
            return false;
        }

        $Expected = $this->parseV1Transaction($challenge_xdr);
        if (
            $Expected === null
            || $Submitted->getSourceAccount()->getId() !== null
            || !$this->BSN::validateStellarAccountIdFormat($Submitted->getSourceAccount()->getAccountId())
        ) {
            return false;
        }

        if ($mode === self::MODE_MANUAL) {
            return ($data[self::EXPECTED_ACCOUNT_ID_KEY] ?? null) === $Submitted->getSourceAccount()->getAccountId()
                && hash_equals($Expected->toXdr()->encode(), $Submitted->toXdr()->encode());
        }
        if ($mode !== self::MODE_SEP07) {
            return false;
        }

        try {
            // SEP-07 replacement may update these three fields. Signatures are
            // envelope data and therefore are not part of this comparison.
            $Normalized = new Transaction(
                $Expected->getSourceAccount(),
                $Expected->getSequenceNumber(),
                $Submitted->getOperations(),
                $Submitted->getMemo(),
                $Submitted->getPreconditions(),
                $Expected->getFee(),
                $Submitted->getSorobanTransactionData(),
            );
        } catch (\Throwable) {
            return false;
        }

        return hash_equals($Expected->toXdr()->encode(), $Normalized->toXdr()->encode());
    }

    private function parseV1Transaction(string $xdr): ?Transaction
    {
        try {
            $Transaction = Transaction::fromEnvelopeBase64XdrString($xdr);
            $Envelope = XdrTransactionEnvelope::fromEnvelopeBase64XdrString($xdr);
        } catch (\Throwable) {
            return null;
        }

        return $Transaction instanceof Transaction && $Envelope->getV1() !== null
            ? $Transaction
            : null;
    }

    private function extractChallengeNonce(Transaction $Transaction): ?string
    {
        $FirstOperation = $Transaction->getOperations()[0] ?? null;
        if (
            !($FirstOperation instanceof ManageDataOperation)
            || $FirstOperation->getKey() !== self::CHALLENGE_DATA_KEY
            || !is_string($FirstOperation->getValue())
            || preg_match('/^[a-f0-9]{32}$/D', $FirstOperation->getValue()) !== 1
        ) {
            return null;
        }

        return $FirstOperation->getValue();
    }

    /** @param array<string, mixed> $data */
    private function storeLoginChallenge(string $nonce, array $data): void
    {
        $this->Memcached->set('login_nonce_' . $nonce, $data, self::CHALLENGE_TTL);
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
        $account_not_found = false;
        $last_error = null;
        for ($attempt = 1; $attempt <= 4 && $StellarAccount === null; $attempt++) {
            try {
                $StellarAccount = $this->Stellar->requestAccount($account_id);
            } catch (HorizonRequestException $E) {
                if ($E->getStatusCode() === 404) {
                    $account_not_found = true;
                    break;
                }
                $last_error = $E;
            } catch (\Throwable $E) {
                $last_error = $E;
            }
        }
        if ($StellarAccount === null && !$account_not_found) {
            error_log(sprintf(
                'Unable to verify login signature for %s after 4 Horizon attempts: %s',
                $account_id,
                $last_error?->getMessage() ?? 'unknown error'
            ));
            return null;
        }

        if ($account_not_found) {
            $signers = [
                $account_id => [
                    'weight' => 1,
                    'Keypair' => Keypair::fromAccountId($account_id),
                ],
            ];
            $medium_threshold = 1;
        } else {
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
            $medium_threshold = $StellarAccount->getThresholds()->getMedThreshold();
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

        // Web authentication must prove control of at least one positive-weight
        // signer even when Stellar permits medium-threshold operations at zero.
        $required_weight = max(1, $medium_threshold);

        return $verified_signer_ids !== [] && $sign_weight_sum >= $required_weight;
    }

    public function LoginManual(): string
    {
        $this->RequestSession->id();
        $Template = $this->Twig->load('login_manual.twig');
        $return_to = $this->resolveReturnTo();

        $account_id = isset($_POST['account_id']) && is_string($_POST['account_id'])
            ? trim($_POST['account_id'])
            : null;
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? $_POST['nonce'] : null;
        $signed_xdr = isset($_POST['signed_xdr']) && is_string($_POST['signed_xdr'])
            ? trim($_POST['signed_xdr'])
            : null;
        $xdr = null;
        $error = null;
        $Translator = $this->Container->get(Translator::class);

        if ($account_id) {
            if (!$this->BSN::validateStellarAccountIdFormat($account_id)) {
                $error = $Translator->trans('login_manual.errors.invalid_account_id');
            }
        }

        if (!$error && $account_id && $signed_xdr) {
            $Result = $this->verifyLoginChallenge($signed_xdr, self::MODE_MANUAL);
            $data = $Result['data'] ?? null;
            if (is_array($data) && ($data[self::EXPECTED_ACCOUNT_ID_KEY] ?? null) === $account_id) {
                $xdr = $data[self::CHALLENGE_XDR_KEY] ?? null;
                $nonce = $Result['nonce'] ?? $nonce;
                $return_to = self::normalizeReturnTo($data['return_to'] ?? $return_to);
            }

            if ($Result['status'] === 'OK' && $Result['account_id'] !== $account_id) {
                $error = $Translator->trans('login_manual.errors.wrong_account_id');
            } elseif ($Result['status'] === 'OK') {
                $this->Memcached->delete('login_nonce_' . $Result['nonce']);
                $this->authenticate($account_id);
                SimpleRouter::response()->redirect($return_to, 302);
                return '';
            } else {
                $error = match ($Result['status']) {
                    'invalid_xdr' => $Translator->trans('login_manual.errors.invalid_signed_xdr'),
                    'timeout' => $Translator->trans('login_manual.errors.expired_challenge'),
                    'upstream_error' => $Translator->trans('login_manual.errors.stellar_node_unavailable'),
                    'bad_signature' => $Translator->trans('login_manual.errors.bad_signature'),
                    default => $Translator->trans('login_manual.errors.invalid_challenge'),
                };
                if ($Result['status'] === 'upstream_error') {
                    SimpleRouter::response()->httpCode(503);
                } elseif (!in_array($Result['status'], ['bad_signature'], true)) {
                    SimpleRouter::response()->httpCode(400);
                }
                if (in_array($Result['status'], ['timeout', 'unknown_nonce', 'obsolete'], true)) {
                    $xdr = null;
                    $nonce = null;
                }
            }
        } elseif (!$error && $account_id && $nonce) {
            $data = $this->Memcached->get('login_nonce_' . $nonce);
            if (
                is_array($data)
                && ($data['status'] ?? null) === 'created'
                && ($data[self::CHALLENGE_MODE_KEY] ?? null) === self::MODE_MANUAL
                && ($data[self::EXPECTED_ACCOUNT_ID_KEY] ?? null) === $account_id
                && $this->challengeBelongsToCurrentBrowser($data)
            ) {
                $xdr = $data[self::CHALLENGE_XDR_KEY] ?? null;
                $return_to = self::normalizeReturnTo($data['return_to'] ?? $return_to);
            } else {
                $error = $Translator->trans('login_manual.errors.expired_challenge');
            }
        } elseif (!$error && $account_id) {
            $Challenge = $this->buildLoginChallenge($return_to, $account_id);
            $nonce = $Challenge['nonce'];
            $xdr = $Challenge['xdr'];
            $this->storeLoginChallenge($nonce, $Challenge['data']);
        }

        return $Template->render([
            'error' => $error,
            'account_id' => $account_id,
            'xdr' => $xdr,
            'signed_xdr' => $signed_xdr,
            'nonce' => $nonce,
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
        return hash('sha256', $this->RequestSession->id());
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
