<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Responses\Account\AccountSignerResponse;
use Soneso\StellarSDK\SetOptionsOperationBuilder;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\Xdr\XdrSignerKey;
use Soneso\StellarSDK\Xdr\XdrSignerKeyType;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class MultisigController
{
    private Environment $Twig;
    private StellarSDK $Stellar;
    private Translator $Translator;

    public function __construct(Environment $Twig, StellarSDK $Stellar, Translator $Translator)
    {
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
        $this->Translator = $Translator;
    }

    public function Multisig(): ?string
    {
        if (!empty($_POST['account']) && !empty($_POST['action']) && $_POST['action'] === 'load') {
            SimpleRouter::response()->redirect(
                SimpleRouter::router()->getUrl('multisig') . "?account=" . urlencode($_POST['account']),
                302
            );
            return null;
        }

        $Account = null;
        if (!empty($_GET['account']) && BSN::validateStellarAccountIdFormat($_GET['account'])) {
            $Account = $this->Stellar->requestAccount($_GET['account']);
        }

        $multisig = [];
        if ($Account) {
            $i = 1;
            /** @var AccountSignerResponse $Signer */
            foreach ($Account->getSigners() as $Signer) {
                if ($Signer->getType() !== 'ed25519_public_key' || $Signer->getWeight() === 0) {
                    continue;
                }
                $current_i = $i;
                if ($Signer->getKey() === $Account->getAccountId()) {
                    $current_i = 0;
                } else {
                    $i++;
                }

                $multisig[$current_i] = [
                    'account' => $Signer->getKey(),
                    'weight' => $Signer->getWeight(),
                ];
            }
        } elseif (isset($_POST['account'])) {
            $multisig[0] = [
                'account' => $_POST['account'],
                'weight' => $_POST['weight_0'],
            ];
            for ($i = 1; $i <= 20; $i++) {
                $multisig[$i] = [
                    'account' => $_POST['account_' . $i],
                    'weight' => $_POST['weight_' . $i],
                ];
            }
        }

        // Save checks
        $errors = [];
        $xdr = null;
        $save = false;

        if (!empty($_POST['action']) && $_POST['action'] === $this->Translator->trans(
                'tools_multisig.buttons.calculate'
            )) {
            // Account
            if (empty($_POST['account'])) {
                $errors[] = $this->Translator->trans('tools_multisig.errors.missing_account');
            } elseif (!BSN::validateStellarAccountIdFormat($_POST['account'])) {
                $errors[] = $this->Translator->trans('tools_multisig.errors.wrong_account');
            }
            // Thresholds
            $validate_threshold = function ($value): bool {
                return !empty($value) && filter_var($value, FILTER_VALIDATE_INT) && $value >= 0 && $value <= 255;
            };
            $check_thresholds = true;
            if (!$validate_threshold($_POST['low_threshold'])) {
                $errors[] = $this->Translator->trans('tools_multisig.errors.wrong_low_threshold');
                $check_thresholds = false;
            }
            if (!$validate_threshold($_POST['med_threshold'])) {
                $errors[] = $this->Translator->trans('tools_multisig.errors.wrong_med_threshold');
                $check_thresholds = false;
            }
            if (!$validate_threshold($_POST['high_threshold'])) {
                $errors[] = $this->Translator->trans('tools_multisig.errors.wrong_high_threshold');
                $check_thresholds = false;
            }
            if ($check_thresholds && $_POST['low_threshold'] > $_POST['med_threshold']) {
                $errors[] = $this->Translator->trans('tools_multisig.errors.low_threshold_more_than_med_threshold');
            }
            if ($check_thresholds && $_POST['med_threshold'] > $_POST['high_threshold']) {
                $errors[] = $this->Translator->trans('tools_multisig.errors.med_threshold_more_than_high_threshold');
            }
            // Multisig
            if (!$validate_threshold($_POST['weight_0'])) {
                $errors[] = $this->Translator->trans('tools_multisig.errors.wrong_master_key_weight');
            }
            $signers = [];
            for ($i = 1; $i <= 20; $i++) {
                $item_name = $this->Translator->trans('tools_multisig.signer') . ' ' . $i;
                if (empty($_POST['account_' . $i]) && empty($_POST['weight_' . $i])) {
                    continue;
                }
                if (empty($_POST['account_' . $i]) && !empty($_POST['weight_' . $i])) {
                    $errors[] = $item_name
                        . ': '
                        . $this->Translator->trans(
                            'tools_multisig.errors.missing_signer_account'
                        );
                }
                if (!empty($_POST['account_' . $i]) && !BSN::validateStellarAccountIdFormat($_POST['account_' . $i])) {
                    $errors[] = $item_name
                        . ': '
                        . $this->Translator->trans(
                            'tools_multisig.errors.wrong_signer_account'
                        );
                }
                if (!$validate_threshold($_POST['weight_' . $i])) {
                    $errors[] = $item_name
                        . ': '
                        . $this->Translator->trans(
                            'tools_multisig.errors.wrong_signer_weight'
                        );
                }
                if (!empty($_POST['account_' . $i]) && BSN::validateStellarAccountIdFormat($_POST['account_' . $i])) {
                    if (array_key_exists($_POST['account_' . $i], $signers)) {
                        $errors[] = $item_name
                            . ': '
                            . $this->Translator->trans(
                                'tools_multisig.errors.doubled_signer_account'
                            );
                    } else {
                        $signers[$_POST['account_' . $i]] = $_POST['weight_' . $i];
                    }
                }
            }
            // Possibility to collect signers
            $weight_sum = (int) $_POST['weight_0'];
            foreach ($signers as $weight) {
                $weight_sum += $weight;
            }
            if ($weight_sum < $_POST['high_threshold']) {
                $errors[] = $this->Translator->trans(
                    'tools_multisig.errors.impossible_to_collect_signers'
                );
            }

            // Transaction
            $StellarAccount = $this->Stellar->requestAccount($_POST['account']);
            $Transaction = new TransactionBuilder($StellarAccount);
            $Transaction->setMaxOperationFee(10000);
            $operations = [];
            $update_options = false;
            $SetOptions = new SetOptionsOperationBuilder();
            if ((int) $_POST['low_threshold'] !== $StellarAccount?->getThresholds()?->getLowThreshold()) {
                $SetOptions->setLowThreshold((int) $_POST['low_threshold']);
                $update_options = true;
            }
            if ((int) $_POST['med_threshold'] !== $StellarAccount?->getThresholds()?->getMedThreshold()) {
                $SetOptions->setMediumThreshold((int) $_POST['med_threshold']);
                $update_options = true;
            }
            if ((int) $_POST['high_threshold'] !== $StellarAccount?->getThresholds()?->getHighThreshold()) {
                $SetOptions->setHighThreshold((int) $_POST['high_threshold']);
                $update_options = true;
            }
            $current_master_key_weight = 0;
            $current_signers = [];
            foreach ($StellarAccount->getSigners() as $Signer) {
                if ($Signer->getType() !== 'ed25519_public_key' || $Signer->getWeight() === 0) {
                    continue;
                }
                if ($Signer->getKey() === $StellarAccount->getAccountId()) {
                    $current_master_key_weight = $Signer->getWeight();
                } else {
                    $current_signers[$Signer->getKey()] = $Signer->getWeight();
                }
            }
            if ((int) $_POST['weight_0'] !== $current_master_key_weight) {
                $SetOptions->setMasterKeyWeight((int) $_POST['weight_0']);
                $update_options = true;
            }
            if ($update_options) {
                $operations[] = $SetOptions->build();
            }
            $make_signer_operation = function ($account_id, $weight): SetOptionsOperationBuilder {
                $Signer = new XdrSignerKey();
                $Signer->setType(new XdrSignerKeyType(XdrSignerKeyType::ED25519));
                $Signer->setEd25519(KeyPair::fromAccountId($account_id)->getPublicKey());
                $SetOptions = new SetOptionsOperationBuilder();
                $SetOptions->setSigner($Signer, (int) $weight);
                return $SetOptions;
            };
            // Removes
            foreach ($current_signers as $account_id => $weight) {
                if (!array_key_exists($account_id, $signers) || !(int) $signers[$account_id]) {
                    $operations[] = $make_signer_operation($account_id, 0)->build();
                }
            }
            // Add & updates
            foreach ($signers as $account_id => $weight) {
                if (!array_key_exists($account_id, $current_signers) || (int) $current_signers[$account_id] !== (int) $weight) {
                    $operations[] = $make_signer_operation($account_id, (int) $weight)->build();
                }
            }
            if ($operations) {
                $Transaction->addOperations($operations);
                $xdr = $Transaction->build()->toEnvelopeXdrBase64();
            }
            $save = true;
        }

        return $this->Twig->render('tools_multisig.twig', [
            'account' => $_POST['account'] ?? $Account?->getAccountId(),
            'low_threshold' => $_POST['low_threshold'] ?? $Account?->getThresholds()?->getLowThreshold(),
            'med_threshold' => $_POST['med_threshold'] ?? $Account?->getThresholds()?->getMedThreshold(),
            'high_threshold' => $_POST['high_threshold'] ?? $Account?->getThresholds()?->getHighThreshold(),
            'multisig' => $multisig,
            'errors' => $errors,
            'xdr' => $xdr,
            'save' => $save,
        ]);
    }
}
