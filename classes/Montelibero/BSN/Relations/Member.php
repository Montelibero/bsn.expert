<?php

namespace Montelibero\BSN\Relations;

abstract class Member implements Relation
{
    private int $level;
    private bool $is_inherited = false;

    public function __construct(int $level)
    {
        $this->level = $level;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function isInherited(?bool $new_value = null): int
    {
        if ($new_value !== null) {
            $this->is_inherited = $new_value;
        }

        return $this->is_inherited;
    }
}