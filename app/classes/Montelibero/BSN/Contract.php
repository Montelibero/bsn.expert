<?php

namespace Montelibero\BSN;

class Contract implements \JsonSerializable, \Stringable
{
    public const REGEX_SHA256 = '/^[0-9a-f]{64}$/';

    public readonly string $hash;
    private ?string $name = null;
    private ?string $type = null;
    private ?string $url = null;
    private ?string $text = null;
    private ?Contract $NewContract = null;

    public function __construct($hash)
    {
        $hash = strtolower((string) $hash);

        if (!self::validate($hash)) {
            throw new \InvalidArgumentException('Invalid hash format');
        }

        $this->hash = $hash;
    }

    /**
     * @return string
     * @deprecated
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    public static function validate(?string $string): bool
    {
        if (empty($string)) {
            return false;
        }

        return preg_match(self::REGEX_SHA256, $string) === 1;
    }

    public function __toString(): string
    {
        return $this->hash;
    }

    public function jsonSerialize(): array
    {
        return [
            'hash' => $this->hash,
            'name' => $this->getName(),
            'display_name' => $this->getDisplayName(),
            'type' => $this->getType(),
            'url' => $this->getUrl(),
            'text' => $this->getText(),
        ];
    }

    public function getDisplayName(): string
    {
        return $this->getName() ?:
            substr($this->hash, 0, 6) . '...' . substr($this->hash, -6);
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    public function getNewContract(): ?Contract
    {
        return $this->NewContract;
    }

    public function setNewContract(?Contract $NewContract): void
    {
        $this->NewContract = $NewContract;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): void
    {
        $this->url = $url;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = $text;
    }
}