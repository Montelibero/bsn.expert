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
        return substr($this->id, 0, 4) . '…' . substr($this->id, -4);
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
        $result .= ' ' . $this->getEmoji();
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

    /**
     * @param string[] $name
     * @return void
     */
    public function setName(array $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string[]
     */
    public function getName(): array
    {
        return $this->name;
    }

    /**
     * @param string[] $about
     * @return void
     */
    public function setAbout(array $about): void
    {
        $this->about = $about;
    }

    /**
     * @return string[]
     */
    public function getAbout(): array
    {
        return $this->about;
    }

    /**
     * @param string[] $website
     * @return void
     */
    public function setWebsite(array $website): void
    {
        $this->website = $website;
    }

    /**
     * @return string[]
     */
    public function getWebsite(): array
    {
        return $this->website;
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
        if (count($this->getOutcomeLinks(Tag::fromName('Signer'))) > 1) {
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
