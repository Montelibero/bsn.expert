<?php
namespace Montelibero\BSN\Controllers;

use DateInterval;
use DateTime;
use DI\Container;
use GuzzleHttp\Client;
use Memcached;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Relations\Member;
use PDO;
use Pecee\SimpleRouter\SimpleRouter;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\Account;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\ManageDataOperation;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\SEP\URIScheme\URIScheme;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TimeBounds;
use Soneso\StellarSDK\Transaction;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\Xdr\XdrTransactionEnvelope;
use Twig\Environment;

class LoginController
{
    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private PDO $PDO;
    private Memcached $Memcached;
    private Container $Container;

    public function __construct(
        BSN $BSN,
        Environment $Twig,
        StellarSDK $Stellar,
        PDO $PDO, Memcached
        $Memcached,
        Container $Container,
    ) {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;

        $this->PDO = $PDO;
        $this->Memcached = $Memcached;

        $this->Container = $Container;
    }

    public function Login(): ?string
    {
        // Cookie check
        if (!isset($_COOKIE[session_name()]) && $_SERVER['QUERY_STRING'] !== 'no_cookie') {
            SimpleRouter::response()->redirect('/login?no_cookie', 302);
            return null;
        }
        if ($_SERVER['QUERY_STRING'] === 'no_cookie' && isset($_COOKIE[session_name()])) {
            SimpleRouter::response()->redirect('/login', 302);
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
            $uri_signed = $this::getSignedUrl($uri, $ServerKeypair);

            $data = [
                'uri' => $uri_signed,
                'status' => 'created',
                'timestamp' => time(),
            ];

            $this->Memcached->set("login_nonce_" . $nonce, $data, 300);
        } else {
            $data = $this->Memcached->get("login_nonce_" . $nonce) ?: null;
            if (($_GET['format'] ?? null) === 'json' || $_SERVER['HTTP_ACCEPT'] === 'application/json') {
                header('Content-type: application/json');
                if (!$data) {
                    $data = ['status' => 'timeout'];
                }
                return json_encode(
                    $data,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
            if (!$data) {
                $error = 'timeout';
            } elseif ($data['status'] === 'created') {
                $uri_signed = $data['uri'];
            } elseif ($data['status'] === 'OK') {
                $_SESSION['account'] = $this->BSN->makeAccountById($data['account_id'])->jsonSerialize();
                $Relation = $this->BSN->makeAccountById($data['account_id'])->getRelation();
                if (($Relation instanceof Member) && $Relation->getLevel() >= 2) {
                    $_SESSION['show_telegram_usernames'] = true;
                }
                SimpleRouter::response()->redirect('/', 302);
            } else {
                $error = $data['status'];
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
            'return_to' => $_GET['return_to'] ?? '/',
            'no_cookie' => $_SERVER['QUERY_STRING'] === 'no_cookie',
            'signing_form' => $signing_form,
            'sign_uri' => $uri_signed ?? null,
            'sign_qr' => $qr ?? null,
            'nonce' => $nonce,
            'timer' => isset($data) ? (300 - (time() - $data['timestamp'])) : null,
            'error' => $error,
        ]);
    }

    private function dbCreateRequest(string $nonce)
    {
        $stmt = $this->PDO->prepare('INSERT INTO stellar_auth (nonce, status)
            VALUES (:nonce, :status);');
        $stmt->execute([
            ':nonce' => $nonce,
            ':status' => 'created',
        ]);
    }

    public static function getSignedUrl(string $url, KeyPair $KeyPair): string
    {
        $payloadStart = array();
        for ($i = 0; $i < 36; $i++) {
            $payloadStart[$i] = pack('C', 0);
        }
        $payloadStart[35] = pack('C', 4);
        $urlBytes = URIScheme::uriSchemePrefix . $url;
        $payload = implode('', $payloadStart) . $urlBytes;
        $signatureBytes = $KeyPair->sign($payload);
        $base64Signature = base64_encode($signatureBytes);
        return $url . '&signature=' . urlencode($base64Signature);
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

        $sign_weight_sum = 0;
        $count = 0;
        while (empty($StellarAccount) && $count++ < 4) {
            try {
                $StellarAccount = $this->Stellar->requestAccount($account_id);
            } catch (\Exception $E) {

            }
        }
        if (!$StellarAccount) {
            SimpleRouter::response()->httpCode(500);
            return "Stellar node error";
        }

        $signers = [];
        foreach ($StellarAccount->getSigners()->toArray() as $Signature) {
            $id = $Signature->getKey();
            if (!$this->BSN::validateStellarAccountIdFormat($id)) {
                continue;
            }
            $signers[] = [
                'id' => $id,
                'weight' => $Signature->getWeight(),
                'Keypair' => Keypair::fromAccountId($id),
            ];
        }
        $tx_hash = $Transaction->hash(Network::public());
        foreach ($Envelope->getV1()->getSignatures() as $Signature) {
            foreach ($signers as $signer) {
                if ($signer['Keypair']->verifySignature($Signature->getSignature(), $tx_hash)) {
                    $sign_weight_sum += $signer['weight'];
                }
            }
        }

        if ($sign_weight_sum < $StellarAccount->getThresholds()->getMedThreshold()) {
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
}
