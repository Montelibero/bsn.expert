<?php

namespace Montelibero\BSN;

use Montelibero\BSN\Relations\Known;
use Montelibero\BSN\Relations\Member;

class BSN
{
    use HasLinks;

    /** @var Account[] */
    private array $accounts = [];
    /** @var Tag[] */
    private array $tags = [];

    /** @var Account[] */
    private array $tg_id_to_account = [];

    public const IGNORE_MEMBER_TOKENS = 'GDGC46H4MQKRW3TZTNCWUU6R2C7IPXGN7HQLZBJTNQO6TW7ZOS6MSECR';

    public function loadFromJson(array $json): void
    {
        foreach ($json['accounts'] as $account_id => $data) {
            $Account = $this->makeAccountById($account_id);

            if (array_key_exists('profile', $data)) {
                if (array_key_exists('Name', $data['profile'])) {
                    $Account->setName($data['profile']['Name']);
                }
                if (array_key_exists('About', $data['profile'])) {
                    $Account->setAbout($data['profile']['About']);
                }
                if (array_key_exists('Website', $data['profile'])) {
                    $Account->setWebsite($data['profile']['Website']);
                }

            }

            if (array_key_exists('tags', $data)) {
                foreach ($data['tags'] as $tag_name => $links) {
                    $Tag = $this->makeTagByName($tag_name);
                    foreach ($links as $link_account_id) {
                        $TargetAccount = $this->makeAccountById($link_account_id);
                        $Link = new Link($Tag, $Account, $TargetAccount);
                        $Tag->addLink($Link);
                        $Account->addLink($Link);
                        $TargetAccount->addLink($Link);
                        $this->addLink($Link);
                    }
                }
            }

            if (array_key_exists('balances', $data)) {
                foreach ($data['balances'] as $name => $value) {
                    if (in_array($name, ['MTLAP', 'MTLAC'], true) && $account_id === self::IGNORE_MEMBER_TOKENS) {
                        $value = 0;
                    }

                    $Account->addBalanceRecord($name, floatval($value));
                }
            }
        }

        $this->findInherited();
        $this->findKnown();
    }

    public function loadMtlaMembersFromJson(array $json): void
    {
        foreach ($json as $item) {
            $Account = $this->makeAccountById($item['stellar']);
            $Account->setTelegramId($item['tg_id']);
            $Account->setTelegramUsername($item['tg_username']);
            $this->tg_id_to_account[$item['tg_id']] = $Account;
        }
    }

    public function loadContacts(): void
    {
        if ($_SESSION['telegram']) {
            $ContactsManager = new ContactsManager($_SESSION['telegram']['id']);
            foreach ($ContactsManager->getContacts() as $stellar_address => $item) {
                $Account = $this->makeAccountById($stellar_address);
                $Account->isContact(true);
                $Account->setContactName($item['name'] ?? null);
            }
        }
    }

    private function findInherited(): void
    {
        foreach ($this->accounts as $Account) {
            if ($Account->getRelation() instanceof Member) {
                continue;
            }
            if (
                ($Owner = $Account->getOwner())
                && ($OwnerRelation = $Owner->getRelation())
                && ($OwnerRelation instanceof Member)
                && !$OwnerRelation->isInherited()
            ) {
                $Relation = clone $OwnerRelation;
                $Relation->isInherited(true);
                $Account->setRelation($Relation);
            }
        }
    }

    private function findKnown(): void
    {
        foreach ($this->accounts as $Account) {
            if ($Account->getRelation() instanceof Member) {
                continue;
            }
            foreach ($Account->getIncomeTags() as $IncomeTag) {
                foreach ($Account->getIncomeLinks($IncomeTag) as $IncomeLinkSource) {
                    if ($IncomeLinkSource->getRelation() instanceof Member) {
                        $Account->setRelation(new Known());
                        continue 3;
                    }
                }
            }
        }
    }

    public function makeAccountById(string $id): Account
    {
        if (!array_key_exists($id, $this->accounts)) {
            $Account = Account::fromId($id);
            $this->accounts[$id] = $Account;
        }

        return $this->accounts[$id];
    }

    public function getAccountById(string $id): ?Account
    {
        return $this->accounts[$id] ?? null;
    }

    public function getAccountsCount(): int
    {
        return count($this->accounts);
    }

    /**
     * @return Tag[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getTag($name): ?Tag
    {
        return $this->tags[$name] ?? null;
    }


    /**
     * @return Link[]
     */
    public function getLinks(): array
    {
        return $this->links;
    }

    public function makeTagByName(string $tag_name): Tag
    {
        if (!array_key_exists($tag_name, $this->tags)) {
            $Tag = Tag::fromName($tag_name);
            $this->tags[$tag_name] = $Tag;
        }

        return $this->tags[$tag_name];
    }

    /**
     * @return Account[]
     */
    public function getAccounts(): array
    {
        return $this->accounts;
    }

    public function getAccountByTelegramId(string $telegram_id): ?Account
    {
        return $this->tg_id_to_account[$telegram_id] ?? null;
    }

    public static function validateStellarAccountIdFormat(?string $account_id): bool
    {
        if (!$account_id) {
            return false;
        }

        return preg_match('/\AG[A-Z2-7]{55}\Z/', $account_id);
    }

    public static function validateTagNameFormat(?string $name): bool
    {
        if (!$name) {
            return false;
        }

        return preg_match('/^[a-z0-9_]+?$/i', $name);
    }
}