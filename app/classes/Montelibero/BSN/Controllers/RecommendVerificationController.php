<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Twig\Environment;

class RecommendVerificationController
{
    private const ASSOCIATION_ACCOUNT = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';
    private const RECOMMEND_TAG = 'RecommendForVerification';
    private const VERIFIED_TAG = 'VerifiedByRecommendation';

    private BSN $BSN;
    private Environment $Twig;

    public function __construct(BSN $BSN, Environment $Twig)
    {
        $this->BSN = $BSN;
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
    }

    public function MtlaRecommendVerification(): string
    {
        $recommendTag = $this->BSN->makeTagByName(self::RECOMMEND_TAG);
        $verifiedTag = $this->BSN->makeTagByName(self::VERIFIED_TAG);
        $association = $this->BSN->makeAccountById(self::ASSOCIATION_ACCOUNT);

        $overflowRecommenders = [];
        $validRecommenders = [];
        $allRecommendationsByTarget = [];

        foreach ($this->BSN->getAccounts() as $Account) {
            $outgoingRecommendations = $this->uniqueAccounts($Account->getOutcomeLinks($recommendTag));
            if (!$outgoingRecommendations) {
                continue;
            }

            $item = $this->makeAccountData($Account);
            $item['accounts'] = $this->serializeAccounts($outgoingRecommendations);

            if ($Account->getBalance('MTLAP') >= 4 && count($outgoingRecommendations) <= 5) {
                $validRecommenders[$Account->getId()] = [
                    'account' => $item,
                    'targets' => $outgoingRecommendations,
                ];
            } elseif (count($outgoingRecommendations) > 5) {
                $overflowRecommenders[] = $item;
            }

            foreach ($outgoingRecommendations as $Target) {
                $allRecommendationsByTarget[$Target->getId()][$Account->getId()] = $Account;
            }
        }

        $verifiedByAssociation = [];
        foreach ($this->uniqueAccounts($association->getOutcomeLinks($verifiedTag)) as $Account) {
            $verifiedByAssociation[$Account->getId()] = $Account;
        }

        $candidates = [];
        $verified = [];
        $lost = [];

        $accountIdsToCheck = array_fill_keys(array_keys($allRecommendationsByTarget), true);
        foreach ($verifiedByAssociation as $accountId => $Account) {
            $accountIdsToCheck[$accountId] = true;
        }

        foreach (array_keys($accountIdsToCheck) as $accountId) {
            $Account = $this->BSN->makeAccountById($accountId);
            $recommenders = $allRecommendationsByTarget[$accountId] ?? [];
            $validForAccount = [];

            foreach ($recommenders as $recommenderId => $Recommender) {
                if (!isset($validRecommenders[$recommenderId])) {
                    continue;
                }
                $validForAccount[] = $Recommender;
            }

            if (count($validForAccount) < 5) {
                if (isset($verifiedByAssociation[$accountId]) && $Account->getBalance('MTLAP') >= 2) {
                    $lost[] = $this->makeAccountData($Account, $this->serializeAccounts($validForAccount));
                }
                continue;
            }

            $accountData = $this->makeAccountData($Account, $this->serializeAccounts($validForAccount));
            if ($Account->getBalance('MTLAP') < 2) {
                $candidates[] = $accountData;
            }

            if (isset($verifiedByAssociation[$accountId]) && $Account->getBalance('MTLAP') >= 2) {
                $verified[] = $accountData;
            }
        }

        $this->sortAccountsWithNested($candidates);
        $this->sortAccountsWithNested($verified);
        $this->sortAccountsWithNested($lost);
        $this->sortAccountsWithNested($overflowRecommenders);

        return $this->Twig->render('tools_mtla_recommend_verification.twig', [
            'candidates' => $candidates,
            'verified' => $verified,
            'lost' => $lost,
            'overflow_recommenders' => $overflowRecommenders,
        ]);
    }

    /**
     * @param Account[] $accounts
     * @return Account[]
     */
    private function uniqueAccounts(array $accounts): array
    {
        $unique = [];
        foreach ($accounts as $Account) {
            $unique[$Account->getId()] = $Account;
        }

        return array_values($unique);
    }

    /**
     * @param Account[] $accounts
     * @return array<int, array<string, mixed>>
     */
    private function serializeAccounts(array $accounts): array
    {
        $items = [];
        foreach ($accounts as $Account) {
            $items[] = $this->makeAccountData($Account);
        }

        usort($items, [$this, 'compareAccountData']);

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     */
    private function sortAccountsWithNested(array &$accounts): void
    {
        foreach ($accounts as &$account) {
            if (!empty($account['accounts']) && is_array($account['accounts'])) {
                usort($account['accounts'], [$this, 'compareAccountData']);
            }
        }
        unset($account);

        usort($accounts, function (array $a, array $b): int {
            $nestedCompare = count($b['accounts'] ?? []) <=> count($a['accounts'] ?? []);
            if ($nestedCompare !== 0) {
                return $nestedCompare;
            }

            return $this->compareAccountData($a, $b);
        });
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function compareAccountData(array $a, array $b): int
    {
        return strcasecmp($a['display_name'], $b['display_name']);
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @return array<string, mixed>
     */
    private function makeAccountData(Account $Account, array $accounts = []): array
    {
        return $Account->jsonSerialize() + [
            'accounts' => $accounts,
        ];
    }
}
