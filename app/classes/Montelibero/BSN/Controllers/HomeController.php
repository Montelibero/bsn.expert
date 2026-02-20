<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\Link;
use Twig\Environment;

class HomeController
{
    private const TOP_ACCOUNTS_LIMIT = 10;
    private const TOP_DOCUMENTS_LIMIT = 5;
    private const TOP_TAGS_LIMIT = 5;
    private const MIN_PARTICIPATION_TOKENS = 1.0;
    private const TOP_TAGS_IGNORED = ['Signer'];

    private BSN $BSN;
    private Environment $Twig;

    public function __construct(BSN $BSN, Environment $Twig)
    {
        $this->BSN = $BSN;
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
    }

    public function Index(): string
    {
        $Template = $this->Twig->load('index.twig');

        return $Template->render([
            'accounts_count' => $this->BSN->getAccountsCount(),
            'top_corporate_accounts' => $this->buildTopAccountsByBalance('MTLAC', self::TOP_ACCOUNTS_LIMIT),
            'top_personal_accounts' => $this->buildTopAccountsByBalance('MTLAP', self::TOP_ACCOUNTS_LIMIT),
            'top_documents' => $this->buildTopSignedDocuments(self::TOP_DOCUMENTS_LIMIT),
            'top_tags' => $this->buildTopTags(self::TOP_TAGS_LIMIT),
        ]);
    }

    private function buildTopAccountsByBalance(string $asset_code, int $limit): array
    {
        $accounts = [];
        foreach ($this->BSN->getAccounts() as $Account) {
            $balance = $Account->getBalance($asset_code);
            if ($balance < self::MIN_PARTICIPATION_TOKENS) {
                continue;
            }

            $accounts[] = $Account->jsonSerialize() + [
                'balance' => $balance,
                'bsn_score' => $Account->calcBsnScore(),
            ];
        }

        usort($accounts, function (array $a, array $b): int {
            return ($b['balance'] <=> $a['balance'])
                ?: ($b['bsn_score'] <=> $a['bsn_score'])
                ?: strcmp($a['display_name'], $b['display_name']);
        });

        return array_slice($accounts, 0, $limit);
    }

    private function buildTopSignedDocuments(int $limit): array
    {
        $Signatures = $this->BSN->getSignatures();
        $documents = [];
        foreach ($Signatures->getContractsByUsing() as $hash => $using_count) {
            if ($using_count <= 0) {
                continue;
            }

            $Contract = $Signatures->makeContract($hash);
            $documents[] = [
                'hash' => $Contract->hash,
                'hash_short' => $Contract->hash_short,
                'display_name' => $Contract->getDisplayName(),
                'using_count' => $using_count,
            ];
        }

        usort($documents, function (array $a, array $b): int {
            return ($b['using_count'] <=> $a['using_count'])
                ?: strcmp($a['display_name'], $b['display_name']);
        });

        return array_slice($documents, 0, $limit);
    }

    private function buildTopTags(int $limit): array
    {
        $tags = [];
        foreach ($this->BSN->getLinks() as $Link) {
            $this->addTagStats($tags, $Link);
        }

        $result = [];
        foreach ($tags as $item) {
            $result[] = [
                'name' => $item['name'],
                'links_count' => $item['links_count'],
                'source_count' => count($item['source_ids']),
                'target_count' => count($item['target_ids']),
            ];
        }

        usort($result, function (array $a, array $b): int {
            return ($b['links_count'] <=> $a['links_count'])
                ?: ($b['source_count'] <=> $a['source_count'])
                ?: ($b['target_count'] <=> $a['target_count'])
                ?: strcmp($a['name'], $b['name']);
        });

        return array_slice($result, 0, $limit);
    }

    private function addTagStats(array &$tags, Link $Link): void
    {
        $name = $Link->getTag()->getName();
        if (in_array($name, self::TOP_TAGS_IGNORED, true)) {
            return;
        }

        if (!array_key_exists($name, $tags)) {
            $tags[$name] = [
                'name' => $name,
                'links_count' => 0,
                'source_ids' => [],
                'target_ids' => [],
            ];
        }

        $tags[$name]['links_count']++;
        $tags[$name]['source_ids'][$Link->getSourceAccount()->getId()] = true;
        $tags[$name]['target_ids'][$Link->getTargetAccount()->getId()] = true;
    }
}
