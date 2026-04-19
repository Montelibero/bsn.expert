<?php

namespace Montelibero\BSN;

use JsonSerializable;
use Montelibero\BSN\Relations\Corporate;
use Montelibero\BSN\Relations\Person;
use Montelibero\BSN\Relations\Relation;
use Montelibero\BSN\Relations\Unknown;

class Account implements JsonSerializable
{
    use HasLinks;

    private string $id;
    private ?string $username = null;
    private array $name = [];
    private array $about = [];
    private array $website = [];

    private array $balances = [];

    private ?array $outcome_tags = null;
    private ?array $income_tags = null;

    private Relation|null $Relation = null;

    private ?self $Owner = null;

    private ?string $telegram_id = null;
    private ?string $telegram_username = null;

    private bool $is_contact = false;
    private ?string $contact_name = null;
    /** @var Signature[] */
    private array $signatures = [];
    private array $profile;
    private ?array $multisig = null;
    private array $multisig_participations = [];

    public function __construct(string $id = null)
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getShortId(): string
    {
        return substr($this->id, 0, 2) . '…' . substr($this->id, -6);
    }

    public function getDisplayName($ignore_contact = false): string
    {
        $result = $this->getShortId();
        $public_name = '';
        if (($name = $this->getName()) && ($name = $name[0])) {
            $public_name = $name;
        }
        $contact_name = null;
        $contact_mode = $this->isContact() && !$ignore_contact;
        if ($contact_mode && ($contact_name = $this->getContactName())) {
            $result .= ' [📒 ' . $contact_name . ']';
        } elseif ($contact_mode && $public_name) {
            $result .= ' [📒 ' . $public_name . ']';
        } elseif ($contact_mode) {
            $result .= ' [📒]';
        } elseif ($public_name) {
            $result .= ' [' . $public_name . ']';
        }
        if (
            $contact_name === null
            && ($_SESSION['show_telegram_usernames'] ?? false)
            && $tg_username = $this->getTelegramUsername()
        ) {
            $result .= ' @' . $tg_username;
        }

        if ($emoji = $this->getEmoji()) {
            $result .= ' ' . $emoji;
        }

        return $result;
    }

    public function getEmoji(): string
    {
        $result = '';
        if ($mtlap = $this->getBalance('MTLAP')) {
            $result = str_repeat('⭐', $mtlap);
        } else if ($mtlac = $this->getBalance('MTLAC')) {
            $result = str_repeat('🌟', $mtlac);
        }

        return $result;
    }

    public function getEmoji2(): string
    {
        $result = '';
        if ($mtlap = $this->getBalance('MTLAP')) {
            $result .= '🖇👤';
            if ($mtlap >= 2) {
                $result .= '🆔';
            }
            if ($mtlap >= 3) {
                $result .= '💰';
            }
            if ($mtlap >= 4) {
                $result .= '🎗️';
            }
            if ($mtlap >= 5) {
                $result .= '💸️';
            }
        } else if ($mtlac = $this->getBalance('MTLAC')) {
            $result .= '🖇🏢';
            if ($mtlac >= 2) {
                $result .= '🌟';
            }
            if ($mtlac >= 3) {
                $result .= '💼';
            }
            if ($mtlac >= 4) {
                $result .= '🎩';
            }
        }

        return $result;
    }

    public static function fromId(string $id = null): self
    {
        return new self($id);
    }

    //region Self-presentation

    public function setProfile(array $data)
    {
        $this->profile = $data;
    }

    public function getProfile(): array
    {
        return $this->profile;
    }

    public function getProfileItem($name): array
    {
        return $this->profile[$name] ?? [];
    }

    public function getProfileSingleItem(string $name): ?string
    {
        return $this->profile[$name][0] ?? null;
    }

    /**
     * @return string[]
     */
    public function getName(): array
    {
        return $this->getProfileItem('Name');
    }

    /**
     * @return string[]
     */
    public function getAbout(): array
    {
        return $this->getProfileItem('About');
    }

    /**
     * @return string[]
     */
    public function getWebsite(): array
    {
        return $this->getProfileItem('Website');
    }
    //endregion Self-presentation

    /**
     * @return Tag[]
     */
    public function getOutcomeTags(): array
    {
        $this->sortLinks();
        $tags = [];
        foreach ($this->outcome_tags as $items) {
            $tags[] = $items[0]->getTag();
        }

        return $tags;
    }

