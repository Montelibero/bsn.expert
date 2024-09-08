<?php

namespace Montelibero\BSN;

class Tag
{
    use HasLinks;

    private string $name;

    private bool $is_single = false;
    private bool $is_standard = false;
    private bool $is_promote = false;
    private bool $is_editable = true;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public static function fromName(string $name): self
    {
        return new self($name);
    }

    public function isEditable(?bool $value = null): bool
    {
        if ($value !== null) {
            $this->is_editable = $value;
        }

        return $this->is_editable;
    }

    public function isSingle(?bool $value = null): bool
    {
        if ($value !== null) {
            $this->is_single = $value;
        }

        return $this->is_single;
    }

    public function isStandard(?bool $value = null): bool
    {
        if ($value !== null) {
            $this->is_standard = $value;
        }

        return $this->is_standard;
    }

    public function isPromote(?bool $value = null): bool
    {
        if ($value !== null) {
            $this->is_promote = $value;
        }

        return $this->is_promote;
    }
}