<?php

namespace Montelibero\BSN;

use DI\Container;
use Pecee\SimpleRouter\SimpleRouter;

class ApplicationContext
{
    public function __construct(
        public readonly Container $Container,
        public readonly CurrentUser $CurrentUser,
        public readonly CurrentContacts $CurrentContacts,
        public readonly RequestArrayView $SessionView,
        public readonly RequestArrayView $ServerView,
    ) {
    }

    public function handleRequest(): void
    {
        $this->syncRequestContext();
        SimpleRouter::start();
    }

    public function syncRequestContext(): void
    {
        $this->SessionView->bind($_SESSION);
        $this->ServerView->bind($_SERVER);
        $this->CurrentUser->beginRequest();
        $this->CurrentContacts->beginRequest();
    }
}
