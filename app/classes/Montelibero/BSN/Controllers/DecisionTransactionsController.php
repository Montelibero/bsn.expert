<?php

namespace Montelibero\BSN\Controllers;

use DateInterval;
use DateTime;
use DateTimeZone;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TimeBounds;
use Soneso\StellarSDK\Transaction;
use Soneso\StellarSDK\TransactionPreconditions;
use Twig\Environment;

class DecisionTransactionsController
{
    private const ASSOCIATION_ACCOUNT = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';
    private const QUESTION_ISSUER = 'GDGC46H4MQKRW3TZTNCWUU6R2C7IPXGN7HQLZBJTNQO6TW7ZOS6MSECR';

    private Environment $Twig;
    private StellarSDK $Stellar;

    public function __construct(Environment $Twig, StellarSDK $Stellar)
    {
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
    }

    public function MtlaDecisionTransactions(): string
    {
        $csrf_token = md5(session_id() . 'decision_transactions');

        $error = '';
        $positive_xdr = '';
        $decline_xdr = '';
        $decision_hash = null;

        $decision_number = trim($_POST['decision_number'] ?? ($_GET['question'] ?? ''));
        $decision_text = $_POST['decision_text'] ?? '';
        $xdr_input = trim($_POST['xdr'] ?? '');
        $seq_num_input = trim($_POST['seq_num'] ?? '');

        if (($_POST ?? []) && ($_POST['csrf_token'] ?? null) === $csrf_token) {
            if ($decision_number === '' || !preg_match('/^\d+$/', $decision_number)) {
                $error .= "Номер решения обязателен и должен быть числом\n";
            }

            if ($xdr_input === '' && trim($decision_text) === '') {
                $error .= "Если нет XDR, нужен текст решения\n";
            }

            if ($seq_num_input !== '' && !preg_match('/^\d+$/', $seq_num_input)) {
                $error .= "Seq num должен быть числом\n";
            }

            $base_transaction = null;
            if (!$error && $xdr_input !== '') {
                try {
                    $base_transaction = Transaction::fromEnvelopeBase64XdrString($xdr_input);
                } catch (\Throwable $E) {
                    $error .= "Не удалось разобрать переданный XDR\n";
                }
            }

            if (!$error) {
                $source_account_id = $base_transaction
                    ? $base_transaction->getSourceAccount()->getAccountId()
                    : self::ASSOCIATION_ACCOUNT;

                $sequence_number = null;
                $SourceAccount = null;
                if ($seq_num_input !== '') {
                    $sequence_number = new BigInteger($seq_num_input);
                } else {
                    try {
                        $SourceAccount = $this->Stellar->requestAccount($source_account_id);
                        $sequence_number = $SourceAccount->getIncrementedSequenceNumber();
                    } catch (\Throwable $E) {
                        $error .= "Не удалось получить данные аккаунта источника\n";
                    }
                }

                if (!$error) {
                    $asset_question = Asset::createNonNativeAsset('Question', self::QUESTION_ISSUER);
                    $payment_operation = (new PaymentOperationBuilder(
                        self::ASSOCIATION_ACCOUNT,
                        $asset_question,
                        (string) $decision_number
                    ));
                    if ($source_account_id !== self::ASSOCIATION_ACCOUNT) {
                        $payment_operation->setSourceAccount(self::ASSOCIATION_ACCOUNT);
                    }
                    $payment_operation = $payment_operation->build();

                    /** @var AbstractOperation[] $operations_positive */
                    $operations_positive = $base_transaction
                        ? array_merge([$payment_operation], $base_transaction->getOperations())
                        : [$payment_operation];

                    $preconditions = $base_transaction && $base_transaction->getPreconditions()
                        ? clone $base_transaction->getPreconditions()
                        : new TransactionPreconditions();

                    $now = new DateTime('now', new DateTimeZone('UTC'));
                    $max_time = (clone $now)->add(new DateInterval('P4D'));
                    $preconditions->setTimeBounds(new TimeBounds($now, $max_time));

                    $trimmed_text = trim($decision_text);
                    if ($trimmed_text !== '') {
                        $decision_hash = hash('sha256', $trimmed_text);
                        $memo_positive = Memo::hash(hex2bin($decision_hash));
                    } else {
                        $memo_positive = Memo::text('MTLA ' . $decision_number);
                    }

                    $source_account_muxed = $base_transaction
                        ? $base_transaction->getSourceAccount()
                        : $SourceAccount->getMuxedAccount();

                    $soroban_data = $base_transaction ? $base_transaction->getSorobanTransactionData() : null;

                    $positive_transaction = new Transaction(
                        $source_account_muxed,
                        $sequence_number,
                        $operations_positive,
                        $memo_positive,
                        $preconditions,
                        10000,
                        $soroban_data
                    );

                    $positive_xdr = $positive_transaction->toEnvelopeXdrBase64();

                    $decline_preconditions = clone $preconditions;
                    $decline_transaction = new Transaction(
                        $source_account_muxed,
                        $sequence_number,
                        [$payment_operation],
                        Memo::text('MTLA ' . $decision_number . ' decline'),
                        $decline_preconditions,
                        10000,
                        $soroban_data
                    );

                    $decline_xdr = $decline_transaction->toEnvelopeXdrBase64();
                }
            }
        }

        return $this->Twig->render('tools_mtla_decision_transactions.twig', [
            'csrf_token' => $csrf_token,
            'decision_number' => $decision_number,
            'decision_text' => $decision_text,
            'xdr' => $xdr_input,
            'seq_num' => $seq_num_input,
            'error' => $error,
            'positive_xdr' => $positive_xdr,
            'decline_xdr' => $decline_xdr,
            'decision_hash' => $decision_hash,
        ]);
    }
}