    /**
     * @return Tag[]
     */
    public function getIncomeTags(): array
    {
        $this->sortLinks();
        $tags = [];
        foreach ($this->income_tags as $items) {
            $tags[] = $items[0]->getTag();
        }

        return $tags;
    }

    /**
     * @param Tag $Tag
     * @return Account[]
     */
    public function getOutcomeLinks(Tag $Tag): array
    {
        $this->sortLinks();
        if (!array_key_exists($Tag->getName(), $this->outcome_tags)) {
            return [];
        }

        $accounts = [];
        /** @var Link $Link */
        foreach ($this->outcome_tags[$Tag->getName()] as $Link) {
            $accounts[] = $Link->getTargetAccount();
        }

        return $accounts;
    }

    /**
     * @param Tag $Tag
     * @return Account[]
     */
    public function getIncomeLinks(Tag $Tag): array
    {
        $this->sortLinks();
        if (!array_key_exists($Tag->getName(), $this->income_tags)) {
            return [];
        }

        $accounts = [];
        /** @var Link $Link */
        foreach ($this->income_tags[$Tag->getName()] as $Link) {
            $accounts[] = $Link->getSourceAccount();
        }

        return $accounts;
    }

    private function sortLinks(): void
    {
        if ($this->outcome_tags !== null) {
            return;
        }

        $this->outcome_tags = [];
        $this->income_tags = [];
        foreach ($this->links as $Link) {
            $tag_name = $Link->getTag()->getName();
            if ($Link->getSourceAccount() === $this) {
                if (!array_key_exists($tag_name, $this->outcome_tags)) {
                    $this->outcome_tags[$tag_name] = [];
                }
                $this->outcome_tags[$tag_name][] = $Link;
            } else if ($Link->getTargetAccount() === $this) {
                if (!array_key_exists($tag_name, $this->income_tags)) {
                    $this->income_tags[$tag_name] = [];
                }
                $this->income_tags[$tag_name][] = $Link;
            }
        }
    }

    public function addBalanceRecord(string $name, float $value): void
    {
        $this->balances[$name] = $value;
    }

    public function getBalances(): array
    {
        return $this->balances;
    }

    public function getBalance(string $name = null): float
    {
        if (!array_key_exists($name, $this->balances)) {
            return .0;
        }

        return $this->balances[$name];
    }

    public function setMultisig(array $thresholds, int $master_key, array $signers): void
    {
        $normalized_signers = [];
        foreach ($signers as $signer) {
            if (!($signer['account'] ?? null) instanceof self) {
                continue;
            }

            $normalized_signers[] = [
                'account' => $signer['account'],
                'weight' => (int) ($signer['weight'] ?? 0),
            ];
        }

        if (!$normalized_signers) {
            $this->multisig = null;
            return;
        }

        $this->multisig = [
            'thresholds' => [
                (int) ($thresholds[0] ?? 0),
                (int) ($thresholds[1] ?? 0),
                (int) ($thresholds[2] ?? 0),
            ],
            'master_key' => $master_key,
            'signers' => $normalized_signers,
        ];
    }

    public function getMultisig(): ?array
    {
        return $this->multisig;
    }

    public function hasMultisig(): bool
    {
        return $this->multisig !== null;
    }

    public function addMultisigParticipation(self $Account, int $weight, int $med_threshold): void
    {
        $this->multisig_participations[$Account->getId()] = [
            'account' => $Account,
            'weight' => $weight,
            'med_threshold' => $med_threshold,
        ];
    }

    public function getMultisigParticipations(): array
    {
        $participations = array_values($this->multisig_participations);

        usort($participations, function (array $a, array $b): int {
            $a_can_sign_alone = self::canSignMultisigAlone($a);
            $b_can_sign_alone = self::canSignMultisigAlone($b);
            if ($a_can_sign_alone !== $b_can_sign_alone) {
                return $a_can_sign_alone ? -1 : 1;
            }

            $ratio_comparison = self::multisigParticipationRatio($b) <=> self::multisigParticipationRatio($a);
            if ($ratio_comparison !== 0) {
                return $ratio_comparison;
            }

            $weight_comparison = ((int) ($b['weight'] ?? 0)) <=> ((int) ($a['weight'] ?? 0));
            if ($weight_comparison !== 0) {
                return $weight_comparison;
            }

            $threshold_comparison = ((int) ($a['med_threshold'] ?? 0)) <=> ((int) ($b['med_threshold'] ?? 0));
            if ($threshold_comparison !== 0) {
                return $threshold_comparison;
            }

            /** @var self $a_account */
            $a_account = $a['account'];
            /** @var self $b_account */
            $b_account = $b['account'];

            return strcmp($a_account->getId(), $b_account->getId());
        });

        return $participations;
    }

