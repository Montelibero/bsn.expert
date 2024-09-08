<?php

namespace Montelibero\BSN;

trait HasLinks
{
    /** @var Link[] */
    private array $links = [];

    public function addLink(Link $Link): void
    {
        $this->links[] = $Link;
    }
}