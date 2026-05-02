<?php

namespace Montelibero\BSN;

class TagCategory
{
    public const SORT_EXAMPLE = [
        "social",
        "reputation",
        "structure",
        "arbitration",
        "family",
        "mtla",
        "ownership",
        "delegation",
        "work",
        "residence",
    ];

    public const UNKNOWN_ID = 'unknown';

    private string $id;
    private string $name;

    /** @var Tag[] */
    private array $tags = [];

    public function __construct(string $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    public static function fromId(string $id, ?string $name = null): self
    {
        return new self($id, $name ?? $id);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isUnknown(): bool
    {
        return $this->id === self::UNKNOWN_ID;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function addTag(Tag $Tag): void
    {
        $this->tags[$Tag->getName()] = $Tag;
    }

    public function removeTag(Tag $Tag): void
    {
        unset($this->tags[$Tag->getName()]);
    }

    /**
     * @return Tag[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }
}
