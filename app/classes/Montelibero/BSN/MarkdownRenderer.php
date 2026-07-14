<?php
declare(strict_types=1);

namespace Montelibero\BSN;

use Parsedown;

final class MarkdownRenderer
{
    public function render(string $markdown): string
    {
        return (new Parsedown())
            ->setSafeMode(true)
            ->text($markdown);
    }
}