    private static function canSignMultisigAlone(array $participation): bool
    {
        return (int) ($participation['weight'] ?? 0) >= (int) ($participation['med_threshold'] ?? 0);
    }

    private static function multisigParticipationRatio(array $participation): float
    {
        $weight = (int) ($participation['weight'] ?? 0);
        $med_threshold = (int) ($participation['med_threshold'] ?? 0);

        if ($med_threshold <= 0) {
            return $weight > 0 ? INF : 0.0;
        }

        return $weight / $med_threshold;
    }

    public function getOwner(): ?Account
    {
        static $found = false;
        if (!$found) {
            $owner = $this->getOutcomeLinks(Tag::fromName('Owner'));
            if (count($owner) === 1) {
                $Owner = $owner[0];
                foreach ($Owner->getOutcomeLinks(Tag::fromName('OwnershipFull')) as $OutcomeLink) {
                    if ($OutcomeLink->getId() === $this->getId()) {
                        $this->Owner = $OutcomeLink;
                        break;
                    }
                }
            }
            $found = true;
        }

        return $this->Owner;
    }

    public function getRelation(): Relation
    {
        if ($this->Relation !== null) {
            return $this->Relation;
        }

        if ($mtlap = $this->getBalance('MTLAP')) {
            $Relation = new Person((int) $mtlap);
        } elseif ($mtlac = $this->getBalance('MTLAC')) {
            $Relation = new Corporate((int)$mtlac);
        } else {
            $Relation = new Unknown();
        }

        return $this->Relation = $Relation;
    }

    public function setRelation(Relation $Relation): void
    {
        $this->Relation = $Relation;
    }

    public function getTelegramId(): ?string
    {
        return $this->telegram_id;
    }

    public function setTelegramId(?string $telegram_id): void
    {
        $this->telegram_id = $telegram_id;
    }

    public function getTelegramUsername(): ?string
    {
        return $this->telegram_username;
    }

    public function setTelegramUsername(?string $telegram_username): void
    {
        $this->telegram_username = $telegram_username;
    }

    public function jsonSerialize(): array
    {
        $data = [
            'id' => $this->getId(),
            'short_id' => $this->getShortId(),
            'display_name' => $this->getDisplayName(),
        ];
        if ($username = $this->getUsername()) {
            $data['username'] = $username;
        }
        if ($name = $this->getName()[0] ?? null ) {
            $data['name'] = $name;
        }

        return $data;
    }

    public function isContact(?bool $value = null): bool
    {
        if ($value !== null) {
            $this->is_contact = $value;
        }

        return $this->is_contact;
    }

    public function getContactName(): ?string
    {
        return $this->contact_name;
    }

    public function setContactName(?string $contact_name): void
    {
        $this->contact_name = $contact_name;
    }

    public function calcBsnScore(): int
    {
        $score = 0;

        if ($this->name) {
            $score += 10;
        }
        if ($this->about) {
            $score += 5;
        }
        if ($this->website) {
            $score += 5;
        }
        if ($this->hasMultisig()) {
            $score += 10;
        }
        if (count($this->getOutcomeTags()) > 2) {
            $score += 5;
        }
        if (count($this->getOutcomeTags()) > 5) {
            $score += 5;
        }
        if (count($this->getIncomeTags()) > 2) {
            $score += 5;
        }
        if (count($this->getIncomeTags()) > 5) {
            $score += 5;
        }
        if (count($this->balances) > 5) {
            $score += 5;
        }
        if ($this->getUsername()) {
            $score += 10;
        }

        return $score;
    }

    public function addSignature(Signature $Signature): void
    {
        $this->signatures[$Signature->getContract()->getHash()] = $Signature;
    }

    /**
     * @return Signature[]
     */
    public function getSignatures(): array
    {
        return $this->signatures;
    }

    public function getUsername(): ?string
    {
        return empty($this->username) ? null : $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }
}
