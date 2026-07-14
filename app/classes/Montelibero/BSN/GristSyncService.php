<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use Montelibero\BSN\Relations\Person;

use function gristRequest;

class GristSyncService
{
    public const KNOWN_TOKENS = 'known_tokens';
    public const MTLA_MEMBERS = 'mtla_members';
    public const DOCUMENTS = 'documents';

    public function __construct(
        private readonly BSN $BSN,
        private readonly DocumentsManager $DocumentsManager,
        private readonly GristSnapshotStore $SnapshotStore,
    ) {
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        return [self::KNOWN_TOKENS, self::MTLA_MEMBERS, self::DOCUMENTS];
    }

    public static function assertScope(string $scope): void
    {
        if (!in_array($scope, self::scopes(), true)) {
            throw new \InvalidArgumentException(sprintf('Unknown Grist sync scope "%s".', $scope));
        }
    }

    public function sync(string $scope): array
    {
        self::assertScope($scope);

        return match ($scope) {
            self::KNOWN_TOKENS => $this->syncKnownTokens(),
            self::MTLA_MEMBERS => $this->syncMtlaMembers(),
            self::DOCUMENTS => $this->syncDocuments(),
        };
    }

    public function syncKnownTokens(): array
    {
        $records = $this->fetchRecords(
            'https://montelibero.getgrist.com/api/docs/gxZer88w3TotbWzkQCzvyw/tables/Assets/records'
        );
        $known_tokens = [];
        $known_codes = [];

        foreach ($records as $item) {
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $code = trim((string) ($fields['code'] ?? ''));
            $issuer = trim((string) ($fields['issuer'] ?? ''));
            if (
                !BSN::validateTokenNameFormat($code)
                || !BSN::validateStellarAccountIdFormat($issuer)
            ) {
                continue;
            }

            $known_tokens[] = [
                'code' => $code,
                'issuer' => $issuer,
                'offer_link' => $fields['offerta_link'] ?? null,
                'category' => $fields['category'] ?? null,
            ];
            $known_codes[strtolower($code)] = true;
        }

        $TagTimeTokenIssuer = $this->BSN->makeTagByName('TimeTokenIssuer');
        foreach ($this->BSN->getAccounts() as $Account) {
            if (
                !($Account->getRelation() instanceof Person)
                || $Account->getBalance('MTLAP') < 1
            ) {
                continue;
            }

            $code = trim((string) $Account->getProfileSingleItem('TimeTokenCode'));
            if (!BSN::validateTokenNameFormat($code)) {
                continue;
            }

            $issuer = $Account->getId();
            if ($tt_issuers = $Account->getOutcomeLinks($TagTimeTokenIssuer)) {
                $issuer = $tt_issuers[0]->getId();
            } elseif ($tt_issuer_profile = $Account->getProfileSingleItem('TimeTokenIssuer')) {
                $issuer = trim((string) $tt_issuer_profile);
            }

            if (!BSN::validateStellarAccountIdFormat($issuer)) {
                continue;
            }

            $code_key = strtolower($code);
            if (array_key_exists($code_key, $known_codes)) {
                continue;
            }

            $known_tokens[] = [
                'code' => $code,
                'issuer' => $issuer,
                'offer_link' => null,
                'category' => 'time_tokens',
            ];
            $known_codes[$code_key] = true;
        }

        $snapshot = $this->SnapshotStore->store(self::KNOWN_TOKENS, $known_tokens);

        return [
            'scope' => self::KNOWN_TOKENS,
            'count' => count($known_tokens),
            'version' => $snapshot['version'],
        ];
    }

    public function syncMtlaMembers(): array
    {
        $records = $this->fetchRecords(
            'https://montelibero.getgrist.com/api/docs/aYk6cpKAp9CDPJe51sP3AT/tables/Users/records'
        );
        $members = [];

        foreach ($records as $item) {
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            if (
                empty($fields['TGID'])
                || empty($fields['Stellar'])
                || empty($fields['MTLAP'])
                || $fields['MTLAP'] == 0
            ) {
                continue;
            }

            $members[] = [
                'stellar' => $fields['Stellar'],
                'tg_id' => $fields['TGID'],
                'tg_username' => trim((string) ($fields['Telegram'] ?? ''), '@'),
            ];
        }

        $snapshot = $this->SnapshotStore->store(self::MTLA_MEMBERS, $members);

        return [
            'scope' => self::MTLA_MEMBERS,
            'count' => count($members),
            'version' => $snapshot['version'],
        ];
    }

    public function syncDocuments(): array
    {
        $result = $this->DocumentsManager->refreshFromGrist();

        return [
            'scope' => self::DOCUMENTS,
            'count' => $result['count'],
            'deleted' => $result['deleted'],
        ];
    }

    /** @return list<array> */
    private function fetchRecords(string $url): array
    {
        $response = gristRequest($url, 'GET');
        if (!is_array($response) || !is_array($response['records'] ?? null)) {
            throw new \UnexpectedValueException('Grist returned a response without a records array.');
        }

        return array_values($response['records']);
    }
}
