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
    /** @var TagCategory[] */
    private array $tag_categories = [];
    /** @var Tag[][] */
    private array $tags_by_category_id = [];

    /** @var Account[] */
    private array $tg_id_to_account = [];

    private SignatureCollection $Signatures;
    private ?int $data_timestamp = null;
    private ?int $data_loaded_at = null;
    private ?int $data_file_mtime = null;
    private array $mtla_members_json = [];

    public const IGNORE_MEMBER_TOKENS = 'GDGC46H4MQKRW3TZTNCWUU6R2C7IPXGN7HQLZBJTNQO6TW7ZOS6MSECR';
    private AccountsManager $AccountsManager;
    private DocumentsManager $DocumentsManager;

    public function __construct(AccountsManager $AccountsManager, DocumentsManager $DocumentsManager)
    {
        $this->AccountsManager = $AccountsManager;
        $this->DocumentsManager = $DocumentsManager;
        $this->Signatures = new SignatureCollection($DocumentsManager);
    }

    public function loadFromJsonFile(string $path): void
    {
        $json = $this->readJsonFile($path);
        if ($json === null) {
            throw new \RuntimeException("Unable to load BSN JSON data from $path");
        }

        $this->loadFromJson($json);
        $this->data_file_mtime = $this->getFileMtime($path);
    }

    public function refreshFromJsonFileIfChanged(string $path): void
    {
        $mtime = $this->getFileMtime($path);
        if ($mtime === null || $mtime === $this->data_file_mtime) {
            return;
        }

        $json = $this->readJsonFile($path);
        if ($json === null) {
            error_log("Unable to refresh BSN JSON data from $path");
            return;
        }

        $data_timestamp = self::extractDataTimestamp($json);
        if ($data_timestamp !== null && $data_timestamp === $this->data_timestamp) {
            $this->data_file_mtime = $mtime;
            return;
        }

        try {
            $this->loadFromJson($json);
        } catch (\Throwable $Throwable) {
            error_log(sprintf('Unable to apply refreshed BSN JSON data from %s: %s', $path, $Throwable->getMessage()));
            return;
        }

        $this->data_file_mtime = $mtime;

        error_log(sprintf(
            'Reloaded BSN JSON data: data_timestamp=%s loaded_at=%d',
            $this->data_timestamp ?? 'unknown',
            $this->data_loaded_at ?? 0
        ));
    }

    public function loadFromJson(array $json): void
    {
        if (!isset($json['accounts']) || !is_array($json['accounts'])) {
            throw new \InvalidArgumentException('BSN JSON data must contain an accounts object.');
        }

        $this->clearLoadedData();

        foreach ($json['accounts'] as $account_id => $data) {
            $Account = $this->makeAccountById($account_id);

            if (array_key_exists('profile', $data)) {
                $Account->setProfile($data['profile']);
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

            if (array_key_exists('signatures', $data)) {
                foreach ($data['signatures'] as $hash => $name) {
                    $this->Signatures->addSignature($Account, $hash, $name);
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

            if (array_key_exists('multisig', $data) && is_array($data['multisig'])) {
                $multisig = $this->normalizeMultisig($account_id, $data['multisig']);
                if ($multisig !== null) {
                    $signers = [];
                    foreach ($multisig['signers'] as $signer) {
                        $SignerAccount = $this->makeAccountById($signer['account_id']);
                        $signers[] = [
                            'account' => $SignerAccount,
                            'weight' => $signer['weight'],
                        ];
                        $SignerAccount->addMultisigParticipation(
                            $Account,
                            $signer['weight'],
                            $multisig['thresholds'][1]
                        );
                    }

                    $Account->setMultisig($multisig['thresholds'], $multisig['master_key'], $signers);
                }
            }
        }

        $this->findInherited();
        $this->findKnown();

        $this->loadUsernames();
        $this->applyMtlaMembersJson();

        $this->data_timestamp = self::extractDataTimestamp($json);
        $this->data_loaded_at = time();
    }

    public function loadMtlaMembersFromJson(array $json): void
    {
        $this->mtla_members_json = $json;
        $this->applyMtlaMembersJson();
    }

    public function getDataTimestamp(): ?int
    {
        return $this->data_timestamp;
    }

    public function getDataLoadedAt(): ?int
    {
        return $this->data_loaded_at;
    }

    private function applyMtlaMembersJson(): void
    {
        $this->tg_id_to_account = [];

        foreach ($this->mtla_members_json as $item) {
            if (!is_array($item)) {
                continue;
            }
            $Account = $this->makeAccountById($item['stellar']);
            $Account->setTelegramId($item['tg_id']);
            $Account->setTelegramUsername($item['tg_username']);
            $this->tg_id_to_account[$item['tg_id']] = $Account;
        }
    }

    private function clearLoadedData(): void
    {
        $this->accounts = [];
        $this->tg_id_to_account = [];
        $this->clearLinks();
        $this->Signatures->clearSignatures();

        foreach ($this->tags as $Tag) {
            $Tag->clearLinks();
        }
    }

    private function readJsonFile(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            $json = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $Exception) {
            error_log(sprintf('Invalid BSN JSON data in %s: %s', $path, $Exception->getMessage()));
            return null;
        }

        return is_array($json) ? $json : null;
    }

    private function getFileMtime(string $path): ?int
    {
        clearstatcache(false, $path);

        $mtime = @filemtime($path);
        return $mtime === false ? null : $mtime;
    }

    private static function extractDataTimestamp(array $json): ?int
    {
        foreach (['data_timestamp', 'timestamp', 'updated_at', 'updatedAt', 'generated_at', 'generatedAt', 'created_at', 'createdAt', 'time'] as $key) {
            $value = $json[$key] ?? null;
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
            if (is_string($value)) {
                $timestamp = strtotime($value);
                if ($timestamp !== false) {
                    return $timestamp;
                }
            }
        }

        return null;
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
     * @return TagCategory[]
     */
    public function getTagCategories(bool $unknown_last = false): array
    {
        if (!$unknown_last || !isset($this->tag_categories[TagCategory::UNKNOWN_ID])) {
            return $this->tag_categories;
        }

        $tag_categories = $this->tag_categories;
        $UnknownCategory = $tag_categories[TagCategory::UNKNOWN_ID];
        unset($tag_categories[TagCategory::UNKNOWN_ID]);
        $tag_categories[TagCategory::UNKNOWN_ID] = $UnknownCategory;

        return $tag_categories;
    }

    public function getTagCategory(string $id): ?TagCategory
    {
        return $this->tag_categories[$id] ?? null;
    }

    public function makeTagCategoryById(string $id, ?string $name = null): TagCategory
    {
        if (!array_key_exists($id, $this->tag_categories)) {
            $this->tag_categories[$id] = TagCategory::fromId($id, $name);
        } elseif ($name !== null) {
            $this->tag_categories[$id]->setName($name);
        }

        return $this->tag_categories[$id];
    }

    public function getUnknownTagCategory(): TagCategory
    {
        return $this->makeTagCategoryById(TagCategory::UNKNOWN_ID);
    }

    public function getTagCategoryByTag(Tag|string $Tag): ?TagCategory
    {
        if (is_string($Tag)) {
            $Tag = $this->getTag($Tag);
        }

        return $Tag?->getCategory();
    }

    /**
     * @return Tag[]
     */
    public function getTagsByCategory(TagCategory|string $Category): array
    {
        $category_id = is_string($Category) ? $Category : $Category->getId();

        return $this->tags_by_category_id[$category_id] ?? [];
    }

    public function assignTagCategory(Tag|string $Tag, TagCategory|string $Category): void
    {
        $Tag = is_string($Tag) ? $this->makeTagByName($Tag) : $Tag;
        $Category = is_string($Category) ? $this->makeTagCategoryById($Category) : $Category;
        $PreviousCategory = $Tag->getCategory();
        if ($PreviousCategory && $PreviousCategory !== $Category) {
            unset($this->tags_by_category_id[$PreviousCategory->getId()][$Tag->getName()]);
            $PreviousCategory->removeTag($Tag);
        }

        $Tag->setCategory($Category);
        $this->tags_by_category_id[$Category->getId()][$Tag->getName()] = $Tag;
    }

    public function loadKnownTags(array $known_tags): void
    {
        $this->makeTagCategoryById(TagCategory::UNKNOWN_ID);

        foreach ($known_tags['links'] ?? [] as $link_name => $link_data) {
            if (!is_array($link_data)) {
                $link_data = [];
            }

            $Tag = $this->makeTagByName($link_name);
            $Tag->isStandard((bool) ($link_data['standard'] ?? $Tag->isStandard()));
            $Tag->isSingle((bool) ($link_data['single'] ?? $Tag->isSingle()));

            if (is_string($link_data['category'] ?? null)) {
                $category_id = $link_data['category'];
                $Category = $this->makeTagCategoryById($category_id);
                $this->assignTagCategory($Tag, $Category);
            }

            if ($pair = ($link_data['pair'] ?? false)) {
                $TagPair = $pair === true ? $Tag : $this->makeTagByName($pair);
                $Tag->setPair($TagPair, $link_data['strong_pair'] ?? false);
            }
        }
    }

    public function findTagByName(string $name): ?Tag
    {
        if (isset($this->tags[$name])) {
            return $this->tags[$name];
        }

        $normalized_name = mb_strtolower($name);
        foreach ($this->tags as $Tag) {
            if (mb_strtolower($Tag->getName()) === $normalized_name) {
                return $Tag;
            }
        }

        return null;
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
            $this->assignTagCategory($Tag, $this->getUnknownTagCategory());
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

    public static function validateTokenNameFormat(?string $name): bool
    {
        if (!$name) {
            return false;
        }

        return preg_match('/^[a-z0-9]{1,12}}?$/i', $name);
    }

    public static function validateTransactionHashFormat(?string $hash): bool
    {
        if (!$hash) {
            return false;
        }

        return preg_match('/^[a-f0-9]{64}$/i', $hash) === 1;
    }

    public function getSignatures(): SignatureCollection
    {
        return $this->Signatures;
    }

    private function loadUsernames(): void
    {
        $usernames = $this->AccountsManager->fetchUsernames();
        foreach ($usernames as $account_id => $username) {
            if (!isset($this->accounts[$account_id])) {
                continue;
            }

            $this->accounts[$account_id]->setUsername($username);
        }
    }

    private function normalizeMultisig(string $account_id, array $data): ?array
    {
        if (!isset($data['thresholds'], $data['signers']) || !is_array($data['thresholds']) || !is_array($data['signers'])) {
            return null;
        }

        $thresholds = array_values(array_map('intval', $data['thresholds']));
        if (count($thresholds) !== 3) {
            return null;
        }

        $signers = [];
        foreach ($data['signers'] as $signer) {
            if (!is_array($signer) || count($signer) < 2) {
                continue;
            }

            $signer_account_id = $signer[0];
            $weight = (int) $signer[1];
            if (
                !is_string($signer_account_id)
                || $signer_account_id === $account_id
                || !self::validateStellarAccountIdFormat($signer_account_id)
                || $weight <= 0
            ) {
                continue;
            }

            $signers[] = [
                'account_id' => $signer_account_id,
                'weight' => $weight,
            ];
        }

        if (!$signers) {
            return null;
        }

        return [
            'thresholds' => $thresholds,
            'master_key' => (int) ($data['master_key'] ?? 0),
            'signers' => $signers,
        ];
    }
}
