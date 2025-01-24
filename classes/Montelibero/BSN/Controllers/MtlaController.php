<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\Relations\Member;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;

class MtlaController
{
    public const MTLA_ACCOUNT = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';

    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
    }

    public function Mtla(): ?string
    {
        $Template = $this->Twig->load('mtla.twig');
        return $Template->render();
    }

    private function fetchMtlaSigners(): array
    {
        if (apcu_exists('mtla_signers_list')) {
            return apcu_fetch('mtla_signers_list');
        }

        $current_signers = [];
        foreach ($this->Stellar->requestAccount(self::MTLA_ACCOUNT)->getSigners() as $Signer) {
            if ($Signer->getKey() === self::MTLA_ACCOUNT) {
                continue;
            }
            $current_signers[$Signer->getKey()] = $Signer->getWeight();
        }
        apcu_store('mtla_signers_list', $current_signers, 600);

        return $current_signers;
    }

    private function fetchMtlaCouncilDelegations(): array
    {
        $key = 'mtla_council_delegations';

        if (apcu_exists($key)) {
            return apcu_fetch($key);
        }

        $accounts_to_delegate = [];
        $Accounts = $this->Stellar
            ->accounts()
            ->forAsset(Asset::createNonNativeAsset('MTLAP', self::MTLA_ACCOUNT))
            ->execute();
        $accounts = [];
        do {
            foreach ($Accounts->getAccounts() as $Account) {
                $accounts[] = $Account;
            }
            $Accounts = $Accounts->getNextPage();
        } while ($Accounts->getAccounts()->count());

        foreach ($accounts as $AccountResponse) {
            if ($AccountResponse instanceof AccountResponse) {
                foreach ($AccountResponse->getBalances()->toArray() as $Asset) {
                    if (($Asset instanceof AccountBalanceResponse)
                        && $Asset->getAssetCode() === 'MTLAP'
                        && $Asset->getAssetIssuer() === self::MTLA_ACCOUNT
                    ) {
                        if (!(int) $Asset->getBalance()) {
                            continue 2;
                        } else {
                            break;
                        }
                    }
                }

                if (($delegate = $AccountResponse->getData()->get('mtla_c_delegate'))
                    && (
                        $this->BSN->validateStellarAccountIdFormat($delegate)
                        || $delegate === 'ready'
                    )
                ) {
                    $accounts_to_delegate[$AccountResponse->getAccountId()] = $delegate;
                } else {
                    $accounts_to_delegate[$AccountResponse->getAccountId()] = null;
                }
            }
        }

        apcu_store($key, $accounts_to_delegate, 600);

        return $accounts_to_delegate;
    }

    public function MtlaCouncil(): ?string
    {
        $Template = $this->Twig->load('mtla_council.twig');

        $current_signers = [];
        foreach ($this->fetchMtlaSigners() as $id => $weight) {
            $Account = $this->BSN->getAccountById($id);
            $current_signers[] = [
                'id' => $Account->getId(),
                'short_id' => $Account->getShortId(),
                'display_name' => $Account->getDisplayName(),
                'sign_weight' => $weight,
            ];
        }

        $accounts_to_delegate = $this->fetchMtlaCouncilDelegations();

        $delegations = [];
        foreach ($accounts_to_delegate as $account_id => $delegate) {
            $Account = $this->BSN->makeAccountById($account_id);
            $member_level = 0;
            if (($Relation = $Account->getRelation()) && $Relation instanceof Member) {
                $member_level = $Relation->getLevel();
            }
            $record = [
                'account' => [
                    'id' => $Account->getId(),
                    'short_id' => $Account->getShortId(),
                    'display_name' => $Account->getDisplayName(),
                ],
                'member_level' => $member_level,
                'ready_to_council' => $delegate === 'ready',
            ];
            if ($delegate && $delegate !== 'ready') {
                $DelegateAccount = $this->BSN->makeAccountById($delegate);
                $record['delegate'] = [
                    'id' => $DelegateAccount->getId(),
                    'short_id' => $DelegateAccount->getShortId(),
                    'display_name' => $DelegateAccount->getDisplayName(),
                ];
            }
            $delegations[] = $record;
        }

        return $Template->render([
            'current_signers' => $current_signers,
            'delegations' => $delegations,
        ]);
    }}
